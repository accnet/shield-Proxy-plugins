<?php
/**
 * Rotation AJAX Handler
 * Handles proxy rotation actions for both PayPal and Stripe
 * 
 * @package Shield_Manager
 * @since 1.4
 */

add_action('wp_ajax_rotation_action', function () {
  // Security: Capability check
  if (!current_user_can('manage_options')) {
    wp_send_json_error(array('message' => 'Unauthorized'), 403);
  }

  // Security: Nonce verification
  if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rotation_action_nonce')) {
    wp_send_json_error(array('message' => 'Invalid security token'), 403);
  }

  // Sanitize command
  $command = isset($_POST['command']) ? sanitize_text_field($_POST['command']) : '';

  switch ($command) {
    case 'add_new_proxy':
      add_new_proxy();
      break;
    case 'save_proxies':
      save_proxies();
      break;
    case 'change_rotation_method':
      change_rotation_method();
      break;
    case 'change_position':
      change_position();
      break;
    case 'move_to_unused_proxies':
      move_to_unused_proxies();
      break;
    case 'move_back_proxy':
      move_back_proxy();
      break;
    case 'delete_proxy':
      delete_proxy();
      break;
    case 'activate_proxy':
      activate_proxy();
      break;

    default:
      break;
  }
  wp_die();
});

function shield_rotation_tab_label_to_method($label, $keys)
{
  $label = strtolower((string)$label);
  if ($label === 'volume') {
    return $keys['byAmount'];
  }
  if ($label === 'time') {
    return $keys['byTime'];
  }
  if ($label === 'order') {
    return $keys['byOrder'];
  }
  return '';
}

function shield_rotation_tab_storage_prefix($PG, $method)
{
  $pg = strtolower(preg_replace('/[^a-z0-9]+/i', '_', (string)$PG));
  $rm = strtolower(preg_replace('/[^a-z0-9]+/i', '_', (string)$method));
  return 'OPT_SHIELD_ROTATION_TAB_' . $pg . '_' . $rm . '_';
}

function shield_rotation_get_tab_state($PG, $keys, $method)
{
  // For the active rotation method, always read from legacy options so that
  // runtime auto-rotate changes (time/amount/order) are immediately reflected
  // in the management UI without requiring a tab switch.
  $activeMethod = Shield_Option_Manager::get($keys['rotationMethod'], $keys['byTime'] ?? 'by_time');
  if ($method === $activeMethod) {
    $state = [
      'proxies'         => Shield_Option_Manager::get($keys['proxies'], []),
      'unusedProxies'   => Shield_Option_Manager::get($keys['unusedProxies'], []),
      'positionList'    => Shield_Option_Manager::get($keys['positionList'], []),
      'activatedProxy'  => Shield_Option_Manager::get($keys['activatedProxy'], null),
      'currentRotation' => Shield_Option_Manager::get($keys['currentRotation'], time()),
    ];
    if (!is_array($state['proxies']))       $state['proxies']       = [];
    if (!is_array($state['unusedProxies'])) $state['unusedProxies'] = [];
    if (!is_array($state['positionList']))  $state['positionList']  = [];
    // Keep tab storage in sync with current runtime state.
    shield_rotation_set_tab_state($PG, $method, $state);
    return $state;
  }

  // For inactive tabs, read from tab-scoped storage.
  $prefix = shield_rotation_tab_storage_prefix($PG, $method);
  $state = [
    'proxies'         => Shield_Option_Manager::get($prefix . 'PROXIES', null),
    'unusedProxies'   => Shield_Option_Manager::get($prefix . 'UNUSED_PROXIES', null),
    'positionList'    => Shield_Option_Manager::get($prefix . 'POSITION_LIST', null),
    'activatedProxy'  => Shield_Option_Manager::get($prefix . 'ACTIVATED_PROXY', null),
    'currentRotation' => Shield_Option_Manager::get($prefix . 'CURRENT_ROTATION', null),
  ];

  // Bootstrap inactive tab state from legacy options on first use.
  if ($state['proxies'] === null) {
    $state['proxies']         = Shield_Option_Manager::get($keys['proxies'], []);
    $state['unusedProxies']   = Shield_Option_Manager::get($keys['unusedProxies'], []);
    $state['positionList']    = Shield_Option_Manager::get($keys['positionList'], []);
    $state['activatedProxy']  = Shield_Option_Manager::get($keys['activatedProxy'], null);
    $state['currentRotation'] = Shield_Option_Manager::get($keys['currentRotation'], time());
    shield_rotation_set_tab_state($PG, $method, $state);
  }

  if (!is_array($state['proxies']))       $state['proxies']       = [];
  if (!is_array($state['unusedProxies'])) $state['unusedProxies'] = [];
  if (!is_array($state['positionList']))  $state['positionList']  = [];

  return $state;
}

