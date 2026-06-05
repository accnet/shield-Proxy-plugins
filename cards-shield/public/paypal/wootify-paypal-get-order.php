<?php
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/helpers/helpers.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/class/class-PayPal.php';

Helpers::checkRequest();
if (!Helpers::verifyProxyHmacV2Request()) {
  status_header(401);
  echo json_encode(['status' => 'unauthorized']);
  exit;
}
$paypal = new PayPal();

$inputJSON = file_get_contents('php://input');
$data = json_decode($inputJSON);

try {
  if (!empty($data)) {
    $order_id = $data->order_id;
    $orderResult = $paypal->get($order_id);
    if (isset($orderResult['order']['id'])) {
      $status = strtoupper((string) ($orderResult['order']['status'] ?? ''));
      if (method_exists('Helpers', 'queuePaymentTransitionLog')) {
        Helpers::queuePaymentTransitionLog([
          'gateway' => 'paypal',
          'mode' => Helpers::paymentTransitionMode('paypal'),
          'source' => $status === 'APPROVED' ? 'approve_order' : 'create_order',
          'transactionId' => (string) $orderResult['order']['id'],
          'nextState' => in_array(strtolower($status), ['created', 'approved', 'completed'], true) ? strtolower($status) : 'failed',
          'transitionApplied' => true,
          'eventId' => (string) $orderResult['order']['id'] . ':' . $status,
          'traceId' => isset($_SERVER['HTTP_X_SHIELD_TRACE_ID']) ? sanitize_text_field((string) $_SERVER['HTTP_X_SHIELD_TRACE_ID']) : '',
          'metadata' => [
            'gateway_response_code' => $status,
          ],
        ]);
      }
      echo json_encode($orderResult);
    } else {
      if (method_exists('Helpers', 'queuePaymentTransitionLog')) {
        Helpers::queuePaymentTransitionLog([
          'gateway' => 'paypal',
          'mode' => Helpers::paymentTransitionMode('paypal'),
          'source' => 'create_order',
          'transactionId' => (string) $order_id,
          'nextState' => 'failed',
          'transitionApplied' => true,
          'eventId' => (string) $order_id . ':FAILED',
          'traceId' => isset($_SERVER['HTTP_X_SHIELD_TRACE_ID']) ? sanitize_text_field((string) $_SERVER['HTTP_X_SHIELD_TRACE_ID']) : '',
          'metadata' => [
            'gateway_response_code' => 'FAILED',
          ],
        ]);
      }
      echo json_encode(['success' => 'failed', 'data' => []]);
    }
  }
} catch (Exception $e) {
  if (!empty($data) && !empty($data->order_id) && method_exists('Helpers', 'queuePaymentTransitionLog')) {
    Helpers::queuePaymentTransitionLog([
      'gateway' => 'paypal',
      'mode' => Helpers::paymentTransitionMode('paypal'),
      'source' => 'create_order',
      'transactionId' => (string) $data->order_id,
      'nextState' => 'failed',
      'transitionApplied' => true,
      'eventId' => (string) $data->order_id . ':EXCEPTION',
      'traceId' => isset($_SERVER['HTTP_X_SHIELD_TRACE_ID']) ? sanitize_text_field((string) $_SERVER['HTTP_X_SHIELD_TRACE_ID']) : '',
      'errorMessage' => $e->getMessage(),
      'metadata' => [
        'gateway_response_code' => 'EXCEPTION',
      ],
    ]);
  }
  echo json_encode(['success' => 'failed', 'data' => []]);
}
