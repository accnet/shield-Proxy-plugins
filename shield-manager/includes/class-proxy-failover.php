<?php
if (!defined('ABSPATH')) { exit; }

class Shield_Proxy_Failover {
    const STATE_OPTION = 'OPT_SHIELD_PROXY_FAILOVER_STATE';
    const MAX_FAILURES_BEFORE_UNUSED = 3;

    public static function init() {
        add_action('wp_ajax_shield_proxy_frame_status', [__CLASS__, 'ajax_frame_status']);
        add_action('wp_ajax_nopriv_shield_proxy_frame_status', [__CLASS__, 'ajax_frame_status']);
    }

    public static function ajax_frame_status() {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field((string) $_POST['nonce']) : '';
        if (!wp_verify_nonce($nonce, 'shield_proxy_frame_status')) {
            wp_send_json_error(['message' => 'Invalid security token'], 403);
        }

        $gateway = strtolower(sanitize_text_field((string) ($_POST['gateway'] ?? '')));
        $reason = sanitize_text_field((string) ($_POST['reason'] ?? 'frame_timeout'));
        if ($gateway !== 'stripe') {
            wp_send_json_error(['message' => 'Unsupported gateway'], 400);
        }

        $result = self::recover_stripe_checkout_proxy($reason, false);
        if (!empty($result['active'])) {
            wp_send_json_success([
                'message' => 'Proxy recovered.',
                'reload_checkout' => true,
                'proxy_id' => $result['proxy']['id'] ?? '',
                'proxy_url' => $result['proxy']['url'] ?? '',
                'status' => $result['status'] ?? '',
                'reason' => $result['reason'] ?? '',
            ]);
        }

        wp_send_json_error([
            'message' => 'No usable Stripe proxy is available.',
            'reload_checkout' => true,
            'status' => $result['status'] ?? 'failed',
            'reason' => $result['reason'] ?? $reason,
        ], 503);
    }

    public static function ensure_stripe_checkout_proxy() {
        return self::recover_stripe_checkout_proxy('checkout_health', false);
    }

    private static function recover_stripe_checkout_proxy($reason, $force_rotate_first = false) {
        if (!function_exists('findAndSetNextProxy') || !function_exists('csStripeGenerateTraceId')) {
            return ['active' => false, 'status' => 'unavailable', 'reason' => 'missing_runtime'];
        }

        if ($force_rotate_first) {
            self::rotate_stripe_proxy($reason, false);
        }

        $attempted = [];
        for ($i = 0; $i < 25; $i++) {
            findAndSetNextProxy();
            $proxy = self::stripe_session_proxy();
            if (empty($proxy['url'])) {
                return ['active' => false, 'status' => 'missing_proxy', 'reason' => $reason];
            }

            $proxy_id = (string) ($proxy['id'] ?? md5((string) $proxy['url']));
            if (isset($attempted[$proxy_id])) {
                return ['active' => false, 'status' => 'no_new_proxy', 'reason' => $reason];
            }
            $attempted[$proxy_id] = true;

            $check = self::check_stripe_proxy($proxy, $reason);
            if (($check['status'] ?? '') === 'active') {
                self::clear_failure('stripe', $proxy_id);
                return array_merge($check, ['active' => true, 'proxy' => $proxy]);
            }

            if (($check['status'] ?? '') === 'hmac_error') {
                $boot = self::refresh_gateway_hmac($proxy, 'stripe');
                $retry = self::check_stripe_proxy($proxy, $reason . '_hmac_retry');
                if (($retry['status'] ?? '') === 'active') {
                    self::clear_failure('stripe', $proxy_id);
                    return array_merge($retry, ['active' => true, 'proxy' => $proxy, 'hmac_refreshed' => !empty($boot['bootstrapped'])]);
                }
            }

            self::record_failure('stripe', $proxy, $check);
            self::rotate_stripe_proxy($check['status'] ?? $reason, ($check['status'] ?? '') === 'deactive');
        }

        return ['active' => false, 'status' => 'max_attempts', 'reason' => $reason];
    }

    private static function stripe_session_proxy() {
        if (!function_exists('WC') || !WC() || !WC()->session) {
            return null;
        }
        $id = WC()->session->get('wootify-stripe-proxy-active-id');
        $url = WC()->session->get('wootify-stripe-proxy-active-url');
        if (!$url) {
            return null;
        }
        $proxy = null;
        if (function_exists('findActivatedProxyDataByIdStripe')) {
            $proxy = findActivatedProxyDataByIdStripe(get_option(OPT_WOOTIFY_STRIPE_PROXIES, []), $id);
        }
        if (!$proxy) {
            $proxy = ['id' => $id, 'url' => $url];
        }
        return $proxy;
    }

