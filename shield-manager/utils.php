<?php
// Constants are defined in includes/constants.php (loaded before this file).




function isEnabledOrderRotation($PG) {
  if (isset(OPTIONKEYS[$PG])) {
    $keys = OPTIONKEYS[$PG];
    return $keys['byOrder'] === get_option($keys['rotationMethod'], $keys['byTime']);
  }
}

function updateRotationOrder($proxyId, $PG) {
  if (isset(OPTIONKEYS[$PG])) {
    $keys = OPTIONKEYS[$PG];
    $activatedProxy = get_option($keys['activatedProxy'], null);
    if ($activatedProxy['id'] == $proxyId) {
      $activatedProxy['order_count']++;
      update_option($keys['activatedProxy'], $activatedProxy, true);
    }
    $proxies = get_option($keys['proxies'], []);
    foreach ($proxies as $key => $proxy) {
      if ($proxy['id'] === $proxyId) {
        $proxies[$key]['order_count']++;
        break;
      }
    }
    update_option($keys['proxies'], $proxies, true);
    
    if (class_exists('Shield_SaaS_Client')) {
        Shield_SaaS_Client::sync_stats_to_saas($PG);
    }
    
    return  $activatedProxy;
  }
}



function resetOrderCountIfNeed($PG) {
  if (isset(OPTIONKEYS[$PG])) {
    $keys = OPTIONKEYS[$PG];
    $lastTimeReset = get_option($keys['lastTimeResetOrderCount'], null);
    $proxies = get_option($keys['proxies'], []);
    if (empty($proxies)) {
      return [];
    }
    // Reset
    if (empty($lastTimeReset) || date('Y-m-d') > $lastTimeReset) {
      return resetOrderCount($proxies, $PG);
    }
    return $proxies;
  }
}


function resetOrderCount($proxies, $PG) {
  if (isset(OPTIONKEYS[$PG])) {
    $keys = OPTIONKEYS[$PG];
    if (empty($proxies)) {
      $proxies = get_option($keys['proxies'], []);
    }

    // Reset
    $newProxies = array_map(function ($proxy) {
      $proxy['order_count'] = 0;
      return $proxy;
    }, $proxies);

    $unusedProxies = get_option($keys['unusedProxies'], []);
    if (empty($unusedProxies)) {
      $newUnusedProxies = [];
    } else {
      $newUnusedProxies = array_map(function ($unusedProxy) {
        $unusedProxy['order_count'] = 0;
        return $unusedProxy;
      }, $unusedProxies);
    }
    update_option($keys['proxies'], $newProxies, true);
    update_option($keys['unusedProxies'], $newUnusedProxies, true);
    update_option($keys['lastTimeResetOrderCount'], date('Y-m-d'), true);
    update_option($keys['activatedProxy'], reset($newProxies), true);
    return $newProxies;
  }
}

function performProxyOrderRotation($activatedProxy, $PG) {

  if (isset(OPTIONKEYS[$PG])) {
    $keys = OPTIONKEYS[$PG];
    $proxies = resetOrderCountIfNeed($PG);
    if (empty($proxies)) {
      return null;
    }
    if (empty($activatedProxy)) {
      $activatedProxy = get_option($keys['activatedProxy'], reset($proxies));
    }
    if (!empty($activatedProxy) && $activatedProxy['order_count'] + 1 < $activatedProxy['order']) {
      return $activatedProxy;
    }
    $isCurrentProxyMatched = false;
    foreach ($proxies as $proxy) {
      if ($activatedProxy['id'] == $proxy['id']  && !$isCurrentProxyMatched) {
        $isCurrentProxyMatched = true;
        continue;
      }
      if ($isCurrentProxyMatched && $proxy['order_count'] < $proxy['order']) {
        $activatedProxy = $proxy;
        update_option($keys['activatedProxy'], $activatedProxy, true);
        // logRotation(OPT_CS_PAYPAL_BY_AMOUNT, $activatedProxy, "Auto");

        return $activatedProxy;
      }
    }
    foreach ($proxies as $proxy) {
      if ($proxy['order_count'] < $proxy['order']) {
        $activatedProxy = $proxy;
        update_option($keys['activatedProxy'], $activatedProxy, true);
        // logRotation(OPT_CS_PAYPAL_BY_AMOUNT, $activatedProxy, "Auto");
        return $activatedProxy;
      }
    }
    $proxies = resetOrderCount([], $PG);
    if (!empty($proxies)) {
      $activatedProxy = $proxies[0];
      update_option($keys['activatedProxy'], $activatedProxy, true);
      return $activatedProxy;
    }
  }
  return $activatedProxy;
}




