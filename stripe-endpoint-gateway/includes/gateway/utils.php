<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Endpoint-mode signed request args helper.
 * Uses derivedKey from active node to sign requests to site1.
 */
if (!function_exists('ep_stripe_endpoint_signed_request_args')) {
    function ep_stripe_endpoint_signed_request_args($proxyOrUrl, $method, $url, $args = [], $bodyRaw = '') {
        if (!class_exists('Shield_Stripe_Endpoint_Client')) {
            return $args;
        }
        $derivedKey = '';
        if (is_array($proxyOrUrl) && Shield_Stripe_Endpoint_Client::is_node_usable($proxyOrUrl)) {
            $derivedKey = $proxyOrUrl['derivedKey'];
        } else {
            if (is_string($proxyOrUrl)) {
                $nodes = get_option(EP_ST_NODES, []);
                if (is_array($nodes)) {
                    foreach ($nodes as $node) {
                        if (trailingslashit($node['url']) === trailingslashit($proxyOrUrl) && Shield_Stripe_Endpoint_Client::is_node_usable($node)) {
                            $derivedKey = $node['derivedKey'] ?? '';
                            break;
                        }
                    }
                }
            }
            if (empty($derivedKey)) {
                $activeNode = Shield_Stripe_Endpoint_Client::get_active_node();
                if ($activeNode && !empty($activeNode['derivedKey'])) {
                    $derivedKey = $activeNode['derivedKey'];
                }
            }
        }
        if (empty($derivedKey)) {
            return $args;
        }
        $headers = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : [];
        $shieldId = is_array($proxyOrUrl) ? ($proxyOrUrl['shieldId'] ?? $proxyOrUrl['nodeId'] ?? $proxyOrUrl['id'] ?? '') : '';
        $signed = Shield_Stripe_Endpoint_Client::build_proxy_hmac_headers($derivedKey, $method, $url, (string) $bodyRaw, $shieldId);
        $args['headers'] = array_merge($headers, $signed);
        return $args;
    }
}
// Also provide backwards-compat shim for ep_stripe_signed_request_args
if (!function_exists('ep_stripe_signed_request_args')) {
    function ep_stripe_signed_request_args($proxyOrUrl, $method, $url, $args = [], $bodyRaw = '') {
        if (!isset($args['_shield_gateway'])) {
            return ep_stripe_endpoint_signed_request_args($proxyOrUrl, $method, $url, $args, $bodyRaw);
        }

        $gateway = '';
        if (isset($args['_shield_gateway'])) {
            $gateway = (string) $args['_shield_gateway'];
            unset($args['_shield_gateway']);
        } elseif (strpos($url, 'stripe') !== false) {
            $gateway = 'stripe';
        } elseif (strpos($url, 'paypal') !== false) {
            $gateway = 'paypal';
        }

        if ($gateway === 'stripe' && class_exists('Shield_Stripe_Endpoint_Client')) {
            $derivedKey = '';
            if (is_array($proxyOrUrl) && Shield_Stripe_Endpoint_Client::is_node_usable($proxyOrUrl)) {
                $derivedKey = $proxyOrUrl['derivedKey'];
            } else {
                if (is_string($proxyOrUrl)) {
                    $nodes = get_option('EP_ST_NODES', []);
                    if (is_array($nodes)) {
                        foreach ($nodes as $node) {
                            if (trailingslashit($node['url']) === trailingslashit($proxyOrUrl) && Shield_Stripe_Endpoint_Client::is_node_usable($node)) {
                                $derivedKey = $node['derivedKey'] ?? '';
                                break;
                            }
                        }
                    }
                }
                if (empty($derivedKey)) {
                    $activeNode = Shield_Stripe_Endpoint_Client::get_active_node();
                    if ($activeNode && !empty($activeNode['derivedKey'])) {
                        $derivedKey = $activeNode['derivedKey'];
                    }
                }
            }
            if (!empty($derivedKey)) {
                $headers = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : [];
                $shieldId = is_array($proxyOrUrl) ? ($proxyOrUrl['shieldId'] ?? $proxyOrUrl['nodeId'] ?? $proxyOrUrl['id'] ?? '') : '';
                $signed = Shield_Stripe_Endpoint_Client::build_proxy_hmac_headers($derivedKey, $method, $url, (string) $bodyRaw, $shieldId);
                $args['headers'] = array_merge($headers, $signed);
            }
            return $args;
        }

        // Default or PayPal
        if (class_exists('Shield_PayPal_Endpoint_Client')) {
            $derivedKey = '';
            if (is_array($proxyOrUrl) && Shield_PayPal_Endpoint_Client::is_node_usable($proxyOrUrl)) {
                $derivedKey = $proxyOrUrl['derivedKey'];
            } else {
                if (is_string($proxyOrUrl)) {
                    $nodes = get_option('EP_PP_NODES', []);
                    if (is_array($nodes)) {
                        foreach ($nodes as $node) {
                            if (trailingslashit($node['url']) === trailingslashit($proxyOrUrl) && Shield_PayPal_Endpoint_Client::is_node_usable($node)) {
                                $derivedKey = $node['derivedKey'] ?? '';
                                break;
                            }
                        }
                    }
                }
                if (empty($derivedKey)) {
                    $activeNode = Shield_PayPal_Endpoint_Client::get_active_node();
                    if ($activeNode && !empty($activeNode['derivedKey'])) {
                        $derivedKey = $activeNode['derivedKey'];
                    }
                }
            }
            if (!empty($derivedKey)) {
                $headers = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : [];
                $shieldId = is_array($proxyOrUrl) ? ($proxyOrUrl['shieldId'] ?? $proxyOrUrl['nodeId'] ?? $proxyOrUrl['id'] ?? '') : '';
                $signed = Shield_PayPal_Endpoint_Client::build_proxy_hmac_headers($derivedKey, $method, $url, (string) $bodyRaw, $shieldId);
                $args['headers'] = array_merge($headers, $signed);
            }
        }
        return $args;
    }
}

