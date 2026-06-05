<?php
/**
 * The bearer interface.
 *
 * @package WooCommerce\WootifyPaypal\ApiClient\Authentication
 */

declare(strict_types=1);

namespace WooCommerce\WootifyPaypal\ApiClient\Authentication;

use WooCommerce\WootifyPaypal\ApiClient\Entity\Token;

/**
 * Interface Bearer
 */
interface Bearer {

	/**
	 * Returns the bearer.
	 *
	 * @return Token
	 */
	public function bearer(): Token;
}
