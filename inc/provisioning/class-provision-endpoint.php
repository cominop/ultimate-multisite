<?php
/**
 * Sharehaus Provisioning Engine — REST API Endpoint.
 *
 * POST   /wp-json/wu/v2/provision       Begin site provisioning
 * GET    /wp-json/wu/v2/provision/:id   Get provisioning status
 *
 * Auth: X-API-Key header (or existing Basic Auth via api_key/api_secret)
 *
 * @package Sharehaus_Provisioning
 * @since 1.0.0
 */

namespace Sharehaus\Provisioning;

use WP_REST_Request;
use WP_REST_Response;
use WP_Ultimo\Models\Site;
use WP_Ultimo\Models\Membership;
use WP_Ultimo\Models\Customer;
use WP_Ultimo\Database\Sites\Site_Type;
use WP_Ultimo\Database\Memberships\Membership_Status;

defined('ABSPATH') || exit;

class Provision_Endpoint {

    use WP_Ultimo\Traits\Singleton;

    /** Allowed provisioning states */
    const STATUS_ACCEPTED    = 'accepted';
    const STATUS_PROVISIONING = 'provisioning';
    const STATUS_ACTIVE      = 'active';
    const STATUS_FAILED      = 'failed';
    const STATUS_CLEANUP     = 'cleanup_required';
    const STATUS_SUSPENDED   = 'suspended';
    const STATUS_ARCHIVED    = 'archived';
    const STATUS_PURGED      = 'purged';

    /**
     * Init hooks.
     */
    public function init(): void {
        add_action('wu_register_rest_routes', [$this, 'register_routes']);
    }

    /**
     * Register provision routes under existing wu/v2 namespace.
     */
    public function register_routes($api): void {
        $namespace = $api->get_namespace(); // 'wu/v2'

        // POST /wu/v2/provision
        register_rest_route($namespace, '/provision', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_provision'],
            'permission_callback' => [$this, 'check_api_key'],
            'args'                => $this->get_provision_args(),
        ]);

