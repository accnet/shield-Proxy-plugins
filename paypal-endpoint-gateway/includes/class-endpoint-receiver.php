<?php
/**
 * Endpoint Receiver
 * REST API receiver for push config updates from SaaS
 *
 * @package Endpoint_Gateway
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Shield_PayPal_Endpoint_Receiver
{
    private static function prefix()
    {
        return 'EP_PP_';
    }

    private static function opt($key)
    {
        return self::prefix() . $key;
    }

    /**
     * Register REST API route for receiving config push from SaaS.
     */
    public static function init()
    {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_filter('rest_pre_dispatch', [__CLASS__, 'rest_pre_dispatch'], 10, 3);
    }

    public static function register_routes()
    {
        register_rest_route('shield-endpoint/v1', '/config-update', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_config_update'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Pre-dispatch filter to intercept route and check type before other plugins can block.
     */
    public static function rest_pre_dispatch($result, $server, $request)
    {
        if ($request->get_route() === '/shield-endpoint/v1/config-update') {
            $body_raw = $request->get_body();
            $data = json_decode($body_raw, true);
            if (isset($data['type']) && $data['type'] === 'paypal') {
                return self::handle_config_update($request);
            }
        }
        return $result;
    }

    /**
     * Handle config update push from SaaS.
     *
     * Expected headers: X-SaaS-Signature, X-SaaS-Timestamp
     * Expected body: { endpointId, type, enableRotation, rotationMethod, nodes[] }
     */
    public static function handle_config_update($request)
    {
        // Verify HMAC signature
        $signature = $request->get_header('X-SaaS-Signature');
        $timestamp = $request->get_header('X-SaaS-Timestamp');
        $body_raw  = $request->get_body();

        if (empty($signature) || empty($timestamp)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Missing signature headers',
            ], 401);
        }

        if (!self::verify_signature($signature, $timestamp, $body_raw)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid HMAC signature',
            ], 401);
        }

        $data = json_decode($body_raw, true);
        if (empty($data)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid JSON body',
            ], 400);
        }

        // Update local config
        if (isset($data['nodes']) && is_array($data['nodes'])) {
            update_option(self::opt('NODES'), $data['nodes'], true);
        }
        if (isset($data['enableRotation'])) {
            update_option(self::opt('ENABLE_ROTATION'), (bool) $data['enableRotation'], true);
        }
        if (isset($data['rotationMethod'])) {
            update_option(self::opt('ROTATION_METHOD'), sanitize_text_field($data['rotationMethod']), true);
        }

        self::log('Config update received: ' . count($data['nodes'] ?? []) . ' nodes');

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Config updated',
        ], 200);
    }

    /**
     * Verify HMAC-SHA256 signature.
     *
     * Format: HMAC-SHA256(hmacSecret, timestamp + "." + body)
     */
    private static function verify_signature($signature, $timestamp, $body_raw)
    {
        $secret = get_option(self::opt('HMAC_SECRET'), '');
        if (empty($secret)) {
            return false;
        }

        // Timestamp tolerance: 15 minutes
        $now = time();
        $ts = intval($timestamp);
        if (abs($now - $ts) > 900) {
            self::log('Signature rejected: timestamp too old (' . abs($now - $ts) . 's)');
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $body_raw, $secret);

        return hash_equals($expected, $signature);
    }

    private static function log($message)
    {
        if ($logger = wc_get_logger()) {
            $logger->debug($message, ['source' => 'endpoint-gateway-receiver']);
        }
    }
}
