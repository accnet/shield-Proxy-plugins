<?php
$shield_paypal = get_option('shield_paypal') ?? [];
$PAYPAL_ENVIRONMENT = '';
$PAYPAL_ENDPOINTS = '';
$PAYPAL_CLIENT_ID = '';
$PAYPAL_CLIENT_SECRET = '';
if (is_array($shield_paypal) && count($shield_paypal) > 0) {
  $test_mode = isset($shield_paypal['test_mode']) ? $shield_paypal['test_mode'] : false;
  $PAYPAL_ENVIRONMENT = $test_mode ? 'sandbox' : 'production';
  $PAYPAL_ENDPOINTS = $test_mode ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
  $PAYPAL_CLIENT_ID = $test_mode ? $shield_paypal['test_client_id'] : $shield_paypal['prod_client_id'];
  $PAYPAL_CLIENT_SECRET = $test_mode ? $shield_paypal['test_secret_key'] : $shield_paypal['prod_secret_key'];
}

define('PAYPAL_ENVIRONMENT', $PAYPAL_ENVIRONMENT);
define('PAYPAL_ENDPOINTS', $PAYPAL_ENDPOINTS);
define('PAYPAL_CLIENT_ID', $PAYPAL_CLIENT_ID);
define('PAYPAL_CLIENT_SECRET', $PAYPAL_CLIENT_SECRET);

define("SBN_CODE", "DemoPortalNM6_MP");
