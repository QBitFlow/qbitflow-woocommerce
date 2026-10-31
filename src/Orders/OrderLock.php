<?php

declare(strict_types=1);

namespace QBitFlowWC\Orders;


defined('ABSPATH') || exit;

/**
 * A per-order lock, so a webhook, the return page and the background check never apply the
 * same payment twice at the same moment (WooCommerce has no lock around payment_complete()).
 *
 * An atomic INSERT IGNORE of an option row (add_option() checks then writes, which races); a
 * lock older than a minute is stale (a crashed request) and taken over.
 */
final class OrderLock
{
	public const STALE_AFTER = 60;

	/**
	 * Runs `$fn` holding the order's lock.
	 *
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 *
	 * @throws OrderBusyException When another request holds it.
	 */
	public static function run(int $orderId, callable $fn): mixed
	{
		$name = 'qbitflow_wc_lock_' . $orderId;
		if (! self::acquire($name)) {
			throw new OrderBusyException(esc_html(sprintf('Order #%d is being updated by another request.', $orderId)));
		}
		try {
			return $fn();
		} finally {
			self::release($name);
		}
	}

	private static function acquire(string $name): bool
	{
		global $wpdb;
		$now = time();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- An atomic lock: the options API can't insert-if-absent.
		if ((int) $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) $now)) === 1) {
			return true;
		}
		$since = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
		if ($now - $since < self::STALE_AFTER) {
			return false;
		}
		// Stale: take it over only if nobody else did meanwhile.
		$taken = (int) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) $now, $name, (string) $since));
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return $taken === 1;
	}

	private static function release(string $name): void
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Releases the lock row taken in acquire().
		$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $name));
	}
}
