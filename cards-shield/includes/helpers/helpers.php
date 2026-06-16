<?php

class Helpers {
  private static function requestHeader($name) {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return isset($_SERVER[$key]) ? (string) $_SERVER[$key] : '';
  }

  private static function findV2Credential($manager_id, $key_id) {
    if (function_exists('shield_hmac_keys_v2_find')) {
      return shield_hmac_keys_v2_find($manager_id, $key_id);
    }

    $keys = get_option('shield_hmac_keys_v2', []);
    if (!is_array($keys)) {
      return null;
    }
    foreach ($keys as $item) {
      if (($item['manager_id'] ?? '') === $manager_id && ($item['key_id'] ?? '') === $key_id) {
        return $item;
      }
    }
    return null;
  }

  public static function verifyProxyHmacV2Request() {
    $manager_id = sanitize_text_field(self::requestHeader('X-Shield-Manager-Id'));
    $key_id = sanitize_text_field(self::requestHeader('X-Shield-Key-Id'));
    $signature = self::requestHeader('X-Shield-Signature');
    $nonce = sanitize_text_field(self::requestHeader('X-Shield-Nonce'));
    $timestamp = (int) self::requestHeader('X-Shield-Timestamp');

    if (!$manager_id || !$key_id || !$signature || !$nonce || !$timestamp) {
      return false;
    }
    if (abs(time() - $timestamp) > 300) {
      return false;
    }

    // Build the request URI exactly as the signing site computed it:
    // 1. Start from the raw server URI.
    // 2. Strip WordPress home-path prefix for subdirectory installs so the
    //    canonical matches even when WP is not installed at root.
    //    e.g. site1 home_url = https://example.com/shop  →  strip "/shop"
    // 3. Normalize non-root path trailing slashes so a proxy that adds/removes
    //    them does not break the canonical comparison.
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
    $home_path = rtrim((string) parse_url(home_url(), PHP_URL_PATH), '/');
    if ($home_path !== '' && strpos($request_uri, $home_path . '/') === 0) {
      $request_uri = substr($request_uri, strlen($home_path));
    } elseif ($home_path !== '' && $request_uri === $home_path) {
      $request_uri = '/';
    }
    // Normalize trailing slash on non-root paths only
    if ($request_uri !== '/' && substr($request_uri, -1) === '?') {
      // preserve
    } elseif ($request_uri !== '/') {
      $q_pos = strpos($request_uri, '?');
      if ($q_pos !== false) {
        $path_part  = rtrim(substr($request_uri, 0, $q_pos), '/');
        $query_part = substr($request_uri, $q_pos);
        $request_uri = ($path_part ?: '/') . $query_part;
      } else {
        $request_uri = rtrim($request_uri, '/') ?: '/';
      }
    }

    // Replay cache is scoped per route/method to prevent cross-endpoint nonce reuse.
    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
    $nonce_scope = $method . '|' . $request_uri;
    $nonce_key = 'shield_hmac_n_' . md5($manager_id . '|' . $nonce . '|' . (string)$timestamp . '|' . $nonce_scope);
    if (get_transient($nonce_key)) {
      return false;
    }
    set_transient($nonce_key, '1', 300);

    $cred = self::findV2Credential($manager_id, $key_id);
    if (!$cred || empty($cred['hmac_secret']) || (($cred['status'] ?? 'active') !== 'active')) {
      return false;
    }

    // New gateway-specific credentials are stored with their gateway and are
    // used directly. Legacy site-level credentials derive a gateway sub-key.
    $gateway       = sanitize_text_field(self::requestHeader('X-Shield-Gateway'));
    $verify_secret = (string) $cred['hmac_secret'];
    $cred_gateway  = sanitize_text_field($cred['gateway'] ?? '');
    if ($gateway === 'paypal' || $gateway === 'stripe') {
      if ($cred_gateway && $cred_gateway !== $gateway) {
        return false;
      }
    }
    if (($gateway === 'paypal' || $gateway === 'stripe') && !$cred_gateway) {
      $verify_secret = hash_hmac('sha256', 'gateway-proxy:' . $gateway, $verify_secret);
    }

    $raw_body = file_get_contents('php://input');
    if (!is_string($raw_body)) {
      $raw_body = '';
    }

    $canonical = implode("\n", [
      $method,
      $request_uri,
      hash('sha256', $raw_body),
      (string) $timestamp,
      $nonce,
      $manager_id,
      $key_id,
    ]);
    $expected = hash_hmac('sha256', $canonical, $verify_secret);

    return hash_equals($expected, (string) $signature);
  }

