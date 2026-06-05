<?php
$shield_stripe = get_option('shield_stripe') ?? [];
$secret_key = '';
$publishable_key = '';

if (is_array($shield_stripe) && count($shield_stripe) > 0) {
  $test_mode = isset($shield_stripe['test_mode']) ? $shield_stripe['test_mode'] : false;
  $secret_key =  $test_mode  ? $shield_stripe['test_secret_key'] : $shield_stripe['prod_secret_key'];
  $publishable_key = $test_mode  ? $shield_stripe['test_publishable_key'] : $shield_stripe['prod_publishable_key'];
}

define("STRIPE_SECRET_KEY", $secret_key);
define("STRIPE_PUBLISHABLE_KEY", $publishable_key);