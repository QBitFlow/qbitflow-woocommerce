<?php

declare(strict_types=1);

namespace QBitFlowWC\Frontend;

use QBitFlowWC\Gateway;
use QBitFlowWC\Orders\OrderMeta;
use QBitFlowWC\Orders\PaymentSync;
use WC_Order;

defined('ABSPATH') || exit;

/**
 * What the customer sees of a QBitFlow payment: the thank-you page (which also confirms the
 * payment with QBitFlow when the webhook hasn't yet), emails and My Account.
 */
final class OrderPages
{
	public static function init(): void
	{
		add_action('woocommerce_thankyou_' . Gateway::ID, [self::class, 'thankyou']);
		add_action('woocommerce_email_before_order_table', [self::class, 'email'], 10, 3);
		add_filter('woocommerce_my_account_my_orders_actions', [self::class, 'my_account_actions'], 10, 2);
	}

	/**
	 * Landing here proves nothing (anyone can open the URL): an unpaid order asks QBitFlow,
	 * once, whether its checkout was paid.
	 */
	public static function thankyou(int $orderId): void
	{
		$order = wc_get_order($orderId);
		if (! $order instanceof WC_Order || $order->get_payment_method() !== Gateway::ID) {
			return;
		}
		if (! $order->is_paid()) {
			PaymentSync::refresh($order, __('the return page', 'qbitflow-for-woocommerce'), 5.0);
			$order = wc_get_order($orderId);
		}

		$txHash = (string) $order->get_meta(OrderMeta::TX_HASH);
		$explorer = (string) $order->get_meta(OrderMeta::EXPLORER_URL);
		$management = (string) $order->get_meta(OrderMeta::MANAGEMENT_LINK);

		echo '<section class="qbitflow-thankyou" style="margin:20px 0;padding:20px;border:1px solid #e0e0e0;border-radius:8px">';
		if ($order->is_paid() && $txHash !== '') {
			echo '<h2>' . esc_html__('Crypto payment confirmed ✓', 'qbitflow-for-woocommerce') . '</h2>';
			echo '<p>' . esc_html__('Your payment has been confirmed on the blockchain.', 'qbitflow-for-woocommerce') . '</p>';
			echo '<p><strong>' . esc_html__('Transaction:', 'qbitflow-for-woocommerce') . '</strong> ';
			echo $explorer !== ''
				? '<a href="' . esc_url($explorer) . '" target="_blank" rel="noopener noreferrer"><code>' . esc_html($txHash) . '</code></a>'
				: '<code>' . esc_html($txHash) . '</code>';
			echo '</p>';
		} elseif (in_array($order->get_status(), ['pending', 'on-hold'], true)) {
			echo '<h2>' . esc_html__('Payment processing…', 'qbitflow-for-woocommerce') . '</h2>';
			echo '<p>' . esc_html__('Your crypto payment is being confirmed on the blockchain. You will receive an email once it is complete.', 'qbitflow-for-woocommerce') . '</p>';
		}
		if ($management !== '') {
			echo '<p><a class="button" href="' . esc_url($management) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('View payment details & invoice', 'qbitflow-for-woocommerce') . '</a></p>';
		}
		echo '</section>';
	}

	/** @param WC_Order $order */
	public static function email($order, bool $sentToAdmin, bool $plainText = false): void
	{
		if (! $order instanceof WC_Order || $order->get_payment_method() !== Gateway::ID) {
			return;
		}
		$txHash = (string) $order->get_meta(OrderMeta::TX_HASH);
		$management = (string) $order->get_meta(OrderMeta::MANAGEMENT_LINK);

		if ($plainText) {
			if ($txHash !== '') {
				echo "\n" . esc_html__('Transaction:', 'qbitflow-for-woocommerce') . ' ' . esc_html($txHash) . "\n";
			}
			if ($management !== '') {
				echo esc_html__('Payment details & invoice:', 'qbitflow-for-woocommerce') . ' ' . esc_url($management) . "\n";
			}

			return;
		}
		if ($txHash !== '') {
			echo '<p><strong>' . esc_html__('Crypto transaction:', 'qbitflow-for-woocommerce') . '</strong> <code>' . esc_html($txHash) . '</code></p>';
		}
		if ($management !== '') {
			echo '<p><a href="' . esc_url($management) . '" target="_blank" style="background:#2c2c2c;color:#fff;padding:8px 16px;border-radius:4px;text-decoration:none;display:inline-block">' .
				esc_html__('View payment details & invoice →', 'qbitflow-for-woocommerce') . '</a></p>';
		}
	}

	/**
	 * @param array<string, array{url: string, name: string}> $actions
	 * @return array<string, array{url: string, name: string}>
	 */
	public static function my_account_actions(array $actions, WC_Order $order): array
	{
		if ($order->get_payment_method() !== Gateway::ID) {
			return $actions;
		}
		$management = (string) $order->get_meta(OrderMeta::MANAGEMENT_LINK);
		if ($management !== '') {
			$actions['qbitflow_invoice'] = [
				'url' => $management,
				'name' => __('Invoice', 'qbitflow-for-woocommerce'),
			];
		}

		return $actions;
	}
}
