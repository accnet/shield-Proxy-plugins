<?php
/**
 * Settings AJAX Handler
 * Handles updating PayPal and Stripe gateway settings
 * 
 * @package Shield_Manager
 * @since 1.4
 */

add_action('wp_ajax_cards_shield_settings', function () {
  // Security: Capability check
  if (!current_user_can('manage_options')) {
    wp_send_json_error(array('message' => 'Unauthorized'), 403);
  }

  // Security: Nonce verification
  if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cards_shield_settings_nonce')) {
    wp_send_json_error(array('message' => 'Invalid security token'), 403);
  }

  // Sanitize inputs
  $settings = isset($_POST["settings"]) ? $_POST["settings"] : array();
  $command = isset($_POST['command']) ? sanitize_text_field($_POST['command']) : '';
  $isSuccess = false;

  // Save default bootstrap token for automatic site connection from rotation.
  if ($command === 'update_connection_settings') {
    $token = isset($settings['default_bootstrap_token'])
      ? sanitize_text_field($settings['default_bootstrap_token'])
      : '';
    $isSuccess = Shield_Option_Manager::update(OPT_SHIELD_DEFAULT_BOOTSTRAP_TOKEN, $token, true);
  }

  // Validate command
  if ($command == "update_paypal_settings" || $command == "update_stripe_settings") {
    $option_names = $command == "update_paypal_settings"
      ? array('woocommerce_WOOTIFY_paypal_settings', 'woocommerce_wootify_paypal_settings')
      : array('woocommerce_WOOTIFY_stripe_settings', 'woocommerce_stripe_settings');

    $existing_settings = array();
    foreach ($option_names as $option_name) {
      $existing_settings = Shield_Option_Manager::get($option_name, []);
      if (!empty($existing_settings)) {
        break;
      }
    }

    // Sanitize each setting
    foreach ($settings as $key => $value) {
      $sanitized_key = sanitize_key($key);
      $sanitized_value = is_array($value)
        ? array_map('sanitize_text_field', $value)
        : sanitize_text_field($value);
      $existing_settings[$sanitized_key] = $sanitized_value;
    }

    $save_results = array();
    foreach ($option_names as $option_name) {
      $save_results[] = Shield_Option_Manager::update($option_name, $existing_settings, true);
    }
    $isSuccess = in_array(true, $save_results, true);

    // NOTE: PayPal/Stripe credentials on proxy sites are managed exclusively by
    // the SaaS via HMAC sync-config. Do NOT push woocommerce gateway UI settings
    // (which contain no credentials) to proxy sites here.
  }

  echo json_encode(['success' => $isSuccess]);
  wp_die();
});

add_action('wp_ajax_cards_shield_saas_connect', function () {
  if (!current_user_can('manage_options')) {
    wp_send_json_error(array('message' => 'Unauthorized'), 403);
  }

  if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cards_shield_settings_nonce')) {
    wp_send_json_error(array('message' => 'Invalid security token'), 403);
  }

  $saas_url = isset($_POST['saas_url']) ? esc_url_raw($_POST['saas_url']) : '';
  $connect_key = isset($_POST['connect_key']) ? sanitize_text_field($_POST['connect_key']) : '';

  $result = Shield_SaaS_Client::connect_saas($saas_url, $connect_key);
  if ($result['success']) {
    wp_send_json_success($result);
  } else {
    wp_send_json_error($result);
  }
});

add_action('wp_ajax_cards_shield_saas_disconnect', function () {
  if (!current_user_can('manage_options')) {
    wp_send_json_error(array('message' => 'Unauthorized'), 403);
  }

  if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cards_shield_settings_nonce')) {
    wp_send_json_error(array('message' => 'Invalid security token'), 403);
  }

  $result = Shield_SaaS_Client::pause_saas();
  wp_send_json_success($result);
});

add_action('wp_ajax_cards_shield_saas_resume', function () {
  if (!current_user_can('manage_options')) {
    wp_send_json_error(array('message' => 'Unauthorized'), 403);
  }

  if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cards_shield_settings_nonce')) {
    wp_send_json_error(array('message' => 'Invalid security token'), 403);
  }

  $result = Shield_SaaS_Client::resume_saas();
  if ($result['success']) {
    wp_send_json_success($result);
  } else {
    wp_send_json_error($result);
  }
});

add_action('wp_ajax_cards_shield_saas_reset', function () {
  if (!current_user_can('manage_options')) {
    wp_send_json_error(array('message' => 'Unauthorized'), 403);
  }

  if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cards_shield_settings_nonce')) {
    wp_send_json_error(array('message' => 'Invalid security token'), 403);
  }

  $result = Shield_SaaS_Client::reset_saas_connection();
  wp_send_json_success($result);
});