function getNextProxyOrderRotation($activatedProxy, $PG) {
  if (isset(OPTIONKEYS[$PG])) {
    $keys = OPTIONKEYS[$PG];
    $proxies = resetOrderCountIfNeed($PG);
    if (empty($proxies)) {
      return null;
    }
    if (empty($activatedProxy)) {
      $activatedProxy = get_option($keys['activatedProxy'], reset($proxies));
    }
    if (!empty($activatedProxy) && $activatedProxy['order_count'] < $activatedProxy['order']) {
      return $activatedProxy;
    }
    $isCurrentProxyMatched = false;
    foreach ($proxies as $proxy) {
      if ($activatedProxy['id'] == $proxy['id'] && !$isCurrentProxyMatched) {
        $isCurrentProxyMatched = true;
        continue;
      }
      if ($isCurrentProxyMatched && $proxy['order_count'] < $proxy['order']) {
        return $proxy;
      }
    }
    foreach ($proxies as $proxy) {
      if ($proxy['order_count'] < $proxy['order']) {
        return $proxy;
      }
    }
    $proxies = resetOrderCount([], $PG);
    if (!empty($proxies)) {
      return $proxies[0];
    }
  }
  return $activatedProxy;
}

function findPositionById($array, $id) {
  foreach ($array as $key => $element) {
    if (isset($element['id']) && $element['id'] === $id) {
      return $key;
    }
  }
  return -1;
}

function shield_proxy_find_site_for_proxy($proxyOrUrl) {
  if (!class_exists('Shield_Site_Registry')) {
    return null;
  }

  $url = is_array($proxyOrUrl) ? ($proxyOrUrl['url'] ?? '') : (string) $proxyOrUrl;
  $site_id = is_array($proxyOrUrl) ? ($proxyOrUrl['site_id'] ?? '') : '';

  if (!empty($site_id)) {
    $site = Shield_Site_Registry::find($site_id);
    if (!empty($site)) {
      return $site;
    }
  }

  $needle = rtrim(trailingslashit(esc_url_raw($url)), '/');
  foreach (Shield_Site_Registry::all() as $site) {
    if (rtrim((string) ($site['url'] ?? ''), '/') === $needle) {
      return $site;
    }
  }

  return null;
}

function shield_proxy_signed_request_args($proxyOrUrl, $method, $url, $args = [], $bodyRaw = '') {
  if (!class_exists('Shield_API_Client') || !method_exists('Shield_API_Client', 'build_signed_headers_for_url')) {
    return $args;
  }

  $site = shield_proxy_find_site_for_proxy($proxyOrUrl);
  if (empty($site)) {
    return $args;
  }

  $headers = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : [];
  $signed = Shield_API_Client::build_signed_headers_for_url($site, (string)$method, (string)$url, (string)$bodyRaw);
  $args['headers'] = array_merge($headers, $signed);
  return $args;
}

function getRotationMethod($PG) {
  $rotationMethod = null;
  if (isset(OPTIONKEYS[$PG])) {
    $keys = OPTIONKEYS[$PG];
    $rotationMethod = get_option($keys['rotationMethod'], false);
    if (empty($rotationMethod)) {
      $rotationMethod = $keys['byTime'];
      update_option($keys['rotationMethod'], $rotationMethod, true);
    }
  }
  return $rotationMethod;
}


function getPositionById($id, $proxiesPosition) {
  return $proxiesPosition[$id] ?? PHP_INT_MAX;
}

