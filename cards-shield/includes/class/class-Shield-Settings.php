<?php
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/helpers/helpers.php';

class ShieldSettings {
    const OPT_PROXY_KEY     = 'shield_proxy_key';      // sh_xxx từ SaaS
    const OPT_SAAS_URL      = 'shield_saas_url';       // URL của SaaS
    const OPT_CONNECT_STATUS = 'shield_connect_status'; // connected|pending|failed
    const OPT_CONNECT_DATA  = 'shield_connect_data';   // payload từ /api/shields/connect
    const OPT_LAST_SYNC_AT  = 'shield_last_sync_at';   // thời điểm sync-config thành công gần nhất
    const OPT_LAST_SYNC_STATUS = 'shield_last_sync_status'; // success|failed
    const OPT_LICENSE_KEY   = 'shield_license_key';    // HMAC key cho shield-manager

    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_notices', [$this, 'display_notice']);
        add_action('wp_ajax_shield_saas_connect', [$this, 'ajax_connect']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /* ── REST: SaaS push sync-config ───────────────────────────── */
    public function register_rest_routes() {
        register_rest_route('shield/v1', '/sync-config', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rest_sync_config'],
            'permission_callback' => '__return_true', // HMAC auth inside callback
        ]);
    }

    public function rest_sync_config(\WP_REST_Request $request) {
        $timestamp = $request->get_header('X-Shield-Timestamp');
        $signature = $request->get_header('X-Shield-Signature');
        $proxy_key = get_option(self::OPT_PROXY_KEY, '');

        if (!$proxy_key || !$timestamp || !$signature) {
            return new \WP_Error('unauthorized', 'Missing auth headers', ['status' => 401]);
        }

        // Replay window: 5 minutes
        if (abs(time() - (int) $timestamp) > 300) {
            return new \WP_Error('unauthorized', 'Timestamp expired', ['status' => 401]);
        }

        // Nonce: prevent replay within the 5-minute window
        $nonce_key = 'shield_sc_n_' . md5($proxy_key . '|' . $timestamp . '|' . $signature);
        if (get_transient($nonce_key)) {
            return new \WP_Error('unauthorized', 'Duplicate request', ['status' => 401]);
        }
        set_transient($nonce_key, '1', 300);

        $expected = hash_hmac('sha256', $proxy_key . '.' . $timestamp, $proxy_key);
        if (!hash_equals($expected, $signature)) {
            return new \WP_Error('unauthorized', 'Invalid signature', ['status' => 401]);
        }

        $body = $request->get_json_params();
        $cfg  = $body['paymentConfig'] ?? [];

        update_option('shield_paypal', [
            'prod_client_id'  => $cfg['paypalProdClientId']  ?? '',
            'prod_secret_key' => $cfg['paypalProdSecretKey'] ?? '',
            'test_mode'       => !empty($cfg['paypalTestMode']) ? '1' : '0',
            'test_client_id'  => $cfg['paypalTestClientId']  ?? '',
            'test_secret_key' => $cfg['paypalTestSecretKey'] ?? '',
        ]);
        update_option('shield_stripe', [
            'prod_publishable_key' => $cfg['stripeProdPublishableKey'] ?? '',
            'prod_secret_key'      => $cfg['stripeProdSecretKey']      ?? '',
            'test_mode'            => !empty($cfg['stripeTestMode']) ? '1' : '0',
            'test_publishable_key' => $cfg['stripeTestPublishableKey'] ?? '',
            'test_secret_key'      => $cfg['stripeTestSecretKey']      ?? '',
        ]);
        $synced_at = current_time('mysql');
        update_option(self::OPT_LAST_SYNC_AT, $synced_at);
        update_option(self::OPT_LAST_SYNC_STATUS, 'success');

        return rest_ensure_response([
            'synced' => true,
            'syncedAt' => $synced_at,
            'message' => 'site1 sync-config applied successfully',
        ]);
    }

