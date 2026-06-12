<?php
/**
 * Stripe Endpoint Gateway
 *
 * @package     Stripe_Endpoint_Gateway
 * @author      Wootify
 * @copyright   2024 Wootify
 * @license     GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name: Stripe Endpoint Gateway
 * Plugin URI:  https://wootify.dev
 * Description: WooCommerce Stripe payment gateway via Shield Proxy Endpoint - Independent rotation managed by SaaS
 * Version:     1.0.7
 * Author:      Wootify
 * Author URI:  https://wootify.dev
 * Text Domain: endpoint-stripe
 * License:     GPL v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

// ─── Constants ────────────────────────────────────────────────────────────────
define('ENDPOINT_STRIPE_VERSION', '1.0.7');
define('ENDPOINT_STRIPE_PLUGIN_FILE', __FILE__);
define('ENDPOINT_STRIPE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ENDPOINT_STRIPE_PLUGIN_URL', plugin_dir_url(__FILE__));

// Option prefix for Stripe endpoint
// Gateway type filter for config-update receiver

// WooCommerce gateway ID
define('ENDPOINT_STRIPE_GATEWAY_ID', 'endpoint_stripe');

// Meta keys (unique to avoid collision)
define('METAKEY_EP_STRIPE_PROXY_URL', '_ep_stripe_proxy_url');
define('METAKEY_EP_STRIPE_PROXY_ID', '_ep_stripe_proxy_id');
define('METAKEY_EP_STRIPE_SHIELD_ID', '_ep_stripe_shield_id');
define('METAKEY_EP_STRIPE_FEE', '_ep_stripe_fee');
define('METAKEY_EP_STRIPE_PAYOUT', '_ep_stripe_payout');
define('METAKEY_EP_STRIPE_CURRENCY', '_ep_stripe_currency');
define('METAKEY_EP_STRIPE_INTENT_AUTHORIZED', '_ep_stripe_intent_authorized');
define('EP_ST_LINK_EXPRESS_ENABLED', 'link_express_enabled');

// Stripe intent constants
define('EP_STRIPE_INTENT_CAPTURE', 'automatic');
define('EP_STRIPE_INTENT_AUTHORIZE', 'manual');

// ─── Check WooCommerce ────────────────────────────────────────────────────────
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>Stripe Endpoint Gateway</strong> requires WooCommerce to be active.</p></div>';
    });
    return;
}

// ─── Load Core Classes ────────────────────────────────────────────────────────
require_once ENDPOINT_STRIPE_PLUGIN_DIR . 'includes/class-endpoint-client.php';
require_once ENDPOINT_STRIPE_PLUGIN_DIR . 'includes/class-endpoint-receiver.php';
require_once ENDPOINT_STRIPE_PLUGIN_DIR . 'includes/class-endpoint-cron.php';

// ─── Load Gateway ─────────────────────────────────────────────────────────────
require_once ENDPOINT_STRIPE_PLUGIN_DIR . 'includes/gateway/endpoint-gateway-stripe.php';

// ─── Admin Menu ───────────────────────────────────────────────────────────────
add_action('admin_menu', function () {
    // Remove old CardShield menu to prevent duplication
    remove_menu_page('wootify-gateway-stripe');

    $page = add_menu_page(
        'Stripe Endpoint',
        'Stripe Endpoint',
        'manage_options',
        'endpoint-stripe',
        function () {
            include ENDPOINT_STRIPE_PLUGIN_DIR . 'views/settings.php';
        },
        'dashicons-money-alt',
        57
    );

    add_action('load-' . $page, function () {
        wp_enqueue_style('endpoint-stripe-settings', ENDPOINT_STRIPE_PLUGIN_URL . 'assets/css/settings.css', [], ENDPOINT_STRIPE_VERSION);
        wp_enqueue_script('endpoint-stripe-settings', ENDPOINT_STRIPE_PLUGIN_URL . 'assets/js/settings.js', ['jquery'], ENDPOINT_STRIPE_VERSION, true);
        wp_localize_script('endpoint-stripe-settings', 'EndpointStripe', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('endpoint_stripe_nonce'),
        ]);
    });
}, 999);

// ─── AJAX Handlers ────────────────────────────────────────────────────────────
add_action('wp_ajax_endpoint_stripe_connect', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Unauthorized'], 403);
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'endpoint_stripe_nonce')) wp_send_json_error(['message' => 'Invalid security token'], 403);

    $result = Shield_Stripe_Endpoint_Client::connect(
        esc_url_raw($_POST['saas_url'] ?? ''),
        sanitize_text_field($_POST['connection_code'] ?? '')
    );
    $result['success'] ? wp_send_json_success($result) : wp_send_json_error($result);
});

add_action('wp_ajax_endpoint_stripe_disconnect', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Unauthorized'], 403);
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'endpoint_stripe_nonce')) wp_send_json_error(['message' => 'Invalid security token'], 403);
    wp_send_json_success(Shield_Stripe_Endpoint_Client::disconnect());
});

add_action('wp_ajax_endpoint_stripe_pull_config', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Unauthorized'], 403);
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'endpoint_stripe_nonce')) wp_send_json_error(['message' => 'Invalid security token'], 403);
    wp_send_json_success(['pulled' => Shield_Stripe_Endpoint_Client::pull_config()]);
});

add_action('wp_ajax_endpoint_stripe_set_active_node', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Unauthorized'], 403);
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'endpoint_stripe_nonce')) wp_send_json_error(['message' => 'Invalid security token'], 403);

    $shield_id = sanitize_text_field($_POST['shield_id'] ?? '');
    if (empty($shield_id)) {
        wp_send_json_error(['message' => 'Shield ID is required']);
    }

    Shield_Stripe_Endpoint_Client::update_active_node_by_shield_id($shield_id);
    wp_send_json_success(['message' => 'Active node updated successfully']);
});

add_action('wp_ajax_endpoint_stripe_reconnect', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Unauthorized'], 403);
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'endpoint_stripe_nonce')) wp_send_json_error(['message' => 'Invalid security token'], 403);

    $saas_url = get_option('EP_ST_SAAS_URL', '');
    $connection_code = get_option('EP_ST_CONNECTION_CODE', '');

    if (empty($saas_url) || empty($connection_code)) {
        wp_send_json_error(['message' => 'No saved credentials found. Please enter them manually.']);
    }

    $result = Shield_Stripe_Endpoint_Client::connect($saas_url, $connection_code);
    $result['success'] ? wp_send_json_success($result) : wp_send_json_error($result);
});

add_action('wp_ajax_endpoint_stripe_clear_credentials', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Unauthorized'], 403);
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'endpoint_stripe_nonce')) wp_send_json_error(['message' => 'Invalid security token'], 403);

    delete_option('EP_ST_SAAS_URL');
    delete_option('EP_ST_CONNECTION_CODE');
    wp_send_json_success(['message' => 'Credentials cleared']);
});

// ─── Init Hooks ───────────────────────────────────────────────────────────────
add_action('init', function () {
    Shield_Stripe_Endpoint_Receiver::init();
    Shield_Stripe_Endpoint_Cron::register();
});

register_activation_hook(__FILE__, function () { Shield_Stripe_Endpoint_Cron::register(); });
register_deactivation_hook(__FILE__, function () { Shield_Stripe_Endpoint_Cron::unregister(); });
