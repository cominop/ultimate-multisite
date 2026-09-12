<?php
/**
 * Brand Pusher.
 *
 * Coordinates brand application to a newly provisioned site.
 * Wraps the Divi_Brand_Adapter with error handling and logging.
 *
 * @package Sharehaus_Provisioning
 * @since 1.0.0
 */

namespace Sharehaus\Provisioning;

defined('ABSPATH') || exit;

class Brand_Pusher {

    /**
     * Push brand data from the pending site's transient/metadata.
     *
     * Called during site publication (wu_pending_site_published hook).
     *
     * @param \WP_Ultimo\Models\Site      $site       Published site.
     * @param \WP_Ultimo\Models\Membership $membership Membership.
     */
    public static function push_from_site($site, $membership): void {
        $site_id = $site->get_id();

        if (! $site_id) {
            return;
        }

        // Brand data is stored in the pending site's transient during checkout
        $transient = $site->get_transient();
        $brand     = $transient['brand'] ?? [];

        if (empty($brand)) {
            return;
        }

        $result = Divi_Brand_Adapter::push((int) $site_id, $brand);

        if (is_wp_error($result)) {
            wu_log_add(
                'brand-push',
                sprintf(
                    'Brand push failed for site %d: %s',
                    $site_id,
                    $result->get_error_message()
                )
            );
            return;
        }

        wu_log_add(
            'brand-push',
            sprintf(
                'Brand pushed to site %d: %s',
                $site_id,
                wp_json_encode($result)
            )
        );

        // Update the provisioning operation record's current_step
        global $wpdb;
        $table = $wpdb->base_prefix . Provisioning_Table::TABLE_NAME;
        $membership_id = $membership->get_id();

        if ($membership_id) {
            $op = $wpdb->get_row($wpdb->prepare(
                "SELECT provision_id FROM {$table}
                 WHERE membership_id = %d AND status = 'provisioning'
                 ORDER BY provision_id DESC LIMIT 1",
                $membership_id
            ));

            if ($op) {
                Provisioning_Table::update((int) $op->provision_id, [
                    'current_step' => 'complete',
                ]);
            }
        }
    }
}