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

    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    // Replay cache is scoped per route/method to prevent cross-endpoint nonce reuse.
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
    $expected = hash_hmac('sha256', $canonical, (string) $cred['hmac_secret']);

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
}
