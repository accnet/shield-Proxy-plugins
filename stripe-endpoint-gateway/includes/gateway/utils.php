<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Endpoint-mode signed request args helper.
 * Uses derivedKey from active node to sign requests to site1.
 */
if (!function_exists('ep_endpoint_signed_request_args')) {
    function ep_endpoint_signed_request_args($proxyOrUrl, $method, $url, $args = [], $bodyRaw = '') {
        if (!class_exists('Shield_Stripe_Endpoint_Client')) {
            return $args;
        }
        $derivedKey = '';
        if (is_array($proxyOrUrl) && !empty($proxyOrUrl['derivedKey'])) {
            $derivedKey = $proxyOrUrl['derivedKey'];
        } else {
            if (is_string($proxyOrUrl)) {
                $nodes = get_option(EP_ST_NODES, []);
                if (is_array($nodes)) {
                    foreach ($nodes as $node) {
                        if (trailingslashit($node['url']) === trailingslashit($proxyOrUrl)) {
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
// Also provide backwards-compat shim for shield_proxy_signed_request_args
if (!function_exists('shield_proxy_signed_request_args')) {
    function shield_proxy_signed_request_args($proxyOrUrl, $method, $url, $args = [], $bodyRaw = '') {
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
            if (is_array($proxyOrUrl) && !empty($proxyOrUrl['derivedKey'])) {
                $derivedKey = $proxyOrUrl['derivedKey'];
            } else {
                if (is_string($proxyOrUrl)) {
                    $nodes = get_option('EP_ST_NODES', []);
                    if (is_array($nodes)) {
                        foreach ($nodes as $node) {
                            if (trailingslashit($node['url']) === trailingslashit($proxyOrUrl)) {
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
            if (is_array($proxyOrUrl) && !empty($proxyOrUrl['derivedKey'])) {
                $derivedKey = $proxyOrUrl['derivedKey'];
            } else {
                if (is_string($proxyOrUrl)) {
                    $nodes = get_option('EP_PP_NODES', []);
                    if (is_array($nodes)) {
                        foreach ($nodes as $node) {
                            if (trailingslashit($node['url']) === trailingslashit($proxyOrUrl)) {
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

defined('OPT_WOOTIFY_STRIPE_VERSION') || define('OPT_WOOTIFY_STRIPE_VERSION', '2.2.13');
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

defined('METAKEY_EP_STRIPE_INTENT_AUTHORIZED') || define('METAKEY_EP_STRIPE_INTENT_AUTHORIZED', '_METAKEY_WOOTIFY_STRIPE_INTENT_CAPTURED');
defined('METAKEY_EP_STRIPE_PROXY_URL') || define('METAKEY_EP_STRIPE_PROXY_URL', '_endpoint_stripe_proxy_url');
defined('METAKEY_STRIPE_PROXY_ID') || define('METAKEY_STRIPE_PROXY_ID', '_endpoint_stripe_proxy_id');
defined('ROTATION_METHOD_TIME') || define('ROTATION_METHOD_TIME', 'by_time');
defined('ROTATION_METHOD_AMOUNT') || define('ROTATION_METHOD_AMOUNT', 'by_volume');

defined('METAKEY_CS_STRIPE_FEE') || define('METAKEY_CS_STRIPE_FEE', '_cs_stripe_fee');
defined('METAKEY_CS_STRIPE_PAYOUT') || define('METAKEY_CS_STRIPE_PAYOUT', '_cs_stripe_payout');
defined('METAKEY_CS_STRIPE_CURRENCY') || define('METAKEY_CS_STRIPE_CURRENCY', '_cs_stripe_currency');

// true: order currency
// false: stripe currency
defined('WOOTIFY_STRIPE_FEE_DISPLAY_ORDER_CURRENCY') || define('WOOTIFY_STRIPE_FEE_DISPLAY_ORDER_CURRENCY', true);

// Backwards compatibility for option page constants
defined('WOOTIFY_STRIPE_BY_TIME') || define('WOOTIFY_STRIPE_BY_TIME', ROTATION_METHOD_TIME);
defined('WOOTIFY_STRIPE_BY_AMOUNT') || define('WOOTIFY_STRIPE_BY_AMOUNT', ROTATION_METHOD_AMOUNT);
defined('OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY') || define('OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY', EP_ST_ACTIVE_NODE);
defined('OPT_WOOTIFY_STRIPE_CURRENT_ROTATION_VALUE') || define('OPT_WOOTIFY_STRIPE_CURRENT_ROTATION_VALUE', EP_ST_ROTATION_VALUE);
defined('OPT_WOOTIFY_STRIPE_UNUSED_PROXIES') || define('OPT_WOOTIFY_STRIPE_UNUSED_PROXIES', EP_ST_UNUSED_NODES);

function resetPaidAmountIfNeedStripe() {
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
        return resetPaidAmountStripe($proxies);
    }
    return $proxies;
}

function findActivatedProxyDataByIdStripe($proxies, $activatedProxyId) {
    foreach ($proxies as $proxy) {
        $proxyId = $proxy['nodeId'] ?? $proxy['id'] ?? null;
        if ($proxyId === $activatedProxyId) {
            return $proxy;
        }
    }
    return null;
}

function getNextProxyAmountRotationStripe($orderTotal) {
    $proxies = get_option(EP_ST_NODES, []);
    if (empty($proxies)) {
        return null;
    }
    $activatedProxy = get_option(EP_ST_ACTIVE_NODE, null);
    if (empty($activatedProxy)) {
        csStripeErrorLog("Activated proxy not found! Use the first proxy of rotation list");
        $activatedProxy = $proxies[0];
    }
    $activatedProxyId = $activatedProxy['nodeId'] ?? $activatedProxy['id'] ?? null;
    $activatedProxy = findActivatedProxyDataByIdStripe($proxies, $activatedProxyId);

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

function performProxyAmountRotationStripe($orderTotal) {
    $proxies = resetPaidAmountIfNeedStripe();
    if (empty($proxies)) {
        return null;
    }
    $activatedProxy = get_option(EP_ST_ACTIVE_NODE, null);
    if (empty($activatedProxy)) {
        csStripeErrorLog("Activated proxy not found! Use the first proxy of rotation list");
        $activatedProxy = $proxies[0];
        update_option(EP_ST_ACTIVE_NODE, $activatedProxy, true);
        logStripeRotation(ROTATION_METHOD_AMOUNT, $activatedProxy, "Auto");
    }
    $activatedProxyId = $activatedProxy['nodeId'] ?? $activatedProxy['id'] ?? null;
    $activatedProxy = findActivatedProxyDataByIdStripe($proxies, $activatedProxyId);

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
            logStripeRotation(ROTATION_METHOD_AMOUNT, $activatedProxy, "Auto");
            return $activatedProxy;
        }
    }
    foreach ($proxies as $proxy) {
        $proxyVolumeLimit = $proxy['volumeLimit'] ?? $proxy['amount'] ?? 0;
        $proxyVolumeUsed = $proxy['volumeUsed'] ?? $proxy['paid_amount'] ?? 0;
        if (doubleval($proxyVolumeUsed) + doubleval($orderTotal) < doubleval($proxyVolumeLimit)) {
            $activatedProxy = $proxy;
            update_option(EP_ST_ACTIVE_NODE, $activatedProxy, true);
            logStripeRotation(ROTATION_METHOD_AMOUNT, $activatedProxy, "Auto");
            return $activatedProxy;
        }
    }

    // All proxies exhausted — force reset paid_amount and restart from first proxy.
    $proxies = resetPaidAmountStripe();
    if (!empty($proxies)) {
        $activatedProxy = $proxies[0];
        update_option(EP_ST_ACTIVE_NODE, $activatedProxy, true);
        logStripeRotation(ROTATION_METHOD_AMOUNT, $activatedProxy, "Reset+Auto");
        return $activatedProxy;
    }
    return null;
}

function resetPaidAmountStripe($proxies = null) {
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

function logStripeRotation($rotationMethod, $proxy, $type) {
    $WOOTIFY_log_dir = wp_get_upload_dir()["basedir"] . '/wootify';
    if (!is_dir($WOOTIFY_log_dir)) {
        mkdir($WOOTIFY_log_dir, 0777, true);
    }
    $logFilePath = $WOOTIFY_log_dir . '/' . getStripeLogFileName($rotationMethod);
    $currentDateTime             = date('Y-m-d H:i:s');
    $proxyVolumeLimit = $proxy['volumeLimit'] ?? $proxy['amount'] ?? 0;
    $proxyVolumeUsed = $proxy['volumeUsed'] ?? $proxy['paid_amount'] ?? 0;
    $rotationValue               = $rotationMethod === ROTATION_METHOD_TIME ? ($proxy['timestamp'] ?? '') : ($proxyVolumeUsed . '/' . $proxyVolumeLimit);
    $content                     = "{$currentDateTime} - {$proxy['url']} , {$rotationValue} - {$type}\n";

    $fp = fopen($logFilePath, 'a');
    fwrite($fp, $content);
    fclose($fp);
}

function getStripeLogFileName($rotationMethod) {
    if ($rotationMethod === ROTATION_METHOD_TIME) {
        return 'endpoint_stripe_rotation_time_log.txt';
    } else if ($rotationMethod === ROTATION_METHOD_AMOUNT) {
        return 'endpoint_stripe_rotation_amount_log.txt';
    }
}

function loadStripeLogs() {
    $WOOTIFY_log_dir = wp_get_upload_dir()["basedir"] . '/wootify';
    $logFilePath = $WOOTIFY_log_dir . '/stripe_rotation_log.txt';
    if (file_exists($logFilePath)) {
        $array = explode("\n", file_get_contents($logFilePath));
    } else {
        $array = array();
    }
    return $array;
}

function isEnabledAmountRotationStripe() {
    return ROTATION_METHOD_AMOUNT === get_option(EP_ST_ROTATION_METHOD, ROTATION_METHOD_TIME);
}

function updateRotationAmountStripe($processedProxyId, $orderTotal) {
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

function hasPayableProxyStripe($cartTotal) {
    $proxies = resetPaidAmountIfNeedStripe();
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

function csStripeErrorLog($data, $message = '') {
    $trace = debug_backtrace();
    $dataLogString = csStripeHandleDataLog($data, $message)
        . print_r([$trace[1]['class'], $trace[1]['function'], $trace[1]['args']], true);
    if (!$logger = wc_get_logger()) {
        error_log($dataLogString);
    } else {
        $logger->debug($dataLogString, ['source' => 'cardshield-gateway-stripe-ERROR']);
    }
}

function csStripeDebugLog($data, $message = '') {
    $trace = debug_backtrace();
    $dataLogString = csStripeHandleDataLog($data, $message)
        . print_r([$trace[1]['class'], $trace[1]['function'], $trace[1]['args']], true);
    if (!$logger = wc_get_logger()) {
        error_log($dataLogString);
    } else {
        $logger->debug($dataLogString, ['source' => 'cardshield-gateway-stripe-INFO']);
    }
}

function csStripeGenerateTraceId() {
    return function_exists('wp_generate_uuid4')
        ? wp_generate_uuid4()
        : uniqid('stripe-trace-', true);
}

function csStripeHandleDataLog($data, $message = '') {
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

function stripeMoveToUnusedProxyIds($proxyIds) {
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

function setNextProxyByTimeRotation() {
    $proxies = get_option(EP_ST_NODES, []);
    $activatedProxy = get_option(EP_ST_ACTIVE_NODE, null);
    if (empty($activatedProxy)) {
        csStripeErrorLog("Activated proxy not found! Use the first proxy of rotation list[2]");
        $activatedProxy = $proxies[0];
        update_option(EP_ST_ACTIVE_NODE, $activatedProxy, true);
    }
    $activatedProxyId = $activatedProxy['nodeId'] ?? $activatedProxy['id'] ?? null;
    $activatedProxy = findActivatedProxyDataByIdStripe($proxies, $activatedProxyId);

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


function csStripeSaveTransactionId(WC_Order $order, $transactionId) {
    $order->set_transaction_id($transactionId);
    $order->update_meta_data('METAKEY_CS_TRANSACTION_ID', $transactionId);
    $order->save();
}
function csStripeGetTransactionId(WC_Order $order) {
    $id = $order->get_transaction_id();
    if (empty($id)) {
        $id = $order->get_meta('METAKEY_CS_TRANSACTION_ID');
    }
    return $id;
}