  public static function currentManagerId() {
    return sanitize_text_field(self::requestHeader('X-Shield-Manager-Id'));
  }

  public static function getIdempotencyKey($fallback = '') {
    $key = sanitize_text_field(self::requestHeader('X-Shield-Idempotency-Key'));
    if ($key) {
      return $key;
    }
    $fallback = (string) $fallback;
    if ($fallback === '') {
      return '';
    }
    return 'fp_' . hash('sha256', $fallback);
  }

  public static function acquireIdempotencyLock($scope, $idempotency_key, $ttl = 900) {
    $scope = sanitize_key((string) $scope);
    $idempotency_key = sanitize_text_field((string) $idempotency_key);
    if (!$scope || !$idempotency_key) {
      return true;
    }

    $lock_key = 'shield_idem_' . md5($scope . '|' . $idempotency_key);
    if (get_transient($lock_key)) {
      return false;
    }

    set_transient($lock_key, '1', max(60, (int) $ttl));
    return true;
  }

  public static function checkRequest($method = 'POST', $json = true) {
    header_remove("X-Frame-Options");
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
      header('HTTP/1.1 503 Service Unavailable');
      exit;
    }
    if ($json) {
      header('Content-Type: application/json');
    }
    status_header(200);
  }


  public static function getSiteUrl() {
    $parts = parse_url(get_site_url());
    $scheme = $parts['scheme'];
    $host = $parts['host'];
    $siteUrl = $scheme . '://' . $host;
    return $siteUrl;
  }
  public static function sendTransactionToShield($total, $payment_method) {
    $shield_license_key = get_option('shield_license_key', '');
    $manager_id = self::currentManagerId();
    $data = array(
      'domain' => self::getSiteUrl(),
      'amount' => $total,
      'type' => 'create',
      'payment_method' => $payment_method,
      'manager_id' => $manager_id,
      'license_key' => $shield_license_key,
    );
    wp_remote_post(SHIELD_MANAGE_URL . '/api/transaction', array(
      'body' => $data,
      'timeout' => 10
    ));
  }

  public static function getStripeWebhookPayments() {
    $data = get_option('shield_stripe_webhook_payments', []);
    return is_array($data) ? $data : [];
  }

  public static function saveStripeWebhookPayments($data) {
    update_option('shield_stripe_webhook_payments', is_array($data) ? $data : []);
  }

  public static function trackStripeWebhookPayment($paymentIntentId, array $payload) {
    $paymentIntentId = sanitize_text_field((string) $paymentIntentId);
    if ($paymentIntentId === '') {
      return;
    }

    if (array_key_exists('order_id', $payload)) {
      $payload['order_id'] = self::normalizePaymentReference($payload['order_id']);
    }
    if (array_key_exists('order_invoice', $payload)) {
      $payload['order_invoice'] = self::normalizePaymentReference($payload['order_invoice']);
    }
    if (array_key_exists('shield_id', $payload)) {
      $payload['shield_id'] = self::normalizePaymentReference($payload['shield_id']);
    }
    if (array_key_exists('trace_id', $payload)) {
      $payload['trace_id'] = self::normalizePaymentReference($payload['trace_id']);
    }

    $payments = self::getStripeWebhookPayments();
    $existing = isset($payments[$paymentIntentId]) && is_array($payments[$paymentIntentId]) ? $payments[$paymentIntentId] : [];
    $payments[$paymentIntentId] = array_merge($existing, $payload, [
      'payment_intent_id' => $paymentIntentId,
      'updated_at' => current_time('mysql'),
    ]);
    self::saveStripeWebhookPayments($payments);
  }

  public static function paymentTransitionMode($gateway) {
    $gateway = strtolower((string) $gateway);
    $option = $gateway === 'paypal' ? get_option('shield_paypal', []) : get_option('shield_stripe', []);
    return is_array($option) && !empty($option['test_mode']) ? 'test' : 'live';
  }

  public static function queuePaymentTransitionLog(array $log) {
    $transactionId = sanitize_text_field((string) ($log['transactionId'] ?? ''));
    if ($transactionId === '') {
      return false;
    }

    $gateway = strtolower(sanitize_text_field((string) ($log['gateway'] ?? '')));
    if (!in_array($gateway, ['stripe', 'paypal'], true)) {
      return false;
    }

    $row = array_merge([
      'gateway' => $gateway,
      'mode' => self::paymentTransitionMode($gateway),
      'site1Url' => self::getSiteUrl(),
      'managerId' => self::currentManagerId(),
      'site2Url' => null,
      'orderId' => null,
      'orderNumber' => null,
      'eventId' => null,
      'traceId' => self::requestHeader('X-Shield-Trace-Id'),
      'previousState' => null,
      'transitionApplied' => true,
      'ignoredReason' => null,
      'amount' => null,
      'currency' => null,
      'httpStatus' => null,
      'errorCode' => null,
      'errorMessage' => null,
      'metadata' => null,
    ], $log);

    $row['gateway'] = $gateway;
    $row['mode'] = in_array(($row['mode'] ?? ''), ['live', 'test'], true) ? $row['mode'] : self::paymentTransitionMode($gateway);
    $row['transactionId'] = $transactionId;
    $row['source'] = sanitize_text_field((string) ($row['source'] ?? ''));
    $row['nextState'] = sanitize_text_field((string) ($row['nextState'] ?? ''));
    $row['orderId'] = self::normalizePaymentReference($row['orderId'] ?? null);
    $row['orderNumber'] = self::normalizePaymentReference($row['orderNumber'] ?? null);
    $row['traceId'] = self::normalizePaymentReference($row['traceId'] ?? null);
    $row['managerId'] = self::normalizePaymentReference($row['managerId'] ?? null);
    $row['site2Url'] = self::normalizePaymentReference($row['site2Url'] ?? null);

    if ($row['source'] === '' || $row['nextState'] === '') {
      return false;
    }

    $queue = get_option('shield_payment_transition_logs_queue', []);
    $queue = is_array($queue) ? $queue : [];
    $queue[] = [
      'payload' => $row,
      'retry_count' => 0,
      'last_error' => '',
      'next_attempt_at' => 0,
      'created_at' => current_time('mysql'),
    ];

    if (count($queue) > 1000) {
      update_option('shield_payment_transition_logs_warning', [
        'message' => 'Payment transition log queue backlog is above 1000 rows.',
        'pending' => count($queue),
        'at' => current_time('mysql'),
      ], false);
    }

    if (count($queue) > 5000) {
      $dropped = count($queue) - 5000;
      $queue = array_slice($queue, -5000);
      update_option('shield_payment_transition_logs_warning', [
        'message' => 'Payment transition log queue exceeded 5000 rows; oldest rows were dropped.',
        'dropped' => $dropped,
        'pending' => count($queue),
        'at' => current_time('mysql'),
      ], false);
    }

    update_option('shield_payment_transition_logs_queue', $queue, false);

    // Immediate flush: attempt to send to SaaS right away so logs appear without waiting for cron.
    // If the flush fails, the item remains in queue and cron will retry.
    self::flushPaymentTransitionLogs(10);

    return true;
  }

  public static function flushPaymentTransitionLogs($limit = 50) {
    $queue = get_option('shield_payment_transition_logs_queue', []);
    $queue = is_array($queue) ? array_values(array_map([__CLASS__, 'normalizePaymentTransitionQueueItem'], $queue)) : [];
    if (empty($queue)) {
      return ['attempted' => false, 'sent' => 0, 'remaining' => 0];
    }

    $proxyKey = get_option('shield_proxy_key', '');
    $saasUrl = get_option('shield_saas_url', SHIELD_MANAGE_URL);
    if (!$proxyKey || !$saasUrl) {
      return ['attempted' => false, 'sent' => 0, 'remaining' => count($queue), 'message' => 'missing SaaS connection'];
    }

    $now = time();
    $batchIndexes = [];
    $batch = [];
    $maxBatch = max(1, min(100, (int) $limit));
    foreach ($queue as $idx => $item) {
      if ((int) ($item['next_attempt_at'] ?? 0) > $now) {
        continue;
      }
      $batchIndexes[] = $idx;
      $batch[] = $item['payload'];
      if (count($batch) >= $maxBatch) {
        break;
      }
    }

    if (empty($batch)) {
      update_option('shield_payment_transition_logs_queue', $queue, false);
      return ['attempted' => false, 'sent' => 0, 'remaining' => count($queue), 'message' => 'waiting for retry backoff'];
    }

    $body = wp_json_encode(['logs' => $batch]);
    $timestamp = (string) time();
    try {
      $nonce = bin2hex(random_bytes(16));
    } catch (Exception $e) {
      $nonce = str_replace('.', '', uniqid('ptl', true));
    }
    $signature = hash_hmac('sha256', $proxyKey . '.' . $timestamp . '.' . $nonce, $proxyKey);

    $response = wp_remote_post(trailingslashit($saasUrl) . 'api/payment-transition-logs/batch', [
      'headers' => [
        'Content-Type' => 'application/json',
        'X-Shield-Key' => $proxyKey,
        'X-Shield-Timestamp' => $timestamp,
        'X-Shield-Nonce' => $nonce,
        'X-Shield-Signature' => $signature,
      ],
      'body' => $body,
      'timeout' => 10,
      'sslverify' => false,
    ]);

    if (is_wp_error($response)) {
      $queue = self::markPaymentTransitionBatchFailed($queue, $batchIndexes, $response->get_error_message());
      update_option('shield_payment_transition_logs_last_flush', [
        'success' => false,
        'message' => $response->get_error_message(),
        'at' => current_time('mysql'),
      ], false);
      update_option('shield_payment_transition_logs_queue', $queue, false);
      return ['attempted' => true, 'sent' => 0, 'remaining' => count($queue), 'message' => $response->get_error_message()];
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
      $message = 'HTTP ' . $code;
      $queue = self::markPaymentTransitionBatchFailed($queue, $batchIndexes, $message);
      update_option('shield_payment_transition_logs_last_flush', [
        'success' => false,
        'message' => $message,
        'at' => current_time('mysql'),
      ], false);
      update_option('shield_payment_transition_logs_queue', $queue, false);
      return ['attempted' => true, 'sent' => 0, 'remaining' => count($queue), 'message' => $message];
    }

    foreach (array_reverse($batchIndexes) as $idx) {
      array_splice($queue, $idx, 1);
    }
    $remaining = array_values($queue);
    update_option('shield_payment_transition_logs_queue', $remaining, false);
    update_option('shield_payment_transition_logs_last_flush', [
      'success' => true,
      'sent' => count($batch),
      'remaining' => count($remaining),
      'at' => current_time('mysql'),
    ], false);

    return ['attempted' => true, 'sent' => count($batch), 'remaining' => count($remaining)];
  }

  public static function normalizePaymentTransitionQueueItem($item) {
    if (is_array($item) && isset($item['payload']) && is_array($item['payload'])) {
      $item['retry_count'] = isset($item['retry_count']) ? (int) $item['retry_count'] : 0;
      $item['last_error'] = isset($item['last_error']) ? (string) $item['last_error'] : '';
      $item['next_attempt_at'] = isset($item['next_attempt_at']) ? (int) $item['next_attempt_at'] : 0;
      $item['created_at'] = isset($item['created_at']) ? (string) $item['created_at'] : current_time('mysql');
      return $item;
    }

    return [
      'payload' => is_array($item) ? $item : [],
      'retry_count' => 0,
      'last_error' => '',
      'next_attempt_at' => 0,
      'created_at' => current_time('mysql'),
    ];
  }

  private static function markPaymentTransitionBatchFailed(array $queue, array $indexes, $message) {
    foreach ($indexes as $idx) {
      if (!isset($queue[$idx])) {
        continue;
      }
      $retry = ((int) ($queue[$idx]['retry_count'] ?? 0)) + 1;
      $delay = min(3600, (int) pow(2, min($retry, 10)) * 60);
      $queue[$idx]['retry_count'] = $retry;
      $queue[$idx]['last_error'] = (string) $message;
      $queue[$idx]['next_attempt_at'] = time() + $delay;
    }
    return $queue;
  }

  public static function normalizeFundingSource($value) {
    if ($value === null) {
      return null;
    }

    $normalized = strtolower(sanitize_text_field((string) $value));
    if ($normalized === '') {
      return null;
    }

    $aliases = [
      'pay_pal' => 'paypal',
      'paypal_wallet' => 'paypal',
      'wallet' => 'paypal',
      'credit_card' => 'card',
      'debit_card' => 'card',
    ];
    if (isset($aliases[$normalized])) {
      $normalized = $aliases[$normalized];
    }

    return in_array($normalized, ['card', 'paypal', 'venmo', 'apple_pay', 'google_pay', 'paylater'], true)
      ? $normalized
      : null;
  }

  public static function extractPayPalFundingSource($orderOrPaymentSource) {
    if (is_object($orderOrPaymentSource)) {
      $orderOrPaymentSource = (array) $orderOrPaymentSource;
    }
    if (!is_array($orderOrPaymentSource) || !$orderOrPaymentSource) {
      return null;
    }

    $paymentSource = null;
    $customId = null;

    if (isset($orderOrPaymentSource['payment_source'])) {
      $paymentSource = $orderOrPaymentSource['payment_source'];
    }

    if (isset($orderOrPaymentSource['purchase_units'])) {
      $purchaseUnits = $orderOrPaymentSource['purchase_units'];
      if (is_array($purchaseUnits) && !empty($purchaseUnits[0])) {
        $firstPU = $purchaseUnits[0];
        if (is_object($firstPU)) {
          $firstPU = (array) $firstPU;
        }
        if (is_array($firstPU) && isset($firstPU['custom_id'])) {
          $customId = $firstPU['custom_id'];
        }
      }
    }

    // Backward compatibility: if it doesn't look like an order, treat it as the payment_source itself
    if ($paymentSource === null && $customId === null) {
      $paymentSource = $orderOrPaymentSource;
    }

    if ($customId !== null) {
      $normalized = self::normalizeFundingSource($customId);
      if ($normalized !== null) {
        return $normalized;
      }
    }

    if ($paymentSource !== null) {
      if (is_object($paymentSource)) {
        $paymentSource = (array) $paymentSource;
      }
      if (is_array($paymentSource)) {
        foreach (['card', 'paypal', 'venmo', 'apple_pay', 'google_pay', 'paylater'] as $candidate) {
          if (!empty($paymentSource[$candidate])) {
            return self::normalizeFundingSource($candidate);
          }
        }

        foreach ($paymentSource as $candidate => $value) {
          if (!empty($value)) {
            $normalized = self::normalizeFundingSource($candidate);
            if ($normalized !== null) {
              return $normalized;
            }
          }
        }
      }
    }

    return null;
  }

  private static function normalizePaymentReference($value) {
    if ($value === null) {
      return null;
    }

    $normalized = sanitize_text_field((string) $value);
    if ($normalized === '' || $normalized === '0') {
      return null;
    }

    return $normalized;
  }
}
