<?php
$paypal_settings = get_option('woocommerce_WOOTIFY_paypal_settings', []);
if (empty($paypal_settings)) {
	$paypal_settings = get_option('woocommerce_wootify_paypal_settings', []);
}
if (empty($paypal_settings)) {
	$paypal_settings = get_option('woocommerce_paypal_settings', []);
}

$form_fields = [
	'paypal_button' => [
		'title'       => 'Payment Button',
		'type'        => 'select',
		'description' => "<b>PayPal Standard:</b> PayPal Standard are static buttons with limited customization options.<br/> <b>Smart Button:</b> Smart Button provides different ways to customize the PayPal checkout button. Accepts alternative payment methods such as PayPal Credit, Venmo, and local funding sources.",
		'default'     => OPT_CS_PAYPAL_SETTING_CHECKOUT,
		'options'     => [
			OPT_CS_PAYPAL_SETTING_CHECKOUT => 'Smart Button',
			OPT_CS_PAYPAL_SETTING_STANDARD   => 'Paypal Standard',
		],
	],
	'enabled_express_on_product_page'    => [
		'title'       => 'Express Checkout on product page',
		'label'       => 'Enable',
		'type'        => 'checkbox',
		'description' => '',
		'default'     => 'no'
	],
	'enabled_express_on_cart_page'    => [
		'title'       => 'Express Checkout on cart page',
		'label'       => 'Enable',
		'type'        => 'checkbox',
		'description' => '',
		'default'     => 'no'
	],
	'enabled_express_on_checkout_page'    => [
		'title'       => 'Express Checkout on checkout page',
		'label'       => 'Enable',
		'type'        => 'checkbox',
		'description' => '',
		'default'     => 'no'
	],
	'title'                     => [
		'title'       => 'Title',
		'type'        => 'text',
		'description' => 'This controls the title which the user sees during checkout.',
		'default'     => 'PayPal',
		'desc_tip'    => true,
	],
	'description'               => [
		'title'       => 'Description',
		'type'        => 'textarea',
		'description' => 'This controls the description which the user sees during checkout.',
		'default'     => '',
		'css'      => 'width: 400px;resize: both;',
	],
	'invoice_prefix'            => [
		'title'       => __( 'Invoice Prefix', 'woocommerce-gateway-paypal-express-checkout' ),
		'type'        => 'text',
		'description' => __( 'Please enter a prefix for your invoice numbers. If you use your PayPal account for multiple stores ensure this prefix is unique as PayPal will not allow orders with the same invoice number.', 'woocommerce-gateway-paypal-express-checkout' ),
		'default'     => 'WC-',
		'desc_tip'    => true,
	],
	'product_title_setting'     => [
		'title'       => 'Overwrite product title',
		'type'        => 'select',
		'description' => '',
		'default'     => 'last_word',
		'desc_tip'    => false,
		'options'     => [
			'last_word'     => 'Use the last word',
			'user_define'   => 'User define',
			'keep_original' => 'Keep the original (Not recommended)'
		]
	],
	'user_define_product_title' => [
		'title'       => 'User define title',
		'type'        => 'text',
		'description' => '<br><b>[order_id]</b> : Id đơn hàng
    <br><b>[variants]</b> : Biến thể sản phẩm
    <br><b>[str:9]</b> : Chuỗi ngẫu nhiêu
    <br><b>[random:S|M|L|XL]</b> : ngẫu nhiên trong danh sách
    <br><b>[by_price:20-30=Tshirt|31-50=Hoodie|51-80=Sweatshirt]</b> : Hiển thị theo giá
    <br><b>[last_word]</b> : Từ cuối của tiêu đề',
		'default'     => '[order_id] [str:9] item',
	],
	'config_proxies_button'     => [
		'id'    => 'config_proxies_button',
		'type'  => 'config_proxies_button',
		'title' => __( 'Config Shields', 'custom_paypal' ),
	],
	'intent'                    => [
		'title'       => 'Payment Intent',
		'type'        => 'select',
		'class'       => [],
		'input_class' => [ 'wc-enhanced-select' ],
		'default'     => 'capture',
		'desc_tip'    => true,
		'description' => 'The intent to either capture payment immediately or authorize a payment for an order after order creation.',
		'options'     => [
			OPT_CS_PAYPAL_CAPTURE   => 'Capture',
			OPT_CS_PAYPAL_AUTHORIZE => 'Authorize',
		],
	],
	'sync_tracking_plugin' => [
		'title'       => 'Sync tracking plugin',
		'type'        => 'select',
		'class'       => [],
		'input_class' => [ 'wc-enhanced-select' ],
		'default'     => OPT_CS_TRACKING_SYNC_PLUGIN_ADVANCED_SHIPMENT_TRACKING,
		'desc_tip'    => true,
		'description' => '',
		'options'     => [
			OPT_CS_TRACKING_SYNC_PLUGIN_ADVANCED_SHIPMENT_TRACKING => '1. Advanced Shipment Tracking for WooCommerce',
			OPT_CS_TRACKING_SYNC_PLUGIN_ORDERS_TRACKING            => '2. Orders Tracking for WooCommerce',
			OPT_CS_TRACKING_SYNC_PLUGIN_DIANXIAOMI                 => '3. Dianxiaomi - WooCommerce ERP',
		],
	],
	'sync_tracking_automatic' => [
		'title'       => 'Sync tracking automatically',
		'label'       => 'Enable',
		'type'        => 'checkbox',
		'description' => '',
		'default'     => 'no'
	],
	'not_send_bill_address_to_paypal' => [
		'title'       => 'Do not send billing & shipping address to PayPal',
		'label'       => 'Check this if you are selling DIGITAL products',
		'type'        => 'checkbox',
		'description' => '',
		'default'     => 'no'
	],
	'disable_credit_card' => array(
		'title'             => 'Disable Credit Card for Checkout', 
		'type'              => 'checkbox',
		'label'             => ' Yes',
		'default'           => 'no',
	),
	'disable_credit_card_express' => array(
		'title'             => 'Disable Credit Card for Express Checkout (Product, Cart, Checkout page)',
		'type'              => 'checkbox',
		'label'             => ' Yes',
		'default'           => 'no',
	),
	'disable_credit_card_express_on_product_page' => array(
		'title'             => 'Disable Credit Card for Express Checkout (Product page only)',
		'type'              => 'checkbox',
		'label'             => ' Yes',
		'default'           => 'no',
	),
	'transaction_logs_enable' => [
		'title'       => 'Transaction logs',
		'label'       => 'Enable',
		'type'        => 'checkbox',
		'description' => '',
		'default'     => 'no'
	],
	'send_email_notice_to_admin' => [
		'title'       => 'Send email notification to admins',
		'label'       => 'Enable',
		'type'        => 'checkbox',
		'description' => '',
		'default'     => 'yes'
	],
	'card_icons' => array(
		'type' => 'multiselect',
		'title' => 'Accepted Payment Methods',
		'class' => 'wc-enhanced-select',
		'default' => array('paypal', 'visa', 'mastercard', 'american_express', 'discover', 'diners', 'jcb'),
		'options' => array(
			'visa' => 'Visa',
			'paypal' => 'Paypal',
			'mastercard' => 'MasterCard',
			'jcb' => 'JCB',
			'discover' => 'Discover',
			'diners' => 'Diners Club',
			'american_express' => 'American Express',
		),
		'desc_tip'    => true,
		'description' => 'The selected icons will show customers which credit card brands you accept.',
	),
	'custom_card_icon_css' => [
		'title'   => 'Custom Paypal icon css',
		'type'    => 'textarea',
		'default' => '/*
.wootify-paypal-payment-icon {
    width: 50px;
}
*/',
		'css' => 'width: 400px; min-height: 110px; resize: both;',
	]
];