function shield_rotation_set_tab_state($PG, $method, $state)
{
  $prefix = shield_rotation_tab_storage_prefix($PG, $method);
  Shield_Option_Manager::update($prefix . 'PROXIES', $state['proxies'] ?? [], true);
  Shield_Option_Manager::update($prefix . 'UNUSED_PROXIES', $state['unusedProxies'] ?? [], true);
  Shield_Option_Manager::update($prefix . 'POSITION_LIST', $state['positionList'] ?? [], true);
  Shield_Option_Manager::update($prefix . 'ACTIVATED_PROXY', $state['activatedProxy'] ?? null, true);
  Shield_Option_Manager::update($prefix . 'CURRENT_ROTATION', $state['currentRotation'] ?? time(), true);
}

function shield_rotation_sync_active_tab_to_legacy($PG, $keys, $method)
{
  // Only sync to shared legacy options when the modified tab is the currently active rotation method.
  $activeMethod = Shield_Option_Manager::get($keys['rotationMethod'], $keys['byTime'] ?? 'by_time');
  if ($method !== $activeMethod) {
    return;
  }
  // Read directly from tab-scoped storage (NOT via get_tab_state which would
  // re-read from legacy for the active tab and overwrite what we just saved).
  $prefix = shield_rotation_tab_storage_prefix($PG, $method);
  Shield_Option_Manager::update($keys['proxies'],        Shield_Option_Manager::get($prefix . 'PROXIES', []), true);
  Shield_Option_Manager::update($keys['unusedProxies'],  Shield_Option_Manager::get($prefix . 'UNUSED_PROXIES', []), true);
  Shield_Option_Manager::update($keys['positionList'],   Shield_Option_Manager::get($prefix . 'POSITION_LIST', []), true);
  Shield_Option_Manager::update($keys['activatedProxy'], Shield_Option_Manager::get($prefix . 'ACTIVATED_PROXY', null), true);
  Shield_Option_Manager::update($keys['currentRotation'],Shield_Option_Manager::get($prefix . 'CURRENT_ROTATION', time()), true);
}

function shield_rotation_persist_legacy_to_tab($PG, $keys, $method)
{
  $state = [
    'proxies' => Shield_Option_Manager::get($keys['proxies'], []),
    'unusedProxies' => Shield_Option_Manager::get($keys['unusedProxies'], []),
    'positionList' => Shield_Option_Manager::get($keys['positionList'], []),
    'activatedProxy' => Shield_Option_Manager::get($keys['activatedProxy'], null),
    'currentRotation' => Shield_Option_Manager::get($keys['currentRotation'], time()),
  ];
  shield_rotation_set_tab_state($PG, $method, $state);
}

function shield_normalize_site_url($url)
{
  return trailingslashit(esc_url_raw($url));
}

function shield_find_registered_site_by_url($url)
{
  $needle = rtrim(shield_normalize_site_url($url), '/');
  foreach (Shield_Site_Registry::all() as $site) {
    if (rtrim($site['url'], '/') === $needle) {
      return $site;
    }
  }
  return null;
}

