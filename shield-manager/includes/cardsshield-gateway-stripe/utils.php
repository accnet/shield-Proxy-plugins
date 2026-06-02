<?php

if (! defined('ABSPATH')) {
    exit;
}

const OPT_WOOTIFY_STRIPE_VERSION = '2.2.13';
const OPT_WOOTIFY_STRIPE_PROXIES = 'OPT_WOOTIFY_STRIPE_PROXIES';
const OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY = 'OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY';
const OPT_WOOTIFY_STRIPE_ROTATION_METHOD =  'OPT_WOOTIFY_STRIPE_ROTATION_METHOD';
const OPT_WOOTIFY_STRIPE_UNUSED_PROXIES = 'OPT_WOOTIFY_STRIPE_UNUSED_PROXIES';
const OPT_WOOTIFY_STRIPE_CURRENT_ROTATION_VALUE = 'OPT_WOOTIFY_STRIPE_CURRENT_ROTATION_VALUE';
const MetaKey_Stripe_Proxy_Url = '_WOOTIFY_stripe_proxy_url';
const OPT_WOOTIFY_STRIPE_LAST_TIME_RESET_PAID_AMOUNT = 'OPT_WOOTIFY_STRIPE_LAST_TIME_RESET_PAID_AMOUNT';

const OPT_WOOTIFY_STRIPE_INTENT_CAPTURE = 'OPT_WOOTIFY_STRIPE_INTENT_CAPTURE';
const OPT_WOOTIFY_STRIPE_INTENT_AUTHORIZE = 'OPT_WOOTIFY_STRIPE_INTENT_AUTHORIZE';

const METAKEY_WOOTIFY_STRIPE_INTENT_AUTHORIZED = '_METAKEY_WOOTIFY_STRIPE_INTENT_CAPTURED';
const METAKEY_STRIPE_PROXY_URL          = '_WOOTIFY_stripe_proxy_url';
const METAKEY_STRIPE_PROXY_ID          = '_WOOTIFY_stripe_proxy_id';
const WOOTIFY_STRIPE_BY_TIME                       = "by_time";
const WOOTIFY_STRIPE_BY_AMOUNT                     = "by_amount";

const METAKEY_CS_STRIPE_FEE      = '_cs_stripe_fee';
const METAKEY_CS_STRIPE_PAYOUT   = '_cs_stripe_payout';
const METAKEY_CS_STRIPE_CURRENCY = '_cs_stripe_currency';

// true: order currency
// false: stripe currency
const WOOTIFY_STRIPE_FEE_DISPLAY_ORDER_CURRENCY = true;

function resetPaidAmountIfNeedStripe() {
    $proxies = get_option(OPT_WOOTIFY_STRIPE_PROXIES, []);
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
        if ($proxy['id'] === $activatedProxyId) {
            return $proxy;
        }
    }
    return null;
}

