<?php
/**
 * The modules Not Found exception.
 *
 * @package EP_PayPal\ApiClient\Exception
 */

declare(strict_types=1);

namespace EP_PayPal\ApiClient\Exception;

use Psr\Container\NotFoundExceptionInterface;
use Exception;

/**
 * Class NotFoundException
 */
class NotFoundException extends Exception implements NotFoundExceptionInterface {


}
