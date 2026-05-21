<?php

/**
 * Cards Shield
 *
 * @package     Cards Shield
 * @author      Wootify
 * @copyright   2023 Wootify
 * @license     GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name: Cards Shield
 * Plugin URI:  https://wootify.dev
 * Description: This plugin prints "Cards Shield" inside an admin page.
 * Version:     3.0
 * Author:      Wootify
 * Author URI:  https://wootify.dev
 * Text Domain: CS
 * License:     GPL v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

define('SHIELD_MANAGE_URL', 'https://shieldzzz.com');
define('CARDSSHIELD_VERSION', '3.0');

define('CARDSSHIELD_PLUGIN', __FILE__);

define('CARDSSHIELD_PLUGIN_BASENAME', plugin_basename(CARDSSHIELD_PLUGIN));

define('CARDSSHIELD_PLUGIN_NAME', trim(dirname(CARDSSHIELD_PLUGIN_BASENAME), '/'));

define('CARDSSHIELD_PLUGIN_DIR', untrailingslashit(dirname(CARDSSHIELD_PLUGIN)));

define('CARDSSHIELD_PLUGIN_PUBLIC_DIR', CARDSSHIELD_PLUGIN_DIR . '/public');

define('CARDSSHIELD_PLUGIN_URL', plugin_dir_url(__FILE__));


require_once CARDSSHIELD_PLUGIN_DIR . '/includes/class/class-rewrite-and-template-manager.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/shield-api.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/class/class-Shield-Settings.php';

// ── SaaS verify cron ──────────────────────────────────────────────────────
add_filter('cron_schedules', function ($schedules) {
    $schedules['shield_every_6h'] = ['interval' => 21600, 'display' => 'Every 6 Hours'];
    return $schedules;
});

add_action('shield_saas_verify_cron', ['ShieldSettings', 'cron_verify']);

register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('shield_saas_verify_cron')) {
        wp_schedule_event(time(), 'shield_every_6h', 'shield_saas_verify_cron');
    }
});

register_deactivation_hook(__FILE__, function () {
    $ts = wp_next_scheduled('shield_saas_verify_cron');
    if ($ts) wp_unschedule_event($ts, 'shield_saas_verify_cron');
});
// ─────────────────────────────────────────────────────────────────────────

add_action('plugins_loaded', 'misha_init_gateway_class');
function misha_init_gateway_class()
{
  add_filter('woocommerce_checkout_redirect_empty_cart', '__return_false');
  add_filter('woocommerce_checkout_update_order_review_expired', '__return_false');
  require_once CARDSSHIELD_PLUGIN_DIR . '/includes/class/class-PayPal-Gateway.php';
  require_once CARDSSHIELD_PLUGIN_DIR . '/includes/class/class-Stripe-Gateway.php';
  add_action('woocommerce_review_order_after_payment', 'mecom_paypal_add_button_credit');
  function mecom_paypal_add_button_credit()
  {
    echo '<div id="wootify-paypal-credit-form-container" style="display:none"><div id="paypal-button-container"></div></div>';
  }
  add_filter('woocommerce_payment_gateways', 'misha_add_gateway_class');
  function misha_add_gateway_class($gateways)
  {
    $gateways[] = 'WC_CS_PayPal_Gateway';
    $gateways[] = 'WC_CS_Stripe_Gateway';
    return $gateways;
  }
}