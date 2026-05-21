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
  echo json_encode(array(
    "status" => ($response ? 'success' : 'error'),
    "data" => $response
  ));
} catch (Exception $e) {
  echo json_encode(['status' => 'error', 'data' => []]);
}
