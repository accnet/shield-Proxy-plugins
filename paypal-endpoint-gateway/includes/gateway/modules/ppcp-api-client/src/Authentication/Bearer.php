<?php
/**
 * The bearer interface.
 *
 * @package EP_PayPal\ApiClient\Authentication
 */

declare(strict_types=1);

namespace EP_PayPal\ApiClient\Authentication;

use EP_PayPal\ApiClient\Entity\Token;

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
