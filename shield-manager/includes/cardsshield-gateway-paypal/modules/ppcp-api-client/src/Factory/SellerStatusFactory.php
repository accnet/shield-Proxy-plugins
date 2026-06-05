<?php
/**
 * Factory for the SellerStatus object.
 *
 * @package WooCommerce\WootifyPaypal\ApiClient\Factory
 */

declare( strict_types=1 );

namespace WooCommerce\WootifyPaypal\ApiClient\Factory;

use WooCommerce\WootifyPaypal\ApiClient\Entity\SellerStatus;
use WooCommerce\WootifyPaypal\ApiClient\Entity\SellerStatusProduct;

/**
 * Class SellerStatusFactory
 */
class SellerStatusFactory {

	/**
	 * Creates a SellerStatus Object out of a PayPal response.
	 *
	 * @param \stdClass $json The response object.
	 *
	 * @return SellerStatus
	 */
	public function from_paypal_reponse( \stdClass $json ) : SellerStatus {
		$products = array_map(
			function( $json ) : SellerStatusProduct {
				$product = new SellerStatusProduct(
					isset( $json->name ) ? (string) $json->name : '',
					isset( $json->vetting_status ) ? (string) $json->vetting_status : '',
					isset( $json->capabilities ) ? (array) $json->capabilities : array()
				);
				return $product;
			},
			isset( $json->products ) ? (array) $json->products : array()
		);

		return new SellerStatus( $products );
	}
}
