<?php
/**
 * Sharehaus Provisioning Engine — Idempotency Table Schema.
 *
 * @package Sharehaus_Provisioning
 * @since 1.0.0
 */

namespace Sharehaus\Provisioning;

defined('ABSPATH') || exit;

/**
 * Creates the provisioning operations table.
 *
 * Stores durable idempotency keys and provisioning state machine records.
 */
class Provisioning_Table {

    const TABLE_NAME = 'provisioning_operations';

    /**
     * Run on plugin activation.
     */
    public static function install(): void {
        global $wpdb;

        $table = $wpdb->base_prefix . self::TABLE_NAME;

        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            provision_id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            idempotency_key VARCHAR(64) NOT NULL,
            command_id      VARCHAR(64) DEFAULT NULL,
            requested_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status          VARCHAR(24) NOT NULL DEFAULT 'accepted',
            current_step    VARCHAR(48) DEFAULT NULL,
            site_id         BIGINT UNSIGNED DEFAULT NULL,
            customer_id     BIGINT UNSIGNED DEFAULT NULL,
            membership_id   BIGINT UNSIGNED DEFAULT NULL,
            op_profile      VARCHAR(48) DEFAULT NULL,
            profile_version INT UNSIGNED DEFAULT NULL,
            request_payload LONGTEXT DEFAULT NULL,
            last_error      TEXT DEFAULT NULL,
            completed_at    DATETIME DEFAULT NULL,
            PRIMARY KEY (provision_id),
            UNIQUE KEY uk_idempotency (idempotency_key),
            KEY idx_status (status),
            KEY idx_site (site_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Claim an idempotency key. Returns existing provision_id if already claimed.
     */
    public static function claim(string $key, array $payload): ?int {
        global $wpdb;

        $table = $wpdb->base_prefix . self::TABLE_NAME;

        // Check existing
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT provision_id FROM {$table} WHERE idempotency_key = %s",
            $key
        ));

        if ($existing) {
            return (int) $existing;
        }

        $inserted = $wpdb->insert($table, [
            'idempotency_key' => $key,
            'command_id'      => $payload['command_id'] ?? null,
            'request_payload' => wp_json_encode($payload),
            'op_profile'      => $payload['operational_profile'] ?? null,
            'profile_version' => $payload['profile_version'] ?? null,
        ]);

        return $inserted ? (int) $wpdb->insert_id : null;
    }

    /**
     * Update the provisioning operation.
     */
    public static function update(int $provision_id, array $fields): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->base_prefix . self::TABLE_NAME,
            $fields,
            ['provision_id' => $provision_id]
        );
    }

    /**
     * Get provisioning operation by provision_id.
     */
    public static function get(int $provision_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->base_prefix}" . self::TABLE_NAME . " WHERE provision_id = %d",
            $provision_id
        ));
    }

    /**
     * Get provisioning operation by idempotency_key.
     */
    public static function get_by_key(string $key): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->base_prefix}" . self::TABLE_NAME . " WHERE idempotency_key = %s",
            $key
        ));
    }
}