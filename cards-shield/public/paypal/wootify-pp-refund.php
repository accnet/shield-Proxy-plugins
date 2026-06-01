<?php
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/helpers/helpers.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/class/class-PayPal.php';

Helpers::checkRequest("GET");
if (!Helpers::verifyProxyHmacV2Request()) {
  status_header(401);
  echo json_encode(['status' => 'unauthorized', 'data' => []]);
  exit;
}
$paypal = new PayPal();

if (!isset($_GET['TRANSACTIONID']) || !isset($_GET['AMT']) || !isset($_GET['CURRENCYCODE'])) {
  header('HTTP/1.1 503 Service Unavailable');
  exit;
}

try {
  $transactionID = $_GET['TRANSACTIONID'];
  $refundData = [
    "amount" => [
      "value" => $_GET['AMT'],
      "currency_code" => $_GET['CURRENCYCODE']
    ],
    "note_to_payer" => !empty($_GET['NOTE']) ? $_GET['NOTE'] : "customer request"
  ];
  $orderRefundResult = $paypal->refund($transactionID, json_encode($refundData));
  $response = [];
  if ($orderRefundResult['order']['status'] == 'COMPLETED') {
    $response = $orderRefundResult['order'];
  }
  $status = $response ? 'refunded' : 'failed';
  if (method_exists('Helpers', 'queuePaymentTransitionLog')) {
    Helpers::queuePaymentTransitionLog([
      'gateway' => 'paypal',
      'mode' => Helpers::paymentTransitionMode('paypal'),
      'source' => 'refund',
      'transactionId' => (string) $transactionID,
      'nextState' => $status,
      'transitionApplied' => true,
      'amount' => isset($_GET['AMT']) ? (float) $_GET['AMT'] : null,
      'currency' => strtoupper((string) $_GET['CURRENCYCODE']),
      'eventId' => (string) $transactionID . ':refund:' . ($response['id'] ?? md5(json_encode($orderRefundResult))),
      'traceId' => isset($_SERVER['HTTP_X_SHIELD_TRACE_ID']) ? sanitize_text_field((string) $_SERVER['HTTP_X_SHIELD_TRACE_ID']) : '',
      'metadata' => [
        'amount' => isset($_GET['AMT']) ? (float) $_GET['AMT'] : null,
        'currency' => strtoupper((string) $_GET['CURRENCYCODE']),
        'gateway_response_code' => (string) ($orderRefundResult['order']['status'] ?? 'ERROR'),
      ],
    ]);
  }
  echo json_encode(array(
    "status" => ($response ? 'success' : 'error'),
    "data" => $response
  ));
} catch (Exception $e) {
  if (method_exists('Helpers', 'queuePaymentTransitionLog')) {
    Helpers::queuePaymentTransitionLog([
      'gateway' => 'paypal',
      'mode' => Helpers::paymentTransitionMode('paypal'),
      'source' => 'refund',
      'transactionId' => (string) ($_GET['TRANSACTIONID'] ?? 'unknown'),
      'nextState' => 'failed',
      'transitionApplied' => true,
      'amount' => isset($_GET['AMT']) ? (float) $_GET['AMT'] : null,
      'currency' => isset($_GET['CURRENCYCODE']) ? strtoupper((string) $_GET['CURRENCYCODE']) : null,
      'eventId' => (string) ($_GET['TRANSACTIONID'] ?? 'unknown') . ':refund:exception',
      'traceId' => isset($_SERVER['HTTP_X_SHIELD_TRACE_ID']) ? sanitize_text_field((string) $_SERVER['HTTP_X_SHIELD_TRACE_ID']) : '',
      'errorMessage' => $e->getMessage(),
      'metadata' => [
        'amount' => isset($_GET['AMT']) ? (float) $_GET['AMT'] : null,
        'currency' => isset($_GET['CURRENCYCODE']) ? strtoupper((string) $_GET['CURRENCYCODE']) : null,
        'gateway_response_code' => 'EXCEPTION',
      ],
    ]);
  }
  echo json_encode(['status' => 'error', 'data' => []]);
}