$countOrderNeedSync = countOrderNeedSync();

// Group definitions
$groups = [
	'express' => [
		'title'  => 'Express Checkout',
		'fields' => ['paypal_button', 'enabled_express_on_product_page', 'enabled_express_on_cart_page', 'enabled_express_on_checkout_page'],
	],
	'general' => [
		'title'  => 'General',
		'fields' => ['title', 'description', 'invoice_prefix'],
	],
	'product_title' => [
		'title'  => 'Product Title',
		'fields' => ['product_title_setting', 'user_define_product_title'],
	],
	'payment' => [
		'title'  => 'Payment Options',
		'fields' => ['intent', 'disable_credit_card', 'disable_credit_card_express', 'disable_credit_card_express_on_product_page', 'not_send_bill_address_to_paypal'],
	],
	'tracking' => [
		'title'  => 'Tracking & Notifications',
		'fields' => ['sync_tracking_plugin', 'sync_tracking_automatic', 'transaction_logs_enable', 'send_email_notice_to_admin'],
	],
	'appearance' => [
		'title'  => 'Appearance',
		'fields' => ['card_icons', 'custom_card_icon_css'],
	],
];
?>

<!-- Sync tracking card -->
<div class="sp-card">
	<div class="sp-card-header">Sync Tracking Info</div>
	<div class="sp-card-body">
		<div class="sp-sync-row">
			<button type="button" id="sync-tracking-info-btn" class="sp-btn-sync">
				<span id="sync-spinner" class="spinner-border spinner-border-sm" role="status" aria-hidden="true" style="display:none;"></span>
				Sync tracking info
			</button>
			<span class="sp-unsynced">Unsynced orders: <strong><?= $countOrderNeedSync ?></strong></span>
			<input id="sync-count" type="hidden" value="<?= $countOrderNeedSync ?>" />
		</div>
	</div>
</div>

<form id="paypal_settings">
<?php foreach ($groups as $group_key => $group) :
	$group_fields = array_intersect_key($form_fields, array_flip($group['fields']));
	$group_fields = array_replace(array_flip($group['fields']), $group_fields); // preserve order
?>
	<div class="sp-card">
		<div class="sp-card-header"><?= esc_html($group['title']) ?></div>
		<div class="sp-card-body">
			<?php render_form($group_fields, $paypal_settings) ?>
		</div>
	</div>
<?php endforeach; ?>

	<div class="sp-save-bar">
		<button type="submit" class="sp-btn-save">Save Settings</button>
	</div>
</form>