    /* ── Menu ─────────────────────────────────────────────────────── */
    public function register_menu() {
        add_menu_page(
            'Shield Settings', 'Shield', 'manage_options',
            'shield-settings', [$this, 'shield_settings_page_callback'],
            'dashicons-shield', 4
        );
    }

    /* ── Admin page ───────────────────────────────────────────────── */
    public function shield_settings_page_callback() {
        $proxy_key     = get_option(self::OPT_PROXY_KEY, '');
        $saas_url      = get_option(self::OPT_SAAS_URL, SHIELD_MANAGE_URL);
        $conn_status   = get_option(self::OPT_CONNECT_STATUS, 'pending');
        $conn_data     = get_option(self::OPT_CONNECT_DATA, []);
        $last_sync_at  = get_option(self::OPT_LAST_SYNC_AT, '');
        $last_sync_status = get_option(self::OPT_LAST_SYNC_STATUS, 'pending');
        $shield_paypal = get_option('shield_paypal', []);
        $shield_stripe = get_option('shield_stripe', []);
        $shield_data   = get_option('shield_data', []);

        $status_badge = match($conn_status) {
            'connected' => '<span style="background:#00a32a;color:#fff;padding:2px 10px;border-radius:3px;font-size:13px">CONNECTED</span>',
            'failed'    => '<span style="background:#d63638;color:#fff;padding:2px 10px;border-radius:3px;font-size:13px">FAILED</span>',
            default     => '<span style="background:#dba617;color:#fff;padding:2px 10px;border-radius:3px;font-size:13px">PENDING</span>',
        };
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <!-- SaaS Connection Section -->
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px;margin:20px 0;max-width:700px">
                <h2 style="margin-top:0">Shield Proxy — SaaS Connection</h2>
                <p>Trạng thái: <?= $status_badge ?></p>
                <?php if ($conn_status === 'connected' && !empty($conn_data['shieldId'])) : ?>
                    <p style="color:#555;font-size:13px">
                        Shield ID: <code><?= esc_html($conn_data['shieldId']) ?></code> |
                        Name: <strong><?= esc_html($conn_data['name'] ?? '') ?></strong>
                    </p>
                <?php endif; ?>
                <?php if ($last_sync_at) : ?>
                    <p style="color:#555;font-size:13px">
                        Sync gần nhất:
                        <strong><?= esc_html($last_sync_at) ?></strong>
                        (<?= esc_html(strtoupper($last_sync_status)) ?>)
                    </p>
                <?php endif; ?>
                <table class="form-table" style="margin:0">
                    <tr>
                        <th><label for="sp_saas_url">SaaS URL</label></th>
                        <td>
                            <input type="url" id="sp_saas_url" value="<?= esc_attr($saas_url) ?>" style="width:400px" placeholder="https://shield.example.com">
                            <p class="description">URL của SaaS Shield (mặc định: <?= esc_html(SHIELD_MANAGE_URL) ?>)</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sp_proxy_key">Shield Proxy Key</label></th>
                        <td>
                            <input type="text" id="sp_proxy_key" value="<?= esc_attr($proxy_key) ?>" style="width:400px" placeholder="sh_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                            <p class="description">API Key do SaaS cấp sau khi Admin duyệt shield (bắt đầu bằng <code>sh_</code>)</p>
                        </td>
                    </tr>
                </table>
                <p>
                    <button id="sp-btn-connect" class="button button-primary">
                        <span id="sp-spinner" class="spinner" style="display:none;float:none;margin:0 5px 0 0"></span>
                        Kết nối với SaaS
                    </button>
                    <span id="sp-connect-msg" style="margin-left:10px;font-size:13px"></span>
                </p>
            </div>

            <h2>Shield Data</h2>
            <?= $this->generate_html_table($shield_data) ?>
            <h2>PayPal</h2>
            <?= $this->generate_html_table($shield_paypal) ?>
            <h2>Stripe</h2>
            <?= $this->generate_html_table($shield_stripe) ?>
        </div>

        <script>
        (function($) {
            $('#sp-btn-connect').on('click', function(e) {
                e.preventDefault();
                const key    = $('#sp_proxy_key').val().trim();
                const saasUrl = $('#sp_saas_url').val().trim();
                if (!key) { $('#sp-connect-msg').css('color','red').text('Vui lòng nhập Shield Proxy Key'); return; }
                if (!saasUrl) { $('#sp-connect-msg').css('color','red').text('Vui lòng nhập SaaS URL'); return; }

                $(this).prop('disabled', true);
                $('#sp-spinner').show();
                $('#sp-connect-msg').css('color','#555').text('Đang kết nối...');

                $.post(ajaxurl, {
                    action:  'shield_saas_connect',
                    nonce:   '<?= wp_create_nonce('shield_saas_connect') ?>',
                    proxy_key: key,
                    saas_url: saasUrl,
                }, function(data) {
                    $('#sp-btn-connect').prop('disabled', false);
                    $('#sp-spinner').hide();
                    if (data.success) {
                        $('#sp-connect-msg').css('color','#00a32a').text('✓ Kết nối thành công! Đang tải lại...');
                        setTimeout(() => location.reload(), 1200);
                    } else {
                        $('#sp-connect-msg').css('color','red').text('✗ ' + (data.data || 'Kết nối thất bại'));
                    }
                }).fail(function() {
                    $('#sp-btn-connect').prop('disabled', false);
                    $('#sp-spinner').hide();
                    $('#sp-connect-msg').css('color','red').text('Lỗi kết nối mạng');
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /* ── AJAX: kết nối với SaaS ───────────────────────────────────── */
    public function ajax_connect() {
        check_ajax_referer('shield_saas_connect', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error('Unauthorized'); }

        $proxy_key = sanitize_text_field($_POST['proxy_key'] ?? '');
        $saas_url  = esc_url_raw($_POST['saas_url'] ?? '');

        if (!$proxy_key || !$saas_url) {
            wp_send_json_error('Thiếu thông tin kết nối');
        }

        $domain   = get_site_url();
        $endpoint = trailingslashit($saas_url) . 'api/shields/connect';

        $response = wp_remote_post($endpoint, [
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => wp_json_encode(['shieldKey' => $proxy_key, 'domain' => $domain]),
            'timeout'   => 15,
            'sslverify' => false, // false cho local dev; set true cho production
        ]);

        if (is_wp_error($response)) {
            update_option(self::OPT_CONNECT_STATUS, 'failed');
            wp_send_json_error($response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && !empty($body['connected'])) {
            // Lưu key + SaaS URL
            update_option(self::OPT_PROXY_KEY, $proxy_key);
            update_option(self::OPT_SAAS_URL, $saas_url);
            update_option(self::OPT_CONNECT_STATUS, 'connected');
            update_option(self::OPT_CONNECT_DATA, $body);
            update_option(self::OPT_LAST_SYNC_STATUS, 'success');

            // Lưu payment config vào các option mà gateway sử dụng
            if (!empty($body['paymentConfig'])) {
                $cfg = $body['paymentConfig'];
                update_option('shield_paypal', [
                    'prod_client_id'  => $cfg['paypalProdClientId'] ?? '',
                    'prod_secret_key' => $cfg['paypalProdSecretKey'] ?? '',
                    'test_mode'       => !empty($cfg['paypalTestMode']) ? '1' : '0',
                    'test_client_id'  => $cfg['paypalTestClientId'] ?? '',
                    'test_secret_key' => $cfg['paypalTestSecretKey'] ?? '',
                ]);
                update_option('shield_stripe', [
                    'prod_publishable_key' => $cfg['stripeProdPublishableKey'] ?? '',
                    'prod_secret_key'      => $cfg['stripeProdSecretKey'] ?? '',
                    'test_mode'            => !empty($cfg['stripeTestMode']) ? '1' : '0',
                    'test_publishable_key' => $cfg['stripeTestPublishableKey'] ?? '',
                    'test_secret_key'      => $cfg['stripeTestSecretKey'] ?? '',
                ]);
            }
            update_option(self::OPT_LAST_SYNC_AT, current_time('mysql'));

            wp_send_json_success(['shieldId' => $body['shieldId'], 'name' => $body['name']]);
        } else {
            update_option(self::OPT_CONNECT_STATUS, 'failed');
            update_option(self::OPT_LAST_SYNC_STATUS, 'failed');
            $error = $body['message'] ?? "HTTP {$code}";
            wp_send_json_error($error);
        }
    }

    /* ── Verify cron callback ─────────────────────────────────────── */
    public static function cron_verify() {
        $proxy_key = get_option(self::OPT_PROXY_KEY, '');
        $saas_url  = get_option(self::OPT_SAAS_URL, SHIELD_MANAGE_URL);

        if (!$proxy_key) return;

        $response = wp_remote_post(trailingslashit($saas_url) . 'api/shields/verify', [
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => wp_json_encode(['shieldKey' => $proxy_key]),
            'timeout'   => 10,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) return;

        $body   = json_decode(wp_remote_retrieve_body($response), true);
        $active = !empty($body['active']);
        update_option(self::OPT_CONNECT_STATUS, $active ? 'connected' : 'failed');
        update_option(self::OPT_LAST_SYNC_STATUS, $active ? 'success' : 'failed');

        // Sync payment config nếu SaaS trả về (keys có thể đã thay đổi)
        if ($active && !empty($body['paymentConfig'])) {
            $cfg = $body['paymentConfig'];
            update_option('shield_paypal', [
                'prod_client_id'  => $cfg['paypalProdClientId']  ?? '',
                'prod_secret_key' => $cfg['paypalProdSecretKey'] ?? '',
                'test_mode'       => !empty($cfg['paypalTestMode']) ? '1' : '0',
                'test_client_id'  => $cfg['paypalTestClientId']  ?? '',
                'test_secret_key' => $cfg['paypalTestSecretKey'] ?? '',
            ]);
            update_option('shield_stripe', [
                'prod_publishable_key' => $cfg['stripeProdPublishableKey'] ?? '',
                'prod_secret_key'      => $cfg['stripeProdSecretKey']      ?? '',
                'test_mode'            => !empty($cfg['stripeTestMode']) ? '1' : '0',
                'test_publishable_key' => $cfg['stripeTestPublishableKey'] ?? '',
                'test_secret_key'      => $cfg['stripeTestSecretKey']      ?? '',
            ]);
            update_option(self::OPT_LAST_SYNC_AT, current_time('mysql'));
        }
    }

    /* ── Helpers ──────────────────────────────────────────────────── */
    public function generate_html_table($data) {
        $html = '<table class="form-table" role="presentation"><tbody>';
        foreach ((array)$data as $key => $value) {
            $html .= '<tr><th scope="row"><span>' . esc_html($key) . '</span></th>'
                   . '<td><input type="text" value="' . esc_attr($value) . '" style="width:100%;" disabled></td></tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    public function register_settings_fields() {
        // Legacy License Key UI removed. HMAC manager auth uses v2 keyring.
    }

    public function render_license_key_field($args) {
        $val = get_option(self::OPT_LICENSE_KEY, '');
        printf('<input type="text" id="%s" name="%s" value="%s" style="width:100%%" />',
            esc_attr($args['label_for']), esc_attr($args['name']), esc_attr($val));
        echo '<p class="description">Key dùng để xác thực HMAC giữa Shield Manager (site2) và plugin này.</p>';
    }

    public function validate_license_key($license_key) {
        return sanitize_text_field($license_key);
    }

    public function display_notice() {
        $errors = get_settings_errors('shield_license_key');
        if (!empty($errors)) return;
        if (
            isset($_GET['page'], $_GET['settings-updated']) &&
            $_GET['page'] === 'shield-settings' && $_GET['settings-updated']
        ) { ?>
            <div class="notice notice-success is-dismissible"><p><strong>Shield settings saved.</strong></p></div>
        <?php }
    }
}

new ShieldSettings();
