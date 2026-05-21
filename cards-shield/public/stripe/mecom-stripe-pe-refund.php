<?php
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/stripe-php/init.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/helpers/helpers.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/config/config-Stripe.php';
Helpers::checkRequest("GET", false);
header('Content-Type: application/json');
if (!STRIPE_SECRET_KEY) {
  header('HTTP/1.1 503 Service Unavailable');
  exit;
}
$stripe = new \Stripe\StripeClient(STRIPE_SECRET_KEY);

if (!isset($_GET['transaction_id'], $_GET['amount'])) {
  http_response_code(400);
  echo json_encode(['status' => 'error', 'message' => 'Missing transaction_id or amount']);
  exit;
}
try {
  $transaction_id = sanitize_text_field(wp_unslash($_GET['transaction_id']));
  $amount = (int) $_GET['amount'];
  if ($amount <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid refund amount']);
    exit;
  }
  $charge = $stripe->paymentIntents->retrieve($transaction_id);
  $refunds = $stripe->refunds->create([
    'charge' => $charge->latest_charge,
    'reason' => "requested_by_customer",
    'amount' => $amount,
  ]);
  $charge = $stripe->paymentIntents->retrieve(
    $transaction_id,
    ['expand' => ['latest_charge.refunds', 'latest_charge.balance_transaction']]
  );
  echo json_encode([
    'refund_obj' => $refunds,
    'charge_obj' => $charge,
  ]);
} catch (\Stripe\Exception\ApiErrorException $e) {
  http_response_code(400);
  echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} catch (\Throwable $e) {
  http_response_code(500);
  echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