function shield_auto_connect_site_from_rotation($proxyUrl)
{
  $result = [
    'site_id' => '',
    'created' => false,
    'bootstrapped' => false,
    'warning' => '',
  ];

  $existing = shield_find_registered_site_by_url($proxyUrl);
  if ($existing) {
    $result['site_id'] = $existing['id'];
    return $result;
  }

  $label = parse_url($proxyUrl, PHP_URL_HOST);
  $label = $label ? ('Auto ' . $label) : 'Auto Proxy Site';
  $token = Shield_Option_Manager::get(OPT_SHIELD_DEFAULT_BOOTSTRAP_TOKEN, '');

  $site = Shield_Site_Registry::add($proxyUrl, $label, '', $token);
  $result['site_id'] = $site['id'];
  $result['created'] = true;

  if (!$token) {
    $result['warning'] = 'Site record created, but default bootstrap token is empty. Please set it in Settings.';
    return $result;
  }

  $boot = Shield_API_Client::bootstrap_v2($site, $token);
  Shield_Site_Registry::update($site['id'], [
    'bootstrap_status' => $boot['success'] ? 'ready' : 'failed',
  ]);

  if (!$boot['success']) {
    $result['warning'] = $boot['error'] ?: 'Bootstrap failed';
    return $result;
  }

  $result['bootstrapped'] = true;
  Shield_Health_Checker::check(Shield_Site_Registry::find($site['id']));
  return $result;
}

function add_new_proxy()
{
  $PG             = sanitize_text_field($_POST['PG'] ?? '');
  $rotationMethod = sanitize_text_field($_POST['rotationMethod'] ?? '');
  $proxyUrl       = esc_url_raw($_POST['proxyUrl'] ?? '');
  $rotationValue  = (float) ($_POST['rotationValue'] ?? 0);
  if ($rotationValue <= 0) {
    echo json_encode(['success' => false, 'error' => 'invalid_value', 'message' => 'Rotation value must be greater than 0.']);
    return;
  }
  if (isset(OPTIONKEYS[$PG])) {
    $keys = OPTIONKEYS[$PG];
    $state = shield_rotation_get_tab_state($PG, $keys, $rotationMethod);
    $proxies = $state['proxies'];

    // Reject duplicate URL in same tab (active + unused).
    $normalizedNew = rtrim(shield_normalize_site_url($proxyUrl), '/');
    $allTabProxies = array_merge($proxies, $state['unusedProxies'] ?? []);
    foreach ($allTabProxies as $_existing) {
      if (rtrim(shield_normalize_site_url($_existing['url'] ?? ''), '/') === $normalizedNew) {
        echo json_encode([
          'success' => false,
          'error'   => 'duplicate_url',
          'message' => 'This site URL is already in the list for this rotation method.',
        ]);
        return;
      }
    }

    $connection = shield_auto_connect_site_from_rotation($proxyUrl);
    $site_id = $connection['site_id'];
    $proxy = [
      'id'          => uniqid('px_', true),
      'url'         => $proxyUrl,
      'site_id'     => $site_id,
      'paid_amount' => 0,
      'order_count' => 0,
    ];
    if ($rotationMethod === $keys['byTime']) {
      $proxy['timestamp'] = $rotationValue;
      $proxy['amount'] = 0;
      $proxy['order'] = 0;
    }
    else if ($rotationMethod === $keys['byAmount']) {
      $proxy['timestamp'] = 0;
      $proxy['amount'] = $rotationValue;
      $proxy['order'] = 0;
    }
    else if ($rotationMethod === $keys['byOrder']) {
      $proxy['timestamp'] = 0;
      $proxy['amount'] = 0;
      $proxy['order'] = $rotationValue;
    }
    $proxies[] = $proxy;

    $activatedProxy = $state['activatedProxy'];
    if (empty($activatedProxy)) {
      $state['activatedProxy'] = $proxies[0];
      $state['currentRotation'] = time();
    }
    $state['proxies'] = $proxies;
    shield_rotation_set_tab_state($PG, $rotationMethod, $state);
    shield_rotation_sync_active_tab_to_legacy($PG, $keys, $rotationMethod);

    echo json_encode([
      'success' => true,
      'addedProxy' => $proxy,
      'connection' => $connection,
    ]);
  }
  else {
    echo json_encode([
      'success' => false,
    ]);
  }
}