defined('OPT_WOOTIFY_STRIPE_VERSION') || define('OPT_WOOTIFY_STRIPE_VERSION', '2.2.20');
defined('EP_ST_NODES') || define('EP_ST_NODES', 'EP_ST_NODES');
defined('EP_ST_ACTIVE_NODE') || define('EP_ST_ACTIVE_NODE', 'EP_ST_ACTIVE_NODE');
defined('EP_ST_ROTATION_METHOD') || define('EP_ST_ROTATION_METHOD', 'EP_ST_ROTATION_METHOD');
defined('EP_ST_UNUSED_NODES') || define('EP_ST_UNUSED_NODES', 'EP_ST_UNUSED_NODES');
defined('EP_ST_ROTATION_VALUE') || define('EP_ST_ROTATION_VALUE', 'EP_ST_ROTATION_VALUE');
defined('MetaKey_Stripe_Proxy_Url') || define('MetaKey_Stripe_Proxy_Url', '_endpoint_stripe_proxy_url');
defined('OPT_WOOTIFY_STRIPE_LAST_TIME_RESET_PAID_AMOUNT') || define('OPT_WOOTIFY_STRIPE_LAST_TIME_RESET_PAID_AMOUNT', 'OPT_WOOTIFY_STRIPE_LAST_TIME_RESET_PAID_AMOUNT');
defined('EP_ST_LINK_EXPRESS_ENABLED') || define('EP_ST_LINK_EXPRESS_ENABLED', 'link_express_enabled');

defined('OPT_WOOTIFY_STRIPE_INTENT_CAPTURE') || define('OPT_WOOTIFY_STRIPE_INTENT_CAPTURE', 'OPT_WOOTIFY_STRIPE_INTENT_CAPTURE');
defined('OPT_WOOTIFY_STRIPE_INTENT_AUTHORIZE') || define('OPT_WOOTIFY_STRIPE_INTENT_AUTHORIZE', 'OPT_WOOTIFY_STRIPE_INTENT_AUTHORIZE');

defined('METAKEY_EP_STRIPE_INTENT_AUTHORIZED') || define('METAKEY_EP_STRIPE_INTENT_AUTHORIZED', '_ep_stripe_intent_authorized');
defined('METAKEY_EP_STRIPE_PROXY_URL') || define('METAKEY_EP_STRIPE_PROXY_URL', '_ep_stripe_proxy_url');
defined('METAKEY_EP_STRIPE_PROXY_ID') || define('METAKEY_EP_STRIPE_PROXY_ID', '_ep_stripe_proxy_id');
defined('ROTATION_METHOD_TIME') || define('ROTATION_METHOD_TIME', 'by_time');
defined('ROTATION_METHOD_AMOUNT') || define('ROTATION_METHOD_AMOUNT', 'by_volume');

