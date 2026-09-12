<?php
/**
 * Sharehaus Provisioning Engine — Bootstrap.
 *
 * Loads the provisioning subsystem into the Ultimate Multisite fork.
 * Registers API endpoints, idempotency table, and post-provisioning hooks.
 *
 * @package Sharehaus_Provisioning
 * @since 1.0.0
 */

namespace Sharehaus\\Provisioning;

defined('ABSPATH') || exit;

class Bootstrap {

    use \\WP_Ultimo\\Traits\\Singleton;

    /**
     * Init hooks.
     */
    public function init(): void {

        // Register the REST API endpoint
        Provision_Endpoint::get_instance()->init();

        // Hook into site publish completion to update operation record
        add_action('wu_pending_site_published', [$this, 'on_site_published'], 99, 2);

        // Hook into site save to catch the site ID after creation
        add_action('wu_site_post_save', [$this, 'on_site_saved'], 10, 3);
    }

    /**
     * Attach the provisioning operation's site_id when the WordPress site is saved.
     *
     * Site::save() fires wu_site_post_save after wpmu_create_blog() + meta setup.
     * The membership_id in the site meta lets us find the operation record.
     */
    public function on_site_saved(array $data, $site, bool $is_new): void {
        if (! $is_new) {
            return;
        }

        $site_id       = $site->get_id();
        $membership_id = $site->get_membership_id();

        if (! $site_id || ! $membership_id) {
            return;
        }

        global $wpdb;
        $table = $wpdb->base_prefix . Provisioning_Table::TABLE_NAME;

        // Find the operation record by membership_id where site_id is still null
        $op = $wpdb->get_row($wpdb->prepare(
            "SELECT provision_id, status FROM {$table}
             WHERE membership_id = %d AND site_id IS NULL
             ORDER BY provision_id DESC LIMIT 1",
            $membership_id
        ));

        if ($op && $op->status === self::STATUS_PROVISIONING) {
            Provisioning_Table::update((int) $op->provision_id, [
                'site_id'      => $site_id,
                'current_step' => 'brand_push',
                'status'       => self::STATUS_PROVISIONING,
            ]);
        }
    }

    /**
     * Mark operation as active when site is fully published.
     */
    public function on_site_published($site, $membership): void {
        global $wpdb;
        $table = $wpdb->base_prefix . Provisioning_Table::TABLE_NAME;

        $membership_id = $membership->get_id();
        if (! $membership_id) {
            return;
        }

        $op = $wpdb->get_row($wpdb->prepare(
            "SELECT provision_id FROM {$table}
             WHERE membership_id = %d AND status = %s
             ORDER BY provision_id DESC LIMIT 1",
            $membership_id,
            self::STATUS_PROVISIONING
        ));

        if ($op) {
            Provisioning_Table::update((int) $op->provision_id, [
                'status'       => self::STATUS_ACTIVE,
                'current_step' => 'complete',
                'completed_at' => current_time('mysql'),
            ]);
        }
    }

    // Re-export provisioning status constants for convenience
    const STATUS_ACCEPTED     = Provision_Endpoint::STATUS_ACCEPTED;
    const STATUS_PROVISIONING = Provision_Endpoint::STATUS_PROVISIONING;
    const STATUS_ACTIVE       = Provision_Endpoint::STATUS_ACTIVE;
    const STATUS_FAILED       = Provision_Endpoint::STATUS_FAILED;
    const STATUS_CLEANUP      = Provision_Endpoint::STATUS_CLEANUP;
    const STATUS_SUSPENDED    = Provision_Endpoint::STATUS_SUSPENDED;
    const STATUS_ARCHIVED     = Provision_Endpoint::STATUS_ARCHIVED;
    const STATUS_PURGED       = Provision_Endpoint::STATUS_PURGED;
}