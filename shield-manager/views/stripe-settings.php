<?php
$stripe_settings = get_option('woocommerce_WOOTIFY_stripe_settings', []);
if (empty($stripe_settings)) {
  $stripe_settings = get_option('woocommerce_stripe_settings', []);
}

$form_fields = array(

  'title' => array(
    'title' => 'Title',
    'type' => 'text',
    'description' => '--------------------------------------------------------------',
    'default' => 'Card',
    'desc_tip' => false,
  ),
  'intent' => [
    'title' => 'Payment Intent',
    'type' => 'select',
    'class' => [],
    'input_class' => ['wc-enhanced-select'],
    'default' => 'capture',
    'desc_tip' => true,
    'description' => 'The intent to either capture payment immediately or authorize a payment for an order after order creation.',
    'options' => [
      OPT_WOOTIFY_STRIPE_INTENT_CAPTURE => 'Capture',
      OPT_WOOTIFY_STRIPE_INTENT_AUTHORIZE => 'Authorize',
    ],
  ],
  'payment_notes' => array(
    'title' => 'Payment notes',
    'type' => 'textarea',
    'description' => __('Payment notes are limited to 100 characters, cannot use the special characters.', 'woocommerce-gateway-stripe'),
    'default' => '',
    'desc_tip' => true,
  ),
  'statement_descriptor' => array(
    'title' => 'Statement Descriptor',
    'type' => 'text',
    'description' => __('Statement descriptors are limited to 22 characters, cannot use the special characters >, <, ", \, \', *, and must not consist solely of numbers. This will appear on your customer\'s statement in capital letters.', 'woocommerce-gateway-stripe'),
    'default' => '',
    'desc_tip' => true,
    'required' => true,
  ),
  'invoice_prefix' => array(
    'title' => __('Invoice Prefix', 'woocommerce-gateway-stripe-express-checkout'),
    'type' => 'text',
    'description' => __('Please enter a prefix for your invoice numbers.', 'woocommerce-gateway-stripe-express-checkout'),
    'default' => 'WC-',
    'desc_tip' => true,
    'required' => true,
  ),
  OPT_WOOTIFY_STRIPE_LINK_EXPRESS_ENABLED => array(
    'title' => 'Enable Stripe Link Express Checkout',
    'label' => 'Show Pay with Link before the checkout form when Stripe reports Link is available.',
    'type' => 'checkbox',
    'description' => 'Only Link is enabled in v1. Apple Pay and Google Pay remain disabled.',
    'default' => 'no',
    'desc_tip' => false,
  ),
  'card_icons' => array(
    'type' => 'multiselect',
    'title' => 'Accepted Payment Methods',
    'class' => 'wc-enhanced-select',
    'default' => array('visa', 'mastercard', 'american_express', 'discover', 'diners', 'jcb'),
    'options' => array(
      'visa' => 'Visa',
      'paypal' => 'Paypal',
      'mastercard' => 'MasterCard',
      'jcb' => 'JCB',
      'discover' => 'Discover',
      'diners' => 'Diners Club',
      'american_express' => 'American Express',
    ),
    'desc_tip' => true,
    'description' => 'The selected icons will show customers which credit card brands you accept.',
  ),
  'custom_card_icon_css' => [
    'title' => 'Custom Stripe icon css',
    'type' => 'textarea',
    'default' => '',
    'css' => 'width: 400px; min-height: 110px; resize: both;',
  ]
);

$groups = [
  'general' => [
    'title'  => 'General',
    'fields' => ['title', 'statement_descriptor', 'invoice_prefix', 'payment_notes'],
  ],
  'payment' => [
    'title'  => 'Payment Options',
    'fields' => ['intent', OPT_WOOTIFY_STRIPE_LINK_EXPRESS_ENABLED],
  ],
  'appearance' => [
    'title'  => 'Appearance',
    'fields' => ['card_icons', 'custom_card_icon_css'],
  ],
];
?>

<form id="stripe_settings">
<?php foreach ($groups as $group_key => $group) :
  $group_fields = array_intersect_key($form_fields, array_flip($group['fields']));
  $group_fields = array_replace(array_flip($group['fields']), $group_fields);
?>
  <div class="sp-card">
    <div class="sp-card-header"><?= esc_html($group['title']) ?></div>
    <div class="sp-card-body">
      <?php render_form($group_fields, $stripe_settings, 'stripe') ?>
    </div>
  </div>
<?php endforeach; ?>

  <div class="sp-save-bar">
    <button type="submit" class="sp-btn-save">Save Settings</button>
  </div>
</form>