function save_proxies()
{
  $newProxies = $_POST['proxies'] ?? [];
  $name       = sanitize_key($_POST['name'] ?? '');
  $PG         = sanitize_text_field($_POST['PG'] ?? '');
  $rotationMethod = sanitize_text_field($_POST['rotationMethod'] ?? '');
  if (isset(OPTIONKEYS[$PG])) {
    $keys = OPTIONKEYS[$PG];
    $state = shield_rotation_get_tab_state($PG, $keys, $rotationMethod);
    $proxies = $state['proxies'];
    $unusedProxies = $state['unusedProxies'];
    $activatedProxy = $state['activatedProxy'];

    foreach ($proxies as $key => $proxy) {
      $newProxyKey = findPositionById($newProxies, $proxy['id']);
      if ($newProxyKey < 0)
        continue;
      $val = (float) ($newProxies[$newProxyKey]['rotationValue'] ?? 0);
      if ($val <= 0) {
        echo json_encode(['success' => false, 'error' => 'invalid_value', 'message' => 'Rotation value must be greater than 0.']);
        return;
      }
      $proxies[$key][$name] = $val;
      if (isset($activatedProxy) && $activatedProxy['id'] === $proxy['id']) {
        $activatedProxy = $proxies[$key];
      }
    }

    foreach ($unusedProxies as $key => $proxy) {
      $newProxyKey = findPositionById($newProxies, $proxy['id']);
      if ($newProxyKey < 0)
        continue;
      $unusedProxies[$key][$name] = $newProxies[$newProxyKey]['rotationValue'];
    }

    $state['proxies'] = $proxies;
    $state['unusedProxies'] = $unusedProxies;
    $state['activatedProxy'] = $activatedProxy;
    shield_rotation_set_tab_state($PG, $rotationMethod, $state);
    shield_rotation_sync_active_tab_to_legacy($PG, $keys, $rotationMethod);

    echo json_encode(['success' => true, ]);
  }
  else {
    echo json_encode(['success' => false, ]);
  }
}

