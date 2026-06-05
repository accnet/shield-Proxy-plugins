<?php
$shield_data = get_option('shield_data');
$shield_license_key = get_option('shield_license_key', '');
define("SHIELD_LICENSE_KEY", get_option('shield_license_key', ''));
$url = home_url();
if (isset($shield_data['suffixes'])) {
  $url .= '/' . $shield_data['suffixes'];
}
define("SHIELD_URL", $url);
