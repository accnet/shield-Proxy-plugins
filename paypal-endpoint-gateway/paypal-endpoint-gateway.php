<?php
/**
 * PayPal Endpoint Gateway
 *
 * @package     PayPal_Endpoint_Gateway
 * @author      Wootify
 * @copyright   2024 Wootify
 * @license     GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name: PayPal Endpoint Gateway
 * Plugin URI:  https://wootify.dev
 * Description: WooCommerce PayPal payment gateway via Shield Proxy Endpoint - Independent rotation managed by SaaS
 * Version:     1.0.4
 * Author:      Wootify
 * Author URI:  https://wootify.dev
 * Text Domain: endpoint-paypal
 * License:     GPL v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

// ─── Constants ────────────────────────────────────────────────────────────────
define('ENDPOINT_PAYPAL_VERSION', '1.0.4');
define('ENDPOINT_PAYPAL_PLUGIN_FILE', __FILE__);
define('ENDPOINT_PAYPAL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ENDPOINT_PAYPAL_PLUGIN_URL', plugin_dir_url(__FILE__));

// Option prefix for PayPal endpoint (all wp_options keys start with this)
// Gateway type filter for config-update receiver

// ─── PayPal-specific constants ────────────────────────────────────────────────
// WooCommerce gateway ID
define('ENDPOINT_PAYPAL_GATEWAY_ID', 'endpoint_paypal');

// Meta keys (unique to avoid collision with shield-manager)
define('METAKEY_EP_PAYPAL_PROXY_URL', '_ep_paypal_proxy_url');
define('METAKEY_EP_PAYPAL_PROXY_ID', '_ep_paypal_proxy_id');
define('METAKEY_EP_PAYPAL_SHIELD_ID', '_ep_paypal_shield_id');
define('METAKEY_EP_PAYPAL_PROCESSING_ORDER_KEY', '_ep_paypal_processing_order_key');
define('METAKEY_EP_PAYPAL_INTENT', '_ep_paypal_intent');
define('METAKEY_EP_PAYPAL_CAPTURED', '_ep_paypal_captured');
define('METAKEY_EP_PAYPAL_FEE', '_ep_paypal_fee');
define('METAKEY_EP_PAYPAL_PAYOUT', '_ep_paypal_payout');
define('METAKEY_EP_PAYPAL_CURRENCY', '_ep_paypal_currency');
define('METAKEY_EP_PAYPAL_SYNC_TRACKING_INFO', '_ep_paypal_sync_tracking');

// PayPal intent constants
define('EP_PAYPAL_INTENT_CAPTURE', 'capture');
define('EP_PAYPAL_INTENT_AUTHORIZE', 'authorize');

// Tracking sync status
define('EP_PAYPAL_NOT_SYNCED', 0);
define('EP_PAYPAL_SYNCED', 1);
define('EP_PAYPAL_SYNC_ERROR', 2);

// Tracking sync plugin options
define('EP_TRACKING_SYNC_PLUGIN_ADVANCED_SHIPMENT_TRACKING', 'advanced_shipment_tracking');
define('EP_TRACKING_SYNC_PLUGIN_ORDERS_TRACKING', 'orders_tracking');
define('EP_TRACKING_SYNC_PLUGIN_DIANXIAOMI', 'dianxiaomi');

// ─── Check WooCommerce ────────────────────────────────────────────────────────
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>PayPal Endpoint Gateway</strong> requires WooCommerce to be active.</p></div>';
    });
    return;
}

// ─── Load Core Classes ────────────────────────────────────────────────────────
require_once ENDPOINT_PAYPAL_PLUGIN_DIR . 'includes/class-endpoint-client.php';
require_once ENDPOINT_PAYPAL_PLUGIN_DIR . 'includes/class-endpoint-receiver.php';
require_once ENDPOINT_PAYPAL_PLUGIN_DIR . 'includes/class-endpoint-cron.php';

// ─── Load Gateway ─────────────────────────────────────────────────────────────
require_once ENDPOINT_PAYPAL_PLUGIN_DIR . 'includes/gateway/class-wc-gateway-ppec-api-exception.php';
require_once ENDPOINT_PAYPAL_PLUGIN_DIR . 'includes/gateway/endpoint-paygate.php';

// ─── Admin Menu ───────────────────────────────────────────────────────────────
add_action('admin_menu', function () {
    // Remove old CardShield menu to prevent duplication
    remove_menu_page('wootify-gateway-paypal');

    $page = add_menu_page(
        'PayPal Endpoint',
        'PayPal Endpoint',
        'manage_options',
        'endpoint-paypal',
        function () {
            include ENDPOINT_PAYPAL_PLUGIN_DIR . 'views/settings.php';
        },
        'dashicons-money-alt',
        56
    );

    add_action('load-' . $page, function () {
        wp_enqueue_style('endpoint-paypal-settings', ENDPOINT_PAYPAL_PLUGIN_URL . 'assets/css/settings.css', [], ENDPOINT_PAYPAL_VERSION);
        wp_enqueue_script('endpoint-paypal-settings', ENDPOINT_PAYPAL_PLUGIN_URL . 'assets/js/settings.js', ['jquery'], ENDPOINT_PAYPAL_VERSION, true);
        wp_localize_script('endpoint-paypal-settings', 'EndpointPayPal', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('endpoint_paypal_nonce'),
        ]);
    });
}, 999);

// ─── AJAX Handlers ────────────────────────────────────────────────────────────
add_action('wp_ajax_endpoint_paypal_connect', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'endpoint_paypal_nonce')) {
        wp_send_json_error(['message' => 'Invalid security token'], 403);
    }

    $saas_url = esc_url_raw($_POST['saas_url'] ?? '');
    $connection_code = sanitize_text_field($_POST['connection_code'] ?? '');

    $result = Shield_PayPal_Endpoint_Client::connect($saas_url, $connection_code);
    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
});

add_action('wp_ajax_endpoint_paypal_disconnect', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'endpoint_paypal_nonce')) {
        wp_send_json_error(['message' => 'Invalid security token'], 403);
    }

    $result = Shield_PayPal_Endpoint_Client::disconnect();
    wp_send_json_success($result);
});

add_action('wp_ajax_endpoint_paypal_pull_config', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'endpoint_paypal_nonce')) {
        wp_send_json_error(['message' => 'Invalid security token'], 403);
    }

    $result = Shield_PayPal_Endpoint_Client::pull_config();
    wp_send_json_success(['pulled' => $result]);
});

add_action('wp_ajax_endpoint_paypal_set_active_node', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'endpoint_paypal_nonce')) {
        wp_send_json_error(['message' => 'Invalid security token'], 403);
    }

    $shield_id = sanitize_text_field($_POST['shield_id'] ?? '');
    if (empty($shield_id)) {
        wp_send_json_error(['message' => 'Shield ID is required']);
    }

    Shield_PayPal_Endpoint_Client::update_active_node_by_shield_id($shield_id);
    wp_send_json_success(['message' => 'Active node updated successfully']);
});

add_action('wp_ajax_endpoint_paypal_reconnect', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'endpoint_paypal_nonce')) {
        wp_send_json_error(['message' => 'Invalid security token'], 403);
    }

    $saas_url = get_option('EP_PP_SAAS_URL', '');
    $connection_code = get_option('EP_PP_CONNECTION_CODE', '');

    if (empty($saas_url) || empty($connection_code)) {
        wp_send_json_error(['message' => 'No saved credentials found. Please enter them manually.']);
    }

    $result = Shield_PayPal_Endpoint_Client::connect($saas_url, $connection_code);
    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
});

add_action('wp_ajax_endpoint_paypal_clear_credentials', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'endpoint_paypal_nonce')) {
        wp_send_json_error(['message' => 'Invalid security token'], 403);
    }

    delete_option('EP_PP_SAAS_URL');
    delete_option('EP_PP_CONNECTION_CODE');
    wp_send_json_success(['message' => 'Credentials cleared']);
});

// ─── Init Hooks ───────────────────────────────────────────────────────────────
add_action('init', function () {
    Shield_PayPal_Endpoint_Receiver::init();
    Shield_PayPal_Endpoint_Cron::register();
});

// ─── Activation / Deactivation ────────────────────────────────────────────────
register_activation_hook(__FILE__, function () {
    Shield_PayPal_Endpoint_Cron::register();
    if (class_exists('Shield_PayPal_Endpoint_Client')) {
        Shield_PayPal_Endpoint_Client::pull_config();
    }
});

register_deactivation_hook(__FILE__, function () {
    Shield_PayPal_Endpoint_Cron::unregister();
});
