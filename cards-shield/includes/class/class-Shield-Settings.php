<?php
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/helpers/helpers.php';

class ShieldSettings {
    const OPT_PROXY_KEY     = 'shield_proxy_key';      // sh_xxx từ SaaS
    const OPT_SAAS_URL      = 'shield_saas_url';       // URL của SaaS
    const OPT_CONNECT_STATUS = 'shield_connect_status'; // connected|pending|failed
    const OPT_CONNECT_DATA  = 'shield_connect_data';   // payload từ /api/shields/connect
    const OPT_LAST_SYNC_AT  = 'shield_last_sync_at';   // thời điểm sync-config thành công gần nhất
    const OPT_LAST_SYNC_STATUS = 'shield_last_sync_status'; // success|failed
    const OPT_LICENSE_KEY   = 'shield_license_key';    // HMAC key cho shield-manager
    const OPT_STRIPE_WEBHOOKS = 'shield_stripe_webhooks';
    const OPT_STRIPE_WEBHOOK_EVENTS = 'shield_stripe_webhook_events';
    const OPT_STRIPE_WEBHOOK_PAYMENTS = 'shield_stripe_webhook_payments';
    const STRIPE_WEBHOOK_EVENTS = [
        'payment_intent.processing',
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
    ];

    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_notices', [$this, 'display_notice']);
        add_action('wp_ajax_shield_saas_connect', [$this, 'ajax_connect']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /* ── REST: SaaS push sync-config ───────────────────────────── */
    public function register_rest_routes() {
        register_rest_route('shield/v1', '/sync-config', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rest_sync_config'],
            'permission_callback' => '__return_true', // HMAC auth inside callback
        ]);
        register_rest_route('shield/v1', '/stripe-webhook/create', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rest_create_stripe_webhook'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('shield/v1', '/stripe-webhook/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_stripe_webhook_status'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('shield/v1', '/stripe-webhook/disable', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rest_disable_stripe_webhook'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('shield/v1', '/stripe-webhook/receive', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rest_receive_stripe_webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function rest_sync_config(\WP_REST_Request $request) {
        $auth_error = $this->authorize_proxy_request($request, 'shield_sc_n_');
        if ($auth_error) {
            return $auth_error;
        }

        $body = $request->get_json_params();
        $cfg  = $body['paymentConfig'] ?? [];

        update_option('shield_paypal', [
            'prod_client_id'  => $cfg['paypalProdClientId']  ?? '',
            'prod_secret_key' => $cfg['paypalProdSecretKey'] ?? '',
            'test_mode'       => !empty($cfg['paypalTestMode']) ? '1' : '0',
            'test_client_id'  => $cfg['paypalTestClientId']  ?? '',
            'test_secret_key' => $cfg['paypalTestSecretKey'] ?? '',
        ]);
        update_option('shield_stripe', [
            'prod_publishable_key' => $cfg['stripeProdPublishableKey'] ?? '',
            'prod_secret_key'      => $cfg['stripeProdSecretKey']      ?? '',
            'test_mode'            => !empty($cfg['stripeTestMode']) ? '1' : '0',
            'test_publishable_key' => $cfg['stripeTestPublishableKey'] ?? '',
            'test_secret_key'      => $cfg['stripeTestSecretKey']      ?? '',
        ]);
        $synced_at = current_time('mysql');
        update_option(self::OPT_LAST_SYNC_AT, $synced_at);
        update_option(self::OPT_LAST_SYNC_STATUS, 'success');

        // Key rotation: if SaaS sends a new shieldKey, store it now (signed with old key — already verified above)
        $new_shield_key = isset($body['newShieldKey']) ? trim((string) $body['newShieldKey']) : '';
        if ($new_shield_key !== '' && strpos($new_shield_key, 'sh_') === 0) {
            update_option(self::OPT_PROXY_KEY, $new_shield_key);
        }

        return rest_ensure_response([
            'synced' => true,
            'syncedAt' => $synced_at,
            'message' => 'site1 sync-config applied successfully',
        ]);
    }

    public function rest_create_stripe_webhook(\WP_REST_Request $request) {
        $auth_error = $this->authorize_proxy_request($request, 'shield_sw_n_');
        if ($auth_error) {
            return $auth_error;
        }

        $this->cleanup_stripe_webhook_storage();

        $mode = $this->sanitize_webhook_mode($request->get_param('mode'));
        $webhook_store = $this->get_stripe_webhooks_store();
        $existing = $this->normalize_webhook_entry($webhook_store[$mode] ?? [], $mode);
        $body = $request->get_json_params();
        $cleanup_old_domain_webhooks = !empty($body['cleanupOldDomainWebhooks']) || !empty($request->get_param('cleanupOldDomainWebhooks'));

        if ($cleanup_old_domain_webhooks) {
            $cleanup = $this->cleanup_old_domain_stripe_webhooks($mode);
            if (is_wp_error($cleanup)) {
                return $cleanup;
            }

            if (!empty($existing['url']) && $this->is_old_domain_stripe_webhook_url($existing['url'])) {
                $existing = $this->get_default_webhook_entry();
                $webhook_store[$mode] = $this->normalize_webhook_entry($existing, $mode);
                $this->update_stripe_webhooks_store($webhook_store);
            }
        }

        if (!empty($existing['endpoint_id'])) {
            $synced = $this->sync_stripe_webhook_from_stripe($mode, $existing);
            if (!is_wp_error($synced) && ($synced['status'] ?? '') !== 'sync_failed' && !empty($synced['endpoint_id'])) {
                $webhook_store[$mode] = $synced;
                $this->update_stripe_webhooks_store($webhook_store);
                return rest_ensure_response([
                    'success' => true,
                    'created' => false,
                    'message' => sprintf('Stripe %s webhook already exists on site1.local.', $mode),
                    'webhook' => $this->format_webhook_for_response($synced),
                ]);
            }
        }

        $created = $this->create_stripe_webhook_on_stripe($mode);
        if (is_wp_error($created)) {
            return $created;
        }

        $webhook_store[$mode] = $created;
        $this->update_stripe_webhooks_store($webhook_store);
        $this->log_stripe_webhook('info', 'Stripe webhook created on site1', [
            'mode' => $mode,
            'endpoint_id' => $created['endpoint_id'],
            'url' => $created['url'],
        ]);

        return rest_ensure_response([
            'success' => true,
            'created' => true,
            'message' => sprintf('Stripe %s webhook created successfully for site1.local.', $mode),
            'webhook' => $this->format_webhook_for_response($created),
        ]);
    }

    public function rest_stripe_webhook_status(\WP_REST_Request $request) {
        $auth_error = $this->authorize_proxy_request($request, 'shield_sw_n_');
        if ($auth_error) {
            return $auth_error;
        }

        $this->cleanup_stripe_webhook_storage();

        $mode = $this->sanitize_webhook_mode($request->get_param('mode'));
        $webhook_store = $this->get_stripe_webhooks_store();
        $existing = $this->normalize_webhook_entry($webhook_store[$mode] ?? [], $mode);

        if (empty($existing['endpoint_id'])) {
            return rest_ensure_response([
                'success' => true,
                'message' => sprintf('Stripe %s webhook has not been created yet.', $mode),
                'webhook' => $this->format_webhook_for_response($existing),
            ]);
        }

        $synced = $this->sync_stripe_webhook_from_stripe($mode, $existing);
        if (is_wp_error($synced)) {
            return $synced;
        }

        $webhook_store[$mode] = $synced;
        $this->update_stripe_webhooks_store($webhook_store);
        $this->log_stripe_webhook('info', 'Stripe webhook status synced on site1', [
            'mode' => $mode,
            'endpoint_id' => $synced['endpoint_id'],
            'status' => $synced['status'],
        ]);

        return rest_ensure_response([
            'success' => true,
            'message' => sprintf('Stripe %s webhook status synced successfully for site1.local.', $mode),
            'webhook' => $this->format_webhook_for_response($synced),
        ]);
    }

    public function rest_disable_stripe_webhook(\WP_REST_Request $request) {
        $auth_error = $this->authorize_proxy_request($request, 'shield_sw_n_');
        if ($auth_error) {
            return $auth_error;
        }

        $this->cleanup_stripe_webhook_storage();

        $mode = $this->sanitize_webhook_mode($request->get_param('mode'));
        $webhook_store = $this->get_stripe_webhooks_store();
        $existing = $this->normalize_webhook_entry($webhook_store[$mode] ?? [], $mode);
        $disabled = $this->disable_stripe_webhook_on_stripe($mode, $existing);
        if (is_wp_error($disabled)) {
            return $disabled;
        }

        $webhook_store[$mode] = $disabled;
        $this->update_stripe_webhooks_store($webhook_store);
        $this->log_stripe_webhook('info', 'Stripe webhook disabled on site1', [
            'mode' => $mode,
            'status' => $disabled['status'],
        ]);

        return rest_ensure_response([
            'success' => true,
            'message' => sprintf('Stripe %s webhook disabled successfully for site1.local.', $mode),
            'webhook' => $this->format_webhook_for_response($disabled),
        ]);
    }

    public function rest_receive_stripe_webhook(\WP_REST_Request $request) {
        $payload = $request->get_body();
        $signature = $request->get_header('Stripe-Signature');
        if (!$signature) {
            $this->log_stripe_webhook('warning', 'Stripe webhook rejected: missing signature');
            return new \WP_Error('unauthorized', 'Missing Stripe-Signature header', ['status' => 401]);
        }

        $this->cleanup_stripe_webhook_storage();

        $store = $this->get_stripe_webhooks_store();
        $secrets = [];
        foreach (['test', 'live'] as $mode) {
            $secret = $store[$mode]['signing_secret'] ?? '';
            if ($secret) {
                $secrets[$mode] = $secret;
            }
        }
        if (empty($secrets)) {
            $this->log_stripe_webhook('warning', 'Stripe webhook rejected: no signing secret configured');
            return new \WP_Error('unauthorized', 'No webhook signing secret configured', ['status' => 401]);
        }

        require_once CARDSSHIELD_PLUGIN_DIR . '/includes/stripe-php/init.php';

        $matched_mode = null;
        $event = null;
        foreach ($secrets as $mode => $secret) {
            try {
                $event = \Stripe\Webhook::constructEvent($payload, $signature, $secret);
                $matched_mode = $mode;
                break;
            } catch (\Throwable $e) {
                continue;
            }
        }

        if (!$matched_mode || !$event) {
            $this->log_stripe_webhook('warning', 'Stripe webhook rejected: signature verification failed');
            return new \WP_Error('unauthorized', 'Stripe webhook signature verification failed', ['status' => 401]);
        }

        $event_id = isset($event->id) ? (string) $event->id : '';
        $event_type = isset($event->type) ? (string) $event->type : '';
        if ($event_id === '' || $event_type === '') {
            $this->log_stripe_webhook('warning', 'Stripe webhook rejected: incomplete event payload');
            return new \WP_Error('invalid_payload', 'Stripe webhook event is incomplete', ['status' => 400]);
        }

        if (!in_array($event_type, self::STRIPE_WEBHOOK_EVENTS, true)) {
            $this->log_stripe_webhook('warning', 'Stripe webhook ignored: unsupported event type', [
                'mode' => $matched_mode,
                'event_id' => $event_id,
                'event_type' => $event_type,
            ]);
            return rest_ensure_response([
                'success' => true,
                'received' => true,
                'ignored' => true,
                'mode' => $matched_mode,
                'eventId' => $event_id,
                'type' => $event_type,
            ]);
        }

        $processed_events = get_option(self::OPT_STRIPE_WEBHOOK_EVENTS, []);
        $processed_events = is_array($processed_events) ? $processed_events : [];
        if (isset($processed_events[$event_id])) {
            $this->log_stripe_webhook('info', 'Stripe webhook duplicate ignored', [
                'mode' => $matched_mode,
                'event_id' => $event_id,
                'event_type' => $event_type,
            ]);
            return rest_ensure_response([
                'success' => true,
                'received' => true,
                'duplicate' => true,
                'mode' => $matched_mode,
                'eventId' => $event_id,
                'type' => $event_type,
            ]);
        }

        $payment_intent = (isset($event->data) && isset($event->data->object)) ? $event->data->object : null;
        $payment_intent_id = is_object($payment_intent) && isset($payment_intent->id) ? (string) $payment_intent->id : '';
        $metadata = is_object($payment_intent) && isset($payment_intent->metadata) ? $payment_intent->metadata : null;
        $payments = get_option(self::OPT_STRIPE_WEBHOOK_PAYMENTS, []);
        $payments = is_array($payments) ? $payments : [];
        $existing_payment = ($payment_intent_id !== '' && isset($payments[$payment_intent_id]) && is_array($payments[$payment_intent_id]))
            ? $payments[$payment_intent_id]
            : [];
        $is_candidate = !empty($existing_payment['is_3ds_candidate']);
        $state = match ($event_type) {
            'payment_intent.processing' => 'processing',
            'payment_intent.succeeded' => 'succeeded',
            'payment_intent.payment_failed' => 'payment_failed',
            default => 'ignored',
        };
        $transition_applied = true;
        if (!$this->should_accept_state_transition($existing_payment['state'] ?? '', $state)) {
            $state = (string) ($existing_payment['state'] ?? $state);
            $transition_applied = false;
        }

        $processed_events[$event_id] = [
            'mode' => $matched_mode,
            'type' => $event_type,
            'payment_intent_id' => $payment_intent_id,
            'processed_at' => current_time('mysql'),
        ];
        if (count($processed_events) > 100) {
            $processed_events = array_slice($processed_events, -100, null, true);
        }
        update_option(self::OPT_STRIPE_WEBHOOK_EVENTS, $processed_events);

        $order_id = is_object($metadata) && isset($metadata->woo_order_id)
            ? (string) $metadata->woo_order_id
            : ($existing_payment['order_id'] ?? '');
        $order_invoice = is_object($metadata) && isset($metadata->order_id)
            ? (string) $metadata->order_id
            : ($existing_payment['order_invoice'] ?? '');
        $trace_id = is_object($metadata) && isset($metadata->trace_id)
            ? (string) $metadata->trace_id
            : ($existing_payment['trace_id'] ?? '');
        $route_id = is_object($metadata) && isset($metadata->route_id)
            ? sanitize_text_field((string) $metadata->route_id)
            : '';
        // manager_callback_url không còn được lưu trong Stripe metadata (đã loại bỏ để bảo vệ URL site2).
        // Tier 1: giải quyết qua route_id → transient (block bên dưới).
        // Tier 2: fallback về local payment tracking.
        $manager_callback_url = esc_url_raw((string) ($existing_payment['manager_callback_url'] ?? ''));
        $shield_id = is_object($metadata) && isset($metadata->processor_id)
            ? (string) $metadata->processor_id
            : ($existing_payment['shield_id'] ?? '');
        $manager_id = is_object($metadata) && isset($metadata->manager_id)
            ? (string) $metadata->manager_id
            : ($existing_payment['manager_id'] ?? '');

        if ($payment_intent_id !== '' && $transition_applied) {
            // --- Callback URL resolution (2-tier) ---
            // Tier 1: route_id → transient (highest priority)
            $route_data = [];
            if ($route_id !== '') {
                $route_data = get_transient('shield_route_' . $route_id);
                if (is_array($route_data) && !empty($route_data['manager_callback_url'])) {
                    $manager_callback_url = esc_url_raw((string) $route_data['manager_callback_url']);
                }
                if ($order_id === '' && !empty($route_data['woo_order_id'])) {
                    $order_id = (string) $route_data['woo_order_id'];
                }
                if ($order_invoice === '' && !empty($route_data['order_invoice'])) {
                    $order_invoice = (string) $route_data['order_invoice'];
                }
                if ($trace_id === '' && !empty($route_data['trace_id'])) {
                    $trace_id = (string) $route_data['trace_id'];
                }
            }

            // Tier 2: local payment tracking (keep permanently)
            // --- End resolution ---
            if ($shield_id === '' && !empty($route_data['shield_id'])) {
                $shield_id = (string) $route_data['shield_id'];
            }
            if ($manager_id === '' && !empty($route_data['manager_id'])) {
                $manager_id = (string) $route_data['manager_id'];
            }
            $payments[$payment_intent_id] = array_merge($existing_payment, [
                'payment_intent_id' => $payment_intent_id,
                'mode' => $matched_mode,
                'state' => $state,
                'order_id' => $order_id,
                'order_invoice' => $order_invoice,
                'shield_id' => $shield_id,
                'manager_callback_url' => $manager_callback_url,
                'manager_id' => $manager_id,
                'proxy_id' => home_url(),
                'trace_id' => $trace_id,
                'event_id' => $event_id,
                'event_type' => $event_type,
                'is_3ds_candidate' => $is_candidate,
                'last_source' => 'stripe_webhook',
                'occurred_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);
            update_option(self::OPT_STRIPE_WEBHOOK_PAYMENTS, $payments);
        }

        $callback_result = null;
        if ($transition_applied && $is_candidate && $payment_intent_id !== '' && in_array($event_type, self::STRIPE_WEBHOOK_EVENTS, true)) {
            $callback_result = $this->push_stripe_webhook_status_to_manager_site($matched_mode, [
                'eventId' => $event_id,
                'paymentIntentId' => $payment_intent_id,
                'state' => $state,
                'mode' => $matched_mode,
                'orderId' => $order_id ?: null,
                'orderInvoice' => $order_invoice ?: null,
                'shieldId' => $shield_id ?: null,
                'managerId' => $manager_id ?: null,
                'proxyId' => home_url(),
                'proxyUrl' => home_url(),
                'managerCallbackUrl' => $manager_callback_url,
                'routeId' => $route_id,
                'occurredAt' => current_time('mysql'),
                'traceId' => $trace_id ?: null,
                'is3dsCandidate' => true,
            ]);
        }

        $this->log_stripe_webhook($transition_applied ? 'info' : 'warning', 'Stripe webhook processed', [
            'mode' => $matched_mode,
            'event_id' => $event_id,
            'event_type' => $event_type,
            'payment_intent_id' => $payment_intent_id,
            'state' => $state,
            'is_3ds_candidate' => $is_candidate,
            'transition_applied' => $transition_applied,
        ]);

        if (class_exists('Helpers') && method_exists('Helpers', 'queuePaymentTransitionLog') && $payment_intent_id !== '') {
            $currency = is_object($payment_intent) && isset($payment_intent->currency) ? strtoupper((string) $payment_intent->currency) : null;
            $amount_minor = null;
            if (is_object($payment_intent) && isset($payment_intent->amount_received)) {
                $amount_minor = (int) $payment_intent->amount_received;
            } elseif (is_object($payment_intent) && isset($payment_intent->amount)) {
                $amount_minor = (int) $payment_intent->amount;
            }
            Helpers::queuePaymentTransitionLog([
                'gateway'           => 'stripe',
                'mode'              => $matched_mode,
                'source'            => 'webhook',
                'transactionId'     => $payment_intent_id,
                'eventId'           => $event_id,
                'traceId'           => $existing_payment['trace_id'] ?? '',
                'previousState'     => $existing_payment['state'] ?? null,
                'nextState'         => $state,
                'transitionApplied' => $transition_applied,
                'ignoredReason'     => $transition_applied ? null : 'stale_state',
                'amount'            => $amount_minor !== null ? $amount_minor / 100 : null,
                'currency'          => $currency,
                'orderId'           => $order_id ?: null,
                'orderNumber'       => $order_invoice ?: null,
                'managerId'         => $manager_id ?: null,
                'site2Url'          => $manager_callback_url ?: null,
                'metadata'          => [
                    'amount'                => $amount_minor !== null ? $amount_minor / 100 : null,
                    'currency'              => $currency,
                    'is_3ds'                => $is_candidate,
                    'gateway_response_code' => $event_type,
                ],
            ]);
        }

        return rest_ensure_response([
            'success' => true,
            'received' => true,
            'mode' => $matched_mode,
            'eventId' => $event_id,
            'type' => $event_type,
            'paymentIntentId' => $payment_intent_id ?: null,
            'is3dsCandidate' => $is_candidate,
            'transitionApplied' => $transition_applied,
            'callback' => $callback_result,
        ]);
    }

    /* ── Menu ─────────────────────────────────────────────────────── */
    public function register_menu() {
        add_menu_page(
            'Shield Settings', 'Shield', 'manage_options',
            'shield-settings', [$this, 'shield_settings_page_callback'],
            'dashicons-shield', 4
        );
    }

    /* ── Admin page ───────────────────────────────────────────────── */
    public function shield_settings_page_callback() {
        $proxy_key     = get_option(self::OPT_PROXY_KEY, '');
        $saas_url      = get_option(self::OPT_SAAS_URL, SHIELD_MANAGE_URL);
        $conn_status   = get_option(self::OPT_CONNECT_STATUS, 'pending');
        $conn_data     = get_option(self::OPT_CONNECT_DATA, []);
        $last_sync_at  = get_option(self::OPT_LAST_SYNC_AT, '');
        $last_sync_status = get_option(self::OPT_LAST_SYNC_STATUS, 'pending');
        
        $shield_paypal = get_option('shield_paypal', []);
        $shield_stripe = get_option('shield_stripe', []);
        $stripe_webhooks = $this->get_stripe_webhooks_store();
        $stripe_webhook_events = get_option(self::OPT_STRIPE_WEBHOOK_EVENTS, []);
        $stripe_webhook_payments = get_option(self::OPT_STRIPE_WEBHOOK_PAYMENTS, []);
        $transition_queue = get_option('shield_payment_transition_logs_queue', []);
        $transition_queue = is_array($transition_queue) ? $transition_queue : [];
        $transition_last_flush = get_option('shield_payment_transition_logs_last_flush', []);
        $transition_last_flush = is_array($transition_last_flush) ? $transition_last_flush : [];
        $transition_warning = get_option('shield_payment_transition_logs_warning', []);
        $transition_warning = is_array($transition_warning) ? $transition_warning : [];
        $shield_data   = get_option('shield_data', []);
        
        $v2_credentials = function_exists('shield_hmac_keys_v2_all') ? shield_hmac_keys_v2_all() : [];

        $status_badge_class = match($conn_status) {
            'connected' => 'connected',
            'failed'    => 'failed',
            default     => 'pending',
        };
        $status_badge_text = match($conn_status) {
            'connected' => 'Connected',
            'failed'    => 'Failed',
            default     => 'Pending',
        };

        $mask_func = function($val) {
            if (empty($val)) return '(none)';
            if (strlen($val) <= 12) return $val;
            return substr($val, 0, 8) . '...' . substr($val, -8);
        };

        // PayPal credentials
        $paypal_prod_client = $shield_paypal['prod_client_id'] ?? '';
        $paypal_prod_secret = $shield_paypal['prod_secret_key'] ?? '';
        $paypal_test_mode = !empty($shield_paypal['test_mode']) ? 'ENABLED' : 'DISABLED';
        $paypal_test_client = $shield_paypal['test_client_id'] ?? '';
        $paypal_test_secret = $shield_paypal['test_secret_key'] ?? '';

        // Stripe credentials
        $stripe_prod_pub = $shield_stripe['prod_publishable_key'] ?? '';
        $stripe_prod_sec = $shield_stripe['prod_secret_key'] ?? '';
        $stripe_test_mode = !empty($shield_stripe['test_mode']) ? 'ENABLED' : 'DISABLED';
        $stripe_test_pub = $shield_stripe['test_publishable_key'] ?? '';
        $stripe_test_sec = $shield_stripe['test_secret_key'] ?? '';

        // Process logs
        $latest_payments = array_slice(is_array($stripe_webhook_payments) ? $stripe_webhook_payments : [], -10);
        $latest_payments = array_reverse($latest_payments); // Newest first

        $latest_events = array_slice(is_array($stripe_webhook_events) ? $stripe_webhook_events : [], -10);
        $latest_events = array_reverse($latest_events); // Newest first

        $queue_pending = count($transition_queue);
        $queue_retrying = 0;
        $queue_next_attempt = null;
        foreach ($transition_queue as $item) {
            $retry_count = is_array($item) ? (int) ($item['retry_count'] ?? 0) : 0;
            $next_attempt_at = is_array($item) ? (int) ($item['next_attempt_at'] ?? 0) : 0;
            if ($retry_count > 0) {
                $queue_retrying++;
            }
            if ($next_attempt_at > time() && ($queue_next_attempt === null || $next_attempt_at < $queue_next_attempt)) {
                $queue_next_attempt = $next_attempt_at;
            }
        }
        ?>
        <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap');

        .shield-dashboard {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #334155;
            margin: 20px 20px 20px 0;
            max-width: 1400px;
        }
        .shield-header {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 20px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
        }
        .shield-brand {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .shield-logo-icon {
            background: #f0fdf4;
            color: #10b981;
            width: 40px;
            height: 40px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            border: 1px solid #d1fae5;
            transition: transform 0.2s ease;
        }
        .shield-logo-icon:hover {
            transform: scale(1.05);
        }
        .shield-brand-title {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
        }
        .shield-brand-subtitle {
            margin: 2px 0 0 0;
            font-size: 10px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
        }
        .shield-connection-badge {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 6px 12px;
            border-radius: 4px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            transition: all 0.2s ease;
        }
        .shield-connection-badge.connected {
            background: #ecfdf5;
            color: #047857;
            border-color: #a7f3d0;
        }
        .shield-connection-badge.failed {
            background: #fff1f2;
            color: #be123c;
            border-color: #fecdd3;
        }
        .shield-connection-badge.pending {
            background: #fffbeb;
            color: #b45309;
            border-color: #fef3c7;
        }
        .shield-connection-badge .dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }
        .shield-connection-badge.connected .dot {
            background: #10b981;
            box-shadow: 0 0 4px #10b981;
            animation: shield-pulse 1.8s infinite;
        }
        .shield-connection-badge.failed .dot {
            background: #ef4444;
        }
        .shield-connection-badge.pending .dot {
            background: #f59e0b;
        }

        @keyframes shield-pulse {
            0% { transform: scale(0.9); opacity: 0.7; }
            50% { transform: scale(1.2); opacity: 1; }
            100% { transform: scale(0.9); opacity: 0.7; }
        }

        .shield-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
        }
        @media (min-width: 1024px) {
            .shield-grid {
                grid-template-columns: 3fr 2fr;
            }
        }

        .shield-card {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            margin-bottom: 24px;
            overflow: hidden;
        }
        .shield-card-header {
            padding: 16px 20px;
            border-bottom: 1px solid #cbd5e1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
        }
        .shield-card-title {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .shield-card-body {
            padding: 20px 24px;
        }

        .shield-form-group {
            margin-bottom: 20px;
        }
        .shield-form-group:last-child {
            margin-bottom: 0;
        }
        .shield-label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #475569;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .shield-input-wrapper {
            display: flex;
            gap: 8px;
        }
        .shield-input {
            flex: 1;
            height: 40px !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 4px !important;
            padding: 0 12px !important;
            font-size: 13px !important;
            font-family: 'JetBrains Mono', monospace !important;
            color: #0f172a !important;
            background: #ffffff !important;
            transition: all 0.2s ease !important;
            box-shadow: none !important;
        }
        .shield-input:focus {
            border-color: #4f46e5 !important;
            box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.1) !important;
            outline: none !important;
        }
        .shield-input-description {
            margin: 8px 0 0 0;
            font-size: 12px;
            color: #64748b;
            line-height: 1.5;
        }

        .shield-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            height: 40px;
            padding: 0 20px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
            border: 1px solid transparent;
        }
        .shield-btn-primary {
            background: #4f46e5;
            color: #ffffff;
        }
        .shield-btn-primary:hover {
            background: #4338ca;
            color: #ffffff;
        }
        .shield-btn-primary:active {
            background: #3730a3;
        }
        .shield-btn-primary:disabled {
            background: #f1f5f9;
            color: #94a3b8;
            border-color: #e2e8f0;
            cursor: not-allowed;
        }

        .shield-webhooks-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }
        @media (min-width: 640px) {
            .shield-webhooks-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        .webhook-item {
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            overflow: hidden;
            background: #f8fafc;
        }
        .webhook-header {
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .webhook-header.test {
            background: #fffbeb;
            color: #b45309;
            border-bottom: 1px solid #fef3c7;
        }
        .webhook-header.live {
            background: #ecfdf5;
            color: #047857;
            border-bottom: 1px solid #d1fae5;
        }
        .webhook-body {
            padding: 16px;
        }
        .webhook-badge {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            padding: 3px 8px;
            border-radius: 4px;
            letter-spacing: 0.05em;
            border: 1px solid transparent;
        }
        .webhook-badge.enabled {
            background: #d1fae5;
            color: #065f46;
            border-color: #a7f3d0;
        }
        .webhook-badge.disabled {
            background: #f1f5f9;
            color: #475569;
            border-color: #e2e8f0;
        }
        .webhook-badge.failed {
            background: #fee2e2;
            color: #991b1b;
            border-color: #fecdd3;
        }
        .webhook-badge.pending {
            background: #fef3c7;
            color: #92400e;
            border-color: #fcd34d;
        }

        .shield-table-wrap {
            overflow-x: auto;
        }
        .shield-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            text-align: left;
        }
        .shield-table th {
            background: #f8fafc;
            padding: 12px 16px;
            font-weight: 700;
            color: #475569;
            border-bottom: 1px solid #cbd5e1;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: 0.05em;
        }
        .shield-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #e2e8f0;
            color: #334155;
            vertical-align: middle;
        }
        .shield-table tr:hover td {
            background: #f8fafc;
            color: #0f172a;
        }
        .shield-table tr:last-child td {
            border-bottom: none;
        }
        .shield-table code {
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px;
            background: #f1f5f9;
            padding: 3px 8px;
            border-radius: 4px;
            color: #4f46e5;
            border: 1px solid #e2e8f0;
        }
        .shield-table-copy-btn {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            cursor: pointer;
            color: #475569;
            padding: 4px 8px;
            border-radius: 4px;
            transition: all 0.2s;
            line-height: 1;
            font-size: 12px;
        }
        .shield-table-copy-btn:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        .shield-cred-group {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 16px;
            margin-bottom: 16px;
        }
        .shield-cred-group:last-child {
            margin-bottom: 0;
        }
        .shield-cred-title {
            margin: 0 0 12px 0;
            font-size: 12px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .shield-cred-title.live {
            color: #10b981;
        }
        .shield-cred-title.test {
            color: #f59e0b;
        }
        .meta-label-value {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 12px;
            align-items: center;
            gap: 12px;
        }
        .meta-label-value:last-child {
            margin-bottom: 0;
        }
        .meta-label {
            font-weight: 500;
            color: #64748b;
        }
        .meta-value {
            font-family: 'JetBrains Mono', monospace;
            color: #334155;
            font-size: 12px;
            word-break: break-all;
            text-align: right;
        }
        .shield-tabs {
            background: transparent;
            border: none;
            box-shadow: none;
            overflow: visible;
        }
        .shield-tab-list {
            display: flex;
            gap: 24px;
            padding: 0;
            background: transparent;
            border-bottom: 1px solid #cbd5e1;
            overflow-x: auto;
            margin-bottom: 28px;
        }
        .shield-tab-button {
            appearance: none;
            border: none;
            background: transparent;
            color: #64748b;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            line-height: 1;
            padding: 0 0 16px 0;
            white-space: nowrap;
            transition: all 0.2s ease;
            position: relative;
            border-bottom: 2px solid transparent;
            border-radius: 0;
        }
        .shield-tab-button:hover {
            color: #0f172a;
        }
        .shield-tab-button.active {
            color: #4f46e5;
            border-bottom-color: #4f46e5;
        }
        .shield-tab-panel {
            display: none;
            padding: 0;
        }
        .shield-tab-panel.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .shield-tab-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }
        @media (min-width: 1180px) {
            .shield-tab-grid.two-column {
                grid-template-columns: minmax(0, 1.15fr) minmax(360px, 0.85fr);
                align-items: start;
            }
        }
        .shield-tab-section-title {
            margin: 0 0 16px 0;
            font-size: 13px;
            font-weight: 800;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .shield-log-grid {
            margin-top: 20px;
        }
        
        .shield-conn-banner {
            display: flex;
            gap: 12px;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            padding: 16px;
            border-radius: 4px;
            margin-bottom: 24px;
            font-size: 13px;
            align-items: center;
            color: #047857;
        }
        .shield-conn-banner code {
            background: #ffffff;
            color: #047857;
            padding: 2px 6px;
            border-radius: 4px;
            border: 1px solid #a7f3d0;
            font-family: 'JetBrains Mono', monospace;
        }
        .shield-card-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 24px;
            border-top: 1px solid #cbd5e1;
            padding-top: 20px;
        }
        .shield-sync-time {
            font-size: 12px;
            color: #64748b;
        }
        .shield-sync-time strong {
            color: #334155;
        }
        .shield-empty-state {
            padding: 32px;
            text-align: center;
            color: #64748b;
            font-size: 13px;
        }
        .shield-alert-warning {
            margin-top: 16px;
            background: #fffbeb;
            border: 1px solid #fcd34d;
            border-radius: 4px;
            padding: 12px 16px;
            color: #b45309;
            font-size: 12px;
            line-height: 1.5;
        }
        .shield-alert-warning strong {
            color: #78350f;
        }
        .shield-alert-warning span {
            color: #64748b;
            font-size: 11px;
        }
        .shield-double-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
            gap: 20px;
        }
        
        .webhook-field-label {
            display: block;
            font-size: 10px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            margin-bottom: 6px;
            letter-spacing: 0.05em;
        }
        .webhook-field-wrapper {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .webhook-field-input {
            flex: 1;
            height: 32px !important;
            font-size: 12px !important;
            font-family: 'JetBrains Mono', monospace !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 4px !important;
            background: #ffffff !important;
            padding: 0 10px !important;
            color: #334155 !important;
        }
        .webhook-field-input:focus {
            border-color: #4f46e5 !important;
            background: #ffffff !important;
        }
        .webhook-field-copy-btn {
            height: 32px !important;
            line-height: 30px !important;
            background: #f1f5f9 !important;
            border: 1px solid #cbd5e1 !important;
            color: #475569 !important;
            padding: 0 12px !important;
            font-size: 12px !important;
            font-weight: 600 !important;
            border-radius: 4px !important;
            cursor: pointer !important;
            transition: all 0.2s !important;
        }
        .webhook-field-copy-btn:hover {
            background: #e2e8f0 !important;
            color: #0f172a !important;
        }
        .webhook-field-value {
            font-size: 12px;
            color: #334155;
            word-break: break-all;
            font-family: 'JetBrains Mono', monospace;
        }
        .webhook-field-value.error {
            color: #be123c;
            background: #fff1f2;
            border: 1px solid #fecdd3;
            padding: 6px 12px;
            border-radius: 4px;
        }
        
        .notice.notice-success,
        div.updated {
            background: #ecfdf5 !important;
            border-left: 4px solid #10b981 !important;
            color: #047857 !important;
            border-radius: 4px !important;
            padding: 12px 20px !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05) !important;
            margin: 20px 20px 0 0 !important;
            border-top: none !important;
            border-right: none !important;
            border-bottom: none !important;
        }
        .notice.notice-success p,
        div.updated p {
            margin: 0 !important;
            font-size: 13px !important;
            font-weight: 600 !important;
        }
        </style>

        <div class="shield-dashboard">
            <!-- Brand Header -->
            <div class="shield-header">
                <div class="shield-brand">
                    <div class="shield-logo-icon">🛡️</div>
                    <div>
                        <h1 class="shield-brand-title">Cards Shield</h1>
                        <p class="shield-brand-subtitle">Proxy Payments Agent — Client v<?= CARDSSHIELD_VERSION ?></p>
                    </div>
                </div>
                <div class="shield-connection-badge <?= esc_attr($status_badge_class) ?>">
                    <span class="dot"></span>
                    <span><?= esc_html($status_badge_text) ?></span>
                </div>
            </div>

            <div class="shield-tabs" data-shield-tabs>
                <div class="shield-tab-list" role="tablist" aria-label="Cards Shield settings sections">
                    <button type="button" class="shield-tab-button active" data-shield-tab="gateways" role="tab" aria-selected="true">Gateways Config</button>
                    <button type="button" class="shield-tab-button" data-shield-tab="managers" role="tab" aria-selected="false">Connected Store Managers</button>
                    <button type="button" class="shield-tab-button" data-shield-tab="transitions" role="tab" aria-selected="false">Transition Log</button>
                </div>
                <div class="shield-tab-panel active" data-shield-panel="gateways" role="tabpanel">
                    <div class="shield-tab-grid two-column">
                        <div>
                    <!-- SaaS Connection Form -->
                    <div class="shield-card">
                        <div class="shield-card-header">
                            <h3 class="shield-card-title">🔌 Connection Settings</h3>
                        </div>
                        <div class="shield-card-body">
                            <?php if ($conn_status === 'connected' && !empty($conn_data['shieldId'])) : ?>
                                <div class="shield-conn-banner">
                                    <div>⚡</div>
                                    <div>
                                        <span style="font-weight:700;">CONNECTED TO SHIELD SYSTEM</span>
                                        <div style="margin-top:4px;">
                                            ID: <code><?= esc_html($conn_data['shieldId']) ?></code> |
                                            Name: <strong><?= esc_html($conn_data['name'] ?? '') ?></strong>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="shield-form-group">
                                <label class="shield-label" for="sp_saas_url">SaaS Control Panel URL</label>
                                <input type="url" id="sp_saas_url" class="shield-input" value="<?= esc_attr($saas_url) ?>" placeholder="https://shield.example.com">
                                <p class="shield-input-description">URL của hệ thống quản lý SaaS Shield</p>
                            </div>

                            <div class="shield-form-group">
                                <label class="shield-label" for="sp_proxy_key">Shield Proxy Key</label>
                                <input type="text" id="sp_proxy_key" class="shield-input" value="<?= esc_attr($proxy_key) ?>" placeholder="sh_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                                <p class="shield-input-description">API Key được cấp bởi SaaS Dashboard sau khi tạo Shield mới</p>
                            </div>

                            <div class="shield-card-footer">
                                <div class="shield-sync-time">
                                    <?php if ($last_sync_at) : ?>
                                        Đồng bộ lần cuối: <strong><?= esc_html($last_sync_at) ?></strong> (<?= esc_html(strtoupper($last_sync_status)) ?>)
                                    <?php endif; ?>
                                </div>
                                <button id="sp-btn-connect" class="shield-btn shield-btn-primary">
                                    <span id="sp-spinner" class="spinner" style="display:none;float:none;margin:0 5px 0 0"></span>
                                    Lưu & Kết nối
                                </button>
                            </div>
                            <div style="text-align:right;margin-top:12px;">
                                <span id="sp-connect-msg" style="font-size:12px;font-weight:600;"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Stripe Webhooks panels -->
                    <div class="shield-card">
                        <div class="shield-card-header">
                            <h3 class="shield-card-title">⚡ Stripe Webhook Endpoints</h3>
                        </div>
                        <div class="shield-card-body">
                            <p style="font-size:12px;color:#64748b;margin:0 0 20px 0;line-height:1.5;">Đường dẫn nhận webhook được tạo tự động bởi SaaS và lưu cục bộ trên Site 1 để xử lý trạng thái thanh toán từ Stripe.</p>
                            <div class="shield-webhooks-grid">
                                <?= $this->render_stripe_webhook_panel('test', $stripe_webhooks['test']) ?>
                                <?= $this->render_stripe_webhook_panel('live', $stripe_webhooks['live']) ?>
                            </div>
                        </div>
                    </div>

                        </div>
                        <div>
                    <!-- Synced Credentials Inspector -->
                    <div class="shield-card">
                        <div class="shield-card-header">
                            <h3 class="shield-card-title">🔑 Synced Gateways Config</h3>
                        </div>
                        <div class="shield-card-body">
                            <!-- PayPal credentials block -->
                            <div class="shield-cred-group">
                                <h4 class="shield-cred-title test">🔵 PayPal Config</h4>
                                <div class="meta-label-value">
                                    <span class="meta-label">Active Mode</span>
                                    <span class="meta-value" style="font-weight:bold;color:<?= $paypal_test_mode === 'ENABLED' ? '#f59e0b' : '#10b981' ?>"><?= $paypal_test_mode ?></span>
                                </div>
                                <div class="meta-label-value">
                                    <span class="meta-label">Live Client ID</span>
                                    <span class="meta-value"><?= esc_html($mask_func($paypal_prod_client)) ?></span>
                                </div>
                                <div class="meta-label-value">
                                    <span class="meta-label">Live Secret Key</span>
                                    <span class="meta-value"><?= esc_html($mask_func($paypal_prod_secret)) ?></span>
                                </div>
                                <div class="meta-label-value">
                                    <span class="meta-label">Test Client ID</span>
                                    <span class="meta-value"><?= esc_html($mask_func($paypal_test_client)) ?></span>
                                </div>
                                <div class="meta-label-value">
                                    <span class="meta-label">Test Secret Key</span>
                                    <span class="meta-value"><?= esc_html($mask_func($paypal_test_secret)) ?></span>
                                </div>
                            </div>

                            <!-- Stripe credentials block -->
                            <div class="shield-cred-group" style="margin-top:16px;">
                                <h4 class="shield-cred-title live">🟣 Stripe Config</h4>
                                <div class="meta-label-value">
                                    <span class="meta-label">Active Mode</span>
                                    <span class="meta-value" style="font-weight:bold;color:<?= $stripe_test_mode === 'ENABLED' ? '#f59e0b' : '#10b981' ?>"><?= $stripe_test_mode ?></span>
                                </div>
                                <div class="meta-label-value">
                                    <span class="meta-label">Live Pub Key</span>
                                    <span class="meta-value"><?= esc_html($mask_func($stripe_prod_pub)) ?></span>
                                </div>
                                <div class="meta-label-value">
                                    <span class="meta-label">Live Secret Key</span>
                                    <span class="meta-value"><?= esc_html($mask_func($stripe_prod_sec)) ?></span>
                                </div>
                                <div class="meta-label-value">
                                    <span class="meta-label">Test Pub Key</span>
                                    <span class="meta-value"><?= esc_html($mask_func($stripe_test_pub)) ?></span>
                                </div>
                                <div class="meta-label-value">
                                    <span class="meta-label">Test Secret Key</span>
                                    <span class="meta-value"><?= esc_html($mask_func($stripe_test_sec)) ?></span>
                                </div>
                            </div>

                            <!-- General Shield Settings -->
                            <div class="shield-cred-group" style="margin-top:16px;">
                                <h4 class="shield-cred-title">⚙️ General Settings</h4>
                                <?php if (empty($shield_data)) : ?>
                                    <div style="font-size:11px;color:#64748b;">Chưa đồng bộ setting từ SaaS.</div>
                                <?php else : ?>
                                    <?php foreach ($shield_data as $k => $v) : ?>
                                        <div class="meta-label-value">
                                            <span class="meta-label"><?= esc_html($k) ?></span>
                                            <span class="meta-value"><?= esc_html(is_array($v) ? wp_json_encode($v) : $v) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                        </div>
                    </div>
                </div>
                <div class="shield-tab-panel" data-shield-panel="managers" role="tabpanel">
                    <p class="shield-tab-section-title">Connected Store Managers</p>
                    <!-- Connected stores (v2 keys) -->
                    <div class="shield-card">
                        <div class="shield-card-header">
                            <h3 class="shield-card-title">🔐 Connected Store Managers (v2 keyrings)</h3>
                        </div>
                        <div class="shield-card-body" style="padding:0">
                            <?php if (empty($v2_credentials)) : ?>
                                <div class="shield-empty-state">Chưa có WooCommerce store manager (site2.local) nào được bootstrap kết nối.</div>
                            <?php else : ?>
                                <div class="shield-table-wrap">
                                    <table class="shield-table">
                                        <thead>
                                            <tr>
                                                <th>Store Label</th>
                                                <th>Store ID</th>
                                                <th>Key ID</th>
                                                <th>HMAC Shared Secret</th>
                                                <th>PayPal HMAC Key</th>
                                                <th>Stripe HMAC Key</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($v2_credentials as $cred) : ?>
                                                <tr>
                                                    <td><strong><?= esc_html($cred['label'] ?: '(unlabeled)') ?></strong></td>
                                                    <td><code><?= esc_html($cred['manager_id'] ?? '') ?></code></td>
                                                    <td><code><?= esc_html($cred['key_id'] ?? '') ?></code></td>
                                                    <td>
                                                        <div style="display:flex;gap:6px;align-items:center;">
                                                            <code><?= esc_html($mask_func($cred['hmac_secret'] ?? '')) ?></code>
                                                            <?php if (!empty($cred['hmac_secret'])) : ?>
                                                                <button class="shield-table-copy-btn" onclick="navigator.clipboard.writeText('<?= esc_js($cred['hmac_secret']) ?>'); alert('Copied HMAC Secret!');">📋</button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <?php
                                                        $pp_key  = !empty($cred['hmac_secret']) ? hash_hmac('sha256', 'gateway-proxy:paypal',  $cred['hmac_secret']) : '';
                                                        $str_key = !empty($cred['hmac_secret']) ? hash_hmac('sha256', 'gateway-proxy:stripe',  $cred['hmac_secret']) : '';
                                                    ?>
                                                    <td>
                                                        <div style="display:flex;gap:6px;align-items:center;">
                                                            <code><?= esc_html($mask_func($pp_key)) ?></code>
                                                            <?php if ($pp_key) : ?>
                                                                <button class="shield-table-copy-btn" onclick="navigator.clipboard.writeText('<?= esc_js($pp_key) ?>'); alert('Copied PayPal HMAC Key!');">📋</button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div style="display:flex;gap:6px;align-items:center;">
                                                            <code><?= esc_html($mask_func($str_key)) ?></code>
                                                            <?php if ($str_key) : ?>
                                                                <button class="shield-table-copy-btn" onclick="navigator.clipboard.writeText('<?= esc_js($str_key) ?>'); alert('Copied Stripe HMAC Key!');">📋</button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="webhook-badge <?= esc_attr($cred['status'] ?? 'active') === 'active' ? 'enabled' : 'disabled' ?>">
                                                            <?= esc_html(strtoupper($cred['status'] ?? 'active')) ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="shield-tab-panel" data-shield-panel="transitions" role="tabpanel">
                    <div class="shield-tab-grid">
                    <div class="shield-card">
                        <div class="shield-card-header">
                            <h3 class="shield-card-title">📡 Payment Transition Log Queue</h3>
                            <span class="webhook-badge <?= $queue_pending > 1000 ? 'failed' : ($queue_pending > 0 ? 'pending' : 'enabled') ?>">
                                <?= esc_html($queue_pending > 0 ? 'PENDING' : 'CLEAR') ?>
                            </span>
                        </div>
                        <div class="shield-card-body">
                            <div class="meta-label-value">
                                <span class="meta-label">Pending Rows</span>
                                <span class="meta-value"><?= esc_html((string) $queue_pending) ?></span>
                            </div>
                            <div class="meta-label-value">
                                <span class="meta-label">Retrying Rows</span>
                                <span class="meta-value"><?= esc_html((string) $queue_retrying) ?></span>
                            </div>
                            <div class="meta-label-value">
                                <span class="meta-label">Next Attempt</span>
                                <span class="meta-value"><?= esc_html($queue_next_attempt ? date_i18n('Y-m-d H:i:s', $queue_next_attempt) : 'now') ?></span>
                            </div>
                            <div class="meta-label-value">
                                <span class="meta-label">Last Flush</span>
                                <span class="meta-value">
                                    <?= esc_html(!empty($transition_last_flush['at']) ? $transition_last_flush['at'] : 'never') ?>
                                    <?php if (array_key_exists('success', $transition_last_flush)) : ?>
                                        (<?= !empty($transition_last_flush['success']) ? 'SUCCESS' : 'FAILED' ?>)
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php if (!empty($transition_last_flush['message'])) : ?>
                                <div class="meta-label-value">
                                    <span class="meta-label">Last Error</span>
                                    <span class="meta-value"><?= esc_html($transition_last_flush['message']) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($transition_warning['message'])) : ?>
                                <div class="shield-alert-warning">
                                    <strong>Warning:</strong> <?= esc_html($transition_warning['message']) ?>
                                    <?php if (!empty($transition_warning['at'])) : ?>
                                        <br><span><?= esc_html($transition_warning['at']) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <p class="shield-input-description">Payment path chỉ ghi local queue. Cron sẽ push batch lên SaaS, lỗi sẽ retry theo backoff.</p>
                        </div>
                    </div>
                    </div>
            <!-- Double Grid logs (Payments + events) -->
                    <div class="shield-log-grid">
                        <div class="shield-double-grid">
                            <!-- Webhook Payments Log -->
                            <div class="shield-card">
                                <div class="shield-card-header">
                                    <h3 class="shield-card-title">💸 Webhook Payment Attempts (Latest 10)</h3>
                                </div>
                                <div class="shield-card-body" style="padding:0">
                                    <?php if (empty($latest_payments)) : ?>
                                <div class="shield-empty-state">Chưa nhận được giao dịch nào qua webhook.</div>
                            <?php else : ?>
                                <div class="shield-table-wrap">
                                    <table class="shield-table">
                                        <thead>
                                            <tr>
                                                <th>Payment Intent ID</th>
                                                <th>State</th>
                                                <th>Order ID</th>
                                                <th>3DS</th>
                                                <th>Updated At</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($latest_payments as $pi_id => $pay) : ?>
                                                <tr>
                                                    <td><code><?= esc_html($pi_id) ?></code></td>
                                                    <td>
                                                        <span class="webhook-badge <?= esc_attr($pay['state'] ?? '') === 'succeeded' ? 'enabled' : (in_array($pay['state'] ?? '', ['processing', 'requires_action']) ? 'pending' : 'failed') ?>">
                                                            <?= esc_html(strtoupper($pay['state'] ?? '')) ?>
                                                        </span>
                                                    </td>
                                                    <td>Order #<?= esc_html($pay['order_id'] ?? '') ?></td>
                                                    <td><?= !empty($pay['is_3ds_candidate']) ? 'Yes' : 'No' ?></td>
                                                    <td><?= esc_html($pay['updated_at'] ?? '') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Webhook Events Log -->
                    <div class="shield-card">
                        <div class="shield-card-header">
                            <h3 class="shield-card-title">🔔 Webhook Event Logs (Latest 10)</h3>
                        </div>
                        <div class="shield-card-body" style="padding:0">
                            <?php if (empty($latest_events)) : ?>
                                <div class="shield-empty-state">Chưa nhận được event nào từ Stripe.</div>
                            <?php else : ?>
                                <div class="shield-table-wrap">
                                    <table class="shield-table">
                                        <thead>
                                            <tr>
                                                <th>Event ID</th>
                                                <th>Type</th>
                                                <th>Processed At</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($latest_events as $ev_id => $ev) : ?>
                                                <tr>
                                                    <td><code><?= esc_html($ev_id) ?></code></td>
                                                    <td><code><?= esc_html($ev['type'] ?? '') ?></code></td>
                                                    <td><?= esc_html($ev['processed_at'] ?? '') ?></td>
                                                    <td>
                                                        <span class="webhook-badge <?= esc_attr($ev['status'] ?? '') === 'processed' ? 'enabled' : 'failed' ?>">
                                                            <?= esc_html(strtoupper($ev['status'] ?? '')) ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
                </div>
            </div>
        </div>

