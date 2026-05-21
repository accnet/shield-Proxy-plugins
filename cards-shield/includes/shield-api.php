<?php
require_once CARDSSHIELD_PLUGIN_DIR . '/includes/helpers/helpers.php';

// =============================================================================
// HMAC request verification
// =============================================================================

function shield_hmac_keys_v2_all() {
  $keys = get_option('shield_hmac_keys_v2', []);
  return is_array($keys) ? $keys : [];
}

function shield_hmac_keys_v2_find($manager_id, $key_id) {
  foreach (shield_hmac_keys_v2_all() as $item) {
    if (($item['manager_id'] ?? '') === $manager_id && ($item['key_id'] ?? '') === $key_id) {
      return $item;
    }
  }
  return null;
}

function shield_hmac_keys_v2_has_active_manager($manager_id) {
  foreach (shield_hmac_keys_v2_all() as $item) {
    if (($item['manager_id'] ?? '') === $manager_id && (($item['status'] ?? 'active') === 'active')) {
      return true;
    }
  }
  return false;
}

function shield_hmac_v2_primary_manager_get() {
  return (string) get_option('shield_hmac_v2_primary_manager_id', '');
}

function shield_hmac_v2_primary_manager_set($manager_id) {
  update_option('shield_hmac_v2_primary_manager_id', (string)$manager_id, false);
}

function shield_hmac_keys_v2_upsert($manager_id, $key_id, $secret, $label = '') {
  $keys = shield_hmac_keys_v2_all();
  $found = false;
  foreach ($keys as &$item) {
    if (($item['manager_id'] ?? '') === $manager_id && ($item['key_id'] ?? '') === $key_id) {
      $item['hmac_secret'] = $secret;
      $item['label'] = $label;
      $item['status'] = 'active';
      $item['updated_at'] = time();
      $found = true;
      break;
    }
  }
  unset($item);

  if (!$found) {
    $keys[] = array(
      'manager_id' => $manager_id,
      'key_id' => $key_id,
      'hmac_secret' => $secret,
      'label' => $label,
      'status' => 'active',
      'created_at' => time(),
      'updated_at' => time(),
    );
  }

  update_option('shield_hmac_keys_v2', $keys, false);
  return $keys;
}

function shield_hmac_keys_v2_revoke($manager_id, $key_id = '') {
  $keys = shield_hmac_keys_v2_all();
  $revoked = 0;

  foreach ($keys as &$item) {
    if (($item['manager_id'] ?? '') !== $manager_id) {
      continue;
    }
    if ($key_id && (($item['key_id'] ?? '') !== $key_id)) {
      continue;
    }

    $item['status'] = 'revoked';
    $item['updated_at'] = time();
    $revoked++;
  }
  unset($item);

  update_option('shield_hmac_keys_v2', $keys, false);

  $primary = shield_hmac_v2_primary_manager_get();
  if ($primary === $manager_id && !shield_hmac_keys_v2_has_active_manager($manager_id)) {
    shield_hmac_v2_primary_manager_set('');
  }

  return $revoked;
}

function shield_verify_bootstrap_token(WP_REST_Request $request) {
  $token = sanitize_text_field($request->get_header('X-Shield-Bootstrap-Token') ?? '');
  if (!$token) {
    $payload = $request->get_json_params();
    $token = sanitize_text_field($payload['bootstrap_token'] ?? '');
  }

  $expected = (string) get_option('shield_proxy_key', '');

  return ($expected && $token && hash_equals($expected, $token));
}

function shield_hmac_v2_nonce_once($manager_id, $nonce, $timestamp) {
  $transient_key = 'shield_hmac_n_' . md5($manager_id . '|' . $nonce . '|' . (string)$timestamp);
  if (get_transient($transient_key)) {
    return false;
  }
  set_transient($transient_key, '1', 300);
  return true;
}

function shield_request_uses_hmac_v2(WP_REST_Request $request) {
  $manager_id = sanitize_text_field($request->get_header('X-Shield-Manager-Id') ?? '');
  $key_id     = sanitize_text_field($request->get_header('X-Shield-Key-Id') ?? '');
  return !empty($manager_id) && !empty($key_id);
}

