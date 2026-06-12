<?php
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/config/config.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/config/config-Stripe.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/helpers/helpers.php';
Helpers::checkRequest("GET", false);

$amount = isset($_GET['amount']) ? absint($_GET['amount']) : 0;
$currency = isset($_GET['currency']) ? strtolower(sanitize_text_field((string) $_GET['currency'])) : 'usd';
$parent_origin = isset($_GET['parent_origin']) ? esc_url_raw((string) $_GET['parent_origin']) : '';
$shipping_amount = isset($_GET['shipping_amount']) ? absint($_GET['shipping_amount']) : 0;
$shipping_label  = isset($_GET['shipping_label'])  ? sanitize_text_field((string) $_GET['shipping_label']) : 'Shipping';
$stripe_key = defined('STRIPE_PUBLISHABLE_KEY') ? STRIPE_PUBLISHABLE_KEY : '';
$has_error = empty($stripe_key) || $amount <= 0 || empty($currency);
?>
<html>
<head>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { margin: 0; background: transparent; }
    #stripe-link-express-form { padding: 0; }
    #stripe-link-express-element { min-height: 44px; }
    .stripe-link-error {
      color: #dc3545;
      padding: 10px;
      background: #f8d7da;
      border: 1px solid #f5c6cb;
      border-radius: 4px;
      font: 13px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }
  </style>
</head>
<body>
  <?php if ($has_error): ?>
    <div class="stripe-link-error">Stripe Link is not available right now.</div>
    <script>
      parent.postMessage({ name: "wootify-stripeLinkUnavailable", value: "invalid_config" }, "*");
    </script>
  <?php else: ?>
    <form id="stripe-link-express-form">
      <div id="stripe-link-express-element"></div>
    </form>
    <script>
      window.stripePublicKey = "<?= esc_js($stripe_key) ?>";
      window.wootifyProxySite = "<?= esc_js(SHIELD_URL) ?>";
      window.stripeLinkAmount = <?= (int) $amount ?>;
      window.stripeLinkCurrency = "<?= esc_js($currency) ?>";
      window.parentOrigin = "<?= esc_js($parent_origin) ?>";
      window.stripeLinkShippingAmount = <?= (int) $shipping_amount ?>;
      window.stripeLinkShippingLabel  = "<?= esc_js($shipping_label) ?>";
    </script>
    <script src="https://js.stripe.com/v3/"></script>
    <?php
      $jsFilePath = CARDSSHIELD_PLUGIN_DIR . '/assets/js/stripe-link-express-form.js';
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