defined('METAKEY_EP_STRIPE_FEE') || define('METAKEY_EP_STRIPE_FEE', '_ep_stripe_fee');
defined('METAKEY_EP_STRIPE_PAYOUT') || define('METAKEY_EP_STRIPE_PAYOUT', '_ep_stripe_payout');
defined('METAKEY_EP_STRIPE_CURRENCY') || define('METAKEY_EP_STRIPE_CURRENCY', '_ep_stripe_currency');

// true: order currency
// false: stripe currency
defined('WOOTIFY_STRIPE_FEE_DISPLAY_ORDER_CURRENCY') || define('WOOTIFY_STRIPE_FEE_DISPLAY_ORDER_CURRENCY', true);

// Backwards compatibility for option page constants
defined('WOOTIFY_STRIPE_BY_TIME') || define('WOOTIFY_STRIPE_BY_TIME', ROTATION_METHOD_TIME);
defined('WOOTIFY_STRIPE_BY_AMOUNT') || define('WOOTIFY_STRIPE_BY_AMOUNT', ROTATION_METHOD_AMOUNT);
defined('OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY') || define('OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY', EP_ST_ACTIVE_NODE);
defined('OPT_WOOTIFY_STRIPE_CURRENT_ROTATION_VALUE') || define('OPT_WOOTIFY_STRIPE_CURRENT_ROTATION_VALUE', EP_ST_ROTATION_VALUE);
defined('OPT_WOOTIFY_STRIPE_UNUSED_PROXIES') || define('OPT_WOOTIFY_STRIPE_UNUSED_PROXIES', EP_ST_UNUSED_NODES);

function ep_stripe_reset_paid_amount_if_needed() {
    $proxies = get_option(EP_ST_NODES, []);
    if (empty($proxies)) {
        return [];
    }
    if (function_exists('shield_is_saas_connected') && shield_is_saas_connected()) {
        return $proxies;
    }
    $lastTimeReset = get_option(OPT_WOOTIFY_STRIPE_LAST_TIME_RESET_PAID_AMOUNT, null);
    // Reset
    if (empty($lastTimeReset) || date('Y-m-d') > $lastTimeReset) {
        return ep_stripe_reset_paid_amount($proxies);
    }
    return $proxies;
}

function ep_stripe_find_activated_proxy_data_by_id($proxies, $activatedProxyId) {
    foreach ($proxies as $proxy) {
        $proxyId = $proxy['nodeId'] ?? $proxy['id'] ?? null;
        if ($proxyId === $activatedProxyId) {
            return $proxy;
        }
    }
    return null;
}

function ep_stripe_get_next_proxy_amount_rotation($orderTotal) {
    $proxies = get_option(EP_ST_NODES, []);
    if (empty($proxies)) {
        return null;
    }
    $activatedProxy = get_option(EP_ST_ACTIVE_NODE, null);
    if (empty($activatedProxy)) {
        ep_stripe_error_log("Activated proxy not found! Use the first proxy of rotation list");
        $activatedProxy = $proxies[0];
    }
    $activatedProxyId = $activatedProxy['nodeId'] ?? $activatedProxy['id'] ?? null;
    $activatedProxy = ep_stripe_find_activated_proxy_data_by_id($proxies, $activatedProxyId);

    // Need to rotate proxy
    $isCurrentProxyMatched = false;
    foreach ($proxies as $proxy) {
        $proxyId = $proxy['nodeId'] ?? $proxy['id'] ?? null;
        $proxyVolumeLimit = $proxy['volumeLimit'] ?? $proxy['amount'] ?? 0;
        $proxyVolumeUsed = $proxy['volumeUsed'] ?? $proxy['paid_amount'] ?? 0;

        if ($activatedProxyId == $proxyId && !$isCurrentProxyMatched) {
            $isCurrentProxyMatched = true;
            continue;
        }
        if ($isCurrentProxyMatched && doubleval($proxyVolumeUsed) + doubleval($orderTotal) < doubleval($proxyVolumeLimit)) {
            return $proxy;
        }
    }
    foreach ($proxies as $proxy) {
        $proxyVolumeLimit = $proxy['volumeLimit'] ?? $proxy['amount'] ?? 0;
        $proxyVolumeUsed = $proxy['volumeUsed'] ?? $proxy['paid_amount'] ?? 0;
        if (doubleval($proxyVolumeUsed) + doubleval($orderTotal) < doubleval($proxyVolumeLimit)) {
            return $proxy;
        }
    }
    return null;
}

