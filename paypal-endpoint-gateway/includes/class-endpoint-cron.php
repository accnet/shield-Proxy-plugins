<?php
/**
 * Endpoint Cron
 * WP Cron job for periodic config pull from SaaS (fallback)
 *
 * @package Endpoint_Gateway
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Shield_PayPal_Endpoint_Cron
{
    private static function prefix()
    {
        return 'EP_PP_';
    }

    /**
     * Register cron hooks.
     */
    public static function register()
    {
        $hook = self::prefix() . 'CONFIG_PULL';

        // Add 5-minute schedule
        add_filter('cron_schedules', [__CLASS__, 'add_cron_schedule']);

        // Schedule if not already scheduled
        if (!wp_next_scheduled($hook)) {
            wp_schedule_event(time(), 'five_minutes', $hook);
        }

        // Hook the pull action
        add_action($hook, [__CLASS__, 'do_config_pull']);
    }

    /**
     * Unregister cron hooks.
     */
    public static function unregister()
    {
        $hook = self::prefix() . 'CONFIG_PULL';
        wp_clear_scheduled_hook($hook);
    }

    /**
     * Add 5-minute cron schedule.
     */
    public static function add_cron_schedule($schedules)
    {
        if (!isset($schedules['five_minutes'])) {
            $schedules['five_minutes'] = [
                'interval' => 300,
                'display'  => esc_html__('Every 5 minutes'),
            ];
        }
        return $schedules;
    }

    /**
     * Execute config pull from SaaS.
     */
    public static function do_config_pull()
    {
        if (!class_exists('Shield_PayPal_Endpoint_Client')) {
            return;
        }

        Shield_PayPal_Endpoint_Client::pull_config();
    }
}
