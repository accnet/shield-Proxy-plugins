<?php
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/stripe-php/init.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/helpers/helpers.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/config/config-Stripe.php';
Helpers::checkRequest("GET");
if (!STRIPE_SECRET_KEY) {
  header('HTTP/1.1 503 Service Unavailable');
  exit;
}
$stripe = new \Stripe\StripeClient(STRIPE_SECRET_KEY);

if (!isset($_GET['order_id'])) {
  header('HTTP/1.1 503 Service Unavailable');
  exit;
}
try {
  $order = wc_get_order($_GET['order_id']);
  if (!$order) {
    wc_add_notice('We cannot process your payment right now, please try another payment method.[12]', 'error');
    return wp_redirect(wc_get_checkout_url());
  }
  $transaction_id = $order->get_transaction_id();
  if (empty($transaction_id)) {
    wc_add_notice('We cannot process your payment right now, please try another payment method.[12]', 'error');
    return wp_redirect(wc_get_checkout_url());
  }
  $paymentIntent = $stripe->paymentIntents->retrieve(
    $transaction_id,
    ['expand' => ['latest_charge.refunds', 'latest_charge.balance_transaction']]
  );
  if ($paymentIntent->status == "succeeded") {
    $order->add_order_note(sprintf(__('Stripe Checkout charge complete (Payment Intent ID: %s) (3ds verification)', 'wootify'), $paymentIntent->id));
    update_post_meta($order->get_id(), '_transaction_id', $paymentIntent->id);
    WC()->cart->empty_cart();
    $order->payment_complete();
    return wp_redirect($order->get_checkout_order_received_url());
  }
  if ($paymentIntent->status == "requires_capture") {
    $order->add_order_note(sprintf(__('Stripe authorized (Payment Intent ID: %s) (3ds verification)', 'wootify'), $paymentIntent->id));
    update_post_meta($order->get_id(), '_transaction_id', $paymentIntent->id);
    $order->update_status('on-hold', 'Payment authorized, capture required.');
    WC()->cart->empty_cart();
    return wp_redirect($order->get_checkout_order_received_url());
  }
  $order->add_order_note(sprintf(
    __('failed Payment Intent ID: %s (3ds verification)', 'wootify'),
    $paymentIntent->id
  ));
  $order->update_status('failed');
  wc_add_notice('We cannot process your payment right now, please try another payment method.[12]', 'error');
  return wp_redirect(wc_get_checkout_url());
} catch (Stripe\Exception\CardException $e) {
  wc_add_notice('We cannot process your payment right now, please try another payment method.[12]', 'error');
  return wp_redirect(wc_get_checkout_url());
}
