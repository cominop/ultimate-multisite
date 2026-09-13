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
use \WP_Ultimo\Models\Site;
use \WP_Ultimo\Models\Membership;
use \WP_Ultimo\Models\Customer;
use \WP_Ultimo\Database\Sites\Site_Type;
use \WP_Ultimo\Database\Memberships\Membership_Status;

defined('ABSPATH') || exit;

class Provision_Endpoint {

    use \WP_Ultimo\Traits\Singleton;

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

        // PUT /wu/v2/provision/:id/profile
        register_rest_route($namespace, '/provision/(?P<id>\d+)/profile', [
            'methods'             => \WP_REST_Server::EDITABLE,
            'callback'            => [$this, 'handle_profile'],
            'permission_callback' => [$this, 'check_api_key'],
        ]);

        // PUT /wu/v2/provision/:id/capabilities
        register_rest_route($namespace, '/provision/(?P<id>\d+)/capabilities', [
            'methods'             => \WP_REST_Server::EDITABLE,
            'callback'            => [$this, 'handle_capabilities'],
            'permission_callback' => [$this, 'check_api_key'],
        ]);

        // PUT /wu/v2/provision/:id/plugins
        register_rest_route($namespace, '/provision/(?P<id>\d+)/plugins', [
            'methods'             => \WP_REST_Server::EDITABLE,
            'callback'            => [$this, 'handle_plugins'],
            'permission_callback' => [$this, 'check_api_key'],
        ]);

        // PUT /wu/v2/provision/:id/state
        register_rest_route($namespace, '/provision/(?P<id>\d+)/state', [
            'methods'             => \WP_REST_Server::EDITABLE,
            'callback'            => [$this, 'handle_state'],
            'permission_callback' => [$this, 'check_api_key'],
        ]);

        // PUT /wu/v2/provision/:id/domain
        register_rest_route($namespace, '/provision/(?P<id>\d+)/domain', [
            'methods'             => \WP_REST_Server::EDITABLE,
            'callback'            => [$this, 'handle_domain'],
            'permission_callback' => [$this, 'check_api_key'],
        ]);

