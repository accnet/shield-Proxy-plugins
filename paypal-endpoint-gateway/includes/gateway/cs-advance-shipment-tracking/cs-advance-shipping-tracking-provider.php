<?php
if (!defined('ABSPATH')) {
    exit;
}

class EP_PayPal_Advance_Shipping_Tracking_Provider
{
    private static $instance;

    public static function get_instance()
    {

        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getPaypalProvider($tsSlug)
    {
        try {
            global $wpdb;
            return $wpdb->get_row($wpdb->prepare('SELECT paypal_slug, provider_url FROM %1s WHERE ts_slug = %s', $wpdb->prefix . 'cs_woo_shippment_provider', $tsSlug));;
        } catch (\Exception $e) {
            ep_paypal_debug_log($e->getMessage(), 'EP_PayPal_Advance_Shipping_Tracking_Provider::getPaypalProvider Exception');
            return false;
        }
    }
}
