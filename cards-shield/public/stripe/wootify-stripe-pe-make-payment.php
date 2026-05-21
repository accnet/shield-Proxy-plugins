<?php
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/stripe-php/init.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/helpers/helpers.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/config/config-Stripe.php';
Helpers::checkRequest("POST");
if (!Helpers::verifyProxyHmacV2Request()) {
  status_header(401);
  echo json_encode(['status' => 'unauthorized']);
  exit;
}
if (!STRIPE_SECRET_KEY) {
	header('HTTP/1.1 503 Service Unavailable');
	exit;
}

$rawInput = file_get_contents('php://input');
$orderData = json_decode((string) $rawInput, true);
if (!is_array($orderData)) {
  $orderData = [];
}

if (empty($orderData['amount']) || empty($orderData['currency']) || empty($orderData['payment_method_id'])) {
	status_header(400);
  echo json_encode([
    'status' => 'ERROR',
    'message' => 'Invalid payload',
    'payment_intent' => [],
    'charge' => []
  ]);
  exit;
}

$idempotencyFallback = implode('|', [
	(string) ($orderData['order_id'] ?? ''),
	(string) ($orderData['payment_intent'] ?? ''),
	(string) ($orderData['payment_method_id'] ?? ''),
	(string) ($orderData['amount'] ?? ''),
	(string) ($orderData['currency'] ?? ''),
]);
$idempotencyKey = Helpers::getIdempotencyKey($idempotencyFallback);
if (!Helpers::acquireIdempotencyLock('stripe_make_payment', $idempotencyKey, 900)) {
	header('HTTP/1.1 409 Conflict');
	status_header(409);
	echo json_encode([
		'status' => 'duplicate_request',
		'message' => 'Duplicate make-payment request',
		'payment_intent' => [],
		'charge' => []
	]);
	exit;
}

$stripe = new \Stripe\StripeClient(STRIPE_SECRET_KEY);

try {
	$amount = (float) $orderData['amount'];
	$shipping = (isset($orderData['shipping']) && is_array($orderData['shipping'])) ? $orderData['shipping'] : [];
	
	$paymentIntent = null;
	$paymentIntentId = $orderData['payment_intent'] ?? null;
	
	if (!empty($paymentIntentId)) {
		try {
			$paymentIntent = $stripe->paymentIntents->retrieve($paymentIntentId);
			if (in_array($paymentIntent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'])) {
				$paymentIntent = $stripe->paymentIntents->update($paymentIntentId, [
					'payment_method' => $orderData['payment_method_id'],
					'amount' => (int) round($amount * 100),
					'currency' => $orderData['currency'],
					'payment_method_options' => [
						'card' => [
							'request_three_d_secure' => 'automatic',
						],
					],
				]);
			}
		} catch (Exception $e) {
			$paymentIntent = null;
		}
	}

	if (empty($paymentIntent)) {
		$paymentIntent = $stripe->paymentIntents->create([
			'amount' => (int) round($amount * 100),
			'currency' => $orderData['currency'],
			'payment_method' => $orderData['payment_method_id'],
			'description' => $orderData['statement_descriptor'] ?? '',
			'shipping' => $shipping,
			'payment_method_options' => [
				'card' => [
					'request_three_d_secure' => 'automatic',
				],
			],
			'metadata' => [
				'customer_email' => $orderData['customer_email'] ?? '',
				'customer_name' => $orderData['name'] ?? '',
				'order_id' => $orderData['order_invoice'] ?? '',
				'manager_id' => Helpers::currentManagerId(),
			],
		], [
			'idempotency_key' => substr(hash('sha256', $idempotencyKey), 0, 255),
		]);
	}

	if ($paymentIntent->status == "succeeded") {
		$total = $orderData['amount'] ?? 0.0;
		Helpers::sendTransactionToShield($total, 'Stripe');
	}
	$charge = $stripe->paymentIntents->retrieve(
		$paymentIntent->id,
		['expand' => ['latest_charge.refunds', 'latest_charge.balance_transaction']]
	);
	echo json_encode([
		'status' => 'success',
		'payment_intent' => $paymentIntent,
		'charge' => $charge->latest_charge
	]);
} catch (Exception $e) {
	echo json_encode([
		'status' => 'ERROR',
		'payment_intent' => [],
		'charge' => []
	]);
}
