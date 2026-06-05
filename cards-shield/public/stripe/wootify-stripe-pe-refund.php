<?php
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/helpers/helpers.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/class/class-Stripe-Proxy-Service.php';
Helpers::checkRequest("POST", false);
header('Content-Type: application/json');
if (!Helpers::verifyProxyHmacV2Request()) {
  http_response_code(401);
  status_header(401);
  echo json_encode([
    'success' => false,
    'status' => 'unauthorized',
    'code' => 'unauthorized',
    'message' => 'Unauthorized',
    'correlation_id' => isset($_SERVER['HTTP_X_SHIELD_TRACE_ID']) ? sanitize_text_field((string) $_SERVER['HTTP_X_SHIELD_TRACE_ID']) : '',
  ]);
  exit;
}
try {
  $payload = json_decode((string) file_get_contents('php://input'), true);
  if (!is_array($payload)) {
    $payload = [];
  }
  $service = new Shield_Stripe_Proxy_Service();
  $result = $service->refundPayment($payload);
  http_response_code(isset($result['http_status']) ? (int) $result['http_status'] : 200);
  echo wp_json_encode($result);
} catch (RuntimeException $e) {
  http_response_code(503);
  echo wp_json_encode([
    'success' => false,
    'status' => 'error',
    'code' => 'stripe_not_configured',
    'message' => $e->getMessage(),
  ]);
}
