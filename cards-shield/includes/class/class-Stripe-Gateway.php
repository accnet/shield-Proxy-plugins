<?php
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/stripe-php/init.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/config/config.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/class/class-Stripe.php';
class WC_CS_Stripe_Gateway extends WC_Payment_Gateway {
	public function __construct() {

		$this->id = 'cs_stripe';
		$this->icon = '';
		$this->has_fields = true;
		$this->method_title = 'CS Stripe';
		$this->method_description = 'CS Stripe';
		$this->supports = array(
			'products'
		);

		// Method with all the options fields
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();
		$this->title = $this->get_option('title');
		$this->enabled = $this->get_option('enabled');

		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

		add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
	}


	/**
	 * Plugin options, we deal with it in Step 3 too
	 */
	public function init_form_fields() {

		$this->form_fields = array(
			'enabled' => array(
				'title' => 'Enable/Disable',
				'label' => 'CS Stripe',
				'type' => 'checkbox',
				'description' => '',
				'default' => 'no'
			),
			'title' => array(
				'title' => 'Title',
				'type' => 'text',
				'description' => 'This controls the title which the user sees during checkout.',
				'default' => 'Stripe',
				'desc_tip' => true,
			),
		);
	}

	/**
	 * You will need it if you want your custom credit card form, Step 4 is about it
	 */
	public function payment_fields() {
?>
		<form id="payment-form">
			<div id="payment-element">
			</div>
		</form>
		<script>
			window.stripePublicKey = "<?= STRIPE_PUBLISHABLE_KEY ?>";
			window.wootifyProxySite = "<?= SHIELD_URL ?>";
		</script>
		<script src="<?= CARDSSHIELD_PLUGIN_URL ?>assets/js/stripe.js"></script>
<?php
	}

	/*
		 * Custom CSS and JS, in most cases required only when you decided to go with a custom credit card form
		 */
	public function payment_scripts() {
		if (!is_cart() && !is_checkout() && !isset($_GET['pay_for_order'])) {
			return;
		}
		if ('no' === $this->enabled) {
			return;
		}
		wp_enqueue_script('js_stripe', 'https://js.stripe.com/v3/', array('jquery'), NULL);

		wp_enqueue_style('wootify_styles', CARDSSHIELD_PLUGIN_URL . 'assets/css/styles.css?v=' . uniqid(), []);
	}


	/*
* Fields validation, more in Step 5
*/
	public function validate_fields() {
	}

	/*
* We're processing the payments here, everything about it is in Step 5
*/
	public function process_payment($order_id) {
		global $woocommerce;
		// we need it to get any order details
		$order = wc_get_order($order_id);
		$shippingName = $order->get_shipping_first_name() . " " . $order->get_shipping_last_name();
		$shippingAddress1 = $order->get_shipping_address_1();
		$shippingAddress2 = $order->get_shipping_address_2();
		$shippingCity = $order->get_shipping_city();
		$shippingCountry = $order->get_shipping_country();
		$shippingPostCode = $order->get_shipping_postcode();
		$shippingState = $order->get_shipping_state();

		// Billing
		$billingName = $order->get_billing_first_name() . " " . $order->get_billing_last_name();
		$billingAddress1 = $order->get_billing_address_1();
		$billingAddress2 = $order->get_billing_address_2();
		$billingCity = $order->get_billing_city();
		$billingCountry = $order->get_billing_country();
		$billingPostCode = $order->get_billing_postcode();
		$billingState = $order->get_billing_state();

		$shippingName = (empty($order->get_shipping_first_name()) && empty($order->get_shipping_last_name())) ? $billingName : $shippingName;
		$shippingAddress1 = empty($shippingAddress1) ? $billingAddress1 : $shippingAddress1;
		$shippingAddress2 = empty($shippingAddress2) ? $billingAddress2 : $shippingAddress2;
		$shippingCity = empty($shippingCity) ? $billingCity : $shippingCity;
		$shippingCountry = empty($shippingCountry) ? $billingCountry : $shippingCountry;
		$shippingPostCode = empty($shippingPostCode) ? $billingPostCode : $shippingPostCode;
		$shippingState = empty($shippingState) ? $billingState : $shippingState;

		$shipping = [
			'name' => $shippingName,
			'phone' => method_exists($order, 'get_shipping_phone') && $order->get_shipping_phone() ? $order->get_shipping_phone() : $order->get_billing_phone(),
			'address' => [
				'city' => $shippingCity,
				'country' => $shippingCountry,
				'line1' => $shippingAddress1,
				'line2' => $shippingAddress2,
				'postal_code' => $shippingPostCode,
				'state' => $shippingState,
			],
		];
		$order->add_order_note('CS Stripe');
		$payment_method_id = $_POST['wootify-stripe-payment-method-id'];
		$stripe = new \Stripe\StripeClient(STRIPE_SECRET_KEY);
		$paymentIntent = $stripe->paymentIntents->create([
			'amount' => $order->get_total() * 100,
			'currency' => $order->get_currency(),
			'payment_method' => $payment_method_id,
			'description' => get_bloginfo('name'),
			'shipping' => json_decode(json_encode($shipping), true),
			'confirm' => true,
			'metadata' => [
				'customer_email' => $order->get_billing_email(),
				'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'order_id' => 'WC-' . $order->get_order_number(),
				'merchant_site' => home_url(),
			],
		]);
		update_post_meta($order->get_id(), '_transaction_id', $paymentIntent->id);
		if ($paymentIntent->status == "succeeded") {
			$order->payment_complete();
			$woocommerce->cart->empty_cart();
			return [
				'result'   => 'success',
				'redirect' => $order->get_checkout_order_received_url()
			];
		} elseif ($paymentIntent->status == "requires_action") {
			$paymentIntentId = $paymentIntent->id;
			$order->add_order_note(sprintf(
				__('Stripe charged 3DS require verification, Payment Intent ID: %s', 'wootify'),
				$paymentIntentId
			));
			return [
				'result' => 'success',
				'redirect' => sprintf('#cs-confirm-pi-%s:%s:%s', $paymentIntent->client_secret, $order_id, uniqid()),
			];
		} else {
			$order->add_order_note('CS Stripe  ERROR');
			$order->update_status('failed');
			wc_add_notice('We cannot process your Stripe payment now, please try again with another method.', 'error');
		}
	}


	public function webhook() {
	}
}
