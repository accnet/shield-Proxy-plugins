<?php
/**
 * SaaS Sync Webhook Receiver
 * Handles incoming gateway sync notifications from the SaaS
 * 
 * @package Shield_Manager
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Shield_SaaS_Receiver
{
    /**
     * Initialize REST routes
     */
    public static function init()
    {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    /**
     * Register REST routes
     */
    public static function register_routes()
    {
        register_rest_route('shield-manager/v1', '/receive-sync', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_sync'],
            'permission_callback' => '__return_true', // checked via signature in handler
        ]);

        register_rest_route('shield-manager/v1', '/active-gateways', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_get_active_gateways'],
            'permission_callback' => '__return_true', // checked via signature in handler
        ]);

        register_rest_route('shield-manager/v1', '/force-activate-gateway', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_force_activate_gateway'],
            'permission_callback' => '__return_true', // checked via signature in handler
        ]);

        register_rest_route('shield-manager/v1', '/rotate-secret', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_rotate_secret'],
            'permission_callback' => '__return_true', // checked via HMAC in handler
        ]);

        register_rest_route('shield-manager/v1', '/stripe-webhook/direct', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_stripe_webhook_direct'],
            'permission_callback' => '__return_true', // checked via direct site1 HMAC in handler
        ]);
    }

    /**
     * Handle SaaS-initiated secret rotation.
     * Body: { "newSecret": "sec_xxx" }
     * Signed with the OLD secret. Updates OPT_SHIELD_SAAS_HMAC_SECRET.
     */
    public static function handle_rotate_secret(WP_REST_Request $request)
    {
        $auth_error = self::verify_saas_request($request);
        if ($auth_error !== null) {
            return $auth_error;
        }

        $raw_body = $request->get_body();
        $payload = json_decode($raw_body, true);
        $new_secret = isset($payload['newSecret']) ? trim((string) $payload['newSecret']) : '';

        if (empty($new_secret) || strpos($new_secret, 'sec_') !== 0) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'invalid_payload',
                'message' => 'newSecret is required and must start with sec_'
            ], 400);
        }

        Shield_Option_Manager::update('OPT_SHIELD_SAAS_HMAC_SECRET', $new_secret, true);

        return new WP_REST_Response([
            'success' => true,
            'message' => 'HMAC secret rotated successfully'
        ], 200);
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

        if (function_exists('csStripeApplyWebhookFallbackResult')) {
            $result = csStripeApplyWebhookFallbackResult($order, $activated_proxy, $status_data, $trace_id);
        } else {
            $result = self::apply_stripe_webhook_state($order, $payment_intent_id, $state, $activated_proxy);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Stripe webhook state applied directly from site1',
            'handled' => is_array($result) ? !empty($result['handled']) : (bool) $result,
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

        $site = null;
        foreach (Shield_Site_Registry::all() as $candidate) {
            if (($candidate['manager_id'] ?? '') === $manager_id && ($candidate['key_id'] ?? '') === $key_id) {
                $site = $candidate;
                break;
            }
        }
        if (!$site || empty($site['hmac_secret'])) {
            return ['success' => false, 'message' => 'Direct callback credential not found'];
        }

        $nonce_key = 'shield_mgr_direct_n_' . md5($manager_id . '|' . $nonce . '|' . (string) $timestamp);
        if (get_transient($nonce_key)) {
            return ['success' => false, 'message' => 'Duplicate direct callback nonce'];
        }
        set_transient($nonce_key, '1', 300);

        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/wp-json/shield-manager/v1/stripe-webhook/direct';
        $canonical = implode("\n", [
            strtoupper($request->get_method()),
            $request_uri,
            hash('sha256', (string) $request->get_body()),
            (string) $timestamp,
            $nonce,
            $manager_id,
            $key_id,
        ]);
        $expected = hash_hmac('sha256', $canonical, (string) $site['hmac_secret']);

        if (!hash_equals($expected, $signature)) {
            return ['success' => false, 'message' => 'Direct callback HMAC verification failed'];
        }

        return ['success' => true, 'message' => 'ok'];
    }

    private static function apply_stripe_webhook_state($order, $payment_intent_id, $state, $activated_proxy)
    {
        if ($state === 'succeeded') {
            $order->payment_complete();
            $order->reduce_order_stock();
            $order->update_meta_data('_cs_stripe_webhook_last_applied_state', 'succeeded');
        } elseif ($state === 'processing') {
            $order->update_status('on-hold', 'Waiting for Stripe webhook confirmation.');
            $order->update_meta_data('_cs_stripe_webhook_last_applied_state', 'processing');
        } elseif ($state === 'payment_failed') {
            $order->update_status('failed', 'Stripe webhook direct callback marked payment as failed.');
            $order->update_meta_data('_cs_stripe_webhook_last_applied_state', 'payment_failed');
        } else {
            return ['handled' => false, 'type' => 'ignored'];
        }

        $order->update_meta_data('_cs_stripe_webhook_last_repair_at', time());
        $order->add_order_note(sprintf(
            'Stripe webhook applied by direct site1 callback via proxy %s (Payment Intent ID: %s)',
            $activated_proxy['url'] ?? '',
            $payment_intent_id
        ));
        $order->save();

        return ['handled' => true, 'type' => $state];
    }

    /**
     * Verify an incoming SaaS request via HMAC + timestamp.
     * Returns null on success or a WP_REST_Response error on failure.
     *
     * Expected signature: HMAC-SHA256(hmacSecret, "$timestamp.$raw_body")
     * Header: X-SaaS-Signature, X-SaaS-Timestamp
     */
    private static function verify_saas_request(WP_REST_Request $request)
    {
        $connected = Shield_Option_Manager::get('OPT_SHIELD_SAAS_CONNECTED', 'no');
        if ($connected !== 'yes') {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'not_connected',
                'message' => 'Site is not connected to SaaS'
            ], 403);
        }

        $signature = (string) $request->get_header('x-saas-signature');
        $timestamp  = (int)   $request->get_header('x-saas-timestamp');
        $secret     = (string) Shield_Option_Manager::get('OPT_SHIELD_SAAS_HMAC_SECRET', '');

        if (empty($signature) || empty($timestamp) || empty($secret)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'missing_credentials',
                'message' => 'Missing signature, timestamp, or HMAC secret'
            ], 401);
        }

        if (abs(time() - $timestamp) > 300) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'timestamp_expired',
                'message' => 'Request timestamp is outside the 5-minute window'
            ], 401);
        }

        $raw_body = $request->get_body();
        $expected = hash_hmac('sha256', $timestamp . '.' . $raw_body, $secret);
        if (!hash_equals($expected, $signature)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'invalid_signature',
                'message' => 'HMAC signature verification failed'
            ], 401);
        }

        // Replay protection
        $nonce_key = 'shield_saas_n_' . md5($signature . '|' . $timestamp . '|' . $secret);
        if (get_transient($nonce_key)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'duplicate_request',
                'message' => 'Duplicate request detected'
            ], 401);
        }
        set_transient($nonce_key, '1', 300);

        return null;
    }

    /**
     * Handle active-gateways query from SaaS
     */
    public static function handle_get_active_gateways(WP_REST_Request $request)
    {
        $auth_error = self::verify_saas_request($request);
        if ($auth_error !== null) {
            return $auth_error;
        }

        $paypal_act = get_option('OPT_WOOTIFY_PAYPAL_ACTIVATED_PROXY', null);
        $stripe_act = get_option('OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY', null);

        return new WP_REST_Response([
            'success' => true,
            'paypalActiveShieldId' => isset($paypal_act['id']) ? $paypal_act['id'] : null,
            'stripeActiveShieldId' => isset($stripe_act['id']) ? $stripe_act['id'] : null,
        ], 200);
    }

    /**
     * Handle manual force gateway activation from SaaS
     */
    public static function handle_force_activate_gateway(WP_REST_Request $request)
    {
        $auth_error = self::verify_saas_request($request);
        if ($auth_error !== null) {
            return $auth_error;
        }

        $raw_body = $request->get_body();
        $payload = json_decode($raw_body, true);
        $type = $payload['type'] ?? '';
        $shieldId = $payload['shieldId'] ?? '';

        if (empty($type) || empty($shieldId)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'invalid_payload',
                'message' => 'Type and shieldId are required'
            ], 400);
        }

        $PG = ($type === 'stripe') ? 'Stripe' : 'PayPal';
        if (!isset(OPTIONKEYS[$PG])) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'invalid_type',
                'message' => 'Invalid gateway type'
            ], 400);
        }

        $keys = OPTIONKEYS[$PG];
        $proxies = Shield_Option_Manager::get($keys['proxies'], []);
        $target_proxy = null;

        foreach ($proxies as $p) {
            if ($p['id'] === $shieldId) {
                $target_proxy = $p;
                break;
            }
        }

        if (!$target_proxy) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'gateway_not_found',
                'message' => 'Shield gateway not found in local active proxies'
            ], 404);
        }

        // Update the active proxy option
        Shield_Option_Manager::update($keys['activatedProxy'], $target_proxy, true);

        // If time rotation method is active, reset the rotation timer
        $rotationMethod = Shield_Option_Manager::get($keys['rotationMethod'], 'by_time');
        if ($rotationMethod === 'by_time') {
            Shield_Option_Manager::update($keys['currentRotation'], time(), true);
        }

        // Sync legacy options state to all tab-scoped storage prefixes to ensure consistent UI rendering
        $methods = [ROTATION_METHOD_TIME, ROTATION_METHOD_AMOUNT, ROTATION_METHOD_ORDER];
        foreach ($methods as $m) {
            $prefix = shield_rotation_tab_storage_prefix($PG, $m);
            Shield_Option_Manager::update($prefix . 'ACTIVATED_PROXY', $target_proxy, true);
            if ($m === ROTATION_METHOD_TIME) {
                Shield_Option_Manager::update($prefix . 'CURRENT_ROTATION', Shield_Option_Manager::get($keys['currentRotation'], time()), true);
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Shield gateway activated successfully'
        ], 200);
    }

    /**
     * Handle incoming POST webhook sync request
     */
    public static function handle_sync(WP_REST_Request $request)
    {
        $auth_error = self::verify_saas_request($request);
        if ($auth_error !== null) {
            return $auth_error;
        }

        $raw_body = $request->get_body();
        $payload = json_decode($raw_body, true);
        if (!isset($payload['shields']) || !is_array($payload['shields'])) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'invalid_payload',
                'message' => 'Payload format is invalid'
            ], 400);
        }

        self::sync_shields($payload);

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Shield gateways synced successfully'
        ], 200);
    }

    /**
     * Update WooCommerce Options to reflect SaaS sync state
     */
    private static function sync_shields($payload)
    {
        $shields = $payload['shields'] ?? [];
        $paypal_method = $payload['paypalRotationMethod'] ?? '';
        $stripe_method = $payload['stripeRotationMethod'] ?? '';

        $pgs = ['PayPal', 'Stripe'];

        foreach ($pgs as $PG) {
            if (!isset(OPTIONKEYS[$PG])) {
                continue;
            }
            $keys = OPTIONKEYS[$PG];

            // Sync rotation method if present in payload
            if ($PG === 'PayPal' && !empty($paypal_method)) {
                Shield_Option_Manager::update($keys['rotationMethod'], $paypal_method, true);
            } elseif ($PG === 'Stripe' && !empty($stripe_method)) {
                Shield_Option_Manager::update($keys['rotationMethod'], $stripe_method, true);
            }

            // Get current proxies to preserve paid_amount and order_count
            $current_proxies = Shield_Option_Manager::get($keys['proxies'], []);
            $current_by_url = [];
            foreach ($current_proxies as $p) {
                if (!empty($p['url'])) {
                    $norm = rtrim(strtolower($p['url']), '/');
                    $current_by_url[$norm] = $p;
                }
            }

            $new_proxies = [];
            $position_list = [];
            $seen_proxy_ids = [];
            $expected_gateway = strtolower($PG);

            foreach ($shields as $shield) {
                if ($shield['status'] !== 'active') {
                    continue;
                }
                $shield_gateway = strtolower($shield['gateway'] ?? 'paypal');
                if ($shield_gateway !== $expected_gateway) {
                    continue;
                }
                $shield_id = (string)($shield['id'] ?? '');
                if ($shield_id === '' || isset($seen_proxy_ids[$shield_id])) {
                    continue;
                }
                $seen_proxy_ids[$shield_id] = true;

                $domain_key = ($PG === 'Stripe') ? 'stripeWebDomain' : 'paypalWebDomain';
                $url = rtrim(!empty($shield[$domain_key]) ? $shield[$domain_key] : $shield['webDomain'], '/');
                $norm_url = strtolower($url);

                // Auto connect/register proxy site if it doesn't exist
                $connection = shield_auto_connect_site_from_rotation($url);
                $site_id = $connection['site_id'];

                // SaaS is authoritative for progress counters when present.
                $paid_amount = isset($shield['paidAmount']) ? (float)$shield['paidAmount'] : 0;
                $order_count = isset($shield['orderCount']) ? (int)$shield['orderCount'] : 0;
                if (isset($current_by_url[$norm_url])) {
                    if (!isset($shield['paidAmount'])) {
                        $paid_amount = $current_by_url[$norm_url]['paid_amount'] ?? 0;
                    }
                    if (!isset($shield['orderCount'])) {
                        $order_count = $current_by_url[$norm_url]['order_count'] ?? 0;
                    }
                }

                $proxy = [
                    'id'          => $shield_id, // SaaS shield ID is used
                    'url'         => $url,
                    'site_id'     => $site_id,
                    'paid_amount' => $paid_amount,
                    'order_count' => $order_count,
                    'timestamp'   => isset($shield['rotationTimeLimit']) ? (int)$shield['rotationTimeLimit'] : 0,
                    'amount'      => isset($shield['rotationAmountLimit']) ? (float)$shield['rotationAmountLimit'] : 0.0,
                    'order'       => isset($shield['rotationOrderLimit']) ? (int)$shield['rotationOrderLimit'] : 0,
                    'proxyToken'  => $shield['shieldKey'] ?? '',
                    'sort_order'  => ($PG === 'Stripe') ? (int)($shield['stripeRotationOrder'] ?? 0) : (int)($shield['rotationOrder'] ?? 0),
                ];

                $new_proxies[] = $proxy;
            }

            // Sort active proxies by their specific rotation order
            usort($new_proxies, function ($a, $b) {
                return $a['sort_order'] <=> $b['sort_order'];
            });

            // Extract the sorted position list
            foreach ($new_proxies as $p) {
                $position_list[] = $p['id'];
            }

            // Save active proxies list
            Shield_Option_Manager::update($keys['proxies'], $new_proxies, true);
            Shield_Option_Manager::update($keys['unusedProxies'], [], true); // clear unused list
            Shield_Option_Manager::update($keys['positionList'], $position_list, true);

            // Also check if activated proxy is still in the new list.
            $activated = Shield_Option_Manager::get($keys['activatedProxy'], null);
            $activated_still_exists = false;
            if ($activated) {
                foreach ($new_proxies as $p) {
                    if ($p['id'] === $activated['id']) {
                        $activated_still_exists = true;
                        // update limit configuration on the activated proxy record in option too
                        Shield_Option_Manager::update($keys['activatedProxy'], $p, true);
                        break;
                    }
                }
            }

            if (!$activated_still_exists && !empty($new_proxies)) {
                Shield_Option_Manager::update($keys['activatedProxy'], $new_proxies[0], true);
                Shield_Option_Manager::update($keys['currentRotation'], time(), true);
            }

            // Sync legacy state to all tab states (so the UI tabs show identical synced order)
            $methods = [ROTATION_METHOD_TIME, ROTATION_METHOD_AMOUNT, ROTATION_METHOD_ORDER];
            foreach ($methods as $m) {
                $prefix = shield_rotation_tab_storage_prefix($PG, $m);
                Shield_Option_Manager::update($prefix . 'PROXIES', $new_proxies, true);
                Shield_Option_Manager::update($prefix . 'UNUSED_PROXIES', [], true);
                Shield_Option_Manager::update($prefix . 'POSITION_LIST', $position_list, true);
                
                $current_activated = Shield_Option_Manager::get($keys['activatedProxy'], null);
                Shield_Option_Manager::update($prefix . 'ACTIVATED_PROXY', $current_activated, true);
                Shield_Option_Manager::update($prefix . 'CURRENT_ROTATION', Shield_Option_Manager::get($keys['currentRotation'], time()), true);
            }
        }
    }
}
