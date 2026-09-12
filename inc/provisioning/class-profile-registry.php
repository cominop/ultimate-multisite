<?php
/**
 * Operational Profile Registry.
 *
 * Maps Studio plans (e.g. "barista") to WordPress operational configurations.
 * Profiles are versioned and immutable — changes create a new version.
 *
 * @package Sharehaus_Provisioning
 * @since 1.0.0
 */

namespace Sharehaus\Provisioning;

defined('ABSPATH') || exit;

class Profile_Registry {

    const OPTION_KEY = 'sharehaus_operational_profiles';

    /**
     * Get all profiles.
     */
    public static function get_all(): array {
        return get_site_option(self::OPTION_KEY, self::defaults());
    }

    /**
     * Get a specific profile version.
     *
     * @return array|null Profile config or null if not found.
     */
    public static function get(string $profile_id, int $version): ?array {
        $profiles = self::get_all();

        if (! isset($profiles[$profile_id]['versions'][$version])) {
            return null;
        }

        $profile = $profiles[$profile_id]['versions'][$version];
        $profile['id']      = $profile_id;
        $profile['version'] = $version;

        return $profile;
    }

    /**
     * Get latest version of a profile.
     */
    public static function get_latest(string $profile_id): ?array {
        $profiles = self::get_all();
        if (! isset($profiles[$profile_id], $profiles[$profile_id]['latest'])) {
            return null;
        }
        return self::get($profile_id, $profiles[$profile_id]['latest']);
    }

    /**
     * Add a new profile version (immutable — never overwrites existing).
     */
    public static function add(string $profile_id, array $config): int {
        $profiles = self::get_all();

        if (! isset($profiles[$profile_id])) {
            $profiles[$profile_id] = ['latest' => 0, 'versions' => []];
        }

        $new_version = $profiles[$profile_id]['latest'] + 1;

        $profiles[$profile_id]['versions'][$new_version] = [
            'network_dependencies' => $config['network_dependencies'] ?? [],
            'site_plugins'         => $config['site_plugins'] ?? [],
            'capabilities'         => $config['capabilities'] ?? [],
            'limits'              => $config['limits'] ?? [],
            'created_at'          => current_time('mysql'),
        ];

        $profiles[$profile_id]['latest'] = $new_version;

        update_site_option(self::OPTION_KEY, $profiles);

        return $new_version;
    }

    /**
     * List available profiles (for the API).
     */
    public static function list_available(): array {
        $profiles = self::get_all();
        $result   = [];

        foreach ($profiles as $id => $data) {
            $result[] = [
                'id'           => $id,
                'latest_version' => $data['latest'],
                'available'    => true,
            ];
        }

        return $result;
    }

    /**
     * Default profiles shipped with the plugin.
     */
    public static function defaults(): array {
        return [
            'aficionado' => [
                'latest' => 1,
                'versions' => [
                    1 => [
                        'network_dependencies' => [
                            'woocommerce/woocommerce.php',
                            'sharehaus-core/sharehaus-core.php',
                        ],
                        'site_plugins' => [
                            'woocommerce-subscriptions/woocommerce-subscriptions.php' => ['enabled' => false],
                            'affiliate-module/affiliate-module.php'                 => ['enabled' => false],
                        ],
                        'capabilities' => [
                            'subscriptions'      => false,
                            'affiliate_marketing' => false,
                            'advanced_email'     => false,
                        ],
                        'limits' => [
                            'products' => 18,
                            'users'    => 2,
                        ],
                        'created_at' => '2026-01-01 00:00:00',
                    ],
                ],
            ],
            'barista' => [
                'latest' => 1,
                'versions' => [
                    1 => [
                        'network_dependencies' => [
                            'woocommerce/woocommerce.php',
                            'sharehaus-core/sharehaus-core.php',
                        ],
                        'site_plugins' => [
                            'woocommerce-subscriptions/woocommerce-subscriptions.php' => ['enabled' => true],
                            'affiliate-module/affiliate-module.php'                 => ['enabled' => false],
                        ],
                        'capabilities' => [
                            'subscriptions'      => true,
                            'affiliate_marketing' => false,
                            'advanced_email'     => true,
                        ],
                        'limits' => [
                            'products' => 25,
                            'users'    => 3,
                        ],
                        'created_at' => '2026-01-01 00:00:00',
                    ],
                ],
            ],
            'master-roaster' => [
                'latest' => 1,
                'versions' => [
                    1 => [
                        'network_dependencies' => [
                            'woocommerce/woocommerce.php',
                            'sharehaus-core/sharehaus-core.php',
                        ],
                        'site_plugins' => [
                            'woocommerce-subscriptions/woocommerce-subscriptions.php' => ['enabled' => true],
                            'affiliate-module/affiliate-module.php'                 => ['enabled' => true],
                        ],
                        'capabilities' => [
                            'subscriptions'      => true,
                            'affiliate_marketing' => true,
                            'advanced_email'     => true,
                        ],
                        'limits' => [
                            'products' => 'unlimited',
                            'users'    => 10,
                        ],
                        'created_at' => '2026-01-01 00:00:00',
                    ],
                ],
            ],
        ];
    }

    /**
     * Reset to defaults (for development).
     */
    public static function reset(): void {
        delete_site_option(self::OPTION_KEY);
    }
}