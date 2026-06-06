<?php
/**
 * The api client module.
 *
 * @package EP_PayPal\ApiClient
 */

declare(strict_types=1);

namespace EP_PayPal\ApiClient;

use Dhii\Modular\Module\ModuleInterface;

return function (): ModuleInterface {
	return new ApiModule();
};
