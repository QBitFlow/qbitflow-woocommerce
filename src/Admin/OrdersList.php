<?php

declare(strict_types=1);

namespace QBitFlowWC\Admin;

use QBitFlowWC\Gateway;
use QBitFlowWC\Orders\OrderMeta;
use WC_Order;

defined('ABSPATH') || exit;

/**
 * A "Crypto" column and a QBitFlow filter in the orders list, on both order storages (HPOS
 * list table, and the posts list). Cells render from order meta only (no API call).
 */
final class OrdersList
{
	public const COLUMN = 'qbitflow';

	public const PARAM = 'qbitflow_filter';

	private const FILTERS = ['all', 'awaiting', 'refund_requested'];

	public static function init(): void
	{
		// HPOS list table.
		add_filter('manage_woocommerce_page_wc-orders_columns', [self::class, 'columns']);
		add_action('manage_woocommerce_page_wc-orders_custom_column', [self::class, 'hpos_column'], 10, 2);
		add_action('woocommerce_order_list_table_restrict_manage_orders', [self::class, 'hpos_dropdown']);
		add_filter('woocommerce_order_list_table_prepare_items_query_args', [self::class, 'hpos_query']);

		add_action('woocommerce_order_status_changed', [self::class, 'resolve_mismatch'], 10, 4);

		// Posts storage.
		add_filter('manage_edit-shop_order_columns', [self::class, 'columns']);
		add_action('manage_shop_order_posts_custom_column', [self::class, 'posts_column'], 10, 2);
		add_action('restrict_manage_posts', [self::class, 'posts_dropdown']);
		add_filter('request', [self::class, 'posts_query']);
	}

	/** An order leaving on-hold is no longer held for a mismatch. */
	public static function resolve_mismatch(int $id, string $from, string $to, WC_Order $order): void
	{
		if ($from === 'on-hold' && $to !== 'on-hold' && $order->get_meta(OrderMeta::AMOUNT_MISMATCH) !== '') {
			$order->delete_meta_data(OrderMeta::AMOUNT_MISMATCH);
			$order->save_meta_data();
		}
	}

	/**
	 * @param array<string, mixed> $columns
	 *
	 * @return array<string, mixed>
	 */
	public static function columns(array $columns): array
	{
		$out = [];
		$added = false;
		foreach ($columns as $key => $label) {
			$out[$key] = $label;
			if ($key === 'order_status') {
				$out[self::COLUMN] = __('Crypto', 'qbitflow-for-woocommerce');
				$added = true;
			}
		}
		if (! $added) {
			$out[self::COLUMN] = __('Crypto', 'qbitflow-for-woocommerce');
		}

		return $out;
	}

	/** @param mixed $order */
	public static function hpos_column(string $column, $order): void
	{
		if ($column === self::COLUMN && $order instanceof WC_Order) {
			echo self::cell($order); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in cell().
		}
	}

	public static function posts_column(string $column, int|string $postId): void
	{
		if ($column !== self::COLUMN) {
			return;
		}
		$order = wc_get_order((int) $postId);
		if ($order instanceof WC_Order) {
			echo self::cell($order); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in cell().
		}
	}

	/** The cell's HTML, escaped; empty for an order not paid through QBitFlow. */
	public static function cell(WC_Order $order): string
	{
		if ($order->get_payment_method() !== Gateway::ID) {
			return '';
		}
		$html = '';
		$token = (string) $order->get_meta(OrderMeta::CURRENCY);
		$chain = (string) $order->get_meta(OrderMeta::CHAIN);
		if ($token !== '') {
			$html .= esc_html($token);
		}
		// The token usually names its chain already ("USDC (BASE)").
		if ($chain !== '' && ! str_contains($token, $chain)) {
			$html .= ($html !== '' ? ' · ' : '') . esc_html($chain);
		}

		$hash = (string) $order->get_meta(OrderMeta::TX_HASH);
		if ($hash !== '') {
			$short = esc_html(self::shorten($hash));
			$url = esc_url((string) $order->get_meta(OrderMeta::EXPLORER_URL), ['http', 'https']);
			$html .= ($html !== '' ? '<br>' : '') . ($url !== ''
				? '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $short . '</a>'
				: $short);
		}

		$badges = [];
		$statuses = array_column(array_filter(OrderMeta::list($order, OrderMeta::REFUNDS), 'is_array'), 'status');
		if (in_array('pending', $statuses, true)) {
			$badges[] = [__('Refund requested', 'qbitflow-for-woocommerce'), '#f0b849'];
		}
		if (in_array('approved', $statuses, true)) {
			$badges[] = [(float) $order->get_total_refunded() < (float) $order->get_total()
				? __('Partially refunded', 'qbitflow-for-woocommerce')
				: __('Refunded', 'qbitflow-for-woocommerce'), '#c6e1c6'];
		}
		// Set by PaymentSync when it holds an underpaid order; cleared when the order leaves on-hold.
		if ($order->get_status() === 'on-hold' && $order->get_meta(OrderMeta::AMOUNT_MISMATCH) === '1') {
			$badges[] = [__('Amount mismatch', 'qbitflow-for-woocommerce'), '#f8dda7'];
		}
		if ($hash === '' && ! $order->is_paid() && (string) $order->get_meta(OrderMeta::SESSION_UUID) !== '' && $order->get_meta(OrderMeta::LAST_STATUS) !== 'expired') {
			$badges[] = [__('Awaiting payment', 'qbitflow-for-woocommerce'), '#e5e5e5'];
		}
		foreach ($badges as [$label, $color]) {
			$html .= ($html !== '' ? '<br>' : '') . '<mark class="order-status" style="background:' . esc_attr($color) . ';color:#2c3338;padding:0 8px;border-radius:4px"><span>' . esc_html($label) . '</span></mark>';
		}

		return $html;
	}

