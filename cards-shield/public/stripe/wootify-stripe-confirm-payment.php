<?php

require_once CARDSSHIELD_PLUGIN_DIR . '/includes/stripe-php/init.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/helpers/helpers.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/config/config-Stripe.php';
Helpers::checkRequest();
if (!STRIPE_SECRET_KEY) {
  header('HTTP/1.1 503 Service Unavailable');
  exit;
}
$stripe = new \Stripe\StripeClient(STRIPE_SECRET_KEY);
$inputJSON = file_get_contents('php://input');
$orderData = json_decode($inputJSON);
try {
  $paymentIntent = $stripe->paymentIntents->retrieve(
    $orderData->payment_intent_id,
    ['expand' => ['latest_charge.refunds', 'latest_charge.balance_transaction']]
  );
  if ($paymentIntent->status == "succeeded") {
    $total = $orderData->amount ?? 0.0;
    Helpers::sendTransactionToShield($total, 'Stripe');
  }
  $status = $paymentIntent->status;
  $normalizedStatus = in_array($status, ['succeeded', 'requires_capture'], true) ? 'success' : $status;
  echo json_encode([
    'status' => $normalizedStatus,
    'payment_intent' => $paymentIntent,
    'charge' => $paymentIntent->latest_charge
  ]);
} catch (Stripe\Exception\CardException $e) {
  echo json_encode(['status' => 'error', 'payment_intent' => []]);
}
