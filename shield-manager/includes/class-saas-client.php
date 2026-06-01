<?php
/**
 * SaaS Connect Client
 * Handles connection establishment and teardown with NestJS SaaS
 * 
 * @package Shield_Manager
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Shield_SaaS_Client
{
    /**
     * Connect to SaaS using Connection Key
     */
    public static function connect_saas($saas_url, $connect_key)
    {
        $saas_url = rtrim(esc_url_raw($saas_url), '/');
        $connect_key = sanitize_text_field($connect_key);
        
        if (empty($saas_url) || empty($connect_key)) {
            return [
                'success' => false,
                'message' => 'SaaS URL and Connection Key are required'
            ];
        }

        $site_url = get_site_url();

        // Perform server-side HTTP request to NestJS connect endpoint
        $response = wp_remote_post($saas_url . '/api/manager/connect', [
            'method'  => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body'    => json_encode([
                'connectKey' => $connect_key,
                'siteUrl'    => $site_url,
            ]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => 'HTTP Error: ' . $response->get_error_message()
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code !== 200 && $code !== 201) {
            $msg = isset($data['message']) 
                ? (is_array($data['message']) ? implode(', ', $data['message']) : $data['message'])
                : 'Unknown error occurred (HTTP ' . $code . ')';
            return [
                'success' => false,
                'message' => 'SaaS API Error: ' . $msg
            ];
        }

        if (empty($data['hmacSecret'])) {
            return [
                'success' => false,
                'message' => 'SaaS returned an empty HMAC secret key.'
            ];
        }

        // Save connection state
        Shield_Option_Manager::update('OPT_SHIELD_SAAS_CONNECTED', 'yes', true);
        Shield_Option_Manager::update('OPT_SHIELD_SAAS_URL', $saas_url, true);
        Shield_Option_Manager::update('OPT_SHIELD_SAAS_KEY', $connect_key, true);
        Shield_Option_Manager::update('OPT_SHIELD_SAAS_HMAC_SECRET', $data['hmacSecret'], true);
        Shield_Option_Manager::update('OPT_SHIELD_SAAS_CONNECTED_AT', time(), true);

        // Run initial sync of returned shields
        if (isset($data['shields']) && is_array($data['shields'])) {
            // Temporarily include receiver if not loaded yet
            if (!class_exists('Shield_SaaS_Receiver')) {
                require_once __DIR__ . '/class-saas-receiver.php';
            }
            // Trigger the sync function directly (simulate payload)
            // Use reflection or private sync method by helper
            $reflector = new ReflectionClass('Shield_SaaS_Receiver');
            $method = $reflector->getMethod('sync_shields');
            $method->setAccessible(true);
            $method->invoke(null, $data);
        }

        return [
            'success' => true,
            'message' => 'Connected to SaaS successfully and imported gateways!'
        ];
    }

    /**
     * Terminate connection and clean up option tokens
     */
    public static function disconnect_saas()
    {
        Shield_Option_Manager::delete('OPT_SHIELD_SAAS_CONNECTED');
        Shield_Option_Manager::delete('OPT_SHIELD_SAAS_URL');
        Shield_Option_Manager::delete('OPT_SHIELD_SAAS_KEY');
        Shield_Option_Manager::delete('OPT_SHIELD_SAAS_HMAC_SECRET');
        Shield_Option_Manager::delete('OPT_SHIELD_SAAS_CONNECTED_AT');

        return [
            'success' => true,
            'message' => 'Disconnected from SaaS successfully. Rotation options are now unlocked locally.'
        ];
    }

    /**
     * Push active stats (paid_amount, order_count) for PayPal or Stripe to SaaS
     */
    public static function sync_stats_to_saas($PG)
    {
        $connected = Shield_Option_Manager::get('OPT_SHIELD_SAAS_CONNECTED', 'no');
        if ($connected !== 'yes') {
            return;
        }

        $saas_url = Shield_Option_Manager::get('OPT_SHIELD_SAAS_URL', '');
        $connect_key = Shield_Option_Manager::get('OPT_SHIELD_SAAS_KEY', '');
        $secret = Shield_Option_Manager::get('OPT_SHIELD_SAAS_HMAC_SECRET', '');
        if (empty($saas_url) || empty($connect_key) || empty($secret)) {
            return;
        }

        if (!isset(OPTIONKEYS[$PG])) {
            return;
        }
        $keys = OPTIONKEYS[$PG];

        $proxies = Shield_Option_Manager::get($keys['proxies'], []);
        $activated = Shield_Option_Manager::get($keys['activatedProxy'], null);

        $stats = [];
        foreach ($proxies as $p) {
            $stats[] = [
                'shieldId'   => $p['id'],
                'paidAmount' => number_format((float)($p['paid_amount'] ?? 0), 2, '.', ''),
                'orderCount' => (int)($p['order_count'] ?? 0),
            ];
        }

        $payload = [
            'connectKey'     => $connect_key,
            'type'           => ($PG === 'Stripe') ? 'stripe' : 'paypal',
            'activeShieldId' => $activated ? $activated['id'] : null,
            'stats'          => $stats,
        ];

        $body = json_encode($payload);
        $signature = hash_hmac('sha256', $body, $secret);

        wp_remote_post($saas_url . '/api/manager/sync-stats', [
            'method'      => 'POST',
            'headers'     => [
                'Content-Type'             => 'application/json',
                'X-WooCommerce-Signature'  => $signature,
            ],
            'body'        => $body,
            'timeout'     => 3,
            'blocking'    => false, // absolutely asynchronous & non-blocking!
        ]);
    }

    public static function get_stripe_webhook_status($shield_id, $payment_intent_id)
    {
        $connected = Shield_Option_Manager::get('OPT_SHIELD_SAAS_CONNECTED', 'no');
        if ($connected !== 'yes') {
            return ['success' => false, 'message' => 'SaaS is not connected'];
        }

        $saas_url = Shield_Option_Manager::get('OPT_SHIELD_SAAS_URL', '');
        $connect_key = Shield_Option_Manager::get('OPT_SHIELD_SAAS_KEY', '');
        $secret = Shield_Option_Manager::get('OPT_SHIELD_SAAS_HMAC_SECRET', '');
        if (empty($saas_url) || empty($connect_key) || empty($secret) || empty($shield_id) || empty($payment_intent_id)) {
            return ['success' => false, 'message' => 'Missing SaaS webhook status credentials'];
        }

        $payload = [
            'connectKey' => $connect_key,
            'shieldId' => (string) $shield_id,
            'paymentIntentId' => (string) $payment_intent_id,
        ];

        $body = wp_json_encode($payload);
        $signature = hash_hmac('sha256', $body, $secret);

        $response = wp_remote_post($saas_url . '/api/manager/stripe-webhook-status', [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'X-WooCommerce-Signature' => $signature,
            ],
            'body' => $body,
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($data)) {
            return [
                'success' => false,
                'message' => 'Invalid SaaS webhook status response',
            ];
        }

        return $data;
    }
}
