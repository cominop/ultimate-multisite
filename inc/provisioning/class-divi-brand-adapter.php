<?php
/**
 * Brand Pusher — Divi Adapter.
 *
 * Applies Studio brand data (colors, fonts, logo) to a Divi-powered WordPress site.
 * Called post-provisioning via the Bootstrap hook chain.
 *
 * All operations use switch_to_blog / restore_current_blog with finally-guard.
 * Logo URL is restricted to studio.sharehaus.coffee (SSRF prevention).
 *
 * @package Sharehaus_Provisioning
 * @since 1.0.0
 */

namespace Sharehaus\Provisioning;

defined('ABSPATH') || exit;

class Divi_Brand_Adapter {

    /** Allowed logo host (SSRF prevention) */
    const ALLOWED_HOST = 'studio.sharehaus.coffee';

    /**
     * Push brand data into a site.
     *
     * @param int   $site_id WordPress site ID.
     * @param array $brand   Brand data: primary_color, secondary_color, accent_color,
     *                       heading_font, body_font, logo_url.
     * @return array|WP_Error Result with applied values or error.
     */
    public static function push(int $site_id, array $brand): array|\WP_Error {

        switch_to_blog($site_id);

        try {
            $result = [];

            // 1. Colors → Divi global presets
            if (! empty($brand['primary_color']) || ! empty($brand['secondary_color'])) {
                $colors_result = self::apply_colors($brand);
                $result = array_merge($result, $colors_result);
            }

            // 2. Fonts → Theme mods
            if (! empty($brand['heading_font']) || ! empty($brand['body_font'])) {
                $fonts_result = self::apply_fonts($brand);
                $result = array_merge($result, $fonts_result);
            }

            // 3. Logo → Sideload + set custom_logo
            if (! empty($brand['logo_url'])) {
                $logo_result = self::apply_logo($brand['logo_url']);
                if (is_wp_error($logo_result)) {
                    restore_current_blog();
                    return $logo_result;
                }
                $result = array_merge($result, $logo_result);
            }

            return $result;

        } catch (\Throwable $e) {
            restore_current_blog();
            return new \WP_Error('brand_push_error', $e->getMessage());
        }
    }

    /**
     * Apply colors to Divi global presets.
     */
    private static function apply_colors(array $brand): array {
        $primary   = $brand['primary_color'] ?? '';
        $secondary = $brand['secondary_color'] ?? '';
        $accent    = $brand['accent_color'] ?? '';

        // Update et_divi option (Divi 5 native global colors)
        $divi_settings = get_option('et_divi', []);
        $divi_settings = is_array($divi_settings) ? $divi_settings : [];

        if ($primary) {
            $divi_settings['primary_color'] = $primary;
        }
        if ($secondary) {
            $divi_settings['secondary_color'] = $secondary;
        }
        if ($accent) {
            $divi_settings['accent_color'] = $accent;
        }

        update_option('et_divi', $divi_settings);

        // Also set WordPress Customizer colors for theme compatibility
        if ($primary) {
            set_theme_mod('primary_color', $primary);
        }
        if ($secondary) {
            set_theme_mod('secondary_color', $secondary);
        }

        return [
            'primary_color_applied'   => ! empty($primary),
            'secondary_color_applied' => ! empty($secondary),
            'accent_color_applied'    => ! empty($accent),
        ];
    }

    /**
     * Apply fonts to theme mods.
     */
    private static function apply_fonts(array $brand): array {
        $heading = $brand['heading_font'] ?? '';
        $body    = $brand['body_font'] ?? '';

        $result = [];

        if ($heading) {
            set_theme_mod('heading_font', $heading);
            $result['heading_font_applied'] = true;
        }

        if ($body) {
            set_theme_mod('body_font', $body);
            $result['body_font_applied'] = true;
        }

        return $result;
    }

    /**
     * Sideload logo, create attachment, set custom_logo.
     *
     * Follows WordPress convention: custom_logo expects an attachment ID.
     */
    private static function apply_logo(string $logo_url): array|\WP_Error {

        // SSRF guard: only allow approved hosts
        $host = wp_parse_url($logo_url, PHP_URL_HOST);
        if (! $host || $host !== self::ALLOWED_HOST) {
            return new \WP_Error(
                'invalid_logo_host',
                sprintf('Logo URL host must be %s. Got: %s', self::ALLOWED_HOST, $host ?: 'none')
            );
        }

        // Download the image
        $tmp = download_url($logo_url);
        if (is_wp_error($tmp)) {
            return new \WP_Error('logo_download_failed', $tmp->get_error_message());
        }

        // Prepare file array for sideload
        $file_array = [
            'name'     => 'brand-logo-' . md5($logo_url) . '.png',
            'tmp_name' => $tmp,
        ];

        // Sideload into media library
        $attachment_id = media_handle_sideload($file_array, 0);

        // Clean up temp file
        if (file_exists($tmp)) {
            unlink($tmp);
        }

        if (is_wp_error($attachment_id)) {
            return new \WP_Error('logo_sideload_failed', $attachment_id->get_error_message());
        }

        // Set as custom logo
        set_theme_mod('custom_logo', $attachment_id);

        return [
            'logo_applied'   => true,
            'logo_attachment_id' => $attachment_id,
        ];
    }
}