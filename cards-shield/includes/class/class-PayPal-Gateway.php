<?php

require_once CARDSSHIELD_PLUGIN_DIR . '/includes/class/class-PayPal.php';
class WC_CS_PayPal_Gateway extends WC_Payment_Gateway {
	public function __construct() {

		$this->id = 'cs_paypal';
		$this->icon = '';
		$this->has_fields = true;
		$this->method_title = 'CS PayPal';
		$this->method_description = 'CS PayPal';
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
				'label' => 'CS PayPal',
				'type' => 'checkbox',
				'description' => '',
				'default' => 'no'
			),
			'title' => array(
				'title' => 'Title',
				'type' => 'text',
				'description' => 'This controls the title which the user sees during checkout.',
				'default' => 'PayPal',
				'desc_tip' => true,
			),
		);
	}

	/**
	 * You will need it if you want your custom credit card form, Step 4 is about it
	 */
	public function payment_fields() {
		$total = WC()->cart->get_total(false);
		$purchaseUnits = [
			[
				'amount' => [
					'currency_code' => get_woocommerce_currency(),
					'value' => (string)$total,
				],
			]
		];
?>

		<script>
			window.wootify_paypal_checkout_purchase_units = <?= json_encode($purchaseUnits) ?>;
			window.wootify_paypal_currency_code = '<?= get_woocommerce_currency() ?>';
		</script>
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
		wp_enqueue_script('sdk_js_paypal', 'https://www.paypal.com/sdk/js?client-id=' . PAYPAL_CLIENT_ID . '&currency=USD&intent=capture', false, NULL);
		wp_enqueue_script('cs_paypal', CARDSSHIELD_PLUGIN_URL . 'assets/js/paypal.js?v=' . uniqid(), array('jquery', 'sdk_js_paypal'));
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
		$order->add_order_note('CS PayPal');
		$pp_order_id = $_POST['wootify-paypal-payment-order-id'];
		$paypal = new PayPal();
		$orderCaptureResult = $paypal->capture($pp_order_id);
		$status = $orderCaptureResult['order']['status'] ?? "ERROR";
		if ($status === 'COMPLETED') {
			$order->payment_complete();
			$woocommerce->cart->empty_cart();
			return [
				'result'   => 'success',
				'redirect' => $order->get_checkout_order_received_url()
			];
		} else {
			$order->add_order_note('CS PayPal  ERROR');
			$order->update_status('failed');
			wc_add_notice('We cannot process your PayPal payment now, please try again with another method.', 'error');
		}
	}

	/*
* In case you need a webhook, like PayPal IPN etc
*/
	public function webhook() {
	}
}