    private static function check_stripe_proxy($proxy, $reason) {
        $url = rtrim((string) ($proxy['url'] ?? ''), '/') . '?' . http_build_query([
            'wootify-stripe-pe-get-account-charge-status' => uniqid(),
        ]);
        $trace_id = function_exists('csStripeGenerateTraceId') ? csStripeGenerateTraceId() : wp_generate_uuid4();
        $args = shield_proxy_signed_request_args($proxy, 'GET', $url, [
            '_shield_gateway' => 'stripe',
            'sslverify' => false,
            'timeout' => 30,
            'headers' => [
                'X-Shield-Trace-Id' => $trace_id,
            ],
        ]);

        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            self::stripe_log([$proxy, $response->get_error_message(), 'trace_id' => $trace_id, 'reason' => $reason], 'Stripe proxy connection check failed');
            return ['status' => 'connection_error', 'trace_id' => $trace_id, 'message' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $body = json_decode($raw);
        $body_status = is_object($body) ? (string) ($body->status ?? '') : '';
        $body_code = is_object($body) ? (string) ($body->code ?? '') : '';

        if ($code === 401 || $body_code === 'unauthorized' || $body_status === 'unauthorized') {
            self::stripe_log([$proxy, $raw, 'trace_id' => $trace_id, 'http_code' => $code, 'reason' => $reason], 'Stripe proxy HMAC check failed');
            return ['status' => 'hmac_error', 'trace_id' => $trace_id, 'http_code' => $code, 'body' => $raw];
        }

        if ($body_status === 'active') {
            return ['status' => 'active', 'trace_id' => $trace_id, 'http_code' => $code, 'body' => $raw];
        }

        if ($body_status === 'deactive') {
            return ['status' => 'deactive', 'trace_id' => $trace_id, 'http_code' => $code, 'body' => $raw];
        }

        self::stripe_log([$proxy, $raw, 'trace_id' => $trace_id, 'http_code' => $code, 'reason' => $reason], 'Stripe proxy health status unknown');
        return ['status' => 'unknown_error', 'trace_id' => $trace_id, 'http_code' => $code, 'body' => $raw];
    }

    private static function refresh_gateway_hmac($proxy, $gateway) {
        if (!function_exists('shield_auto_connect_site_from_rotation')) {
            return ['bootstrapped' => false, 'warning' => 'Auto connect helper is not available.'];
        }
        $result = shield_auto_connect_site_from_rotation((string) ($proxy['url'] ?? ''), $gateway, true);
        self::stripe_log([$proxy, $result], 'Stripe proxy HMAC refresh attempted');
        return $result;
    }

    private static function rotate_stripe_proxy($reason, $move_unused = false) {
        $current_id = function_exists('WC') && WC() && WC()->session ? WC()->session->get('wootify-stripe-proxy-active-id') : '';
        if ($move_unused && $current_id && function_exists('stripeMoveToUnusedProxyIds')) {
            stripeMoveToUnusedProxyIds([$current_id]);
        } elseif (self::failure_count('stripe', (string) $current_id) >= self::MAX_FAILURES_BEFORE_UNUSED && $current_id && function_exists('stripeMoveToUnusedProxyIds')) {
            stripeMoveToUnusedProxyIds([$current_id]);
        } elseif (function_exists('isEnabledAmountRotationStripe') && isEnabledAmountRotationStripe()) {
            performProxyAmountRotationStripe(function_exists('WC') && WC() && WC()->cart ? WC()->cart->get_total(false) : 0);
        } elseif (function_exists('isEnabledOrderRotation') && isEnabledOrderRotation('Stripe')) {
            performProxyOrderRotation(false, 'Stripe');
        } elseif (function_exists('setNextProxyByTimeRotation')) {
            setNextProxyByTimeRotation();
        }

        if (function_exists('findAndSetNextProxy')) {
            findAndSetNextProxy();
        }
        self::stripe_log(['proxy_id' => $current_id, 'reason' => $reason], 'Stripe proxy rotated by failover');
    }

    private static function state() {
        $state = get_option(self::STATE_OPTION, []);
        return is_array($state) ? $state : [];
    }

    private static function update_state($state) {
        update_option(self::STATE_OPTION, is_array($state) ? $state : [], false);
    }

    private static function record_failure($gateway, $proxy, $check) {
        $id = (string) ($proxy['id'] ?? md5((string) ($proxy['url'] ?? '')));
        $state = self::state();
        if (!isset($state[$gateway]) || !is_array($state[$gateway])) {
            $state[$gateway] = [];
        }
        $row = isset($state[$gateway][$id]) && is_array($state[$gateway][$id]) ? $state[$gateway][$id] : ['count' => 0];
        $row['count'] = (int) ($row['count'] ?? 0) + 1;
        $row['last_status'] = $check['status'] ?? 'unknown_error';
        $row['last_error_at'] = time();
        $row['last_trace_id'] = $check['trace_id'] ?? '';
        $row['url'] = (string) ($proxy['url'] ?? '');
        $state[$gateway][$id] = $row;
        self::update_state($state);
    }

    private static function clear_failure($gateway, $proxy_id) {
        $state = self::state();
        if (isset($state[$gateway][$proxy_id])) {
            unset($state[$gateway][$proxy_id]);
            self::update_state($state);
        }
    }

    private static function failure_count($gateway, $proxy_id) {
        $state = self::state();
        return (int) ($state[$gateway][$proxy_id]['count'] ?? 0);
    }

    private static function stripe_log($data, $message) {
        if (function_exists('csStripeErrorLog')) {
            csStripeErrorLog($data, $message);
        } else {
            error_log($message . ' ' . wp_json_encode($data));
        }
    }
}
