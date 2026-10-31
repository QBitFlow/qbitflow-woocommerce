<?php

declare(strict_types=1);

namespace QBitFlowWC;

use WC_Order;

defined('ABSPATH') || exit;

/**
 * QBitFlow prices in USD. A USD store converts nothing; any other currency needs a rate from
 * `qbitflow_wc_usd_rate` (a multi-currency plugin, or the merchant's own code), else the
 * gateway stays hidden rather than charging the wrong amount.
 */
final class Currency
{
	/**
	 * USD per unit of the order's currency (or the shop's current one), or null when unknown.
	 */
	public static function usd_rate(?WC_Order $order = null): ?float
	{
		$currency = $order !== null ? (string) $order->get_currency() : (string) get_woocommerce_currency();
		if (strtoupper($currency) === 'USD') {
			return 1.0;
		}

		/**
		 * Filters how many US dollars one unit of `$currency` is worth, for QBitFlow checkouts.
		 * Return a positive number to accept that currency; null (the default) keeps QBitFlow
		 * unavailable for it.
		 *
		 * @param float|null     $rate
		 * @param string         $currency ISO 4217 code.
		 * @param WC_Order|null  $order    The order being paid, when there is one.
		 */
		$rate = apply_filters('qbitflow_wc_usd_rate', null, $currency, $order);

		return is_numeric($rate) && (float) $rate > 0 && is_finite((float) $rate) ? (float) $rate : null;
	}
}
