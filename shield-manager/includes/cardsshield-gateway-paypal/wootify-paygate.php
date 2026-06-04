<?php

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}
if (!defined('ABSPATH')) {
    exit;
}

require_once('class-wc-gateway-ppec-api-exception.php');
require_once(plugin_dir_path(__FILE__) . 'utils.php');

if (!function_exists('cs_paypal_get_plugin_file')) {
    function cs_paypal_get_plugin_file() {
        return defined('SHIELD_MANAGER_PLUGIN_FILE') ? SHIELD_MANAGER_PLUGIN_FILE : __FILE__;
    }
}


//Cron
add_filter('cron_schedules', 'WOOTIFY_add_cron_interval');

function WOOTIFY_add_cron_interval($schedules) {
    $schedules['one_minute'] = array(
        'interval' => 60,
        'display' => esc_html__('Every minute'),
    );

    return $schedules;
}

if (!wp_next_scheduled('WOOTIFY_gateway_paypal_rotation')) {
    wp_schedule_event(time(), 'one_minute', 'WOOTIFY_gateway_paypal_rotation');
}

// add an action hook for expiration check and notification check
add_action('WOOTIFY_gateway_paypal_rotation', 'WOOTIFY_paypal_rotation_checker');

if (!wp_next_scheduled('WOOTIFY_gateway_paypal_daily')) {
    wp_schedule_event(strtotime('23:59:59 ' . get_option('timezone_string')), 'daily', 'WOOTIFY_gateway_paypal_daily');
}
add_action('WOOTIFY_gateway_paypal_daily', 'WOOTIFY_gateway_paypal_daily_process');

$wootifyPpSettings = get_option('woocommerce_wootify_paypal_settings', []);
if (empty($wootifyPpSettings)) {
    $wootifyPpSettings = get_option('woocommerce_paypal_settings', []);
}
if (isset($wootifyPpSettings['sync_tracking_automatic']) && $wootifyPpSettings['sync_tracking_automatic'] === 'yes' && !wp_next_scheduled('WOOTIFY_gateway_paypal_cron_auto_sync')) {
    wp_schedule_event(strtotime('7:00:00'), 'daily', 'WOOTIFY_gateway_paypal_cron_auto_sync');
    wp_schedule_event(strtotime('8:00:00'), 'daily', 'WOOTIFY_gateway_paypal_cron_auto_sync');
    wp_schedule_event(strtotime('9:00:00'), 'daily', 'WOOTIFY_gateway_paypal_cron_auto_sync');
}
add_action('WOOTIFY_gateway_paypal_cron_auto_sync', 'WOOTIFY_gateway_paypal_cron_auto_sync_process');
function WOOTIFY_gateway_paypal_daily_process() {
    // Reset paid amount
    $rotationMethod = get_option(OPT_WOOTIFY_PAYPAL_ROTATION_METHOD, OPT_CS_PAYPAL_BY_TIME);
    if ($rotationMethod === OPT_CS_PAYPAL_BY_AMOUNT) {
        resetPaidAmount();
    }
}
function WOOTIFY_gateway_paypal_cron_auto_sync_process() {
    syncTrackingInfo();
}

add_filter('woocommerce_payment_gateways', 'WOOTIFY_add_gateway_class');
function WOOTIFY_add_gateway_class($gateways) {
    $gateways[] = 'WC_WOOTIFY_Gateway'; // your class name is here
    return $gateways;
}

add_action('get_header', 'handleReturn');
add_action('wp', 'ensure_session'); // Ensure there is a customer session so that nonce is not invalidated by new session created on AJAX POST request.

function WOOTIFY_paypal_rotation_checker() {

    $rotationMethod = get_option(OPT_WOOTIFY_PAYPAL_ROTATION_METHOD, OPT_CS_PAYPAL_BY_TIME);
    if ($rotationMethod != OPT_CS_PAYPAL_BY_TIME) {
        return;
    }
    // Auto Switching Proxy
    $proxies = get_option(OPT_WOOTIFY_PAYPAL_PROXIES, []);
    if (empty($proxies)) {
        return;
    }
    $activatedProxy = get_option(OPT_WOOTIFY_PAYPAL_ACTIVATED_PROXY, null);
    // if only has 1 proxy, don't rotate
    if (count($proxies) === 1 && isset($activatedProxy['id']) && $activatedProxy['id'] === $proxies[0]['id']) {
        return;
    }
    $lastActivatedTimestamp = get_option(OPT_WOOTIFY_PAYPAL_CURRENT_ROTATION_VALUE, 0);

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
                    update_option(OPT_WOOTIFY_PAYPAL_ACTIVATED_PROXY, $needToActivateProxy, true);
                    update_option(OPT_WOOTIFY_PAYPAL_CURRENT_ROTATION_VALUE, time(), true);
                    logRotation(OPT_CS_PAYPAL_BY_TIME, $needToActivateProxy, "Auto");
                    $hasDeletedActivateProxy = false;
                    break;
                }
            }
        }
    }

    if (empty($activatedProxy) || $hasDeletedActivateProxy) {
        update_option(OPT_WOOTIFY_PAYPAL_ACTIVATED_PROXY, $proxies[0], true);
        update_option(OPT_WOOTIFY_PAYPAL_CURRENT_ROTATION_VALUE, time(), true);
        logRotation(OPT_CS_PAYPAL_BY_TIME, $proxies[0], "Auto");
    }
}

/**
 * Creates a customer session if one is not already active.
 */
function ensure_session() {
    $frontend = (!is_admin() || defined('DOING_AJAX')) && !defined('DOING_CRON') && !defined('REST_REQUEST');

    if (!$frontend) {
        return;
    }

    if (!WC()->session->has_session()) {
        WC()->session->set_customer_session_cookie(true);
    }
}