function change_rotation_method()
{
  $PG             = sanitize_text_field($_POST['PG'] ?? '');
  $rotationMethod = sanitize_text_field($_POST['rotationMethod'] ?? '');
  $isSuccess = false;
  if (isset(OPTIONKEYS[$PG])) {
    $keys = OPTIONKEYS[$PG];

    // 1. Save current legacy → old method tab storage
    $oldMethod = Shield_Option_Manager::get($keys['rotationMethod'], $keys['byTime']);
    shield_rotation_persist_legacy_to_tab($PG, $keys, $oldMethod);

    // 2. Read new method's tab storage DIRECTLY (bypass active-reads-legacy logic)
    $prefix          = shield_rotation_tab_storage_prefix($PG, $rotationMethod);
    $newProxies      = Shield_Option_Manager::get($prefix . 'PROXIES', null);
    $newUnused       = Shield_Option_Manager::get($prefix . 'UNUSED_PROXIES', []);
    $newPosition     = Shield_Option_Manager::get($prefix . 'POSITION_LIST', []);
    $newActivated    = Shield_Option_Manager::get($prefix . 'ACTIVATED_PROXY', null);
    $newCurrentRot   = Shield_Option_Manager::get($prefix . 'CURRENT_ROTATION', time());

    // 3. Switch active method
    $isSuccess = Shield_Option_Manager::update($keys['rotationMethod'], $rotationMethod, true);

    // 4. Push new method tab storage → legacy so runtime uses correct data.
    //    If new tab was never initialised, keep legacy as-is (bootstrap scenario).
    if ($newProxies !== null) {
      Shield_Option_Manager::update($keys['proxies'],       is_array($newProxies) ? $newProxies : [], true);
      Shield_Option_Manager::update($keys['unusedProxies'], is_array($newUnused)  ? $newUnused  : [], true);
      Shield_Option_Manager::update($keys['positionList'],  is_array($newPosition)? $newPosition: [], true);
      Shield_Option_Manager::update($keys['activatedProxy'],$newActivated, true);
      Shield_Option_Manager::update($keys['currentRotation'],$newCurrentRot, true);
    }
  }
  echo json_encode([
    'success' => $isSuccess
  ]);
}
function change_position()
{
  $positionList = array_map('intval', (array) ($_POST['positionList'] ?? []));
  $PG           = sanitize_text_field($_POST['PG'] ?? '');
  $rotationMethod = sanitize_text_field($_POST['rotationMethod'] ?? '');
  if (isset(OPTIONKEYS[$PG])) {
    $keys = OPTIONKEYS[$PG];
    $state = shield_rotation_get_tab_state($PG, $keys, $rotationMethod);
    $proxies = $state['proxies'];
    $proxies = sortByPosition($proxies, $positionList);

    $state['positionList'] = $positionList;
    $state['proxies'] = $proxies;
    shield_rotation_set_tab_state($PG, $rotationMethod, $state);
    shield_rotation_sync_active_tab_to_legacy($PG, $keys, $rotationMethod);

    echo json_encode([
      'success' => true,
      'success2' => true,
    ]);
  }
  else {
    echo json_encode(["success" => false]);
  }
}
function move_to_unused_proxies()
{
  $proxyId = sanitize_text_field($_POST['proxyId'] ?? '');
  $PG      = sanitize_text_field($_POST['PG'] ?? '');
  $rotationMethod = sanitize_text_field($_POST['rotationMethod'] ?? '');
  if (isset(OPTIONKEYS[$PG])) {
    $keys = OPTIONKEYS[$PG];
    $state = shield_rotation_get_tab_state($PG, $keys, $rotationMethod);
    $proxies = $state['proxies'];
    $unusedProxies = $state['unusedProxies'];
    $activatedProxy = $state['activatedProxy'];
    if (isset($activatedProxy) && $activatedProxy['id'] == $proxyId) {
      echo json_encode([
        "success" => false,
        "error" => "Can't move activated proxy to unused list!"
      ]);
      return;
    }
    foreach ($proxies as $key => $proxy) {
      if ($proxy['id'] == $proxyId) {
        $unusedProxies[] = $proxy;
        unset($proxies[$key]);
      }
    }

    $state['proxies'] = array_values($proxies);
    $state['unusedProxies'] = array_values($unusedProxies);
    shield_rotation_set_tab_state($PG, $rotationMethod, $state);
    shield_rotation_sync_active_tab_to_legacy($PG, $keys, $rotationMethod);

    echo json_encode([
      "success" => true,
      "proxyId" => $proxyId
    ]);
  }
  else {
    echo json_encode(["success" => false]);
  }
}


function move_back_proxy($id = null, $json = false)
{
  $proxyId = $id ?? sanitize_text_field($_POST['proxyId'] ?? '');
  $PG      = sanitize_text_field($_POST['PG'] ?? '');
  $rotationMethod = sanitize_text_field($_POST['rotationMethod'] ?? '');
  if (isset(OPTIONKEYS[$PG])) {
    $keys = OPTIONKEYS[$PG];
    $state = shield_rotation_get_tab_state($PG, $keys, $rotationMethod);
    $positionList = $state['positionList'];
    $proxies = $state['proxies'];
    $unusedProxies = $state['unusedProxies'];
    foreach ($unusedProxies as $key => $proxy) {
      if ($proxy['id'] == $proxyId) {
        $proxies[] = $proxy;
        unset($unusedProxies[$key]);
      }
    }
    $proxies = sortByPosition($proxies, $positionList);

    $state['proxies'] = array_values($proxies);
    $state['unusedProxies'] = array_values($unusedProxies);
    shield_rotation_set_tab_state($PG, $rotationMethod, $state);
    shield_rotation_sync_active_tab_to_legacy($PG, $keys, $rotationMethod);

    if ($json)
      return true;

    echo json_encode(["success" => true, "proxyId" => $proxyId]);
  }
  else {
    if ($json)
      return false;
    echo json_encode(["success" => false, "proxyId" => $proxyId]);
  }
}

