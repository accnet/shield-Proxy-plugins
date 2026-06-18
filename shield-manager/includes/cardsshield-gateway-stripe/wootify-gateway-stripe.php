<?php

if (!defined('ABSPATH')) {
    exit;
}
if (!function_exists('cs_stripe_get_plugin_file')) {
    function cs_stripe_get_plugin_file() {
        return defined('SHIELD_MANAGER_PLUGIN_FILE') ? SHIELD_MANAGER_PLUGIN_FILE : __FILE__;
    }
}

if (!function_exists('cs_stripe_endpoint_gateway_is_usable')) {
    function cs_stripe_endpoint_gateway_is_usable() {
        $settings = get_option('woocommerce_endpoint_stripe_settings', array());
        if (($settings['enabled'] ?? 'no') !== 'yes') {
            return false;
        }

        return class_exists('Shield_Stripe_Endpoint_Client')
            && Shield_Stripe_Endpoint_Client::is_connected()
            && Shield_Stripe_Endpoint_Client::has_active_nodes()
            && Shield_Stripe_Endpoint_Client::get_active_node();
    }
}

register_activation_hook(cs_stripe_get_plugin_file(), 'WOOTIFY_gateway_stripe_install');

require_once(plugin_dir_path(__FILE__) . 'utils.php');



//Cron
add_filter('cron_schedules', 'WOOTIFY_add_stripe_cron_interval');
if (!wp_next_scheduled('WOOTIFY_gateway_stripe_rotation')) {
    wp_schedule_event(time(), 'one_minute', 'WOOTIFY_gateway_stripe_rotation');
}

add_action('WOOTIFY_gateway_stripe_rotation', 'WOOTIFY_stripe_rotation_checker');
if (!wp_next_scheduled('WOOTIFY_gateway_stripe_daily')) {
    wp_schedule_event(strtotime('23:59:59'), 'daily', 'WOOTIFY_gateway_stripe_daily');
}
add_action('WOOTIFY_gateway_stripe_daily', 'WOOTIFY_gateway_stripe_daily_process');

function WOOTIFY_gateway_stripe_daily_process() {
    // Reset paid amount
    $rotationMethod = get_option(OPT_WOOTIFY_STRIPE_ROTATION_METHOD, WOOTIFY_STRIPE_BY_TIME);
    if ($rotationMethod === WOOTIFY_STRIPE_BY_AMOUNT) {
        resetPaidAmountStripe();
    }
}

add_action('plugins_loaded', 'WOOTIFY_add_gateway_stripe_init');
add_action('wp_loaded', 'cs_stripe_handle_link_express_post_route');
add_action('get_header', 'handle_route');
add_action('woocommerce_admin_order_totals_after_total', 'action_woocommerce_admin_order_totals_after_total_stripe', 10, 1);

function cs_stripe_get_setting_value($key, $default = null) {
    $settings = get_option('woocommerce_WOOTIFY_stripe_settings', []);
    if (!empty($settings) && array_key_exists($key, $settings)) {
        return $settings[$key];
    }
    $legacy = get_option('woocommerce_stripe_settings', []);
    if (!empty($legacy) && array_key_exists($key, $legacy)) {
        return $legacy[$key];
    }
    return $default;
}

function WOOTIFY_add_stripe_cron_interval($schedules) {
    $schedules['one_minute'] = array(
        'interval' => 60,
        'display' => esc_html__('Every minute'),
    );

    return $schedules;
}

function WOOTIFY_gateway_stripe_install() {
    //    delete_option('WOOTIFY_gateway_stripe_version');
    //    add_option('WOOTIFY_gateway_stripe_version', uniqid());
}

function renderMoneyRowStripe($title, $tooltip, $value, $currency, $negative = false) {
    /**
     * Bad type hint in WC phpdoc.
     *
     * @psalm-suppress InvalidScalarArgument
     */
    return '
            <tr>
                <td class="label">' . wc_help_tip($tooltip) . ' ' . esc_html($title) . '
                </td>
                <td width="1%"></td>
                <td class="total">
                    ' .
        ($negative ? ' - ' : '') .
        wc_price($value, array('currency' => $currency)) . '
                </td>
            </tr>';
}

function action_woocommerce_admin_order_totals_after_total_stripe($order_get_id) {

    $wc_order = wc_get_order($order_get_id);
    if (!$wc_order instanceof WC_Order) {
        return;
    }

    if ($wc_order->get_payment_method() !== 'WOOTIFY_stripe') {
        return;
    }
    $stripeFee = $wc_order->get_meta(METAKEY_CS_STRIPE_FEE);
    $stripeCurrency = $wc_order->get_meta(METAKEY_CS_STRIPE_CURRENCY);
    $stripePayout = $wc_order->get_meta(METAKEY_CS_STRIPE_PAYOUT);

    $html = '';

    if (isset($stripeFee) && isset($stripeCurrency)) {
        $html .= renderMoneyRowStripe(
            'Stripe Fee:',
            'The fee Stripe collects for the transaction.',
            $stripeFee,
            $stripeCurrency,
            true
        );
    }

    if (isset($stripePayout) && isset($stripeCurrency)) {
        $html .= renderMoneyRowStripe(
            'Stripe Payout:',
            'The net total that will be credited to your Stripe account.',
            $stripePayout,
            $stripeCurrency
        );
    }

    echo $html;
}

function csStripeApplyWebhookFallbackResult($order, $activatedProxy, $statusData, $traceId) {
    if (!$order || !is_array($statusData) || empty($statusData['success']) || empty($statusData['found']) || empty($statusData['paymentState'])) {
        return false;
    }

    $paymentState = $statusData['paymentState'];
    $state = $paymentState['state'] ?? '';
    $paymentIntentId = $paymentState['paymentIntentId'] ?? csStripeGetTransactionId($order);
    $lastAppliedState = $order->get_meta('_cs_stripe_webhook_last_applied_state');
    $currentStatus = $order->get_status();

    csStripeDebugLog([
        'trace_id' => $traceId,
        'proxy_url' => $activatedProxy['url'] ?? null,
        'proxy_id' => $activatedProxy['id'] ?? null,
        'order_id' => $order->get_id(),
        'payment_intent_id' => $paymentIntentId,
        'webhook_state' => $state,
        'event_id' => $paymentState['eventId'] ?? null,
    ], 'Stripe webhook fallback status received');

    if ($state === 'succeeded' && in_array($currentStatus, ['processing', 'completed'], true)) {
        return [
            'handled' => true,
            'type' => 'success',
            'skipped' => true,
        ];
    }

    if ($state === 'processing' && $currentStatus === 'on-hold' && $lastAppliedState === 'processing') {
        return [
            'handled' => true,
            'type' => 'processing',
            'skipped' => true,
        ];
    }

    if ($state === 'payment_failed' && $currentStatus === 'failed' && $lastAppliedState === 'payment_failed') {
        return [
            'handled' => true,
            'type' => 'failed',
            'skipped' => true,
        ];
    }

    if ($state === 'succeeded') {
        $order->payment_complete();
        $order->reduce_order_stock();
        $order->add_order_note(sprintf(
            __('Stripe payment recovered by webhook fallback via proxy %s (Payment Intent ID: %s)', 'wootify'),
            $activatedProxy['url'] ?? '',
            $paymentIntentId
        ));
        csStripeSaveTransactionId($order, $paymentIntentId);
        $order->update_meta_data('_cs_stripe_webhook_last_applied_state', 'succeeded');
        $order->update_meta_data('_cs_stripe_webhook_last_repair_at', time());
        $order->save();
        if (WC()->cart) {
            WC()->cart->empty_cart();
        }
        return [
            'handled' => true,
            'type' => 'success',
        ];
    }

    if ($state === 'processing') {
        $order->update_status('on-hold', 'Waiting for Stripe webhook confirmation.');
        $order->add_order_note(sprintf(
            __('Stripe payment is still processing according to webhook fallback via proxy %s (Payment Intent ID: %s)', 'wootify'),
            $activatedProxy['url'] ?? '',
            $paymentIntentId
        ));
        csStripeSaveTransactionId($order, $paymentIntentId);
        $order->update_meta_data('_cs_stripe_webhook_last_applied_state', 'processing');
        $order->update_meta_data('_cs_stripe_webhook_last_repair_at', time());
        $order->save();
        return [
            'handled' => true,
            'type' => 'processing',
        ];
    }

    if ($state === 'payment_failed') {
        $order->update_status('failed', 'Stripe webhook fallback marked payment as failed.');
        $order->add_order_note(sprintf(
            __('Stripe payment failed according to webhook fallback via proxy %s (Payment Intent ID: %s)', 'wootify'),
            $activatedProxy['url'] ?? '',
            $paymentIntentId
        ));
        csStripeSaveTransactionId($order, $paymentIntentId);
        $order->update_meta_data('_cs_stripe_webhook_last_applied_state', 'payment_failed');
        $order->update_meta_data('_cs_stripe_webhook_last_repair_at', time());
        $order->save();
        return [
            'handled' => true,
            'type' => 'failed',
        ];
    }

    return false;
}

function csStripeAttemptWebhookFallback($order, $activatedProxy, $paymentIntentId, $traceId) {
    if (!$order || !$activatedProxy || empty($activatedProxy['id']) || empty($paymentIntentId)) {
        return false;
    }

    $lastAppliedState = $order->get_meta('_cs_stripe_webhook_last_applied_state');
    if (!$lastAppliedState) {
        return false;
    }

    return [
        'handled' => true,
        'type' => $lastAppliedState === 'payment_failed' ? 'failed' : $lastAppliedState,
        'skipped' => true,
    ];
}

function csStripeDirectWebhookCallbackUrl() {
    return rest_url('shield-manager/v1/stripe-webhook/direct');
}

function csStripeFindRepairableOrders($limit = 20) {
    if (!function_exists('wc_get_orders')) {
        return [];
    }

    return wc_get_orders([
        'type' => 'shop_order',
        'status' => ['pending', 'on-hold'],
        'payment_method' => 'WOOTIFY_stripe',
        'limit' => $limit,
        'orderby' => 'date',
        'order' => 'DESC',
        'return' => 'objects',
    ]);
}

function csStripeRepairPendingWebhookOrders($limit = 20) {
    $orders = csStripeFindRepairableOrders($limit);
    if (empty($orders)) {
        return [
            'checked' => 0,
            'handled' => 0,
        ];
    }

    $proxies = get_option(OPT_WOOTIFY_STRIPE_PROXIES, []);
    $summary = [
        'checked' => 0,
        'handled' => 0,
    ];

    foreach ($orders as $order) {
        if (!$order instanceof WC_Order) {
            continue;
        }

        if ($order->get_meta(METAKEY_WOOTIFY_STRIPE_INTENT_AUTHORIZED) === 'true') {
            continue;
        }

        $paymentIntentId = csStripeGetTransactionId($order);
        $proxyId = $order->get_meta(METAKEY_STRIPE_PROXY_ID);
        $proxyUrl = $order->get_meta(METAKEY_STRIPE_PROXY_URL);
        if (empty($paymentIntentId) || empty($proxyId)) {
            continue;
        }

        $activatedProxy = findActivatedProxyDataByIdStripe($proxies, $proxyId);
        if (!$activatedProxy) {
            $activatedProxy = [
                'id' => $proxyId,
                'url' => $proxyUrl,
            ];
        }

        $summary['checked']++;
        $traceId = csStripeGenerateTraceId();
        $result = csStripeAttemptWebhookFallback($order, $activatedProxy, $paymentIntentId, $traceId);
        $order->update_meta_data('_cs_stripe_webhook_last_repair_check_at', time());
        $order->save();

        if (is_array($result) && !empty($result['handled'])) {
            $summary['handled']++;
        }
    }

    if ($summary['checked'] > 0) {
        csStripeDebugLog($summary, 'Stripe webhook repair cron summary');
    }

    return $summary;
}

