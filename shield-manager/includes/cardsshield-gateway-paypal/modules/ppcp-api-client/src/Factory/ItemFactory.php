<?php

/**
 * The Item factory.
 *
 * @package WooCommerce\WootifyPaypal\ApiClient\Factory
 */

declare(strict_types=1);

namespace WooCommerce\WootifyPaypal\ApiClient\Factory;

use WC_Product;
use WooCommerce\WootifyPaypal\ApiClient\Entity\Item;
use WooCommerce\WootifyPaypal\ApiClient\Entity\Money;
use WooCommerce\WootifyPaypal\ApiClient\Exception\RuntimeException;

/**
 * Class ItemFactory
 */
class ItemFactory {
	/**
	 * 3-letter currency code of the shop.
	 *
	 * @var string
	 */
	private $currency;

	/**
	 * ItemFactory constructor.
	 *
	 * @param string $currency 3-letter currency code of the shop.
	 */
	public function __construct(string $currency) {
		$this->currency = $currency;
	}

	/**
	 * Creates items based off a WooCommerce cart.
	 *
	 * @param \WC_Cart $cart The cart.
	 *
	 * @return Item[]
	 */
	public function from_wc_cart(\WC_Cart $cart): array {
		$index = 0;
		$items = array_map(
			function (array $item) use (&$index): Item {
				$index += 1;
				$product = $item['data'];

				/**
				 * The WooCommerce product.
				 *
				 * @var \WC_Product $product
				 */
				$quantity = (int) $item['quantity'];

				$price = (float) $item['line_subtotal'] / (float) $item['quantity'];
				return new Item(
					mb_substr($product->get_name(), 0, 127),
					new Money($price, $this->currency),
					$quantity,
					substr(wp_strip_all_tags($product->get_description()), 0, 127) ?: '',
					null,
					//					$product->get_sku(), WOOTIFY
					(string)($index),
					($product->is_virtual()) ? Item::DIGITAL_GOODS : Item::PHYSICAL_GOODS
				);
			},
			$cart->get_cart_contents()
		);

		$fees              = array();
		$fees_from_session = WC()->session->get('ppcp_fees');
		if ($fees_from_session) {
			$fees = array_map(
				function (\stdClass $fee): Item {
					return new Item(
						$fee->name,
						new Money((float) $fee->amount, $this->currency),
						1,
						'',
						null
					);
				},
				$fees_from_session
			);
		}

		return array_merge($items, $fees);
	}

	/**
	 * Creates Items based off a WooCommerce order.
	 *
	 * @param \WC_Order $order The order.
	 * @return Item[]
	 */
	public function from_wc_order(\WC_Order $order): array {
		$index = 0;
		$items = array_map(
			function (\WC_Order_Item_Product $item) use ($order, &$index): Item {
				$index += 1;
				return $this->from_wc_order_line_item($item, $order, $index);
			},
			$order->get_items('line_item')
		);

		$fees = array_map(
			function (\WC_Order_Item_Fee $item) use ($order): Item {
				return $this->from_wc_order_fee($item, $order);
			},
			$order->get_fees()
		);

		return array_merge($items, $fees);
	}

	/**
	 * Creates an Item based off a WooCommerce Order Item.
	 *
	 * @param \WC_Order_Item_Product $item The WooCommerce order item.
	 * @param \WC_Order              $order The WooCommerce order.
	 *
	 * @return Item
	 */
	private function from_wc_order_line_item(\WC_Order_Item_Product $item, \WC_Order $order, $index): Item {
		$product                   = $item->get_product();
		$currency                  = $order->get_currency();
		$quantity                  = (int) $item->get_quantity();
		$price_without_tax         = (float) $order->get_item_subtotal($item, false);
		$price_without_tax_rounded = round($price_without_tax, 2);
		return new Item(
			mb_substr($this->getProductTitle($product->get_title(), $order->get_id()), 0, 127),
			new Money($price_without_tax_rounded, $currency),
			$quantity,
			substr(wp_strip_all_tags($product instanceof WC_Product ? $product->get_description() : ''), 0, 127) ?: '',
			null,
			//			$product instanceof WC_Product ? $product->get_sku() : '', WOOTIFY
			(string)($index),
			($product instanceof WC_Product && $product->is_virtual()) ? Item::DIGITAL_GOODS : Item::PHYSICAL_GOODS
		);
	}

