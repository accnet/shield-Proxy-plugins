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
}