function getNextProxyAmountRotationStripe($orderTotal) {
    $proxies = get_option(OPT_WOOTIFY_STRIPE_PROXIES, []);
    if (empty($proxies)) {
        return null;
    }
    $activatedProxy = get_option(OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY, null);
    if (empty($activatedProxy)) {
        csStripeErrorLog("Activated proxy not found! Use the first proxy of rotation list");
        $activatedProxy = $proxies[0];
    }
    $activatedProxy = findActivatedProxyDataByIdStripe($proxies, $activatedProxy['id']);

    // Need to rotate proxy
    $isCurrentProxyMatched = false;
    foreach ($proxies as $proxy) {
        if ($activatedProxy['id'] == $proxy['id'] && !$isCurrentProxyMatched) {
            $isCurrentProxyMatched = true;
            continue;
        }
        if ($isCurrentProxyMatched && doubleval($proxy['paid_amount']) + doubleval($orderTotal) < doubleval($proxy['amount'])) {
            return $proxy;
        }
    }
    foreach ($proxies as $proxy) {
        if (doubleval($proxy['paid_amount']) + doubleval($orderTotal) < doubleval($proxy['amount'])) {
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
    $activatedProxy = get_option(OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY, null);
    if (empty($activatedProxy)) {
        csStripeErrorLog("Activated proxy not found! Use the first proxy of rotation list");
        $activatedProxy = $proxies[0];
        update_option(OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY, $activatedProxy, true);
        logStripeRotation(WOOTIFY_STRIPE_BY_AMOUNT, $activatedProxy, "Auto");
    }
    $activatedProxy = findActivatedProxyDataByIdStripe($proxies, $activatedProxy['id']);

    // Need to rotate proxy
    $isCurrentProxyMatched = false;
    foreach ($proxies as $proxy) {
        if ($activatedProxy['id'] == $proxy['id'] && !$isCurrentProxyMatched) {
            $isCurrentProxyMatched = true;
            continue;
        }
        if ($isCurrentProxyMatched && doubleval($proxy['paid_amount']) + doubleval($orderTotal) < doubleval($proxy['amount'])) {
            $activatedProxy = $proxy;
            update_option(OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY, $activatedProxy, true);
            logStripeRotation(WOOTIFY_STRIPE_BY_AMOUNT, $activatedProxy, "Auto");
            return $activatedProxy;
        }
    }
    foreach ($proxies as $proxy) {
        if (doubleval($proxy['paid_amount']) + doubleval($orderTotal) < doubleval($proxy['amount'])) {
            $activatedProxy = $proxy;
            update_option(OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY, $activatedProxy, true);
            logStripeRotation(WOOTIFY_STRIPE_BY_AMOUNT, $activatedProxy, "Auto");
            return $activatedProxy;
        }
    }

    // All proxies exhausted — force reset paid_amount and restart from first proxy.
    $proxies = resetPaidAmountStripe();
    if (!empty($proxies)) {
        $activatedProxy = $proxies[0];
        update_option(OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY, $activatedProxy, true);
        logStripeRotation(WOOTIFY_STRIPE_BY_AMOUNT, $activatedProxy, "Reset+Auto");
        return $activatedProxy;
    }
    return null;
}

function resetPaidAmountStripe($proxies = null) {
    if (empty($proxies)) {
        $proxies = get_option(OPT_WOOTIFY_STRIPE_PROXIES, []);
    }
    if (empty($proxies)) return [];
    // Reset
    $newProxies = array_map(function ($proxy) {
        $proxy['paid_amount'] = 0;
        return $proxy;
    }, $proxies);
    $unusedProxies = get_option(OPT_WOOTIFY_STRIPE_UNUSED_PROXIES, []);
    if (empty($unusedProxies)) {
        $newUnusedProxies = [];
    } else {
        $newUnusedProxies = array_map(function ($unusedProxy) {
            $unusedProxy['paid_amount'] = 0;
            return $unusedProxy;
        }, $unusedProxies);
    }
    update_option(OPT_WOOTIFY_STRIPE_PROXIES, $newProxies, true);
    update_option(OPT_WOOTIFY_STRIPE_UNUSED_PROXIES, $newUnusedProxies, true);
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
    $rotationValue               = $rotationMethod === WOOTIFY_STRIPE_BY_TIME ? $proxy['timestamp'] : ($proxy['paid_amount'] . '/' . $proxy['amount']);
    $content                     = "{$currentDateTime} - {$proxy['url']} , {$rotationValue} - {$type}\n";

    $fp = fopen($logFilePath, 'a');
    fwrite($fp, $content);
    fclose($fp);
}

function getStripeLogFileName($rotationMethod) {
    if ($rotationMethod === WOOTIFY_STRIPE_BY_TIME) {
        return 'WOOTIFY_stripe_rotation_time_log.txt';
    } else if ($rotationMethod === WOOTIFY_STRIPE_BY_AMOUNT) {
        return 'WOOTIFY_stripe_rotation_amount_log.txt';
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
    return WOOTIFY_STRIPE_BY_AMOUNT === get_option(OPT_WOOTIFY_STRIPE_ROTATION_METHOD, WOOTIFY_STRIPE_BY_TIME);
}

function updateRotationAmountStripe($processedProxyId, $orderTotal) {
    $proxies = get_option(OPT_WOOTIFY_STRIPE_PROXIES, []);
    foreach ($proxies as $key => $proxy) {
        if ($proxy['id'] === $processedProxyId) {
            $proxies[$key]['paid_amount'] = doubleval($proxy['paid_amount']) + doubleval($orderTotal);
            break;
        }
    }
    $result = update_option(OPT_WOOTIFY_STRIPE_PROXIES, $proxies, true);
    
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
        if (doubleval($proxy['paid_amount']) + doubleval($cartTotal) < doubleval($proxy['amount'])) {
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
    $proxies        = get_option(OPT_WOOTIFY_STRIPE_PROXIES, []);
    if (empty($proxies)) {
        $proxies = [];
    }
    $unusedProxies  = get_option(OPT_WOOTIFY_STRIPE_UNUSED_PROXIES, []);
    if (empty($unusedProxies)) {
        $unusedProxies = [];
    }
    foreach ($proxies as $key => $proxy) {
        if (in_array($proxy['id'], $proxyIds)) {
            $unusedProxies[] = $proxy;
            unset($proxies[$key]);
        }
    }
    $isSuccess1 = update_option(OPT_WOOTIFY_STRIPE_PROXIES, array_values($proxies), true);
    $isSuccess2 = update_option(OPT_WOOTIFY_STRIPE_UNUSED_PROXIES, $unusedProxies, true);
    $proxies        = get_option(OPT_WOOTIFY_STRIPE_PROXIES, []);
    update_option(OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY, isset($proxies[0]) ? $proxies[0] : null, true);
    return $isSuccess1 && $isSuccess2;
}

function setNextProxyByTimeRotation() {
    $proxies = get_option(OPT_WOOTIFY_STRIPE_PROXIES, []);
    $activatedProxy = get_option(OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY, null);
    if (empty($activatedProxy)) {
        csStripeErrorLog("Activated proxy not found! Use the first proxy of rotation list[2]");
        $activatedProxy = $proxies[0];
        update_option(OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY, $activatedProxy, true);
    }
    $activatedProxy = findActivatedProxyDataByIdStripe($proxies, $activatedProxy['id'] ?? null);

    // Need to rotate proxy
    $isCurrentProxyMatched = false;
    $activatedProxyId = is_array($activatedProxy) ? ($activatedProxy['id'] ?? null) : null;

    foreach ($proxies as $proxy) {
        if ($activatedProxyId !== null && $proxy['id'] == $activatedProxyId && !$isCurrentProxyMatched) {
            $isCurrentProxyMatched = true;
            continue;
        }
        if ($isCurrentProxyMatched) {
            $activatedProxy = $proxy;
            update_option(OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY, $activatedProxy, true);
            return $activatedProxy;
        }
    }
    
    $fallbackProxy = isset($proxies[0]) ? $proxies[0] : null;
    if ($fallbackProxy) {
        update_option(OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY, $fallbackProxy, true);
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

