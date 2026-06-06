<?php

declare(strict_types=1);

namespace EP_PayPal\ApiClient\Compat;

use Psr\Container\ContainerInterface;

class Settings implements ContainerInterface {

	private $settings;

	public function __construct() {
		$settings       = get_option( 'woocommerce_endpoint_paypal_settings', array() );
		$this->settings = is_array( $settings ) ? $settings : array();
	}

	public function get( $id ) {
		return $this->settings[ $id ] ?? null;
	}

	public function has( $id ): bool {
		return array_key_exists( $id, $this->settings );
	}
}