	/**
	 * WOOTIFY custom to get product title
	 * @param $productTitle
	 * @param $orderId
	 * @return array|false|mixed|string|string[]
	 */
	private function getProductTitle($productTitle, $orderId) {
		$wootifyPPSetting = get_option('woocommerce_wootify_paypal_settings', []);
		if (empty($wootifyPPSetting)) {
			$wootifyPPSetting = get_option('woocommerce_paypal_settings', []);
		}
		if (!isset($wootifyPPSetting['product_title_setting']) || $wootifyPPSetting['product_title_setting'] == 'keep_original') {
			return $productTitle;
		}

		$title = $productTitle;
		if ($wootifyPPSetting['product_title_setting'] == 'user_define') {
			$title = $wootifyPPSetting['user_define_product_title'];
			$pattern = '/\[(\w+)(?::([^\]]+))?\]/';
			preg_match_all($pattern, $title, $matches, PREG_SET_ORDER);
			$replacements = [];

			foreach ($matches as $shortcodeMatch) {
				$shortcode = $shortcodeMatch[0];
				$key = $shortcodeMatch[1];
				$values = $shortcodeMatch[2] ?? '';

				switch ($key) {
					case 'order_id':
						$replacements[$shortcode] = strval($orderId);
						break;
					case 'variants':
						$variants = [];
						$item_meta = wc_get_order_item_meta($item->get_id(), '_WCPA_order_meta_data', true);
						if (is_array($item_meta)) {
							foreach ($item_meta as $meta) {
								$variants[] = reset($meta['value'])['label'] ?? '';
							}
						}
						$replacements[$shortcode] = implode(' - ', $variants);
						break;
					case 'random':
						$values = explode('|', $values);
						$random_index = array_rand($values);
						$replacements[$shortcode] = $values[$random_index];
						break;
					case 'str':
						$length = (int) $values;
						$replacements[$shortcode] = $this->generateRandomString($length);
						break;
					case 'by_price':
						$price = (float) $item->get_subtotal();
						$conditions = explode('|', $values);
						$name = '';
						foreach ($conditions as $condition) {
							$parts = explode('=', $condition);
							if (count($parts) === 2) {
								$priceRange = $parts[0];
								$itemName = $parts[1];
								list($priceMin, $priceMax) = array_map('floatval', explode('-', $priceRange));
								if ($priceMin <= $price && $price < $priceMax) {
									$name = $itemName;
									break;
								}
							}
						}
						$replacements[$shortcode] = $name;
						break;
					case 'last_word':
						$replacements[$shortcode] = strrchr($productTitle, ' ');
						break;
				}
			}

			$title = strtr($title, $replacements);
		} else if ($wootifyPPSetting['product_title_setting'] == 'last_word') {
			return strrchr($productTitle, ' ');
		}

		return $title;
	}

	/**
	 * WOOTIFY custom to generate random string
	 * @param int $length
	 * @return string
	 * @throws \Exception
	 */
	private function generateRandomString($length = 10) {
		$characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$charactersLength = strlen($characters);
		$randomString = '';
		for ($i = 0; $i < $length; $i++) {
			$randomString .= $characters[rand(0, $charactersLength - 1)];
		}
		return $randomString;
	}

	/**
	 * Creates an Item based off a WooCommerce Fee Item.
	 *
	 * @param \WC_Order_Item_Fee $item The WooCommerce order item.
	 * @param \WC_Order          $order The WooCommerce order.
	 *
	 * @return Item
	 */
	private function from_wc_order_fee(\WC_Order_Item_Fee $item, \WC_Order $order): Item {
		return new Item(
			$item->get_name(),
			new Money((float) $item->get_amount(), $order->get_currency()),
			$item->get_quantity(),
			'',
			null
		);
	}

	/**
	 * Creates an Item based off a PayPal response.
	 *
	 * @param \stdClass $data The JSON object.
	 *
	 * @return Item
	 * @throws RuntimeException When JSON object is malformed.
	 */
	public function from_paypal_response(\stdClass $data): Item {
		if (!isset($data->name)) {
			throw new RuntimeException(
				__('No name for item given', 'woocommerce-paypal-payments')
			);
		}
		if (!isset($data->quantity) || !is_numeric($data->quantity)) {
			throw new RuntimeException(
				__('No quantity for item given', 'woocommerce-paypal-payments')
			);
		}
		if (!isset($data->unit_amount->value) || !isset($data->unit_amount->currency_code)) {
			throw new RuntimeException(
				__('No money values for item given', 'woocommerce-paypal-payments')
			);
		}

		$unit_amount = new Money((float) $data->unit_amount->value, $data->unit_amount->currency_code);
		$description = (isset($data->description)) ? $data->description : '';
		$tax         = (isset($data->tax)) ?
			new Money((float) $data->tax->value, $data->tax->currency_code)
			: null;
		$sku         = (isset($data->sku)) ? $data->sku : '';
		$category    = (isset($data->category)) ? $data->category : 'PHYSICAL_GOODS';

		return new Item(
			$data->name,
			$unit_amount,
			(int) $data->quantity,
			$description,
			$tax,
			$sku,
			$category
		);
	}
}