        <script>
        (function($) {
            $('.shield-tab-button').on('click', function() {
                const target = $(this).data('shield-tab');
                $('.shield-tab-button').removeClass('active').attr('aria-selected', 'false');
                $(this).addClass('active').attr('aria-selected', 'true');
                $('.shield-tab-panel').removeClass('active');
                $('[data-shield-panel="' + target + '"]').addClass('active');
            });

            $('#sp-btn-connect').on('click', function(e) {
                e.preventDefault();
                const key    = $('#sp_proxy_key').val().trim();
                const saasUrl = $('#sp_saas_url').val().trim();
                if (!key) { $('#sp-connect-msg').css('color','#ef4444').text('Vui lòng nhập Shield Proxy Key'); return; }
                if (!saasUrl) { $('#sp-connect-msg').css('color','#ef4444').text('Vui lòng nhập SaaS URL'); return; }

                $(this).prop('disabled', true);
                $('#sp-spinner').addClass('is-active').show();
                $('#sp-connect-msg').css('color','#64748b').text('Đang kết nối...');

                $.post(ajaxurl, {
                    action:  'shield_saas_connect',
                    nonce:   '<?= wp_create_nonce('shield_saas_connect') ?>',
                    proxy_key: key,
                    saas_url: saasUrl,
                }, function(data) {
                    $('#sp-btn-connect').prop('disabled', false);
                    $('#sp-spinner').removeClass('is-active').hide();
                    if (data.success) {
                        $('#sp-connect-msg').css('color','#10b981').text('✓ Kết nối thành công! Đang tải lại...');
                        setTimeout(() => location.reload(), 1200);
                    } else {
                        $('#sp-connect-msg').css('color','#ef4444').text('✗ ' + (data.data || 'Kết nối thất bại'));
                    }
                }).fail(function() {
                    $('#sp-btn-connect').prop('disabled', false);
                    $('#sp-spinner').removeClass('is-active').hide();
                    $('#sp-connect-msg').css('color','#ef4444').text('Lỗi kết nối mạng');
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /* ── AJAX: kết nối với SaaS ───────────────────────────────────── */
    public function ajax_connect() {
        check_ajax_referer('shield_saas_connect', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error('Unauthorized'); }

        $proxy_key = sanitize_text_field($_POST['proxy_key'] ?? '');
        $saas_url  = esc_url_raw($_POST['saas_url'] ?? '');

        if (!$proxy_key || !$saas_url) {
            wp_send_json_error('Thiếu thông tin kết nối');
        }

        $domain   = get_site_url();
        $endpoint = trailingslashit($saas_url) . 'api/shields/connect';

        $response = wp_remote_post($endpoint, [
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => wp_json_encode(['shieldKey' => $proxy_key, 'domain' => $domain]),
            'timeout'   => 15,
            'sslverify' => false, // false cho local dev; set true cho production
        ]);

        if (is_wp_error($response)) {
            update_option(self::OPT_CONNECT_STATUS, 'failed');
            wp_send_json_error($response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && !empty($body['connected'])) {
            // Lưu key + SaaS URL
            update_option(self::OPT_PROXY_KEY, $proxy_key);
            update_option(self::OPT_SAAS_URL, $saas_url);
            update_option(self::OPT_CONNECT_STATUS, 'connected');
            update_option(self::OPT_CONNECT_DATA, $body);
            update_option(self::OPT_LAST_SYNC_STATUS, 'success');

            // Lưu payment config vào các option mà gateway sử dụng
            if (!empty($body['paymentConfig'])) {
                $cfg = $body['paymentConfig'];
                update_option('shield_paypal', [
                    'prod_client_id'  => $cfg['paypalProdClientId'] ?? '',
                    'prod_secret_key' => $cfg['paypalProdSecretKey'] ?? '',
                    'test_mode'       => !empty($cfg['paypalTestMode']) ? '1' : '0',
                    'test_client_id'  => $cfg['paypalTestClientId'] ?? '',
                    'test_secret_key' => $cfg['paypalTestSecretKey'] ?? '',
                ]);
                update_option('shield_stripe', [
                    'prod_publishable_key' => $cfg['stripeProdPublishableKey'] ?? '',
                    'prod_secret_key'      => $cfg['stripeProdSecretKey'] ?? '',
                    'test_mode'            => !empty($cfg['stripeTestMode']) ? '1' : '0',
                    'test_publishable_key' => $cfg['stripeTestPublishableKey'] ?? '',
                    'test_secret_key'      => $cfg['stripeTestSecretKey'] ?? '',
                ]);
            }
            update_option(self::OPT_LAST_SYNC_AT, current_time('mysql'));

            wp_send_json_success(['shieldId' => $body['shieldId'], 'name' => $body['name']]);
        } else {
            update_option(self::OPT_CONNECT_STATUS, 'failed');
            update_option(self::OPT_LAST_SYNC_STATUS, 'failed');
            $error = $body['message'] ?? "HTTP {$code}";
            wp_send_json_error($error);
        }
    }

    /* ── Verify cron callback ─────────────────────────────────────── */
    public static function cron_verify() {
        $proxy_key = get_option(self::OPT_PROXY_KEY, '');
        $saas_url  = get_option(self::OPT_SAAS_URL, SHIELD_MANAGE_URL);

        if (!$proxy_key) return;

        $response = wp_remote_post(trailingslashit($saas_url) . 'api/shields/verify', [
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => wp_json_encode(['shieldKey' => $proxy_key]),
            'timeout'   => 10,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) return;

        $body   = json_decode(wp_remote_retrieve_body($response), true);
        $active = !empty($body['active']);
        update_option(self::OPT_CONNECT_STATUS, $active ? 'connected' : 'failed');
        update_option(self::OPT_LAST_SYNC_STATUS, $active ? 'success' : 'failed');

        // Sync payment config nếu SaaS trả về (keys có thể đã thay đổi)
        if ($active && !empty($body['paymentConfig'])) {
            $cfg = $body['paymentConfig'];
            update_option('shield_paypal', [
                'prod_client_id'  => $cfg['paypalProdClientId']  ?? '',
                'prod_secret_key' => $cfg['paypalProdSecretKey'] ?? '',
                'test_mode'       => !empty($cfg['paypalTestMode']) ? '1' : '0',
                'test_client_id'  => $cfg['paypalTestClientId']  ?? '',
                'test_secret_key' => $cfg['paypalTestSecretKey'] ?? '',
            ]);
            update_option('shield_stripe', [
                'prod_publishable_key' => $cfg['stripeProdPublishableKey'] ?? '',
                'prod_secret_key'      => $cfg['stripeProdSecretKey']      ?? '',
                'test_mode'            => !empty($cfg['stripeTestMode']) ? '1' : '0',
                'test_publishable_key' => $cfg['stripeTestPublishableKey'] ?? '',
                'test_secret_key'      => $cfg['stripeTestSecretKey']      ?? '',
            ]);
            update_option(self::OPT_LAST_SYNC_AT, current_time('mysql'));
        }
    }

    /* ── Helpers ──────────────────────────────────────────────────── */
    private function authorize_proxy_request(\WP_REST_Request $request, $nonce_prefix) {
        $timestamp = $request->get_header('X-Shield-Timestamp');
        $signature = $request->get_header('X-Shield-Signature');
        $proxy_key = get_option(self::OPT_PROXY_KEY, '');

        if (!$proxy_key || !$timestamp || !$signature) {
            return new \WP_Error('unauthorized', 'Missing auth headers', ['status' => 401]);
        }
        if (abs(time() - (int) $timestamp) > 300) {
            return new \WP_Error('unauthorized', 'Timestamp expired', ['status' => 401]);
        }

        $nonce_key = $nonce_prefix . md5(implode('|', [
            $proxy_key,
            $timestamp,
            $signature,
            $request->get_route(),
            $request->get_method(),
        ]));
        if (get_transient($nonce_key)) {
            return new \WP_Error('unauthorized', 'Duplicate request', ['status' => 401]);
        }
        set_transient($nonce_key, '1', 300);

        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : $request->get_route();
        $route_bound = hash_hmac('sha256', implode('.', [
            $proxy_key,
            $timestamp,
            $request->get_method(),
            $request_uri,
        ]), $proxy_key);
        $body_bound = hash_hmac('sha256', implode('.', [
            $proxy_key,
            $timestamp,
            hash('sha256', (string) $request->get_body()),
        ]), $proxy_key);
        $canonical = hash_hmac('sha256', implode('.', [
            $proxy_key,
            $timestamp,
            $request->get_method(),
            $request_uri,
            hash('sha256', (string) $request->get_body()),
        ]), $proxy_key);
        $legacy = hash_hmac('sha256', $proxy_key . '.' . $timestamp, $proxy_key);
        $is_webhook_control_route = str_starts_with($request->get_route(), '/shield/v1/stripe-webhook/');
        $valid_signature = hash_equals($route_bound, $signature)
            || hash_equals($body_bound, $signature)
            || hash_equals($canonical, $signature)
            || (!$is_webhook_control_route && hash_equals($legacy, $signature));
        if (!$valid_signature) {
            return new \WP_Error('unauthorized', 'Invalid signature', ['status' => 401]);
        }

        return null;
    }

    private function sanitize_webhook_mode($mode) {
        return $mode === 'live' ? 'live' : 'test';
    }

    private function get_default_webhook_entry() {
        return [
            'endpoint_id' => '',
            'signing_secret' => '',
            'url' => '',
            'enabled_events' => self::STRIPE_WEBHOOK_EVENTS,
            'status' => 'not_created',
            'created_at' => '',
            'updated_at' => '',
            'last_checked_at' => '',
            'last_error' => '',
        ];
    }

    private function get_stripe_webhooks_store() {
        $store = get_option(self::OPT_STRIPE_WEBHOOKS, []);
        return [
            'test' => $this->normalize_webhook_entry($store['test'] ?? [], 'test'),
            'live' => $this->normalize_webhook_entry($store['live'] ?? [], 'live'),
        ];
    }

    private function update_stripe_webhooks_store($store) {
        update_option(self::OPT_STRIPE_WEBHOOKS, [
            'test' => $this->normalize_webhook_entry($store['test'] ?? [], 'test'),
            'live' => $this->normalize_webhook_entry($store['live'] ?? [], 'live'),
        ]);
    }

    private function normalize_webhook_entry($entry, $mode) {
        $defaults = $this->get_default_webhook_entry();
        $entry = is_array($entry) ? $entry : [];
        $merged = array_merge($defaults, $entry);
        $merged['enabled_events'] = is_array($merged['enabled_events']) && !empty($merged['enabled_events'])
            ? array_values(array_map('strval', $merged['enabled_events']))
            : self::STRIPE_WEBHOOK_EVENTS;
        $merged['status'] = !empty($merged['status']) ? (string) $merged['status'] : 'not_created';
        $merged['mode'] = $mode;
        return $merged;
    }

    private function get_mode_specific_stripe_keys($mode) {
        $cfg = get_option('shield_stripe', []);
        $secret_key = $mode === 'live'
            ? ($cfg['prod_secret_key'] ?? '')
            : ($cfg['test_secret_key'] ?? '');
        $publishable_key = $mode === 'live'
            ? ($cfg['prod_publishable_key'] ?? '')
            : ($cfg['test_publishable_key'] ?? '');

        return [
            'secret_key' => is_string($secret_key) ? trim($secret_key) : '',
            'publishable_key' => is_string($publishable_key) ? trim($publishable_key) : '',
        ];
    }

    private function get_site1_webhook_url() {
        $base = rest_url('shield/v1/stripe-webhook/receive');
        $parts = wp_parse_url($base);
        if (!$parts || empty($parts['host'])) {
            return $base;
        }

        $host = (string) $parts['host'];
        $is_local = $host === 'localhost'
            || str_ends_with($host, '.local')
            || preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host);

        if ($is_local && (($parts['scheme'] ?? 'http') === 'http')) {
            $parts['scheme'] = 'https';
            $url = $parts['scheme'] . '://' . $parts['host'];
            if (!empty($parts['port'])) {
                $url .= ':' . $parts['port'];
            }
            $url .= $parts['path'] ?? '/';
            if (!empty($parts['query'])) {
                $url .= '?' . $parts['query'];
            }
            return $url;
        }

        return $base;
    }

    private function is_old_domain_stripe_webhook_url($url) {
        $candidate = wp_parse_url((string) $url);
        $current = wp_parse_url($this->get_site1_webhook_url());

        if (!$candidate || !$current || empty($candidate['host']) || empty($current['host'])) {
            return false;
        }

        $candidate_path = rtrim((string) ($candidate['path'] ?? ''), '/');
        $current_path = rtrim((string) ($current['path'] ?? ''), '/');
        if ($candidate_path !== $current_path) {
            return false;
        }

        return strtolower((string) $candidate['host']) !== strtolower((string) $current['host']);
    }

    private function cleanup_old_domain_stripe_webhooks($mode) {
        $keys = $this->get_mode_specific_stripe_keys($mode);
        if (!$keys['secret_key']) {
            return new \WP_Error('invalid_config', sprintf('Stripe %s secret key is not configured on site1.', $mode), ['status' => 400]);
        }

        require_once CARDSSHIELD_PLUGIN_DIR . '/includes/stripe-php/init.php';

        $current = wp_parse_url($this->get_site1_webhook_url());
        if (!$current || empty($current['host']) || empty($current['path'])) {
            return new \WP_Error('invalid_config', 'Current site1 webhook URL is invalid.', ['status' => 400]);
        }

        $current_host = strtolower((string) $current['host']);
        $current_path = rtrim((string) $current['path'], '/');
        $deleted = [];

        try {
            $client = new \Stripe\StripeClient($keys['secret_key']);
            $webhooks = $client->webhookEndpoints->all(['limit' => 100]);
            $iterator = method_exists($webhooks, 'autoPagingIterator')
                ? $webhooks->autoPagingIterator()
                : ($webhooks->data ?? []);

            foreach ($iterator as $webhook) {
                $url = isset($webhook->url) ? (string) $webhook->url : '';
                $parts = wp_parse_url($url);
                if (!$parts || empty($parts['host']) || empty($parts['path'])) {
                    continue;
                }

                $host = strtolower((string) $parts['host']);
                $path = rtrim((string) $parts['path'], '/');
                if ($path !== $current_path || $host === $current_host) {
                    continue;
                }

                if (!$this->stripe_webhook_events_match($webhook)) {
                    continue;
                }

                $endpoint_id = isset($webhook->id) ? (string) $webhook->id : '';
                if ($endpoint_id === '') {
                    continue;
                }

                $client->webhookEndpoints->delete($endpoint_id);
                $deleted[] = [
                    'endpoint_id' => $endpoint_id,
                    'url' => $url,
                ];
            }

            if (!empty($deleted)) {
                $this->log_stripe_webhook('info', 'Old-domain Stripe webhooks deleted before recreate', [
                    'mode' => $mode,
                    'deleted' => $deleted,
                ]);
            }

            return [
                'deleted' => $deleted,
            ];
        } catch (\Throwable $e) {
            return new \WP_Error('stripe_webhook_cleanup_failed', $e->getMessage(), ['status' => 400]);
        }
    }

    private function stripe_webhook_events_match($webhook) {
        $enabled_events = isset($webhook->enabled_events) ? (array) $webhook->enabled_events : [];
        if (empty($enabled_events)) {
            return false;
        }

        foreach (self::STRIPE_WEBHOOK_EVENTS as $event) {
            if (!in_array($event, $enabled_events, true)) {
                return false;
            }
        }

        return true;
    }

    private function create_stripe_webhook_on_stripe($mode) {
        $keys = $this->get_mode_specific_stripe_keys($mode);
        if (!$keys['secret_key']) {
            return new \WP_Error('invalid_config', sprintf('Stripe %s secret key is not configured on site1.', $mode), ['status' => 400]);
        }

        require_once CARDSSHIELD_PLUGIN_DIR . '/includes/stripe-php/init.php';

        try {
            $client = new \Stripe\StripeClient($keys['secret_key']);
            $webhook = $client->webhookEndpoints->create([
                'url' => $this->get_site1_webhook_url(),
                'enabled_events' => self::STRIPE_WEBHOOK_EVENTS,
                'description' => sprintf('cards-shield site1 webhook (%s)', $mode),
            ]);

            return $this->normalize_webhook_entry([
                'endpoint_id' => isset($webhook->id) ? (string) $webhook->id : '',
                'signing_secret' => isset($webhook->secret) ? (string) $webhook->secret : '',
                'url' => isset($webhook->url) ? (string) $webhook->url : $this->get_site1_webhook_url(),
                'enabled_events' => isset($webhook->enabled_events) ? (array) $webhook->enabled_events : self::STRIPE_WEBHOOK_EVENTS,
                'status' => isset($webhook->status) ? (string) $webhook->status : 'enabled',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
                'last_checked_at' => current_time('mysql'),
                'last_error' => '',
            ], $mode);
        } catch (\Throwable $e) {
            return new \WP_Error('stripe_webhook_create_failed', $e->getMessage(), ['status' => 400]);
        }
    }

    private function sync_stripe_webhook_from_stripe($mode, $existing) {
        $keys = $this->get_mode_specific_stripe_keys($mode);
        if (!$keys['secret_key']) {
            return new \WP_Error('invalid_config', sprintf('Stripe %s secret key is not configured on site1.', $mode), ['status' => 400]);
        }
        if (empty($existing['endpoint_id'])) {
            return $this->normalize_webhook_entry($existing, $mode);
        }

        require_once CARDSSHIELD_PLUGIN_DIR . '/includes/stripe-php/init.php';

        try {
            $client = new \Stripe\StripeClient($keys['secret_key']);
            $webhook = $client->webhookEndpoints->retrieve($existing['endpoint_id']);

            return $this->normalize_webhook_entry([
                'endpoint_id' => isset($webhook->id) ? (string) $webhook->id : ($existing['endpoint_id'] ?? ''),
                'signing_secret' => $existing['signing_secret'] ?? '',
                'url' => isset($webhook->url) ? (string) $webhook->url : ($existing['url'] ?? ''),
                'enabled_events' => isset($webhook->enabled_events) ? (array) $webhook->enabled_events : ($existing['enabled_events'] ?? self::STRIPE_WEBHOOK_EVENTS),
                'status' => isset($webhook->status) ? (string) $webhook->status : ($existing['status'] ?? 'enabled'),
                'created_at' => $existing['created_at'] ?? '',
                'updated_at' => current_time('mysql'),
                'last_checked_at' => current_time('mysql'),
                'last_error' => '',
            ], $mode);
        } catch (\Throwable $e) {
            $existing['status'] = 'sync_failed';
            $existing['last_checked_at'] = current_time('mysql');
            $existing['last_error'] = $e->getMessage();
            return $this->normalize_webhook_entry($existing, $mode);
        }
    }

    private function disable_stripe_webhook_on_stripe($mode, $existing) {
        $keys = $this->get_mode_specific_stripe_keys($mode);
        if (!$keys['secret_key']) {
            return new \WP_Error('invalid_config', sprintf('Stripe %s secret key is not configured on site1.', $mode), ['status' => 400]);
        }

        require_once CARDSSHIELD_PLUGIN_DIR . '/includes/stripe-php/init.php';

        try {
            if (!empty($existing['endpoint_id'])) {
                $client = new \Stripe\StripeClient($keys['secret_key']);
                try {
                    $client->webhookEndpoints->delete($existing['endpoint_id']);
                } catch (\Throwable $e) {
                    $message = $e->getMessage();
                    if (stripos($message, 'No such webhook_endpoint') === false) {
                        throw $e;
                    }
                }
            }

            return $this->normalize_webhook_entry([
                'endpoint_id' => '',
                'signing_secret' => '',
                'url' => $existing['url'] ?: $this->get_site1_webhook_url(),
                'enabled_events' => self::STRIPE_WEBHOOK_EVENTS,
                'status' => 'disabled',
                'created_at' => $existing['created_at'] ?? '',
                'updated_at' => current_time('mysql'),
                'last_checked_at' => current_time('mysql'),
                'last_error' => '',
            ], $mode);
        } catch (\Throwable $e) {
            return new \WP_Error('stripe_webhook_disable_failed', $e->getMessage(), ['status' => 400]);
        }
    }

    private function format_webhook_for_response($entry) {
        $entry = $this->normalize_webhook_entry($entry, $entry['mode'] ?? 'test');
        return [
            'endpointId' => $entry['endpoint_id'] ?: null,
            'url' => $entry['url'] ?: null,
            'enabledEvents' => $entry['enabled_events'],
            'status' => $entry['status'],
            'createdAt' => $entry['created_at'] ?: null,
            'updatedAt' => $entry['updated_at'] ?: null,
            'lastCheckedAt' => $entry['last_checked_at'] ?: null,
            'lastError' => $entry['last_error'] ?: null,
        ];
    }

    private function push_stripe_webhook_status_to_manager_site($mode, $payload) {
        $callback_url = !empty($payload['managerCallbackUrl']) ? esc_url_raw((string) $payload['managerCallbackUrl']) : '';
        $manager_id = !empty($payload['managerId']) ? sanitize_text_field((string) $payload['managerId']) : '';
        $credential = $this->find_active_manager_hmac_credential($manager_id);

        if (!$callback_url || !$manager_id || !$credential) {
            $this->log_stripe_webhook('warning', 'Stripe webhook direct callback: missing routing data', [
                'manager_id'        => $manager_id,
                'route_id'          => $payload['routeId'] ?? null,
                'payment_intent_id' => $payload['paymentIntentId'] ?? null,
                'has_callback_url'  => !empty($callback_url),
                'has_credential'    => !empty($credential),
            ]);
            return [
                'attempted' => false,
                'success' => false,
                'message' => 'Missing direct manager callback credentials',
            ];
        }

        $attempts = 0;
        $last_message = 'Unknown callback failure';

        while ($attempts < 3) {
            $attempts++;
            $body = wp_json_encode($payload);
            $headers = $this->build_manager_direct_callback_headers($credential, 'POST', $callback_url, $body);

            $response = wp_remote_post($callback_url, [
                'headers' => array_merge($headers, [
                    'Content-Type' => 'application/json',
                ]),
                'body' => $body,
                'timeout' => 10,
                'sslverify' => false,
            ]);

            if (is_wp_error($response)) {
                $last_message = $response->get_error_message();
                if ($attempts < 3) {
                    usleep(250000 * $attempts);
                    continue;
                }
                break;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $decoded = json_decode(wp_remote_retrieve_body($response), true);
            $last_message = is_array($decoded) && !empty($decoded['message']) ? $decoded['message'] : "HTTP {$code}";

            if ($code >= 200 && $code < 300) {
                return [
                    'attempted' => true,
                    'success' => true,
                    'message' => $last_message,
                    'attempts' => $attempts,
                ];
            }

            if ($code >= 500 && $attempts < 3) {
                usleep(250000 * $attempts);
                continue;
            }
            break;
        }

        $this->log_stripe_webhook('warning', 'Stripe webhook direct callback to manager failed', [
            'mode' => $mode,
            'event_id' => $payload['eventId'] ?? null,
            'payment_intent_id' => $payload['paymentIntentId'] ?? null,
            'attempts' => $attempts,
            'message' => $last_message,
        ]);

        return [
            'attempted' => true,
            'success' => false,
            'message' => $last_message,
            'attempts' => $attempts,
        ];
    }

    private function find_active_manager_hmac_credential($manager_id) {
        if (!$manager_id || !function_exists('shield_hmac_keys_v2_all')) {
            return null;
        }

        foreach (shield_hmac_keys_v2_all() as $credential) {
            if (($credential['manager_id'] ?? '') === $manager_id && (($credential['status'] ?? 'active') === 'active')) {
                return $credential;
            }
        }

        return null;
    }

    private function build_manager_direct_callback_headers($credential, $method, $url, $body) {
        $parts = wp_parse_url((string) $url);
        $path = isset($parts['path']) ? $parts['path'] : '/';
        $query = isset($parts['query']) && $parts['query'] !== '' ? ('?' . $parts['query']) : '';
        $timestamp = (string) time();
        $nonce = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('swcb_', true);
        $manager_id = (string) ($credential['manager_id'] ?? '');
        $key_id = (string) ($credential['key_id'] ?? '');

        $canonical = implode("\n", [
            strtoupper((string) $method),
            $path . $query,
            hash('sha256', (string) $body),
            $timestamp,
            $nonce,
            $manager_id,
            $key_id,
        ]);

        return [
            'X-Shield-Signature' => hash_hmac('sha256', $canonical, (string) ($credential['hmac_secret'] ?? '')),
            'X-Shield-Timestamp' => $timestamp,
            'X-Shield-Nonce' => $nonce,
            'X-Shield-Manager-Id' => $manager_id,
            'X-Shield-Key-Id' => $key_id,
        ];
    }

    private function should_accept_state_transition($previous_state, $next_state) {
        $previous = is_string($previous_state) ? $previous_state : '';
        $next = is_string($next_state) ? $next_state : '';
        if ($previous === '' || $previous === $next) {
            return true;
        }
        if ($this->is_final_webhook_state($previous)) {
            return false;
        }
        return true;
    }

    private function is_final_webhook_state($state) {
        return in_array($state, ['succeeded', 'payment_failed'], true);
    }

    private function cleanup_stripe_webhook_storage() {
        $events = get_option(self::OPT_STRIPE_WEBHOOK_EVENTS, []);
        $events = is_array($events) ? $events : [];
        $event_cutoff = time() - (14 * DAY_IN_SECONDS);
        foreach ($events as $event_id => $event) {
            $processed_at = isset($event['processed_at']) ? strtotime((string) $event['processed_at']) : 0;
            if ($processed_at > 0 && $processed_at < $event_cutoff) {
                unset($events[$event_id]);
            }
        }
        if (count($events) > 200) {
            $events = array_slice($events, -200, null, true);
        }
        update_option(self::OPT_STRIPE_WEBHOOK_EVENTS, $events);

        $payments = get_option(self::OPT_STRIPE_WEBHOOK_PAYMENTS, []);
        $payments = is_array($payments) ? $payments : [];
        $payment_cutoff = time() - (30 * DAY_IN_SECONDS);
        foreach ($payments as $payment_intent_id => $payment) {
            $updated_at = isset($payment['updated_at']) ? strtotime((string) $payment['updated_at']) : 0;
            if ($updated_at > 0 && $updated_at < $payment_cutoff) {
                unset($payments[$payment_intent_id]);
            }
        }
        if (count($payments) > 200) {
            $payments = array_slice($payments, -200, null, true);
        }
        update_option(self::OPT_STRIPE_WEBHOOK_PAYMENTS, $payments);
    }

    private function log_stripe_webhook($level, $message, $context = []) {
        $payload = wp_json_encode($context);
        if ($logger = function_exists('wc_get_logger') ? wc_get_logger() : null) {
            $logger->log($level, $message . ($payload ? ' ' . $payload : ''), ['source' => 'cards-shield-stripe-webhook']);
            return;
        }
        error_log($message . ($payload ? ' ' . $payload : ''));
    }

    public function render_stripe_webhook_panel($mode, $entry) {
        $entry = $this->normalize_webhook_entry($entry, $mode);
        $status = str_replace('_', ' ', $entry['status']);
        $status_class = match ($entry['status']) {
            'enabled' => 'enabled',
            'disabled' => 'disabled',
            'sync_failed', 'unreachable' => 'failed',
            default => 'pending',
        };
        $mode_title = ucfirst($mode) . ' Mode';

        $html = '<div class="webhook-item">';
        $html .= '  <div class="webhook-header ' . esc_attr($mode) . '">';
        $html .= '    <span>' . esc_html($mode_title) . '</span>';
        $html .= '    <span class="webhook-badge ' . esc_attr($status_class) . '">' . esc_html(strtoupper($status)) . '</span>';
        $html .= '  </div>';
        $html .= '  <div class="webhook-body">';
        
        $fields = [
            'Webhook URL' => [
                'val' => $entry['url'] ?: '(not created)',
                'copy' => !empty($entry['url']),
            ],
            'Endpoint ID' => [
                'val' => $entry['endpoint_id'] ?: '(none)',
                'copy' => !empty($entry['endpoint_id']),
            ],
            'Enabled Events' => [
                'val' => implode(', ', (array) $entry['enabled_events']),
                'copy' => false,
            ],
            'Last Checked' => [
                'val' => $entry['last_checked_at'] ?: '(never)',
                'copy' => false,
            ],
        ];

        if (!empty($entry['last_error'])) {
            $fields['Last Error'] = [
                'val' => $entry['last_error'],
                'copy' => false,
                'error' => true,
            ];
        }

        foreach ($fields as $label => $info) {
            $val = $info['val'];
            $is_error = !empty($info['error']);
            $html .= '    <div style="margin-bottom: 16px;">';
            $html .= '      <span class="webhook-field-label">' . esc_html($label) . '</span>';
            $html .= '      <div class="webhook-field-wrapper">';
            
            if ($info['copy']) {
                $html .= '        <input type="text" value="' . esc_attr($val) . '" class="webhook-field-input" readonly>';
                $html .= '        <button type="button" class="webhook-field-copy-btn" onclick="navigator.clipboard.writeText(\'' . esc_js($val) . '\'); alert(\'Copied!\');">Copy</button>';
            } else {
                $class = $is_error ? 'webhook-field-value error' : 'webhook-field-value';
                $html .= '        <span class="' . $class . '">' . esc_html($val) . '</span>';
            }
            $html .= '      </div>';
            $html .= '    </div>';
        }

        $html .= '  </div>';
        $html .= '</div>';
        return $html;
    }

    public function generate_html_table($data) {
        $html = '<table class="form-table" role="presentation"><tbody>';
        foreach ((array)$data as $key => $value) {
            if (is_array($value)) {
                $value = wp_json_encode($value);
            }
            $html .= '<tr><th scope="row"><span>' . esc_html($key) . '</span></th>'
                   . '<td><input type="text" value="' . esc_attr($value) . '" style="width:100%;" disabled></td></tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    public function register_settings_fields() {
        // Legacy License Key UI removed. HMAC manager auth uses v2 keyring.
    }

    public function render_license_key_field($args) {
        $val = get_option(self::OPT_LICENSE_KEY, '');
        printf('<input type="text" id="%s" name="%s" value="%s" style="width:100%%" />',
            esc_attr($args['label_for']), esc_attr($args['name']), esc_attr($val));
        echo '<p class="description">Key dùng để xác thực HMAC giữa Shield Manager (site2) và plugin này.</p>';
    }

    public function validate_license_key($license_key) {
        return sanitize_text_field($license_key);
    }

    public function display_notice() {
        $errors = get_settings_errors('shield_license_key');
        if (!empty($errors)) return;
        if (
            isset($_GET['page'], $_GET['settings-updated']) &&
            $_GET['page'] === 'shield-settings' && $_GET['settings-updated']
        ) { ?>
            <div class="notice notice-success is-dismissible"><p><strong>Shield settings saved.</strong></p></div>
        <?php }
    }
}

new ShieldSettings();
