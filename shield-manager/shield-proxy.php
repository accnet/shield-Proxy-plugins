<?php

/**
 *  Shiled Proxy
 *
 * @package     Shield Proxy
 * @author      Wootify
 * @copyright   2023 Wootify
 * @license     GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name: Shield Proxy Manager
 * Plugin URI:  https://wootify.dev
 * Description: Shield Proxy Manager
 * Version:     2.0.0
 * Author:      Wootify
 * Author URI:  https://wootify.dev
 * Text Domain: CS
 * License:     GPL v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */
if (!defined('ABSPATH')) {
  exit;
}
if (!function_exists('is_plugin_active'))
  require_once(ABSPATH . '/wp-admin/includes/plugin.php');
define('SHILED_PROXY_VERSION', '2.1.3');
if (!defined('SHIELD_MANAGER_PLUGIN_FILE')) {
  define('SHIELD_MANAGER_PLUGIN_FILE', __FILE__);
}
if (!defined('SHIELD_MANAGER_PLUGIN_DIR')) {
  define('SHIELD_MANAGER_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('SHIELD_MANAGER_PLUGIN_URL')) {
  define('SHIELD_MANAGER_PLUGIN_URL', plugin_dir_url(__FILE__));
}
const ECOM_PAYPAL_PLUGIN = 'cardsshield-gateway-paypal/wootify-paygate.php';
const ECOM_STRIPE_PLUGIN = 'cardsshield-gateway-stripe/wootify-gateway-stripe.php';
const ECOM_PAYPAL_PATH = 'includes/cardsshield-gateway-paypal/wootify-paygate.php';
const ECOM_STRIPE_PATH = 'includes/cardsshield-gateway-stripe/wootify-gateway-stripe.php';


function enqueue_scripts_shield_proxy()
{
  // bootstrap
  wp_enqueue_style('bootstrap', "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css");
  wp_enqueue_script("bootstrap", "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js");
  // css
  wp_enqueue_style("custom", plugins_url('assets/css/custom.css', __FILE__), [], SHILED_PROXY_VERSION);

  // js
  wp_enqueue_script("toast", plugins_url('assets/toast/toast.js', __FILE__), [], SHILED_PROXY_VERSION);
  wp_enqueue_style("toast", plugins_url('assets/toast/toast.css', __FILE__), [], SHILED_PROXY_VERSION);

  wp_register_script("WOOTIFY_swal2", "https://cdn.jsdelivr.net/npm/sweetalert2@8");
  wp_enqueue_script("WOOTIFY_swal2");

  // in JavaScript, object properties are accessed as ajax_object.ajax_url, ajax_object.we_value
  wp_localize_script('bootstrap', 'cs_ajax_object', ['ajax_url' => admin_url('admin-ajax.php'), 'we_value' => 1234]);
}
function custom_redirect($old_url, $new_url)
{
  if (strpos($_SERVER['REQUEST_URI'], $old_url) !== false) {
    $new_url = admin_url('admin.php?' . $new_url);
    wp_redirect($new_url, 301);
    exit;
  }
}
function perform_custom_redirects()
{
  custom_redirect('page=wc-settings&tab=checkout&section=WOOTIFY_stripe', 'page=shiled-proxy&tab=nav-stripe-tab');
  custom_redirect('page=wc-settings&tab=checkout&section=WOOTIFY_paypal', 'page=shiled-proxy');
}
function shield_proxy_options_panel()
{
  // Shield Proxy Settings
  add_menu_page('Shield Proxy', 'Shield Proxy', 'manage_options', 'shiled-proxy', 'shield_proxy_settings', 'dashicons-shield');
  $cards_shield = add_submenu_page('shiled-proxy', 'Settings', 'Settings', 'manage_options', 'shiled-proxy', 'shield_proxy_settings');

  // Rotation Manager
  add_menu_page('Rotation Manager', 'Rotation Manager', 'manage_options', 'shiled_proxy-paypal-rotation', 'shield_proxy_paypal_rotation', 'dashicons-randomize');
  $paypal = add_submenu_page('shiled_proxy-paypal-rotation', 'PayPal Rotation', 'PayPal Rotation', 'manage_options', 'shiled_proxy-paypal-rotation', 'shield_proxy_paypal_rotation');
  $stripe = add_submenu_page('shiled_proxy-paypal-rotation', 'Stripe Rotation', 'Stripe Rotation', 'manage_options', 'shiled_proxy-stripe-rotation', 'shield_proxy_stripe_rotation');

  add_action('load-' . $cards_shield, 'enqueue_scripts_shield_proxy');
  add_action('load-' . $paypal, 'enqueue_scripts_shield_proxy');
  add_action('load-' . $stripe, 'enqueue_scripts_shield_proxy');

  add_action('load-' . $cards_shield, function () {
    wp_enqueue_script("shield_proxy_settings", plugins_url('assets/js/settings.js', __FILE__), [], SHILED_PROXY_VERSION);
    wp_localize_script('shield_proxy_settings', 'ShieldSettings', array(
      'nonce' => wp_create_nonce('cards_shield_settings_nonce')
    ));
    wp_enqueue_style("settings", plugins_url('assets/css/settings.css', __FILE__), [], SHILED_PROXY_VERSION);
    wp_enqueue_style('select2', "https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css");
    wp_enqueue_script("select2", "https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js");
  });
  add_action('load-' . $paypal, function () {
    wp_enqueue_script("rotation", plugins_url('assets/js/rotation.js', __FILE__), [], SHILED_PROXY_VERSION);
    wp_localize_script('rotation', 'CS', array(
      'PG' => 'PayPal',
      'nonce' => wp_create_nonce('rotation_action_nonce')
    ));
    wp_enqueue_script('jquery-ui-sortable');
  });
  add_action('load-' . $stripe, function () {
    wp_enqueue_script("rotation", plugins_url('assets/js/rotation.js', __FILE__), [], SHILED_PROXY_VERSION);
    wp_localize_script('rotation', 'CS', array(
      'PG' => 'Stripe',
      'nonce' => wp_create_nonce('rotation_action_nonce')
    ));
    wp_enqueue_script('jquery-ui-sortable');
  });
}
function shield_proxy_settings()
{
  include __DIR__ . '/views/settings.php';
}
function shield_proxy_paypal_rotation()
{
  include __DIR__ . '/views/paypal-rotation.php';
}
function shield_proxy_stripe_rotation()
{
  include __DIR__ . '/views/stripe-rotation.php';
}
function shield_proxy_cleanup_legacy_sync_queue_cron($force = false)
{
  if (!$force && get_option('OPT_SHIELD_LEGACY_SYNC_QUEUE_CRON_CLEANED', 'no') === 'yes') {
    return;
  }
  wp_clear_scheduled_hook('shield_sync_queue_cron');
  update_option('OPT_SHIELD_LEGACY_SYNC_QUEUE_CRON_CLEANED', 'yes', true);
}

if (is_plugin_active(ECOM_PAYPAL_PLUGIN) || is_plugin_active(ECOM_STRIPE_PLUGIN)) {
  function devvn_quickbuy_admin_notice__error()
  {
    $class = 'notice notice-alt notice-warning notice-error';
    $title = '<h2 class="notice-title">Chú ý!</h2>';
    $message = 'Cần hủy kích hoạt plugin <strong>CardsShield Gateway Stripe</strong> và <strong>CardsShield Gateway PayPal</strong>.';
    printf('<div class="%1$s">%2$s<p>%3$s</p></div>', esc_attr($class), $title, $message);
  }
  add_action('admin_notices', 'devvn_quickbuy_admin_notice__error');
}
else {
  require_once(plugin_dir_path(__FILE__) . 'includes/constants.php');

  require_once(plugin_dir_path(__FILE__) . ECOM_PAYPAL_PATH);
  require_once(plugin_dir_path(__FILE__) . ECOM_STRIPE_PATH);

  add_action('init', function () {
    remove_action('admin_menu', 'add_WOOTIFY_paypal_paygate_menu');
  });

  require_once(plugin_dir_path(__FILE__) . 'utils.php');
  require_once(plugin_dir_path(__FILE__) . 'includes/class-shield-option-manager.php');
  require_once(plugin_dir_path(__FILE__) . 'includes/class-site-registry.php');
  require_once(plugin_dir_path(__FILE__) . 'includes/class-shield-api-client.php');
  require_once(plugin_dir_path(__FILE__) . 'includes/class-health-checker.php');
  require_once(plugin_dir_path(__FILE__) . 'includes/class-saas-client.php');
  require_once(plugin_dir_path(__FILE__) . 'includes/class-saas-receiver.php');
  require_once(plugin_dir_path(__FILE__) . 'includes/settings.php');
  require_once(plugin_dir_path(__FILE__) . 'includes/rotation.php');
  require_once(plugin_dir_path(__FILE__) . 'includes/class-proxy-failover.php');

  // Register active cron jobs and SaaS webhook receiver.
  add_action('init', function () {
    shield_proxy_cleanup_legacy_sync_queue_cron(false);
    Shield_Health_Checker::register();
    Shield_SaaS_Receiver::init();
    Shield_Proxy_Failover::init();
  });

  add_action('init', 'perform_custom_redirects');
  add_action('admin_menu', 'shield_proxy_options_panel');
}

// Activation / deactivation hooks for cron cleanup.
register_activation_hook(__FILE__, function () {
  shield_proxy_cleanup_legacy_sync_queue_cron(true);
  Shield_Health_Checker::register();
});
register_deactivation_hook(__FILE__, function () {
  shield_proxy_cleanup_legacy_sync_queue_cron(true);
  Shield_Health_Checker::unregister();
});