function ep_stripe_perform_proxy_amount_rotation($orderTotal) {
    $proxies = ep_stripe_reset_paid_amount_if_needed();
    if (empty($proxies)) {
        return null;
    }
    $activatedProxy = get_option(EP_ST_ACTIVE_NODE, null);
    if (empty($activatedProxy)) {
        ep_stripe_error_log("Activated proxy not found! Use the first proxy of rotation list");
        $activatedProxy = $proxies[0];
        update_option(EP_ST_ACTIVE_NODE, $activatedProxy, true);
        ep_stripe_log_rotation(ROTATION_METHOD_AMOUNT, $activatedProxy, "Auto");
    }
    $activatedProxyId = $activatedProxy['nodeId'] ?? $activatedProxy['id'] ?? null;
    $activatedProxy = ep_stripe_find_activated_proxy_data_by_id($proxies, $activatedProxyId);

    // Need to rotate proxy
    $isCurrentProxyMatched = false;
    foreach ($proxies as $proxy) {
        $proxyId = $proxy['nodeId'] ?? $proxy['id'] ?? null;
        $proxyVolumeLimit = $proxy['volumeLimit'] ?? $proxy['amount'] ?? 0;
        $proxyVolumeUsed = $proxy['volumeUsed'] ?? $proxy['paid_amount'] ?? 0;

        if ($activatedProxyId == $proxyId && !$isCurrentProxyMatched) {
            $isCurrentProxyMatched = true;
            continue;
        }
        if ($isCurrentProxyMatched && doubleval($proxyVolumeUsed) + doubleval($orderTotal) < doubleval($proxyVolumeLimit)) {
            $activatedProxy = $proxy;
            update_option(EP_ST_ACTIVE_NODE, $activatedProxy, true);
            ep_stripe_log_rotation(ROTATION_METHOD_AMOUNT, $activatedProxy, "Auto");
            return $activatedProxy;
        }
    }
    foreach ($proxies as $proxy) {
        $proxyVolumeLimit = $proxy['volumeLimit'] ?? $proxy['amount'] ?? 0;
        $proxyVolumeUsed = $proxy['volumeUsed'] ?? $proxy['paid_amount'] ?? 0;
        if (doubleval($proxyVolumeUsed) + doubleval($orderTotal) < doubleval($proxyVolumeLimit)) {
            $activatedProxy = $proxy;
            update_option(EP_ST_ACTIVE_NODE, $activatedProxy, true);
            ep_stripe_log_rotation(ROTATION_METHOD_AMOUNT, $activatedProxy, "Auto");
            return $activatedProxy;
        }
    }

    // All proxies exhausted — force reset paid_amount and restart from first proxy.
    $proxies = ep_stripe_reset_paid_amount();
    if (!empty($proxies)) {
        $activatedProxy = $proxies[0];
        update_option(EP_ST_ACTIVE_NODE, $activatedProxy, true);
        ep_stripe_log_rotation(ROTATION_METHOD_AMOUNT, $activatedProxy, "Reset+Auto");
        return $activatedProxy;
    }
    return null;
}

function ep_stripe_reset_paid_amount($proxies = null) {
    if (empty($proxies)) {
        $proxies = get_option(EP_ST_NODES, []);
    }
    if (empty($proxies)) return [];
    // Reset
    $newProxies = array_map(function ($proxy) {
        if (isset($proxy['volumeUsed'])) {
            $proxy['volumeUsed'] = 0;
        }
        if (isset($proxy['paid_amount'])) {
            $proxy['paid_amount'] = 0;
        }
        if (!isset($proxy['volumeUsed']) && !isset($proxy['paid_amount'])) {
            $proxy['volumeUsed'] = 0;
        }
        return $proxy;
    }, $proxies);
    $unusedProxies = get_option(EP_ST_UNUSED_NODES, []);
    if (empty($unusedProxies)) {
        $newUnusedProxies = [];
    } else {
        $newUnusedProxies = array_map(function ($unusedProxy) {
            if (isset($unusedProxy['volumeUsed'])) {
                $unusedProxy['volumeUsed'] = 0;
            }
            if (isset($unusedProxy['paid_amount'])) {
                $unusedProxy['paid_amount'] = 0;
            }
            if (!isset($unusedProxy['volumeUsed']) && !isset($unusedProxy['paid_amount'])) {
                $unusedProxy['volumeUsed'] = 0;
            }
            return $unusedProxy;
        }, $unusedProxies);
    }
    update_option(EP_ST_NODES, $newProxies, true);
    update_option(EP_ST_UNUSED_NODES, $newUnusedProxies, true);
    update_option(OPT_WOOTIFY_STRIPE_LAST_TIME_RESET_PAID_AMOUNT, date('Y-m-d'), true);
    return $newProxies;
}

