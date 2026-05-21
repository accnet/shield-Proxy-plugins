<?php
if (!defined('ABSPATH')) { exit; }
class Shield_Site_Registry {
    const OPTION_KEY = 'OPT_SHIELD_CONNECTED_SITES';
    public static function all() { return get_option(self::OPTION_KEY, []); }
    public static function find($site_id) {
        foreach (self::all() as $site) {
            if ($site['id'] === $site_id) return $site;
        }
        return null;
    }
    private static function generate_manager_id() {
        return 'mgr_' . str_replace('-', '', wp_generate_uuid4());
    }
    private static function generate_key_id() {
        return 'kid_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 16);
    }
    private static function generate_hmac_secret() {
        return bin2hex(random_bytes(32));
    }
    public static function add($url, $label, $license_key = '', $bootstrap_token = '') {
        $sites = self::all();
        $site = [
            'id'          => uniqid('cs_site_', true),
            'url'         => trailingslashit(esc_url_raw($url)),
            'label'       => sanitize_text_field($label),
            'license_key' => sanitize_text_field($license_key),
            'bootstrap_token' => sanitize_text_field($bootstrap_token),
            'manager_id'  => self::generate_manager_id(),
            'key_id'      => self::generate_key_id(),
            'hmac_secret' => self::generate_hmac_secret(),
            'hmac_version'=> 'v2',
            'bootstrap_status' => 'pending',
            'status'      => 'pending',
            'sync_status' => 'pending',
            'last_ping'   => null,
            'last_sync'   => null,
            'gateways'    => [],
            'version'     => '',
            'created_at'  => time(),
        ];
        $sites[] = $site;
        update_option(self::OPTION_KEY, $sites, true);
        return $site;
    }
    public static function update($site_id, $data) {
        $sites = self::all();
        foreach ($sites as &$site) {
            if ($site['id'] === $site_id) { $site = array_merge($site, $data); break; }
        }
        unset($site);
        return update_option(self::OPTION_KEY, $sites, true);
    }
    public static function delete($site_id) {
        $sites = array_values(array_filter(self::all(), function ($s) use ($site_id) {
            return $s['id'] !== $site_id;
        }));
        return update_option(self::OPTION_KEY, $sites, true);
    }
    public static function update_status($site_id, $status, $last_ping = null) {
        return self::update($site_id, ['status' => $status, 'last_ping' => $last_ping ?? time()]);
    }
    public static function update_sync($site_id, $sync_status) {
        return self::update($site_id, ['sync_status' => $sync_status, 'last_sync' => time()]);
    }
    public static function active_sites() {
        return array_values(array_filter(self::all(), function ($s) {
            return $s['status'] !== 'disabled';
        }));
    }
}