function shield_verify_hmac_v2(WP_REST_Request $request) {
  $manager_id = sanitize_text_field($request->get_header('X-Shield-Manager-Id') ?? '');
  $key_id     = sanitize_text_field($request->get_header('X-Shield-Key-Id') ?? '');
  $signature  = $request->get_header('X-Shield-Signature');
  $nonce      = sanitize_text_field($request->get_header('X-Shield-Nonce') ?? '');
  $timestamp  = (int) $request->get_header('X-Shield-Timestamp');

  if (!$manager_id || !$key_id || !$signature || !$nonce || !$timestamp) {
    return false;
  }

  if (abs(time() - $timestamp) > 300) {
    return false;
  }

  if (!shield_hmac_v2_nonce_once($manager_id, $nonce, $timestamp)) {
    return false;
  }

  $cred = shield_hmac_keys_v2_find($manager_id, $key_id);
  if (!$cred || empty($cred['hmac_secret']) || (($cred['status'] ?? 'active') !== 'active')) {
    return false;
  }

  $canonical = implode("\n", [
    strtoupper($request->get_method()),
    (string) $request->get_route(),
    hash('sha256', (string) $request->get_body()),
    (string) $timestamp,
    $nonce,
    $manager_id,
    $key_id,
  ]);

  $expected = hash_hmac('sha256', $canonical, (string) $cred['hmac_secret']);
  return hash_equals($expected, (string) $signature);
}

/**
 * Verify v2 HMAC signature sent by Shield Manager.
 * Legacy v1 (X-Shield-License) is intentionally disabled.
 */
function shield_verify_hmac(WP_REST_Request $request) {
  if (!shield_request_uses_hmac_v2($request)) {
    return false;
  }
  return shield_verify_hmac_v2($request);
}

// =============================================================================
// REST endpoint registration
// =============================================================================

add_action('rest_api_init', 'register_shield_options_endpoint');

function register_shield_options_endpoint() {
  // DEPRECATED: /shield/paypal and /shield/stripe were the old mechanism for
  // pushing WooCommerce UI settings from site2 to site1. Payment credentials
  // are now exclusively managed by the SaaS via POST /shield/v1/sync-config.
  // These endpoints are disabled to prevent stale UI settings from overwriting
  // the credential-only shield_paypal / shield_stripe options.
  register_rest_route('shield', '/paypal', array(
    'methods'             => 'POST',
    'callback'            => 'shield_legacy_endpoint_disabled',
    'permission_callback' => '__return_true',
  ));

  register_rest_route('shield', '/stripe', array(
    'methods'             => 'POST',
    'callback'            => 'shield_legacy_endpoint_disabled',
    'permission_callback' => '__return_true',
  ));

  register_rest_route('shield', '/sync', array(
    'methods'             => 'POST',
    'callback'            => 'add_option_callback',
    'args'                => array(
      array('option_name' => 'shield_data')
    ),
    'permission_callback' => 'shield_verify_hmac',
  ));

  // Health-check endpoint — read-only, still HMAC-protected.
  register_rest_route('shield', '/health', array(
    'methods'             => 'GET',
    'callback'            => 'shield_health_callback',
    'permission_callback' => 'shield_verify_hmac',
  ));

  // One-time/bootstrap endpoint used by manager plugins to register v2 keys.
  register_rest_route('shield/v2', '/bootstrap', array(
    'methods'             => 'POST',
    'callback'            => 'shield_bootstrap_v2_callback',
    'permission_callback' => '__return_true',
  ));

  register_rest_route('shield/v2', '/rotate', array(
    'methods'             => 'POST',
    'callback'            => 'shield_rotate_v2_callback',
    'permission_callback' => '__return_true',
  ));

  register_rest_route('shield/v2', '/revoke', array(
    'methods'             => 'POST',
    'callback'            => 'shield_revoke_v2_callback',
    'permission_callback' => '__return_true',
  ));

  register_rest_route('shield/v2', '/set-primary', array(
    'methods'             => 'POST',
    'callback'            => 'shield_set_primary_v2_callback',
    'permission_callback' => '__return_true',
  ));
}

// =============================================================================
// Callbacks
// =============================================================================

function shield_legacy_endpoint_disabled(WP_REST_Request $request) {
  return new WP_REST_Response(array(
    'message' => 'This endpoint is deprecated. Payment credentials are managed exclusively by the SaaS via /shield/v1/sync-config.',
    'code'    => 'endpoint_deprecated',
  ), 410);
}

function add_option_callback(WP_REST_Request $request) {
  $attributes  = $request->get_attributes();
  $option_name = $attributes['args'][0]['option_name'];

  $body        = json_decode($request->get_body(), true);
  $manager_id  = sanitize_text_field($request->get_header('X-Shield-Manager-Id') ?? '');
  $primary = shield_hmac_v2_primary_manager_get();

  // Enforce single-writer policy for payment config updates.
  if (in_array($option_name, array('shield_paypal', 'shield_stripe'), true)) {
    if ($primary && $manager_id !== $primary) {
      return new WP_REST_Response(array('message' => 'Forbidden: manager is not primary writer'), 403);
    }
    if (!$primary && $manager_id) {
      shield_hmac_v2_primary_manager_set($manager_id);
    }
  }

  update_option($option_name, $body['data']);
  return new WP_REST_Response(array('message' => 'Success'), 200);
}

