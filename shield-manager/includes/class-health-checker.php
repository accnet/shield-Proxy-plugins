<?php
if (!defined('ABSPATH')) { exit; }
class Shield_Health_Checker {
    const CRON_HOOK     = 'shield_health_check_cron';
    const CRON_INTERVAL = 'shield_every_5min';
    const OFFLINE_NOTIFY_THRESHOLD_MIN = 15;

    public static function register() {
        add_filter('cron_schedules', [__CLASS__, 'add_cron_interval']);
        add_action(self::CRON_HOOK, [__CLASS__, 'run']);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), self::CRON_INTERVAL, self::CRON_HOOK);
        }
    }
    public static function unregister() {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) wp_unschedule_event($ts, self::CRON_HOOK);
    }
    public static function add_cron_interval($schedules) {
        $schedules[self::CRON_INTERVAL] = ['interval' => 300, 'display' => 'Every 5 Minutes'];
        return $schedules;
    }
    public static function run() {
        foreach (Shield_Site_Registry::all() as $site) {
            if ($site['status'] === 'disabled') continue;
            self::check($site);
        }
    }
    public static function check($site) {
        $result = Shield_API_Client::health($site);
        if ($result['success']) {
            $body = $result['body'];
            Shield_Site_Registry::update($site['id'], [
                'status'    => 'active',
                'last_ping' => time(),
                'gateways'  => $body['gateways'] ?? [],
                'version'   => $body['version']  ?? '',
            ]);
        } else {
            $was_active    = isset($site['status']) && $site['status'] === 'active';
            $offline_since = $site['last_ping'] ?? time();
            $offline_min   = (int)round((time() - $offline_since) / 60);
            Shield_Site_Registry::update($site['id'], ['status' => 'offline', 'last_ping' => time()]);
            if ($was_active || $offline_min >= self::OFFLINE_NOTIFY_THRESHOLD_MIN) {
                self::notify_offline($site, $result['error'] ?? '');
            }
        }
    }
    private static function notify_offline($site, $error) {
        wp_mail(
            get_option('admin_email'),
            sprintf('[Shield Manager] Proxy offline: %s', $site['label']),
            sprintf("Proxy site \"%s\" (%s) is not responding.\n\nError: %s", $site['label'], $site['url'], $error ?: 'unknown')
        );
    }
}
