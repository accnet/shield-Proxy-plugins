<?php
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/stripe-php/init.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/helpers/helpers.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/config/config-Stripe.php';
Helpers::checkRequest("GET", false);
if (!STRIPE_SECRET_KEY) {
  header('HTTP/1.1 503 Service Unavailable');
  exit;
}
$stripe = new \Stripe\StripeClient(STRIPE_SECRET_KEY);

if (!isset($_GET['transaction_id'], $_GET['amount'])) {
  header('HTTP/1.1 503 Service Unavailable');
  exit;
}
try {
  $transaction_id = $_GET['transaction_id'];
  $charge = $stripe->paymentIntents->retrieve(
    $transaction_id,
  );
  $refunds = $stripe->refunds->create([
    'charge' => $charge->latest_charge,
    'reason' => "requested_by_customer",
    'amount' => $_GET['amount'],
  ]);
  $charge = $stripe->paymentIntents->retrieve(
    $transaction_id,
    ['expand' => ['latest_charge.refunds', 'latest_charge.balance_transaction']]
  );
  echo json_encode([
    'refund_obj' => $refunds,
    'charge_obj' => $charge,
  ]);
} catch (Stripe\Exception\CardException $e) {
  echo json_encode(['status' => 'error', 'payment_intent' => []]);
}
