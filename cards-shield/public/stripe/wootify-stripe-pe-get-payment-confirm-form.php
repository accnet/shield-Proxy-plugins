<?php
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/config/config.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/config/config-Stripe.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/helpers/helpers.php';
Helpers::checkRequest("GET", false);
?>
<html>

	<body>
		<script>
			window.stripePublicKey = "<?= STRIPE_PUBLISHABLE_KEY ?>";
			window.wootifyProxySite = "<?= SHIELD_URL ?>";
			window.parentOrigin = "<?= isset($_GET['parent_origin']) ? esc_js(sanitize_text_field($_GET['parent_origin'])) : '' ?>";
		</script>
		<script src="https://js.stripe.com/v3/"></script>
		<?php
	$jsFilePath = CARDSSHIELD_PLUGIN_DIR . '/assets/js/stripe-pe-payment-form-confirm.js';
			
			if (file_exists($jsFilePath)) {
				echo '<script>';
				$jsContent = file_get_contents($jsFilePath);
				echo wp_strip_all_tags($jsContent);
				echo '</script>';
			}
		?>
	</body>

</html>