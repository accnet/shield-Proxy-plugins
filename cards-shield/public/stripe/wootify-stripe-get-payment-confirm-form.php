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
  </script>
  <script src="https://js.stripe.com/v3/"></script>
  <script src="<?= CARDSSHIELD_PLUGIN_URL; ?>assets/js/stripe-payment-form-confirm.js?v=<?= CARDSSHIELD_VERSION ?>">
  </script>
</body>

</html>