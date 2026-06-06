<?php
/**
 * Shield Manager - Constants
 *
 * Centralized constant definitions for the Shield Manager plugin
 *
 * @package Shield_Manager
 * @since 1.5
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// POSITION LIST CONSTANTS
// ============================================================================
defined('PAYPAL_POSITION_PROXIES') || define('PAYPAL_POSITION_PROXIES', 'PAYPAL_POSITION_PROXIES');
defined('STRIPE_POSITION_PROXIES') || define('STRIPE_POSITION_PROXIES', 'STRIPE_POSITION_PROXIES');

// ============================================================================
// ROTATION METHOD CONSTANTS
// ============================================================================
defined('ROTATION_METHOD_TIME') || define('ROTATION_METHOD_TIME', 'by_time');
defined('ROTATION_METHOD_AMOUNT') || define('ROTATION_METHOD_AMOUNT', 'by_amount');
defined('ROTATION_METHOD_ORDER') || define('ROTATION_METHOD_ORDER', 'by_order');

// ============================================================================
// PAYPAL CONSTANTS
// ============================================================================
defined('OPT_WOOTIFY_PAYPAL_PROXIES') || define('OPT_WOOTIFY_PAYPAL_PROXIES', 'OPT_WOOTIFY_PAYPAL_PROXIES');
defined('OPT_WOOTIFY_PAYPAL_UNUSED_PROXIES') || define('OPT_WOOTIFY_PAYPAL_UNUSED_PROXIES', 'OPT_WOOTIFY_PAYPAL_UNUSED_PROXIES');
defined('OPT_WOOTIFY_PAYPAL_ACTIVATED_PROXY') || define('OPT_WOOTIFY_PAYPAL_ACTIVATED_PROXY', 'OPT_WOOTIFY_PAYPAL_ACTIVATED_PROXY');
defined('OPT_WOOTIFY_PAYPAL_CURRENT_ROTATION_VALUE') || define('OPT_WOOTIFY_PAYPAL_CURRENT_ROTATION_VALUE', 'OPT_WOOTIFY_PAYPAL_CURRENT_ROTATION_VALUE');
defined('OPT_WOOTIFY_PAYPAL_ROTATION_METHOD') || define('OPT_WOOTIFY_PAYPAL_ROTATION_METHOD', 'OPT_WOOTIFY_PAYPAL_ROTATION_METHOD');
defined('OPT_WOOTIFY_PAYPAL_LAST_TIME_RESET_ORDER_COUNT') || define('OPT_WOOTIFY_PAYPAL_LAST_TIME_RESET_ORDER_COUNT', 'OPT_WOOTIFY_PAYPAL_LAST_TIME_RESET_ORDER_COUNT');

// PayPal rotation method aliases
defined('OPT_CS_PAYPAL_BY_TIME') || define('OPT_CS_PAYPAL_BY_TIME', ROTATION_METHOD_TIME);
defined('OPT_CS_PAYPAL_BY_AMOUNT') || define('OPT_CS_PAYPAL_BY_AMOUNT', ROTATION_METHOD_AMOUNT);
defined('OPT_CS_PAYPAL_BY_ORDER') || define('OPT_CS_PAYPAL_BY_ORDER', ROTATION_METHOD_ORDER);

// ============================================================================
// STRIPE CONSTANTS
// ============================================================================
defined('OPT_WOOTIFY_STRIPE_PROXIES') || define('OPT_WOOTIFY_STRIPE_PROXIES', 'OPT_WOOTIFY_STRIPE_PROXIES');
defined('OPT_WOOTIFY_STRIPE_UNUSED_PROXIES') || define('OPT_WOOTIFY_STRIPE_UNUSED_PROXIES', 'OPT_WOOTIFY_STRIPE_UNUSED_PROXIES');
defined('OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY') || define('OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY', 'OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY');
defined('OPT_WOOTIFY_STRIPE_CURRENT_ROTATION_VALUE') || define('OPT_WOOTIFY_STRIPE_CURRENT_ROTATION_VALUE', 'OPT_WOOTIFY_STRIPE_CURRENT_ROTATION_VALUE');
defined('OPT_WOOTIFY_STRIPE_ROTATION_METHOD') || define('OPT_WOOTIFY_STRIPE_ROTATION_METHOD', 'OPT_WOOTIFY_STRIPE_ROTATION_METHOD');
defined('OPT_WOOTIFY_STRIPE_LAST_TIME_RESET_ORDER_COUNT') || define('OPT_WOOTIFY_STRIPE_LAST_TIME_RESET_ORDER_COUNT', 'OPT_WOOTIFY_STRIPE_LAST_TIME_RESET_ORDER_COUNT');
defined('OPT_WOOTIFY_STRIPE_LINK_EXPRESS_ENABLED') || define('OPT_WOOTIFY_STRIPE_LINK_EXPRESS_ENABLED', 'link_express_enabled');

// Stripe rotation method aliases
defined('WOOTIFY_STRIPE_BY_TIME') || define('WOOTIFY_STRIPE_BY_TIME', ROTATION_METHOD_TIME);
defined('WOOTIFY_STRIPE_BY_AMOUNT') || define('WOOTIFY_STRIPE_BY_AMOUNT', ROTATION_METHOD_AMOUNT);
defined('WOOTIFY_STRIPE_BY_ORDER') || define('WOOTIFY_STRIPE_BY_ORDER', ROTATION_METHOD_ORDER);

// ============================================================================
// OPTION KEYS MAPPING
// ============================================================================
defined('OPT_SHIELD_DEFAULT_BOOTSTRAP_TOKEN') || define('OPT_SHIELD_DEFAULT_BOOTSTRAP_TOKEN', 'OPT_SHIELD_DEFAULT_BOOTSTRAP_TOKEN');

defined('OPTIONKEYS') || define('OPTIONKEYS', [
    'PayPal' => [
        'rotationMethod' => OPT_WOOTIFY_PAYPAL_ROTATION_METHOD,
        'positionList' => PAYPAL_POSITION_PROXIES,
        'proxies' => OPT_WOOTIFY_PAYPAL_PROXIES,
        'unusedProxies' => OPT_WOOTIFY_PAYPAL_UNUSED_PROXIES,
        'activatedProxy' => OPT_WOOTIFY_PAYPAL_ACTIVATED_PROXY,
        'currentRotation' => OPT_WOOTIFY_PAYPAL_CURRENT_ROTATION_VALUE,
        'byTime' => OPT_CS_PAYPAL_BY_TIME,
        'byOrder' => OPT_CS_PAYPAL_BY_ORDER,
        'byAmount' => OPT_CS_PAYPAL_BY_AMOUNT,
        'lastTimeResetOrderCount' => OPT_WOOTIFY_PAYPAL_LAST_TIME_RESET_ORDER_COUNT,
    ],
    'Stripe' => [
        'rotationMethod' => OPT_WOOTIFY_STRIPE_ROTATION_METHOD,
        'positionList' => STRIPE_POSITION_PROXIES,
        'proxies' => OPT_WOOTIFY_STRIPE_PROXIES,
        'unusedProxies' => OPT_WOOTIFY_STRIPE_UNUSED_PROXIES,
        'activatedProxy' => OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY,
        'currentRotation' => OPT_WOOTIFY_STRIPE_CURRENT_ROTATION_VALUE,
        'byTime' => WOOTIFY_STRIPE_BY_TIME,
        'byOrder' => WOOTIFY_STRIPE_BY_ORDER,
        'byAmount' => WOOTIFY_STRIPE_BY_AMOUNT,
        'lastTimeResetOrderCount' => OPT_WOOTIFY_STRIPE_LAST_TIME_RESET_ORDER_COUNT,
    ],
]);