function ep_stripe_log_rotation($rotationMethod, $proxy, $type) {
    $WOOTIFY_log_dir = wp_get_upload_dir()["basedir"] . '/wootify';
    if (!is_dir($WOOTIFY_log_dir)) {
        mkdir($WOOTIFY_log_dir, 0777, true);
    }
    $logFilePath = $WOOTIFY_log_dir . '/' . ep_stripe_get_log_file_name($rotationMethod);
    $currentDateTime             = date('Y-m-d H:i:s');
    $proxyVolumeLimit = $proxy['volumeLimit'] ?? $proxy['amount'] ?? 0;
    $proxyVolumeUsed = $proxy['volumeUsed'] ?? $proxy['paid_amount'] ?? 0;
    $rotationValue               = $rotationMethod === ROTATION_METHOD_TIME ? ($proxy['timestamp'] ?? '') : ($proxyVolumeUsed . '/' . $proxyVolumeLimit);
    $content                     = "{$currentDateTime} - {$proxy['url']} , {$rotationValue} - {$type}\n";

    $fp = fopen($logFilePath, 'a');
    fwrite($fp, $content);
    fclose($fp);
}

function ep_stripe_get_log_file_name($rotationMethod) {
    if ($rotationMethod === ROTATION_METHOD_TIME) {
        return 'endpoint_stripe_rotation_time_log.txt';
    } else if ($rotationMethod === ROTATION_METHOD_AMOUNT) {
        return 'endpoint_stripe_rotation_amount_log.txt';
    }
}

function ep_stripe_load_logs() {
    $WOOTIFY_log_dir = wp_get_upload_dir()["basedir"] . '/wootify';
    $logFilePath = $WOOTIFY_log_dir . '/stripe_rotation_log.txt';
    if (file_exists($logFilePath)) {
        $array = explode("\n", file_get_contents($logFilePath));
    } else {
        $array = array();
    }
    return $array;
}

function ep_stripe_is_enabled_amount_rotation() {
    return ROTATION_METHOD_AMOUNT === get_option(EP_ST_ROTATION_METHOD, ROTATION_METHOD_TIME);
}

function ep_stripe_update_rotation_amount($processedProxyId, $orderTotal) {
    $proxies = get_option(EP_ST_NODES, []);
    foreach ($proxies as $key => $proxy) {
        $proxyId = $proxy['nodeId'] ?? $proxy['id'] ?? null;
        if ($proxyId === $processedProxyId) {
            if (isset($proxy['volumeUsed'])) {
                $proxies[$key]['volumeUsed'] = doubleval($proxy['volumeUsed']) + doubleval($orderTotal);
            }
            if (isset($proxy['paid_amount'])) {
                $proxies[$key]['paid_amount'] = doubleval($proxy['paid_amount']) + doubleval($orderTotal);
            }
            if (!isset($proxy['volumeUsed']) && !isset($proxy['paid_amount'])) {
                $proxies[$key]['volumeUsed'] = doubleval($orderTotal);
            }
            break;
        }
    }
    $result = update_option(EP_ST_NODES, $proxies, true);
    
    if (class_exists('Shield_SaaS_Client')) {
        Shield_SaaS_Client::sync_stats_to_saas('Stripe');
    }
    
    return $result;
}

function ep_stripe_has_payable_proxy($cartTotal) {
    $proxies = ep_stripe_reset_paid_amount_if_needed();
    if (empty($proxies)) {
        return false;
    }

    foreach ($proxies as $proxy) {
        $proxyVolumeLimit = $proxy['volumeLimit'] ?? $proxy['amount'] ?? 0;
        $proxyVolumeUsed = $proxy['volumeUsed'] ?? $proxy['paid_amount'] ?? 0;
        if (doubleval($proxyVolumeUsed) + doubleval($cartTotal) < doubleval($proxyVolumeLimit)) {
            return true;
        }
    }
    return false;
}

function ep_stripe_error_log($data, $message = '') {
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    $caller = isset($trace[1]) ? $trace[1] : [];
    $dataLogString = ep_stripe_handle_data_log($data, $message)
        . print_r([$caller['class'] ?? '', $caller['function'] ?? '', []], true);
    if (!$logger = wc_get_logger()) {
        error_log($dataLogString);
    } else {
        $logger->debug($dataLogString, ['source' => 'cardshield-gateway-stripe-ERROR']);
    }
}

