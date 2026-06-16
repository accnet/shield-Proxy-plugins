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

class Shield_Stripe_Endpoint_Receiver
{
    private static function prefix()
    {
        return 'EP_ST_';
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
        register_rest_route('shield-endpoint/v1', '/stripe/config-update', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_config_update'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('shield-endpoint/v1', '/stripe-webhook/direct', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_stripe_webhook_direct'],
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
            if (isset($data['type']) && $data['type'] === 'stripe') {
                return self::handle_config_update($request);
            }
        }
        return $result;
    }

    /**
     * Handle config update push from SaaS.
     *
     * Expected headers: X-SaaS-Signature, X-SaaS-Timestamp, X-SaaS-Nonce
     * Expected body: { endpointId, type, enableRotation, rotationMethod, nodes[] }
     */
    public static function handle_config_update($request)
    {
        // Verify HMAC signature
        $signature = $request->get_header('X-SaaS-Signature');
        $timestamp = $request->get_header('X-SaaS-Timestamp');
        $nonce     = $request->get_header('X-SaaS-Nonce');
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

        // Nonce replay protection (if nonce header is present)
        if (!empty($nonce)) {
            $nonce_key = 'ep_st_nonce_' . md5($nonce);
            if (get_transient($nonce_key)) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Nonce already used (replay detected)',
                ], 409);
            }
            set_transient($nonce_key, '1', 900); // 15 minutes
        }

        $data = json_decode($body_raw, true);
        if (empty($data)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid JSON body',
            ], 400);
        }
        if (($data['type'] ?? '') !== 'stripe') {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Endpoint type mismatch',
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
        // Save paused state pushed from SaaS
        $paused_bool = false;
        if (array_key_exists('paused', $data)) {
            $paused_bool = !empty($data['paused']);
            $paused_option = $paused_bool ? 'yes' : 'no';
            update_option(Shield_Stripe_Endpoint_Client::opt('PAUSED'), $paused_option, true);
        }

        // Auto-sync: update HMAC secret nếu được push từ SaaS sau khi rotate
        $secret_synced = false;
        if (!empty($data['endpointHmacSecret']) && is_string($data['endpointHmacSecret'])) {
            update_option(self::opt('HMAC_SECRET'), sanitize_text_field($data['endpointHmacSecret']), true);
            $secret_synced = true;
            self::log('HMAC secret auto-synced from SaaS rotate.');
        }

        update_option(self::opt('LAST_SYNC_AT'), time(), true);

        self::log('Config update received: ' . count($data['nodes'] ?? []) . ' nodes, paused=' . ($paused_bool ? 'yes' : 'no'));

        return new WP_REST_Response([
            'success'       => true,
            'paused'        => $paused_bool,
            'secret_synced' => $secret_synced,
            'provider'      => 'stripe',
            'message'       => $paused_bool ? 'Stripe endpoint gateway paused' : 'Stripe endpoint gateway resumed/active',
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

    public static function handle_stripe_webhook_direct(WP_REST_Request $request)
    {
        $auth = self::verify_site1_direct_hmac($request);
        if (!$auth['success']) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'invalid_signature',
                'message' => $auth['message'],
            ], 401);
        }

        $payload = json_decode($request->get_body(), true);
        if (!is_array($payload)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'invalid_payload',
                'message' => 'Invalid JSON payload',
            ], 400);
        }

        $payment_intent_id = sanitize_text_field($payload['paymentIntentId'] ?? '');
        $state = sanitize_text_field($payload['state'] ?? '');
        $order_id = absint($payload['orderId'] ?? 0);
        if (!$payment_intent_id || !$state || !$order_id) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'invalid_payload',
                'message' => 'paymentIntentId, state, and orderId are required',
            ], 400);
        }

        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$order) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'order_not_found',
                'message' => 'WooCommerce order not found',
            ], 404);
        }

        $activated_proxy = [
            'id' => sanitize_text_field($payload['shieldId'] ?? ''),
            'url' => esc_url_raw($payload['proxyUrl'] ?? ($payload['proxyId'] ?? '')),
            'shieldId' => sanitize_text_field($payload['shieldId'] ?? ''),
        ];
        $status_data = [
            'success' => true,
            'found' => true,
            'paymentState' => [
                'eventId' => sanitize_text_field($payload['eventId'] ?? ''),
                'paymentIntentId' => $payment_intent_id,
                'state' => $state,
            ],
        ];
        $trace_id = sanitize_text_field($payload['traceId'] ?? '');

        $result = function_exists('ep_stripe_apply_webhook_fallback_result')
            ? ep_stripe_apply_webhook_fallback_result($order, $activated_proxy, $status_data, $trace_id)
            : false;

        if (!$result) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'unsupported_state',
                'message' => 'Webhook state could not be applied',
            ], 400);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Stripe webhook state applied directly from site1',
            'handled' => !empty($result['handled']),
            'result' => $result,
        ], 200);
    }

    private static function verify_site1_direct_hmac(WP_REST_Request $request)
    {
        $manager_id = sanitize_text_field($request->get_header('X-Shield-Manager-Id') ?? '');
        $key_id = sanitize_text_field($request->get_header('X-Shield-Key-Id') ?? '');
        $signature = (string) $request->get_header('X-Shield-Signature');
        $nonce = sanitize_text_field($request->get_header('X-Shield-Nonce') ?? '');
        $timestamp = (int) $request->get_header('X-Shield-Timestamp');

        if (!$manager_id || !$key_id || !$signature || !$nonce || !$timestamp) {
            return ['success' => false, 'message' => 'Missing direct callback HMAC headers'];
        }

        if (abs(time() - $timestamp) > 300) {
            return ['success' => false, 'message' => 'Direct callback timestamp expired'];
        }

        $endpoint_id = (string) get_option(self::opt('ENDPOINT_ID'), '');
        if ($endpoint_id === '' || $manager_id !== 'mgr_' . $endpoint_id) {
            return ['success' => false, 'message' => 'Direct callback credential not found'];
        }

        $shield_id = str_starts_with($key_id, 'kid_') ? substr($key_id, 4) : '';
        if ($shield_id === '') {
            return ['success' => false, 'message' => 'Direct callback credential not found'];
        }

        $node = Shield_Stripe_Endpoint_Client::find_node_by_shield_id($shield_id);
        $derived_key = is_array($node) ? (string) ($node['derivedKey'] ?? '') : '';
        if ($derived_key === '') {
            return ['success' => false, 'message' => 'Direct callback credential not found'];
        }

        $nonce_key = 'ep_st_direct_n_' . md5($manager_id . '|' . $nonce . '|' . (string) $timestamp);
        if (get_transient($nonce_key)) {
            return ['success' => false, 'message' => 'Duplicate direct callback nonce'];
        }
        set_transient($nonce_key, '1', 300);

        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/wp-json/shield-endpoint/v1/stripe-webhook/direct';
        $canonical = implode("\n", [
            strtoupper($request->get_method()),
            $request_uri,
            hash('sha256', (string) $request->get_body()),
            (string) $timestamp,
            $nonce,
            $manager_id,
            $key_id,
        ]);
        $expected = hash_hmac('sha256', $canonical, $derived_key);

        if (!hash_equals($expected, $signature)) {
            return ['success' => false, 'message' => 'Direct callback HMAC verification failed'];
        }

        return ['success' => true, 'message' => 'ok'];
    }
}
