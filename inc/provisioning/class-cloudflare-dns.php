<?php
/**
 * Cloudflare DNS integration.
 *
 * Adds/updates DNS records for tenant sites via the Cloudflare API v4.
 * Optional — skips silently when no API token is configured.
 *
 * Token lookup order:
 *   1. CLOUDFLARE_API_TOKEN constant (wp-config.php)
 *   2. sharehaus/cloudflare/api-token (pass password store)
 *   3. get_site_option('sharehaus_cloudflare_api_token')
 *
 * Zone lookup order:
 *   1. CLOUDFLARE_ZONE_ID constant
 *   2. sharehaus/cloudflare/zone-id (pass)
 *   3. Auto-lookup via Cloudflare API (costs one extra API call)
 *
 * @package Sharehaus_Provisioning
 * @since 1.0.0
 */

namespace Sharehaus\Provisioning;

defined('ABSPATH') || exit;

class Cloudflare_DNS {

    /**
     * Base URL for the Cloudflare API v4.
     */
    const API_BASE = 'https://api.cloudflare.com/client/v4';

    /**
     * Add or update a DNS A/AAAA record for a tenant domain.
     *
     * @param string $domain  The domain to point (e.g. mybrand.sharehaus.coffee).
     * @param string $target  Target IP or CNAME hostname.
     * @param string $type    Record type: 'A', 'AAAA', or 'CNAME'.
     * @return array|\WP_Error Response array or error.
     */
    public static function set_dns_record(string $domain, string $target, string $type = 'CNAME'): array|\WP_Error {
        $token = self::get_token();
        if (is_wp_error($token)) {
            return $token; // Silent skip when no token
        }

        $zone_id = self::get_zone_id($token);
        if (is_wp_error($zone_id)) {
            return $zone_id;
        }

        // Check for existing record
        $existing = self::find_record($token, $zone_id, $domain, $type);

        if (! is_wp_error($existing) && ! empty($existing)) {
            // Update existing record
            $record_id = $existing[0]['id'];
            return self::api_request("zones/{$zone_id}/dns_records/{$record_id}", $token, 'PUT', [
                'type'    => $type,
                'name'    => $domain,
                'content' => $target,
                'ttl'     => 1, // Auto
                'proxied' => true,
            ]);
        }

        // Create new record
        return self::api_request("zones/{$zone_id}/dns_records", $token, 'POST', [
            'type'    => $type,
            'name'    => $domain,
            'content' => $target,
            'ttl'     => 1,
            'proxied' => true,
        ]);
    }

    /**
     * Remove a DNS record for a tenant domain.
     */
    public static function remove_dns_record(string $domain): array|\WP_Error {
        $token = self::get_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $zone_id = self::get_zone_id($token);
        if (is_wp_error($zone_id)) {
            return $zone_id;
        }

        $existing = self::find_record($token, $zone_id, $domain, 'CNAME');
        if (is_wp_error($existing) || empty($existing)) {
            return $existing;
        }

        return self::api_request("zones/{$zone_id}/dns_records/{$existing[0]['id']}", $token, 'DELETE');
    }

    // ────────── Private helpers ──────────

    private static function get_token(): string|\WP_Error {
        if (defined('CLOUDFLARE_API_TOKEN') && CLOUDFLARE_API_TOKEN) {
            return CLOUDFLARE_API_TOKEN;
        }

        $pass = trim((string) shell_exec('pass show sharehaus/cloudflare/api-token 2>/dev/null'));
        if ($pass) {
            return $pass;
        }

        $option = get_site_option('sharehaus_cloudflare_api_token');
        if ($option) {
            return $option;
        }

        return new \WP_Error('no_cf_token', 'Cloudflare API token not configured');
    }

    private static function get_zone_id(string $token): string|\WP_Error {
        if (defined('CLOUDFLARE_ZONE_ID') && CLOUDFLARE_ZONE_ID) {
            return CLOUDFLARE_ZONE_ID;
        }

        $pass = trim((string) shell_exec('pass show sharehaus/cloudflare/zone-id 2>/dev/null'));
        if ($pass) {
            return $pass;
        }

        $option = get_site_option('sharehaus_cloudflare_zone_id');
        if ($option) {
            return $option;
        }

        // Auto-lookup: find zone by the main site's domain
        $main_domain = wp_parse_url(network_site_url(), PHP_URL_HOST);
        $result = self::api_request('zones', $token, 'GET', ['name' => $main_domain]);

        if (is_wp_error($result)) {
            return $result;
        }

        if (! empty($result['result'])) {
            return $result['result'][0]['id'];
        }

        return new \WP_Error('no_cf_zone', 'Could not determine Cloudflare zone');
    }

    private static function find_record(string $token, string $zone_id, string $domain, string $type): array|\WP_Error {
        $result = self::api_request("zones/{$zone_id}/dns_records", $token, 'GET', [
            'name' => $domain,
            'type' => $type,
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        return $result['result'] ?? [];
    }

    private static function api_request(string $path, string $token, string $method = 'GET', array $params = []): array|\WP_Error {
        $url = self::API_BASE . '/' . ltrim($path, '/');

        $args = [
            'method'    => $method,
            'headers'   => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'timeout'   => 15,
            'sslverify' => true,
        ];

        if ($method === 'GET' && ! empty($params)) {
            $url = add_query_arg($params, $url);
        } elseif (in_array($method, ['POST', 'PUT', 'PATCH'], true) && ! empty($params)) {
            $args['body'] = wp_json_encode($params);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (! $body || ! ($body['success'] ?? false)) {
            $errors = isset($body['errors']) ? wp_json_encode($body['errors']) : 'Unknown API error';
            return new \WP_Error(
                'cf_api_error',
                sprintf('Cloudflare API error: %s', $errors)
            );
        }

        return $body;
    }
}