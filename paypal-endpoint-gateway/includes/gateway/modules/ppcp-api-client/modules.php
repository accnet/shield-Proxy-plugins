<?php
/**
 * The api client module.
 *
 * @package WooCommerce\WootifyPaypal\ApiClient
 */

declare(strict_types=1);

namespace WooCommerce\WootifyPaypal\ApiClient;

use Dhii\Modular\Module\ModuleInterface;

return function (): ModuleInterface {
	return new ApiModule();
};