        // POST /wu/v2/provision/:id/archive
        register_rest_route($namespace, '/provision/(?P<id>\d+)/archive', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_archive'],
            'permission_callback' => [$this, 'check_api_key'],
        ]);

        // POST /wu/v2/provision/:id/restore
        register_rest_route($namespace, '/provision/(?P<id>\d+)/restore', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_restore'],
            'permission_callback' => [$this, 'check_api_key'],
        ]);

        // POST /wu/v2/provision/:id/purge
        register_rest_route($namespace, '/provision/(?P<id>\d+)/purge', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_purge'],
            'permission_callback' => [$this, 'check_api_key'],
        ]);

        // GET /wu/v2/operational-profiles
        register_rest_route($namespace, '/operational-profiles', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_list_profiles'],
            'permission_callback' => [$this, 'check_api_key'],
        ]);

        // GET /wu/v2/templates
        register_rest_route($namespace, '/templates', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_list_templates'],
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
                : get_site_option('sharehaus_provisioning_api_key', '');

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

    // ─── Additional API Handlers ────────────────────────────────────

    /**
     * PUT /wu/v2/provision/:id/profile — Reconcile site against an operational profile.
     */
    public function handle_profile(WP_REST_Request $request): WP_REST_Response {
        $op = $this->find_operation($request);
        if (is_wp_error($op)) {
            return new WP_REST_Response($op->get_error_data(), $op->get_error_code() === 'not_found' ? 404 : 400);
        }

        $body     = $request->get_json_params();
        $site_id  = (int) $op->site_id;
        $is_upgrade = ! empty($body['is_upgrade']);

        if (! $site_id) {
            return new WP_REST_Response(['code' => 'no_site', 'message' => 'No site provisioned yet.'], 400);
        }

        $profile_id      = $body['operational_profile'] ?? $op->op_profile;
        $profile_version = (int) ($body['profile_version'] ?? $op->profile_version);

        $profile = Profile_Registry::get($profile_id, $profile_version);

        if (! $profile) {
            return new WP_REST_Response([
                'code'    => 'profile_not_found',
                'message' => "Profile {$profile_id} v{$profile_version} not found.",
            ], 404);
        }

        $changes = Profile_Reconciler::reconcile($site_id, $profile, $is_upgrade);

        Provisioning_Table::update((int) $op->provision_id, [
            'op_profile'      => $profile_id,
            'profile_version' => $profile_version,
        ]);

        return new WP_REST_Response([
            'status'          => 'active',
            'profile'         => $profile_id,
            'profile_version' => $profile_version,
            'changes'         => $changes,
        ], 200);
    }

    /**
     * PUT /wu/v2/provision/:id/capabilities — Override capabilities.
     */
    public function handle_capabilities(WP_REST_Request $request): WP_REST_Response {
        $op = $this->find_operation($request);
        if (is_wp_error($op)) return new WP_REST_Response($op->get_error_data(), 404);

        $body    = $request->get_json_params();
        $site_id = (int) $op->site_id;
        if (! $site_id) return new WP_REST_Response(['code' => 'no_site', 'message' => 'No site.'], 400);

        $caps    = $body['capabilities'] ?? [];
        $changes = Profile_Reconciler::reconcile_capabilities($site_id, $caps);

        return new WP_REST_Response(['changes' => $changes], 200);
    }

    /**
     * PUT /wu/v2/provision/:id/plugins — Direct plugin reconciliation (support only).
     */
    public function handle_plugins(WP_REST_Request $request): WP_REST_Response {
        $op = $this->find_operation($request);
        if (is_wp_error($op)) return new WP_REST_Response($op->get_error_data(), 404);

        $body    = $request->get_json_params();
        $site_id = (int) $op->site_id;
        if (! $site_id) return new WP_REST_Response(['code' => 'no_site', 'message' => 'No site.'], 400);

        $desired = $body['desired_plugins'] ?? $body['activate'] ?? [];
        $changes = Profile_Reconciler::reconcile_plugins($site_id, $desired);

        return new WP_REST_Response(['changes' => $changes], 200);
    }

    /**
     * PUT /wu/v2/provision/:id/state — Set operational state.
     */
    public function handle_state(WP_REST_Request $request): WP_REST_Response {
        $op = $this->find_operation($request);
        if (is_wp_error($op)) return new WP_REST_Response($op->get_error_data(), 404);

        $body  = $request->get_json_params();
        $state = $body['state'] ?? '';

        $allowed = [self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_ARCHIVED, self::STATUS_PURGED];
        if (! in_array($state, $allowed, true)) {
            return new WP_REST_Response([
                'code'    => 'invalid_state',
                'message' => "Invalid state: {$state}. Allowed: " . implode(', ', $allowed),
            ], 400);
        }

        $site_id = (int) $op->site_id;

        if ($site_id) {
            switch ($state) {
                case self::STATUS_SUSPENDED:
                    update_blog_option($site_id, 'sharehaus_suspended', true);
                    // WordPress doesn't have a native "suspend" — archive as approach
                    update_blog_status($site_id, 'archived', '1');
                    break;
                case self::STATUS_ACTIVE:
                    update_blog_option($site_id, 'sharehaus_suspended', false);
                    update_blog_status($site_id, 'archived', '0');
                    update_blog_status($site_id, 'deleted', '0');
                    break;
                case self::STATUS_PURGED:
                    if (function_exists('wp_delete_site')) {
                        wp_delete_site($site_id);
                    }
                    break;
            }
        }

        Provisioning_Table::update((int) $op->provision_id, ['status' => $state]);

        return new WP_REST_Response([
            'provision_id' => (int) $op->provision_id,
            'status'       => $state,
            'site_id'      => $site_id,
            'reason'       => $body['reason'] ?? 'studio_instruction',
        ], 200);
    }

    /**
     * PUT /wu/v2/provision/:id/domain — Assign domain.
     */
    public function handle_domain(WP_REST_Request $request): WP_REST_Response {
        $op = $this->find_operation($request);
        if (is_wp_error($op)) return new WP_REST_Response($op->get_error_data(), 404);

        $body    = $request->get_json_params();
        $domain  = sanitize_text_field($body['domain'] ?? '');
        $site_id = (int) $op->site_id;

        if (! $site_id || ! $domain) {
            return new WP_REST_Response(['code' => 'missing', 'message' => 'site_id and domain required'], 400);
        }

        // Use UM's domain mapping if available, else fallback
        if (function_exists('wu_set_site_domain')) {
            wu_set_site_domain($site_id, $domain);
        } else {
            update_blog_option($site_id, 'siteurl', "https://{$domain}");
            update_blog_option($site_id, 'home', "https://{$domain}");
        }

        return new WP_REST_Response([
            'site_id' => $site_id,
            'domain'  => $domain,
            'status'  => 'updated',
        ], 200);
    }

    /**
     * POST /wu/v2/provision/:id/archive — Archive site (recoverable).
     */
    public function handle_archive(WP_REST_Request $request): WP_REST_Response {
        $op = $this->find_operation($request);
        if (is_wp_error($op)) return new WP_REST_Response($op->get_error_data(), 404);

        $site_id = (int) $op->site_id;
        if ($site_id) {
            update_blog_status($site_id, 'archived', '1');
            update_blog_option($site_id, 'sharehaus_archived_at', current_time('mysql'));
        }

        Provisioning_Table::update((int) $op->provision_id, ['status' => self::STATUS_ARCHIVED]);

        return new WP_REST_Response([
            'provision_id' => (int) $op->provision_id,
            'status'       => self::STATUS_ARCHIVED,
            'site_id'      => $site_id,
            'recoverable'  => true,
        ], 200);
    }

    /**
     * POST /wu/v2/provision/:id/restore — Restore from archive.
     */
    public function handle_restore(WP_REST_Request $request): WP_REST_Response {
        $op = $this->find_operation($request);
        if (is_wp_error($op)) return new WP_REST_Response($op->get_error_data(), 404);

        $site_id = (int) $op->site_id;
        if ($site_id) {
            update_blog_status($site_id, 'archived', '0');
            update_blog_option($site_id, 'sharehaus_suspended', false);
        }

        Provisioning_Table::update((int) $op->provision_id, ['status' => self::STATUS_ACTIVE]);

        return new WP_REST_Response([
            'provision_id' => (int) $op->provision_id,
            'status'       => self::STATUS_ACTIVE,
            'site_id'      => $site_id,
        ], 200);
    }

    /**
     * POST /wu/v2/provision/:id/purge — Permanent destruction.
     */
    public function handle_purge(WP_REST_Request $request): WP_REST_Response {
        $op = $this->find_operation($request);
        if (is_wp_error($op)) return new WP_REST_Response($op->get_error_data(), 404);

        $site_id = (int) $op->site_id;
        if ($site_id && function_exists('wpmu_delete_blog')) {
            wpmu_delete_blog($site_id, true);
        }

        Provisioning_Table::update((int) $op->provision_id, [
            'status'       => self::STATUS_PURGED,
            'completed_at' => current_time('mysql'),
        ]);

        return new WP_REST_Response([
            'provision_id' => (int) $op->provision_id,
            'status'       => self::STATUS_PURGED,
            'terminal'     => true,
        ], 200);
    }

    /**
     * GET /wu/v2/operational-profiles — List available profiles.
     */
    public function handle_list_profiles(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response(Profile_Registry::list_available(), 200);
    }

    /**
     * GET /wu/v2/templates — List available site templates.
     */
    public function handle_list_templates(WP_REST_Request $request): WP_REST_Response {
        // Use UM's Site model to fetch templates
        if (method_exists('\WP_Ultimo\Models\Site', 'get_all_by_type')) {
            $templates = \WP_Ultimo\Models\Site::get_all_by_type('template');
            $result = [];
            foreach ($templates as $tpl) {
                $result[] = [
                    'id'          => $tpl->get_id(),
                    'title'       => $tpl->get_title(),
                    'description' => $tpl->get_description(),
                    'categories'  => $tpl->get_categories(),
                ];
            }
            return new WP_REST_Response($result, 200);
        }

        return new WP_REST_Response([], 200);
    }

    /**
     * Helper: find a provisioning operation by provision_id URL param.
     */
    private function find_operation(WP_REST_Request $request) {
        $op_id = (int) $request->get_param('id');
        $op    = Provisioning_Table::get($op_id);

        if (! $op) {
            return new \WP_Error('not_found', 'Provisioning operation not found.', [
                'status' => 404,
                'code'   => 'not_found',
                'message' => 'Provisioning operation not found.',
            ]);
        }

        return $op;
    }

    /**
     * PUT /wu/v2/provision/:id/brand — Incremental brand push.
     *
     * Accepts partial brand data. Only provided fields are applied.
     * Idempotent — pushing the same values twice is harmless.
     */
    public function handle_brand(WP_REST_Request $request): WP_REST_Response {
        $op = $this->find_operation($request);
        if (is_wp_error($op)) return new WP_REST_Response($op->get_error_data(), 404);

        $site_id = (int) $op->site_id;
        if (! $site_id) return new WP_REST_Response(['code' => 'no_site', 'message' => 'No site provisioned yet.'], 400);

        $brand = $request->get_json_params();
        if (empty($brand)) return new WP_REST_Response(['code' => 'empty', 'message' => 'No brand data provided.'], 400);

        $result = \Sharehaus\Provisioning\Divi_Brand_Adapter::push($site_id, $brand);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'code'    => 'brand_push_failed',
                'message' => $result->get_error_message(),
            ], 500);
        }

        return new WP_REST_Response([
            'provision_id' => (int) $op->provision_id,
            'site_id'      => $site_id,
            'applied'      => $result,
        ], 200);
    }
}