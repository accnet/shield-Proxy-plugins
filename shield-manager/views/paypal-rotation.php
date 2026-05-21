<?php
$PG = "PayPal";
$key = OPTIONKEYS[$PG];

$rotationMethod = getRotationMethod($PG);
$currency  = get_woocommerce_currency();
$proxyList =  processProxyList($PG);
?>


<div class="cs-wrap">
  <h1>PayPal Rotation</h1>

  <?php include __DIR__ . '/rotation.php'; ?>
</div>
<div id="toast"></div>