function WOOTIFY_stripe_rotation_checker() {
    csStripeRepairPendingWebhookOrders();

    $rotationMethod = get_option(OPT_WOOTIFY_STRIPE_ROTATION_METHOD, WOOTIFY_STRIPE_BY_TIME);
    if ($rotationMethod != WOOTIFY_STRIPE_BY_TIME) {
        return;
    }
    // Auto Switching Proxy
    $proxies = get_option(OPT_WOOTIFY_STRIPE_PROXIES, []);
    if (empty($proxies)) {
        return;
    }
    $activatedProxy = get_option(OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY, null);
    // if only has 1 proxy, don't rotate
    if (count($proxies) === 1 && isset($activatedProxy['id']) && $activatedProxy['id'] === $proxies[0]['id']) {
        return;
    }
    $lastActivatedTimestamp = get_option(OPT_WOOTIFY_STRIPE_CURRENT_ROTATION_VALUE, 0);
    if (empty($lastActivatedTimestamp) || $lastActivatedTimestamp == 0) {
        $lastActivatedTimestamp = time();
        update_option(OPT_WOOTIFY_STRIPE_CURRENT_ROTATION_VALUE, $lastActivatedTimestamp, true);
    }

    $hasDeletedActivateProxy = false;

    if ($lastActivatedTimestamp != 0 && !empty($activatedProxy)) {
        $currentTimestamp = time();
        $timeStampSinceLastActivated = $currentTimestamp - $lastActivatedTimestamp;
        // How many minutes?
        $minutes = $timeStampSinceLastActivated / 60;

        // Need to rotate
        if ($minutes > floatval($activatedProxy["timestamp"])) {
            $hasDeletedActivateProxy = true;
            for ($i = 0; $i < count($proxies); $i++) {
                $proxy = $proxies[$i];
                if ($proxy["id"] == $activatedProxy["id"]) {
                    // The last one => active the proxy at 0 index
                    if ($i + 1 == count($proxies)) {
                        $needToActivateProxy = $proxies[0];
                    } else {
                        // Active the next one
                        $needToActivateProxy = $proxies[$i + 1];
                    }
                    update_option(OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY, $needToActivateProxy, true);
                    update_option(OPT_WOOTIFY_STRIPE_CURRENT_ROTATION_VALUE, time(), true);
                    logStripeRotation(WOOTIFY_STRIPE_BY_TIME, $needToActivateProxy, "Auto");
                    $hasDeletedActivateProxy = false;
                    break;
                }
            }
        }
    }

    if (empty($activatedProxy) || $hasDeletedActivateProxy) {
        update_option(OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY, $proxies[0], true);
        update_option(OPT_WOOTIFY_STRIPE_CURRENT_ROTATION_VALUE, time(), true);
        logStripeRotation(WOOTIFY_STRIPE_BY_TIME, $proxies[0], "Auto");
    }
}

function cs_stripe_redirect_and_exit($url) {
    wp_safe_redirect($url);
    exit();
}

function handle_route() {
    if (isset($_POST['wootify-stripe-link-create-woo-order'])) {
        // Defer to stripe-endpoint-gateway when it is active and configured.
        $defer = function_exists('ep_stripe_handle_link_express_create_woo_order')
            && !empty(get_option('EP_ST_NODES', []));
        if (!$defer) {
            cs_stripe_handle_link_express_create_woo_order();
            exit();
        }
        return;
    }

    if (isset($_GET['WOOTIFY_stripe_return_result']) && isset($_GET['order_id'])) {
        $order = wc_get_order($_GET['order_id']);
        if (!$order) {
            wc_add_notice('We cannot process your payment right now, please try another payment method.[11]', 'error');
            return cs_stripe_redirect_and_exit(wc_get_checkout_url());
        }

        // If order has already been completed/processed by another tab/attempt, redirect directly to success page
        if ($order->has_status(array('processing', 'completed', 'on-hold'))) {
            return cs_stripe_redirect_and_exit($order->get_checkout_order_received_url());
        }

        // Validate attempt token to prevent stale tabs from executing double confirms or failing the order
        $savedAttemptToken = $order->get_meta('_cs_stripe_attempt_token');
        $requestAttemptToken = isset($_GET['attempt_token']) ? sanitize_text_field($_GET['attempt_token']) : '';
        if (!empty($savedAttemptToken) && $savedAttemptToken !== $requestAttemptToken) {
            csStripeErrorLog("Attempt token mismatch: order expects '{$savedAttemptToken}', got '{$requestAttemptToken}'");
            wc_add_notice('This payment attempt is outdated. Please try again.', 'error');
            return cs_stripe_redirect_and_exit(wc_get_checkout_url());
        }

        if (!$activeProxyId = $order->get_meta(METAKEY_STRIPE_PROXY_ID)) {
            $activeProxyId = WC()->session->get('wootify-stripe-proxy-active-id');
        }
        $activatedProxy = findActivatedProxyDataByIdStripe(get_option(OPT_WOOTIFY_STRIPE_PROXIES, []), $activeProxyId);

        if (!$activatedProxy) {
            csStripeErrorLog("Can't find activated proxy!\n");
            wc_add_notice('We cannot accept any payments right now. Please comeback to try tomorrow or select other payment methods if available.', 'error');
            return cs_stripe_redirect_and_exit(wc_get_checkout_url());
        }
        $confirmUrl = $activatedProxy['url'] . '?' . http_build_query([
            'wootify-stripe-pe-confirm-payment' => uniqid(),
            'payment_intent_id' => csStripeGetTransactionId($order),
            'amount' => $order->get_total(),
            'currency' => $order->get_currency(),
            'order_id' => $order->get_id(),
            'shield_id' => $activatedProxy['id'],
            'manager_callback_url' => csStripeDirectWebhookCallbackUrl(),
        ]);
        $traceId = csStripeGenerateTraceId();
        $response = wp_remote_get($confirmUrl, shield_proxy_signed_request_args($activatedProxy, 'GET', $confirmUrl, [
            '_shield_gateway' => 'stripe',
            'sslverify' => false,
            'timeout' => 5 * 60,
            'headers' => [
                'X-Shield-Trace-Id' => $traceId,
            ],
        ]));
        $order->update_meta_data(METAKEY_STRIPE_PROXY_URL, $activatedProxy['url']);
        $order->update_meta_data(METAKEY_STRIPE_PROXY_ID, $activatedProxy['id']);
        $order->save();
        if (is_wp_error($response)) {
            csStripeErrorLog([$response, 'trace_id' => $traceId, 'proxy_url' => $activatedProxy['url'], 'order_id' => $order->get_id()], 'Stripe return result error');
            $fallback = csStripeAttemptWebhookFallback($order, $activatedProxy, csStripeGetTransactionId($order), $traceId);
            if (is_array($fallback) && !empty($fallback['handled'])) {
                if ($fallback['type'] === 'success' || $fallback['type'] === 'processing') {
                    return cs_stripe_redirect_and_exit($order->get_checkout_order_received_url());
                }
                wc_add_notice('We cannot process your payment right now, please try another payment method.[fallback-failed]', 'error');
                return cs_stripe_redirect_and_exit(wc_get_checkout_url());
            }
            wc_add_notice('We cannot process your payment right now, please try another payment method.', 'error');
            return cs_stripe_redirect_and_exit(wc_get_checkout_url());
        }
        $rawBody = wp_remote_retrieve_body($response);
        $body = json_decode($rawBody);
        if (!is_object($body) || !isset($body->status)) {
            csStripeErrorLog([
                'response_body' => $rawBody,
                'trace_id' => $traceId,
                'proxy_url' => $activatedProxy['url'],
                'order_id' => $order->get_id(),
            ], 'Stripe confirm payment invalid response');
            $fallback = csStripeAttemptWebhookFallback($order, $activatedProxy, csStripeGetTransactionId($order), $traceId);
            if (is_array($fallback) && !empty($fallback['handled']) && ($fallback['type'] === 'success' || $fallback['type'] === 'processing')) {
                return cs_stripe_redirect_and_exit($order->get_checkout_order_received_url());
            }
            wc_add_notice('We cannot process your payment right now, please try another payment method.[invalid-response]', 'error');
            return cs_stripe_redirect_and_exit(wc_get_checkout_url());
        }
        // Read intent from own gateway settings (with legacy woocommerce_stripe_settings fallback)
        $paymentStripeIntent = cs_stripe_get_setting_value('intent', 'capture');

        if ($body->status === 'success') {
            $paymentIntent = $body->payment_intent;
            csStripeDebugLog([
                'trace_id' => $traceId,
                'correlation_id' => $body->correlation_id ?? null,
                'proxy_url' => $activatedProxy['url'],
                'proxy_id' => $activatedProxy['id'],
                'order_id' => $order->get_id(),
                'payment_intent_id' => $paymentIntent->id ?? null,
                'intent_status' => isset($paymentIntent->status) ? $paymentIntent->status : null,
                'status' => $body->status,
            ], 'Stripe confirm-payment success');
            $intentStatus = isset($paymentIntent->status) ? $paymentIntent->status : null;
            $isAuthorized = $paymentStripeIntent === OPT_WOOTIFY_STRIPE_INTENT_AUTHORIZE || $intentStatus === 'requires_capture';
            if ($isAuthorized) {
                $order->add_order_note(sprintf(__('Stripe authorize by proxy %s, (Payment Intent ID: %s)', 'wootify'), $activatedProxy['url'], $paymentIntent->id));
                $order->update_status('on-hold', 'Payment can be captured.');
                $order->update_meta_data(METAKEY_WOOTIFY_STRIPE_INTENT_AUTHORIZED, 'true');
            } else {
                $order->payment_complete();
                //Save the processed proxy for this order (using for refund later)
                $order->add_order_note(sprintf(__('Stripe charged by proxy %s', 'wootify'), $activatedProxy['url']), 0, false);
                // some notes to customer (replace true with false to make it private)
                $order->add_order_note(sprintf(__('Stripe Checkout charge complete (Payment Intent ID: %s)', 'wootify'), $paymentIntent->id));
            }
            $order->reduce_order_stock();

            // Report transaction to SaaS (mirrors stripe-endpoint-gateway behavior)
            if (class_exists('Shield_Stripe_Endpoint_Client')) {
                // shield-manager proxies use legacy IDs ('id') and do NOT have 'shieldId'.
                // Resolve shieldId from the Endpoint Client's active node instead.
                $epShieldId = $activatedProxy['shieldId'] ?? null;
                if (empty($epShieldId)) {
                    $epActiveNode = Shield_Stripe_Endpoint_Client::get_active_node();
                    $epShieldId = $epActiveNode['shieldId'] ?? ($epActiveNode['nodeId'] ?? null);
                }
                if (!empty($epShieldId)) {
                    Shield_Stripe_Endpoint_Client::report_transaction(
                        $epShieldId,
                        $order->get_total(),
                        $order->get_id(),
                        $order->get_currency(),
                        [
                            'providerTransactionId' => $body->charge->id ?? null,
                            'paymentIntentId'       => $paymentIntent->id ?? null,
                            'idempotencyKey'        => 'stripe:confirm:' . $order->get_id() . ':' . ($paymentIntent->id ?? ''),
                            'traceId'               => $traceId,
                            'paymentStatus'         => $isAuthorized ? 'processing' : 'succeeded',
                        ]
                    );
                }
            }

            if (isEnabledAmountRotationStripe()) {
                performProxyAmountRotationStripe($order->get_total());
                updateRotationAmountStripe($activatedProxy['id'], $order->get_total());
            }
            if (isEnabledOrderRotation('Stripe')) {
                performProxyOrderRotation($activatedProxy, 'Stripe');
                updateRotationOrder($activatedProxy['id'], 'Stripe');
            }
            csStripeSaveTransactionId($order, $paymentIntent->id);
            updateFeeNetOrderStripe($body->charge, $order);
            // Empty cart
            WC()->cart->empty_cart();
            return cs_stripe_redirect_and_exit($order->get_checkout_order_received_url());
        } else {
            csStripeErrorLog([$response, 'trace_id' => $traceId, 'correlation_id' => $body->correlation_id ?? null, 'proxy_url' => $activatedProxy['url'], 'order_id' => $order->get_id()], 'Stripe confirm payment error');
            $fallbackPaymentIntentId = csStripeGetTransactionId($order);
            if (isset($body->payment_intent) && isset($body->payment_intent->id)) {
                $fallbackPaymentIntentId = $body->payment_intent->id;
            } elseif (isset($body->err) && isset($body->err->payment_intent) && isset($body->err->payment_intent->id)) {
                $fallbackPaymentIntentId = $body->err->payment_intent->id;
            }
            $fallback = csStripeAttemptWebhookFallback($order, $activatedProxy, $fallbackPaymentIntentId, $traceId);
            if (is_array($fallback) && !empty($fallback['handled'])) {
                if ($fallback['type'] === 'success' || $fallback['type'] === 'processing') {
                    return cs_stripe_redirect_and_exit($order->get_checkout_order_received_url());
                }
            }
            // Empty cart
            $order->update_status('failed');
            $err = $body->err ?? (object) ['message' => 'Unknown Stripe confirm error'];
            $paymentIntentId = csStripeGetTransactionId($order);
            if (isset($err->payment_intent)) {
                $paymentIntentId = $err->payment_intent->id;
                csStripeSaveTransactionId($order, $paymentIntentId);
            }
            $order->add_order_note(sprintf(
                __('Stripe charged ERROR by proxy %s, ERROR message: %s, Payment Intent ID: %s', 'wootify'),
                $activatedProxy['url'],
                is_string($err) ? $err : ($err->message ?? 'Unknown Stripe confirm error'),
                $paymentIntentId
            ));
            wc_add_notice('We cannot process your payment right now, please try another payment method.[12]', 'error');
            return cs_stripe_redirect_and_exit(wc_get_checkout_url());
        }
    }
}

