<?php
if (!defined('ABSPATH')) { exit; }
class Shield_API_Client {
    const TIMEOUT = 15;

    private static function sign($license_key, $timestamp) {
        return hash_hmac('sha256', $license_key . '.' . (string)$timestamp, $license_key);
    }

    private static function has_v2_credentials($site) {
        return !empty($site['manager_id']) && !empty($site['key_id']) && !empty($site['hmac_secret']);
    }

    private static function route_path($endpoint) {
        return '/shield/' . ltrim((string)$endpoint, '/');
    }

    private static function route_url($site, $endpoint) {
        return trailingslashit($site['url']) . 'wp-json/shield/' . ltrim((string)$endpoint, '/');
    }

    private static function route_url_v2($site, $endpoint) {
        return trailingslashit($site['url']) . 'wp-json/shield/v2/' . ltrim((string)$endpoint, '/');
    }

    private static function auth_headers($site, $method, $route_path, $body_raw = '') {
        $ts = time();

        if (self::has_v2_credentials($site)) {
            $nonce = wp_generate_uuid4();
            $canonical = implode("\n", [
                strtoupper((string)$method),
                (string)$route_path,
                hash('sha256', (string)$body_raw),
                (string)$ts,
                (string)$nonce,
                (string)$site['manager_id'],
                (string)$site['key_id'],
            ]);
            $sig = hash_hmac('sha256', $canonical, (string)$site['hmac_secret']);

            return [
                'X-Shield-Signature'  => $sig,
                'X-Shield-Timestamp'  => (string)$ts,
                'X-Shield-Nonce'      => (string)$nonce,
                'X-Shield-Manager-Id' => (string)$site['manager_id'],
                'X-Shield-Key-Id'     => (string)$site['key_id'],
            ];
        }

        return [
            'X-Shield-Signature' => self::sign($site['license_key'], $ts),
            'X-Shield-Timestamp' => (string)$ts,
            'X-Shield-License'   => $site['license_key'],
        ];
    }

    public static function build_signed_headers_for_url($site, $method, $url, $body_raw = '') {
        $parts = wp_parse_url((string)$url);
        $path = isset($parts['path']) ? $parts['path'] : '/';
        $query = isset($parts['query']) && $parts['query'] !== '' ? ('?' . $parts['query']) : '';
        return self::auth_headers($site, $method, $path . $query, (string)$body_raw);
    }

    public static function post($site, $endpoint, $payload) {
        $body = wp_json_encode(array_merge($payload, [
            'shield' => [
                'license_key' => $site['license_key'],
                'domain'      => get_site_url(),
                'manager_id'  => $site['manager_id'] ?? '',
            ],
        ]));
        $route_path = self::route_path($endpoint);
        $response = wp_remote_post(
            self::route_url($site, $endpoint),
            [
                'headers'   => array_merge(
                    self::auth_headers($site, 'POST', $route_path, (string)$body),
                    ['Content-Type' => 'application/json']
                ),
                'body'      => $body,
                'timeout'   => self::TIMEOUT,
                'sslverify' => false,
            ]
        );
        return self::parse_response($response);
    }
    public static function get($site, $endpoint) {
        $route_path = self::route_path($endpoint);
        $response = wp_remote_get(
            self::route_url($site, $endpoint),
            [
                'headers'   => self::auth_headers($site, 'GET', $route_path, ''),
                'timeout'   => self::TIMEOUT,
                'sslverify' => false,
            ]
        );
        return self::parse_response($response);
    }

    public static function bootstrap_v2($site, $bootstrap_token) {
        $payload = [
            'manager_id' => $site['manager_id'] ?? '',
            'key_id' => $site['key_id'] ?? '',
            'hmac_secret' => $site['hmac_secret'] ?? '',
            'label' => get_bloginfo('name'),
        ];

        $response = wp_remote_post(
            self::route_url($site, 'v2/bootstrap'),
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Shield-Bootstrap-Token' => (string)$bootstrap_token,
                ],
                'body' => wp_json_encode($payload),
                'timeout' => self::TIMEOUT,
                'sslverify' => false,
            ]
        );

        return self::parse_response($response);
    }

    public static function rotate_v2($site, $bootstrap_token, $revoke_old = true) {
        $payload = [
            'manager_id' => $site['manager_id'] ?? '',
            'key_id' => $site['key_id'] ?? '',
            'revoke_old' => (bool)$revoke_old,
        ];

        $response = wp_remote_post(
            self::route_url_v2($site, 'rotate'),
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Shield-Bootstrap-Token' => (string)$bootstrap_token,
                ],
                'body' => wp_json_encode($payload),
                'timeout' => self::TIMEOUT,
                'sslverify' => false,
            ]
        );

        return self::parse_response($response);
    }

    public static function revoke_v2($site, $bootstrap_token) {
        $payload = [
            'manager_id' => $site['manager_id'] ?? '',
            'key_id' => $site['key_id'] ?? '',
        ];

        $response = wp_remote_post(
            self::route_url_v2($site, 'revoke'),
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Shield-Bootstrap-Token' => (string)$bootstrap_token,
                ],
                'body' => wp_json_encode($payload),
                'timeout' => self::TIMEOUT,
                'sslverify' => false,
            ]
        );

        return self::parse_response($response);
    }

    public static function set_primary_v2($site, $bootstrap_token) {
        $payload = [
            'manager_id' => $site['manager_id'] ?? '',
        ];

        $response = wp_remote_post(
            self::route_url_v2($site, 'set-primary'),
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Shield-Bootstrap-Token' => (string)$bootstrap_token,
                ],
                'body' => wp_json_encode($payload),
                'timeout' => self::TIMEOUT,
                'sslverify' => false,
            ]
        );

        return self::parse_response($response);
    }

    public static function health($site)                   { return self::get($site, 'health'); }
    public static function push_paypal($site, $settings)   { return self::post($site, 'paypal', ['data' => $settings]); }
    public static function push_stripe($site, $settings)   { return self::post($site, 'stripe', ['data' => $settings]); }
    public static function push_sync($site, $data)         { return self::post($site, 'sync',   ['data' => $data]); }

    private static function parse_response($response) {
        if (is_wp_error($response)) {
            return ['success' => false, 'code' => 0, 'body' => [], 'error' => $response->get_error_message()];
        }
        $code = (int)wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        $body = json_decode($raw, true) ?? [];
        return [
            'success' => $code === 200,
            'code'    => $code,
            'body'    => $body,
            'error'   => $code !== 200 ? ($body['message'] ?? "HTTP {$code}") : '',
        ];
    }
}
