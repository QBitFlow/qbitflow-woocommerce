<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QBitFlowWC\Checkout\OrderAmounts;
use QBitFlowWC\Vendor\QBitFlow\Params\CheckoutFees;

final class OrderAmountsTest extends TestCase
{
	private static function line(string $label, int $cents): array
	{
		return ['label' => $label, 'cents' => $cents];
	}

	private static function sum(OrderAmounts $a): int
	{
		return $a->priceCents + array_sum(array_column($a->lines, 'cents'));
	}

	public function test_not_itemized_is_single_price(): void
	{
		$a = OrderAmounts::build(13250, [self::line('Flat rate', 1000)], [self::line('VAT (20%)', 2250)], [], false);

		$this->assertSame(13250, $a->priceCents);
		$this->assertSame([], $a->lines);
		$this->assertSame(13250, $a->totalCents);
	}

	public function test_itemized_lines_in_order_shipping_taxes_fees(): void
	{
		$a = OrderAmounts::build(13750,
			[self::line('Flat rate', 1000)],
			[self::line('VAT (20%)', 2250)],
			[self::line('Gift wrap', 500)],
			true,
		);

		$this->assertSame(10000, $a->priceCents);
		$this->assertSame([
			self::line('Flat rate', 1000),
			self::line('VAT (20%)', 2250),
			self::line('Gift wrap', 500),
		], $a->lines);
		$this->assertSame(13750, self::sum($a));
	}

	public function test_rounding_residue_lands_in_price(): void
	{
		// 3 × 3.33 with 20 % VAT stored per line: WooCommerce's total can differ from the parts by a cent.
		$a = OrderAmounts::build(1199, [], [self::line('VAT (20%)', 201)], [], true);
		$this->assertSame(998, $a->priceCents);
		$this->assertSame(1199, self::sum($a));

		$b = OrderAmounts::build(1200, [], [self::line('VAT (20%)', 200)], [], true);
		$this->assertSame(1000, $b->priceCents);
		$this->assertSame(1200, self::sum($b));
	}

	public function test_negative_fee_folds_into_price(): void
	{
		$a = OrderAmounts::build(9500, [], [], [self::line('Loyalty discount', -500)], true);

		$this->assertSame(9500, $a->priceCents);
		$this->assertSame([], $a->lines);
	}

	public function test_zero_lines_dropped(): void
	{
		$a = OrderAmounts::build(10000, [self::line('Free shipping', 0)], [self::line('VAT (0%)', 0)], [], true);

		$this->assertSame([], $a->lines);
		$this->assertSame(10000, $a->priceCents);
	}

	public function test_label_truncated_to_40_with_ellipsis(): void
	{
		$label = str_repeat('é', 50);
		$a = OrderAmounts::build(11000, [self::line($label, 1000)], [], [], true);

		$this->assertSame(40, mb_strlen($a->lines[0]['label']));
		$this->assertStringEndsWith('…', $a->lines[0]['label']);
	}

	public function test_blank_label_gets_a_default(): void
	{
		$a = OrderAmounts::build(11000, [self::line('  ', 1000)], [], [], true);

		$this->assertSame('Shipping', $a->lines[0]['label']);
	}

	public function test_more_than_10_lines_merge_into_other_charges(): void
	{
		$fees = [];
		for ($i = 1; $i <= 12; $i++) {
			$fees[] = self::line("Fee {$i}", 100);
		}
		$a = OrderAmounts::build(11200, [], [], $fees, true);

		$this->assertCount(10, $a->lines);
		$this->assertSame('Fee 9', $a->lines[8]['label']);
		$this->assertSame(['label' => 'Other charges', 'cents' => 300], $a->lines[9]);
		$this->assertSame(11200, self::sum($a));
	}

	public function test_price_not_positive_falls_back_to_single_price(): void
	{
		// Items fully discounted: only shipping is paid.
		$a = OrderAmounts::build(500, [self::line('Flat rate', 500)], [], [], true);

		$this->assertSame(500, $a->priceCents);
		$this->assertSame([], $a->lines);
	}

	public function test_usd_strings(): void
	{
		$this->assertSame('0.05', OrderAmounts::cents_to_usd(5));
		$this->assertSame('12345.67', OrderAmounts::cents_to_usd(1234567));
		$this->assertSame('10.00', OrderAmounts::cents_to_usd(1000));

		$a = OrderAmounts::build(13250, [self::line('Flat rate', 1000)], [self::line('VAT (20%)', 2250)], [], true);
		$this->assertSame('100.00', $a->priceUsd());
		$this->assertSame('132.50', $a->totalUsd());
	}

	public function test_fingerprint_changes_with_any_amount_or_label(): void
	{
		$base = OrderAmounts::build(11000, [self::line('Flat rate', 1000)], [], [], true);
		$same = OrderAmounts::build(11000, [self::line('Flat rate', 1000)], [], [], true);
		$label = OrderAmounts::build(11000, [self::line('Express', 1000)], [], [], true);
		$amount = OrderAmounts::build(11100, [self::line('Flat rate', 1100)], [], [], true);

		$this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $base->fingerprint());
		$this->assertSame($base->fingerprint(), $same->fingerprint());
		$this->assertNotSame($base->fingerprint(), $label->fingerprint());
		$this->assertNotSame($base->fingerprint(), $amount->fingerprint());
	}

	public function test_to_fees(): void
	{
		$a = OrderAmounts::build(13250, [self::line('Flat rate', 1000)], [self::line('VAT (20%)', 2250)], [], true);

		$fees = $a->toFees(true);

		$this->assertInstanceOf(CheckoutFees::class, $fees);
		$this->assertTrue($fees->processingFee);
		$this->assertSame('Flat rate', $fees->items[0]->label);
		$this->assertSame('10.00', $fees->items[0]->amountUsd);
		$this->assertSame('22.50', $fees->items[1]->amountUsd);
		$this->assertFalse($a->toFees(false)->processingFee);
		$this->assertNull($a->toFees(null)->processingFee);
		$this->assertObjectNotHasProperty('processingFee', $a->toFees(null)->toObject());
	}

	public function test_rejects_negative_total(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		OrderAmounts::build(-1, [], [], [], true);
	}
}
