<?php
/**
 * Endpoint Client
 * HTTP client for SaaS Endpoint API communication
 *
 * @package Endpoint_Gateway
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Shield_PayPal_Endpoint_Client
{
    /**
     * Get option key prefix based on which plugin is calling.
     * PayPal plugin uses 'EP_PP_', Stripe uses 'EP_ST_'.
     */
    private static function prefix()
    {
        return 'EP_PP_';
    }

    private static function opt($key)
    {
        return self::prefix() . $key;
    }

    // ─── Connect ──────────────────────────────────────────────────────────

    /**
     * Connect to SaaS using Connection Code.
     *
     * @param string $saas_url  SaaS base URL (e.g. http://localhost:3000)
     * @param string $connection_code  Connection code from SaaS dashboard
     * @return array{success: bool, message: string}
     */
    public static function connect($saas_url, $connection_code)
    {
        $saas_url = rtrim(esc_url_raw($saas_url), '/');
        $connection_code = sanitize_text_field($connection_code);

        if (empty($saas_url) || empty($connection_code)) {
            return [
                'success' => false,
                'message' => 'SaaS URL and Connection Code are required',
            ];
        }

        $site_url = get_site_url();

        $response = wp_remote_post($saas_url . '/api/endpoints/connect', [
            'method'  => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body'    => wp_json_encode([
                'connectionCode' => $connection_code,
                'siteUrl'        => $site_url,
            ]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => 'HTTP Error: ' . $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code !== 200 && $code !== 201) {
            $msg = isset($data['message'])
                ? (is_array($data['message']) ? implode(', ', $data['message']) : $data['message'])
                : 'Unknown error (HTTP ' . $code . ')';
            return [
                'success' => false,
                'message' => 'SaaS API Error: ' . $msg,
            ];
        }

        if (empty($data['endpointHmacSecret'])) {
            return [
                'success' => false,
                'message' => 'SaaS returned empty HMAC secret.',
            ];
        }

        // Save connection state
        update_option(self::opt('CONNECTED'), 'yes', true);
        update_option(self::opt('SAAS_URL'), $saas_url, true);
        update_option(self::opt('CONNECTION_CODE'), $connection_code, true);
        update_option(self::opt('HMAC_SECRET'), $data['endpointHmacSecret'], true);
        update_option(self::opt('ENDPOINT_ID'), $data['endpointId'] ?? '', true);
        update_option(self::opt('ENDPOINT_NAME'), $data['endpointName'] ?? '', true);
        update_option(self::opt('TYPE'), $data['type'] ?? '', true);
        update_option(self::opt('ENABLE_ROTATION'), $data['enableRotation'] ?? false, true);
        update_option(self::opt('ROTATION_METHOD'), $data['rotationMethod'] ?? 'by_volume', true);
        update_option(self::opt('CONNECTED_AT'), time(), true);
        update_option(self::opt('LAST_SYNC_AT'), time(), true);

        // Save nodes
        if (isset($data['nodes']) && is_array($data['nodes'])) {
            update_option(self::opt('NODES'), $data['nodes'], true);
        }

        return [
            'success' => true,
            'message' => 'Connected to SaaS Endpoint successfully!',
            'data'    => $data,
        ];
    }

    // ─── Disconnect ───────────────────────────────────────────────────────

    /**
     * Disconnect from SaaS.
     */
    public static function disconnect()
    {
        $saas_url = get_option(self::opt('SAAS_URL'), '');
        $connection_code = get_option(self::opt('CONNECTION_CODE'), '');
        $secret = get_option(self::opt('HMAC_SECRET'), '');

        if (!empty($saas_url) && !empty($connection_code) && !empty($secret)) {
            $body = wp_json_encode(['connectionCode' => $connection_code]);
            $headers = self::build_hmac_headers($body);

            wp_remote_post($saas_url . '/api/endpoints/disconnect', [
                'method'  => 'POST',
                'headers' => array_merge([
                    'Content-Type' => 'application/json',
                ], $headers),
                'body'    => $body,
                'timeout' => 10,
            ]);
        }

        // Clear all options
        $keys = [
            'CONNECTED', 'SAAS_URL', 'CONNECTION_CODE', 'HMAC_SECRET',
            'ENDPOINT_ID', 'ENDPOINT_NAME', 'TYPE', 'ENABLE_ROTATION',
            'ROTATION_METHOD', 'CONNECTED_AT', 'NODES',
        ];
        foreach ($keys as $key) {
            delete_option(self::opt($key));
        }

        return [
            'success' => true,
            'message' => 'Disconnected from SaaS Endpoint.',
        ];
    }

    // ─── Pull Config ──────────────────────────────────────────────────────

    /**
     * Pull latest config from SaaS (cron fallback).
     */
    public static function pull_config()
    {
        if (get_option(self::opt('CONNECTED'), 'no') !== 'yes') {
            return false;
        }

        $saas_url = get_option(self::opt('SAAS_URL'), '');
        $connection_code = get_option(self::opt('CONNECTION_CODE'), '');

        if (empty($saas_url) || empty($connection_code)) {
            return false;
        }

        $headers = self::build_hmac_headers('');
        $url = $saas_url . '/api/endpoints/config/' . rawurlencode($connection_code);

        $response = wp_remote_get($url, [
            'headers' => $headers,
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['success'])) {
            return false;
        }

        // Update local state
        if (isset($data['nodes']) && is_array($data['nodes'])) {
            update_option(self::opt('NODES'), $data['nodes'], true);
        }
        if (isset($data['enableRotation'])) {
            update_option(self::opt('ENABLE_ROTATION'), $data['enableRotation'], true);
        }
        if (isset($data['rotationMethod'])) {
            update_option(self::opt('ROTATION_METHOD'), $data['rotationMethod'], true);
        }

        // Auto-rotate: if SaaS sent a new secret, update it locally
        if (!empty($data['newHmacSecret'])) {
            update_option(self::opt('HMAC_SECRET'), $data['newHmacSecret'], true);
            self::log('HMAC secret auto-rotated by SaaS');
        }

        update_option(self::opt('LAST_SYNC_AT'), time(), true);

        return true;
    }

    // ─── Report Transaction ───────────────────────────────────────────────

    /**
     * Report a completed transaction back to SaaS.
     *
     * @param string $shield_id  The shieldId of the node that processed the payment
     * @param float  $amount     Order total amount
     * @param string $order_id   WooCommerce order ID
     * @param string $currency   Currency code (default: USD)
     * @return array|false       Response data or false on failure
     */
    public static function report_transaction($shield_id, $amount, $order_id, $currency = 'USD')
    {
        if (get_option(self::opt('CONNECTED'), 'no') !== 'yes') {
            return false;
        }

        $saas_url = get_option(self::opt('SAAS_URL'), '');
        $connection_code = get_option(self::opt('CONNECTION_CODE'), '');

        if (empty($saas_url) || empty($connection_code)) {
            return false;
        }

        $payload = [
            'connectionCode' => $connection_code,
            'shieldId'       => (string) $shield_id,
            'amount'         => (float) $amount,
            'orderId'        => (string) $order_id,
            'currency'       => $currency ?: 'USD',
        ];

        $body = wp_json_encode($payload);
        $headers = self::build_hmac_headers($body);

        $response = wp_remote_post($saas_url . '/api/endpoints/transaction', [
            'method'  => 'POST',
            'headers' => array_merge([
                'Content-Type' => 'application/json',
            ], $headers),
            'body'    => $body,
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            self::log('report_transaction error: ' . $response->get_error_message());
            self::queue_transaction($payload);
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 409) {
            // Duplicate transaction — already reported, treat as success
            return ['success' => true, 'duplicate' => true];
        }

        if ($code !== 200 && $code !== 201) {
            self::log('report_transaction HTTP ' . $code . ': ' . wp_remote_retrieve_body($response));
            self::queue_transaction($payload);
            return false;
        }

        // Update active node if rotation happened
        if (!empty($data['nextActiveShieldId'])) {
            self::update_active_node_by_shield_id($data['nextActiveShieldId']);
        }

        return $data;
    }

    // ─── Active Node Helpers ──────────────────────────────────────────────

    /**
     * Get the currently active node (isCurrent: true).
     *
     * @return array|null  Node data or null if none active
     */
    public static function get_active_node()
    {
        $nodes = get_option(self::opt('NODES'), []);
        if (!is_array($nodes) || empty($nodes)) {
            return null;
        }

        foreach ($nodes as $node) {
            if (!empty($node['isCurrent']) && ($node['status'] ?? '') === 'active') {
                return $node;
            }
        }

        // Fallback: return first active node
        foreach ($nodes as $node) {
            if (($node['status'] ?? '') === 'active') {
                return $node;
            }
        }

        return null;
    }

    /**
     * Get all active nodes.
     */
    public static function get_active_nodes()
    {
        $nodes = get_option(self::opt('NODES'), []);
        if (!is_array($nodes)) {
            return [];
        }

        return array_filter($nodes, function ($node) {
            return ($node['status'] ?? '') === 'active';
        });
    }

    /**
     * Update isCurrent flag to point to the given shieldId.
     */
    public static function update_active_node_by_shield_id($shield_id)
    {
        $nodes = get_option(self::opt('NODES'), []);
        if (!is_array($nodes)) {
            return;
        }

        $changed = false;
        foreach ($nodes as &$node) {
            $was = !empty($node['isCurrent']);
            $should = ($node['shieldId'] ?? '') === $shield_id;
            if ($was !== $should) {
                $node['isCurrent'] = $should;
                $changed = true;
            }
        }
        unset($node);

        if ($changed) {
            update_option(self::opt('NODES'), $nodes, true);
        }
    }

    /**
     * Find a node by shieldId.
     */
    public static function find_node_by_shield_id($shield_id)
    {
        $nodes = get_option(self::opt('NODES'), []);
        if (!is_array($nodes)) {
            return null;
        }

        foreach ($nodes as $node) {
            if (($node['shieldId'] ?? '') === $shield_id) {
                return $node;
            }
        }
        return null;
    }

    /**
     * Check if connected.
     */
    public static function is_connected()
    {
        return get_option(self::opt('CONNECTED'), 'no') === 'yes';
    }

    /**
     * Check if any active nodes exist.
     */
    public static function has_active_nodes()
    {
        return !empty(self::get_active_nodes());
    }

    // ─── HMAC ─────────────────────────────────────────────────────────────

    /**
     * Build HMAC headers for endpoint API requests.
     *
     * Format: HMAC-SHA256(hmacSecret, timestamp + "." + body)
     *
     * @param string $body  Request body (empty string for GET requests)
     * @return array         Headers array
     */
    private static function build_hmac_headers($body)
    {
        $secret = get_option(self::opt('HMAC_SECRET'), '');
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        return [
            'X-Endpoint-Signature' => $signature,
            'X-Endpoint-Timestamp' => $timestamp,
            'X-Endpoint-Site-Url'  => get_site_url(),
        ];
    }

    /**
     * Build HMAC headers for proxy requests to site1 using derivedKey.
     *
     * @param string $derived_key  The derivedKey for this node
     * @param string $method       HTTP method
     * @param string $url          Request URL
     * @param string $body         Request body
     * @param string $shield_id    Optional shield ID
     * @return array                Signed headers
     */
    public static function build_proxy_hmac_headers($derived_key, $method, $url, $body = '', $shield_id = '')
    {
        $timestamp = (string) time();
        $nonce = wp_generate_uuid4();

        $parts = wp_parse_url($url);
        $path = isset($parts['path']) ? $parts['path'] : '/';
        $query = isset($parts['query']) && $parts['query'] !== '' ? ('?' . $parts['query']) : '';
        $request_uri = $path . $query;

        $endpoint_id = get_option(self::opt('ENDPOINT_ID'), '');
        $manager_id = $endpoint_id ? ('mgr_' . $endpoint_id) : 'mgr_endpoint_paypal';
        
        if (empty($shield_id)) {
            $active_node = self::get_active_node();
            $shield_id = $active_node ? ($active_node['shieldId'] ?? $active_node['nodeId'] ?? $active_node['id'] ?? '') : '';
        }
        $key_id = $shield_id ? ('kid_' . $shield_id) : 'kid_endpoint_paypal';

        $canonical = implode("\n", [
            strtoupper((string) $method),
            $request_uri,
            hash('sha256', (string) $body),
            $timestamp,
            $nonce,
            $manager_id,
            $key_id,
        ]);

        $signature = hash_hmac('sha256', $canonical, $derived_key);

        return [
            'X-Shield-Signature'  => $signature,
            'X-Shield-Timestamp'  => $timestamp,
            'X-Shield-Nonce'      => $nonce,
            'X-Shield-Manager-Id' => $manager_id,
            'X-Shield-Key-Id'     => $key_id,
            'X-Shield-Gateway'    => 'paypal',
        ];
    }

    // ─── Transaction Retry Queue ───────────────────────────────────────────

    /**
     * Add a failed transaction payload to the local retry queue.
     *
     * @param array $payload Transaction payload
     */
    public static function queue_transaction($payload)
    {
        $queue = get_option(self::opt('TX_QUEUE'), []);
        if (!is_array($queue)) {
            $queue = [];
        }

        // Cap queue at 5000 entries, drop oldest if full
        if (count($queue) >= 5000) {
            array_shift($queue);
        }

        $queue[] = [
            'payload'    => $payload,
            'queued_at'  => time(),
            'attempts'   => 0,
            'next_retry' => time(),
        ];

        update_option(self::opt('TX_QUEUE'), $queue, false);
        self::log('Transaction queued for retry (order: ' . ($payload['orderId'] ?? '?') . ')');
    }

    /**
     * Flush the retry queue: attempt to re-send failed transactions.
     * Called by cron every 5 minutes.
     *
     * @return int Number of successfully flushed entries
     */
    public static function flush_queue()
    {
        $queue = get_option(self::opt('TX_QUEUE'), []);
        if (!is_array($queue) || empty($queue)) {
            return 0;
        }

        $saas_url = get_option(self::opt('SAAS_URL'), '');
        if (empty($saas_url)) {
            return 0;
        }

        $now = time();
        $remaining = [];
        $flushed = 0;
        $max_per_run = 50;
        $processed = 0;

        foreach ($queue as $item) {
            if (($item['next_retry'] ?? 0) > $now) {
                $remaining[] = $item;
                continue;
            }

            if ($processed >= $max_per_run) {
                $remaining[] = $item;
                continue;
            }

            $processed++;
            $payload = $item['payload'] ?? [];
            $attempts = ($item['attempts'] ?? 0) + 1;

            $body = wp_json_encode($payload);
            $headers = self::build_hmac_headers($body);

            $response = wp_remote_post($saas_url . '/api/endpoints/transaction', [
                'method'  => 'POST',
                'headers' => array_merge([
                    'Content-Type' => 'application/json',
                ], $headers),
                'body'    => $body,
                'timeout' => 15,
            ]);

            $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);

            if ($code === 200 || $code === 201 || $code === 409) {
                $flushed++;
                continue;
            }

            $backoff = min(60 * pow(2, $attempts - 1), 3600);

            if ($attempts > 15 || ($now - ($item['queued_at'] ?? $now)) > 86400) {
                self::log('Queue entry dropped after ' . $attempts . ' attempts (order: ' . ($payload['orderId'] ?? '?') . ')');
                continue;
            }

            $item['attempts'] = $attempts;
            $item['next_retry'] = $now + $backoff;
            $remaining[] = $item;
        }

        update_option(self::opt('TX_QUEUE'), $remaining, false);

        if ($flushed > 0) {
            self::log('Queue flush: ' . $flushed . ' transactions sent, ' . count($remaining) . ' remaining');
        }

        return $flushed;
    }

    /**
     * Get the current queue size.
     *
     * @return int
     */
    public static function queue_count()
    {
        $queue = get_option(self::opt('TX_QUEUE'), []);
        return is_array($queue) ? count($queue) : 0;
    }

    // ─── Logging ──────────────────────────────────────────────────────────

    private static function log($message)
    {
        if ($logger = wc_get_logger()) {
            $logger->debug($message, ['source' => 'endpoint-gateway']);
        } else {
            error_log('[endpoint-gateway] ' . $message);
        }
    }

    // ─── Schema Compatibility Helpers ─────────────────────────────────────

    public static function map_node_schema_compat($node)
    {
        if (is_array($node)) {
            if (!isset($node['id']) && isset($node['nodeId'])) {
                $node['id'] = $node['nodeId'];
            }
            if (!isset($node['amount']) && isset($node['volumeLimit'])) {
                $node['amount'] = $node['volumeLimit'];
            }
            if (!isset($node['paid_amount']) && isset($node['volumeUsed'])) {
                $node['paid_amount'] = $node['volumeUsed'];
            }
            if (!isset($node['shieldId']) && isset($node['id'])) {
                $node['shieldId'] = $node['id'];
            }
        }
        return $node;
    }

    public static function map_nodes_list_schema_compat($nodes)
    {
        if (is_array($nodes)) {
            foreach ($nodes as $key => $node) {
                $nodes[$key] = self::map_node_schema_compat($node);
            }
        }
        return $nodes;
    }
}

add_filter('option_EP_PP_NODES', [ 'Shield_PayPal_Endpoint_Client', 'map_nodes_list_schema_compat' ]);
add_filter('option_EP_PP_ACTIVE_NODE', [ 'Shield_PayPal_Endpoint_Client', 'map_node_schema_compat' ]);
add_filter('option_EP_PP_UNUSED_NODES', [ 'Shield_PayPal_Endpoint_Client', 'map_nodes_list_schema_compat' ]);
