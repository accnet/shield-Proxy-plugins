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
        $pull_hook = self::prefix() . 'CONFIG_PULL';
        $flush_hook = self::prefix() . 'TX_QUEUE_FLUSH';

        // Add 5-minute schedule
        add_filter('cron_schedules', [__CLASS__, 'add_cron_schedule']);

        // Schedule config pull if not already scheduled
        if (!wp_next_scheduled($pull_hook)) {
            wp_schedule_event(time(), 'five_minutes', $pull_hook);
        }

        // Schedule queue flush if not already scheduled
        if (!wp_next_scheduled($flush_hook)) {
            wp_schedule_event(time(), 'five_minutes', $flush_hook);
        }

        // Hook the actions
        add_action($pull_hook, [__CLASS__, 'do_config_pull']);
        add_action($flush_hook, [__CLASS__, 'do_queue_flush']);
    }

    /**
     * Unregister cron hooks.
     */
    public static function unregister()
    {
        wp_clear_scheduled_hook(self::prefix() . 'CONFIG_PULL');
        wp_clear_scheduled_hook(self::prefix() . 'TX_QUEUE_FLUSH');
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

    /**
     * Flush the transaction retry queue.
     */
    public static function do_queue_flush()
    {
        if (!class_exists('Shield_PayPal_Endpoint_Client')) {
            return;
        }

        Shield_PayPal_Endpoint_Client::flush_queue();
    }
}
