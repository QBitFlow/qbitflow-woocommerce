<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration\Checkout;

use QBitFlowWC\Checkout\OrderAmounts;
use QBitFlowWC\Tests\Integration\Support\TestCase;
use WC_Coupon;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Product_Simple;
use WC_Tax;

/**
 * Amounts from orders WooCommerce totals itself (taxes, coupons, fees, shipping).
 */
final class OrderAmountsFromOrderTest extends TestCase
{
	public function set_up(): void
	{
		parent::set_up();
		update_option('woocommerce_calc_taxes', 'yes');
		update_option('woocommerce_prices_include_tax', 'no');
		WC_Tax::_insert_tax_rate([
			'tax_rate_country' => '',
			'tax_rate_state' => '',
			'tax_rate' => '20.0000',
			'tax_rate_name' => 'VAT',
			'tax_rate_priority' => '1',
			'tax_rate_compound' => '0',
			'tax_rate_shipping' => '1',
			'tax_rate_order' => '1',
			'tax_rate_class' => '',
		]);
	}

	private function calculated_order(bool $withCoupon = true): WC_Order
	{
		$order = wc_create_order();
		foreach ([['Mug', '3.33', 3], ['Poster', '12.99', 1]] as [$name, $price, $qty]) {
			$product = new WC_Product_Simple();
			$product->set_name($name);
			$product->set_regular_price($price);
			$product->save();
			$item = new WC_Order_Item_Product();
			$item->set_product($product);
			$item->set_quantity($qty);
			$item->set_subtotal((string) ((float) $price * $qty));
			$item->set_total((string) ((float) $price * $qty));
			$order->add_item($item);
		}
		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_method_title('Flat rate');
		$shipping->set_total('4.95');
		$order->add_item($shipping);
		$fee = new WC_Order_Item_Fee();
		$fee->set_name('Gift wrap');
		$fee->set_amount('2.50');
		$fee->set_total('2.50');
		$order->add_item($fee);
		$order->set_billing_country('FR');
		$order->set_currency('USD');
		$order->set_payment_method('qbitflow');
		$order->save();

		if ($withCoupon) {
			$coupon = new WC_Coupon();
			$coupon->set_code('TENOFF');
			$coupon->set_discount_type('percent');
			$coupon->set_amount(10);
			$coupon->save();
			$order->apply_coupon('TENOFF');
		}
		$order->calculate_totals(true);
		$order->save();

		return wc_get_order($order->get_id());
	}

	public function test_real_order_totals_match_to_the_cent(): void
	{
		$order = $this->calculated_order();

		$amounts = OrderAmounts::fromOrder($order, true);

		$total = (int) round((float) $order->get_total() * 100);
		$this->assertSame($total, $amounts->totalCents);
		$this->assertSame($total, $amounts->priceCents + array_sum(array_column($amounts->lines, 'cents')));
		$labels = array_column($amounts->lines, 'label');
		$this->assertContains('Flat rate', $labels);
		$this->assertContains('VAT (20%)', $labels);
		$this->assertContains('Gift wrap', $labels);
	}

	public function test_tax_line_includes_shipping_and_fee_tax(): void
	{
		$order = $this->calculated_order(false);

		$amounts = OrderAmounts::fromOrder($order, true);
		$tax = array_values(array_filter($amounts->lines, static fn ($l) => $l['label'] === 'VAT (20%)'))[0];

		$this->assertSame((int) round((float) $order->get_total_tax() * 100), $tax['cents']);
	}

	public function test_rate_applied_for_non_usd(): void
	{
		$order = $this->calculated_order();

		$amounts = OrderAmounts::fromOrder($order, true, 1.1);

		$this->assertSame((int) round((float) $order->get_total() * 1.1 * 100), $amounts->totalCents);
		$this->assertSame($amounts->totalCents, $amounts->priceCents + array_sum(array_column($amounts->lines, 'cents')));
	}
}
