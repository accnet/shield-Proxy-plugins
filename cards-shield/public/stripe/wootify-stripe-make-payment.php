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
  $paymentIntent = $stripe->paymentIntents->create([
    'amount' => $orderData->amount * 100,
    'currency' => $orderData->currency,
    'payment_method' => $orderData->payment_method_id,
    'description' => $orderData->statement_descriptor,
    'shipping' => json_decode(json_encode($orderData->shipping), true),
    'confirm' => true,
    'metadata' => [
      'customer_email' => $orderData->customer_email,
      'customer_name' => $orderData->name,
      'order_id' => $orderData->order_invoice,
      'merchant_site' => home_url(),
    ],
  ]);
  if ($paymentIntent->status == "succeeded") {
    $total = $orderData->amount ?? 0.0;
    Helpers::sendTransactionToShield($total, 'Stripe');
  }
  echo json_encode([
    'status' => $paymentIntent->status == "succeeded" ? 'success' : $paymentIntent->status,
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
