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
      echo json_encode($orderResult);
    } else {
      echo json_encode(['success' => 'failed', 'data' => []]);
    }
  }
} catch (Exception $e) {
  echo json_encode(['success' => 'failed', 'data' => []]);
}