function cs_stripe_handle_link_express_post_route() {
    if (!isset($_POST['wootify-stripe-link-create-woo-order'])) {
        return;
    }

    // Defer to stripe-endpoint-gateway when it is active and has nodes configured.
    // Both plugins handle the same POST action; the endpoint gateway takes priority
    // because it manages its own proxy nodes independently of shield-manager's proxies.
    if (function_exists('ep_stripe_handle_link_express_create_woo_order')) {
        $ep_nodes = get_option('EP_ST_NODES', []);
        if (!empty($ep_nodes) && is_array($ep_nodes)) {
            // stripe-endpoint-gateway is active and configured — let it handle this.
            return;
        }
    }

    cs_stripe_handle_link_express_create_woo_order();
    exit();
}

function cs_stripe_first_non_empty($values) {
    foreach ($values as $value) {
        if (is_array($value) || is_object($value)) {
            continue;
        }
        $value = sanitize_text_field(wp_unslash((string) $value));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function cs_stripe_order_shipping_payload(WC_Order $order) {
    $billingName = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    $shippingName = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());

    return [
        'name' => cs_stripe_first_non_empty([$shippingName, $billingName]),
        'phone' => cs_stripe_first_non_empty([
            method_exists($order, 'get_shipping_phone') ? $order->get_shipping_phone() : '',
            $order->get_billing_phone(),
        ]),
        'address' => [
            'city' => cs_stripe_first_non_empty([$order->get_shipping_city(), $order->get_billing_city()]),
            'country' => cs_stripe_first_non_empty([$order->get_shipping_country(), $order->get_billing_country()]),
            'line1' => cs_stripe_first_non_empty([$order->get_shipping_address_1(), $order->get_billing_address_1()]),
            'line2' => cs_stripe_first_non_empty([$order->get_shipping_address_2(), $order->get_billing_address_2()]),
            'postal_code' => cs_stripe_first_non_empty([$order->get_shipping_postcode(), $order->get_billing_postcode()]),
            'state' => cs_stripe_first_non_empty([$order->get_shipping_state(), $order->get_billing_state()]),
        ],
    ];
}

function cs_stripe_order_items_payload(WC_Order $order, WC_WOOTIFY_Gateway_Stripe $gateway) {
    $items = [];
    foreach ($order->get_items() as $it) {
        $product = wc_get_product($it->get_product_id());
        $productName = $product ? $gateway->getProductTitle($product->get_name()) : $it->get_name();
        $qty = max(1, (int) $it->get_quantity());
        $amount = round(((float) $it->get_subtotal()) / $qty, $gateway->get_number_of_decimal_digits());
        $items[] = [
            'name' => $productName,
            'quantity' => $qty,
            'total' => $amount,
        ];
    }
    return $items;
}

function cs_stripe_handle_link_express_create_woo_order() {
    if (!function_exists('WC') || !WC()->cart) {
        wp_send_json_error(['message' => 'Cart is not available.'], 400);
    }

    $confirmationToken = isset($_POST['confirmation_token']) ? sanitize_text_field(wp_unslash((string) $_POST['confirmation_token'])) : '';
    if ($confirmationToken === '') {
        wp_send_json_error(['message' => 'Missing Stripe Link confirmation token.'], 400);
    }

    $_POST['payment_method'] = 'WOOTIFY_stripe';
    $paymentGateways = WC()->payment_gateways->payment_gateways();
    if (empty($paymentGateways['WOOTIFY_stripe']) || !$paymentGateways['WOOTIFY_stripe'] instanceof WC_WOOTIFY_Gateway_Stripe) {
        wp_send_json_error(['message' => 'Stripe gateway is not available.'], 400);
    }
    $gateway = $paymentGateways['WOOTIFY_stripe'];

    $activeProxyId = WC()->session->get('wootify-stripe-proxy-active-id');
    $activatedProxy = findActivatedProxyDataByIdStripe(get_option(OPT_WOOTIFY_STRIPE_PROXIES, []), $activeProxyId);
    if (!$activatedProxy) {
        wp_send_json_error(['message' => 'Stripe shield is not available.'], 400);
    }

    try {
        $checkout = WC()->checkout();
        $postedData = $checkout->get_posted_data();
        $postedData['payment_method'] = 'WOOTIFY_stripe';

        // Force billing address from $_POST to prevent WooCommerce get_posted_data from skipping/sanitizing them
        $billing_fields = [
            'billing_first_name', 'billing_last_name', 'billing_company',
            'billing_address_1', 'billing_address_2', 'billing_city',
            'billing_state', 'billing_postcode', 'billing_country',
            'billing_phone', 'billing_email'
        ];
        foreach ($billing_fields as $field) {
            if (isset($_POST[$field])) {
                $value = wp_unslash($_POST[$field]);
                if ($field === 'billing_email') {
                    $postedData[$field] = sanitize_email($value);
                } else {
                    $postedData[$field] = sanitize_text_field($value);
                }
            }
        }

        // Force shipping address from $_POST if ship_to_different_address is true
        if (!empty($_POST['ship_to_different_address'])) {
            $postedData['ship_to_different_address'] = true;
            $shipping_fields = [
                'shipping_first_name', 'shipping_last_name', 'shipping_company',
                'shipping_address_1', 'shipping_address_2', 'shipping_city',
                'shipping_state', 'shipping_postcode', 'shipping_country', 'shipping_phone'
            ];
            foreach ($shipping_fields as $field) {
                if (isset($_POST[$field])) {
                    $postedData[$field] = sanitize_text_field(wp_unslash($_POST[$field]));
                }
            }
        }

        // Update WC customer session address to ensure proper shipping method calculation and layout display
        if ( isset( WC()->customer ) ) {
            $customer = WC()->customer;
            $billing_fields_for_session = [
                'billing_first_name', 'billing_last_name', 'billing_company',
                'billing_address_1', 'billing_address_2', 'billing_city',
                'billing_state', 'billing_postcode', 'billing_country',
                'billing_phone', 'billing_email'
            ];
            foreach ($billing_fields_for_session as $field) {
                if (isset($postedData[$field])) {
                    $setter = 'set_' . $field;
                    if (method_exists($customer, $setter)) {
                        $customer->$setter($postedData[$field]);
                    }
                }
            }

            $shipping_fields_for_session = [
                'shipping_first_name', 'shipping_last_name', 'shipping_company',
                'shipping_address_1', 'shipping_address_2', 'shipping_city',
                'shipping_state', 'shipping_postcode', 'shipping_country', 'shipping_phone'
            ];
            if (!empty($postedData['ship_to_different_address'])) {
                foreach ($shipping_fields_for_session as $field) {
                    if (isset($postedData[$field])) {
                        $setter = 'set_' . $field;
                        if (method_exists($customer, $setter)) {
                            $customer->$setter($postedData[$field]);
                        }
                    }
                }
            } else {
                foreach ($shipping_fields_for_session as $field) {
                    $billing_field = str_replace('shipping_', 'billing_', $field);
                    if (isset($postedData[$billing_field])) {
                        $setter = 'set_' . $field;
                        if (method_exists($customer, $setter)) {
                            $customer->$setter($postedData[$billing_field]);
                        }
                    }
                }
            }
            $customer->save();

            // Clear shipping session cache and force recalculate cart shipping and totals
            if ( isset( WC()->session ) ) {
                $packages = WC()->cart->get_shipping_packages();
                foreach ( $packages as $package_key => $package ) {
                    $session_key = 'shipping_for_package_' . $package_key;
                    WC()->session->__unset( $session_key );
                }
            }
            WC()->shipping()->reset_shipping();
            WC()->cart->calculate_shipping();
            WC()->cart->calculate_totals();
        }

        // Log POST raw and posted data
        csStripeDebugLog([
            '$_POST' => $_POST,
            '$postedData' => $postedData
        ], 'Stripe Link: Create Woo Order Debug Start');

        $orderId = $checkout->create_order($postedData);
        if (is_wp_error($orderId)) {
            wp_send_json_error(['message' => $orderId->get_error_message()], 400);
        }
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            wp_send_json_error(['message' => 'Unable to create Woo order.'], 500);
        }

        // Manually override/save billing/shipping addresses directly on the $order object to ensure they are persisted
        $order->set_billing_first_name(isset($postedData['billing_first_name']) ? $postedData['billing_first_name'] : '');
        $order->set_billing_last_name(isset($postedData['billing_last_name']) ? $postedData['billing_last_name'] : '');
        $order->set_billing_company(isset($postedData['billing_company']) ? $postedData['billing_company'] : '');
        $order->set_billing_address_1(isset($postedData['billing_address_1']) ? $postedData['billing_address_1'] : '');
        $order->set_billing_address_2(isset($postedData['billing_address_2']) ? $postedData['billing_address_2'] : '');
        $order->set_billing_city(isset($postedData['billing_city']) ? $postedData['billing_city'] : '');
        $order->set_billing_state(isset($postedData['billing_state']) ? $postedData['billing_state'] : '');
        $order->set_billing_postcode(isset($postedData['billing_postcode']) ? $postedData['billing_postcode'] : '');
        $order->set_billing_country(isset($postedData['billing_country']) ? $postedData['billing_country'] : '');
        $order->set_billing_phone(isset($postedData['billing_phone']) ? $postedData['billing_phone'] : '');
        $order->set_billing_email(isset($postedData['billing_email']) ? $postedData['billing_email'] : '');

        if (!empty($postedData['ship_to_different_address'])) {
            $order->set_shipping_first_name(isset($postedData['shipping_first_name']) ? $postedData['shipping_first_name'] : '');
            $order->set_shipping_last_name(isset($postedData['shipping_last_name']) ? $postedData['shipping_last_name'] : '');
            $order->set_shipping_company(isset($postedData['shipping_company']) ? $postedData['shipping_company'] : '');
            $order->set_shipping_address_1(isset($postedData['shipping_address_1']) ? $postedData['shipping_address_1'] : '');
            $order->set_shipping_address_2(isset($postedData['shipping_address_2']) ? $postedData['shipping_address_2'] : '');
            $order->set_shipping_city(isset($postedData['shipping_city']) ? $postedData['shipping_city'] : '');
            $order->set_shipping_state(isset($postedData['shipping_state']) ? $postedData['shipping_state'] : '');
            $order->set_shipping_postcode(isset($postedData['shipping_postcode']) ? $postedData['shipping_postcode'] : '');
            $order->set_shipping_country(isset($postedData['shipping_country']) ? $postedData['shipping_country'] : '');
            $order->set_shipping_phone(isset($postedData['shipping_phone']) ? $postedData['shipping_phone'] : '');
        } else {
            $order->set_shipping_first_name($order->get_billing_first_name());
            $order->set_shipping_last_name($order->get_billing_last_name());
            $order->set_shipping_company($order->get_billing_company());
            $order->set_shipping_address_1($order->get_billing_address_1());
            $order->set_shipping_address_2($order->get_billing_address_2());
            $order->set_shipping_city($order->get_billing_city());
            $order->set_shipping_state($order->get_billing_state());
            $order->set_shipping_postcode($order->get_billing_postcode());
            $order->set_shipping_country($order->get_billing_country());
            $order->set_shipping_phone($order->get_billing_phone());
        }

        // Log created order address fields
        csStripeDebugLog([
            'order_id' => $order->get_id(),
            'billing_first_name' => $order->get_billing_first_name(),
            'billing_last_name' => $order->get_billing_last_name(),
            'billing_address_1' => $order->get_billing_address_1(),
            'billing_address_2' => $order->get_billing_address_2(),
            'billing_city' => $order->get_billing_city(),
            'billing_state' => $order->get_billing_state(),
            'billing_postcode' => $order->get_billing_postcode(),
            'billing_country' => $order->get_billing_country(),
            'shipping_first_name' => $order->get_shipping_first_name(),
            'shipping_last_name' => $order->get_shipping_last_name(),
            'shipping_address_1' => $order->get_shipping_address_1(),
            'shipping_city' => $order->get_shipping_city(),
            'shipping_state' => $order->get_shipping_state(),
            'shipping_postcode' => $order->get_shipping_postcode(),
            'shipping_country' => $order->get_shipping_country(),
        ], 'Stripe Link: Created Order Address Fields');

        $order->set_payment_method($gateway);
        $order->update_meta_data(METAKEY_STRIPE_PROXY_URL, $activatedProxy['url']);
        $order->update_meta_data(METAKEY_STRIPE_PROXY_ID, $activatedProxy['id']);
        $order->update_meta_data('_shield_payment_method', 'stripe');
        $order->update_meta_data('_shield_payment_url', $activatedProxy['url']);
        $order->update_meta_data('_shield_stripe_funding_source', 'link');
        $attemptToken = uniqid('stripe_link_att_');
        $order->update_meta_data('_cs_stripe_attempt_token', $attemptToken);
        $order->add_order_note(sprintf(__('Starting Stripe Link express checkout with proxy %s', 'wootify'), $activatedProxy['url']), 0, false);
        $order->save();

        $paymentStripeIntent = $gateway->get_option('intent');
        $payload = [
            'capture_method' => $paymentStripeIntent === OPT_WOOTIFY_STRIPE_INTENT_AUTHORIZE ? 'manual' : 'automatic',
            'confirmation_token' => $confirmationToken,
            'order_id' => $order->get_id(),
            'shield_id' => $activatedProxy['id'],
            'manager_callback_url' => csStripeDirectWebhookCallbackUrl(),
            'order_invoice' => $gateway->invoice_prefix . $order->get_order_number(),
            'order_items' => cs_stripe_order_items_payload($order, $gateway),
            'statement_descriptor' => $gateway->get_option('statement_descriptor'),
            'amount' => $order->get_total(),
            'customer_zipcode' => $order->get_billing_postcode(),
            'customer_email' => $order->get_billing_email(),
            'currency' => $order->get_currency(),
            'name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'email' => $order->get_billing_email(),
            'shipping' => cs_stripe_order_shipping_payload($order),
        ];

        $createIntentUrl = $activatedProxy['url'] . '?' . http_build_query([
            'wootify-stripe-link-create-intent' => uniqid(),
        ]);
        $idempotencyKey = 'stripe-link-' . $order->get_id() . '-' . md5($confirmationToken);
        $traceId = csStripeGenerateTraceId();
        $payloadJson = wp_json_encode($payload);
        $response = wp_remote_post($createIntentUrl, shield_proxy_signed_request_args($activatedProxy, 'POST', $createIntentUrl, [
            '_shield_gateway' => 'stripe',
            'sslverify' => false,
            'timeout' => 5 * 60,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Shield-Idempotency-Key' => $idempotencyKey,
                'X-Shield-Trace-Id' => $traceId,
            ],
            'body' => $payloadJson,
        ], $payloadJson));

        if (is_wp_error($response)) {
            $order->update_status('failed', 'Stripe Link express create intent request failed.');
            csStripeErrorLog([$response, 'trace_id' => $traceId, 'order_id' => $order->get_id()], 'Stripe Link create-intent request error');
            wp_send_json_error(['message' => 'We cannot process your payment right now, please try another payment method.'], 500);
        }

        $body = json_decode(wp_remote_retrieve_body($response));
        if (empty($body) || ($body->status ?? '') !== 'success' || empty($body->client_secret) || empty($body->payment_intent_id)) {
            $message = isset($body->message) ? (string) $body->message : 'Stripe Link express create intent failed.';
            $order->update_status('failed', $message);
            csStripeErrorLog([$body, 'trace_id' => $traceId, 'order_id' => $order->get_id()], 'Stripe Link create-intent error');
            wp_send_json_error(['message' => 'We cannot process your payment right now, please try another payment method.'], 400);
        }

        csStripeSaveTransactionId($order, $body->payment_intent_id);
        $order->add_order_note(sprintf(__('Stripe Link express Payment Intent created by proxy %s, Payment Intent ID: %s', 'wootify'), $activatedProxy['url'], $body->payment_intent_id), 0, false);
        $order->save();

        wp_send_json_success([
            'order_id' => $order->get_id(),
            'attempt_token' => $attemptToken,
            'payment_intent_id' => $body->payment_intent_id,
            'client_secret' => $body->client_secret,
        ]);
    } catch (Throwable $e) {
        csStripeErrorLog($e->getMessage(), 'Stripe Link express order creation exception');
        wp_send_json_error(['message' => 'We cannot process your payment right now, please try another payment method.'], 500);
    }
}


