<?php

declare(strict_types=1);

namespace QBitFlowWC\Orders;

use Automattic\WooCommerce\Utilities\OrderUtil;
use QBitFlowWC\Gateway;
use QBitFlowWC\Settings;
use WC_Order;

defined('ABSPATH') || exit;

/**
 * Finds the WooCommerce order a QBitFlow session, payment or event is about, in either order
 * storage (HPOS tables, or the posts table where `wc_get_orders` takes no meta query).
 */
final class OrderFinder
{
	/** The order whose session is `$uuid` (`pay@…` or the bare uuid 1.x stored). */
	public static function by_session(string $uuid): ?WC_Order
	{
		$bare = OrderMeta::bare_uuid($uuid);
		if ($bare === '') {
			return null;
		}
		$meta = [[
			'key' => OrderMeta::SESSION_UUID,
			'value' => [$bare, 'pay@' . $bare],
			'compare' => 'IN',
		]];
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Events name orders only by session uuid; once per event.
		if (self::hpos()) {
			$orders = wc_get_orders(['limit' => 1, 'type' => 'shop_order', 'meta_query' => $meta]);
			$order = $orders[0] ?? null;
		} else {
			// The posts storage: wc_get_orders() doesn't take meta_query there (WooCommerce 9.2+).
			$ids = get_posts(['post_type' => 'shop_order', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => true, 'meta_query' => $meta]);
			$order = isset($ids[0]) ? wc_get_order((int) $ids[0]) : null;
		}
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query

		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * The order a session's metadata names, only when this store made the session for that
	 * order (store id and the order's token): a staging clone sharing the account and the
	 * order numbers can't complete this store's orders. Events are signature-verified, so the
	 * metadata is QBitFlow's copy of what was sent.
	 *
	 * @param array<array-key, mixed> $metadata
	 */
	public static function by_metadata(array $metadata): ?WC_Order
	{
		if (($metadata['source'] ?? null) !== 'woocommerce' || ($metadata['store_id'] ?? null) !== Settings::store_id()) {
			return null;
		}
		$order = wc_get_order(absint($metadata['order_id'] ?? 0));

		return $order instanceof WC_Order && $order->get_payment_method() === Gateway::ID && OrderMeta::metadata_matches($order, $metadata) ? $order : null;
	}

	/**
	 * @param array<array-key, mixed> $metadata
	 */
	public static function for_session(string $uuid, array $metadata = []): ?WC_Order
	{
		return self::by_session($uuid) ?? self::by_metadata($metadata);
	}

	private static function hpos(): bool
	{
		return class_exists(OrderUtil::class) && OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Unpaid QBitFlow orders whose checkout may still settle: a session, not already recorded
	 * (`completed`) or expired, touched in the last `$maxAgeHours` (a checkout is opened or
	 * replaced on each payment attempt), newest first so a backlog never hides recent orders.
	 *
	 * @return list<WC_Order>
	 */
	public static function unpaid_with_session(int $maxAgeHours = 48, int $limit = 50): array
	{
		$since = time() - $maxAgeHours * HOUR_IN_SECONDS;
		$meta = [
			'relation' => 'AND',
			['key' => OrderMeta::SESSION_UUID, 'compare' => 'EXISTS'],
			[
				'relation' => 'OR',
				['key' => OrderMeta::LAST_STATUS, 'compare' => 'NOT EXISTS'],
				['key' => OrderMeta::LAST_STATUS, 'value' => ['completed', 'expired'], 'compare' => 'NOT IN'],
			],
		];
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded background reconciliation.
		if (self::hpos()) {
			$orders = wc_get_orders([
				'limit' => $limit,
				'type' => 'shop_order',
				'status' => ['pending', 'on-hold'],
				'payment_method' => Gateway::ID,
				'date_modified' => '>' . $since,
				'orderby' => 'modified',
				'order' => 'DESC',
				'meta_query' => $meta,
			]);
		} else {
			$meta[] = ['key' => '_payment_method', 'value' => Gateway::ID];
			$ids = get_posts([
				'post_type' => 'shop_order',
				'post_status' => ['wc-pending', 'wc-on-hold'],
				'fields' => 'ids',
				'posts_per_page' => $limit,
				'no_found_rows' => true,
				'orderby' => 'modified',
				'order' => 'DESC',
				'date_query' => [['after' => gmdate('Y-m-d H:i:s', $since), 'column' => 'post_modified_gmt']],
				'meta_query' => $meta,
			]);
			$orders = array_map(static fn ($id) => wc_get_order((int) $id), $ids);
		}
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query

		return array_values(array_filter($orders, static fn ($o): bool => $o instanceof WC_Order && ! $o->is_paid()));
	}
}