	/** `0x1234…abcd`: the start and the end of a transaction hash. */
	private static function shorten(string $hash): string
	{
		return mb_strlen($hash) <= 12 ? $hash : mb_substr($hash, 0, 6) . '…' . mb_substr($hash, -4);
	}

	public static function hpos_dropdown(string $orderType = 'shop_order'): void
	{
		if ($orderType === 'shop_order') {
			self::dropdown();
		}
	}

	public static function posts_dropdown(string $postType = ''): void
	{
		if ($postType === 'shop_order') {
			self::dropdown();
		}
	}

	public static function dropdown(): void
	{
		$current = self::requested() ?? '';
		$choices = [
			'' => __('QBitFlow: any', 'qbitflow-for-woocommerce'),
			'all' => __('QBitFlow: all', 'qbitflow-for-woocommerce'),
			'awaiting' => __('QBitFlow: awaiting payment', 'qbitflow-for-woocommerce'),
			'refund_requested' => __('QBitFlow: refund requested', 'qbitflow-for-woocommerce'),
		];
		echo '<select name="' . esc_attr(self::PARAM) . '" id="' . esc_attr(self::PARAM) . '">';
		foreach ($choices as $value => $label) {
			echo '<option value="' . esc_attr((string) $value) . '"' . selected($current, (string) $value, false) . '>' . esc_html($label) . '</option>';
		}
		echo '</select>';
	}

	/** The filter asked for, or null (none, or a value this plugin doesn't know). */
	private static function requested(): ?string
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A read-only list filter.
		$value = isset($_GET[self::PARAM]) && is_scalar($_GET[self::PARAM]) ? sanitize_key(wp_unslash((string) $_GET[self::PARAM])) : '';

		return in_array($value, self::FILTERS, true) ? $value : null;
	}

	/**
	 * @param array<string, mixed> $args `wc_get_orders()` arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function hpos_query(array $args): array
	{
		$filter = self::requested();
		if ($filter === null) {
			return $args;
		}
		$args['payment_method'] = Gateway::ID;
		$extra = self::meta_for($filter);
		if ($extra !== []) {
			$args['meta_query'] = self::combine($args['meta_query'] ?? [], $extra); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- An admin list filter.
		}

		return $args;
	}

	/**
	 * @param array<string, mixed> $vars The posts list's query vars.
	 *
	 * @return array<string, mixed>
	 */
	public static function posts_query(array $vars): array
	{
		$filter = self::requested();
		if ($filter === null || ! is_admin() || ($vars['post_type'] ?? '') !== 'shop_order') {
			return $vars;
		}
		$extra = array_merge([['key' => '_payment_method', 'value' => Gateway::ID]], self::meta_for($filter));
		$vars['meta_query'] = self::combine($vars['meta_query'] ?? [], $extra); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- An admin list filter.

		return $vars;
	}

	/**
	 * @return list<array<string, mixed>> Meta clauses on top of "paid with QBitFlow".
	 */
	private static function meta_for(string $filter): array
	{
		return match ($filter) {
			// The same rule the reconciler uses for an open checkout.
			'awaiting' => [
				['key' => OrderMeta::SESSION_UUID, 'compare' => 'EXISTS'],
				[
					'relation' => 'OR',
					['key' => OrderMeta::LAST_STATUS, 'compare' => 'NOT EXISTS'],
					['key' => OrderMeta::LAST_STATUS, 'value' => ['completed', 'expired'], 'compare' => 'NOT IN'],
				],
			],
			'refund_requested' => [
				['key' => OrderMeta::REFUNDS, 'value' => '"status";s:7:"pending";', 'compare' => 'LIKE'],
			],
			default => [],
		};
	}

	/**
	 * @param mixed                      $existing
	 * @param list<array<string, mixed>> $extra
	 *
	 * @return array<array-key, mixed>
	 */
	private static function combine($existing, array $extra): array
	{
		$all = ['relation' => 'AND'];
		if (is_array($existing) && $existing !== []) {
			$all[] = $existing;
		}

		return array_merge($all, $extra);
	}
}