function WOOTIFY_add_gateway_stripe_init() {

    if (!class_exists('WC_WOOTIFY_Stripe')) :

        class WC_WOOTIFY_Stripe {

            /**
             * @var Singleton The reference the *Singleton* instance of this class
             */
            private static $instance;

            /**
             * Returns the *Singleton* instance of this class.
             *
             * @return Singleton The *Singleton* instance.
             */
            public static function get_instance() {
                if (null === self::$instance) {
                    self::$instance = new self();
                }
                return self::$instance;
            }

            /**
             * Private clone method to prevent cloning of the instance of the
             * *Singleton* instance.
             *
             * @return void
             */
            private function __clone() {
            }

            /**
             * Private unserialize method to prevent unserializing of the *Singleton*
             * instance.
             *
             * @return void
             */
            public function __wakeup() {
            }

            /**
             * Protected constructor to prevent creating a new instance of the
             * *Singleton* via the `new` operator from outside of this class.
             */
            private function __construct() {
                $this->init();
            }

            private function init() {
                add_filter('woocommerce_payment_gateways', array($this, 'add_gateways'));
                if (is_admin()) {
                    add_filter(
                        'plugin_action_links_' . plugin_basename(cs_stripe_get_plugin_file()),
                        array($this, 'add_settings_link')
                    );
                }
                add_filter('woocommerce_available_payment_gateways', array($this, 'check_cs_stripe_payment_gateways'), 10, 1);
                add_filter('woocommerce_order_actions', [$this, 'add_WOOTIFY_stripe_order_actions'], 10, 1);
                add_action('woocommerce_order_action_WOOTIFY_stripe_capture_authorization_order', [$this, 'WOOTIFY_stripe_capture_authorization_order']);
                add_action('woocommerce_order_action_WOOTIFY_stripe_cancel_authorization_order', [$this, 'WOOTIFY_stripe_cancel_authorization_order']);
            }

            public function check_cs_stripe_payment_gateways($gateways) {
                if (!is_checkout() && !defined('WOOCOMMERCE_CHECKOUT')) {
                    return $gateways;
                }

                if (function_exists('cs_stripe_endpoint_gateway_is_usable') && cs_stripe_endpoint_gateway_is_usable()) {
                    unset($gateways['WOOTIFY_stripe']);
                    return $gateways;
                }

                $rotationMethod = get_option(OPT_WOOTIFY_STRIPE_ROTATION_METHOD, WOOTIFY_STRIPE_BY_TIME);
                global $woocommerce;

                if ($rotationMethod == WOOTIFY_STRIPE_BY_AMOUNT) {
                    $carTotal = WC()->cart->get_total(false);
                    if (!hasPayableProxyStripe($carTotal)) {
                        // unset($gateways['WOOTIFY_stripe']);
                    }
                }

                if (empty(get_option(OPT_WOOTIFY_STRIPE_PROXIES, []))) {
                    unset($gateways['WOOTIFY_stripe']);
                }

                return $gateways;
            }

            public function add_WOOTIFY_stripe_order_actions($order_actions) {
                global $theorder;

                if (!is_a($theorder, WC_Order::class)) {
                    return $order_actions;
                }
                if (!$this->should_render_for_action($theorder)) {
                    return $order_actions;
                }

                $order_actions['WOOTIFY_stripe_capture_authorization_order'] = 'Capture authorized Stripe payment';
                $order_actions['WOOTIFY_stripe_cancel_authorization_order'] = 'Void authorized Stripe payment';

                return $order_actions;
            }

            public function should_render_for_action(\WC_Order $order) {
                $status = $order->get_status();
                $not_allowed_statuses = array('refunded', 'cancelled', 'failed');
                return $this->is_authorized($order) &&
                    !in_array($status, $not_allowed_statuses, true);
            }

            public function is_authorized(\WC_Order $wc_order) {
                return $wc_order->get_meta(METAKEY_WOOTIFY_STRIPE_INTENT_AUTHORIZED) === 'true';
            }

            function WOOTIFY_stripe_capture_authorization_order(WC_Order $wc_order) {
                if ($wc_order->get_status() == 'cancelled') {
                    return false;
                }
                if (!$this->is_authorized($wc_order)) {
                    return false;
                }

                $proxyUrl = $wc_order->get_meta(METAKEY_STRIPE_PROXY_URL);

                if (empty($proxyUrl)) {
                    $wc_order->add_order_note("Can't found proxy url!");
                    return false;
                }
                $paymentIntentId = csStripeGetTransactionId($wc_order);
                $params = [];
                $params["payment_intent_id"] = $paymentIntentId;
                $capturePaymentUrl = $proxyUrl . "?wootify-stripe-pe-capture-payment=1&" . http_build_query($params);

                $request = wp_remote_get($capturePaymentUrl, ['sslverify' => false, 'timeout' => 300]);
                if (is_wp_error($request)) {
                    csStripeErrorLog($request, "Capture request error!");
                    $wc_order->add_order_note("Capture request error!");
                    $wc_order->update_status('failed', 'Order capture failed');
                    return false;
                }
                $responseBody = wp_remote_retrieve_body($request);
                $data = json_decode($responseBody);
                if (empty($data)) {
                    csStripeErrorLog($responseBody, "Capture error! Empty response");
                    $wc_order->add_order_note("Capture error! Empty response");
                    $wc_order->update_status('failed', 'Order capture failed');
                    return false;
                }

                if ($data->status !== 'success') {
                    $wc_order->add_order_note($data->message);
                    $wc_order->update_status('failed', 'Order capture failed');
                    return false;
                }
                $paymentIntent = $data->payment_intent;
                csStripeSaveTransactionId($wc_order, $paymentIntent->id);
                updateFeeNetOrderStripe($data->charge, $wc_order);
                $wc_order->add_order_note(sprintf(__('Stripe Capture complete (Payment Intent ID: %s)', 'wootify'), $paymentIntent->id));
                $wc_order->update_meta_data(METAKEY_WOOTIFY_STRIPE_INTENT_AUTHORIZED, 'false');
                $wc_order->save();
                $wc_order->payment_complete();
                return true;
            }

            function WOOTIFY_stripe_cancel_authorization_order(WC_Order $wc_order) {
                if ($wc_order->get_status() == 'cancelled') {
                    return false;
                }
                if (!$this->is_authorized($wc_order)) {
                    return false;
                }

                $proxyUrl = $wc_order->get_meta(METAKEY_STRIPE_PROXY_URL);

                if (empty($proxyUrl)) {
                    $wc_order->add_order_note("Can't found proxy url!");
                    return false;
                }
                $paymentIntentId = csStripeGetTransactionId($wc_order);
                $params = [];
                $params["payment_intent_id"] = $paymentIntentId;
                $cancelPaymentUrl = $proxyUrl . "?wootify-stripe-pe-cancel-payment=1&" . http_build_query($params);

                $request = wp_remote_get($cancelPaymentUrl, ['sslverify' => false, 'timeout' => 300]);
                if (is_wp_error($request)) {
                    csStripeErrorLog($request, "Void order request error!");
                    $wc_order->add_order_note("Void order request error!");
                    $wc_order->update_status('failed', 'Order void failed');
                    return false;
                }
                $responseBody = wp_remote_retrieve_body($request);
                $data = json_decode($responseBody);
                if (empty($data)) {
                    csStripeErrorLog($responseBody, "Void order error! Empty response");
                    $wc_order->add_order_note("Void order error! Empty response");
                    return false;
                }

                if ($data->status !== 'success') {
                    $wc_order->add_order_note($data->message);
                    return false;
                }
                $paymentIntent = $data->payment_intent;
                csStripeSaveTransactionId($wc_order, $paymentIntent->id);
                updateFeeNetOrderStripe($data->charge, $wc_order);
                $wc_order->add_order_note(sprintf(__('Stripe Void complete (Payment Intent ID: %s)', 'wootify'), $paymentIntent->id));
                $wc_order->update_meta_data(METAKEY_WOOTIFY_STRIPE_INTENT_AUTHORIZED, 'false');
                $wc_order->save();
                $wc_order->update_status('cancelled', 'Order Cancelled');
                return true;
            }

            /**
             * Add the gateways to WooCommerce.
             *
             * @since 1.0.0
             * @version 4.0.0
             */
            public function add_gateways($gateways) {
                $gateways[] = 'WC_WOOTIFY_Gateway_Stripe'; // your class name is here
                return $gateways;
            }

            public function add_settings_link($links) {
                $settings = array(
                    'settings' => sprintf(
                        '<a href="%s">%s</a>',
                        admin_url('admin.php?page=wc-settings&tab=checkout&section=WOOTIFY_stripe'),
                        'Settings'
                    )
                );
                return array_merge($settings, $links);
            }
        }

        WC_WOOTIFY_Stripe::get_instance();

        if (!class_exists('WC_Payment_Gateway')) {
            return;
        }

        class WC_WOOTIFY_Gateway_Stripe extends WC_Payment_Gateway {
            /**
             * Whether or not logging is enabled
             *`
             * @var bool
             */
            public static $log_enabled = false;
            public static $WOOTIFY_stripe_is_inited = false;
            private static $link_express_rendered = false;

            /**
             * Logger instance
             *
             * @var WC_Logger
             */
            public static $log = false;

            /**
             * @var string
             */
            public $productTitleSetting = 'last_word';

            /**
             * @var string
             */
            public $invoice_prefix;

            /**
             * Class constructor, more about it in Step 3
             */
            public function __construct() {
                $this->id = 'WOOTIFY_stripe'; // payment gateway plugin ID
                $this->icon = ''; // URL of the icon that will be displayed on checkout page near your gateway name
                $this->has_fields = true; // in case you need a custom credit card form
                $this->method_title = 'CardsShield Gateway Stripe';
                $this->order_button_text = __('Place order', 'woocommerce');
                $this->method_description = 'CardsShield Gateway Stripe'; // will be displayed on the options page
                $this->invoice_prefix = $this->get_option('invoice_prefix');

                // gateways can support subscriptions, refunds, saved payment methods,
                // but in this tutorial we begin with simple payments
                $this->supports = array(
                    'products',
                    'refunds',
                );

                // Load the settings.
                $this->init_form_fields();
                $this->init_settings();

                // Define user set variables.
                $this->enabled = $this->get_option('enabled');
                $this->title = $this->get_option('title');
                $this->description = $this->get_option('description');
                $this->payment_notes = $this->get_option('payment_notes');
                $this->description .= ' ' . $this->payment_notes;
                $this->description = trim($this->description);
                if (!self::$WOOTIFY_stripe_is_inited) {
                    self::$WOOTIFY_stripe_is_inited = true;
                    add_filter('manage_edit-shop_order_columns', [$this, 'add_WOOTIFY_shield_url'], 10, 1);
                    add_filter('manage_shop_order_posts_custom_column', [$this, 'add_WOOTIFY_order_values'], 10, 2);
                    if (get_option('woocommerce_custom_orders_table_enabled') === 'yes') {
                        add_filter('woocommerce_shop_order_list_table_columns', [$this, 'add_WOOTIFY_shield_url'], 10, 1);
                        add_action('woocommerce_shop_order_list_table_custom_column', function ($column, $wc_order) {
                            $this->add_WOOTIFY_order_values($column, $wc_order->get_id());
                        }, 10, 2);
                    }
                }
                add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
                add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
                add_action('woocommerce_before_checkout_form', [$this, 'render_link_express_checkout'], 5);
                add_action('woocommerce_checkout_before_customer_details', [$this, 'render_link_express_checkout'], 5);
                add_action('wp_footer', [$this, 'render_link_express_checkout_fallback'], 20);

                // process admin CardsShield Gateway Stripe
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
                // add_action( 'woocommerce_order_status_on-hold_to_processing', array( $this, 'capture_payment' ) );
                // add_action( 'woocommerce_order_status_on-hold_to_completed', array( $this, 'capture_payment' ) );
                // add_action( 'woocommerce_api_wootify-process-payment', array( $this, 'webhook' ) );

                if (!$this->is_valid_for_use()) {
                    $this->enabled = 'no';
                }
            }

            public function add_WOOTIFY_shield_url($columns) {
                $statusColumnPos = array_search('order_status', array_keys($columns), true);
                $insertPos = false === $statusColumnPos ? count($columns) : $statusColumnPos + 1;

                return array_merge(
                    array_slice($columns, 0, $insertPos),
                    [
                        'WOOTIFY_shield_url' => 'Shield URL',
                    ],
                    array_slice($columns, $insertPos)
                );
            }

            public function add_WOOTIFY_order_values($column, $wc_order_id) {
                $this->add_WOOTIFY_shield_url_value($column, $wc_order_id);
            }

            public function add_WOOTIFY_shield_url_value($column, $wc_order_id) {
                if ('WOOTIFY_shield_url' != $column) {
                    return;
                }
                $wc_order = wc_get_order($wc_order_id);

                if (!is_a($wc_order, \WC_Order::class)) {
                    return;
                }
                if ($wc_order->get_payment_method() === 'WOOTIFY_stripe') {
                    echo '[Stripe] ' . $wc_order->get_meta(METAKEY_STRIPE_PROXY_URL);
                }
            }

            /**
             * Plugin options, we deal with it in Step 3 too
             */
            public function init_form_fields() {
                $this->form_fields = array(
                    'enabled' => array(
                        'title' => 'Enable/Disable',
                        'label' => 'Enable CardsShield Gateway',
                        'type' => 'checkbox',
                        'description' => '',
                        'default' => 'no'
                    ),
                    'title' => array(
                        'title' => 'Title',
                        'type' => 'text',
                        'description' => '--------------------------------------------------------------',
                        'default' => 'Card',
                        'desc_tip' => false,
                    ),
                    'intent' => [
                        'title' => 'Payment Intent',
                        'type' => 'select',
                        'class' => [],
                        'input_class' => ['wc-enhanced-select'],
                        'default' => 'capture',
                        'desc_tip' => true,
                        'description' => 'The intent to either capture payment immediately or authorize a payment for an order after order creation.',
                        'options' => [
                            OPT_WOOTIFY_STRIPE_INTENT_CAPTURE => 'Capture',
                            OPT_WOOTIFY_STRIPE_INTENT_AUTHORIZE => 'Authorize',
                        ],
                    ],
                    'payment_notes' => array(
                        'title' => 'Payment notes',
                        'type' => 'textarea',
                        'description' => __('Payment notes are limited to 100 characters, cannot use the special characters.', 'woocommerce-gateway-stripe'),
                        'default' => '',
                        'desc_tip' => true,
                    ),
                    'statement_descriptor' => array(
                        'title' => 'Statement Descriptor',
                        'type' => 'text',
                        'description' => __('Statement descriptors are limited to 22 characters, cannot use the special characters >, <, ", \, \', *, and must not consist solely of numbers. This will appear on your customer\'s statement in capital letters.', 'woocommerce-gateway-stripe'),
                        'default' => '',
                        'desc_tip' => true,
                        'required' => true,
                    ),
                    'invoice_prefix' => array(
                        'title' => __('Invoice Prefix', 'woocommerce-gateway-stripe-express-checkout'),
                        'type' => 'text',
                        'description' => __('Please enter a prefix for your invoice numbers.', 'woocommerce-gateway-stripe-express-checkout'),
                        'default' => 'WC-',
                        'desc_tip' => true,
                        'required' => true,
                    ),
                    OPT_WOOTIFY_STRIPE_LINK_EXPRESS_ENABLED => array(
                        'title' => 'Enable Stripe Link Express Checkout',
                        'label' => 'Show Pay with Link before the checkout form when Stripe reports Link is available.',
                        'type' => 'checkbox',
                        'description' => 'Only Link is enabled in v1. Apple Pay and Google Pay remain disabled.',
                        'default' => 'no',
                        'desc_tip' => false,
                    ),
                    'config_proxies_button' => [
                        'id' => 'config_proxies_button',
                        'type' => 'config_proxies_button',
                        'title' => __('Config Shields', 'custom_stripe'),
                    ],
                    'card_icons' => array(
                        'type' => 'multiselect',
                        'title' => 'Accepted Payment Methods',
                        'class' => 'wc-enhanced-select',
                        'default' => array('visa', 'mastercard', 'american_express', 'discover', 'diners', 'jcb'),
                        'options' => array(
                            'visa' => 'Visa',
                            'paypal' => 'Paypal',
                            'mastercard' => 'MasterCard',
                            'jcb' => 'JCB',
                            'discover' => 'Discover',
                            'diners' => 'Diners Club',
                            'american_express' => 'American Express',
                        ),
                        'desc_tip' => true,
                        'description' => 'The selected icons will show customers which credit card brands you accept.',
                    ),
                    'custom_card_icon_css' => [
                        'title' => 'Custom Stripe icon css',
                        'type' => 'textarea',
                        'default' => '/*
.wootify-stripe-payment-icon {
    width: 50px;
}
*/',
                        'css' => 'width: 400px; min-height: 110px; resize: both;',
                    ]
                );
            }

            /**
             * Screen button Field
             */
            public function generate_config_proxies_button_html($key, $value) {
?>
                <tr valign="top">
                    <td colspan="2" class="forminp forminp-<?php echo sanitize_title($value['type']) ?>">
                        <a href="<?php echo admin_url('admin.php?page=wootify-gateway-stripe'); ?>" class="button"><?php _e('Config Shields', 'custom_stripe'); ?></a>
                    </td>
                </tr>
                <?php
            }


            /**
             * Check if this gateway is enabled and available in the user's country.
             *
             * @return bool
             */
            public function is_valid_for_use() {
                return in_array(
                    get_woocommerce_currency(),
                    apply_filters(
                        'woocommerce_stripe_supported_currencies',
                        array('AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 'TRY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 'CHF', 'TWD', 'THB', 'GBP', 'RMB', 'RUB', 'INR')
                    ),
                    true
                );
            }

            public function render_link_express_checkout() {
                if (self::$link_express_rendered) {
                    return;
                }

                $html = $this->get_link_express_checkout_html();
                if ($html === '') {
                    return;
                }

                self::$link_express_rendered = true;
                echo $html;
            }

            public function render_link_express_checkout_fallback() {
                if (self::$link_express_rendered) {
                    return;
                }

                $html = $this->get_link_express_checkout_html();
                if ($html === '') {
                    return;
                }

                $encodedHtml = wp_json_encode($html);
                if (!$encodedHtml) {
                    return;
                }
                ?>
                <script>
                    (function () {
                        var html = <?php echo $encodedHtml; ?>;
                        var attempts = 0;

                        function insertStripeLinkExpress() {
                            if (document.getElementById("wootify-stripe-link-express-container")) {
                                return true;
                            }

                            var wrapper = document.createElement("div");
                            wrapper.innerHTML = html;
                            var block = wrapper.firstElementChild;
                            if (!block) {
                                return true;
                            }

                            var target = document.querySelector(".starterkit-checkout__payment, .woocommerce-checkout-payment, #payment");
                            if (target && target.parentNode) {
                                target.parentNode.insertBefore(block, target);
                                return true;
                            }

                            var form = document.querySelector("form.checkout, form.woocommerce-checkout");
                            if (form) {
                                form.insertBefore(block, form.firstChild);
                                return true;
                            }

                            var container = document.querySelector(".woocommerce");
                            if (container) {
                                container.appendChild(block);
                                return true;
                            }

                            return false;
                        }

                        function retryInsert() {
                            if (insertStripeLinkExpress() || attempts >= 20) {
                                return;
                            }
                            attempts += 1;
                            window.setTimeout(retryInsert, 250);
                        }

                        if (document.readyState === "loading") {
                            document.addEventListener("DOMContentLoaded", retryInsert);
                        } else {
                            retryInsert();
                        }
                    })();
                </script>
                <?php
            }

            private function get_link_express_checkout_html() {
                $gateway = $this->get_link_express_gateway();
                if (!$gateway) {
                    return '';
                }
                if (!is_checkout() || isset($_GET['pay_for_order'])) {
                    return '';
                }
                if ($gateway->enabled !== 'yes' || $gateway->get_option(OPT_WOOTIFY_STRIPE_LINK_EXPRESS_ENABLED, 'no') !== 'yes') {
                    return '';
                }
                if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
                    return '';
                }
                // Defer to stripe-endpoint-gateway when it is active and usable.
                // Both plugins use the same iframe ID (#payment-stripe-link-area);
                // rendering two iframes with the same ID breaks JS selectors.
                if (function_exists('cs_stripe_endpoint_gateway_is_usable') && cs_stripe_endpoint_gateway_is_usable()) {
                    return '';
                }

                findAndSetNextProxy();
                $nextProxyUrl = WC()->session->get('wootify-stripe-proxy-active-url');
                $nextProxyId = WC()->session->get('wootify-stripe-proxy-active-id');
                if (!$nextProxyUrl || !$nextProxyId) {
                    return '';
                }

                $amount = $gateway->get_stripe_amount(WC()->cart->get_total(false), get_woocommerce_currency());
                if ($amount <= 0) {
                    return '';
                }
                $shippingAmount = $gateway->get_stripe_amount(WC()->cart->get_shipping_total(), get_woocommerce_currency());
                $params = [
                    'wootify-stripe-link-express-form' => 1,
                    'amount' => $amount,
                    'currency' => get_woocommerce_currency(),
                    // parent_origin removed: iframe learns site2 origin via postMessage
                    // handshake (event.origin is browser-enforced, not spoofable)
                    'shipping_amount' => $shippingAmount,
                    'shipping_label' => __('Shipping', 'woocommerce'),
                ];
                $iframeUrl = $nextProxyUrl . '?' . http_build_query($params);
                ob_start();
                ?>
                <div id="wootify-stripe-link-express-container" class="cs-stripe-link-express" style="display:none">
                    <!-- <div id="stripe-link-express-text">Express Checkout</div> -->
                    <iframe id="payment-stripe-link-area"
                            referrerpolicy="no-referrer"
                            allow="payment *"
                            src="<?= esc_url($iframeUrl) ?>"
                            height="52"
                            frameborder="0"
                            style="width: 100%; border: 0"></iframe>
                    <div id="stripe-link-express-or-text" style="text-align:center">- OR -</div>
                    <div id="WOOTIFY_stripe_link_current_proxy_id" data-value="<?= esc_attr($nextProxyId) ?>" style="display:none"></div>
                    <div id="WOOTIFY_stripe_link_current_proxy_url" data-value="<?= esc_attr($nextProxyUrl) ?>" style="display:none"></div>
                </div>
                <?php
                return ob_get_clean();
            }

            private function get_link_express_gateway() {
                if (!function_exists('WC') || !WC()->payment_gateways()) {
                    return null;
                }

                $gateways = WC()->payment_gateways()->payment_gateways();
                return isset($gateways['WOOTIFY_stripe']) && $gateways['WOOTIFY_stripe'] instanceof WC_WOOTIFY_Gateway_Stripe
                    ? $gateways['WOOTIFY_stripe']
                    : null;
            }

            /**
             * Get_icon function.
             *
             * @return string
             * @version 4.0.0
             * @since 1.0.0
             */
            public function get_icon() {
                $icons = $this->get_option('card_icons');
                $icons_str = '';
                if (is_array($icons)) {
                    foreach ($icons as $icon) {
                        $icons_str = '<img class="wootify-stripe-payment-icon" src="' . plugins_url('/assets/images/icons/' . $icon . '.svg', __FILE__) . '" style="width: 50px;padding-top: 2px; margin-right: 8px"/>' . $icons_str;
                    }
                }
                $icons_str .= '<style type="text/css">' . $this->get_option('custom_card_icon_css') . '</style>';
                return apply_filters('woocommerce_gateway_icon', $icons_str, $this->id);
            }

            /**
             * Load admin scripts.
             *
             * @since 3.3.0
             */
            public function admin_scripts() {
                $screen = get_current_screen();
                $screen_id = $screen ? $screen->id : '';

                if ('woocommerce_page_wc-settings' !== $screen_id) {
                    return;
                }

                $suffix = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';

                // wp_enqueue_script( 'woocommerce_stripe_admin', WC()->plugin_url() . '/includes/gateways/stripe/assets/js/stripe-admin' . $suffix . '.js', array(), WC_VERSION, true );
            }

            /**
             * You will need it if you want your custom credit card form, Step 4 is about it
             */
            public function payment_fields() {
                global $woocommerce;
                // ok, let's display some description before the payment form
                if ($this->description) {
                    // display the description with <p> tags etc.
                    echo wpautop(wp_kses_post($this->description));
                }
                if (class_exists('Shield_Proxy_Failover')) {
                    Shield_Proxy_Failover::ensure_stripe_checkout_proxy();
                }
                $loopCheckShield = 0;
                $hasShield = false;
                while (true) {
                    $nextProxyUrl = WC()->session->get('wootify-stripe-proxy-active-url');
                    $nextProxyId = WC()->session->get('wootify-stripe-proxy-active-id');
                    if (!$nextProxyUrl) {
                        break;
                    }
                    $statusUrl = $nextProxyUrl . '?' . http_build_query([
                        'wootify-stripe-pe-get-account-charge-status' => uniqid(),
                    ]);
                    $traceId = csStripeGenerateTraceId();
                    $response = wp_remote_get($statusUrl, shield_proxy_signed_request_args($nextProxyUrl, 'GET', $statusUrl, [
                        '_shield_gateway' => 'stripe',
                        'sslverify' => false,
                        'timeout' => 5 * 60,
                        'headers' => [
                            'X-Shield-Trace-Id' => $traceId,
                        ],
                    ]));

                    if (is_wp_error($response)) {
                        csStripeErrorLog([$nextProxyUrl, $nextProxyId, $response, 'trace_id' => $traceId], 'API check charge fail!');
                        if (isEnabledAmountRotationStripe()) {
                            performProxyAmountRotationStripe(WC()->cart->get_total(false));
                        } elseif (isEnabledOrderRotation('Stripe')) {
                            performProxyOrderRotation(false, 'Stripe');
                        } else {
                            setNextProxyByTimeRotation();
                        }
                        findAndSetNextProxy();
                    } else {
                        $bodyResponse = wp_remote_retrieve_body($response);
                        $body = json_decode($bodyResponse);
                        if ($body->status === 'deactive') {
                            csStripeErrorLog([$nextProxyUrl, $nextProxyId, $bodyResponse, 'trace_id' => $traceId, 'correlation_id' => $body->correlation_id ?? null], 'Proxy move to unused because check charge status deactive!');
                            stripeMoveToUnusedProxyIds([WC()->session->get('wootify-stripe-proxy-active-id')]);
                            findAndSetNextProxy();
                        } else if ($body->status === 'active') {
                            if (isset($body->health_status) && $body->health_status === 'degraded') {
                                csStripeErrorLog([$nextProxyUrl, $nextProxyId, $bodyResponse, 'trace_id' => $traceId, 'correlation_id' => $body->correlation_id ?? null], 'Proxy health degraded but still usable.');
                            }
                            csStripeDebugLog([
                                'trace_id' => $traceId,
                                'correlation_id' => $body->correlation_id ?? null,
                                'proxy_url' => $nextProxyUrl,
                                'proxy_id' => $nextProxyId,
                                'health_status' => $body->health_status ?? 'healthy',
                                'mode' => $body->mode ?? null,
                            ], 'Stripe proxy health check success');
                            $hasShield = true;
                            break;
                        } else {
                            csStripeErrorLog([$nextProxyUrl, $nextProxyId, $bodyResponse, 'trace_id' => $traceId, 'correlation_id' => $body->correlation_id ?? null], 'account status unknown!');
                            if (isEnabledAmountRotationStripe()) {
                                performProxyAmountRotationStripe(WC()->cart->get_total(false));
                            } elseif (isEnabledOrderRotation('Stripe')) {
                                performProxyOrderRotation(false, 'Stripe');
                            } else {
                                setNextProxyByTimeRotation();
                            }
                            findAndSetNextProxy();
                        }
                    }
                    $loopCheckShield++;
                    if ($loopCheckShield > 100) {
                        csStripeErrorLog('$loopCheckShield over 100 tries');
                        break;
                    }
                }

                if (!$hasShield) {
                ?>
                    <div>We cannot accept any payments right now. Please comeback to try tomorrow or select other
                        payment methods if available [3].
                    </div>
                <?php
                } else {
                    if (isset($_GET['pay_for_order']) && get_query_var('order-pay')) {
                        WOOTIFY_stripe_generate_input_order();
                    }
                    $params = [
                        "wootify-stripe-pe-get-payment-form" => 1,
                        "amount" => WC()->cart->get_total(false) * 100,
                        "currency" => get_woocommerce_currency(),
                    ]
                ?>
                    <input style="display:none;" name="wootify-stripe-payment-method-id" />
                    <iframe id="payment-stripe-area" referrerpolicy="no-referrer" src="<?= $nextProxyUrl . '?' . http_build_query($params) ?>" height="200" frameBorder="0" style="width: 100%"></iframe>
                    <span id="endpoint-stripe-confirm-config" data-confirm-url="<?= esc_attr($nextProxyUrl . '?wootify-stripe-pe-get-payment-confirm-form=1') ?>"></span>
    <?php
                }
                action_stripe_wp_footer();
            }

            /*
                * Custom CSS and JS, in most cases required only when you decided to go with a custom credit card form
                */
            public function payment_scripts() {
                // we need JavaScript to process a token only on cart/checkout pages, right?
                if (!is_cart() && !is_checkout()) {
                    return;
                }

                // if our payment gateway is disabled, we do not have to enqueue JS too
                if ('no' === $this->enabled) {
                    return;
                }
                wp_register_style('WOOTIFY_stripe_styles', plugins_url('assets/css/styles.css', __FILE__), [], OPT_WOOTIFY_STRIPE_VERSION);
                wp_enqueue_style('WOOTIFY_stripe_styles');

                wp_register_script('WOOTIFY_stripe_js', plugins_url('assets/js/checkout_hook.js', __FILE__), array('jquery'), OPT_WOOTIFY_STRIPE_VERSION, true);
                wp_enqueue_script('WOOTIFY_stripe_js');
                wp_localize_script('WOOTIFY_stripe_js', 'ajax_object', [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'cs_add_order_note_nonce' => wp_create_nonce('cs_add_order_note'),
                    'shield_proxy_frame_status_nonce' => wp_create_nonce('shield_proxy_frame_status'),
                ]);
            }

            /*
                * Fields validation, more in Step 5
                */
            public function validate_fields() {
                return true;
            }

            private function getActivateProxyUrl() {
                $activatedProxy = get_option(OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY, null);
                return empty($activatedProxy["url"]) ? null : $activatedProxy["url"] . '/index.php';
            }

            /*
                * We're processing the payments here, everything about it is in Step 5
                */
            public function getProductTitle($productTitle) {
                switch ($this->productTitleSetting) {
                    case 'user_define':
                        return $this->userDefineProductTitle;
                    case 'keep_original':
                        return $productTitle;
                    case 'last_word':
                    default:
                        return strrchr($productTitle, ' ');
                }
            }

            public function get_number_of_decimal_digits() {
                return $this->is_currency_supports_zero_decimal() ? 0 : 2;
            }

            public function is_currency_supports_zero_decimal() {
                return in_array(get_woocommerce_currency(), array('HUF', 'JPY', 'TWD'));
            }

            public function process_payment($order_id) {
                global $woocommerce;
                // we need it to get any order details
                $order = wc_get_order($order_id);
                $paymentStripeIntent = $this->get_option('intent');
                $activeProxyId = WC()->session->get('wootify-stripe-proxy-active-id');
                $activatedProxy = findActivatedProxyDataByIdStripe(get_option(OPT_WOOTIFY_STRIPE_PROXIES, []), $activeProxyId);

                if (!$activatedProxy) {
                    csStripeErrorLog($activeProxyId, "Can't find activated proxy!\n");
                    wc_add_notice('We cannot accept any payments right now. Please comeback to try tomorrow or select other payment methods if available.', 'error');
                    return [
                        'result' => 'failure',
                        'reload' => true
                    ];
                }

                $shippingName = $order->get_shipping_first_name() . " " . $order->get_shipping_last_name();
                $shippingAddress1 = $order->get_shipping_address_1();
                $shippingAddress2 = $order->get_shipping_address_2();
                $shippingCity = $order->get_shipping_city();
                $shippingCountry = $order->get_shipping_country();
                $shippingPostCode = $order->get_shipping_postcode();
                $shippingState = $order->get_shipping_state();

                // Billing
                $billingName = $order->get_billing_first_name() . " " . $order->get_billing_last_name();
                $billingAddress1 = $order->get_billing_address_1();
                $billingAddress2 = $order->get_billing_address_2();
                $billingCity = $order->get_billing_city();
                $billingCountry = $order->get_billing_country();
                $billingPostCode = $order->get_billing_postcode();
                $billingState = $order->get_billing_state();

                $shippingName = (empty($order->get_shipping_first_name()) && empty($order->get_shipping_last_name())) ? $billingName : $shippingName;
                $shippingAddress1 = empty($shippingAddress1) ? $billingAddress1 : $shippingAddress1;
                $shippingAddress2 = empty($shippingAddress2) ? $billingAddress2 : $shippingAddress2;
                $shippingCity = empty($shippingCity) ? $billingCity : $shippingCity;
                $shippingCountry = empty($shippingCountry) ? $billingCountry : $shippingCountry;
                $shippingPostCode = empty($shippingPostCode) ? $billingPostCode : $shippingPostCode;
                $shippingState = empty($shippingState) ? $billingState : $shippingState;


                // Log processing proxyUrl
                $order->add_order_note(sprintf(__('Starting checkout with Stripe proxy %s', 'wootify'), $activatedProxy['url']), 0, false);

                $items = [];

                $order_items = $order->get_items();
                foreach ($order_items as $it) {
                    $product = wc_get_product($it->get_product_id());
                    //$product_name = $product->get_name(); // Get the product name
                    $product_name = $this->getProductTitle($product->get_name());

                    $item_quantity = $it->get_quantity(); // Get the item quantity

                    $amount = round($it['line_subtotal'] / $it['qty'], $this->get_number_of_decimal_digits());

                    $items[] = [
                        "name" => $product_name,
                        "quantity" => $item_quantity,
                        "total" => $amount
                    ];
                }
                $paymentIntentIdRequest = null;
                if (!empty(csStripeGetTransactionId($order))) {
                    $proxyProcessingUrl = $order->get_meta(METAKEY_STRIPE_PROXY_URL);
                    if ($proxyProcessingUrl) {
                        if (WC()->session->get('wootify-stripe-proxy-active-url') == $proxyProcessingUrl) {
                            $paymentIntentIdRequest = csStripeGetTransactionId($order);
                        }
                    } else {
                        $paymentIntentIdRequest = csStripeGetTransactionId($order);
                    }
                }
                $makePaymentUrl = $activatedProxy['url'] . '?' . http_build_query([
                    'wootify-stripe-pe-make-payment' => uniqid(),
                ]);
                $payload = [
                    'capture_method' => $paymentStripeIntent === OPT_WOOTIFY_STRIPE_INTENT_AUTHORIZE ? 'manual' : 'automatic',
                    'payment_intent' => $paymentIntentIdRequest,
                    'payment_method_id' => $_POST['wootify-stripe-payment-method-id'],
                    'order_id' => $order->get_id(),
                    'processor_id' => $activatedProxy['id'],
                    'manager_callback_url' => csStripeDirectWebhookCallbackUrl(),
                    'order_invoice' => $this->invoice_prefix . $order->get_order_number(),
                    'order_items' => $items,
                    'statement_descriptor' => $this->get_option('statement_descriptor'),
                    'amount' => $order->get_total(),
                    'customer_zipcode' => $billingPostCode,
                    'customer_email' => $order->get_billing_email(),
                    'currency' => $order->get_currency(),
                    'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'email' => $order->get_billing_email(),
                    'shipping' => [
                        'name' => $shippingName,
                        'phone' => method_exists($order, 'get_shipping_phone') && $order->get_shipping_phone() ? $order->get_shipping_phone() : $order->get_billing_phone(),
                        'address' => [
                            'city' => $shippingCity,
                            'country' => $shippingCountry,
                            'line1' => $shippingAddress1,
                            'line2' => $shippingAddress2,
                            'postal_code' => $shippingPostCode,
                            'state' => $shippingState,
                        ],
                    ],
                ];
                $idempotencyKey = 'stripe-make-' . $order->get_id() . '-' . md5((string) ($paymentIntentIdRequest ?: ($_POST['wootify-stripe-payment-method-id'] ?? '')));
                $traceId = csStripeGenerateTraceId();
                $payloadJson = wp_json_encode($payload);
                $response = wp_remote_post($makePaymentUrl, shield_proxy_signed_request_args($activatedProxy, 'POST', $makePaymentUrl, [
                    '_shield_gateway' => 'stripe',
                    'sslverify' => false,
                    'timeout' => 5 * 60,
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'X-Shield-Idempotency-Key' => $idempotencyKey,
                        'X-Shield-Trace-Id' => $traceId,
                    ],
                    'body' => $payloadJson,
                ], $payloadJson));
                $order->update_meta_data(METAKEY_STRIPE_PROXY_URL, $activatedProxy['url']);
                $order->update_meta_data(METAKEY_STRIPE_PROXY_ID, $activatedProxy['id']);
                $order->save();
                if (is_wp_error($response)) {
                    csStripeErrorLog([$response, 'trace_id' => $traceId, 'proxy_url' => $activatedProxy['url'], 'order_id' => $order->get_id()], 'Stripe request error');
                    wc_add_notice('We cannot process your payment right now, please try another payment method.', 'error');
                    return false;
                }
                $body = wp_remote_retrieve_body($response);
                $body = json_decode($body);
                if ($body->status === 'success') {
                    $paymentIntent = $body->payment_intent;
                    csStripeDebugLog([
                        'trace_id' => $traceId,
                        'correlation_id' => $body->correlation_id ?? null,
                        'proxy_url' => $activatedProxy['url'],
                        'proxy_id' => $activatedProxy['id'],
                        'order_id' => $order->get_id(),
                        'payment_intent_id' => $paymentIntent->id ?? null,
                        'status' => $body->status,
                    ], 'Stripe make-payment success');
                    if (isset($body->payment_intent)) {
                        $paymentIntentId = $body->payment_intent->id;
                        csStripeSaveTransactionId($order, $paymentIntentId);
                        $order->add_order_note(sprintf(
                            __('Stripe confirm Payment Intent by proxy %s, Payment Intent ID: %s', 'wootify'),
                            $activatedProxy['url'],
                            $paymentIntentId
                        ));
                    }

                    $attemptToken = uniqid('stripe_att_');
                    $order->update_meta_data('_cs_stripe_attempt_token', $attemptToken);
                    $order->save();

                    if (isset($_GET['pay_for_order'])) {
                        echo json_encode([
                            'result' => 'success',
                            'redirect' => sprintf('#cs-confirm-pi-%s:%s:%s', $paymentIntent->client_secret, $order_id, $attemptToken),
                        ]);
                        exit();
                    }
                    return [
                        'result' => 'success',
                        'redirect' => sprintf('#cs-confirm-pi-%s:%s:%s', $paymentIntent->client_secret, $order_id, $attemptToken),
                    ];
                } else {
                    csStripeErrorLog([$response, 'trace_id' => $traceId, 'correlation_id' => $body->correlation_id ?? null, 'proxy_url' => $activatedProxy['url'], 'order_id' => $order->get_id()], 'Stripe request payment error');
                    // Handle duplicate_request BEFORE setting failed to prevent unwanted
                    // side-effect hooks (email, stock restore) from firing on a race condition.
                    if ($body->code === 'duplicate_request') {
                        // Lock is protecting against double charge — do NOT mark order failed.
                        // If a PI already exists, request 1 may have succeeded or be in-flight.
                        $existingPiId = csStripeGetTransactionId($order);
                        if ($existingPiId) {
                            $order->add_order_note(sprintf(
                                __('Stripe idempotency lock active (duplicate request blocked by proxy %s). Order held pending. PI: %s', 'wootify'),
                                $activatedProxy['url'],
                                $existingPiId
                            ));
                            $order->save();
                            wc_add_notice(__('Your payment is already being processed. Please wait a moment or check your order status.', 'wootify'), 'notice');
                            return [
                                'result'   => 'success',
                                'redirect' => $order->get_checkout_order_received_url(),
                            ];
                        }
                        // No PI yet — first request may still be in-flight or failed before PI creation
                        csStripeErrorLog([
                            'order_id'  => $order->get_id(),
                            'trace_id'  => $traceId,
                            'proxy_url' => $activatedProxy['url'],
                        ], 'Stripe duplicate_request — no PI found, treating as transient lock error');
                        $order->save();
                        wc_add_notice(__('Your payment is being processed. Please wait a moment and check your order status before trying again.', 'wootify'), 'error');
                        return false;
                    }

                    // For all other error codes: mark order failed now
                    $order->update_status('failed');

                    if ($body->code === 'domain_whitelist_not_allow') {
                        $order->add_order_note(sprintf(
                            __('Stripe charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                            $activatedProxy['url'],
                            'Domain whitelist is required'
                        ));
                    } else if ($body->code === 'customer_zipcode_not_allow') {
                        $order->add_order_note(sprintf(
                            __('Stripe charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                            $activatedProxy['url'],
                            "Customer's zipcode is blacklisted"
                        ));
                        wc_add_notice('The selected payment method is suspended, Please contact merchant for more information.', 'error');
                        return false;
                    } else if ($body->code === 'customer_email_not_allow') {
                        $order->add_order_note(sprintf(
                            __('Stripe charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                            $activatedProxy['url'],
                            "Customer's email is blacklisted"
                        ));
                        wc_add_notice('The selected payment method is suspended, Please contact merchant for more information.', 'error');
                        return false;
                    } else if ($body->code === 'states_cities_not_allow') {
                        $order->add_order_note(sprintf(
                            __('Stripe charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                            $activatedProxy['url'],
                            "Customer's State and City is blacklisted"
                        ));
                        wc_add_notice('Sorry, Your selected products are not available to purchase due to our policy violation.', 'error');
                        return false;
                    } else if ($body->code === 'order_total_not_allow') {
                        $order->add_order_note(sprintf(
                            __('Stripe charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                            $activatedProxy['url'],
                            "Order value exceeds Stripe capability"
                        ));
                        wc_add_notice('The selected payment method is suspended, Please contact merchant for more information.', 'error');
                        return false;
                    } else {
                        $err = $body->err;
                        $paymentIntentId = csStripeGetTransactionId($order);
                        if (isset($err->payment_intent)) {
                            $paymentIntentId = $err->payment_intent->id;
                            csStripeSaveTransactionId($order, $paymentIntentId);
                        }
                        $order->add_order_note(sprintf(
                            __('Stripe charged ERROR by proxy %s, ERROR message: %s, Payment Intent ID: %s', 'wootify'),
                            $activatedProxy['url'],
                            is_string($err) ? $err : ($err->message ?? 'Unknown error'),
                            $paymentIntentId
                        ));
                    }
                    wc_add_notice('We cannot process your payment right now, please try another payment method.[2]', 'error');
                    return false;
                }
            }

            /**
             * Process refund.
             *
             * @param int $order_id Order ID
             * @param float $amount Order amount
             * @param string $reason Refund reason
             *
             * @return boolean True or false based on success, or a WP_Error object.
             */
            public function process_refund($order_id, $amount = null, $reason = '') {
                $order = wc_get_order($order_id);
                if (0 == $amount || null == $amount) {
                    return new WP_Error('stripe_refund_error', __('Refund Error: You need to specify a refund amount.', 'wootify-stripe-gateway'));
                }

                try {
                    $result = $this->refund_order($order, $order_id, $amount, "", $reason);
                    $charge = $result['charge_obj'];
                    $order->add_order_note(sprintf(__('Stripe refund completed; transaction ID = %s', 'wootify-stripe-gateway'), csStripeGetTransactionId($order)));
                    updateFeeNetOrderStripe($charge, $order);
                    return true;
                } catch (Exception $e) {
                    csStripeErrorLog($e->getMessage(), 'Stripe process_refund error');
                    return new WP_Error('stripe_refund_error', $e->getMessage());
                }
            }

            private function refund_order($order, $order_id, $amount, $refundType, $reason) {
                $proxyUrl = $order->get_meta(MetaKey_Stripe_Proxy_Url);

                // do API call
                $url = $proxyUrl . "?" . http_build_query([
                    'wootify-stripe-pe-refund' => uniqid(),
                ]);
                $payload = [
                    'order_id' => $order_id,
                    'transaction_id' => csStripeGetTransactionId($order),
                    'amount' => $this->get_stripe_amount($amount, $order->get_currency()),
                    'reason' => $reason,
                    'currency' => $order->get_currency(),
                ];
                $traceId = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('stripe-refund-', true);
                $payloadJson = wp_json_encode($payload);

                $request = wp_remote_post($url, shield_proxy_signed_request_args($proxyUrl, 'POST', $url, [
                    '_shield_gateway' => 'stripe',
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'X-Shield-Trace-Id' => $traceId,
                    ],
                    'body' => $payloadJson,
                ], $payloadJson));

                $notice = 'There is an error when process this payment, please contact us for more support or you can try to use Stripe!';
                if (is_wp_error($request)) {
                    wc_add_notice($notice, 'error');
                    $order->add_order_note(sprintf(__('Failed refund by Stripe! Debug proxy %s', 'wootify-stripe-gateway'), $url));
                    throw new Exception($notice);
                }

                $body = wp_remote_retrieve_body($request);
                $result = json_decode($body);

                if (isset($result->success) && $result->success && isset($result->refund_obj) && isset($result->refund_obj->status) && $result->refund_obj->status == "succeeded") {
                    return [
                        'refund_obj' => $result->refund_obj,
                        'charge_obj' => $result->charge_obj,
                    ];
                } else {
                    $order->add_order_note(sprintf(__('Failed refund by Stripe! Debug proxy %s', 'wootify-stripe-gateway'), $url));
                    throw new Exception($notice);
                }
            }

            /**
             * Get Stripe amount to pay
             *
             * @param float $total Amount due.
             * @param string $currency Accepted currency.
             *
             * @return float|int
             */
            public function get_stripe_amount($total, $currency = '') {
                if (!$currency) {
                    $currency = get_woocommerce_currency();
                }

                if (in_array(strtolower($currency), $this->no_decimal_currencies())) {
                    return absint($total);
                } else {
                    return absint(wc_format_decimal(((float)$total * 100), wc_get_price_decimals())); // In cents.
                }
            }

            /**
             * List of currencies supported by Stripe that has no decimals.
             *
             * @return array $currencies
             */
            public function no_decimal_currencies() {
                return array(
                    'bif', // Burundian Franc
                    'djf', // Djiboutian Franc
                    'jpy', // Japanese Yen
                    'krw', // South Korean Won
                    'pyg', // Paraguayan Guaraní
                    'vnd', // Vietnamese Đồng
                    'xaf', // Central African Cfa Franc
                    'xpf', // Cfp Franc
                    'clp', // Chilean Peso
                    'gnf', // Guinean Franc
                    'kmf', // Comorian Franc
                    'mga', // Malagasy Ariary
                    'rwf', // Rwandan Franc
                    'vuv', // Vanuatu Vatu
                    'xof', // West African Cfa Franc
                );
            }

            /*
            * In case you need a webhook, like Stripe IPN etc
            */
            public function webhook() {
                // $order = wc_get_order( $_GET['id'] );
                // $order->payment_complete();
                // $order->reduce_order_stock();

                // update_option('webhook_debug', $_GET);
            }
        }
    endif;
}

register_deactivation_hook(cs_stripe_get_plugin_file(), 'cs_stripe_plugin_deactivation');

function cs_stripe_plugin_deactivation() {
    wp_clear_scheduled_hook('WOOTIFY_gateway_stripe_daily');
    wp_clear_scheduled_hook('WOOTIFY_gateway_stripe_rotation');
}

function updateFeeNetOrderStripe($charge, $order) {
    if (isset($charge->balance_transaction) && is_object($charge->balance_transaction)) {
        $display_order_currency = WOOTIFY_STRIPE_FEE_DISPLAY_ORDER_CURRENCY;
        $balance_transaction = $charge->balance_transaction;
        $exchange_rate = $balance_transaction->exchange_rate === null ? 1 : $balance_transaction->exchange_rate;
        $amount_refunded = $display_order_currency ? $charge->amount_refunded : $charge->amount_refunded * $exchange_rate;
        $net = $display_order_currency ? $balance_transaction->net / $exchange_rate : $balance_transaction->net;
        $net = $net - $amount_refunded;
        $fee = $display_order_currency ? $balance_transaction->fee / $exchange_rate : $balance_transaction->fee;
        $currency = $display_order_currency ? $order->get_currency() : strtoupper($balance_transaction->currency);
        $payment_balance = [];
        $payment_balance['currency'] = $currency;
        $payment_balance['fee'] = $fee;
        $payment_balance['net'] = $net;
        if (count($charge->refunds->data) > 0) {
            foreach ($charge->refunds->data as $refund) {
                if (is_object($refund->balance_transaction)) {
                    $balance_transaction = $refund->balance_transaction;
                    $exchange_rate = $balance_transaction->exchange_rate === null ? 1 : $balance_transaction->exchange_rate;
                    $fee = $display_order_currency ? $balance_transaction->fee / $exchange_rate : $balance_transaction->fee;
                    $payment_balance['net'] = $payment_balance['net'] - $fee;
                    $payment_balance['fee'] = $payment_balance['fee'] + $fee;
                }
            }
        }
        $payment_balance['fee'] = wc_format_decimal($payment_balance['fee'] / 100, 4);
        $payment_balance['net'] = wc_format_decimal($payment_balance['net'] / 100, 4);
        $order->update_meta_data(METAKEY_CS_STRIPE_FEE, $payment_balance['fee']);
        $order->update_meta_data(METAKEY_CS_STRIPE_PAYOUT, $payment_balance['net']);
        $order->update_meta_data(METAKEY_CS_STRIPE_CURRENCY, $payment_balance['currency']);
        $order->save();
    }
}

//add_action('wp_footer', 'action_stripe_wp_footer', 10, 1);
function action_stripe_wp_footer() {
    if (is_checkout()) {
        $gateways = WC()->payment_gateways->get_available_payment_gateways();
        if (isset($gateways['WOOTIFY_stripe']->enabled) && $gateways['WOOTIFY_stripe']->enabled == 'yes') {
            echo '<div id="cs-stripe-loader">
                  <div class="cs-stripe-spinnerWithLockIcon cs-stripe-spinner" aria-busy="true">
                      <p>We\'re processing your payment...<br/>Please <b>DO NOT</b> close this page!</p>
                  </div>
            </div>';
        }
    }
}

add_action('wp_head', 'action_stripe_wp_head', 10, 1);

function action_stripe_wp_head() {
    if (is_checkout()) {
        if (cs_stripe_endpoint_gateway_is_usable()) {
            return;
        }

        $gateways = WC()->payment_gateways->get_available_payment_gateways();
        if (isset($gateways['WOOTIFY_stripe']->enabled) && $gateways['WOOTIFY_stripe']->enabled == 'yes') {
            // Preconnect Stripe CDN/API to warm up DNS + TCP + TLS
            echo '<link rel="dns-prefetch" href="https://js.stripe.com">';
            echo '<link rel="preconnect" href="https://js.stripe.com" crossorigin>';
            echo '<link rel="preconnect" href="https://api.stripe.com" crossorigin>';

            findAndSetNextProxy();
            $proxyUrl = WC()->session->get('wootify-stripe-proxy-active-url');
            if (!empty($proxyUrl)) {
                $currency = get_woocommerce_currency();
                $cart     = WC()->cart;

                // Preload Credit Card iframe — URL khớp chính xác với iframe src trong payment_fields()
                $ccParams = [
                    'wootify-stripe-pe-get-payment-form' => 1,
                    'amount'   => $cart->get_total(false) * 100,
                    'currency' => $currency,
                ];
                echo '<link rel="preload" href="' . esc_url(add_query_arg($ccParams, $proxyUrl)) . '" as="document">';

                // Preload Stripe Link Express iframe nếu tính năng được bật
                $gateway = $gateways['WOOTIFY_stripe'];
                if (
                    !isset($_GET['pay_for_order']) &&
                    !$cart->is_empty() &&
                    $gateway->get_option(OPT_WOOTIFY_STRIPE_LINK_EXPRESS_ENABLED, 'no') === 'yes'
                ) {
                    $linkAmount   = $gateway->get_stripe_amount($cart->get_total(false), $currency);
                    $shippingAmt  = $gateway->get_stripe_amount($cart->get_shipping_total(), $currency);
                    $linkParams   = [
                        'wootify-stripe-link-express-form' => 1,
                        'amount'          => $linkAmount,
                        'currency'        => $currency,
                        'shipping_amount' => $shippingAmt,
                        'shipping_label'  => __('Shipping', 'woocommerce'),
                    ];
                    echo '<link rel="preload" href="' . esc_url(add_query_arg($linkParams, $proxyUrl)) . '" as="document">';
                }
            }
        }
    }
}

add_action('wc_ajax_cs_add_order_note', 'cs_add_order_note', 10, 1);

function findAndSetNextProxy() {
    if (isset($_GET['pay_for_order']) && get_query_var('order-pay')) {
        $orderIdProcessing = get_query_var('order-pay');
    } else {
        $orderIdProcessing = WC()->session->get('order_awaiting_payment');
    }
    if (!empty($orderIdProcessing)) {
        $orderProcessing = wc_get_order($orderIdProcessing);
        if ($orderProcessing instanceof WC_Order) {
            $proxyProcessingId = $orderProcessing->get_meta(METAKEY_STRIPE_PROXY_ID);
            $proxyProcessing = findActivatedProxyDataByIdStripe(get_option(OPT_WOOTIFY_STRIPE_PROXIES, []), $proxyProcessingId);
            if ($proxyProcessing) {
                csStripeDebugLog($proxyProcessing, '$proxyProcessing');
                WC()->session->set('wootify-stripe-proxy-active-id', $proxyProcessing['id']);
                WC()->session->set('wootify-stripe-proxy-active-url', $proxyProcessing['url']);
                return;
            }
        }
    }
    if (isEnabledAmountRotationStripe()) {
        $nextProxy = getNextProxyAmountRotationStripe(WC()->cart->get_total(false));
    } elseif (isEnabledOrderRotation('Stripe')) {
        $nextProxy = getNextProxyOrderRotation(false, 'Stripe');
    } else {
        $nextProxy = get_option(OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY, null);
    }
    if (empty($nextProxy)) {
        WC()->session->set('wootify-stripe-proxy-active-id', null);
        WC()->session->set('wootify-stripe-proxy-active-url', null);
        return;
    }
    WC()->session->set('wootify-stripe-proxy-active-id', $nextProxy['id']);
    WC()->session->set('wootify-stripe-proxy-active-url', $nextProxy['url']);
}

function cs_add_order_note() {
    if (
        !wp_verify_nonce($_POST['security'], 'cs_add_order_note') ||
        empty($_POST['order_id']) || empty($_POST['note'])
    ) {
        die('Wrong nonce!');
    }
    $order = wc_get_order($_POST['order_id']);
    $order->add_order_note($_POST['note']);
    $order->update_status('failed');
    //    $order->save();
    echo json_encode([
        'success' => true
    ]);
    wp_die();
}

function WOOTIFY_stripe_generate_input_order() {
    $order = wc_get_order(get_query_var('order-pay'));
    $billingFirstName = $order->get_billing_first_name();
    $billingLastName = $order->get_billing_last_name();
    $billingAddress1 = $order->get_billing_address_1();
    $billingAddress2 = $order->get_billing_address_2();
    $billingCity = $order->get_billing_city();
    $billingCountry = $order->get_billing_country();
    $billingPostCode = $order->get_billing_postcode();
    $billingState = $order->get_billing_state();
    ?>
    <input id="billing_first_name" value="<?= $billingFirstName ?>" style="display: none" />
    <input id="billing_last_name" value="<?= $billingLastName ?>" style="display: none" />
    <input id="billing_address_1" value="<?= $billingAddress1 ?>" style="display: none" />
    <input id="billing_address_2" value="<?= $billingAddress2 ?>" style="display: none" />
    <input id="billing_city" value="<?= $billingCity ?>" style="display: none" />
    <input id="billing_country" value="<?= $billingCountry ?>" style="display: none" />
    <input id="billing_postcode" value="<?= $billingPostCode ?>" style="display: none" />
    <input id="billing_state" value="<?= $billingState ?>" style="display: none" />
    <div id="WOOTIFY_stripe_pay_for_order_page"></div>
<?php
}
