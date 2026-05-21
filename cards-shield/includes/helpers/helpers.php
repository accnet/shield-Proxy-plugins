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
}
