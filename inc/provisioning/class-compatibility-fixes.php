<?php
/**
 * ShareHaus fork compatibility fixes.
 *
 * Ensures MPDF temp dirs and missing font variants exist after
 * plugin activation or update.
 *
 * @package Sharehaus_Provisioning
 * @since 1.0.0
 */

namespace Sharehaus\Provisioning;

defined('ABSPATH') || exit;

class Compatibility_Fixes {

    /**
     * Run all fixes. Called on plugin activation and upgrade.
     */
    public static function run(): void {
        self::fix_mpdf_tmp_dir();
        self::fix_mpdf_fonts();
    }

    /**
     * Ensure the MPDF tmp directory exists and is writable.
     */
    private static function fix_mpdf_tmp_dir(): void {
        $tmp_dir = WP_ULTIMO_PLUGIN_DIR . '/vendor/mpdf/mpdf/tmp';

        if (! is_dir($tmp_dir)) {
            wp_mkdir_p($tmp_dir);
        }

        if (is_dir($tmp_dir) && ! is_writable($tmp_dir)) {
            chmod($tmp_dir, 0755);
        }
    }

    /**
     * Create symlinks for missing DejaVuSerifCondensed italic variants.
     * MPDF references these but the distribution only ships Roman + Bold.
     */
    private static function fix_mpdf_fonts(): void {
        $ttfonts = WP_ULTIMO_PLUGIN_DIR . '/vendor/mpdf/mpdf/ttfonts';

        $missing = [
            'DejaVuSerifCondensed-Italic.ttf'     => 'DejaVuSerifCondensed.ttf',
            'DejaVuSerifCondensed-BoldItalic.ttf'  => 'DejaVuSerifCondensed-Bold.ttf',
        ];

        foreach ($missing as $target => $source) {
            $target_path = $ttfonts . '/' . $target;
            $source_path = $ttfonts . '/' . $source;

            if (file_exists($target_path) || ! file_exists($source_path)) {
                continue;
            }

            if (function_exists('symlink')) {
                symlink($source_path, $target_path);
            } else {
                copy($source_path, $target_path);
            }
        }
    }
}