<?php
if (!defined('ABSPATH')) { exit; }
class Shield_Sync_Queue {
    const OPTION_KEY    = 'OPT_SHIELD_SYNC_QUEUE';
    const CRON_HOOK     = 'shield_sync_queue_cron';
    const CRON_INTERVAL = 'shield_every_1min';
    const MAX_RETRIES   = 5;
    const DELAYS        = [60, 300, 900, 3600, 14400];

    public static function register() {
        add_filter('cron_schedules', [__CLASS__, 'add_cron_interval']);
        add_action(self::CRON_HOOK, [__CLASS__, 'process']);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), self::CRON_INTERVAL, self::CRON_HOOK);
        }
    }
    public static function unregister() {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) wp_unschedule_event($ts, self::CRON_HOOK);
    }
    public static function add_cron_interval($schedules) {
        $schedules[self::CRON_INTERVAL] = ['interval' => 60, 'display' => 'Every 1 Minute'];
        return $schedules;
    }
    public static function all()   { return get_option(self::OPTION_KEY, []); }
    public static function enqueue($site_id, $endpoint, $payload) {
        $queue   = self::all();
        $queue[] = ['id' => uniqid('sq_', true), 'site_id' => $site_id, 'endpoint' => $endpoint,
                    'payload' => $payload, 'attempts' => 0, 'next_retry' => time(), 'created_at' => time(), 'error' => ''];
        update_option(self::OPTION_KEY, $queue, false);
    }
    public static function enqueue_all($endpoint, $payload) {
        foreach (Shield_Site_Registry::active_sites() as $site) {
            self::enqueue($site['id'], $endpoint, $payload);
        }
    }
    public static function process() {
        $queue   = self::all();
        $updated = [];
        foreach ($queue as $item) {
            if ($item['next_retry'] > time()) { $updated[] = $item; continue; }
            $site = Shield_Site_Registry::find($item['site_id']);
            if (!$site) continue;
            $result = Shield_API_Client::post($site, $item['endpoint'], $item['payload']);
            if ($result['success']) {
                Shield_Site_Registry::update_sync($site['id'], 'synced');
            } else {
                $http_code = (int)($result['code'] ?? 0);
                // 4xx = client/permanent error — do not retry.
                $permanent_failure = ($http_code >= 400 && $http_code < 500);
                $item['attempts']++;
                $item['error'] = $result['error'] ?: sprintf('HTTP %d', $http_code);
                if ($permanent_failure || $item['attempts'] >= self::MAX_RETRIES) {
                    Shield_Site_Registry::update_sync($site['id'], 'failed');
                    self::notify_failed($site, $item);
                } else {
                    $delays = self::DELAYS;
                    $delay  = $delays[min($item['attempts'] - 1, count($delays) - 1)];
                    $item['next_retry'] = time() + $delay;
                    $updated[] = $item;
                    Shield_Site_Registry::update_sync($site['id'], 'pending');
                }
            }
        }
        update_option(self::OPTION_KEY, $updated, false);
    }
    private static function notify_failed($site, $item) {
        wp_mail(
            get_option('admin_email'),
            sprintf('[Shield Manager] Sync failed: %s', $site['label']),
            sprintf("Could not sync \"%s\" to \"%s\" after %d attempts.\n\nLast error: %s",
                    $item['endpoint'], $site['label'], $item['attempts'], $item['error'])
        );
    }
}