function shield_bootstrap_v2_callback(WP_REST_Request $request) {
  if (!shield_verify_bootstrap_token($request)) {
    return new WP_REST_Response(array('message' => 'Unauthorized'), 401);
  }

  $body       = $request->get_json_params();
  $manager_id = sanitize_text_field($body['manager_id'] ?? '');
  $key_id     = sanitize_text_field($body['key_id'] ?? '');
  $secret     = sanitize_text_field($body['hmac_secret'] ?? '');
  $label      = sanitize_text_field($body['label'] ?? '');

  if (!$manager_id || !$key_id || !$secret) {
    return new WP_REST_Response(array('message' => 'manager_id, key_id, hmac_secret are required'), 400);
  }

  $keys = shield_hmac_keys_v2_upsert($manager_id, $key_id, $secret, $label);

  return new WP_REST_Response(array(
    'registered' => true,
    'manager_id' => $manager_id,
    'key_id' => $key_id,
    'total_keys' => count($keys),
  ), 200);
}

function shield_rotate_v2_callback(WP_REST_Request $request) {
  if (!shield_verify_bootstrap_token($request)) {
    return new WP_REST_Response(array('message' => 'Unauthorized'), 401);
  }

  $body       = $request->get_json_params();
  $manager_id = sanitize_text_field($body['manager_id'] ?? '');
  $old_key_id = sanitize_text_field($body['key_id'] ?? '');
  $revoke_old = !empty($body['revoke_old']);

  if (!$manager_id || !$old_key_id) {
    return new WP_REST_Response(array('message' => 'manager_id and key_id are required'), 400);
  }

  $old = shield_hmac_keys_v2_find($manager_id, $old_key_id);
  if (!$old) {
    return new WP_REST_Response(array('message' => 'key not found'), 404);
  }

  $new_key_id = 'kid_' . bin2hex(random_bytes(8));
  $new_secret = bin2hex(random_bytes(32));
  shield_hmac_keys_v2_upsert($manager_id, $new_key_id, $new_secret, sanitize_text_field($old['label'] ?? ''));

  if ($revoke_old) {
    shield_hmac_keys_v2_revoke($manager_id, $old_key_id);
  }

  return new WP_REST_Response(array(
    'rotated' => true,
    'manager_id' => $manager_id,
    'old_key_id' => $old_key_id,
    'key_id' => $new_key_id,
    'hmac_secret' => $new_secret,
  ), 200);
}

function shield_revoke_v2_callback(WP_REST_Request $request) {
  if (!shield_verify_bootstrap_token($request)) {
    return new WP_REST_Response(array('message' => 'Unauthorized'), 401);
  }

  $body       = $request->get_json_params();
  $manager_id = sanitize_text_field($body['manager_id'] ?? '');
  $key_id     = sanitize_text_field($body['key_id'] ?? '');

  if (!$manager_id) {
    return new WP_REST_Response(array('message' => 'manager_id is required'), 400);
  }

  $count = shield_hmac_keys_v2_revoke($manager_id, $key_id);
  return new WP_REST_Response(array('revoked' => $count), 200);
}

function shield_set_primary_v2_callback(WP_REST_Request $request) {
  if (!shield_verify_bootstrap_token($request)) {
    return new WP_REST_Response(array('message' => 'Unauthorized'), 401);
  }

  $body       = $request->get_json_params();
  $manager_id = sanitize_text_field($body['manager_id'] ?? '');
  if (!$manager_id) {
    return new WP_REST_Response(array('message' => 'manager_id is required'), 400);
  }

  if (!shield_hmac_keys_v2_has_active_manager($manager_id)) {
    return new WP_REST_Response(array('message' => 'manager has no active key'), 400);
  }

  shield_hmac_v2_primary_manager_set($manager_id);
  return new WP_REST_Response(array('primary_manager_id' => $manager_id), 200);
}

/**
 * Return basic status information about this Cards Shield installation.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function shield_health_callback(WP_REST_Request $request) {
  $gateways = [];
  if (class_exists('WC_CS_PayPal_Gateway')) {
    $gateways[] = 'PayPal';
  }
  if (class_exists('WC_CS_Stripe_Gateway')) {
    $gateways[] = 'Stripe';
  }

  return new WP_REST_Response(array(
    'status'  => 'ok',
    'version' => CARDSSHIELD_VERSION,
    'domain'  => Helpers::getSiteUrl(),
    'gateways' => $gateways,
  ), 200);
}
