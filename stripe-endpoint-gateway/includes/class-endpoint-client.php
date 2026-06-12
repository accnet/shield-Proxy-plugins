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

class Shield_Stripe_Endpoint_Client
{
    /**
     * Get option key prefix based on which plugin is calling.
     * PayPal plugin uses 'EP_PP_', Stripe uses 'EP_ST_'.
     */
    private static function prefix()
    {
        return 'EP_ST_';
    }

    public static function opt($key)
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

        // Clear all options except SaaS URL and Connection Code (preserved for easy reconnect)
        $keys = [
            'CONNECTED', 'HMAC_SECRET',
            'ENDPOINT_ID', 'ENDPOINT_NAME', 'TYPE', 'ENABLE_ROTATION',
            'ROTATION_METHOD', 'CONNECTED_AT', 'NODES', 'PAUSED',
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
        if ($code === 410) {
            self::log('SaaS disconnected this site (410 Gone) — flushing queue then auto-disconnecting');
            self::flush_queue();  // report pending transactions before clearing config
            self::disconnect();
            return false;
        }
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
        if (isset($data['paused'])) {
            $paused = !empty($data['paused']) ? 'yes' : 'no';
            update_option(self::opt('PAUSED'), $paused, true);
        } else {
            // Log warning if paused field missing in a successful response (new backend required)
            self::log('pull_config: paused field missing in SaaS response — keeping current paused state');
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
     * @param array  $meta       Optional provider receipt/idempotency metadata
     * @return array|false       Response data or false on failure
     */
    public static function report_transaction($shield_id, $amount, $order_id, $currency = 'USD', $meta = [])
    {
        if (get_option(self::opt('CONNECTED'), 'no') !== 'yes') {
            return false;
        }

        $saas_url = get_option(self::opt('SAAS_URL'), '');
        $connection_code = get_option(self::opt('CONNECTION_CODE'), '');

        if (empty($saas_url) || empty($connection_code)) {
            return false;
        }

        $trace_id = self::normalize_receipt_value($meta['traceId'] ?? $meta['trace_id'] ?? '');
        if (empty($trace_id)) {
            $trace_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('endpoint-trace-', true);
        }

        $idempotency_key = self::normalize_receipt_value($meta['idempotencyKey'] ?? $meta['idempotency_key'] ?? '');
        if (empty($idempotency_key)) {
            $idempotency_key = implode(':', [
                'endpoint',
                md5((string) $connection_code),
                'stripe',
                'order',
                (string) $order_id,
                'shield',
                (string) $shield_id,
                'report',
            ]);
        }

        $payload = [
            'connectionCode' => $connection_code,
            'shieldId'       => (string) $shield_id,
            'amount'         => (float) $amount,
            'orderId'        => (string) $order_id,
            'currency'       => $currency ?: 'USD',
            'provider'       => 'stripe',
            'idempotencyKey' => $idempotency_key,
            'traceId'        => $trace_id,
            'paymentStatus'  => self::normalize_payment_status($meta['paymentStatus'] ?? $meta['payment_status'] ?? 'succeeded'),
        ];

        foreach ([
            'providerTransactionId' => ['providerTransactionId', 'provider_transaction_id', 'transaction_id'],
            'paymentIntentId'       => ['paymentIntentId', 'payment_intent_id'],
            'eventId'               => ['eventId', 'event_id'],
        ] as $target_key => $source_keys) {
            foreach ($source_keys as $source_key) {
                $value = self::normalize_receipt_value($meta[$source_key] ?? '');
                if (!empty($value)) {
                    $payload[$target_key] = $value;
                    break;
                }
            }
        }

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

        if ($code === 410) {
            self::log('report_transaction: SaaS rejected (410 Gone) — queuing for retry');
            self::queue_transaction($payload);
            return false;
        }

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

    private static function normalize_receipt_value($value)
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        return $value === '' ? '' : substr($value, 0, 512);
    }

    private static function normalize_payment_status($status)
    {
        $status = self::normalize_receipt_value($status);
        $allowed = ['created', 'requires_action', 'processing', 'succeeded', 'failed', 'canceled', 'refunded', 'partially_refunded'];
        return in_array($status, $allowed, true) ? $status : 'succeeded';
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
            if (!empty($node['isCurrent']) && self::is_node_usable($node)) {
                return $node;
            }
        }

        // Fallback: return first usable node.
        foreach ($nodes as $node) {
            if (self::is_node_usable($node)) {
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
            return self::is_node_usable($node);
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
        $matchedUsable = false;
        foreach ($nodes as &$node) {
            $was = !empty($node['isCurrent']);
            $should = (($node['shieldId'] ?? '') === $shield_id) && self::is_node_usable($node);
            if ($should) {
                $matchedUsable = true;
            }
            if ($was !== $should) {
                $node['isCurrent'] = $should;
                $changed = true;
            }
        }
        unset($node);

        if ($changed) {
            update_option(self::opt('NODES'), $nodes, true);
        }

        if (!$matchedUsable) {
            self::log('Skipped active node update because the SaaS-selected shield is not usable yet: ' . $shield_id);
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

    /**
     * A node is safe for checkout only when SaaS marks it usable.
     * Older configs did not include isUsable/bootstrapStatus/healthStatus, so active nodes with derivedKey remain compatible.
     */
    public static function is_node_usable($node)
    {
        if (!is_array($node)) {
            return false;
        }

        if (($node['status'] ?? '') !== 'active') {
            return false;
        }

        if (array_key_exists('isUsable', $node)) {
            return !empty($node['isUsable']) && !empty($node['derivedKey']);
        }

        if (isset($node['bootstrapStatus']) && $node['bootstrapStatus'] !== 'ready') {
            return false;
        }

        if (isset($node['healthStatus']) && $node['healthStatus'] === 'unhealthy') {
            return false;
        }

        return !empty($node['derivedKey']);
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
        if (strpos($request_uri, '/wp-json/') === 0) {
            $request_uri = substr($request_uri, 8);
        }

        $endpoint_id = get_option(self::opt('ENDPOINT_ID'), '');
        $manager_id = $endpoint_id ? ('mgr_' . $endpoint_id) : 'mgr_endpoint_stripe';
        
        if (empty($shield_id)) {
            $active_node = self::get_active_node();
            $shield_id = $active_node ? ($active_node['shieldId'] ?? $active_node['nodeId'] ?? $active_node['id'] ?? '') : '';
        }
        $key_id = $shield_id ? ('kid_' . $shield_id) : 'kid_endpoint_stripe';

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
            'X-Shield-Gateway'    => 'stripe',
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
            'next_retry' => time(), // retry immediately on first attempt
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
        $max_per_run = 50; // limit per cron run to avoid timeout
        $processed = 0;

        foreach ($queue as $item) {
            // Skip items not yet due for retry
            if (($item['next_retry'] ?? 0) > $now) {
                $remaining[] = $item;
                continue;
            }

            // Skip if processed enough this run
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
                // Success or duplicate — remove from queue
                $flushed++;
                continue;
            }

            // Still failing — exponential backoff: 60s, 120s, 240s, ... max 3600s
            $backoff = min(60 * pow(2, $attempts - 1), 3600);

            // Drop entries older than 24 hours or with > 15 attempts
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
            if (array_key_exists('isUsable', $node)) {
                $node['isUsable'] = !empty($node['isUsable']);
            } elseif (isset($node['bootstrapStatus']) && $node['bootstrapStatus'] !== 'ready') {
                $node['isUsable'] = false;
            } elseif (isset($node['healthStatus']) && $node['healthStatus'] === 'unhealthy') {
                $node['isUsable'] = false;
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

add_filter('option_EP_ST_NODES', [ 'Shield_Stripe_Endpoint_Client', 'map_nodes_list_schema_compat' ]);
add_filter('option_EP_ST_ACTIVE_NODE', [ 'Shield_Stripe_Endpoint_Client', 'map_node_schema_compat' ]);
add_filter('option_EP_ST_UNUSED_NODES', [ 'Shield_Stripe_Endpoint_Client', 'map_nodes_list_schema_compat' ]);
