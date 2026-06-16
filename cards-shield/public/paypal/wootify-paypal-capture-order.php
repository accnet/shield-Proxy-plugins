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
		$capture = $orderCaptureResult['order']['purchase_units'][0]['payments']['captures'][0] ?? [];
		$captureId = is_array($capture) && !empty($capture['id']) ? (string) $capture['id'] : $orderID;
		$currency = is_array($capture) && !empty($capture['amount']['currency_code']) ? (string) $capture['amount']['currency_code'] : (string) ($_GET['currency'] ?? '');
		$fundingSource = Helpers::extractPayPalFundingSource($orderCaptureResult['order'] ?? null);
		if (method_exists('Helpers', 'queuePaymentTransitionLog')) {
			Helpers::queuePaymentTransitionLog([
				'gateway' => 'paypal',
				'mode' => Helpers::paymentTransitionMode('paypal'),
				'source' => 'capture',
				'transactionId' => $captureId,
				'nextState' => strtolower((string) $status) === 'completed' ? 'completed' : 'failed',
				'transitionApplied' => true,
				'amount' => isset($_GET['total']) ? (float) $_GET['total'] : null,
				'currency' => $currency ? strtoupper($currency) : null,
				'orderId' => (string) ($_GET['order_id'] ?? ''),
				'eventId' => $orderID,
				'traceId' => isset($_SERVER['HTTP_X_SHIELD_TRACE_ID']) ? sanitize_text_field((string) $_SERVER['HTTP_X_SHIELD_TRACE_ID']) : '',
				'metadata' => [
					'amount' => isset($_GET['total']) ? (float) $_GET['total'] : null,
					'currency' => $currency ? strtoupper($currency) : null,
					'gateway_response_code' => (string) $status,
					'funding_source' => $fundingSource,
				],
			]);
		}
		if ($status === 'COMPLETED') {
			$total = isset($_GET['total']) ? (float) $_GET['total'] : 0.0;
			Helpers::sendTransactionToShield($total, 'PayPal');
		}
		$orderCaptureResult['seller_receivable_breakdown'] = $capture['seller_receivable_breakdown'] ?? null;
		echo json_encode($orderCaptureResult);
	} catch (Exception $e) {
		if (method_exists('Helpers', 'queuePaymentTransitionLog')) {
			Helpers::queuePaymentTransitionLog([
				'gateway' => 'paypal',
				'mode' => Helpers::paymentTransitionMode('paypal'),
				'source' => 'capture',
				'transactionId' => (string) ($_GET['pp_order_id'] ?? 'unknown'),
				'nextState' => 'failed',
				'transitionApplied' => true,
				'amount' => isset($_GET['total']) ? (float) $_GET['total'] : null,
				'currency' => isset($_GET['currency']) ? strtoupper((string) $_GET['currency']) : null,
				'orderId' => (string) ($_GET['order_id'] ?? ''),
				'eventId' => (string) ($_GET['pp_order_id'] ?? 'unknown') . ':capture:exception',
				'traceId' => isset($_SERVER['HTTP_X_SHIELD_TRACE_ID']) ? sanitize_text_field((string) $_SERVER['HTTP_X_SHIELD_TRACE_ID']) : '',
				'errorMessage' => $e->getMessage(),
				'metadata' => [
					'amount' => isset($_GET['total']) ? (float) $_GET['total'] : null,
					'currency' => isset($_GET['currency']) ? strtoupper((string) $_GET['currency']) : null,
					'gateway_response_code' => 'EXCEPTION',
				],
			]);
		}
		echo json_encode(['status' => 'error', 'payment_intent' => []]);
	}