function ep_stripe_debug_log($data, $message = '') {
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    $caller = isset($trace[1]) ? $trace[1] : [];
    $dataLogString = ep_stripe_handle_data_log($data, $message)
        . print_r([$caller['class'] ?? '', $caller['function'] ?? '', []], true);
    if (!$logger = wc_get_logger()) {
        error_log($dataLogString);
    } else {
        $logger->debug($dataLogString, ['source' => 'cardshield-gateway-stripe-INFO']);
    }
}

function ep_stripe_generate_trace_id() {
    return function_exists('wp_generate_uuid4')
        ? wp_generate_uuid4()
        : uniqid('stripe-trace-', true);
}

function ep_stripe_handle_data_log($data, $message = '') {
    try {
        if (is_array($data) || is_object($data)) {
            $dataLog = print_r($data, true);
        } else {
            $dataLog = (string)$data;
        }
    } catch (\Exception $e) {
        $dataLog = 'csStripeLog ERROR: ' . $e->getMessage();
    }
    return '\n--------------------- ' . $message . ' ---------------------\n'
        . $dataLog;
}

function ep_stripe_move_to_unused_proxy_ids($proxyIds) {
    $proxies        = get_option(EP_ST_NODES, []);
    if (empty($proxies)) {
        $proxies = [];
    }
    $unusedProxies  = get_option(EP_ST_UNUSED_NODES, []);
    if (empty($unusedProxies)) {
        $unusedProxies = [];
    }
    foreach ($proxies as $key => $proxy) {
        $proxyId = $proxy['nodeId'] ?? $proxy['id'] ?? null;
        if (in_array($proxyId, $proxyIds)) {
            $unusedProxies[] = $proxy;
            unset($proxies[$key]);
        }
    }
    $isSuccess1 = update_option(EP_ST_NODES, array_values($proxies), true);
    $isSuccess2 = update_option(EP_ST_UNUSED_NODES, $unusedProxies, true);
    $proxies        = get_option(EP_ST_NODES, []);
    update_option(EP_ST_ACTIVE_NODE, isset($proxies[0]) ? $proxies[0] : null, true);
    return $isSuccess1 && $isSuccess2;
}

function ep_stripe_set_next_proxy_by_time_rotation() {
    $proxies = get_option(EP_ST_NODES, []);
    $activatedProxy = get_option(EP_ST_ACTIVE_NODE, null);
    if (empty($activatedProxy)) {
        ep_stripe_error_log("Activated proxy not found! Use the first proxy of rotation list[2]");
        $activatedProxy = $proxies[0];
        update_option(EP_ST_ACTIVE_NODE, $activatedProxy, true);
    }
    $activatedProxyId = $activatedProxy['nodeId'] ?? $activatedProxy['id'] ?? null;
    $activatedProxy = ep_stripe_find_activated_proxy_data_by_id($proxies, $activatedProxyId);

    // Need to rotate proxy
    $isCurrentProxyMatched = false;
    $activatedProxyId = is_array($activatedProxy) ? ($activatedProxy['nodeId'] ?? $activatedProxy['id'] ?? null) : null;

    foreach ($proxies as $proxy) {
        $proxyId = $proxy['nodeId'] ?? $proxy['id'] ?? null;
        if ($activatedProxyId !== null && $proxyId == $activatedProxyId && !$isCurrentProxyMatched) {
            $isCurrentProxyMatched = true;
            continue;
        }
        if ($isCurrentProxyMatched) {
            $activatedProxy = $proxy;
            update_option(EP_ST_ACTIVE_NODE, $activatedProxy, true);
            return $activatedProxy;
        }
    }
    
    $fallbackProxy = isset($proxies[0]) ? $proxies[0] : null;
    if ($fallbackProxy) {
        update_option(EP_ST_ACTIVE_NODE, $fallbackProxy, true);
    }
    return $fallbackProxy;
}


function ep_stripe_save_transaction_id(WC_Order $order, $transactionId) {
    $order->set_transaction_id($transactionId);
    $order->update_meta_data('_ep_stripe_transaction_id', $transactionId);
    $order->save();
}
function ep_stripe_get_transaction_id(WC_Order $order) {
    $id = $order->get_transaction_id();
    if (empty($id)) {
        $id = $order->get_meta('_ep_stripe_transaction_id');
    }
    return $id;
}
