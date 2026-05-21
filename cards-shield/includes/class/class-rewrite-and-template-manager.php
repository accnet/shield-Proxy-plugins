<?php
if (!defined('ABSPATH')) {
    exit;
}

class RewriteAndTemplateManager {
    private $query_vars;

    public function __construct() {
        $this->query_vars = array(
            'paypal_checkout' => '/paypal/paypal_checkout.php',
            'wootify-paypal-capture-order' => '/paypal/wootify-paypal-capture-order.php',
            'wootify-pp-refund' => '/paypal/wootify-pp-refund.php',
            'wootify-paypal-get-order' => '/paypal/wootify-paypal-get-order.php',
            'wootify-paypal-sync-tracking' => '/paypal/wootify-paypal-sync-tracking.php',
            'wootify-stripe-pe-get-payment-form' => '/stripe/wootify-stripe-pe-get-payment-form.php',
            'wootify-stripe-pe-make-payment' => '/stripe/wootify-stripe-pe-make-payment.php',
            'wootify-stripe-pe-get-payment-confirm-form' => '/stripe/wootify-stripe-pe-get-payment-confirm-form.php',
            'wootify-stripe-pe-confirm-payment' => '/stripe/wootify-stripe-pe-confirm-payment.php',
            'wootify-stripe-pe-refund' => '/stripe/wootify-stripe-pe-refund.php',
            'wootify_stripe_return_result' => '/stripe/wootify_stripe_return_result.php',
            'wootify_stripe_return_3d_success' => '/stripe/wootify_stripe_return_3d_success.php',
            'wootify-stripe-pe-get-account-charge-status' => '/stripe/wootify-stripe-pe-get-account-charge-status.php',
        );
    }
    public function init() {
        add_filter('template_include', array($this, 'include_template'));
        add_filter('init', array($this, 'rewrite_rules'));
    }

    public function include_template($template) {

        foreach ($this->query_vars as $query_var => $file) {
            if (get_query_var($query_var)) {
                return CARDSSHIELD_PLUGIN_PUBLIC_DIR . $file;
            }
        }
        return $template;
    }

    public function flush_rules() {
        $this->rewrite_rules();
        flush_rewrite_rules();
    }

    public function rewrite_rules() {
        foreach ($this->query_vars as $query_var => $file) {
            $rule = '\?' . $query_var . '=(.+?)$';
            $rewrite = 'index.php?' . $query_var . '=$matches[1]';
            $tag = '%' . $query_var . '%';
            add_rewrite_rule($rule, $rewrite, 'top');
            add_rewrite_tag($tag, '([^&]+)');
        }
    }
}
$RewriteAndTemplateManager = new RewriteAndTemplateManager();
$RewriteAndTemplateManager->init();
register_activation_hook(CARDSSHIELD_PLUGIN, array($RewriteAndTemplateManager, 'flush_rules'));