function handleReturn() {
    global $woocommerce;
    if (isset($_GET["woo-wootify-return"]) && !empty($_GET['order_id'])) {

        $isError = $_GET['error'] == 1;
        $isCancel = $_GET['cancel'] == 1;
        $order_id = $_GET['order_id'];
        $order = wc_get_order($order_id);
        $proxyUrl = $order->get_meta(METAKEY_PAYPAL_PROXY_URL);
        $proxyId = $order->get_meta(METAKEY_PAYPAL_PROXY_ID);
        $order->add_order_note(sprintf(
            __('Paypal process info at proxy %s, message: %s', 'wootify'),
            $proxyUrl,
            'Start handle Paypal checkout result'
        ));
        if ($isError) {
            wc_add_notice(__('Your PayPal checkout session has expired. Please check out again.[1]', 'wootify'), 'error');
            $order->update_status('failed');
            $order->add_order_note(sprintf(
                __('Paypal charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                $proxyUrl,
                isset($_GET['err_msg']) ? $_GET['err_msg'] : 'Unknown error'
            ));
            redirectToCheckoutPage();
        }
        if (!$isCancel) {
            $payment_id = $_GET["paymentId"];
            $token = $_GET['token'];
            $payer_id = $_GET['PayerID'];
            $create_billing_agreement = !empty($_GET['create-billing-agreement']);
            $paymentIntent = $order->get_meta(METAKEY_CS_PAYPAL_INTENT);

            // Ask the proxy to capture this payment
            $payer_data = [];
            $payer_data["payment_id"] = $payment_id;
            $payer_data["payer_id"] = $payer_id;
            $payer_data["create_billing_agreement"] = $create_billing_agreement;
            $payer_data["order_id"] = $order_id;
            $purchaseUnitsFromWooOrder = get_purchase_unit_from_order($order);
            $payer_data["purchase_units"] = $purchaseUnitsFromWooOrder;
            $method = $paymentIntent == OPT_CS_PAYPAL_AUTHORIZE ? 'wootify-pp-authorize-payment' : 'wootify-pp-capture-payment';
            $proxyCapturePaymentAPI = $proxyUrl . "?$method=1&" . http_build_query($payer_data);

            $request = wp_remote_post($proxyCapturePaymentAPI, [
                'sslverify' => false,
                'timeout' => 300,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'cs_order_detail' => getCsPaypalOrderDetailFromWcOrder($order),
                ])
            ]);
            if (is_wp_error($request)) {
                csPaypalErrorLog($request, "$method error");
                wc_add_notice(__('Your PayPal checkout session has expired. Please check out again.[3]', 'wootify'), 'error');
                $order->add_order_note(sprintf(
                    __('Paypal charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                    $proxyUrl,
                    'Paypal checkout capture API Error'
                ));
                $order->update_status('failed');
                redirectToCheckoutPage();
            }
            $responseBody = wp_remote_retrieve_body($request);
            $data = json_decode($responseBody);
            if (empty($data)) {
                csPaypalErrorLog($responseBody, "$method empty response!");
                wc_add_notice(__('Your PayPal checkout session has expired. Please check out again.[4]', 'wootify'), 'error');
                $order->update_status('failed');
                $order->add_order_note(sprintf(
                    __('Paypal charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                    $proxyUrl,
                    'Paypal checkout capture API response empty'
                ));
                redirectToCheckoutPage();
            }

            if (!$data->success) {
                wc_add_notice(__($data->message, 'wootify'), 'error');
                if ($order->has_status('pending')) {
                    $order->add_order_note(sprintf(
                        __('Paypal charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                        $proxyUrl,
                        $data->message
                    ));
                    $order->update_status('failed');
                }
                redirectToCheckoutPage();
            }
            $transaction_id = $data->transaction_id;

            if ($paymentIntent == OPT_CS_PAYPAL_AUTHORIZE) {
                $order->add_order_note(sprintf(__('PayPal authorized by proxy %s, ID: %s', 'wootify'), $proxyUrl, $transaction_id), 0, false);

                $order->update_status('on-hold', 'Payment can be captured.');

                $order->update_meta_data(METAKEY_CS_PAYPAL_CAPTURED, 'false');
                $order->save_meta_data();
            } else {
                //Save the processed proxy for this order (using for refund later)
                $order->add_order_note(sprintf(__('PayPal charged by proxy %s', 'wootify'), $proxyUrl), 0, false);
                // some notes to customer (replace true with false to make it private)
                $order->add_order_note(sprintf(__('PayPal Checkout charge complete (Charge ID: %s)', 'wootify'), $transaction_id));

                $sellerPayableBreakdown = $data->seller_receivable_breakdown;
                $paypalFee              = $sellerPayableBreakdown->paypal_fee->value;
                $paypalCurrency         = $sellerPayableBreakdown->paypal_fee->currency_code;
                $paypalPayout           = $sellerPayableBreakdown->net_amount->value;

                $order->update_meta_data(METAKEY_CS_PAYPAL_FEE, $paypalFee);
                $order->update_meta_data(METAKEY_CS_PAYPAL_PAYOUT, $paypalPayout);
                $order->update_meta_data(METAKEY_CS_PAYPAL_CURRENCY, $paypalCurrency);

                // we received the payment
                $order->payment_complete();
            }

            $order->reduce_order_stock();

            if (isEnabledAmountRotation()) {
                updateRotationAmount($proxyId, $order->get_total());
                $activatedProxy = get_option(OPT_WOOTIFY_PAYPAL_ACTIVATED_PROXY, null);
                if ($proxyId == $activatedProxy['id']) {
                    $activatedProxy = getNextProxy($activatedProxy['id']);
                    update_option(OPT_WOOTIFY_PAYPAL_ACTIVATED_PROXY, $activatedProxy, true);
                    logRotation(OPT_CS_PAYPAL_BY_AMOUNT, $activatedProxy, "Auto");
                }
            }

            csPaypalSaveTransactionId($order, wc_clean($transaction_id));
            $order->update_meta_data(METAKEY_PAYPAL_SYNC_TRACKING_INFO, OPT_CS_PAYPAL_NOT_SYNCED);
            $order->save_meta_data();
            // Empty cart
            $woocommerce->cart->empty_cart();
            // Redirect to the thank you page
            wp_redirect($order->get_checkout_order_received_url());
            exit();
        } else {
            $order->add_order_note(sprintf(
                __('Paypal process info at proxy %s, message: %s', 'wootify'),
                $proxyUrl,
                'Customer canceled and returned to merchant'
            ));
            redirectToCheckoutPage();
        }
    }

    if (isset($_GET['wootify-return-paypal-whitelist-error']) && isset($_GET['err_type']) &&  isset($_GET['order_id'])) {
        $order = wc_get_order($_GET['order_id']);
        $order->update_status('failed');
        switch ($_GET['err_type']) {
            case 'domain_whitelist_not_allow':
                $order->add_order_note(sprintf(
                    __('Paypal charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                    $_GET['proxy_site'],
                    'Domain whitelist is required'
                ));
                wc_add_notice('We cannot process your payment right now, please try another payment method.', 'error');
                break;
            case 'customer_zipcode_not_allow':
                $order->add_order_note(sprintf(
                    __('Paypal charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                    $_GET['proxy_site'],
                    "Customer's zipcode is blacklisted"
                ));
                csPaypalSendMailOrderBlacklisted($order->get_id());
                wc_add_notice('PAYPAL_ACCOUNT_RESTRICTED, Please contact the merchant for more information.', 'error');
                break;
            case 'customer_email_not_allow':
                $order->add_order_note(sprintf(
                    __('Paypal charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                    $_GET['proxy_site'],
                    "Customer's email is blacklisted"
                ));
                csPaypalSendMailOrderBlacklisted($order->get_id());
                wc_add_notice('We cannot process your payment right now. Please try again with another payment method.', 'error');
                break;
            case 'states_cities_not_allow':
                $order->add_order_note(sprintf(
                    __('Paypal charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                    $_GET['proxy_site'],
                    "Customer's State and City is blacklisted"
                ));
                csPaypalSendMailOrderBlacklisted($order->get_id());
                wc_add_notice('Sorry, Your selected products are not available to purchase due to our policy violation.', 'error');
                break;
            case 'order_total_not_allow':
                $order->add_order_note(sprintf(
                    __('Paypal charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                    $_GET['proxy_site'],
                    "Order value exceeds PayPal capability"
                ));
                wc_add_notice('We cannot process your payment right now. Please try again with another payment method.', 'error');
                break;
            case 'customer_ip_blacklisted':
                $order->add_order_note(sprintf(
                    __('Paypal charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                    $_GET['proxy_site'],
                    "Smart Shield+ blocked this payment due to high risk exposure."
                ));
                wc_add_notice('We cannot process your payment right now. Please try again with another payment method.', 'error');
                break;
        }
        redirectToCheckoutPage();
    }

    if (isset($_GET['wootify-paypal-note-debug'])) {
        csPaypalDebugLog($_GET, "paypal pp_order_id debug");
        exit();
    }

    if (isset($_GET['wootify-paypal-button-create-order']) && isset($_POST['current_proxy_id'])) {
        $isEnableEndpointMode = isCsPaypalEnableEndpointMode();
        if ($isEnableEndpointMode) {
            $activatedProxy = ['id' => null, 'url' => $_POST['current_proxy_url']];
        } else {
            $activatedProxy = findActivatedProxyDataById(get_option(OPT_WOOTIFY_PAYPAL_PROXIES, []), $_POST['current_proxy_id']);
        }
        $response = wp_remote_post($activatedProxy['url'] . '?wootify-paypal-create-order=1', [
            'sslverify' => false,
            'timeout' => 5 * 60,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'order' => $_POST['cs_order'],
            ])
        ]);
        if (is_wp_error($response)) {
            csPaypalErrorLog($response, "pp request checkout error[12]");
        }
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);
        if ($data->status === 'failed' && isset($data->error_detail)) {
            csPaypalErrorLog($body, 'wootify-paypal-button-create-order FAIL');
            if (in_array($data->error_detail, ['PAYEE_ACCOUNT_LOCKED_OR_CLOSED', 'PAYEE_ACCOUNT_RESTRICTED'])) {
                if ($isEnableEndpointMode) {
                    csEndpointSetNextShield(wc_get_order($_POST['cs_order']));
                } else {
                    if (isEnabledAmountRotation()) {
                        performProxyAmountRotation($activatedProxy, 0);
                    } else {
                        performProxyByTimeRotation($activatedProxy);
                    }
                    moveToUnusedProxyIdsRestrictAccount([$activatedProxy['id']]);
                }
                csPaypalSendMailShieldDie($activatedProxy['url']);
            }
            return false;
        }
        exit();
    }

    if (isset($_POST['wootify-paypal-button-create-woo-order']) && $_POST['pp_order_id']) {
        $cart = WC()->cart;
        if (!empty($_POST['order_id'])) {
            handlePaypalButtonCreateWooOrderAtPayForOrder($_POST['order_id'], $_POST['pp_order_id'], $_POST['current_proxy_id'], $_POST['current_proxy_url']);
        } else {
            handlePaypalButtonCreateWooOrder($cart, $_POST['pp_order_id'], $_POST['current_proxy_id'], $_POST['current_proxy_url']);
        }
    }

    if (isset($_POST['wootify-paypal-button-reset-carts-and-get-purchase-units']) && $_POST['product_id'] && $_POST['quantity']) {
        WC()->cart->empty_cart();
        $product = wc_get_product($_POST['product_id']);
        if (is_array($_POST['variations'])) {
            $variations = array();
            foreach ($_POST['variations'] as $key => $value) {
                $variations[$value['name']] = $value['value'];
            }

            $variation_id = WC_Data_Store::load('product')->find_matching_product_variation($product, $variations);
            WC()->cart->add_to_cart($_POST['product_id'], $_POST['quantity'], $variation_id, $variations);
        } else {
            WC()->cart->add_to_cart($_POST['product_id'], $_POST['quantity']);
        }
        $purchaseUnits = get_purchase_unit_from_cart(WC()->cart);
        echo json_encode([$purchaseUnits]);
        exit();
    }

    if (isset($_POST['wootify-paypal-button-reset-carts'])) {
        WC()->cart->empty_cart();
        echo json_encode(['status' => 'success']);
        exit();
    }

    if (isset($_POST['wootify-paypal-button-calculate-to-get-purchase-units'])) {
        if (isset($_POST['order_id']) && !empty($_POST['order_id'])) {
            $purchaseUnits = get_purchase_unit_from_order(wc_get_order(get_query_var('order-pay')));
        } else {
            $purchaseUnits = get_purchase_unit_from_cart(WC()->cart);
        }
        echo json_encode([$purchaseUnits]);
        exit();
    }
}

function cs_pp_get_setting_value($key, $default = null) {
    $settings = get_option('woocommerce_wootify_paypal_settings', []);
    if (!empty($settings) && array_key_exists($key, $settings)) {
        return $settings[$key];
    }
    $legacy = get_option('woocommerce_paypal_settings', []);
    if (!empty($legacy) && array_key_exists($key, $legacy)) {
        return $legacy[$key];
    }
    return $default;
}

function cs_pp_normalize_checkout_base($baseUrl) {
    $parsed = wp_parse_url($baseUrl);
    if (!$parsed) {
        return rtrim($baseUrl, '/') . '/checkouts/';
    }
    $path = rtrim($parsed['path'] ?? '', '/');
    if ($path === '') {
        return rtrim($baseUrl, '/') . '/checkouts/';
    }
    return $baseUrl;
}

function cs_pp_build_proxy_url($baseUrl, $params) {
    $baseUrl = cs_pp_normalize_checkout_base($baseUrl);
    return add_query_arg($params, $baseUrl);
}

function cs_pp_get_nested_value($source, $path, $default = '') {
    $value = $source;
    foreach ($path as $key) {
        if (is_object($value) && isset($value->{$key})) {
            $value = $value->{$key};
            continue;
        }
        if (is_array($value) && isset($value[$key])) {
            $value = $value[$key];
            continue;
        }
        return $default;
    }
    return $value === null ? $default : $value;
}

function cs_pp_clean_checkout_value($value) {
    if (is_array($value) || is_object($value)) {
        return '';
    }
    return sanitize_text_field(wp_unslash((string) $value));
}

function cs_pp_get_checkout_post_value($key) {
    if (isset($_POST[$key])) {
        return cs_pp_clean_checkout_value($_POST[$key]);
    }
    return '';
}

function cs_pp_get_customer_value($type, $field) {
    if (!function_exists('WC') || !WC()->customer) {
        return '';
    }

    $method = 'get_' . $type . '_' . $field;
    if (is_callable([WC()->customer, $method])) {
        return cs_pp_clean_checkout_value(WC()->customer->{$method}());
    }

    return '';
}

function cs_pp_get_checkout_field($type, $field) {
    $value = cs_pp_get_checkout_post_value($type . '_' . $field);
    if ($value !== '') {
        return $value;
    }

    return cs_pp_get_customer_value($type, $field);
}

function cs_pp_first_non_empty($values) {
    foreach ($values as $value) {
        $value = cs_pp_clean_checkout_value($value);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function cs_pp_build_checkout_addresses($ppOrder) {
    $payerCountry = cs_pp_get_nested_value($ppOrder, ['payer', 'address', 'country_code']);
    $payerPhone = cs_pp_get_nested_value($ppOrder, ['payer', 'phone', 'phone_number', 'national_number']);
    $paypalShipping = cs_pp_get_nested_value($ppOrder, ['purchase_units', 0, 'shipping'], null);

    $paypalAddress = [
        'first_name' => cs_pp_get_nested_value($paypalShipping, ['name', 'full_name']),
        'address_1' => cs_pp_get_nested_value($paypalShipping, ['address', 'address_line_1']),
        'address_2' => cs_pp_get_nested_value($paypalShipping, ['address', 'address_line_2']),
        'city' => cs_pp_get_nested_value($paypalShipping, ['address', 'admin_area_2']),
        'state' => cs_pp_get_nested_value($paypalShipping, ['address', 'admin_area_1']),
        'postcode' => cs_pp_get_nested_value($paypalShipping, ['address', 'postal_code']),
        'country' => cs_pp_get_nested_value($paypalShipping, ['address', 'country_code']),
    ];

    $payerFirstName = cs_pp_get_nested_value($ppOrder, ['payer', 'name', 'given_name']);
    $payerLastName = cs_pp_get_nested_value($ppOrder, ['payer', 'name', 'surname']);
    if ($paypalAddress['first_name'] !== '' && ($payerFirstName === '' || $payerLastName === '')) {
        $nameParts = preg_split('/\s+/', trim($paypalAddress['first_name']), 2);
        $payerFirstName = $payerFirstName !== '' ? $payerFirstName : ($nameParts[0] ?? '');
        $payerLastName = $payerLastName !== '' ? $payerLastName : ($nameParts[1] ?? '');
    }

    $billing = [
        'first_name' => cs_pp_first_non_empty([$payerFirstName, cs_pp_get_checkout_field('billing', 'first_name'), cs_pp_get_checkout_field('shipping', 'first_name')]),
        'last_name' => cs_pp_first_non_empty([$payerLastName, cs_pp_get_checkout_field('billing', 'last_name'), cs_pp_get_checkout_field('shipping', 'last_name')]),
        'email' => cs_pp_first_non_empty([cs_pp_get_nested_value($ppOrder, ['payer', 'email_address']), cs_pp_get_checkout_field('billing', 'email')]),
        'address_1' => cs_pp_first_non_empty([cs_pp_get_checkout_field('billing', 'address_1'), $paypalAddress['address_1'], cs_pp_get_checkout_field('shipping', 'address_1')]),
        'address_2' => cs_pp_first_non_empty([cs_pp_get_checkout_field('billing', 'address_2'), $paypalAddress['address_2'], cs_pp_get_checkout_field('shipping', 'address_2')]),
        'city' => cs_pp_first_non_empty([cs_pp_get_checkout_field('billing', 'city'), $paypalAddress['city'], cs_pp_get_checkout_field('shipping', 'city')]),
        'state' => cs_pp_first_non_empty([cs_pp_get_checkout_field('billing', 'state'), $paypalAddress['state'], cs_pp_get_checkout_field('shipping', 'state')]),
        'postcode' => cs_pp_first_non_empty([cs_pp_get_checkout_field('billing', 'postcode'), $paypalAddress['postcode'], cs_pp_get_checkout_field('shipping', 'postcode')]),
        'country' => cs_pp_first_non_empty([cs_pp_get_checkout_field('billing', 'country'), $paypalAddress['country'], cs_pp_get_checkout_field('shipping', 'country'), $payerCountry]),
        'phone' => cs_pp_first_non_empty([$payerPhone, cs_pp_get_checkout_field('billing', 'phone')]),
    ];

    $shipping = [
        'first_name' => cs_pp_first_non_empty([$payerFirstName, cs_pp_get_checkout_field('shipping', 'first_name'), $billing['first_name']]),
        'last_name' => cs_pp_first_non_empty([$payerLastName, cs_pp_get_checkout_field('shipping', 'last_name'), $billing['last_name']]),
        'address_1' => cs_pp_first_non_empty([$paypalAddress['address_1'], cs_pp_get_checkout_field('shipping', 'address_1'), $billing['address_1']]),
        'address_2' => cs_pp_first_non_empty([$paypalAddress['address_2'], cs_pp_get_checkout_field('shipping', 'address_2'), $billing['address_2']]),
        'city' => cs_pp_first_non_empty([$paypalAddress['city'], cs_pp_get_checkout_field('shipping', 'city'), $billing['city']]),
        'state' => cs_pp_first_non_empty([$paypalAddress['state'], cs_pp_get_checkout_field('shipping', 'state'), $billing['state']]),
        'postcode' => cs_pp_first_non_empty([$paypalAddress['postcode'], cs_pp_get_checkout_field('shipping', 'postcode'), $billing['postcode']]),
        'country' => cs_pp_first_non_empty([$paypalAddress['country'], cs_pp_get_checkout_field('shipping', 'country'), $billing['country'], $payerCountry]),
    ];

    return [
        'billing' => $billing,
        'shipping' => $shipping,
    ];
}

function handlePaypalButtonCreateWooOrder($cart, $ppOrderId, $currentProxyId, $currentProxyUrl) {
    // Get Order info from paypal to get ship + bill address
    $isEnableEndpointMode = isCsPaypalEnableEndpointMode();
    if ($isEnableEndpointMode) {
        $activatedProxy = ['id' => null, 'url' => $currentProxyUrl];
    } else {
        $activatedProxy = findActivatedProxyDataById(get_option(OPT_WOOTIFY_PAYPAL_PROXIES, []), $currentProxyId);
    }
    $getActivateProxyUrl = $activatedProxy['url'];
    $getOrderUrl = $activatedProxy['url'] . '?wootify-paypal-get-order=1';
    $payloadJson = json_encode([
        'order_id' => $ppOrderId,
    ]);
    $response = wp_remote_post($getOrderUrl, shield_proxy_signed_request_args($activatedProxy, 'POST', $getOrderUrl, [
        'sslverify' => false,
        'timeout' => 5 * 60,
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'body' => $payloadJson,
    ], $payloadJson));
    if (is_wp_error($response)) {
        csPaypalErrorLog([$activatedProxy, $response], "handlePaypalButtonCreateWooOrder ERROR! [1]");
    }
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body);
    csPaypalDebugLog([$activatedProxy, $response, $body], 'wootify-paypal-get-order RESPONSE: ' . __LINE__);
    if ($data->status === 'failed') {
        echo json_encode([
            'result' => 'failed',
            'message' => 'We cannot process your payment right now, please try another payment method.[24]',
        ]);
        exit();
    }
    $ppOrder = $data->order;
    $addresses = cs_pp_build_checkout_addresses($ppOrder);
    $address = $addresses['billing'];
    // Create new Order
    $checkout = WC()->checkout();
    $order_id = $checkout->create_order(array());
    $order = wc_get_order($order_id);
    $order->set_address($addresses['billing'], 'billing');
    $order->set_address($addresses['shipping'], 'shipping');
    $payment_gateways = WC()->payment_gateways->payment_gateways();
    $order->set_payment_method($payment_gateways['WOOTIFY_paypal']);
    $order->set_created_via('paypal_express_checkout');
    $order->set_customer_id(apply_filters('woocommerce_checkout_customer_id', get_current_user_id()));
    $order->set_currency(get_woocommerce_currency());

    // Add Order Shipping
    if ((WC()->cart->needs_shipping())) {
        if (!$order->get_item_count('shipping')) { // count is 0
            $chosen_methods = WC()->session->get('chosen_shipping_methods');
            $shipping_for_package_0 = WC()->session->get('shipping_for_package_0');

            if (!empty($chosen_methods)) {
                if (isset($shipping_for_package_0['rates'][$chosen_methods[0]])) {
                    $chosen_method = $shipping_for_package_0['rates'][$chosen_methods[0]];
                    $item = new WC_Order_Item_Shipping();
                    $item->set_shipping_rate($chosen_method);
                    if (empty($chosen_method->get_taxes())) {
                        add_action('woocommerce_order_item_shipping_after_calculate_taxes', 'WOOTIFY_pp_remove_shipping_taxes');
                    }
                    foreach ($chosen_method->get_meta_data() as $key => $value) {
                        $item->add_meta_data($key, $value, true);
                    }
                    $order->add_item($item);
                }
            }
        }
    }
    $order->calculate_totals();

    // Process paypal payment at Proxy
    $wootifyPPGateway = WC_WOOTIFY_Gateway::load();
    $order->update_meta_data(METAKEY_PAYPAL_PROCESSING_ORDER_KEY, WC()->session->get('wootify-paypal-processing-order-key'));
    $order->update_meta_data(METAKEY_PAYPAL_PROXY_URL, $getActivateProxyUrl);
    $order->update_meta_data('_shield_payment_method', 'paypal');
    $order->update_meta_data('_shield_payment_url', $getActivateProxyUrl);
    $order->update_meta_data(METAKEY_PAYPAL_PROXY_ID, $activatedProxy['id']);
    $order->update_meta_data(METAKEY_CS_PAYPAL_INTENT, $wootifyPPGateway->intent);
    $order->add_order_note(sprintf(
        __('Express Paypal processing info at proxy %s, message: %s', 'wootify'),
        $getActivateProxyUrl,
        'Start checkout paypal'
    ));
    $order_items = $cart->get_cart();
    $productNameArr = [];
    foreach ($order_items as $it) {
        $product = wc_get_product($it['product_id']);
        $product_name = $wootifyPPGateway->getProductTitle($product->get_title(), $order_id);
        $item_quantity = $it['quantity'];
        $productNameArr[] = $product_name . ' x ' . $item_quantity;
    }
    $purchaseUnits = get_purchase_unit_from_order($order);
    $orderData = [
        'total' => $order->get_total(),
        'currency' => $order->get_currency(),
        'invoice_id' => $wootifyPPGateway->invoice_prefix . $order->get_order_number(),
        'items' => [
            ['name' => implode(", ", $productNameArr)]
        ],
        'purchase_units' => $purchaseUnits
    ];
    $orderData["billing_info"]  = "billing[address_city]=" . $address['city'] . "&billing[address_country]=" . $address['country'] . "&billing[address_state]=" . $address['state'];
    $orderData['order_id'] = $order_id;
    $orderData['pp_order_id'] = $ppOrderId;
    $orderData['customer_zipcode'] = $address['postcode'];
    $orderData['customer_email'] = $address['email'];
    $orderData['shipping_address_country'] = $addresses['shipping']['country'];
    $orderData['bfp'] = WC()->session->get('wootify-paypal-browser-fingerprint');
    if ($wootifyPPGateway->intent == OPT_CS_PAYPAL_AUTHORIZE) {
        $urlCheckout = $getActivateProxyUrl . "?wootify-paypal-authorize-order=1"
            . '&' . http_build_query($orderData);
    } else {
        $urlCheckout = $getActivateProxyUrl . "?wootify-paypal-capture-order=1"
            . '&' . http_build_query($orderData);
    }
    $idempotencyKey = 'pp-capture-' . $order_id . '-' . $ppOrderId;
    $payloadJson = json_encode([
        'cs_order_detail' => getCsPaypalOrderDetailFromWcOrder($order),
    ]);
    $proxyProcess = wp_remote_post($urlCheckout, shield_proxy_signed_request_args($activatedProxy, 'POST', $urlCheckout, [
        'sslverify' => false,
        'timeout' => 300,
        'headers' => [
            'Content-Type' => 'application/json',
            'X-Shield-Idempotency-Key' => $idempotencyKey,
        ],
        'body' => $payloadJson,
    ], $payloadJson));
    if (is_wp_error($proxyProcess)) {
        csPaypalErrorLog($proxyProcess, "pp request checkout error[13]");
    }
    $order->add_order_note(sprintf(
        __('Express Paypal handle order at proxy url %s', 'wootify'),
        $getActivateProxyUrl
    ));
    $responseBody = wp_remote_retrieve_body($proxyProcess);
    $data = json_decode($responseBody);
    if ($data->status === 'success') {
        if ($wootifyPPGateway->intent == OPT_CS_PAYPAL_AUTHORIZE) {
            $ppPayment = $data->order->purchase_units[0]->payments->authorizations[0];
        } else {
            $ppPayment = $data->order->purchase_units[0]->payments->captures[0];
        }
    }
    $order->update_meta_data('_cs_paypal_checkout_page', 'express');
    $order->update_meta_data('_shield_paypal_funding_source', $data->order->purchase_units[0]->custom_id ?? null);
    $order->save_meta_data();
    if ($data->status === 'success' && isset($ppPayment)) {
        if ($wootifyPPGateway->intent == OPT_CS_PAYPAL_AUTHORIZE) {
            $order->add_order_note(sprintf(__('Express PayPal authorized by proxy %s, ID: %s', 'wootify'), $getActivateProxyUrl, $ppPayment->id), 0, false);
            $order->update_status('on-hold', 'Express Payment can be captured.');
            $order->update_meta_data(METAKEY_CS_PAYPAL_CAPTURED, 'false');
        } else {
            $order->add_order_note(sprintf(__('Express Paypal charged by proxy %s', 'wootify'), $getActivateProxyUrl), 0, false);
            $order->add_order_note(sprintf(__('Express Paypal Checkout charge complete (Payment ID: %s)', 'wootify'), $ppPayment->id));

            $sellerPayableBreakdown = $data->seller_receivable_breakdown;
            $paypalFee = $sellerPayableBreakdown->paypal_fee->value;
            $paypalCurrency = $sellerPayableBreakdown->paypal_fee->currency_code;
            $paypalPayout = $sellerPayableBreakdown->net_amount->value;

            $order->update_meta_data(METAKEY_CS_PAYPAL_FEE, $paypalFee);
            $order->update_meta_data(METAKEY_CS_PAYPAL_PAYOUT, $paypalPayout);
            $order->update_meta_data(METAKEY_CS_PAYPAL_CURRENCY, $paypalCurrency);
            $order->payment_complete();
        }
        $order->reduce_order_stock();
        $isEnableEndpointMode = isCsPaypalEnableEndpointMode();
        if ($isEnableEndpointMode) {
            csEndpointPerformShieldRotateByAmount($order);
        } else {
            if (isEnabledAmountRotation()) {
                performProxyAmountRotation($activatedProxy, $order->get_total());
                updateRotationAmount($activatedProxy['id'], $order->get_total());
            }
        }
        csPaypalSaveTransactionId($order, $ppPayment->id);
        $order->update_meta_data(METAKEY_PAYPAL_SYNC_TRACKING_INFO, OPT_CS_PAYPAL_NOT_SYNCED);
        $order->save_meta_data();
        // Empty cart
        WC()->cart->empty_cart();
        echo json_encode([
            'result' => 'success',
            'redirect' => $order->get_checkout_order_received_url()
        ]);
    } else {
        $msg_err = 'We cannot process your PayPal payment now, please try again with another method.';
        if ($data->code === 'domain_whitelist_not_allow') {
            $order->add_order_note(sprintf(
                __('Express Paypal charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                $getActivateProxyUrl,
                'Domain whitelist is required'
            ));
            $msg_err = 'We cannot process your payment right now, please try another payment method.[21]';
        } else if ($data->code === 'order_total_not_allow') {
            $order->add_order_note(sprintf(
                __('Express Paypal charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                $getActivateProxyUrl,
                "Order value exceeds PayPal capability"
            ));
            $msg_err = 'We cannot process your payment right now, please try another payment method.[22]';
        } else if ($data->code === 'customer_zipcode_not_allow') {
            $order->add_order_note(sprintf(
                __('Express Paypal charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                $getActivateProxyUrl,
                "Customer's zipcode is blacklisted"
            ));
            csPaypalSendMailOrderBlacklisted($order->get_id());
            $msg_err = 'We cannot process your payment right now, please try another payment method.[23]';
        } else if ($data->code === 'customer_email_not_allow') {
            $order->add_order_note(sprintf(
                __('Express Paypal charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                $getActivateProxyUrl,
                "Customer's email is blacklisted"
            ));
            csPaypalSendMailOrderBlacklisted($order->get_id());
            $msg_err = 'We cannot process your payment right now, please try another payment method.[24]';
        } else if ($data->code === 'states_cities_not_allow') {
            $order->add_order_note(sprintf(
                __('Express Paypal charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                $getActivateProxyUrl,
                "Customer's State and City is blacklisted"
            ));
            csPaypalSendMailOrderBlacklisted($order->get_id());
            $msg_err = 'We cannot process your payment right now, please try another payment method.[25]';
        } else {
            $order->add_order_note(sprintf(
                __('Express Paypal charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                $getActivateProxyUrl,
                $data->code
            ));
            $msg_err = 'We cannot process your payment right now, please try another payment method.[29]';
        }
        csPaypalDebugLog([$data, $order->get_id()], 'Paypal Checkout error!');
        $order->update_status('failed');
        echo json_encode([
            'result' => 'failed',
            'message' => $msg_err
        ]);
    }
    exit();
}

function handlePaypalButtonCreateWooOrderAtPayForOrder($order_id, $ppOrderId, $currentProxyId, $currentProxyUrl) {
    // Get Order info from paypal to get ship + bill address
    $isEnableEndpointMode = isCsPaypalEnableEndpointMode();
    if ($isEnableEndpointMode) {
        $activatedProxy = ['id' => null, 'url' => $currentProxyUrl];
    } else {
        $activatedProxy = findActivatedProxyDataById(get_option(OPT_WOOTIFY_PAYPAL_PROXIES, []), $currentProxyId);
    }
    $getActivateProxyUrl = $activatedProxy['url'];
    // Add Order Shipping
    // Process paypal payment at Proxy
    $order = wc_get_order($order_id);
    $wootifyPPGateway = WC_WOOTIFY_Gateway::load();
    $order->update_meta_data(METAKEY_PAYPAL_PROCESSING_ORDER_KEY, WC()->session->get('wootify-paypal-processing-order-key'));
    $order->update_meta_data(METAKEY_PAYPAL_PROXY_URL, $getActivateProxyUrl);
    $order->update_meta_data('_shield_payment_method', 'paypal');
    $order->update_meta_data('_shield_payment_url', $getActivateProxyUrl);
    $order->update_meta_data(METAKEY_PAYPAL_PROXY_ID, $activatedProxy['id']);
    $order->update_meta_data(METAKEY_CS_PAYPAL_INTENT, $wootifyPPGateway->intent);
    $order->add_order_note(sprintf(
        __('Express Paypal processing info at proxy %s, message: %s', 'wootify'),
        $getActivateProxyUrl,
        'Start checkout paypal'
    ));
    $order_items = $order->get_items();
    $productNameArr = [];
    foreach ($order_items as $it) {
        $product = wc_get_product($it->get_product_id());
        //$product_name = $product->get_name(); // Get the product name
        $product_name = $wootifyPPGateway->getProductTitle($product->get_title(), $order_id);

        $item_quantity = $it->get_quantity(); // Get the item quantity

        $productNameArr[] = $product_name . ' x ' . $item_quantity;
    }
    $purchaseUnits = get_purchase_unit_from_order($order);
    $orderData = [
        'total' => $order->get_total(),
        'currency' => $order->get_currency(),
        'invoice_id' => $wootifyPPGateway->invoice_prefix . $order->get_order_number(),
        'items' => [
            ['name' => implode(", ", $productNameArr)]
        ],
        'purchase_units' => $purchaseUnits
    ];
    $orderData["billing_info"] = "billing[address_city]=" . $order->get_billing_city() . "&billing[address_country]=" . $order->get_billing_country() . "&billing[address_state]=" . $order->get_billing_state();
    $orderData['order_id'] = $order_id;
    $orderData['pp_order_id'] = $ppOrderId;
    $orderData['bfp'] = WC()->session->get('wootify-paypal-browser-fingerprint');
    if ($wootifyPPGateway->intent == OPT_CS_PAYPAL_AUTHORIZE) {
        $urlCheckout = $getActivateProxyUrl . "?wootify-paypal-authorize-order=1"
            . '&' . http_build_query($orderData);
    } else {
        $urlCheckout = $getActivateProxyUrl . "?wootify-paypal-capture-order=1"
            . '&' . http_build_query($orderData);
    }
    $payloadJson = json_encode([
        'cs_order_detail' => getCsPaypalOrderDetailFromWcOrder($order),
    ]);
    $proxyProcess = wp_remote_post($urlCheckout, shield_proxy_signed_request_args($activatedProxy, 'POST', $urlCheckout, [
        'sslverify' => false,
        'timeout' => 300,
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'body' => $payloadJson,
    ], $payloadJson));
    if (is_wp_error($proxyProcess)) {
        csPaypalErrorLog($proxyProcess, "pp request checkout error[11]");
    }
    $order->add_order_note(sprintf(
        __('Express Paypal handle order at proxy url %s', 'wootify'),
        $getActivateProxyUrl
    ));
    $responseBody = wp_remote_retrieve_body($proxyProcess);
    $data = json_decode($responseBody);
    if ($data->status === 'success') {
        if ($wootifyPPGateway->intent == OPT_CS_PAYPAL_AUTHORIZE) {
            $ppPayment = $data->order->purchase_units[0]->payments->authorizations[0];
        } else {
            $ppPayment = $data->order->purchase_units[0]->payments->captures[0];
        }
    }
    $order->update_meta_data('_cs_paypal_checkout_page', 'express');
    $order->update_meta_data('_shield_paypal_funding_source', $data->order->purchase_units[0]->custom_id ?? null);
    $order->save_meta_data();
    if ($data->status === 'success' && isset($ppPayment)) {
        if ($wootifyPPGateway->intent == OPT_CS_PAYPAL_AUTHORIZE) {
            $order->add_order_note(sprintf(__('Express PayPal authorized by proxy %s, ID: %s', 'wootify'), $getActivateProxyUrl, $ppPayment->id), 0, false);
            $order->update_status('on-hold', 'Express Payment can be captured.');
            $order->update_meta_data(METAKEY_CS_PAYPAL_CAPTURED, 'false');
        } else {
            $order->add_order_note(sprintf(__('Express Paypal charged by proxy %s', 'wootify'), $getActivateProxyUrl), 0, false);
            $order->add_order_note(sprintf(__('Express Paypal Checkout charge complete (Payment ID: %s)', 'wootify'), $ppPayment->id));

            $sellerPayableBreakdown = $data->seller_receivable_breakdown;
            $paypalFee = $sellerPayableBreakdown->paypal_fee->value;
            $paypalCurrency = $sellerPayableBreakdown->paypal_fee->currency_code;
            $paypalPayout = $sellerPayableBreakdown->net_amount->value;

            $order->update_meta_data(METAKEY_CS_PAYPAL_FEE, $paypalFee);
            $order->update_meta_data(METAKEY_CS_PAYPAL_PAYOUT, $paypalPayout);
            $order->update_meta_data(METAKEY_CS_PAYPAL_CURRENCY, $paypalCurrency);
            $order->payment_complete();
        }
        $order->reduce_order_stock();
        $isEnableEndpointMode = isCsPaypalEnableEndpointMode();
        if ($isEnableEndpointMode) {
            csEndpointPerformShieldRotateByAmount($order);
        } else {
            if (isEnabledAmountRotation()) {
                performProxyAmountRotation($activatedProxy, $order->get_total());
                updateRotationAmount($activatedProxy['id'], $order->get_total());
            }
        }
        csPaypalSaveTransactionId($order, $ppPayment->id);
        $order->update_meta_data(METAKEY_PAYPAL_SYNC_TRACKING_INFO, OPT_CS_PAYPAL_NOT_SYNCED);
        $order->save_meta_data();
        // Empty cart
        WC()->cart->empty_cart();
        echo json_encode([
            'result' => 'success',
            'redirect' => $order->get_checkout_order_received_url()
        ]);
    } else {
        $msg_err = 'We cannot process your PayPal payment now, please try again with another method.';
        if ($data->code === 'domain_whitelist_not_allow') {
            $order->add_order_note(sprintf(
                __('Express Paypal charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                $getActivateProxyUrl,
                'Domain whitelist is required'
            ));
            $msg_err = 'We cannot process your payment right now, please try another payment method.[21]';
        } else if ($data->code === 'order_total_not_allow') {
            $order->add_order_note(sprintf(
                __('Express Paypal charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                $getActivateProxyUrl,
                "Order value exceeds PayPal capability"
            ));
            $msg_err = 'We cannot process your payment right now, please try another payment method.[22]';
        } else if ($data->code === 'customer_zipcode_not_allow') {
            $order->add_order_note(sprintf(
                __('Express Paypal charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                $getActivateProxyUrl,
                "Customer's zipcode is blacklisted"
            ));
            $msg_err = 'We cannot process your payment right now, please try another payment method.[23]';
        } else if ($data->code === 'customer_email_not_allow') {
            $order->add_order_note(sprintf(
                __('Express Paypal charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                $getActivateProxyUrl,
                "Customer's email is blacklisted"
            ));
            $msg_err = 'We cannot process your payment right now, please try another payment method.[24]';
        } else if ($data->code === 'states_cities_not_allow') {
            $order->add_order_note(sprintf(
                __('Express Paypal charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                $getActivateProxyUrl,
                "Customer's State and City is blacklisted"
            ));
            $msg_err = 'We cannot process your payment right now, please try another payment method.[25]';
        } else {
            $order->add_order_note(sprintf(
                __('Express Paypal charged ERROR by proxy %s, ERROR message: %s', 'wootify'),
                $getActivateProxyUrl,
                $data->code
            ));
            $msg_err = 'We cannot process your payment right now, please try another payment method.[29]';
        }
        csPaypalErrorLog(json_encode($data), 'Paypal Checkout error![2]');
        $order->update_status('failed');
        echo json_encode([
            'result' => 'failed',
            'message' => $msg_err
        ]);
    }
    exit();
}

function redirectToCheckoutPage() {
    global $woocommerce;
    $checkout_page_url = function_exists('wc_get_cart_url') ? wc_get_checkout_url() : $woocommerce->cart->get_checkout_url();
    wp_redirect($checkout_page_url);
    exit();
}

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action('plugins_loaded', 'WOOTIFY_init_gateway_class');
function WOOTIFY_init_gateway_class() {

    if (is_admin()) {
        add_filter(
            'plugin_action_links_' . plugin_basename(cs_paypal_get_plugin_file()),
            'add_settings_link'
        );
        require_once("wootify-paygate-options.php");
        //        require_once("cs-pp-update-checker.php");
        //        CSPayPalUpdateChecker::load();
    }

    function add_settings_link($links) {
        $settings = array(
            'settings' => sprintf(
                '<a href="%s">%s</a>',
                admin_url('admin.php?page=wc-settings&tab=checkout&section=WOOTIFY_paypal'),
                'Settings'
            )
        );
        return array_merge($settings, $links);
    }
    require_once("cs-pp-gateway.php");
    $ppGatewayObj = WC_WOOTIFY_Gateway::load();
    add_action('wp_head', 'cs_pp_action_wp_head');
    add_action('wp_footer', 'cs_pp_action_wp_footer');
    if (cs_pp_get_setting_value('enabled_express_on_cart_page', 'no') === 'yes') {
        add_action('woocommerce_after_cart_totals', function () {
            WOOTIFY_paypal_add_checkout_button_at_carts(false);
        });
    }
    if (cs_pp_get_setting_value('enabled_express_on_product_page', 'no') === 'yes') {
        add_action('woocommerce_after_add_to_cart_button',  'WOOTIFY_paypal_add_checkout_button_at_product_page');
    }
    if (cs_pp_get_setting_value('enabled_express_on_checkout_page', 'no') === 'yes') {
        if (isset($_GET['pay_for_order'])) {
            add_action('before_woocommerce_pay',  function () {
                WOOTIFY_paypal_add_checkout_button_at_carts(true);
            });
        } else {
            add_action('woocommerce_before_checkout_form',  function () {
                WOOTIFY_paypal_add_checkout_button_at_carts(true);
            });
        }
    }
    function cs_pp_action_wp_head() {
        $gateways = WC()->payment_gateways->get_available_payment_gateways();
        $isEnableEndpointMode = isCsPaypalEnableEndpointMode();
        if (isset($gateways['WOOTIFY_paypal']->enabled) && $gateways['WOOTIFY_paypal']->enabled == 'yes') {
            echo '<meta name="referrer" content="no-referrer" />';
            WC()->session->set('wootify-paypal-browser-fingerprint', getBrowserFingerprint());
            if (isset($_GET['pay_for_order']) && get_query_var('order-pay')) {
                $orderIdProcessing = get_query_var('order-pay');
            } else {
                $orderIdProcessing = WC()->session->get('order_awaiting_payment');
            }
            if (!empty($orderIdProcessing)) {
                $orderProcessing = wc_get_order($orderIdProcessing);
                if ($isEnableEndpointMode) {
                    $proxyProcessing = ['id' => null, 'url' => $orderProcessing->get_meta(METAKEY_PAYPAL_PROXY_URL)];
                } else {
                    $proxyProcessingId = $orderProcessing->get_meta(METAKEY_PAYPAL_PROXY_ID);
                    $proxyProcessing = findActivatedProxyDataById(get_option(OPT_WOOTIFY_PAYPAL_PROXIES, []), $proxyProcessingId);
                }
            }

            if (empty($proxyProcessing)) {
                if ($isEnableEndpointMode) {
                    $csOrderKey = md5(get_option(OPT_CS_PAYPAL_ENDPOINT_TOKEN, null)) . '_' . md5(uniqid(rand(), true));
                    WC()->session->set('wootify-paypal-processing-order-key', $csOrderKey);
                    $proxyProcessing = ['id' => null, 'url' => csEndpointGetShieldPaypalToProcess($csOrderKey, 0)];
                } else {
                    $proxyProcessing = get_option(OPT_WOOTIFY_PAYPAL_ACTIVATED_PROXY, null);
                    if (isEnabledAmountRotation() && !isPayableProxy($proxyProcessing, 0)) {
                        $proxyProcessing = getNextProxyAmountRotation($proxyProcessing, 0);
                    }
                }
            }
            if (empty($proxyProcessing)) {
                if (isPaypalShieldReachAmount(0)) {
                    csPaypalSendMailShieldReachAmount();
                }
                csPaypalErrorLog([
                    WC()->session->get('order_awaiting_payment'),
                    get_query_var('order-pay'),
                    get_option(OPT_WOOTIFY_PAYPAL_PROXIES, []),
                ], 'Can not find paypal proxy for charge!');
                return;
            }
            WC()->session->set('wootify-paypal-proxy-active-id', $proxyProcessing['id']);
            WC()->session->set('wootify-paypal-proxy-active-url', $proxyProcessing['url']);
            echo '<link class="cs_pp_element" rel="preload" href="' . cs_pp_build_proxy_url($proxyProcessing['url'], ['paypal_checkout' => 1]) . '" as="document">';
        }
        cs_pp_action_backup_wp_footer();
    }

    function cs_pp_action_wp_footer() {
        $ppGatewayObj = WC_WOOTIFY_Gateway::load();
        if ((is_checkout() || is_cart())) {
            echo '<div id="cs_pp_action_wp_footer_container" class="cs_pp_element">';
            handleSomeSettingWootifyPaypal();
            echo '</div>';
        } else {
            if (cs_pp_get_setting_value('enabled_express_on_product_page', 'no') !== 'yes') {
                return;
            }
            $gateways = WC()->payment_gateways->get_available_payment_gateways();
            $isEnableEndpointMode = isCsPaypalEnableEndpointMode();
            if (isset($gateways['WOOTIFY_paypal']->enabled) && $gateways['WOOTIFY_paypal']->enabled == 'yes') {
                if ($isEnableEndpointMode) {
                    $nextProxy = ['id' => null, 'url' => WC()->session->get('wootify-paypal-proxy-active-url')];
                } else {
                    $nextProxy = findActivatedProxyDataById(get_option(OPT_WOOTIFY_PAYPAL_PROXIES, []), WC()->session->get('wootify-paypal-proxy-active-id'));
                    if (empty($nextProxy)) {
                        return;
                    }
                }
                echo '<div id="cs_pp_action_wp_footer_container" class="cs_pp_element">';
                echo '<div id="WOOTIFY_express_paypal_current_proxy_id" data-value="' . $nextProxy['id'] . '"></div>';
                echo '<div id="WOOTIFY_express_paypal_current_proxy_url" data-value="' . $nextProxy['url'] . '"></div>';
                if ($ppGatewayObj->get_option('paypal_button') === OPT_CS_PAYPAL_SETTING_CHECKOUT) {
                    echo '<div id="WOOTIFY_enable_paypal_card_payment" ></div>';
                }
                if ($ppGatewayObj->get_option('not_send_bill_address_to_paypal') === 'yes') {
                    echo '<div id="WOOTIFY_express_paypal_shipping_preference" data-value="NO_SHIPPING"></div>';
                } else {
                    echo '<div id="WOOTIFY_express_paypal_shipping_preference" data-value="GET_FROM_FILE"></div>';
                }
                echo '<div id="WOOTIFY_merchant_site_url" data-value="' . esc_attr($nextProxy['url']) . '"></div>';
?>
                <div id="cs-pp-loader-credit-custom" class="wootify-display-none" style="display: none">
                    <div class="cs-pp-spinnerWithLockIcon cs-pp-spinner" aria-busy="true">
                        <p>We're processing your payment...<br />Please <b>DO NOT</b> close this page!</p>
                    </div>
                </div>
            <?php
                echo '</div>';
            }
        }
    }

    function cs_pp_action_backup_wp_footer() {
        $ppGatewayObj = WC_WOOTIFY_Gateway::load();
        if ((is_checkout() || is_cart())) {
            $gateways = WC()->payment_gateways->get_available_payment_gateways();
            $isEnableEndpointMode = isCsPaypalEnableEndpointMode();
            if (isset($gateways['WOOTIFY_paypal']->enabled) && $gateways['WOOTIFY_paypal']->enabled == 'yes') {
                if ($isEnableEndpointMode) {
                    $nextProxy = ['id' => null, 'url' => WC()->session->get('wootify-paypal-proxy-active-url')];
                } else {
                    $nextProxy = findActivatedProxyDataById(get_option(OPT_WOOTIFY_PAYPAL_PROXIES, []), WC()->session->get('wootify-paypal-proxy-active-id'));
                    if (empty($nextProxy)) {
                        return;
                    }
                }
                $html = '<div id="WOOTIFY_express_paypal_current_proxy_id" data-value="' . $nextProxy['id'] . '"></div>';
                $html = '<div id="WOOTIFY_express_paypal_current_proxy_url" data-value="' . $nextProxy['url'] . '"></div>';
                if ($ppGatewayObj->get_option('paypal_button') === OPT_CS_PAYPAL_SETTING_CHECKOUT) {
                    $html .= '<div id="WOOTIFY_enable_paypal_card_payment" ></div>';
                }
                $html .= '<div id="WOOTIFY_merchant_site_url" data-value="' . esc_attr($nextProxy['url']) . '"></div>';
                if ($ppGatewayObj->get_option('not_send_bill_address_to_paypal') === 'yes') {
                    $html .= '<div id="WOOTIFY_express_paypal_shipping_preference" data-value="NO_SHIPPING"></div>';
                } else {
                    $html .= '<div id="WOOTIFY_express_paypal_shipping_preference" data-value="GET_FROM_FILE"></div>';
                }
                echo "<script class='cs_pp_element'>
                    document.addEventListener('DOMContentLoaded', function() {
                        if (!document.getElementById('cs_pp_action_wp_footer_container')) {
                            var div = document.createElement('div');
				            div.innerHTML = '$html';
                            document.body.appendChild(div);
                        }
                    });
                </script>";
            }
        } else {
            if (cs_pp_get_setting_value('enabled_express_on_product_page', 'no') !== 'yes') {
                return;
            }
            $gateways = WC()->payment_gateways->get_available_payment_gateways();
            $isEnableEndpointMode = isCsPaypalEnableEndpointMode();
            if (isset($gateways['WOOTIFY_paypal']->enabled) && $gateways['WOOTIFY_paypal']->enabled == 'yes') {
                if ($isEnableEndpointMode) {
                    $nextProxy = ['id' => null, 'url' => WC()->session->get('wootify-paypal-proxy-active-url')];
                } else {
                    $nextProxy = findActivatedProxyDataById(get_option(OPT_WOOTIFY_PAYPAL_PROXIES, []), WC()->session->get('wootify-paypal-proxy-active-id'));
                    if (empty($nextProxy)) {
                        return;
                    }
                }
                $html = '<div id="WOOTIFY_express_paypal_current_proxy_id" data-value="' . $nextProxy['id'] . '"></div>';
                $html = '<div id="WOOTIFY_express_paypal_current_proxy_url" data-value="' . $nextProxy['url'] . '"></div>';
                if ($ppGatewayObj->get_option('paypal_button') === OPT_CS_PAYPAL_SETTING_CHECKOUT) {
                    $html .= '<div id="WOOTIFY_enable_paypal_card_payment" ></div>';
                }
                wp_register_script('WOOTIFY_js_sha1_custom', plugins_url('/assets/js/sha1.js', __FILE__) . '?v=' . uniqid(), []);
                wp_enqueue_script('WOOTIFY_js_sha1_custom');
                wp_register_script('WOOTIFY_js_paypal_checkout_hook_custom', plugins_url('/assets/js/checkout_hook_custom.js', __FILE__) . '?v=' . uniqid(), ['jquery']);
                wp_enqueue_script('WOOTIFY_js_paypal_checkout_hook_custom');
                wp_register_style('WOOTIFY_styles_pp_custom', plugins_url('assets/css/styles.css', __FILE__) . '?v=' . uniqid(), []);
                wp_enqueue_style('WOOTIFY_styles_pp_custom');
                if ($ppGatewayObj->get_option('not_send_bill_address_to_paypal') === 'yes') {
                    $html .= '<div id="WOOTIFY_express_paypal_shipping_preference" data-value="NO_SHIPPING"></div>';
                } else {
                    $html .= '<div id="WOOTIFY_express_paypal_shipping_preference" data-value="GET_FROM_FILE"></div>';
                }
                $html .= '<div id="WOOTIFY_merchant_site_url" data-value="' . esc_attr($nextProxy['url']) . '"></div>';
                $html .= '<div id="cs-pp-loader-credit-custom" class="wootify-display-none" style="display: none">';
                $html .= '<div class="cs-pp-spinnerWithLockIcon cs-pp-spinner" aria-busy="true">';
                $html .= '<p>We\\\'re processing your payment...<br/>Please <b>DO NOT</b> close this page!</p>';
                $html .= '</div>';
                $html .= '</div>';
                echo "<script class='cs_pp_element'>
                    document.addEventListener('DOMContentLoaded', function() {
                        if (!document.getElementById('cs_pp_action_wp_footer_container')) {
                            var div = document.createElement('div');
				            div.innerHTML = '$html';
                            document.body.appendChild(div);
                        }
                    });
                </script>";
            }
        }
    }

    function handleSomeSettingWootifyPaypal() {
        $gateways = WC()->payment_gateways->get_available_payment_gateways();
        $isEnableEndpointMode = isCsPaypalEnableEndpointMode();
        if (isset($gateways['WOOTIFY_paypal']->enabled) && $gateways['WOOTIFY_paypal']->enabled == 'yes') {
            $ppGatewayObj = WC_WOOTIFY_Gateway::load();
            if ($isEnableEndpointMode) {
                $nextProxy = ['id' => null, 'url' => WC()->session->get('wootify-paypal-proxy-active-url')];
            } else {
                $nextProxy = findActivatedProxyDataById(get_option(OPT_WOOTIFY_PAYPAL_PROXIES, []), WC()->session->get('wootify-paypal-proxy-active-id'));
                if (empty($nextProxy)) {
                    return;
                }
            }
            echo '<div id="WOOTIFY_express_paypal_current_proxy_id" data-value="' . $nextProxy['id'] . '"></div>';
            echo '<div id="WOOTIFY_express_paypal_current_proxy_url" data-value="' . $nextProxy['url'] . '"></div>';
            if ($ppGatewayObj->get_option('paypal_button') === OPT_CS_PAYPAL_SETTING_CHECKOUT) {
                echo '<div id="WOOTIFY_enable_paypal_card_payment" ></div>';
            }
            echo '<div id="WOOTIFY_merchant_site_url" data-value="' . esc_attr($nextProxy['url']) . '"></div>';
            if ($ppGatewayObj->get_option('not_send_bill_address_to_paypal') === 'yes') {
                echo '<div id="WOOTIFY_express_paypal_shipping_preference" data-value="NO_SHIPPING"></div>';
            } else {
                echo '<div id="WOOTIFY_express_paypal_shipping_preference" data-value="GET_FROM_FILE"></div>';
            }
        }
    }

    function WOOTIFY_paypal_add_button_credit() {
        if (is_checkout()) {
            $gateways = WC()->payment_gateways->get_available_payment_gateways();
            if (isset($gateways['WOOTIFY_paypal']->enabled) && $gateways['WOOTIFY_paypal']->enabled == 'yes') {
                $nextProxyUrl = WC()->session->get('wootify-paypal-proxy-active-url');
                $ppGatewayObj = WC_WOOTIFY_Gateway::load();
                $ppBtnSetting = $ppGatewayObj->get_option('paypal_button');
            ?>
                <div id="wootify-paypal-button-setting" data-value="<?= $ppBtnSetting ?>" style="display:none"></div>
                <div id="wootify-paypal-button-setting-context" data-value="checkout_page" style="display:none"></div>
                <?php
                if ($nextProxyUrl && $ppBtnSetting === OPT_CS_PAYPAL_SETTING_CHECKOUT) {
                    $intentIframe = strtolower($ppGatewayObj->get_option('intent'));
                    $params = [
                        'paypal_checkout' => 1,
                        'intent' => $intentIframe,
                        'currency' => get_woocommerce_currency(),
                    ];
                    if ($ppGatewayObj->get_option('disable_credit_card') == 'yes') {
                        $params['disable_credit_card'] = 1;
                    }
                    if ($ppGatewayObj->get_option('disable_credit_card_express') == 'yes') {
                        $params['disable_credit_card_express'] = 1;
                    }
                    $proxyFullUrl = cs_pp_build_proxy_url($nextProxyUrl, $params);
                ?>
                    <div id="wootify-paypal-credit-form-container" style="display:none">
                        <iframe id="payment-paypal-area" referrerpolicy="no-referrer" src="<?= $proxyFullUrl ?>" height="130" frameBorder="0" style="width: 100%"></iframe>
                        <div style="display: none" id="wootify-paypal-order-intent" data-value="<?= $ppGatewayObj->get_option('intent') ?>"></div>
                    </div>
            <?php
                }
            }
        }
    }
    function WOOTIFY_paypal_add_checkout_button_at_carts($isCheckoutPage) {
        if (!$isCheckoutPage) {
            handleSomeSettingWootifyPaypal();
        }
        $gateways = WC()->payment_gateways->get_available_payment_gateways();
        if (isset($gateways['WOOTIFY_paypal']->enabled) && $gateways['WOOTIFY_paypal']->enabled == 'yes') {
            $nextProxyUrl = WC()->session->get('wootify-paypal-proxy-active-url');
            $ppGatewayObj = WC_WOOTIFY_Gateway::load();
            $ppBtnSetting = $ppGatewayObj->get_option('paypal_button');
            ?>
            <div id="wootify-paypal-button-setting-custom" data-value="<?= $ppBtnSetting ?>" style="display:none"></div>
            <div id="wootify-paypal-button-setting-context" data-value="<?= $isCheckoutPage ? 'express_checkout_page' : 'carts_page' ?>" style="display:none"></div>
            <?php
            if ($nextProxyUrl && $ppBtnSetting === OPT_CS_PAYPAL_SETTING_CHECKOUT) {
                wp_register_script('WOOTIFY_js_paypal_checkout_hook_custom', plugins_url('/assets/js/checkout_hook_custom.js', __FILE__) . '?v=' . uniqid(), ['jquery']);
                wp_enqueue_script('WOOTIFY_js_paypal_checkout_hook_custom');
                $intentIframe = strtolower($ppGatewayObj->get_option('intent'));
                $params = [
                    'paypal_checkout' => 1,
                    'is_not_checkout_page' => 1,
                    'intent' => $intentIframe,
                    'currency' => get_woocommerce_currency(),
                ];
                if ($isCheckoutPage) {
                    $params['express_button_style'] = 1;
                }
                if ($ppGatewayObj->get_option('disable_credit_card') == 'yes') {
                    $params['disable_credit_card'] = 1;
                }
                if ($ppGatewayObj->get_option('disable_credit_card_express') == 'yes') {
                    $params['disable_credit_card_express'] = 1;
                }
                $proxyFullUrl = cs_pp_build_proxy_url($nextProxyUrl, $params);
            ?>
                <div id="cs-pp-loader-credit-custom">
                    <div class="cs-pp-spinnerWithLockIcon cs-pp-spinner" aria-busy="true">
                        <p>We're processing your payment...<br />Please <b>DO NOT</b> close this page!</p>
                    </div>
                </div>
                <div id="wootify-paypal-credit-form-container-custom" style="position: relative;">
                    <?php
                    if ($isCheckoutPage) {
                    ?>
                        <div id="paypal-button-express-text" class="cs_pp_element">Express Checkout</div>
                    <?php
                    } else {
                    ?>
                        <div id="paypal-button-express-or-text" class="cs_pp_element" style="text-align: center">- OR -</div>
                    <?php
                    }
                    ?>
                    <iframe id="payment-paypal-area-custom" referrerpolicy="no-referrer" src="<?= $proxyFullUrl ?>" height="150" frameBorder="0" style="width: 100%"></iframe>
                    <?php
                    if ($isCheckoutPage) {
                    ?>
                        <div style="text-align: center" class="cs_pp_element">- OR -</div>
                    <?php
                    }
                    ?>
                    <div style="display: none" id="wootify-paypal-order-intent-custom" data-value="<?= $ppGatewayObj->get_option('intent') ?>"></div>
                </div>
            <?php
            }
        }
    }

    function WOOTIFY_paypal_add_checkout_button_at_product_page() {
        $gateways = WC()->payment_gateways->get_available_payment_gateways();
        if (isset($gateways['WOOTIFY_paypal']->enabled) && $gateways['WOOTIFY_paypal']->enabled == 'yes') {
            $ppGatewayObj = WC_WOOTIFY_Gateway::load();
            $nextProxyUrl = WC()->session->get('wootify-paypal-proxy-active-url');
            $ppBtnSetting = $ppGatewayObj->get_option('paypal_button');
            $isProdHasVariations = is_a(wc_get_product(), 'WC_Product_Variable');
            ?>
            <div id="wootify-paypal-button-setting-custom" data-value="<?= $ppBtnSetting ?>" style="display:none"></div>
            <div id="wootify-paypal-button-setting-context" data-value="product_page" style="display:none"></div>
            <div id="wootify-paypal-product-page-current-id" data-value="<?= wc_get_product()->get_id(); ?>"></div>
            <div id="wootify-paypal-product-page-has-variations" data-value="<?= $isProdHasVariations ? 'yes' : 'no' ?>"></div>
            <?php
            if ($nextProxyUrl && $ppBtnSetting === OPT_CS_PAYPAL_SETTING_CHECKOUT) {
                $intentIframe = strtolower($ppGatewayObj->get_option('intent'));
                $params = [
                    'paypal_checkout' => 1,
                    'is_not_checkout_page' => 1,
                    'intent' => $intentIframe,
                    'currency' => get_woocommerce_currency(),
                ];
                if ($ppGatewayObj->get_option('disable_credit_card') == 'yes') {
                    $params['disable_credit_card'] = 1;
                }
                if ($ppGatewayObj->get_option('disable_credit_card_express') == 'yes' || $ppGatewayObj->get_option('disable_credit_card_express_on_product_page') == 'yes') {
                    $params['disable_credit_card_express'] = 1;
                }
                $proxyFullUrl = cs_pp_build_proxy_url($nextProxyUrl, $params);
            ?>
                <div id="wootify-paypal-credit-form-container-custom" <?= $isProdHasVariations ? 'style="display: none"' : '' ?>>
                    <div id="paypal-button-express-or-text" style="text-align: center" class="cs_pp_element">- OR -</div>
                    <iframe id="payment-paypal-area-custom" referrerpolicy="no-referrer" src="<?= $proxyFullUrl ?>" height="150" frameBorder="0" style="width: 100%"></iframe>
                    <div style="display: none" id="wootify-paypal-order-intent-custom" data-value="<?= $ppGatewayObj->get_option('intent') ?>"></div>
                </div>
<?php
            }
        }
    }
}

register_deactivation_hook(cs_paypal_get_plugin_file(), 'cs_paypal_plugin_deactivation');
register_activation_hook(cs_paypal_get_plugin_file(), 'cs_paypal_plugin_activation');
add_action('woocommerce_update_option', function ($event) {
    if ($event['id'] === 'woocommerce_paypal_settings') {
        wp_clear_scheduled_hook('WOOTIFY_gateway_paypal_cron_auto_sync');
    }
});
function cs_paypal_plugin_deactivation() {
    wp_clear_scheduled_hook('WOOTIFY_gateway_paypal_cron_auto_sync');
    wp_clear_scheduled_hook('WOOTIFY_gateway_paypal_daily');
    wp_clear_scheduled_hook('WOOTIFY_gateway_paypal_rotation');
}

function cs_paypal_plugin_activation() {
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    require_once(plugin_dir_path(__FILE__) . 'cs-advance-shipment-tracking/data.php');
    global $wpdb;
    global $csPaypalDBVersion;
    $csPaypalDBVersion = get_option('cs_paypal_db_version', '0');
    $csPaypalWooShippmentProvider = $wpdb->prefix . 'cs_woo_shippment_provider';
    $charsetCollate = $wpdb->get_charset_collate();
    // DB migration
    if (version_compare($csPaypalDBVersion, '1.0', '<')) {
        $sql = "CREATE TABLE IF NOT EXISTS  $csPaypalWooShippmentProvider (
                  id mediumint NOT NULL AUTO_INCREMENT,
                  provider_name varchar(500) NOT NULL DEFAULT '',
                  api_provider_name text,
                  custom_provider_name text,
                  ts_slug varchar(1024),
                  provider_url varchar(500) DEFAULT '',
                  shipping_country varchar(45) DEFAULT '',
                  shipping_default tinyint DEFAULT '0',
                  custom_thumb_id int NOT NULL DEFAULT '0',
                  display_in_order tinyint NOT NULL DEFAULT '1',
                  trackship_supported int NOT NULL DEFAULT '0',
                  sort_order int NOT NULL DEFAULT '0',
                  custom_tracking_url text,
                  paypal_slug text,
                  shipping_country_name varchar(45) DEFAULT '',
                  PRIMARY KEY  (id),
                  KEY ts_slug (ts_slug)
            ) $charsetCollate;";
        dbDelta($sql);
        $records = csPaypalGetAdvanceShipmentTrackingData();
        foreach ($records as $record) {
            $wpdb->insert($csPaypalWooShippmentProvider, $record);
        }

        $csPaypalDBVersion = '1.0';
    }
    update_option('cs_paypal_db_version', $csPaypalDBVersion);
}

function WOOTIFY_pp_remove_shipping_taxes(WC_Order_Item_Shipping $item) {
    $item->set_taxes(false);
}

