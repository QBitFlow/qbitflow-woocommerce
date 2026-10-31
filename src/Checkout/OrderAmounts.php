<?php

declare(strict_types=1);

namespace QBitFlowWC\Checkout;

use InvalidArgumentException;
use QBitFlowWC\Support\Text;
use QBitFlowWC\Vendor\QBitFlow\Params\CheckoutFees;
use QBitFlowWC\Vendor\QBitFlow\Params\FeeItem;
use WC_Order;

defined('ABSPATH') || exit;

/**
 * What a QBitFlow checkout charges for an order, in integer cents: a price and, when
 * itemizing, the order's shipping, taxes and fees as separate lines (at most 10, labels of at
 * most 40 characters, each above 0, as the API requires).
 *
 * The price is always the order total minus the lines, so price + lines equals the total to the
 * cent whatever WooCommerce's rounding did; negative fees (discounts) end up in the price.
 */
final readonly class OrderAmounts
{
	public const MAX_LINES = 10;

	public const MAX_LABEL = 40;

	/**
	 * @param list<array{label: string, cents: int}> $lines
	 */
	private function __construct(
		public int $priceCents,
		public array $lines,
		public int $totalCents,
	) {
	}

	/**
	 * @param list<array{label: string, cents: int}> $shipping Shipping lines (excluding tax).
	 * @param list<array{label: string, cents: int}> $taxes    Tax lines (one per rate).
	 * @param list<array{label: string, cents: int}> $fees     Cart fees (excluding tax; negative ones fold into the price).
	 *
	 * @throws InvalidArgumentException For a negative total.
	 */
	public static function build(int $totalCents, array $shipping, array $taxes, array $fees, bool $itemize): self
	{
		if ($totalCents < 0) {
			throw new InvalidArgumentException('The order total cannot be negative.');
		}
		if (! $itemize) {
			return new self($totalCents, [], $totalCents);
		}

		$lines = [];
		foreach (['Shipping' => $shipping, 'Tax' => $taxes, 'Fee' => $fees] as $default => $group) {
			foreach ($group as $line) {
				if ($line['cents'] > 0) {
					$lines[] = ['label' => self::label($line['label'], $default), 'cents' => $line['cents']];
				}
			}
		}
		if (count($lines) > self::MAX_LINES) {
			$rest = array_splice($lines, self::MAX_LINES - 1);
			$lines[] = ['label' => 'Other charges', 'cents' => array_sum(array_column($rest, 'cents'))];
		}

		$price = $totalCents - array_sum(array_column($lines, 'cents'));
		if ($price <= 0) {
			// Nothing left for the price (items fully discounted): charge the total as the price.
			return new self($totalCents, [], $totalCents);
		}

		return new self($price, $lines, $totalCents);
	}

	/**
	 * The amounts of a WooCommerce order, converted to USD at `$rate` (1.0 for a USD order).
	 */
	public static function fromOrder(WC_Order $order, bool $itemize, float $rate = 1.0): self
	{
		$cents = static fn (mixed $amount): int => (int) round((float) $amount * $rate * 100);
		$text = static fn (string $label): string => wp_strip_all_tags(html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

		$shipping = [];
		foreach ($order->get_items('shipping') as $item) {
			$shipping[] = ['label' => $text((string) $item->get_name()), 'cents' => $cents($item->get_total())];
		}

		$taxes = [];
		foreach ($order->get_tax_totals() as $tax) {
			$label = (string) $tax->label;
			$percent = class_exists('WC_Tax') && ! empty($tax->rate_id) ? (string) \WC_Tax::get_rate_percent((int) $tax->rate_id) : '';
			if ($percent !== '' && ! str_contains($label, '%')) {
				$label .= ' (' . $percent . ')';
			}
			$taxes[] = ['label' => $text($label), 'cents' => $cents($tax->amount)];
		}

		$fees = [];
		foreach ($order->get_fees() as $fee) {
			$fees[] = ['label' => $text((string) $fee->get_name()), 'cents' => $cents($fee->get_total())];
		}

		return self::build($cents($order->get_total()), $shipping, $taxes, $fees, $itemize);
	}

	/** `"12.50"` from 1250. */
	public static function cents_to_usd(int $cents): string
	{
		return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
	}

	public function priceUsd(): string
	{
		return self::cents_to_usd($this->priceCents);
	}

	public function totalUsd(): string
	{
		return self::cents_to_usd($this->totalCents);
	}

	/**
	 * 16 hex characters identifying these amounts and labels: a checkout session opened for
	 * them can be reused as long as they don't change.
	 */
	public function fingerprint(): string
	{
		return substr(hash('sha256', (string) json_encode([$this->priceCents, $this->lines, $this->totalCents], JSON_UNESCAPED_UNICODE)), 0, 16);
	}

	/** The checkout's `fees`: the lines, and who pays the processing fee (null: the account decides, key omitted). */
	public function toFees(?bool $processingFee): CheckoutFees
	{
		return new CheckoutFees(
			processingFee: $processingFee,
			items: array_map(
				static fn (array $line): FeeItem => new FeeItem($line['label'], self::cents_to_usd($line['cents'])),
				$this->lines,
			),
		);
	}

	/** One line the API accepts, at most 40 characters, a default when nothing is left. */
	private static function label(string $label, string $default): string
	{
		$label = Text::line($label, self::MAX_LABEL);

		return $label !== '' ? $label : $default;
	}
}
