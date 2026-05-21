<?php
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/helpers/helpers.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/class/class-PayPal.php';

Helpers::checkRequest();
if (!Helpers::verifyProxyHmacV2Request()) {
	status_header(401);
	echo json_encode(['status' => 'unauthorized']);
	exit;
}

$idempotencyFallback = implode('|', [
	(string) ($_GET['pp_order_id'] ?? ''),
	(string) ($_GET['order_id'] ?? ''),
	(string) ($_GET['total'] ?? ''),
]);
$idempotencyKey = Helpers::getIdempotencyKey($idempotencyFallback);
if (!Helpers::acquireIdempotencyLock('paypal_capture_order', $idempotencyKey, 900)) {
	header('HTTP/1.1 409 Conflict');
	status_header(409);
	echo json_encode(['status' => 'duplicate_request', 'payment_intent' => []]);
	exit;
}
$paypal = new PayPal();

if (!isset($_GET['pp_order_id'])) {
	header('HTTP/1.1 503 Service Unavailable');
	exit;
}
try {

	$orderID = $_GET['pp_order_id'];
	if (isset($_GET['purchase_units'])) {
		$patchData = [
			"op" => "replace",
			"path" => "/purchase_units/@reference_id=='default'",
			"value" => $_GET['purchase_units']
		];
		$paypal->patch($orderID, json_encode([$patchData]));
	}

	$orderCaptureResult = $paypal->capture($orderID);
	$status = $orderCaptureResult['order']['status'] ?? "ERROR";
	if ($status === 'COMPLETED') {
		$total = isset($_GET['total']) ? (float) $_GET['total'] : 0.0;
		Helpers::sendTransactionToShield($total, 'PayPal');
	}
	$orderCaptureResult['seller_receivable_breakdown'] = $orderCaptureResult['order']['purchase_units'][0]['payments']['captures'][0]['seller_receivable_breakdown'];
	echo json_encode($orderCaptureResult);
} catch (Exception $e) {
	echo json_encode(['status' => 'error', 'payment_intent' => []]);
}
