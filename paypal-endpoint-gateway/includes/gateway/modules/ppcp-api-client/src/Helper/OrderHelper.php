<?php
/**
 * PayPal order helper.
 *
 * @package EP_PayPal\ApiClient\Helper
 */

declare(strict_types=1);

namespace EP_PayPal\ApiClient\Helper;

use EP_PayPal\ApiClient\Entity\Order;

/**
 * Class OrderHelper
 */
class OrderHelper {

	/**
	 * Checks if order contains physical goods.
	 *
	 * @param Order $order PayPal order.
	 * @return bool
	 */
	public function contains_physical_goods( Order $order ): bool {
		foreach ( $order->purchase_units() as $unit ) {
			if ( $unit->contains_physical_goods() ) {
				return true;
			}
		}

		return false;
	}
}