        // GET /wu/v2/provision/:id
        register_rest_route($namespace, '/provision/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_status'],
            'permission_callback' => [$this, 'check_api_key'],
        ]);
    }

    /**
     * Authenticate via X-API-Key header (preferred) or fall back to existing
     * Ultimate Multisite Basic Auth (api_key / api_secret).
     */
    public function check_api_key($request): bool {
        // 1. X-API-Key header
        $api_key_header = $request->get_header('X-API-Key');
        if ($api_key_header) {
            $stored_key = defined('SHAREHAUS_PROVISIONING_API_KEY')
                ? SHAREHAUS_PROVISIONING_API_KEY
                : wu_get_setting('sharehaus_provisioning_api_key', '');

            return ! empty($stored_key) && hash_equals($stored_key, $api_key_header);
        }

        // 2. Fall back to existing UM Basic Auth
        $api = \WP_Ultimo\API::get_instance();
        return $api->check_authorization($request);
    }

    /**
     * POST /wu/v2/provision — Begin provisioning a site.
     */
    public function handle_provision(WP_REST_Request $request): WP_REST_Response {
        $body = $request->get_json_params();

        // Validate required fields
        $required = ['customer', 'subdomain', 'operational_profile', 'profile_version'];
        foreach ($required as $field) {
            if (empty($body[$field])) {
                return new WP_REST_Response([
                    'code'    => 'missing_field',
                    'message' => sprintf('Missing required field: %s', $field),
                ], 400);
            }
        }

        $customer_email = $body['customer']['email'] ?? '';
        if (! is_email($customer_email)) {
            return new WP_REST_Response([
                'code'    => 'invalid_email',
                'message' => 'Invalid customer email.',
            ], 400);
        }

        // Validate subdomain
        $subdomain = sanitize_title($body['subdomain']);
        if ($subdomain !== $body['subdomain']) {
            return new WP_REST_Response([
                'code'    => 'invalid_subdomain',
                'message' => 'Subdomain must be a valid slug.',
            ], 400);
        }

        // 1. Claim idempotency key
        $idempotency_key = $body['idempotency_key'] ?? wp_generate_uuid4();
        $existing = Provisioning_Table::claim($idempotency_key, $body);

        if ($existing) {
            // Already claimed — check if it's done
            $op = Provisioning_Table::get($existing);
            if ($op && $op->site_id && $op->status === self::STATUS_ACTIVE) {
                return new WP_REST_Response([
                    'provision_id'   => (int) $op->provision_id,
                    'status'         => $op->status,
                    'site_id'        => (int) $op->site_id,
                    'site_url'       => get_site_url($op->site_id),
                    'membership_id'  => (int) $op->membership_id,
                    'customer_id'    => (int) $op->customer_id,
                    'duplicate'      => true,
                ], 200);
            }

            return new WP_REST_Response([
                'provision_id'   => (int) $op->provision_id,
                'status'         => $op->status,
                'current_step'   => $op->current_step,
                'duplicate'      => true,
                'message'        => 'Provisioning already in progress for this idempotency key.',
            ], 202);
        }

        // 2. Create operation record
        $op_id = Provisioning_Table::claim($idempotency_key, $body);

        // 3. Create or find WordPress user from email
        $user = get_user_by('email', $customer_email);
        if (! $user) {
            $display_name = $body['customer']['display_name'] ?? explode('@', $customer_email)[0];
            $user_id = wp_insert_user([
                'user_login'   => sanitize_user($customer_email),
                'user_email'   => $customer_email,
                'display_name' => $display_name,
                'user_pass'    => wp_generate_password(24),
                'role'         => 'subscriber',
            ]);

            if (is_wp_error($user_id)) {
                Provisioning_Table::update($op_id, [
                    'status'     => self::STATUS_FAILED,
                    'last_error' => $user_id->get_error_message(),
                ]);
                return new WP_REST_Response([
                    'code'    => 'user_creation_failed',
                    'message' => $user_id->get_error_message(),
                ], 500);
            }
        } else {
            $user_id = $user->ID;
        }

        // 4. Create or find UM Customer
        $customer = Customer::get_by_user_id($user_id);
        if (! $customer) {
            $customer_data = [
                'user_id' => $user_id,
                'email'   => $customer_email,
                'type'    => 'customer',
            ];
            $customer = Customer::create($customer_data);
            if (is_wp_error($customer)) {
                Provisioning_Table::update($op_id, [
                    'status'     => self::STATUS_FAILED,
                    'last_error' => $customer->get_error_message(),
                ]);
                return new WP_REST_Response([
                    'code'    => 'customer_creation_failed',
                    'message' => $customer->get_error_message(),
                ], 500);
            }
        }

        // 5. Create membership (operational projection only — no payment, no trial)
        $membership_data = [
            'customer_id'      => $customer->get_id(),
            'status'           => Membership_Status::ACTIVE,
            'op_profile'       => $body['operational_profile'],
            'op_profile_version' => (int) $body['profile_version'],
            'date_created'     => current_time('mysql'),
        ];

        $membership = Membership::create($membership_data);
        if (is_wp_error($membership)) {
            Provisioning_Table::update($op_id, [
                'status'     => self::STATUS_FAILED,
                'last_error' => $membership->get_error_message(),
            ]);
            return new WP_REST_Response([
                'code'    => 'membership_creation_failed',
                'message' => $membership->get_error_message(),
            ], 500);
        }

        // 6. Create pending site
        global $current_site;
        $site_info = [
            'title'      => $body['site_title'] ?? $subdomain,
            'domain'     => $current_site->domain,
            'path'       => '/' . $subdomain . '/',
            'type'       => Site_Type::CUSTOMER_OWNED,
            'template_id' => $body['template_id'] ?? 0,
        ];

        $pending_site = $membership->create_pending_site($site_info);

        // Store brand data in site transient for post-provisioning
        if (! empty($body['brand'])) {
            $pending_site->set_transient(['brand' => $body['brand']]);
            $membership->update_pending_site($pending_site);
        }

        // 7. Trigger async publishing
        $membership->publish_pending_site_async();

        // 8. Update operation record
        Provisioning_Table::update($op_id, [
            'customer_id'    => $customer->get_id(),
            'membership_id'  => $membership->get_id(),
            'status'         => self::STATUS_PROVISIONING,
            'current_step'   => 'site_creation_queued',
        ]);

        return new WP_REST_Response([
            'provision_id'   => $op_id,
            'status'         => self::STATUS_PROVISIONING,
            'customer_id'    => (int) $customer->get_id(),
            'membership_id'  => (int) $membership->get_id(),
            'message'        => 'Provisioning started. Poll GET /provision/:id for status.',
        ], 202);
    }

    /**
     * GET /wu/v2/provision/:id — Get provisioning status.
     */
    public function handle_status(WP_REST_Request $request): WP_REST_Response {
        $op_id = (int) $request->get_param('id');

        $op = Provisioning_Table::get($op_id);
        if (! $op) {
            return new WP_REST_Response([
                'code'    => 'not_found',
                'message' => 'Provisioning operation not found.',
            ], 404);
        }

        $response = [
            'provision_id'   => (int) $op->provision_id,
            'status'         => $op->status,
            'current_step'   => $op->current_step,
            'site_id'        => $op->site_id ? (int) $op->site_id : null,
            'customer_id'    => $op->customer_id ? (int) $op->customer_id : null,
            'membership_id'  => $op->membership_id ? (int) $op->membership_id : null,
            'op_profile'     => $op->op_profile,
            'profile_version' => $op->profile_version ? (int) $op->profile_version : null,
            'last_error'     => $op->last_error,
            'requested_at'   => $op->requested_at,
            'completed_at'   => $op->completed_at,
        ];

        if ($op->site_id) {
            $response['site_url']  = get_site_url((int) $op->site_id);
            $response['admin_url'] = get_admin_url((int) $op->site_id);
        }

        if ($op->status === self::STATUS_FAILED) {
            return new WP_REST_Response($response, 500);
        }

        return new WP_REST_Response($response, 200);
    }

    /**
     * Validation schema for POST /provision.
     */
    private function get_provision_args(): array {
        return [
            'operational_profile' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'profile_version' => [
                'required' => true,
                'type'     => 'integer',
            ],
            'subdomain' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_title',
            ],
            'customer' => [
                'required' => true,
                'type'     => 'object',
            ],
            'command_id' => [
                'type' => 'string',
            ],
            'idempotency_key' => [
                'type' => 'string',
            ],
        ];
    }
}