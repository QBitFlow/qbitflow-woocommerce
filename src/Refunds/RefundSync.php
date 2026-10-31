<?php

declare(strict_types=1);

namespace QBitFlowWC\Refunds;

use QBitFlowWC\ClientFactory;
use QBitFlowWC\Log;
use QBitFlowWC\Orders\OrderBusyException;
use QBitFlowWC\Orders\OrderFinder;
use QBitFlowWC\Orders\OrderLock;
use QBitFlowWC\Orders\OrderMeta;
use QBitFlowWC\Vendor\QBitFlow\Enums\EventType;
use QBitFlowWC\Vendor\QBitFlow\Enums\RefundStatus;
use QBitFlowWC\Vendor\QBitFlow\Events\Event;
use QBitFlowWC\Vendor\QBitFlow\Exceptions\QBitFlowException;
use QBitFlowWC\Vendor\QBitFlow\Models\Refund;
use QBitFlowWC\Vendor\QBitFlow\Params\RefundListParams;
use QBitFlowWC\Vendor\QBitFlow\Webhooks\WebhookRouter;
use QBitFlowWC\Webhooks\Receiver;
use RuntimeException;
use WC_Order;

defined('ABSPATH') || exit;

/**
 * Refunds are decided and sent in the QBitFlow dashboard (the merchant's wallet signs them);
 * their webhooks keep WooCommerce in step:
 *
 * - `refund.requested`: a note, and the pending-requests notice;
 * - `refund.approved`: a WooCommerce refund for the share sent back (`refundPercent` of the
 *   order total, capped to what is not refunded yet), once per refund;
 * - `refund.rejected`: a note with the merchant's answer.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Thrown to make QBitFlow retry; logged, never output.
final class RefundSync
{
	/** Pending requests of this store: refund uuid → order id. */
	public const PENDING_OPTION = 'qbitflow_wc_pending_refunds';

	public const SYNC_HOOK = 'qbitflow_wc_sync_refunds';

	public const DASHBOARD_URL = 'https://qbitflow.app/refunds';

	public static function init(): void
	{
		add_action('admin_notices', [self::class, 'admin_notice']);
		add_action(self::SYNC_HOOK, [self::class, 'resync_pending']);
	}

	public static function attach(WebhookRouter $router): WebhookRouter
	{
		return $router
			->on(EventType::REFUND_REQUESTED, static fn (Refund $refund, Event $event) => self::dispatch($refund, $event, [self::class, 'on_requested']))
			->on(EventType::REFUND_APPROVED, static fn (Refund $refund, Event $event) => self::dispatch($refund, $event, [self::class, 'on_approved']))
			->on(EventType::REFUND_REJECTED, static fn (Refund $refund, Event $event) => self::dispatch($refund, $event, [self::class, 'on_rejected']));
	}

	/** @param callable(WC_Order, Refund): void $handler */
	private static function dispatch(Refund $refund, Event $event, callable $handler): void
	{
		// Only payments are WooCommerce orders (a subscription bill, sub-hist@…, isn't).
		$order = str_starts_with($refund->txUuid, 'sub-hist@') ? null : OrderFinder::by_session($refund->txUuid);
		Receiver::for_order($order, $event, static fn (WC_Order $o) => $handler($o, $refund));
	}

	public static function on_requested(WC_Order $order, Refund $refund): void
	{
		self::snapshot($order, $refund);
		$order->add_order_note(sprintf(
			/* translators: 1: refund share in percent, 2: the customer's reason */
			__('The customer requested a QBitFlow refund (%1$s%%). Reason: %2$s. Approve or reject it in the QBitFlow dashboard.', 'qbitflow-for-woocommerce'),
			self::percent($refund),
			rtrim((string) ($refund->reason ?? ''), " \t\n.") ?: '—'
		));
		$order->save();
		self::set_pending($refund->uuid, $order->get_id());
	}

	/**
	 * Records the refund in WooCommerce, holding the order's lock: a delivery QBitFlow retries
	 * while the first one is still running must not record it twice.
	 *
	 * @throws RuntimeException    When WooCommerce refuses the refund (QBitFlow then retries).
	 * @throws OrderBusyException  While another request updates the order (QBitFlow then retries).
	 */
	public static function on_approved(WC_Order $order, Refund $refund): void
	{
		OrderLock::run($order->get_id(), static function () use ($order, $refund): void {
			$fresh = wc_get_order($order->get_id());
			if ($fresh instanceof WC_Order) {
				self::approve_locked($fresh, $refund);
			}
		});
	}

	private static function approve_locked(WC_Order $order, Refund $refund): void
	{
		self::set_pending($refund->uuid, null);
		$known = OrderMeta::list($order, OrderMeta::REFUNDS);
		if (! empty($known[$refund->uuid]['wc_refund_id'])) {
			return;
		}

		$total = (int) round((float) $order->get_total() * 100);
		$remaining = $total - (int) round((float) $order->get_total_refunded() * 100);
		// round(total × percent / 100, 2): float noise removed first, so an exact half cent rounds up.
		$cents = min((int) round(round($total * (float) $refund->refundPercent / 100, 6)), $remaining);
		$tx = (string) $refund->txHash;

		// A refund recorded by an earlier delivery that died before saving its snapshot.
		foreach ($order->get_refunds() as $existing) {
			if (str_contains((string) $existing->get_reason(), $refund->uuid)) {
				self::snapshot($order, $refund, ['wc_refund_id' => $existing->get_id()]);
				$order->save();

				return;
			}
		}

		if ($cents <= 0) {
			self::snapshot($order, $refund, ['wc_refund_id' => -1]);
			$order->add_order_note(sprintf(
				/* translators: %s: refund transaction hash */
				__('QBitFlow refund sent (tx %s); the order was already fully refunded in WooCommerce.', 'qbitflow-for-woocommerce'),
				$tx
			));
			$order->save();

			return;
		}

		$wcRefund = wc_create_refund([
			'order_id' => $order->get_id(),
			'amount' => number_format($cents / 100, 2, '.', ''),
			'reason' => sprintf(
				/* translators: 1: QBitFlow refund id, 2: refund share in percent, 3: refund transaction hash, 4: reason */
				__('QBitFlow refund %1$s (%2$s%%), tx %3$s. %4$s', 'qbitflow-for-woocommerce'),
				$refund->uuid,
				self::percent($refund),
				self::short_hash($tx),
				$refund->reason ?? ''
			),
			'refund_payment' => false,
			'restock_items' => false,
		]);
		if (is_wp_error($wcRefund)) {
			throw new RuntimeException(sprintf('Order #%d: WooCommerce refused the QBitFlow refund %s: %s', $order->get_id(), $refund->uuid, $wcRefund->get_error_message()));
		}

		$order = wc_get_order($order->get_id());
		self::snapshot($order, $refund, ['wc_refund_id' => $wcRefund->get_id()]);
		$order->update_meta_data(OrderMeta::REFUND_TX_HASH, $tx);
		$order->add_order_note(sprintf(
			/* translators: 1: amount refunded, 2: token amount sent back, 3: refund transaction hash */
			__('QBitFlow refund completed: %1$s recorded (%2$s sent back on-chain). Tx: %3$s', 'qbitflow-for-woocommerce'),
			wp_strip_all_tags(wc_price($cents / 100, ['currency' => $order->get_currency()])),
			$refund->amount . ' ' . $refund->currency->symbol,
			$tx
		));
		$order->save();
		Log::info(sprintf('Order #%d: QBitFlow refund %s recorded (%d cents).', $order->get_id(), $refund->uuid, $cents));
	}

	public static function on_rejected(WC_Order $order, Refund $refund): void
	{
		self::set_pending($refund->uuid, null);
		self::snapshot($order, $refund);
		$order->add_order_note(sprintf(
			/* translators: %s: the merchant's answer */
			__('QBitFlow refund request rejected. Answer: %s', 'qbitflow-for-woocommerce'),
			$refund->merchantMessage ?? '—'
		));
		$order->save();
	}

	public static function pending_count(): int
	{
		return count(self::pending());
	}

	/**
	 * Rebuilds the pending list from QBitFlow (hourly): requests of this store's orders only.
	 */
	public static function resync_pending(): void
	{
		$client = ClientFactory::make(10.0, 1);
		if ($client === null) {
			return;
		}
		$pending = [];
		try {
			$seen = 0;
			foreach ($client->refunds->iterate(new RefundListParams(limit: 100, status: [RefundStatus::PENDING])) as $refund) {
				if (++$seen > 500) {
					break;
				}
				$order = str_starts_with($refund->txUuid, 'sub-hist@') ? null : OrderFinder::by_session($refund->txUuid);
				if ($order !== null) {
					$pending[$refund->uuid] = $order->get_id();
				}
			}
		} catch (QBitFlowException $e) {
			Log::warning('Pending QBitFlow refunds not refreshed: ' . $e->getMessage());

			return;
		}
		update_option(self::PENDING_OPTION, $pending, false);
	}

	/** On WooCommerce screens: pending refund requests (from the local list, no API call). */
	public static function admin_notice(): void
	{
		if (! current_user_can('manage_woocommerce')) {
			return;
		}
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ($screen !== null && ! str_contains((string) $screen->id, 'woocommerce') && ! str_contains((string) $screen->id, 'shop_order') && $screen->id !== 'dashboard') {
			return;
		}
		$count = self::pending_count();
		if ($count < 1) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <a class="button button-small" href="%s" target="_blank" rel="noopener noreferrer">%s</a></p></div>',
			esc_html__('QBitFlow:', 'qbitflow-for-woocommerce'),
			esc_html(sprintf(
				/* translators: %d: number of pending refund requests */
				_n('%d refund request waits for your answer.', '%d refund requests wait for your answer.', $count, 'qbitflow-for-woocommerce'),
				$count
			)),
			esc_url(self::DASHBOARD_URL),
			esc_html__('Review on QBitFlow →', 'qbitflow-for-woocommerce')
		);
	}

	/** @return array<string, int> */
	private static function pending(): array
	{
		$pending = get_option(self::PENDING_OPTION, []);

		return is_array($pending) ? $pending : [];
	}

	private static function set_pending(string $refundUuid, ?int $orderId): void
	{
		$pending = self::pending();
		if ($orderId === null) {
			unset($pending[$refundUuid]);
		} else {
			$pending[$refundUuid] = $orderId;
		}
		update_option(self::PENDING_OPTION, $pending, false);
	}

	/** @param array<string, mixed> $extra */
	private static function snapshot(WC_Order $order, Refund $refund, array $extra = []): void
	{
		$known = OrderMeta::list($order, OrderMeta::REFUNDS);
		$known[$refund->uuid] = array_merge($known[$refund->uuid] ?? [], [
			'status' => $refund->status,
			'initiated_by' => $refund->initiatedBy,
			'percent' => $refund->refundPercent,
			'amount' => $refund->amount . ' ' . $refund->currency->symbol,
			'amount_usd' => $refund->amountUsd,
			'reason' => $refund->reason,
			'merchant_message' => $refund->merchantMessage,
			'tx_hash' => $refund->txHash,
			'explorer_url' => $refund->explorerUrl,
			'responded_at' => $refund->respondedAt?->getTimestamp(),
		], $extra);
		$order->update_meta_data(OrderMeta::REFUNDS, $known);
	}

	private static function percent(Refund $refund): string
	{
		$percent = $refund->refundPercent;

		return str_contains($percent, '.') ? rtrim(rtrim($percent, '0'), '.') : $percent;
	}

	/** A hash short enough for WooCommerce's refund line, which doesn't wrap (`0x1234…abcd`). */
	private static function short_hash(string $hash): string
	{
		return mb_strlen($hash) <= 14 ? $hash : mb_substr($hash, 0, 6) . '…' . mb_substr($hash, -4);
	}
}
