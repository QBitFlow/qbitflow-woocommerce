<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration\Support;

use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Order_Item_Tax;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * Integration base: a fake API, a test-mode key, and order factories.
 */
abstract class TestCase extends WP_UnitTestCase
{
	public const TEST_KEY = 'sk_test_019a0000-0000-7000-8000-000000000001_secret';

	public const LIVE_KEY = 'sk_live_019a0000-0000-7000-8000-000000000001_secret';

	/** The checkout a member's status check reads to learn whether it may open checkouts. */
	public const CHECKOUTS_PROBE = '#^/checkout-sessions/00000000-0000-0000-0000-000000000000$#';

	protected FakeApi $api;

	public function set_up(): void
	{
		parent::set_up();
		$this->api = FakeApi::install();
		update_option('woocommerce_currency', 'USD');
		$this->set_settings([]);
		delete_transient('qbitflow_wc_status');
	}

	public function tear_down(): void
	{
		$this->api->uninstall();
		parent::tear_down();
	}

	/**
	 * QBitFlow's answers to the status check for a member's key whose organization lets it open
	 * checkouts.
	 *
	 * @param list<array<string, mixed>>|null $currencies The accepted currencies (one by default).
	 */
	protected function member_status_api(?array $currencies = null): void
	{
		$this->api->on('GET', '#^/me$#', 200, Fixtures::me(true, true));
		$this->api->on('GET', '#^/wallets/accepted-currencies$#', 200, Fixtures::page($currencies ?? [Fixtures::currency()]));
		$this->api->onError('GET', self::CHECKOUTS_PROBE, 404, 'not_found', 'Not found');
	}

	/** Replaces the gateway settings (merged over sensible defaults) and reloads the gateway. */
	protected function set_settings(array $settings): void
	{
		update_option('woocommerce_qbitflow_settings', $settings + [
			'enabled' => 'yes',
			'api_key' => self::TEST_KEY,
			'itemize' => 'yes',
			'processing_fee' => 'account',
			'debug' => 'no',
			'webhook_secret' => '',
		]);
		WC()->payment_gateways()->init();
	}

	/**
	 * A QBitFlow order with billing details.
	 *
	 * @param list<array{name?:string, price:string, qty?:int}> $items
	 * @param array{shipping?:string, shipping_label?:string, taxes?:list<array{label:string, amount:string}>, fees?:list<array{name:string, amount:string}>, email?:string, discount?:string, currency?:string} $opts
	 */
	protected function make_order(array $items = [['price' => '10.00']], array $opts = []): WC_Order
	{
		$order = wc_create_order();
		foreach ($items as $i => $item) {
			$product = new WC_Product_Simple();
			$product->set_name($item['name'] ?? 'Product ' . ($i + 1));
			$product->set_regular_price($item['price']);
			$product->save();
			$line = new WC_Order_Item_Product();
			$line->set_product($product);
			$qty = $item['qty'] ?? 1;
			$line->set_quantity($qty);
			$subtotal = (float) $item['price'] * $qty;
			$line->set_subtotal((string) $subtotal);
			$line->set_total((string) ($subtotal - (float) ($i === 0 ? ($opts['discount'] ?? 0) : 0)));
			$order->add_item($line);
		}
		if (isset($opts['shipping'])) {
			$ship = new WC_Order_Item_Shipping();
			$ship->set_method_title($opts['shipping_label'] ?? 'Flat rate');
			$ship->set_total($opts['shipping']);
			$order->add_item($ship);
		}
		foreach ($opts['fees'] ?? [] as $fee) {
			$item = new WC_Order_Item_Fee();
			$item->set_name($fee['name']);
			$item->set_total($fee['amount']);
			$order->add_item($item);
		}
		foreach ($opts['taxes'] ?? [] as $i => $tax) {
			$item = new WC_Order_Item_Tax();
			$item->set_rate_id(1000 + $i);
			$item->set_label($tax['label']);
			$item->set_rate_code('TAX-' . $i);
			$item->set_tax_total($tax['amount']);
			$item->set_shipping_tax_total('0');
			$order->add_item($item);
		}
		$order->set_currency($opts['currency'] ?? 'USD');
		$order->set_payment_method('qbitflow');
		$order->set_billing_first_name('Ada');
		$order->set_billing_last_name('Lovelace');
		$order->set_billing_email($opts['email'] ?? 'ada@example.com');
		$order->set_billing_phone('+44 20 7946 0000');
		$order->set_billing_address_1('12 St James Square');
		$order->set_billing_city('London');
		$order->set_billing_postcode('SW1Y 4JH');
		$order->set_billing_country('GB');
		$this->set_totals($order, $opts);
		$order->set_status('pending');
		$order->save();

		return $order;
	}

	/** Totals the way WooCommerce stores them, without recalculating taxes from rates. */
	private function set_totals(WC_Order $order, array $opts): void
	{
		$cents = 0;
		foreach ($order->get_items() as $item) {
			$cents += (int) round((float) $item->get_total() * 100);
		}
		foreach ($order->get_fees() as $fee) {
			$cents += (int) round((float) $fee->get_total() * 100);
		}
		$shipping = (int) round((float) ($opts['shipping'] ?? 0) * 100);
		$tax = 0;
		foreach ($opts['taxes'] ?? [] as $t) {
			$tax += (int) round((float) $t['amount'] * 100);
		}
		$order->set_shipping_total((string) ($shipping / 100));
		$order->set_cart_tax((string) ($tax / 100));
		$order->set_discount_total((string) (float) ($opts['discount'] ?? 0));
		$order->set_total(number_format(($cents + $shipping + $tax) / 100, 2, '.', ''));
	}

	/** A `CreatedCheckoutSession` body. */
	protected static function created_session(string $uuid = 'pay@019a0000-0000-7000-8000-0000000000a1'): array
	{
		return [
			'uuid' => $uuid,
			'link' => 'https://qbitflow.app/checkout?uuid=' . $uuid,
			'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
		];
	}
}
