<?php
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/config/config.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/config/config-PayPal.php';
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/helpers/helpers.php';
Helpers::checkRequest("GET", false);
// Validate and sanitize GET params to prevent XSS via script src injection
$allowed_currencies = ['USD','EUR','GBP','AUD','CAD','JPY','CNY','HKD','SGD','MXN','BRL','INR','TWD','SEK','NOK','DKK','CHF','PLN','HUF','CZK','ILS','MYR','PHP','THB','IDR','NZD','RUB','AED','SAR','QAR','KWD','BHD','OMR'];
$currency = in_array(strtoupper($_GET['currency'] ?? 'USD'), $allowed_currencies, true) ? strtoupper($_GET['currency']) : 'USD';
$allowed_intents = ['capture', 'authorize'];
$intent = in_array(strtolower($_GET['intent'] ?? 'capture'), $allowed_intents, true) ? strtolower($_GET['intent']) : 'capture';
$is_not_checkout_page = $_GET['is_not_checkout_page'] ?? false;
$nameFile = $is_not_checkout_page ? 'paypal-credit-payment-form-custom' : 'paypal-credit-payment-form';
$jsFilePath = CARDSSHIELD_PLUGIN_DIR . '/assets/js/' . $nameFile . '.js';

$expressButtonClass = isset($_GET['express_button_style']) ? 'express_button_style' : '';
$allowed_funding = ['card', 'credit', 'paylater', 'venmo', 'sepa', 'bancontact', 'giropay', 'ideal', 'mybank', 'sofort', 'eps', 'przelewy24'];
$raw_funding = $_GET['disable-funding'] ?? '';
$disableFunding = in_array($raw_funding, $allowed_funding, true) ? $raw_funding : '';
if ($disableFunding === '' && (isset($_GET['disable_credit_card']) || isset($_GET['disable_credit_card_express']))) {
	$disableFunding = 'card';
}
$disablefundingParam = $disableFunding !== '' ? '&disable-funding=' . $disableFunding : '';
?>

<!DOCTYPE html>
<html>

	<head>
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<style>
			body {
				margin: 0;
			}

			.hide_paypal_btn {
				display: none !important;
			}

			#paypal-button-container {
				display: flex;
				flex-direction: column;
				align-items: center;
				justify-content: center;
				margin-top: 15px;
			}
		</style>
	</head>

	<body>
		<script>
			window.wootifyProxySite = "<?= SHIELD_URL ?>";
			window.wootifyDomainWhiteList = undefined;
			window.wootifyZipcodeGlobalBlacklist = [];
			window.wootifyZipcodeLocalBlacklist = undefined;
			window.wootifyEmailGlobalBlacklist = undefined;
			window.wootifyEmailLocalBlacklist = undefined;
			window.wootifyGlobalStatesBlacklist = undefined;
			window.wootifyGlobalCitiesStatesBlacklist = undefined;
			window.wootifyLocalStatesBlacklist = undefined;
			window.wootifyLocalCitiesStatesBlacklist = undefined;
		</script>
		<script src="https://www.paypal.com/sdk/js?client-id=<?= PAYPAL_CLIENT_ID ?>&currency=<?= $currency ?>&intent=<?= $intent ?><?= $disablefundingParam ?>">
		</script>
		<div id="paypal-button-container" class="<?= $expressButtonClass ?>"></div>
		<?php

	if (file_exists($jsFilePath)) {
		echo '<script>';
		$jsContent = file_get_contents($jsFilePath);
		echo wp_strip_all_tags($jsContent);
		echo '</script>';
	}
		?>
	</body>

</html>