function processProxyList($PG) {
  $proxies = [];
  $unusedProxies = [];
  $activatedProxy = null;
  $positionList = [];

  if (isset(OPTIONKEYS[$PG])) {
    $keys = OPTIONKEYS[$PG];
    $proxies = get_option($keys['proxies'], []);
    $unusedProxies = get_option($keys['unusedProxies'], []);
    $activatedProxy = get_option($keys['activatedProxy'], null);
    $positionList =  get_option($keys['positionList'], []);
  }

  $unusedProxies = array_map(function ($proxy) {
    $proxy['is_off'] = true;
    return $proxy;
  }, $unusedProxies);

  $proxies = array_map(function ($proxy) use ($activatedProxy) {
    if (isset($activatedProxy['id']) && $activatedProxy['id'] == $proxy['id'])
      $proxy['active'] = true;
    return $proxy;
  }, $proxies);

  $proxyList = array_merge($proxies, $unusedProxies);
  $proxyList = sortByPosition($proxyList, $positionList);

  // Enrich each proxy with site registry info (label, status).
  $sites = class_exists('Shield_Site_Registry') ? Shield_Site_Registry::all() : [];
  $site_map = [];
  foreach ($sites as $site) {
    $site_map[$site['id']] = $site;
  }

  $proxyList = array_map(function ($proxy) use ($site_map) {
    $site_id = $proxy['site_id'] ?? '';
    if ($site_id && isset($site_map[$site_id])) {
      $proxy['site_label']  = $site_map[$site_id]['label'];
      $proxy['site_status'] = $site_map[$site_id]['status'];
    } else {
      $proxy['site_label']  = '';
      $proxy['site_status'] = 'unknown';
    }
    return $proxy;
  }, $proxyList);

  return $proxyList;
}
function sortByPosition($proxyList, $positionList) {
  usort($proxyList, function ($a, $b) use ($positionList) {
    return getPositionById($a['id'], $positionList) - getPositionById($b['id'], $positionList);
  });

  return $proxyList;
}
function render_form($form_fields, $settings, $gateway = "paypal") {
  foreach ($form_fields as $key => $field) {
    $id = $gateway . '_' . $key;
?>

    <div class="row mb-4">
      <label for="<?= $id ?>" class="col-sm-3"><?= $field['title'] ?></label>
      <div class="col-sm-9">
        <?php
        switch ($field['type']) {
          case 'checkbox': ?>
            <label for="<?= $id ?>"> <input class="" type="checkbox" name="<?= $key ?>" id="<?= $id ?>" value="yes" <?php checked($settings[$key] ?? $field['default'], 'yes') ?>> <?= $field["label"] ?></label>
          <?php
            break;
          case 'text': ?>
            <input class="form-control" type="text" name="<?= $key ?>" id="<?= $id ?>" value="<?= $settings[$key] ?? $field['default'] ?>">
            <p class="description">
              <?= $field['description'] ?? '' ?>
            <p>
            <?php
            break;
          case 'textarea': ?>
              <textarea rows="4" cols="20" class="form-control" type="textarea" name="<?= $key ?>" id="<?= $id ?>" placeholder=""><?= $settings[$key] ?? $field['default'] ?></textarea>
            <p class="description">
              <?= $field['description'] ?? '' ?>
            <p>
            <?php
            break;
          case 'select': ?>
              <select name="<?= $key ?>" id="<?= $id ?>" class="form-select">
                <?php foreach ($field['options'] as $value => $label) : ?>
                  <option value="<?= $value ?>" <?php selected($settings[$key] ?? $field['default'], $value); ?>>
                    <?= $label ?></option>
                <?php endforeach ?>
              </select>
            <p class=" description">
              <?= $field['description'] ?? '' ?>
            <p>
            <?php
            break;
          case 'multiselect': ?>

              <select multiple="multiple" name="<?= $key ?>" id="<?= $id ?>" class="form-control multiselect">
                <?php foreach ($field['options'] as $value => $label) : ?>
                  <option value="<?= $value ?>" <?php
                                                selected(in_array($value, (array) ($settings[$key] ?? $field['default'])), true); ?>>
                    <?= $label ?></option>
                <?php endforeach ?>
              </select>
            <p class=" description">
              <?= $field['description'] ?? '' ?>
            <p>
          <?php
            break;
        }
          ?>

      </div>
    </div>



<?php
  }
}