function delete_proxy()
{
  $proxyId = sanitize_text_field($_POST['proxyId'] ?? '');
  $PG      = sanitize_text_field($_POST['PG'] ?? '');
  $rotationMethod = sanitize_text_field($_POST['rotationMethod'] ?? '');
  if (isset(OPTIONKEYS[$PG])) {
    $isSuccess = false;
    $keys = OPTIONKEYS[$PG];
    $state = shield_rotation_get_tab_state($PG, $keys, $rotationMethod);
    $unusedProxies = $state['unusedProxies'];
    $unusedKey = findPositionById($unusedProxies, $proxyId);
    if ($unusedKey >= 0) {
      unset($unusedProxies[$unusedKey]);
      $isSuccess = true;
    }
    else {
      $proxies = $state['proxies'];
      $proxyKey = findPositionById($proxies, $proxyId);
      if ($proxyKey >= 0) {
        unset($proxies[$proxyKey]);
        $isSuccess = true;
      }
    }

    if ($isSuccess) {
      $positionList = $state['positionList'];
      unset($positionList[$proxyId]);
      $state['positionList'] = $positionList;
      $state['proxies'] = isset($proxies) ? array_values($proxies) : $state['proxies'];
      $state['unusedProxies'] = array_values($unusedProxies);
      if (!empty($state['activatedProxy']) && (($state['activatedProxy']['id'] ?? '') === $proxyId)) {
        $state['activatedProxy'] = !empty($state['proxies']) ? $state['proxies'][0] : null;
      }
      shield_rotation_set_tab_state($PG, $rotationMethod, $state);
      shield_rotation_sync_active_tab_to_legacy($PG, $keys, $rotationMethod);
    }

    echo json_encode(["success" => $isSuccess]);
  }
  else {
    echo json_encode(["success" => false, "proxyId" => $proxyId]);
  }
}
function activate_proxy()
{
  $rotationMethod = sanitize_text_field($_POST['rotationMethod'] ?? '');
  $proxyId        = sanitize_text_field($_POST['proxyId'] ?? '');
  $PG             = sanitize_text_field($_POST['PG'] ?? '');
  if (isset(OPTIONKEYS[$PG])) {
    $keys = OPTIONKEYS[$PG];
    $state = shield_rotation_get_tab_state($PG, $keys, $rotationMethod);
    $unusedProxies = $state['unusedProxies'];
    $unusedKey = findPositionById($unusedProxies, $proxyId);
    if ($unusedKey >= 0)
      move_back_proxy($proxyId, true);
    $state = shield_rotation_get_tab_state($PG, $keys, $rotationMethod);
    $proxies = $state['proxies'];
    foreach ($proxies as $proxy) {
      if ($proxy["id"] == $proxyId) {
        $state['activatedProxy'] = $proxy;
        if ($rotationMethod === $keys['byTime']) {
          $state['currentRotation'] = time();
        }
        shield_rotation_set_tab_state($PG, $rotationMethod, $state);
        shield_rotation_sync_active_tab_to_legacy($PG, $keys, $rotationMethod);
        // logRotation($rotationMethod, $proxy, "Force");
        echo json_encode([
          'success' => true,
          'id' => $proxyId
        ]);
        return;
      }
    }
  }
  else {
    echo json_encode([
      'success' => false
    ]);
  }
}
