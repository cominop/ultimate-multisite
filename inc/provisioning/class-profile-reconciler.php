<?php
/**
 * Profile Reconciliation Engine.
 *
 * Compares a site's actual state against its operational profile and reconciles.
 * Drives PUT /profile, PUT /capabilities, and PUT /plugins endpoints.
 *
 * @package Sharehaus_Provisioning
 * @since 1.0.0
 */

namespace Sharehaus\Provisioning;

defined('ABSPATH') || exit;

class Profile_Reconciler {

    /**
     * Reconcile a site against a full operational profile.
     *
     * @param int   $site_id     WordPress site ID.
     * @param array $profile     Profile config from Profile_Registry.
     * @param bool  $is_upgrade  True if this is an upgrade (enable new), false if downgrade.
     * @return array Changes made.
     */
    public static function reconcile(int $site_id, array $profile, bool $is_upgrade = true): array {
        $changes = [
            'site_plugins_activated'     => [],
            'site_plugins_deactivated'   => [],
            'network_dependencies_verified' => [],
            'capabilities_enabled'       => [],
            'capabilities_disabled'      => [],
            'limits_changed'            => [],
        ];

        switch_to_blog($site_id);

        try {
            // 1. Verify network dependencies exist
            foreach ($profile['network_dependencies'] ?? [] as $plugin) {
                if (is_plugin_active_for_network($plugin)) {
                    $changes['network_dependencies_verified'][] = $plugin;
                }
            }

            // 2. Reconcile site-level plugins
            $desired_plugins = $profile['site_plugins'] ?? [];
            foreach ($desired_plugins as $plugin_slug => $config) {
                $should_be_active = ! empty($config['enabled']);
                $is_active        = is_plugin_active($plugin_slug);

                if ($should_be_active && ! $is_active) {
                    activate_plugin($plugin_slug);
                    $changes['site_plugins_activated'][] = $plugin_slug;
                } elseif (! $should_be_active && $is_active) {
                    // DOWGRADE: deactivate but never uninstall, never delete data
                    deactivate_plugins($plugin_slug, true); // silent deactivation
                    $changes['site_plugins_deactivated'][] = $plugin_slug;
                }
            }

            // 3. Reconcile capabilities (feature flags stored in site options)
            $desired_caps = $profile['capabilities'] ?? [];
            $current_caps = get_option('sharehaus_capabilities', []);

            foreach ($desired_caps as $cap => $enabled) {
                $currently = ! empty($current_caps[$cap]);

                if ($enabled && ! $currently) {
                    $current_caps[$cap] = true;
                    $changes['capabilities_enabled'][] = $cap;
                } elseif (! $enabled && $currently) {
                    $current_caps[$cap] = false;
                    $changes['capabilities_disabled'][] = $cap;
                }
            }

            update_option('sharehaus_capabilities', $current_caps);

            // 4. Reconcile limits
            $desired_limits = $profile['limits'] ?? [];
            $current_limits = get_option('sharehaus_limits', []);

            foreach ($desired_limits as $limit_key => $value) {
                $existing = $current_limits[$limit_key] ?? null;
                if ($existing !== $value) {
                    $current_limits[$limit_key] = $value;
                    $changes['limits_changed'][] = $limit_key;
                }
            }

            update_option('sharehaus_limits', $current_limits);

            // 5. Store current profile reference on the site
            update_option('sharehaus_operational_profile', [
                'id'      => $profile['id'] ?? '',
                'version' => $profile['version'] ?? 0,
                'applied_at' => current_time('mysql'),
            ]);

        } finally {
            restore_current_blog();
        }

        return $changes;
    }

    /**
     * Reconcile only capabilities (override without changing profile).
     */
    public static function reconcile_capabilities(int $site_id, array $capabilities): array {
        switch_to_blog($site_id);

        try {
            $current_caps = get_option('sharehaus_capabilities', []);
            $changes = ['enabled' => [], 'disabled' => []];

            foreach ($capabilities as $cap => $enabled) {
                $currently = ! empty($current_caps[$cap]);
                if ($enabled && ! $currently) {
                    $current_caps[$cap] = true;
                    $changes['enabled'][] = $cap;
                } elseif (! $enabled && $currently) {
                    $current_caps[$cap] = false;
                    $changes['disabled'][] = $cap;
                }
            }

            update_option('sharehaus_capabilities', $current_caps);
            return $changes;

        } finally {
            restore_current_blog();
        }
    }

    /**
     * Reconcile only plugins (support/migration/repair).
     *
     * @param array $desired_plugins Plugin slugs that should be active.
     */
    public static function reconcile_plugins(int $site_id, array $desired_plugins): array {
        switch_to_blog($site_id);

        try {
            $changes = ['activated' => [], 'deactivated' => []];

            // Get all active plugins to find ones NOT in the desired list
            $active = get_option('active_plugins', []);

            // Activate desired plugins
            foreach ($desired_plugins as $plugin) {
                if (! in_array($plugin, $active, true)) {
                    activate_plugin($plugin);
                    $changes['activated'][] = $plugin;
                }
            }

            // Deactivate plugins that aren't in desired list
            // CAREFUL: never deactivate network-active plugins
            foreach ($active as $plugin) {
                if (! in_array($plugin, $desired_plugins, true) && ! is_plugin_active_for_network($plugin)) {
                    deactivate_plugins($plugin, true);
                    $changes['deactivated'][] = $plugin;
                }
            }

            return $changes;

        } finally {
            restore_current_blog();
        }
    }

    /**
     * Get the current profile applied to a site.
     */
    public static function get_current_profile(int $site_id): ?array {
        switch_to_blog($site_id);
        $profile = get_option('sharehaus_operational_profile', null);
        restore_current_blog();
        return $profile;
    }
}