<?php
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/stripe-php/init.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/helpers/helpers.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/config/config-Stripe.php';
class Stripe {


  public function __construct() {
    $card_shields = get_option('card_shields');
    $sandbox_key = $card_shields['sandbox_2'];
    $secret_key_sandbox_2 = $card_shields['secret_key_sandbox_2'];
    $secret_key_2 = $card_shields['secret_key_2'];
    $this->secret_key = $sandbox_key ? $secret_key_sandbox_2 : $secret_key_2;
    if (empty($this->secret_key)) {
      throw new Exception('Secret key is missing.');
    }
    $this->stripe = new \Stripe\StripeClient($this->secret_key);
  }
  public function paymentIntents() {
    Helpers::checkRequest();

    try {
      $inputJSON = file_get_contents('php://input');
      $input = json_decode($inputJSON);

      $paymentIntent = $this->stripe->paymentIntents->create([
        'amount' => $input->amount * 100,
        'currency' => $input->currency,
        'payment_method' => $input->payment_method_id,
        'description' => $input->statement_descriptor,
        'shipping' => json_decode(json_encode($input->shipping), true),
        'confirm' => true,
        'metadata' => [
          'customer_email' => $input->customer_email,
          'customer_name' => $input->name,
          'order_id' => $input->order_invoice,
          'merchant_site' => home_url(),
        ],
      ]);

      $charge = $this->stripe->paymentIntents->retrieve(
        $paymentIntent->id,
        ['expand' => ['latest_charge.refunds', 'latest_charge.balance_transaction']]
      );

      $data = [
        'domain' => $_GET['merchant_site'] ?? 'GGWP',
        'amount' => $paymentIntent->amount,
        'type' => 'create',
        'payment_method' => 'Stripe'
      ];

      if ($paymentIntent->status == "succeeded") {
        wp_remote_post('https://shield.flamecms.dev/api/transaction/' . $shield_license_key, [
          'body' => $data
        ]);
      }

      echo json_encode([
        'status' => $paymentIntent->status == "succeeded" ? 'success' : $paymentIntent->status,
        'payment_intent' => $paymentIntent,
        'charge' => $charge->latest_charge
      ]);
    } catch (Stripe\Exception\CardException $e) {
      echo json_encode(['status' => 'error', 'payment_intent' => []]);
    }
  }

  public function confirm() {
    Helpers::checkRequest();

    try {
      $inputJSON = file_get_contents('php://input');
      $input = json_decode($inputJSON);
      $paymentIntent = $this->stripe->paymentIntents->retrieve(
        $input->payment_intent_id,
        ['expand' => ['latest_charge.refunds', 'latest_charge.balance_transaction']]
      );
      echo json_encode([
        'status' => $paymentIntent->status == "succeeded" ? 'success' : $paymentIntent->status,
        'payment_intent' => $paymentIntent,
        'charge' => $paymentIntent->latest_charge
      ]);
    } catch (Stripe\Exception\CardException $e) {
      echo json_encode(['status' => 'error', 'payment_intent' => []]);
    }
  }
  public function refund() {
    Helpers::checkRequest("GET");

    try {
      $transaction_id = $_GET['transaction_id'];
      $charge = $this->stripe->paymentIntents->retrieve(
        $transaction_id,
      );

      $refunds = $this->stripe->refunds->create([
        'charge' => $charge->latest_charge,
        'reason' => "requested_by_customer",
        'amount' => $_GET['amount'],
      ]);
      $charge = $this->stripe->paymentIntents->retrieve(
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
  }
}
