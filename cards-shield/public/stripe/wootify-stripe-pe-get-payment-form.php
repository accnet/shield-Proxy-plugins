	<?php
	require_once CARDSSHIELD_PLUGIN_DIR . '/includes/config/config.php';
	require_once CARDSSHIELD_PLUGIN_DIR . '/includes/config/config-Stripe.php';
	require_once CARDSSHIELD_PLUGIN_DIR . '/includes/helpers/helpers.php';
	Helpers::checkRequest("GET", false);
	$amount = $_GET['amount'] ?? 0;
	$currency = $_GET['currency'] ?? 'USD';
	
	// Validate Stripe configuration
	$stripe_key = defined('STRIPE_PUBLISHABLE_KEY') ? STRIPE_PUBLISHABLE_KEY : '';
	$has_error = empty($stripe_key);
	?>
	<html>

	<head>
	  <style>
		body {
		  margin: 10px 0 0 0;
		}

		#payment-form {
		  padding: 10px;
		  border-radius: 4px;
		  background: #fff;
		}
		
		.stripe-error {
		  color: #dc3545;
		  padding: 15px;
		  background: #f8d7da;
		  border: 1px solid #f5c6cb;
		  border-radius: 4px;
		  margin: 10px;
		}
	  </style>
	</head>

	<body>
	  <?php if ($has_error): ?>
	    <div class="stripe-error">
	      Stripe configuration error. Please check Stripe API keys.
	    </div>
	    <script>
	      parent.postMessage({ name: "wootify-errorSubmitPaymentStripe", value: "Stripe not configured properly" }, "*");
	    </script>
	  <?php else: ?>
	  <form id="payment-form">
		<div id="payment-element">
		</div>
	  </form>
	  <script>
		window.stripePublicKey = "<?= esc_js($stripe_key) ?>";
		window.wootifyProxySite = "<?= esc_js(SHIELD_URL) ?>";
		window.stripeAmount = "<?= esc_js($amount) ?>";
		window.stripeCurrency = "<?= esc_js(strtolower($currency)) ?>";
		console.log('Stripe iframe config:', {
		  publicKey: window.stripePublicKey ? 'SET' : 'EMPTY',
		  amount: window.stripeAmount,
		  currency: window.stripeCurrency
		});
	  </script>
	  <script src="https://js.stripe.com/v3/"></script>
				<?php
		$jsFilePath = CARDSSHIELD_PLUGIN_DIR . '/assets/js/stripe-pe-payment-form.js';

				if (file_exists($jsFilePath)) {
					echo '<script>';
					$jsContent = file_get_contents($jsFilePath);
					echo wp_strip_all_tags($jsContent);
					echo '</script>';
				}
			?>
	  <?php endif; ?>
	</body>

	</html>