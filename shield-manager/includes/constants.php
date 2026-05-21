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
const PAYPAL_POSITION_PROXIES = 'PAYPAL_POSITION_PROXIES';
const STRIPE_POSITION_PROXIES = 'STRIPE_POSITION_PROXIES';

// ============================================================================
// ROTATION METHOD CONSTANTS
// ============================================================================
const ROTATION_METHOD_TIME = 'by_time';
const ROTATION_METHOD_AMOUNT = 'by_amount';
const ROTATION_METHOD_ORDER = 'by_order';

// ============================================================================
// PAYPAL CONSTANTS
// ============================================================================
const OPT_WOOTIFY_PAYPAL_PROXIES = 'OPT_WOOTIFY_PAYPAL_PROXIES';
const OPT_WOOTIFY_PAYPAL_UNUSED_PROXIES = 'OPT_WOOTIFY_PAYPAL_UNUSED_PROXIES';
const OPT_WOOTIFY_PAYPAL_ACTIVATED_PROXY = 'OPT_WOOTIFY_PAYPAL_ACTIVATED_PROXY';
const OPT_WOOTIFY_PAYPAL_CURRENT_ROTATION_VALUE = 'OPT_WOOTIFY_PAYPAL_CURRENT_ROTATION_VALUE';
const OPT_WOOTIFY_PAYPAL_ROTATION_METHOD = 'OPT_WOOTIFY_PAYPAL_ROTATION_METHOD';
const OPT_WOOTIFY_PAYPAL_LAST_TIME_RESET_ORDER_COUNT = 'OPT_WOOTIFY_PAYPAL_LAST_TIME_RESET_ORDER_COUNT';

// PayPal rotation method aliases
const OPT_CS_PAYPAL_BY_TIME = ROTATION_METHOD_TIME;
const OPT_CS_PAYPAL_BY_AMOUNT = ROTATION_METHOD_AMOUNT;
const OPT_CS_PAYPAL_BY_ORDER = ROTATION_METHOD_ORDER;

// ============================================================================
// STRIPE CONSTANTS
// ============================================================================
const OPT_WOOTIFY_STRIPE_PROXIES = 'OPT_WOOTIFY_STRIPE_PROXIES';
const OPT_WOOTIFY_STRIPE_UNUSED_PROXIES = 'OPT_WOOTIFY_STRIPE_UNUSED_PROXIES';
const OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY = 'OPT_WOOTIFY_STRIPE_ACTIVATED_PROXY';
const OPT_WOOTIFY_STRIPE_CURRENT_ROTATION_VALUE = 'OPT_WOOTIFY_STRIPE_CURRENT_ROTATION_VALUE';
const OPT_WOOTIFY_STRIPE_ROTATION_METHOD = 'OPT_WOOTIFY_STRIPE_ROTATION_METHOD';
const OPT_WOOTIFY_STRIPE_LAST_TIME_RESET_ORDER_COUNT = 'OPT_WOOTIFY_STRIPE_LAST_TIME_RESET_ORDER_COUNT';

// Stripe rotation method aliases
const WOOTIFY_STRIPE_BY_TIME = ROTATION_METHOD_TIME;
const WOOTIFY_STRIPE_BY_AMOUNT = ROTATION_METHOD_AMOUNT;
const WOOTIFY_STRIPE_BY_ORDER = ROTATION_METHOD_ORDER;

// ============================================================================
// OPTION KEYS MAPPING
// ============================================================================
const OPT_SHIELD_DEFAULT_BOOTSTRAP_TOKEN = 'OPT_SHIELD_DEFAULT_BOOTSTRAP_TOKEN';

const OPTIONKEYS = [
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
];
