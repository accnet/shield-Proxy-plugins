<?php
if (!defined('ABSPATH')) { exit; }

add_action('wp_ajax_shield_sites_action', function () {
    if (!current_user_can('manage_options')) { wp_send_json_error(['error' => 'Unauthorized'], 403); }
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'shield_sites_nonce')) {
        wp_send_json_error(['error' => 'Invalid security token'], 403);
    }
    $command = isset($_POST['command']) ? sanitize_text_field($_POST['command']) : '';
    switch ($command) {
        case 'add_site':
            $label   = sanitize_text_field($_POST['label']   ?? '');
            $url     = esc_url_raw($_POST['url']             ?? '');
            $license = sanitize_text_field($_POST['license'] ?? '');
            $bootstrap_token = sanitize_text_field($_POST['bootstrap_token'] ?? '');
            if (!$label || !$url) { echo json_encode(['success' => false, 'error' => 'Label and URL are required.']); break; }
            if (!filter_var($url, FILTER_VALIDATE_URL)) { echo json_encode(['success' => false, 'error' => 'Invalid URL.']); break; }
            $site = Shield_Site_Registry::add($url, $label, $license, $bootstrap_token);

            $bootstrap = ['success' => false, 'error' => ''];
            if (!empty($bootstrap_token)) {
                $bootstrap = Shield_API_Client::bootstrap_v2($site, $bootstrap_token);
                Shield_Site_Registry::update($site['id'], [
                    'bootstrap_status' => $bootstrap['success'] ? 'ready' : 'failed',
                ]);
            }

            Shield_Health_Checker::check($site);
            $site = Shield_Site_Registry::find($site['id']);
            echo json_encode([
                'success' => true,
                'site' => $site,
                'bootstrap' => $bootstrap,
            ]);
            break;
        case 'rotate_key':
            $site_id = sanitize_text_field($_POST['site_id'] ?? '');
            $site = Shield_Site_Registry::find($site_id);
            if (!$site) { echo json_encode(['success' => false, 'error' => 'Site not found.']); break; }

            $token = sanitize_text_field($_POST['bootstrap_token'] ?? ($site['bootstrap_token'] ?? ''));
            if (!$token) { echo json_encode(['success' => false, 'error' => 'Bootstrap token required.']); break; }

            $res = Shield_API_Client::rotate_v2($site, $token, true);
            if (!$res['success']) { echo json_encode(['success' => false, 'error' => $res['error'] ?: 'Rotate failed']); break; }

            $body = $res['body'];
            Shield_Site_Registry::update($site_id, [
                'key_id' => sanitize_text_field($body['key_id'] ?? ''),
                'hmac_secret' => sanitize_text_field($body['hmac_secret'] ?? ''),
                'bootstrap_token' => $token,
                'bootstrap_status' => 'ready',
            ]);

            echo json_encode(['success' => true, 'site' => Shield_Site_Registry::find($site_id)]);
            break;
        case 'set_primary':
            $site_id = sanitize_text_field($_POST['site_id'] ?? '');
            $site = Shield_Site_Registry::find($site_id);
            if (!$site) { echo json_encode(['success' => false, 'error' => 'Site not found.']); break; }

            $token = sanitize_text_field($_POST['bootstrap_token'] ?? ($site['bootstrap_token'] ?? ''));
            if (!$token) { echo json_encode(['success' => false, 'error' => 'Bootstrap token required.']); break; }

            $res = Shield_API_Client::set_primary_v2($site, $token);
            if (!$res['success']) { echo json_encode(['success' => false, 'error' => $res['error'] ?: 'Set primary failed']); break; }

            foreach (Shield_Site_Registry::all() as $s) {
                Shield_Site_Registry::update($s['id'], ['is_primary_manager' => $s['id'] === $site_id ? '1' : '0']);
            }

            echo json_encode(['success' => true, 'site' => Shield_Site_Registry::find($site_id)]);
            break;
        case 'revoke_site':
            $site_id = sanitize_text_field($_POST['site_id'] ?? '');
            $site = Shield_Site_Registry::find($site_id);
            if (!$site) { echo json_encode(['success' => false, 'error' => 'Site not found.']); break; }

            $token = sanitize_text_field($_POST['bootstrap_token'] ?? ($site['bootstrap_token'] ?? ''));
            if (!$token) { echo json_encode(['success' => false, 'error' => 'Bootstrap token required.']); break; }

            $res = Shield_API_Client::revoke_v2($site, $token);
            if (!$res['success']) { echo json_encode(['success' => false, 'error' => $res['error'] ?: 'Revoke failed']); break; }

            Shield_Site_Registry::update($site_id, [
                'status' => 'disabled',
                'sync_status' => 'failed',
            ]);

            echo json_encode(['success' => true, 'site' => Shield_Site_Registry::find($site_id)]);
            break;
        case 'ping_site':
            $site_id = sanitize_text_field($_POST['site_id'] ?? '');
            $site    = Shield_Site_Registry::find($site_id);
            if (!$site) { echo json_encode(['success' => false, 'error' => 'Site not found.']); break; }
            Shield_Health_Checker::check($site);
            $site = Shield_Site_Registry::find($site_id);
            echo json_encode(['success' => $site['status'] === 'active', 'status' => $site['status'],
                              'gateways' => $site['gateways'], 'error' => $site['status'] !== 'active' ? 'Site did not respond.' : '']);
            break;
        case 'sync_site':
            $site_id = sanitize_text_field($_POST['site_id'] ?? '');
            $site    = Shield_Site_Registry::find($site_id);
            if (!$site) { echo json_encode(['success' => false, 'error' => 'Site not found.']); break; }
            // PayPal/Stripe credentials on site1 are managed exclusively by the SaaS via HMAC sync-config.
            // Do NOT push woocommerce_wootify_paypal_settings or woocommerce_stripe_settings here
            // as those options contain WooCommerce gateway UI settings, not API credentials,
            // and would overwrite the credentials already pushed by the SaaS.
            Shield_Site_Registry::update_sync($site_id, 'synced');
            echo json_encode(['success' => true]);
            break;
        case 'delete_site':
            $site_id = sanitize_text_field($_POST['site_id'] ?? '');
            echo json_encode(['success' => Shield_Site_Registry::delete($site_id)]);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Unknown command.']);
    }
    wp_die();
});
