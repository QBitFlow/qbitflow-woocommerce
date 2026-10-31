<?php

declare(strict_types=1);

namespace QBitFlowWC\Admin;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use QBitFlowWC\Gateway;
use QBitFlowWC\Orders\OrderMeta;
use QBitFlowWC\Orders\PaymentSync;
use QBitFlowWC\Refunds\RefundSync;
use WC_Order;
use WP_Post;

defined('ABSPATH') || exit;

/**
 * The "QBitFlow Payment" box on the order screen. It renders from order meta only (no API
 * call on page load); "Refresh from QBitFlow" reads the checkout on demand.
 */
final class OrderMetaBox
{
	public static function init(): void
	{
		add_action('add_meta_boxes', [self::class, 'register']);
		Actions::add(Actions::REFRESH_ORDER, [self::class, 'refresh'], 'edit_shop_orders');
	}

	public static function register(): void
	{
		$hpos = class_exists(CustomOrdersTableController::class)
			&& wc_get_container()->get(CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled();

		add_meta_box(
			'qbitflow-payment-info',
			__('QBitFlow Payment', 'qbitflow-for-woocommerce'),
			[self::class, 'render'],
			$hpos ? wc_get_page_screen_id('shop-order') : 'shop_order',
			'side',
			'high'
		);
	}

	/** @param WC_Order|WP_Post $postOrOrder */
	public static function render($postOrOrder): void
	{
		$order = $postOrOrder instanceof WP_Post ? wc_get_order($postOrOrder->ID) : $postOrOrder;
		if (! $order instanceof WC_Order || $order->get_payment_method() !== Gateway::ID) {
			echo '<p>' . esc_html__('Not a QBitFlow payment.', 'qbitflow-for-woocommerce') . '</p>';

			return;
		}

		$rows = [];
		$status = (string) $order->get_meta(OrderMeta::LAST_STATUS);
		if ($status !== '') {
			$colors = ['completed' => '#28a745', 'waitingConfirmation' => '#17a2b8', 'created' => '#007bff', 'expired' => '#6c757d', 'pending' => '#ffc107', 'failed' => '#dc3545', 'cancelled' => '#6c757d'];
			$rows[] = [__('Status', 'qbitflow-for-woocommerce'), '<span style="color:' . esc_attr($colors[$status] ?? '#666') . ';font-weight:bold">' . esc_html(self::status_label($status)) . '</span>'];
		}
		$rows[] = [__('Mode', 'qbitflow-for-woocommerce'), esc_html((string) $order->get_meta(OrderMeta::MODE))];
		$rows[] = [__('Checkout', 'qbitflow-for-woocommerce'), self::code((string) $order->get_meta(OrderMeta::SESSION_UUID))];
		$tx = (string) $order->get_meta(OrderMeta::TX_HASH);
		$explorer = (string) $order->get_meta(OrderMeta::EXPLORER_URL);
		$rows[] = [__('Tx hash', 'qbitflow-for-woocommerce'), $tx !== '' && $explorer !== ''
			? '<a href="' . esc_url($explorer) . '" target="_blank" rel="noopener noreferrer">' . self::code($tx) . '</a>'
			: self::code($tx)];
		$rows[] = [__('Paid in', 'qbitflow-for-woocommerce'), esc_html((string) $order->get_meta(OrderMeta::CURRENCY))];
		$paid = (string) $order->get_meta(OrderMeta::PAID_USD);
		$rows[] = [__('Paid (USD)', 'qbitflow-for-woocommerce'), $paid !== '' ? esc_html('$' . $paid) : ''];
		$fee = (string) $order->get_meta(OrderMeta::PROCESSING_FEE_USD);
		$rows[] = [__('Processing fee', 'qbitflow-for-woocommerce'), $fee !== '' ? esc_html(sprintf(
			/* translators: %s: fee in USD */
			__('$%s, paid by the customer', 'qbitflow-for-woocommerce'),
			$fee
		)) : ''];
		$rows[] = [__('Customer', 'qbitflow-for-woocommerce'), self::code((string) $order->get_meta(OrderMeta::CUSTOMER_UUID))];
		$paidAfter = $order->is_paid() || $status === 'completed';
		$rows[] = [$paidAfter
			? __('Earlier failed attempt (the customer paid afterwards)', 'qbitflow-for-woocommerce')
			: __('Last failed attempt', 'qbitflow-for-woocommerce'), self::last_attempt($order)];

		// A fixed layout keeps the table inside the side box, so long ids wrap instead of widening it
		// (wp_kses_post() strips word-break from the code's own style).
		echo '<table class="widefat" style="border:0;table-layout:fixed;width:100%"><tbody>';
		foreach ($rows as [$label, $html]) {
			if ($html === '' || $html === self::code('')) {
				continue;
			}
			printf('<tr><td style="width:38%%"><strong>%s</strong></td><td style="overflow-wrap:anywhere">%s</td></tr>', esc_html($label), wp_kses_post($html));
		}
		echo '</tbody></table>';

		self::render_refunds($order);

		echo '<p style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap">';
		$management = (string) $order->get_meta(OrderMeta::MANAGEMENT_LINK);
		if ($management !== '') {
			printf('<a class="button button-small" href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url($management), esc_html__('Payment page', 'qbitflow-for-woocommerce'));
		}
		if ((string) $order->get_meta(OrderMeta::SESSION_UUID) !== '') {
			printf('<a class="button button-small" href="%s">%s</a>', esc_url(Actions::url(Actions::REFRESH_ORDER, ['order_id' => $order->get_id()])), esc_html__('Refresh from QBitFlow', 'qbitflow-for-woocommerce'));
		}
		if ($order->is_paid()) {
			printf('<a class="button button-small" href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url(RefundSync::DASHBOARD_URL), esc_html__('Refund on QBitFlow', 'qbitflow-for-woocommerce'));
		}
		echo '</p>';
	}

	/** The "Refresh from QBitFlow" action. */
	public static function refresh(): string
	{
		// Nonce and capability checked by Actions::run().
		$orderId = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order = wc_get_order($orderId);
		if (! $order instanceof WC_Order || $order->get_payment_method() !== Gateway::ID) {
			Notices::add(__('Not a QBitFlow order.', 'qbitflow-for-woocommerce'), 'error');

			return admin_url('admin.php?page=wc-orders');
		}

		$messages = [
			PaymentSync::COMPLETED => [__('QBitFlow: the payment is recorded on this order.', 'qbitflow-for-woocommerce'), 'success'],
			PaymentSync::EXPIRED => [__('QBitFlow: the checkout expired unpaid.', 'qbitflow-for-woocommerce'), 'warning'],
			PaymentSync::OPEN => [__('QBitFlow: the checkout is still open (not paid yet).', 'qbitflow-for-woocommerce'), 'info'],
			PaymentSync::UNKNOWN => [__('QBitFlow could not be read: see WooCommerce → Status → Logs (qbitflow).', 'qbitflow-for-woocommerce'), 'error'],
		];
		[$message, $type] = $messages[PaymentSync::refresh($order, __('a manual refresh', 'qbitflow-for-woocommerce'))];
		Notices::add($message, $type);

		return $order->get_edit_order_url();
	}

	/** The last failed attempt (code, reason, time in the site's timezone), from meta; escaped. */
	private static function last_attempt(WC_Order $order): string
	{
		$attempt = $order->get_meta(OrderMeta::LAST_ATTEMPT);
		if (! is_array($attempt) || $attempt === []) {
			return '';
		}
		$html = '';
		$code = (string) ($attempt['code'] ?? '');
		$message = (string) ($attempt['message'] ?? '');
		if ($code !== '') {
			$html .= self::code($code);
		}
		if ($message !== '') {
			$html .= ($html !== '' ? '<br>' : '') . esc_html($message);
		}
		$at = (int) ($attempt['at'] ?? 0);
		if ($at > 0) {
			$html .= ($html !== '' ? '<br>' : '') . '<small>' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $at)) . '</small>';
		}

		return $html;
	}

	private static function render_refunds(WC_Order $order): void
	{
		$refunds = OrderMeta::list($order, OrderMeta::REFUNDS);
		if ($refunds === []) {
			return;
		}
		echo '<hr><p style="margin:6px 0;font-weight:bold">' . esc_html__('Refunds', 'qbitflow-for-woocommerce') . '</p><ul style="margin:0">';
		foreach ($refunds as $refund) {
			$line = sprintf('%s — %s%%', self::refund_label((string) ($refund['status'] ?? '')), (string) ($refund['percent'] ?? ''));
			if (! empty($refund['amount'])) {
				$line .= ' (' . $refund['amount'] . ')';
			}
			echo '<li>' . esc_html($line);
			if (! empty($refund['tx_hash']) && ! empty($refund['explorer_url'])) {
				echo ' <a href="' . esc_url((string) $refund['explorer_url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('tx', 'qbitflow-for-woocommerce') . '</a>';
			}
			if (! empty($refund['merchant_message'])) {
				echo '<br><em>' . esc_html((string) $refund['merchant_message']) . '</em>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	private static function status_label(string $status): string
	{
		return match ($status) {
			'completed' => __('Completed', 'qbitflow-for-woocommerce'),
			'waitingConfirmation' => __('Confirming on-chain', 'qbitflow-for-woocommerce'),
			'created' => __('Awaiting payment', 'qbitflow-for-woocommerce'),
			'expired' => __('Expired', 'qbitflow-for-woocommerce'),
			default => ucfirst($status),
		};
	}

	private static function refund_label(string $status): string
	{
		return match ($status) {
			'pending' => __('Requested', 'qbitflow-for-woocommerce'),
			'approved' => __('Refunded', 'qbitflow-for-woocommerce'),
			'rejected' => __('Rejected', 'qbitflow-for-woocommerce'),
			default => ucfirst($status),
		};
	}

	private static function code(string $value): string
	{
		return $value === '' ? '' : '<code style="font-size:11px;word-break:break-all">' . esc_html($value) . '</code>';
	}
}
