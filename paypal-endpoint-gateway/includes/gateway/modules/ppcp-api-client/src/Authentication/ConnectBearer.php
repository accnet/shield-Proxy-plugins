<?php
/**
 * The connect dummy bearer.
 *
 * @package EP_PayPal\ApiClient\Authentication
 */

declare(strict_types=1);

namespace EP_PayPal\ApiClient\Authentication;

use EP_PayPal\ApiClient\Entity\Token;

/**
 * Class ConnectBearer
 */
class ConnectBearer implements Bearer {

	/**
	 * Returns the bearer.
	 *
	 * @return Token
	 */
	public function bearer(): Token {
		$data = (object) array(
			'created'    => time(),
			'expires_in' => 3600,
			'token'      => 'token',
		);
		return new Token( $data );
	}
}
