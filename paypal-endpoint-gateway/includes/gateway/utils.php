<?php

/**
 * Endpoint-mode signed request args helper.
 * Replaces ep_paypal_signed_request_args() for endpoint gateway plugins.
 * Uses derivedKey from active node to sign requests to site1.
 */
if (!function_exists('ep_paypal_endpoint_signed_request_args')) {
    function ep_paypal_endpoint_signed_request_args($proxyOrUrl, $method, $url, $args = [], $bodyRaw = '') {
        if (!class_exists('Shield_PayPal_Endpoint_Client')) {
            return $args;
        }

        // Get derivedKey from active node or from proxy data
        $derivedKey = '';
        if (is_array($proxyOrUrl) && Shield_PayPal_Endpoint_Client::is_node_usable($proxyOrUrl)) {
            $derivedKey = $proxyOrUrl['derivedKey'];
        } else {
            if (is_string($proxyOrUrl)) {
                $nodes = get_option(EP_PP_NODES, []);
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

        if (empty($derivedKey)) {
            return $args;
        }

        $headers = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : [];
        $shieldId = is_array($proxyOrUrl) ? ($proxyOrUrl['shieldId'] ?? $proxyOrUrl['nodeId'] ?? $proxyOrUrl['id'] ?? '') : '';
        $signed = Shield_PayPal_Endpoint_Client::build_proxy_hmac_headers($derivedKey, $method, $url, (string) $bodyRaw, $shieldId);
        $args['headers'] = array_merge($headers, $signed);
        return $args;
    }
}

// Also provide backwards-compat shim for ep_paypal_signed_request_args
if (!function_exists('ep_paypal_signed_request_args')) {
    function ep_paypal_signed_request_args($proxyOrUrl, $method, $url, $args = [], $bodyRaw = '') {
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

if (!function_exists('ep_paypal_normalize_funding_source')) {
    function ep_paypal_normalize_funding_source($value) {
        $value = is_scalar($value) ? strtolower(trim((string) $value)) : '';
        if ($value === '') {
            return null;
        }

        $aliases = [
            'pay_pal' => 'paypal',
            'paypal_wallet' => 'paypal',
            'wallet' => 'paypal',
            'credit_card' => 'card',
            'debit_card' => 'card',
        ];

        if (isset($aliases[$value])) {
            $value = $aliases[$value];
        }

        $allowed = ['card', 'paypal', 'venmo', 'apple_pay', 'google_pay', 'paylater'];
        return in_array($value, $allowed, true) ? $value : null;
    }
}

if (!function_exists('ep_paypal_extract_funding_source_from_payment_source')) {
    function ep_paypal_extract_funding_source_from_payment_source($orderOrPaymentSource) {
        if (is_object($orderOrPaymentSource)) {
            $orderOrPaymentSource = (array) $orderOrPaymentSource;
        }
        if (!is_array($orderOrPaymentSource) || !$orderOrPaymentSource) {
            return null;
        }

        $paymentSource = null;
        $customId = null;

        if (isset($orderOrPaymentSource['payment_source'])) {
            $paymentSource = $orderOrPaymentSource['payment_source'];
        }

        if (isset($orderOrPaymentSource['purchase_units'])) {
            $purchaseUnits = $orderOrPaymentSource['purchase_units'];
            if (is_array($purchaseUnits) && !empty($purchaseUnits[0])) {
                $firstPU = $purchaseUnits[0];
                if (is_object($firstPU)) {
                    $firstPU = (array) $firstPU;
                }
                if (is_array($firstPU) && isset($firstPU['custom_id'])) {
                    $customId = $firstPU['custom_id'];
                }
            }
        }

        // Backward compatibility: if it doesn't look like an order, treat it as the payment_source itself
        if ($paymentSource === null && $customId === null) {
            $paymentSource = $orderOrPaymentSource;
        }

        if ($customId !== null) {
            $normalized = ep_paypal_normalize_funding_source($customId);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        if ($paymentSource !== null) {
            if (is_object($paymentSource)) {
                $paymentSource = (array) $paymentSource;
            }
            if (is_array($paymentSource)) {
                foreach (['card', 'paypal', 'venmo', 'apple_pay', 'google_pay', 'paylater'] as $candidate) {
                    if (!empty($paymentSource[$candidate])) {
                        return ep_paypal_normalize_funding_source($candidate);
                    }
                }

                foreach ($paymentSource as $candidate => $value) {
                    if (!empty($value)) {
                        $normalized = ep_paypal_normalize_funding_source($candidate);
                        if ($normalized !== null) {
                            return $normalized;
                        }
                    }
                }
            }
        }

        return null;
    }
}

defined('OPT_WOOTIFY_PAYPAL_VERSION') || define('OPT_WOOTIFY_PAYPAL_VERSION', '2.7.3');
defined('EP_PP_NODES') || define('EP_PP_NODES', 'EP_PP_NODES');
defined('EP_PP_UNUSED_NODES') || define('EP_PP_UNUSED_NODES', 'EP_PP_UNUSED_NODES');
defined('EP_PP_ACTIVE_NODE') || define('EP_PP_ACTIVE_NODE', 'EP_PP_ACTIVE_NODE');
defined('EP_PP_ROTATION_VALUE') || define('EP_PP_ROTATION_VALUE', 'EP_PP_ROTATION_VALUE');
defined('EP_PP_ROTATION_METHOD') || define('EP_PP_ROTATION_METHOD', 'EP_PP_ROTATION_METHOD');
defined('EP_PP_CONNECTION_MODE') || define('EP_PP_CONNECTION_MODE', 'EP_PP_CONNECTION_MODE');
defined('OPT_WOOTIFY_PAYPAL_LAST_TIME_RESET_PAID_AMOUNT') || define('OPT_WOOTIFY_PAYPAL_LAST_TIME_RESET_PAID_AMOUNT', 'OPT_WOOTIFY_PAYPAL_LAST_TIME_RESET_PAID_AMOUNT');

defined('METAKEY_EP_PAYPAL_PROXY_URL') || define('METAKEY_EP_PAYPAL_PROXY_URL', '_WOOTIFY_paypal_proxy_url');
defined('METAKEY_PAYPAL_PROXY_ID') || define('METAKEY_PAYPAL_PROXY_ID', '_WOOTIFY_paypal_proxy_id');
defined('METAKEY_EP_PAYPAL_SYNC_TRACKING_INFO') || define('METAKEY_EP_PAYPAL_SYNC_TRACKING_INFO', '_WOOTIFY_paypal_sync_tracking_info');
defined('METAKEY_PAYPAL_PROCESSING_ORDER_KEY') || define('METAKEY_PAYPAL_PROCESSING_ORDER_KEY', '_METAKEY_PAYPAL_PROCESSING_ORDER_KEY');

defined('METAKEY_CS_PAYPAL_FEE') || define('METAKEY_CS_PAYPAL_FEE', '_cs_paypal_fee');
defined('METAKEY_CS_PAYPAL_PAYOUT') || define('METAKEY_CS_PAYPAL_PAYOUT', '_cs_paypal_payout');
defined('METAKEY_CS_PAYPAL_CURRENCY') || define('METAKEY_CS_PAYPAL_CURRENCY', '_cs_paypal_currency');
defined('METAKEY_CS_PAYPAL_INTENT') || define('METAKEY_CS_PAYPAL_INTENT', '_cs_paypal_intent');
defined('METAKEY_EP_PAYPAL_CAPTURED') || define('METAKEY_EP_PAYPAL_CAPTURED', '_cs_paypal_captured');

defined('EP_PAYPAL_NOT_SYNCED') || define('EP_PAYPAL_NOT_SYNCED', 1);
defined('EP_PAYPAL_SYNCED') || define('EP_PAYPAL_SYNCED', 2);
defined('EP_PAYPAL_SYNC_ERROR') || define('EP_PAYPAL_SYNC_ERROR', 99);

defined('ROTATION_METHOD_TIME') || define('ROTATION_METHOD_TIME', 'by_time');
defined('ROTATION_METHOD_AMOUNT') || define('ROTATION_METHOD_AMOUNT', 'by_volume');
defined('EP_PP_ENDPOINT_TOKEN') || define('EP_PP_ENDPOINT_TOKEN', 'EP_PP_ENDPOINT_TOKEN');
defined('EP_PP_ENDPOINT_SECRET') || define('EP_PP_ENDPOINT_SECRET', 'EP_PP_ENDPOINT_SECRET');

defined('OPT_CS_PAYPAL_CONNECTION_MODE_SHIELD_DOMAINS') || define('OPT_CS_PAYPAL_CONNECTION_MODE_SHIELD_DOMAINS', 'shield_domains');
defined('EP_PP_MODE_ENDPOINT_TOKEN') || define('EP_PP_MODE_ENDPOINT_TOKEN', 'endpoint_token');

defined('OPT_CS_PAYPAL_AUTHORIZE') || define('OPT_CS_PAYPAL_AUTHORIZE', 'AUTHORIZE');
defined('OPT_CS_PAYPAL_CAPTURE') || define('OPT_CS_PAYPAL_CAPTURE', 'CAPTURE');

defined('OPT_CS_PAYPAL_SETTING_STANDARD') || define('OPT_CS_PAYPAL_SETTING_STANDARD', 'standard');
defined('OPT_CS_PAYPAL_SETTING_CHECKOUT') || define('OPT_CS_PAYPAL_SETTING_CHECKOUT', 'checkout');

defined('EP_TRACKING_SYNC_PLUGIN_ADVANCED_SHIPMENT_TRACKING') || define('EP_TRACKING_SYNC_PLUGIN_ADVANCED_SHIPMENT_TRACKING', 'opt_cs_tracking_sync_plugin_advanced_shipment_tracking');
defined('EP_TRACKING_SYNC_PLUGIN_ORDERS_TRACKING') || define('EP_TRACKING_SYNC_PLUGIN_ORDERS_TRACKING', 'opt_cs_tracking_sync_plugin_orders_tracking');
defined('EP_TRACKING_SYNC_PLUGIN_DIANXIAOMI') || define('EP_TRACKING_SYNC_PLUGIN_DIANXIAOMI', 'opt_cs_tracking_sync_plugin_dianxiaomi');
defined('EP_PP_GATEWAY_TYPE') || define('EP_PP_GATEWAY_TYPE', 1);

// Backwards compatibility for option page constants
defined('OPT_CS_PAYPAL_NOT_SYNCED') || define('OPT_CS_PAYPAL_NOT_SYNCED', EP_PAYPAL_NOT_SYNCED);
defined('OPT_CS_PAYPAL_SYNCED') || define('OPT_CS_PAYPAL_SYNCED', EP_PAYPAL_SYNCED);
defined('OPT_CS_PAYPAL_SYNC_ERROR') || define('OPT_CS_PAYPAL_SYNC_ERROR', EP_PAYPAL_SYNC_ERROR);
defined('OPT_CS_PAYPAL_BY_TIME') || define('OPT_CS_PAYPAL_BY_TIME', ROTATION_METHOD_TIME);
defined('OPT_CS_PAYPAL_BY_AMOUNT') || define('OPT_CS_PAYPAL_BY_AMOUNT', ROTATION_METHOD_AMOUNT);
defined('OPT_CS_PAYPAL_CONNECTION_MODE_ENDPOINT_TOKEN') || define('OPT_CS_PAYPAL_CONNECTION_MODE_ENDPOINT_TOKEN', EP_PP_MODE_ENDPOINT_TOKEN);
defined('OPT_CS_PAYPAL_ENDPOINT_TOKEN') || define('OPT_CS_PAYPAL_ENDPOINT_TOKEN', EP_PP_ENDPOINT_TOKEN);
defined('OPT_CS_PAYPAL_ENDPOINT_SECRET') || define('OPT_CS_PAYPAL_ENDPOINT_SECRET', EP_PP_ENDPOINT_SECRET);
defined('OPT_WOOTIFY_PAYPAL_CONNECTION_MODE') || define('OPT_WOOTIFY_PAYPAL_CONNECTION_MODE', EP_PP_CONNECTION_MODE);
defined('OPT_WOOTIFY_PAYPAL_UNUSED_PROXIES') || define('OPT_WOOTIFY_PAYPAL_UNUSED_PROXIES', EP_PP_UNUSED_NODES);
defined('OPT_WOOTIFY_PAYPAL_ACTIVATED_PROXY') || define('OPT_WOOTIFY_PAYPAL_ACTIVATED_PROXY', EP_PP_ACTIVE_NODE);
defined('OPT_WOOTIFY_PAYPAL_CURRENT_ROTATION_VALUE') || define('OPT_WOOTIFY_PAYPAL_CURRENT_ROTATION_VALUE', EP_PP_ROTATION_VALUE);
defined('METAKEY_PAYPAL_SYNC_TRACKING_INFO') || define('METAKEY_PAYPAL_SYNC_TRACKING_INFO', METAKEY_EP_PAYPAL_SYNC_TRACKING_INFO);

function ep_paypal_log_rotation($rotationMethod, $proxy, $type)
{
    $WOOTIFY_log_dir = wp_get_upload_dir()["basedir"] . '/wootify';
    if (!is_dir($WOOTIFY_log_dir)) {
        mkdir($WOOTIFY_log_dir, 0777, true);
    }
    $logFilePath = $WOOTIFY_log_dir . '/' . ep_paypal_get_log_file_name($rotationMethod);
    $currentDateTime             = date('Y-m-d H:i:s');
    $proxyVolumeLimit = $proxy['volumeLimit'] ?? $proxy['amount'] ?? 0;
    $proxyVolumeUsed = $proxy['volumeUsed'] ?? $proxy['paid_amount'] ?? 0;
    $rotationValue               = $rotationMethod === ROTATION_METHOD_TIME ? ($proxy['timestamp'] ?? '') : ( $proxyVolumeUsed . '/' . $proxyVolumeLimit);
    $content                     = "{$currentDateTime} - {$proxy['url']} , {$rotationValue} - {$type}\n";

    $fp = fopen($logFilePath, 'a');
    fwrite($fp, $content);
    fclose($fp);
}

function ep_paypal_load_logs($rotationMethod)
{
    $wootifyLogDir = wp_get_upload_dir()["basedir"] . '/wootify';
    $logFilePath = $wootifyLogDir . '/' . ep_paypal_get_log_file_name($rotationMethod);
    if (!file_exists($logFilePath)) {
        return [];
    }
    $logArr = explode("\n", file_get_contents($logFilePath));
    // reverse order
    $logArr = array_reverse($logArr);
    $result = [];
    foreach($logArr as $log) {
        if (empty($log)) continue;
        list($time, $proxy, $trigger) = explode(" - ", $log);
        list($proxyUrl, $rotationValue) = explode(" , ", $proxy);
        $result[] = [
            'time' => $time,
            'proxyUrl' => $proxyUrl,
            'rotationValue' => $rotationValue,
            'trigger' => $trigger
        ];
    }
    return $result;

}

function ep_paypal_clear_logs($rotationMethod)
{
    $wootifyLogDir = wp_get_upload_dir()["basedir"] . '/wootify';
    if (!is_dir($wootifyLogDir)) {
        mkdir($wootifyLogDir, 0777, true);
    }

    $logFilePath = $wootifyLogDir . '/' . ep_paypal_get_log_file_name($rotationMethod);
    $fp = fopen($logFilePath, 'w');
    fwrite($fp, "");
    fclose($fp);
    return empty(file_get_contents($logFilePath));
}

function ep_paypal_get_log_file_name($rotationMethod) {
    if ( $rotationMethod === ROTATION_METHOD_TIME) {
        return 'WOOTIFY_paypal_rotation_time_log.txt';
    } else if ( $rotationMethod === ROTATION_METHOD_AMOUNT) {
        return 'WOOTIFY_paypal_rotation_amount_log.txt';
    }
}

function ep_paypal_is_enabled_amount_rotation() {
    return ROTATION_METHOD_AMOUNT === get_option(EP_PP_ROTATION_METHOD, ROTATION_METHOD_TIME);
}

function ep_paypal_get_gateway_settings() {
    $settings = get_option('woocommerce_endpoint_paypal_settings', []);
    if (!empty($settings) && is_array($settings)) {
        return $settings;
    }

    return [];
}

function ep_paypal_generate_random_string($length = 10) {
    $length = max(1, (int) $length);
    $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[random_int(0, $charactersLength - 1)];
    }
    return $randomString;
}

function ep_paypal_get_product_last_word($productTitle) {
    $productTitle = trim((string) $productTitle);
    if ($productTitle === '') {
        return '';
    }

    $parts = preg_split('/\s+/', $productTitle);
    return $parts ? (string) end($parts) : $productTitle;
}

function ep_paypal_get_order_item_variants($item = null) {
    if (!$item || !is_object($item) || !method_exists($item, 'get_id')) {
        return '';
    }

    $variants = [];
    $wcpaMeta = wc_get_order_item_meta($item->get_id(), '_WCPA_order_meta_data', true);
    if (is_array($wcpaMeta)) {
        foreach ($wcpaMeta as $meta) {
            if (!isset($meta['value'])) {
                continue;
            }
            $value = $meta['value'];
            if (is_array($value)) {
                $first = reset($value);
                if (is_array($first) && isset($first['label'])) {
                    $variants[] = $first['label'];
                } elseif (isset($meta['label'])) {
                    $variants[] = $meta['label'];
                }
            } elseif (is_scalar($value)) {
                $variants[] = (string) $value;
            }
        }
    }

    foreach ($item->get_meta_data() as $meta) {
        $data = $meta->get_data();
        $key = isset($data['key']) ? (string) $data['key'] : '';
        $value = $data['value'] ?? '';
        if ($key === '' || strpos($key, '_') === 0 || $key === '_WCPA_order_meta_data') {
            continue;
        }
        if (is_scalar($value)) {
            $variants[] = (string) $value;
        }
    }

    $variants = array_values(array_filter(array_map('trim', $variants), 'strlen'));
    return implode(' - ', array_unique($variants));
}

function ep_paypal_resolve_product_title($productTitle, $orderId, $item = null, $settings = null) {
    $settings = is_array($settings) ? $settings : ep_paypal_get_gateway_settings();
    $mode = $settings['product_title_setting'] ?? 'last_word';
    $productTitle = trim(wp_strip_all_tags((string) $productTitle));

    if ($mode === 'keep_original') {
        return $productTitle;
    }

    if ($mode !== 'user_define') {
        $lastWord = ep_paypal_get_product_last_word($productTitle);
        return $lastWord !== '' ? $lastWord : $productTitle;
    }

    $title = isset($settings['user_define_product_title']) ? (string) $settings['user_define_product_title'] : '';
    if (trim($title) === '') {
        return $productTitle;
    }

    $price = ($item && is_object($item) && method_exists($item, 'get_subtotal')) ? (float) $item->get_subtotal() : 0.0;
    $replacements = [
        '[order_id]' => (string) $orderId,
        '[last_word]' => ep_paypal_get_product_last_word($productTitle),
        '[variants]' => ep_paypal_get_order_item_variants($item),
    ];

    $title = strtr($title, $replacements);

    $title = preg_replace_callback('/\[rand_(\d+)\]/', function ($matches) {
        return ep_paypal_generate_random_string((int) $matches[1]);
    }, $title);

    $title = preg_replace_callback('/\[str:(\d+)\]/', function ($matches) {
        return ep_paypal_generate_random_string((int) $matches[1]);
    }, $title);

    $title = preg_replace_callback('/\[random:([^\]]+)\]/', function ($matches) {
        $values = array_values(array_filter(array_map('trim', explode('|', $matches[1])), 'strlen'));
        if (empty($values)) {
            return '';
        }
        return $values[array_rand($values)];
    }, $title);

    $title = preg_replace_callback('/\[by_price:([^\]]+)\]/', function ($matches) use ($price) {
        $conditions = explode('|', $matches[1]);
        foreach ($conditions as $condition) {
            $parts = explode('=', $condition, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $range = array_map('floatval', explode('-', $parts[0], 2));
            if (count($range) !== 2) {
                continue;
            }
            if ($range[0] <= $price && $price < $range[1]) {
                return trim($parts[1]);
            }
        }
        return '';
    }, $title);

    $title = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($title)));
    return $title !== '' ? $title : $productTitle;
}

function ep_paypal_reset_paid_amount_if_needed() {
    $proxies = get_option(EP_PP_NODES, []);
    if (empty($proxies)) {
        return [];
    }
    if (function_exists('shield_is_saas_connected') && shield_is_saas_connected()) {
        return $proxies;
    }
    $lastTimeReset = get_option(OPT_WOOTIFY_PAYPAL_LAST_TIME_RESET_PAID_AMOUNT, null);
    // Reset
    if (empty($lastTimeReset) || date('Y-m-d') > $lastTimeReset) {
        return ep_paypal_reset_paid_amount($proxies);
    }
    return $proxies;
}

function ep_paypal_reset_paid_amount($proxies = null)
{
    if (empty($proxies)) {
        $proxies = get_option( EP_PP_NODES, [] );
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
    $unusedProxies = get_option(EP_PP_UNUSED_NODES, []);
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
    update_option(EP_PP_NODES, $newProxies, true);
    update_option(EP_PP_UNUSED_NODES, $newUnusedProxies, true);
    update_option(OPT_WOOTIFY_PAYPAL_LAST_TIME_RESET_PAID_AMOUNT, date('Y-m-d'), true);
    return $newProxies;
}

function ep_paypal_find_activated_proxy_data_by_id($proxies, $activatedProxyId) {
    foreach ($proxies as $proxy) {
        $proxyId = $proxy['nodeId'] ?? $proxy['id'] ?? null;
        if ($proxyId == $activatedProxyId) {
            return $proxy;
        }
    }
    return null;
}

function ep_paypal_get_next_proxy($currentProxyId) {
    $proxies = get_option( EP_PP_NODES, [] );
    if (empty($proxies)) return null;
    $lastProxy = end($proxies);
    $lastProxyId = $lastProxy['nodeId'] ?? $lastProxy['id'] ?? null;
    if ($currentProxyId == $lastProxyId) {
        return $proxies[0];
    }

    foreach ($proxies as $key => $proxy) {
        $proxyId = $proxy['nodeId'] ?? $proxy['id'] ?? null;
        if ($proxyId == $currentProxyId) {
            return $proxies[$key + 1];
        }
    }
    return null;
}

function ep_paypal_get_next_proxy_amount_rotation($activatedProxy, $orderTotal) {
    $proxies = ep_paypal_reset_paid_amount_if_needed();
    if ( empty( $proxies ) ) {
        return null;
    }
    $isCurrentProxyMatched = false;
    $activatedProxyId = $activatedProxy['nodeId'] ?? $activatedProxy['id'] ?? null;
    foreach ( $proxies as $proxy ) {
        $proxyId = $proxy['nodeId'] ?? $proxy['id'] ?? null;
        if ( $activatedProxyId == $proxyId && !$isCurrentProxyMatched) {
            $isCurrentProxyMatched = true;
            continue;
        }
        if ($isCurrentProxyMatched && ep_paypal_is_payable_proxy( $proxy, $orderTotal ) ) {
            return $proxy;
        }
    }
    foreach ( $proxies as $proxy ) {
        if ( ep_paypal_is_payable_proxy( $proxy, $orderTotal ) ) {
            return $proxy;
        }
    }

    return null;
}

function ep_paypal_perform_proxy_amount_rotation($activatedProxy, $orderTotal) {
    $proxies = ep_paypal_reset_paid_amount_if_needed();
    if ( empty( $proxies ) ) {
        return null;
    }
    $isCurrentProxyMatched = false;
    $activatedProxyId = $activatedProxy['nodeId'] ?? $activatedProxy['id'] ?? null;
    foreach ( $proxies as $proxy ) {
        $proxyId = $proxy['nodeId'] ?? $proxy['id'] ?? null;
        if ( $activatedProxyId == $proxyId  && !$isCurrentProxyMatched) {
            $isCurrentProxyMatched = true;
            continue;
        }
        if ($isCurrentProxyMatched && ep_paypal_is_payable_proxy( $proxy, $orderTotal ) ) {
            $activatedProxy = $proxy;
            update_option( EP_PP_ACTIVE_NODE, $activatedProxy, true );
            ep_paypal_log_rotation( ROTATION_METHOD_AMOUNT, $activatedProxy, "Auto" );

            return $activatedProxy;
        }
    }
    foreach ( $proxies as $proxy ) {
        if ( ep_paypal_is_payable_proxy( $proxy, $orderTotal ) ) {
            $activatedProxy = $proxy;
            update_option( EP_PP_ACTIVE_NODE, $activatedProxy, true );
            ep_paypal_log_rotation( ROTATION_METHOD_AMOUNT, $activatedProxy, "Auto" );

            return $activatedProxy;
        }
    }

    // All proxies exhausted — force reset paid_amount and restart from first proxy.
    $proxies = ep_paypal_reset_paid_amount();
    if ( !empty( $proxies ) ) {
        $activatedProxy = $proxies[0];
        update_option( EP_PP_ACTIVE_NODE, $activatedProxy, true );
        ep_paypal_log_rotation( ROTATION_METHOD_AMOUNT, $activatedProxy, "Reset+Auto" );
        return $activatedProxy;
    }
    return null;
}

function ep_paypal_perform_proxy_by_time_rotation($activatedProxy) {
    $proxies = get_option( EP_PP_NODES, [] );
    if ( empty( $proxies ) ) {
        return null;
    }
    $isCurrentProxyMatched = false;
    $activatedProxyId = $activatedProxy['nodeId'] ?? $activatedProxy['id'] ?? null;
    foreach ( $proxies as $proxy ) {
        $proxyId = $proxy['nodeId'] ?? $proxy['id'] ?? null;
        if ( $activatedProxyId == $proxyId  && !$isCurrentProxyMatched) {
            $isCurrentProxyMatched = true;
            continue;
        }
        if ($isCurrentProxyMatched) {
            $activatedProxy = $proxy;
            update_option( EP_PP_ACTIVE_NODE, $activatedProxy, true );
            ep_paypal_log_rotation( ROTATION_METHOD_TIME, $activatedProxy, "Auto" );

            return $activatedProxy;
        }
    }
    $activatedProxy = $proxies[0];
    update_option( EP_PP_ACTIVE_NODE, $activatedProxy, true );
    ep_paypal_log_rotation( ROTATION_METHOD_TIME, $activatedProxy, "Auto" );
    return $activatedProxy;
}

function ep_paypal_is_payable_proxy($proxy, $orderTotal) {
    $proxyVolumeLimit = $proxy['volumeLimit'] ?? $proxy['amount'] ?? 0;
    $proxyVolumeUsed = $proxy['volumeUsed'] ?? $proxy['paid_amount'] ?? 0;
    return doubleval( $proxyVolumeUsed ) + doubleval( $orderTotal ) < doubleval($proxyVolumeLimit);
}

function ep_paypal_has_payable_proxy($orderTotal) {
    $proxies = ep_paypal_reset_paid_amount_if_needed();
    if (empty($proxies)) {
        return false;
    }

    foreach ($proxies as $proxy) {
        if(ep_paypal_is_payable_proxy($proxy, $orderTotal)) {
            return true;
        }
    }
    return false;
}

function ep_paypal_update_rotation_amount($proxyId, $orderTotal) {
    $activatedProxy = get_option( EP_PP_ACTIVE_NODE, null );
    $activatedProxyId = $activatedProxy['nodeId'] ?? $activatedProxy['id'] ?? null;
    if ($activatedProxyId == $proxyId) {
        if (isset($activatedProxy['volumeUsed'])) {
            $activatedProxy['volumeUsed'] = doubleval($activatedProxy['volumeUsed']) + doubleval( $orderTotal );
        }
        if (isset($activatedProxy['paid_amount'])) {
            $activatedProxy['paid_amount'] = doubleval($activatedProxy['paid_amount']) + doubleval( $orderTotal );
        }
        if (!isset($activatedProxy['volumeUsed']) && !isset($activatedProxy['paid_amount'])) {
            $activatedProxy['volumeUsed'] = doubleval($orderTotal);
        }
        update_option(EP_PP_ACTIVE_NODE, $activatedProxy, true);
    }
    $proxies = get_option(EP_PP_NODES, []);
    foreach ($proxies as $key => $proxy) {
        $currProxyId = $proxy['nodeId'] ?? $proxy['id'] ?? null;
        if ($currProxyId === $proxyId) {
            if (isset($proxies[$key]['volumeUsed'])) {
                $proxies[$key]['volumeUsed'] = doubleval($proxy['volumeUsed']) + doubleval( $orderTotal );
            }
            if (isset($proxies[$key]['paid_amount'])) {
                $proxies[$key]['paid_amount'] = doubleval($proxy['paid_amount']) + doubleval( $orderTotal );
            }
            if (!isset($proxies[$key]['volumeUsed']) && !isset($proxies[$key]['paid_amount'])) {
                $proxies[$key]['volumeUsed'] = doubleval($orderTotal);
            }
            break;
        }
    }
    $result = update_option(EP_PP_NODES, $proxies, true);

    if (class_exists('Shield_SaaS_Client')) {
        Shield_SaaS_Client::sync_stats_to_saas('PayPal');
    }

    return $result;
}

function ep_paypal_get_enabled_payment_gateways() {
    $gateways        = WC()->payment_gateways->get_available_payment_gateways();
    $enabledGateways = [];
    if ( $gateways ) {
        foreach ( $gateways as $gateway ) {

            if ( $gateway->enabled == 'yes' ) {

                $enabledGateways[] = $gateway;

            }
        }
    }
    return $enabledGateways;
}

function ep_paypal_get_browser_fingerprint() {
    $browserData = (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '' ) . '|' .
                   (isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '') . '|' .
                   (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '');
    return sha1($browserData);
}

function ep_paypal_log($error, $message = '') {
    $logger = wc_get_logger();
    if (is_array($error) || is_object($error)) {
        $error = json_encode($error);
    }
    $error = '\n--------------------- ' . $message . ' ---------------------\n'
             . $error .
             '\n------------------------------------------\n';
    if (empty($logger)) {
        error_log($error);
    } else {
        $logger->debug( $error, [ 'source' => 'cardsshield-gateway-paypal' ] );
    }

}

function ep_paypal_move_to_unused_proxy_ids($proxyIds) {
    $proxies        = get_option( EP_PP_NODES, [] );
    if (empty($proxies)) {
        $proxies = [];
    }
    $unusedProxies  = get_option( EP_PP_UNUSED_NODES, [] );
    if (empty($unusedProxies)) {
        $unusedProxies = [];
    }
    foreach ( $proxies as $key => $proxy ) {
        $proxyId = $proxy['nodeId'] ?? $proxy['id'] ?? null;
        if ( in_array( $proxyId, $proxyIds ) ) {
            $unusedProxies[] = $proxy;
            unset( $proxies[ $key ] );
        }
    }
    $isSuccess1 = update_option( EP_PP_NODES, array_values($proxies), true );
    $isSuccess2 = update_option( EP_PP_UNUSED_NODES, $unusedProxies, true );
    $proxies        = get_option( EP_PP_NODES, [] );
    if(isset($proxies[0])) {
        update_option( EP_PP_ACTIVE_NODE, $proxies[0], true );
    }
    return $isSuccess1 && $isSuccess2;
}

function ep_paypal_move_to_unused_proxy_ids_restrict_account($proxyIds) {
    $proxies        = get_option( EP_PP_NODES, [] );
    if (empty($proxies)) {
        $proxies = [];
    }
    $unusedProxies  = get_option( EP_PP_UNUSED_NODES, [] );
    if (empty($unusedProxies)) {
        $unusedProxies = [];
    }
    foreach ( $proxies as $key => $proxy ) {
        $proxyId = $proxy['nodeId'] ?? $proxy['id'] ?? null;
        if ( in_array( $proxyId, $proxyIds ) ) {
            $unusedProxies[] = $proxy;
            unset( $proxies[ $key ] );
        }
    }
    $isSuccess1 = update_option( EP_PP_NODES, array_values($proxies), true );
    $isSuccess2 = update_option( EP_PP_UNUSED_NODES, $unusedProxies, true );
    return $isSuccess1 && $isSuccess2;
}

function ep_paypal_count_order_need_sync() {
    if (get_option('woocommerce_custom_orders_table_enabled') === 'yes') {
        $results = ep_paypal_query_order_need_sync_hpos('count');
    } else {
        $results = ep_paypal_query_order_need_sync('count');
    }
    return $results[0]['count'] ?? 0;
}

require_once(plugin_dir_path(__FILE__) . 'cs-woocommerce-orders-tracking/includes/admin/paypal.php');
require_once(plugin_dir_path(__FILE__) . 'cs-woocommerce-orders-tracking/includes/data.php');
require_once(plugin_dir_path(__FILE__) . 'cs-advance-shipment-tracking/cs-advance-shipping-tracking-provider.php');

function ep_paypal_sync_tracking_info() {
    $csPayPalGw = WC()->payment_gateways->payment_gateways()['endpoint_paypal'];
    $trackingSyncPlugin = $csPayPalGw->get_option('sync_tracking_plugin');
    if (get_option('woocommerce_custom_orders_table_enabled') === 'yes') {
        $orders = ep_paypal_query_order_need_sync_hpos('get');
    } else {
        $orders = ep_paypal_query_order_need_sync('get');
    }
    $hasError = false;
    $errorOrderIdList = [];
    $shippingData = [];

    foreach ($orders as $order) {
        $orderId = $order['order_id'];
        $order = wc_get_order($orderId);
        $orderPostalCode = $order->get_shipping_postcode();
        $trackingNumberArr = [];

        $processedProxyUrl = $order->get_meta( METAKEY_EP_PAYPAL_PROXY_URL );
        if ( empty( $processedProxyUrl ) ) {
            ep_paypal_error_log('Sync error: Empty proxy url, order_id: ' . $orderId);
            $hasError       = true;
            $errorOrderIdList[] = $orderId;
            $order->update_meta_data( METAKEY_EP_PAYPAL_SYNC_TRACKING_INFO, EP_PAYPAL_SYNC_ERROR );
            $order->save_meta_data();
            continue;
        }
        if ($order->get_status() === 'on-hold') {
            continue;
        }

        if ($trackingSyncPlugin == EP_TRACKING_SYNC_PLUGIN_ADVANCED_SHIPMENT_TRACKING) {
            $trackingItems = $order->get_meta('_wc_shipment_tracking_items');
            if (empty($trackingItems)) {
                continue;
            }
            foreach ($trackingItems as $trackingItem) {
                if (empty($trackingItem['tracking_number'])) {
                    continue;
                }
                if (in_array($trackingItem['tracking_number'], $trackingNumberArr)) {
                    continue;
                }
                $trackingNumberArr[] = $trackingItem['tracking_number'];
                $ppProvider = EP_PayPal_Advance_Shipping_Tracking_Provider::get_instance()->getPaypalProvider($trackingItem['tracking_provider']);
                $trackingData = [
                    'order_id' => $orderId,
                    'transaction_id' => ep_paypal_get_transaction_id($order),
                    'tracking_number' => $trackingItem['tracking_number'],
                    'status' => 'SHIPPED',
                    'carrier' => $ppProvider->paypal_slug,
                ];
                if (isset($ppProvider->paypal_slug) && '' != $ppProvider->paypal_slug) {
                    $trackingData['carrier'] = $ppProvider->paypal_slug;
                    if (isset($ppProvider->provider_url) && '' != $ppProvider->provider_url) {
                        $trackingData['tracking_url'] = str_replace('%number%', $trackingItem['tracking_number'], $ppProvider->provider_url);
                    }
                } else {
                    $trackingData['carrier'] = 'OTHER';
                    $trackingData['carrier_name_other'] = $trackingItem['tracking_provider'];
                }
                $shippingData[$processedProxyUrl][] = $trackingData;
            }
        } else if ($trackingSyncPlugin == EP_TRACKING_SYNC_PLUGIN_ORDERS_TRACKING) {
            if($trackingDataByOrder = $order->get_meta('_wot_tracking_number')) {
                $carrierSlug = $order->get_meta('_wot_tracking_carrier');
                $carrierPaypalName = EP_PayPal_Orders_Tracking_Admin::get_paypal_carrier_by_slug($carrierSlug);
                $trackingData = [
                    'order_id' => $orderId,
                    'transaction_id' => ep_paypal_get_transaction_id($order),
                    'tracking_number' => $trackingDataByOrder,
                    'status' => 'SHIPPED',
                    'carrier' => $carrierPaypalName,
                ];
                $trackingUrl = null;
                try {
                    $itemTrackingData = EP_PayPal_Orders_Tracking_Data::get_instance()->get_item_tracking_number($orderId);
                    $trackingUrl = $itemTrackingData['tracking_url_show'] ?? null;
                    ep_paypal_debug_log($itemTrackingData, 'Sync get tracking URL  $itemTrackingData');
                } catch (\Exception $e) {
                    ep_paypal_debug_log($e->getMessage(), 'Sync get tracking URL exception!');
                }
                if (!empty($trackingUrl)) {
                    $trackingData['tracking_url'] = $trackingUrl;
                }
                if ('OTHER' === $carrierPaypalName) {
                    $trackingData['carrier_name_other'] = $carrierSlug;
                }
                $shippingData[$processedProxyUrl][] = $trackingData;
            } else {
                foreach ( $order->get_items() as $item_id => $item_value ) {
                    $item_tracking_data    = wc_get_order_item_meta( $item_id, '_vi_wot_order_item_tracking_data', true );
                    if ( empty($item_tracking_data )) {
                        continue;
                    }
                    $item_tracking_data    = json_decode( $item_tracking_data, true );
                    $current_tracking_data = array_pop( $item_tracking_data );
                    if (in_array($current_tracking_data['tracking_number'], $trackingNumberArr)) {
                        continue;
                    }
                    $trackingNumberArr[] = $current_tracking_data['tracking_number'];
                    $carrierName = $current_tracking_data['carrier_name'];
                    $carrierPaypalName = EP_PayPal_Orders_Tracking_Admin::get_carrier($carrierName);
                    $trackingData = [
                        'order_id'           => $orderId,
                        'transaction_id'     => ep_paypal_get_transaction_id($order),
                        'tracking_number'    => $current_tracking_data['tracking_number'],
                        'status'             => 'SHIPPED',
                        'carrier'            => $carrierPaypalName,
                    ];
                    if ('OTHER' === $carrierPaypalName) {
                        $trackingData['carrier_name_other'] = $carrierName;
                    }
                    $trackingUrl = null;
                    try {
                        $itemTrackingData = EP_PayPal_Orders_Tracking_Data::get_instance()->get_item_tracking_number($orderId, $item_id);
                        $trackingUrl = $itemTrackingData['tracking_url_show'] ?? null;
                        ep_paypal_debug_log($itemTrackingData, 'Sync get tracking URL  $itemTrackingData');
                    } catch (\Exception $e) {
                        ep_paypal_debug_log($e->getMessage(), 'Sync get tracking URL exception!');
                    }
                    if (!empty($trackingUrl)) {
                        $trackingData['tracking_url'] = $trackingUrl;
                    }
                    $shippingData[$processedProxyUrl][] = $trackingData;
                }
            }
        } else if ($trackingSyncPlugin == EP_TRACKING_SYNC_PLUGIN_DIANXIAOMI) {
            $trackingProvider = $order->get_meta('_dianxiaomi_tracking_provider_name');
            $trackingNumber = $order->get_meta('_dianxiaomi_tracking_number');
            if ( empty( $trackingProvider ) || empty($trackingNumber) ) {
                continue;
            }
            $shippingData[$processedProxyUrl][] = [
                'order_id'           => $orderId,
                'transaction_id'     => ep_paypal_get_transaction_id($order),
                'tracking_number'    => $trackingNumber,
                'status'             => 'SHIPPED',
                'carrier'            => 'OTHER',
                'carrier_name_other' => $trackingProvider,
            ];
        }
    }
    ep_paypal_debug_log($shippingData, 'Sync data $shippingData INFO');
    foreach ($shippingData as $proxyUrl => $shippingDataBatch) {
        if (isset($shippingDataBatch) && count($shippingDataBatch)) {
            $shippingDataParts = array_chunk($shippingDataBatch, 20);
        } else {
            $shippingDataParts = [];
        }

        foreach($shippingDataParts as $shippingDataPart) {
            $orderIds = array_unique(array_map(function ($data) {
                return $data['order_id'];
            }, $shippingDataPart));
            $traceId = function_exists('wp_generate_uuid4')
                ? wp_generate_uuid4()
                : uniqid('shield-track-', true);

            $requestUrl = $proxyUrl . "?wootify-paypal-sync-tracking=1";
            // order_id is kept in the payload so site1 can echo it back in tracker_results.
            // site1 strips order_id before forwarding to PayPal API.
            $requestBody = wp_json_encode([
                'trackers' => $shippingDataPart,
            ]);
            $response = wp_remote_post($requestUrl, ep_paypal_signed_request_args($proxyUrl, 'POST', $requestUrl, [
                'timeout' => 5 * 60,
                'headers' => [
                    'Content-Type'      => 'application/json',
                    'X-Shield-Trace-Id' => $traceId,
                ],
                'body' => $requestBody,
            ], $requestBody));

            if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
                // HTTP-level failure: mark all orders in this batch as error (fallback)
                $errorOrderIdList = array_unique(array_merge($errorOrderIdList, $orderIds));
                ep_paypal_error_log([$response, $requestUrl, $orderIds, 'trace_id' => $traceId], "Sync data error![1]");
                foreach ($orderIds as $orderId) {
                    $subOrder = wc_get_order($orderId);
                    if (!$subOrder) continue;
                    $subOrder->update_meta_data( METAKEY_EP_PAYPAL_SYNC_TRACKING_INFO, EP_PAYPAL_SYNC_ERROR );
                    $subOrder->save_meta_data();
                }
                $hasError = true;
                continue;
            }

            $data = json_decode( wp_remote_retrieve_body( $response ), true );

            // Per-order status update using tracker_results from site1 response
            $updatedOrderIds = [];
            if (
                isset($data['batches']) && is_array($data['batches'])
            ) {
                foreach ($data['batches'] as $batchInfo) {
                    if ( !isset($batchInfo['tracker_results']) || !is_array($batchInfo['tracker_results']) ) {
                        continue;
                    }
                    foreach ($batchInfo['tracker_results'] as $res) {
                        $resOrderId = isset($res['order_id']) ? (int) $res['order_id'] : 0;
                        if ( !$resOrderId ) {
                            continue;
                        }
                        $updatedOrderIds[] = $resOrderId;
                        $subOrder = wc_get_order($resOrderId);
                        if ( !$subOrder ) {
                            continue;
                        }
                        // created or duplicate (already on PayPal) = success
                        if ( in_array($res['status'], ['created', 'duplicate'], true) ) {
                            $subOrder->update_meta_data( METAKEY_EP_PAYPAL_SYNC_TRACKING_INFO, EP_PAYPAL_SYNCED );
                        } else {
                            // error or unknown
                            $errorOrderIdList[] = $resOrderId;
                            $hasError = true;
                            $subOrder->update_meta_data( METAKEY_EP_PAYPAL_SYNC_TRACKING_INFO, EP_PAYPAL_SYNC_ERROR );
                        }
                        $subOrder->save_meta_data();
                    }
                }
            }

            // Fallback: any order not covered by tracker_results (e.g. site1 older version)
            $missingOrderIds = array_diff($orderIds, $updatedOrderIds);
            if ( !empty($missingOrderIds) ) {
                ep_paypal_debug_log($missingOrderIds, 'Fallback bulk update for orders missing from tracker_results');
                $allSuccess = isset($data['success']) && $data['success'];
                foreach ($missingOrderIds as $orderId) {
                    $subOrder = wc_get_order($orderId);
                    if (!$subOrder) continue;
                    if ($allSuccess) {
                        $subOrder->update_meta_data( METAKEY_EP_PAYPAL_SYNC_TRACKING_INFO, EP_PAYPAL_SYNCED );
                    } else {
                        $errorOrderIdList[] = $orderId;
                        $hasError = true;
                        $subOrder->update_meta_data( METAKEY_EP_PAYPAL_SYNC_TRACKING_INFO, EP_PAYPAL_SYNC_ERROR );
                    }
                    $subOrder->save_meta_data();
                }
            }
        }
    }

    if ($hasError) {
        echo json_encode( [
            'success' => false,
            'error'   => 'Fail synced order_id: ' . implode(', ', array_unique($errorOrderIdList)),
        ] );
    } else {
        echo json_encode( [
            'success' => true,
            'count'   => ep_paypal_count_order_need_sync()
        ] );
    }
}
require_once(plugin_dir_path(__FILE__) . 'vendor/autoload.php');
$bootstrapWootifyPaypalModules = require_once(plugin_dir_path(__FILE__) . 'modules/ppcp-api-client/bootstrap.php');
$appContainerWootifyPaypalModules = $bootstrapWootifyPaypalModules( plugin_dir_path(__FILE__) . 'modules/ppcp-api-client');

function ep_paypal_get_purchase_unit_from_cart(WC_Cart $cart) {
    global $appContainerWootifyPaypalModules;
    $purchaseUnits = $appContainerWootifyPaypalModules->get('api.factory.purchase-unit')->from_wc_cart($cart)->to_array();
    $result = [];
    foreach (['amount'] as $attrToUse) {
        $result[$attrToUse] = $purchaseUnits[$attrToUse];
    }

    return $result;
}

function ep_paypal_get_purchase_unit_from_order(WC_Order $order) {
    global $appContainerWootifyPaypalModules;
    $purchaseUnits = $appContainerWootifyPaypalModules->get('api.factory.purchase-unit')->from_wc_order($order)->to_array();
        $result = [];
    foreach (['amount', 'items', 'invoice_id'] as $attrToUse) {
        $result[$attrToUse] = $purchaseUnits[$attrToUse];
    }

    return $result;
}

function ep_paypal_error_log($data, $message = '')
{
    $trace = debug_backtrace();
    $dataLogString = ep_paypal_handle_data_log($data, $message)
        . json_encode([$trace[1]['class'] ?? null, $trace[1]['function'] ?? null, $trace[1]['args'] ?? null], true);
    if (!$logger = wc_get_logger()) {
        error_log($dataLogString);
    } else {
        $logger->debug($dataLogString, ['source' => 'cardshield-gateway-paypal-ERROR']);
    }
}

function ep_paypal_debug_log($data, $message = '')
{
    $trace = debug_backtrace();
    $dataLogString = ep_paypal_handle_data_log($data, $message)
        . json_encode([$trace[1]['class'] ?? null, $trace[1]['function'] ?? null, $trace[1]['args'] ?? null], true);
    if (!$logger = wc_get_logger()) {
        error_log($dataLogString);
    } else {
        $logger->debug($dataLogString, ['source' => 'cardshield-gateway-paypal-INFO']);
    }
}

function ep_paypal_handle_data_log($data, $message = '') {
    try {
        if (is_array($data) || is_object($data)) {
            $dataLog = json_encode($data);
        } else {
            $dataLog = (string)$data;
        }
    } catch (\Exception $e) {
        $dataLog = 'csPaypalLog ERROR: ' . $e->getMessage();
    }
    return '\n--------------------- ' . $message . ' ---------------------\n'
        . $dataLog;
}

function ep_paypal_get_order_detail_from_wc_order(WC_Order $order) {
    // Shipping
    $shippingName     = $order->get_shipping_first_name() . " " . $order->get_shipping_last_name();
    $shippingAddress1 = $order->get_shipping_address_1();
    $shippingAddress2 = $order->get_shipping_address_2();
    $shippingCity     = $order->get_shipping_city();
    $shippingCountry  = $order->get_shipping_country();
    $shippingPostCode = $order->get_shipping_postcode();
    $shippingState    = $order->get_shipping_state();

    // Billing
    $billingName     = $order->get_billing_first_name() . " " . $order->get_billing_last_name();
    $billingAddress1 = $order->get_billing_address_1();
    $billingAddress2 = $order->get_billing_address_2();
    $billingCity     = $order->get_billing_city();
    $billingCountry  = $order->get_billing_country();
    $billingPostCode = $order->get_billing_postcode();
    $billingState    = $order->get_billing_state();

    $shippingName     = ( empty( $order->get_shipping_first_name() ) && empty( $order->get_shipping_last_name() ) ) ? $billingName : $shippingName;
    $shippingAddress1 = empty( $shippingAddress1 ) ? $billingAddress1 : $shippingAddress1;
    $shippingAddress2 = empty( $shippingAddress2 ) ? $billingAddress2 : $shippingAddress2;
    $shippingCity     = empty( $shippingCity ) ? $billingCity : $shippingCity;
    $shippingCountry  = empty( $shippingCountry ) ? $billingCountry : $shippingCountry;
    $shippingPostCode = empty( $shippingPostCode ) ? $billingPostCode : $shippingPostCode;
    $shippingState    = empty( $shippingState ) ? $billingState : $shippingState;
    $csPaypalGw = WC()->payment_gateways->payment_gateways()['endpoint_paypal'];
    $trackingSyncPlugin = $csPaypalGw->get_option('transaction_logs_enable');
    if ($trackingSyncPlugin && $trackingSyncPlugin === 'yes') {
        return [
            'order_id' => $order->get_id(),
            'platform' => 'woocommerce',
            'endpoint_token' => get_option(EP_PP_ENDPOINT_TOKEN, null),
            'created_at' => date('Y/m/d H:i:s'),
            'amount_total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'store_domain' => get_home_url(),
            'customer_name' => $shippingName,
            'customer_email' => $order->get_billing_email(),
            'customer_phone' => $order->get_billing_phone(),
            'customer_ip' => $order->get_customer_ip_address(),
            'shipping_address_company' => $order->get_billing_company(),
            'shipping_address_line1' => $shippingAddress1,
            'shipping_address_line2' => $shippingAddress2,
            'shipping_address_city' => $shippingCity,
            'shipping_address_state' => $shippingState,
            'shipping_address_country' => $shippingCountry,
            'shipping_address_postal_code' => $shippingPostCode,
        ];
    } else {
        return [
            'SETTING_DISABLE_TRANSACTION_LOG' => true,
        ];
    }
}

function ep_paypal_save_transaction_id(WC_Order $order, $transactionId) {
    $order->set_transaction_id($transactionId);
    $order->update_meta_data('METAKEY_CS_TRANSACTION_ID', $transactionId);
    $order->save();
}

function ep_paypal_get_transaction_id(WC_Order $order) {
    $id = $order->get_transaction_id();
    if (empty($id)) {
        $id = $order->get_meta('METAKEY_CS_TRANSACTION_ID');
    }
    return $id;
}

function ep_paypal_query_order_need_sync($mode = 'count') {
    global $wpdb;

    $csPayPalGw = WC()->payment_gateways->payment_gateways()['endpoint_paypal'];
    $trackingSyncPlugin = $csPayPalGw->get_option('sync_tracking_plugin');
    $selectStatement = 'COUNT(DISTINCT(posts.id)) as count';
    if ($mode == 'get') {
        $selectStatement = 'DISTINCT(posts.id) as order_id';
    }
    switch ( $trackingSyncPlugin ) {
        case EP_TRACKING_SYNC_PLUGIN_ADVANCED_SHIPMENT_TRACKING:
            return $wpdb->get_results( $wpdb->prepare( "
                SELECT $selectStatement FROM {$wpdb->prefix}posts AS posts
                LEFT JOIN {$wpdb->prefix}postmeta AS post_meta1 ON posts.id = post_meta1.post_id AND post_meta1.meta_key = %s
                LEFT JOIN {$wpdb->prefix}postmeta AS post_meta2 ON posts.id = post_meta2.post_id AND post_meta2.meta_key = '_wc_shipment_tracking_items'
                LEFT JOIN {$wpdb->prefix}postmeta AS post_meta3 ON posts.id = post_meta3.post_id AND post_meta3.meta_key = %s
                WHERE posts.post_type = 'shop_order' AND post_meta1.meta_value = %d AND post_meta2.meta_value IS NOT NULL AND (post_meta3.meta_value IS NULL OR post_meta3.meta_value = 'true');
            ", METAKEY_EP_PAYPAL_SYNC_TRACKING_INFO , METAKEY_EP_PAYPAL_CAPTURED, EP_PAYPAL_NOT_SYNCED), ARRAY_A );

        case EP_TRACKING_SYNC_PLUGIN_ORDERS_TRACKING:
            return $wpdb->get_results( $wpdb->prepare( "
                SELECT $selectStatement FROM {$wpdb->prefix}posts AS posts
                LEFT JOIN {$wpdb->prefix}postmeta AS post_meta1 ON posts.id = post_meta1.post_id AND post_meta1.meta_key = %s
                LEFT JOIN {$wpdb->prefix}postmeta AS post_meta2 ON posts.id = post_meta2.post_id AND post_meta2.meta_key = %s
                LEFT JOIN {$wpdb->prefix}postmeta AS post_meta3 ON posts.id = post_meta3.post_id AND post_meta3.meta_key = '_wot_tracking_number'
                LEFT JOIN {$wpdb->prefix}woocommerce_order_items AS order_items
                    ON posts.id = order_items.order_id AND order_items.order_item_type = 'line_item'
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_itemmeta
                    ON order_items.order_item_id = order_itemmeta.order_item_id AND order_itemmeta.meta_key = '_vi_wot_order_item_tracking_data'
                WHERE posts.post_type = 'shop_order'
                  AND post_meta1.meta_value = %d
                  AND (
                        (order_itemmeta.meta_value IS NOT NULL AND (post_meta2.meta_value IS NULL OR post_meta2.meta_value = 'true'))
                        OR
                        post_meta3.meta_value IS NOT NULL
                      );
            ", METAKEY_EP_PAYPAL_SYNC_TRACKING_INFO, METAKEY_EP_PAYPAL_CAPTURED, EP_PAYPAL_NOT_SYNCED ), ARRAY_A );

        case EP_TRACKING_SYNC_PLUGIN_DIANXIAOMI:
            return $wpdb->get_results( $wpdb->prepare( "
                SELECT $selectStatement FROM {$wpdb->prefix}posts AS posts
                LEFT JOIN {$wpdb->prefix}postmeta AS post_meta1 ON posts.id = post_meta1.post_id AND post_meta1.meta_key = %s
                LEFT JOIN {$wpdb->prefix}postmeta AS post_meta2 ON posts.id = post_meta2.post_id AND post_meta2.meta_key = '_dianxiaomi_tracking_provider_name'
                LEFT JOIN {$wpdb->prefix}postmeta AS post_meta3 ON posts.id = post_meta3.post_id AND post_meta3.meta_key = %s
                LEFT JOIN {$wpdb->prefix}postmeta AS post_meta4 ON posts.id = post_meta4.post_id AND post_meta4.meta_key = '_dianxiaomi_tracking_number'
                WHERE posts.post_type = 'shop_order' AND post_meta1.meta_value = %d AND post_meta2.meta_value IS NOT NULL
                    AND (post_meta3.meta_value IS NULL OR post_meta3.meta_value = 'true')
                    AND post_meta4.meta_value IS NOT NULL;
            ", METAKEY_EP_PAYPAL_SYNC_TRACKING_INFO , METAKEY_EP_PAYPAL_CAPTURED, EP_PAYPAL_NOT_SYNCED), ARRAY_A );

    }
}

function ep_paypal_query_order_need_sync_hpos($mode = 'count')
{
    global $wpdb;

    $csPayPalGw = WC()->payment_gateways->payment_gateways()['endpoint_paypal'];
    $trackingSyncPlugin = $csPayPalGw->get_option('sync_tracking_plugin');
    $selectStatement = 'COUNT(DISTINCT(orders.id)) as count';
    if ($mode == 'get') {
        $selectStatement = 'DISTINCT(orders.id) as order_id';
    }
    switch ($trackingSyncPlugin) {
        case EP_TRACKING_SYNC_PLUGIN_ADVANCED_SHIPMENT_TRACKING:
            return $wpdb->get_results($wpdb->prepare("
                SELECT $selectStatement FROM {$wpdb->prefix}wc_orders AS orders
                LEFT JOIN {$wpdb->prefix}wc_orders_meta AS order_meta1 ON orders.id = order_meta1.order_id AND order_meta1.meta_key = %s
                LEFT JOIN {$wpdb->prefix}wc_orders_meta AS order_meta2 ON orders.id = order_meta2.order_id AND order_meta2.meta_key = '_wc_shipment_tracking_items'
                LEFT JOIN {$wpdb->prefix}wc_orders_meta AS order_meta3 ON orders.id = order_meta3.order_id AND order_meta3.meta_key = %s
                WHERE order_meta1.meta_value = %d AND order_meta2.meta_value IS NOT NULL AND (order_meta3.meta_value IS NULL OR order_meta3.meta_value = 'true');
            ", METAKEY_EP_PAYPAL_SYNC_TRACKING_INFO, METAKEY_EP_PAYPAL_CAPTURED, EP_PAYPAL_NOT_SYNCED), ARRAY_A);

        case EP_TRACKING_SYNC_PLUGIN_ORDERS_TRACKING:
            return $wpdb->get_results($wpdb->prepare("
                SELECT $selectStatement FROM {$wpdb->prefix}wc_orders AS orders
                LEFT JOIN {$wpdb->prefix}wc_orders_meta AS order_meta1 ON orders.id = order_meta1.order_id AND order_meta1.meta_key = %s
                LEFT JOIN {$wpdb->prefix}wc_orders_meta AS order_meta2 ON orders.id = order_meta2.order_id AND order_meta2.meta_key = %s
                LEFT JOIN {$wpdb->prefix}wc_orders_meta AS order_meta3 ON orders.id = order_meta3.order_id AND order_meta3.meta_key = '_wot_tracking_number'
                LEFT JOIN {$wpdb->prefix}woocommerce_order_items AS order_items
                    ON orders.id = order_items.order_id AND order_items.order_item_type = 'line_item'
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_itemmeta
                    ON order_items.order_item_id = order_itemmeta.order_item_id AND order_itemmeta.meta_key = '_vi_wot_order_item_tracking_data'
                WHERE order_meta1.meta_value = %d
                  AND (
                        (order_itemmeta.meta_value IS NOT NULL AND (order_meta2.meta_value IS NULL OR order_meta2.meta_value = 'true'))
                        OR
                        order_meta3.meta_value IS NOT NULL
                      );
            ", METAKEY_EP_PAYPAL_SYNC_TRACKING_INFO, METAKEY_EP_PAYPAL_CAPTURED, EP_PAYPAL_NOT_SYNCED), ARRAY_A);

        case EP_TRACKING_SYNC_PLUGIN_DIANXIAOMI:
            return $wpdb->get_results($wpdb->prepare("
                SELECT $selectStatement FROM {$wpdb->prefix}wc_orders AS orders
                LEFT JOIN {$wpdb->prefix}wc_orders_meta AS order_meta1 ON orders.id = order_meta1.order_id AND order_meta1.meta_key = %s
                LEFT JOIN {$wpdb->prefix}wc_orders_meta AS order_meta2 ON orders.id = order_meta2.order_id AND order_meta2.meta_key = '_dianxiaomi_tracking_provider_name'
                LEFT JOIN {$wpdb->prefix}wc_orders_meta AS order_meta3 ON orders.id = order_meta3.order_id AND order_meta3.meta_key = %s
                LEFT JOIN {$wpdb->prefix}wc_orders_meta AS order_meta4 ON orders.id = order_meta4.order_id AND order_meta4.meta_key = '_dianxiaomi_tracking_number'
                WHERE order_meta1.meta_value = %d AND order_meta2.meta_value IS NOT NULL
                    AND (order_meta3.meta_value IS NULL OR order_meta3.meta_value = 'true')
                    AND order_meta4.meta_value IS NOT NULL;
            ", METAKEY_EP_PAYPAL_SYNC_TRACKING_INFO, METAKEY_EP_PAYPAL_CAPTURED, EP_PAYPAL_NOT_SYNCED), ARRAY_A);

    }
}

function ep_paypal_is_shield_reach_amount($orderTotal) {
    if (!ep_paypal_is_enabled_amount_rotation()) {
        return false;
    }
    $proxies = get_option(EP_PP_NODES, []);
    if (empty($proxies)) {
        return false;
    }
    foreach ($proxies as $proxy) {
        $proxyVolumeLimit = $proxy['volumeLimit'] ?? $proxy['amount'] ?? 0;
        $proxyVolumeUsed = $proxy['volumeUsed'] ?? $proxy['paid_amount'] ?? 0;
        if(doubleval( $proxyVolumeUsed ) + doubleval( $orderTotal ) < doubleval($proxyVolumeLimit)) {
            return false;
        }
    }
    return true;
}

function ep_paypal_send_mail_shield_reach_amount() {
    $csPaypalGw = WC()->payment_gateways->payment_gateways()['endpoint_paypal'];
    if($csPaypalGw->get_option('send_email_notice_to_admin') === 'no') {
        return false;
    }
    $lastSent = strtotime(get_option('CS_PAYPAL_LAST_SEND_EMAIL_SHIELD_REACH_AMOUNT'));
    $now = strtotime(date( 'Y-m-d H:i:s'));
    if (($now - $lastSent)/60 > 360) { //Delay send by 360 minute
        update_option('CS_PAYPAL_LAST_SEND_EMAIL_SHIELD_REACH_AMOUNT', date( 'Y-m-d H:i:s'));
    } else {
        return false;
    }
    try {
        $headers[] = 'Content-type: text/html; charset=utf-8';
        add_filter( 'wp_mail_from_name', function () {
            return 'CardsShield';
        });
        $siteDomain = parse_url(get_home_url())['host'];
        ob_start();
		?>
            <p>
                Your website [<?=$siteDomain?>] cannot affort new orders due to shield amount has been reached.
                Please go to settings and increase the shield amount immediately!
            </p>
        <?php
		$body = ob_get_clean();
        $body = ep_paypal_build_body_email("Paypal shield amount has been reached", $body);
        wp_mail(ep_paypal_get_admin_emails(), "[$siteDomain]: Paypal shield amount has been reached", $body, $headers);
    } catch (\Exception $e) {
        ep_paypal_error_log($e->getMessage(), 'ep_paypal_send_mail_shield_reach_amount failed!');
    }
}

function ep_paypal_send_mail_shield_die($shieldUrl) {
    $csPaypalGw = WC()->payment_gateways->payment_gateways()['endpoint_paypal'];
    if($csPaypalGw->get_option('send_email_notice_to_admin') === 'no') {
        return false;
    }
    try {
        $headers[] = 'Content-type: text/html; charset=utf-8';
        add_filter( 'wp_mail_from_name', function () {
            return 'CardsShield';
        });
        $siteDomain = parse_url(get_home_url())['host'];
        ob_start();
		?>
            <p>
                A Paypal shield <?= $shieldUrl ?> has been moved to unused list due to unchargable problem.
                Please check your payment account immediately!
            </p>
        <?php
		$body = ob_get_clean();
        $body = ep_paypal_build_body_email("A Paypal shield has been moved to unused list", $body);
        wp_mail(ep_paypal_get_admin_emails(), "[$siteDomain]: A Paypal shield $shieldUrl has been moved to unused list", $body, $headers);
    } catch (\Exception $e) {
        ep_paypal_error_log($e->getMessage(), 'ep_paypal_send_mail_shield_die failed!');
    }
}

function ep_paypal_send_mail_order_blacklisted($orderId) {
    $csPaypalGw = WC()->payment_gateways->payment_gateways()['endpoint_paypal'];
    if($csPaypalGw->get_option('send_email_notice_to_admin') === 'no') {
        return false;
    }
    $order = wc_get_order($orderId);
    try {
        $headers[] = 'Content-type: text/html; charset=utf-8';
        add_filter( 'wp_mail_from_name', function () {
            return 'CardsShield';
        });
        $siteDomain = parse_url(get_home_url())['host'];
        ob_start();
		?>
            <?php
                wc_get_template( 'emails/email-order-details.php', array(
                    'order'         => $order,
                    'sent_to_admin' => true,
                    'plain_text'    => false,
                    'email'         => '',
                ));
                wc_get_template( 'emails/email-addresses.php', array(
                    'order'         => $order,
                    'sent_to_admin' => true,
                    'plain_text'    => false,
                    'email'         => '',
                ));
            ?>
        <?php
		$body = ob_get_clean();
        $body = ep_paypal_build_body_email("Order Blacklisted: #{$order->get_order_number()}", $body);
        wp_mail(ep_paypal_get_admin_emails(), "[$siteDomain]: Order #{$order->get_order_number()} has been BLACKLISTED", $body, $headers);
    } catch (\Exception $e) {
        ep_paypal_error_log($e->getMessage(), 'ep_paypal_send_mail_shield_die failed!');
    }
}

function ep_paypal_build_body_email($heading, $body) {
    add_filter( 'woocommerce_email_footer_text', function () {
        return "<br><b>Cards Shield Team</b><br/>support@cardsshield.com";
    } );
    $mailer = WC()->mailer();
    $wrapped_message = $mailer->wrap_message($heading, $body);
    $wc_email = new WC_Email;
    return $wc_email->style_inline($wrapped_message);
}

function ep_paypal_get_admin_emails() {
    $blogusers = get_users('role=Administrator');
    $emails = [];
    foreach ($blogusers as $user) {
        $emails[] = $user->user_email;
    }
    if (!empty($emails)) {
        return implode(',', $emails);
    }
    return false;
}

function ep_paypal_get_shield_to_process($csOrderKey, $orderTotal) {
    if ($shield = get_transient('csEndpointGetShieldPaypalToProcessValue')) {
        set_transient("csEndpointGetShieldPaypalToProcessValue_$csOrderKey", $shield, 14400);
        $shield = json_decode($shield);
        return 'https://' . $shield->shield_domain;
    }
    if (!$gwDomain = ep_paypal_get_gateway_domain()) {
        return null;
    }
    wc_get_logger()->debug('request ep_paypal_get_shield_to_process', ['source' => 'cardshield-gateway-paypal-INFO']);
    $request = wp_remote_post($gwDomain . '/woo/get-shield-process', [
        'sslverify' => false,
        'timeout' => 300,
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode([
            'cs_order_key' => $csOrderKey,
            'order_total' => $orderTotal,
            'ep_token' => get_option(EP_PP_ENDPOINT_TOKEN, null),
            'merchant_site' => get_home_url(),
            'payment_gateway' => EP_PP_GATEWAY_TYPE,
        ])
    ]);
    if (is_wp_error($request)) {
        ep_paypal_error_log($request, "ep_paypal_get_shield_to_process error");
        return null;
    }
    $responseBody = wp_remote_retrieve_body($request);
    $data = json_decode($responseBody);
    if ($data->status == 'success') {
        $shieldUrl = 'https://' . $data->shield->shield_domain;
        // Global cache shield Url 30s
        set_transient("csEndpointGetShieldPaypalToProcessValue_$csOrderKey", json_encode($data->shield), 14400);
        set_transient('csEndpointGetShieldPaypalToProcessValue', json_encode($data->shield), 10);
        return $shieldUrl;
    } else {
        ep_paypal_error_log($request, "ep_paypal_get_shield_to_process error[2]");
        return null;
    }
}

function ep_paypal_perform_shield_rotate_by_amount(WC_Order $order) {
    if (!$gwDomain = ep_paypal_get_gateway_domain()) {
        return null;
    }
    $csOrderKey = $order->get_meta(METAKEY_PAYPAL_PROCESSING_ORDER_KEY);
    $body = [
        'cs_order_key' => $csOrderKey,
        'order_total' => $order->get_total(),
        'order_currency' => $order->get_currency(),
        'ep_token' => get_option(EP_PP_ENDPOINT_TOKEN, null),
        'shield_processing' => json_decode(get_transient("csEndpointGetShieldPaypalToProcessValue_$csOrderKey"), true),
    ];
    $request = wp_remote_post($gwDomain . '/woo/perform-rotate-shield-by-amount', [
        'sslverify' => false,
        'timeout' => 300,
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode($body)
    ]);
    ep_paypal_debug_log(['request' => $body, 'response' => $request], "ep_paypal_perform_shield_rotate_by_amount response");
    if (is_wp_error($request)) {
        ep_paypal_error_log($request, "ep_paypal_perform_shield_rotate_by_amount error");
        return null;
    }
}

function ep_paypal_set_next_shield(WC_Order $order) {
    if (!$gwDomain = ep_paypal_get_gateway_domain()) {
        return null;
    }
    $csOrderKey = $order->get_meta(METAKEY_PAYPAL_PROCESSING_ORDER_KEY);
    $request = wp_remote_post($gwDomain . '/woo/set-next-shield', [
        'sslverify' => false,
        'timeout' => 300,
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode([
            'cs_order_key' => $csOrderKey,
            'order_total' => $order->get_total(),
            'order_currency' => $order->get_currency(),
            'ep_token' => get_option(EP_PP_ENDPOINT_TOKEN, null),
            'shield_processing' => json_decode(get_transient("csEndpointGetShieldPaypalToProcessValue_$csOrderKey"), true),
        ])
    ]);
    ep_paypal_debug_log($request, "ep_paypal_set_next_shield response");
    if (is_wp_error($request)) {
        ep_paypal_error_log($request, "ep_paypal_set_next_shield error");
        return null;
    }
}

function ep_paypal_is_endpoint_mode_enabled() {
    return get_option(EP_PP_CONNECTION_MODE, null) == EP_PP_MODE_ENDPOINT_TOKEN;
}

function ep_paypal_get_gateway_domain()
{
    try {
        $endpointToken = get_option(EP_PP_ENDPOINT_TOKEN, null);
        $endpointSecret = get_option(EP_PP_ENDPOINT_SECRET, null);
        $decrypt = base64_decode(strtr(base64_decode($endpointSecret),
            './-:?=&%# ZQXJKVWPY abcdefghijklmnopqrstuvwxyz123456789ABCDEFGHILMNORSTU',
            'ZQXJKVWPY ./-:?=&%# 123456789ABCDEFGHILMNORSTUabcdefghijklmnopqrstuvwxyz'));
        return str_replace($endpointToken, '', $decrypt);
    } catch (\Exception $e) {
        ep_paypal_error_log($e->getMessage(), 'ep_paypal_get_gateway_domain failed!');
        return null;
    }
}

function ep_paypal_get_client_ip()
{
    foreach (array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR') as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);

                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? null;
}
