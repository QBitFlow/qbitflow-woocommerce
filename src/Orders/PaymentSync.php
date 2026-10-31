<?php

declare(strict_types=1);

namespace QBitFlowWC\Orders;

use QBitFlowWC\ClientFactory;
use QBitFlowWC\Currency;
use QBitFlowWC\Log;
use QBitFlowWC\Vendor\QBitFlow\Enums\CheckoutSessionStatusValue;
use QBitFlowWC\Vendor\QBitFlow\Exceptions\NotFoundException;
use QBitFlowWC\Vendor\QBitFlow\Exceptions\QBitFlowException;
use QBitFlowWC\Vendor\QBitFlow\Models\Payment;
use QBitFlowWC\Vendor\QBitFlow\Models\PaymentCompleted;
use WC_Order;

defined('ABSPATH') || exit;

/**
 * Brings a WooCommerce order in line with its QBitFlow checkout. Every entry point (webhook,
 * return page, background job, admin button) goes through here, and every method is
 * idempotent: a fact seen twice changes the order once.
 */
final class PaymentSync
{
	public const OPEN = 'open';

	public const COMPLETED = 'completed';

	public const EXPIRED = 'expired';

	public const UNKNOWN = 'unknown';

	/**
	 * Records a confirmed payment and marks the order paid. An amount below what the session
	 * charged puts the order on hold instead (never fulfilled on a mismatch).
	 *
	 * @param string $source Where the fact came from, for the order note (`webhook`, `return page`…).
	 *
	 * @return bool Whether the order changed.
	 *
	 * @throws OrderBusyException While another request updates the order.
	 */
	public static function complete(WC_Order $order, Payment $payment, string $source): bool
	{
		return OrderLock::run($order->get_id(), static function () use ($order, $payment, $source): bool {
			$fresh = wc_get_order($order->get_id());

			return $fresh instanceof WC_Order && self::complete_locked($fresh, $payment, $source);
		});
	}

	private static function complete_locked(WC_Order $order, Payment $payment, string $source): bool
	{
		if ($order->is_paid() || $order->get_status() === 'refunded') {
			$known = (string) $order->get_meta(OrderMeta::TX_HASH);
			if ($known !== '' && $known !== $payment->txHash && ! OrderMeta::is_current_session($order, $payment->uuid)) {
				$order->add_order_note(sprintf(
					/* translators: 1: payment id, 2: transaction hash */
					__('QBitFlow received a second payment for this already paid order (%1$s, tx %2$s). Refund it from the QBitFlow dashboard.', 'qbitflow-for-woocommerce'),
					$payment->uuid,
					$payment->txHash
				));
			} elseif (self::keep_management_link($order, $payment)) {
				$order->save_meta_data();
			}

			return false;
		}
		if ($order->get_meta(OrderMeta::LAST_STATUS) === 'completed' && $order->get_meta(OrderMeta::TX_HASH) === $payment->txHash) {
			// Recorded already and held for review (an amount mismatch).
			if (self::keep_management_link($order, $payment)) {
				$order->save_meta_data();
			}

			return false;
		}

		$order->update_meta_data(OrderMeta::SESSION_UUID, $payment->uuid);
		$order->update_meta_data(OrderMeta::LAST_STATUS, 'completed');
		$order->update_meta_data(OrderMeta::TX_HASH, $payment->txHash);
		$order->update_meta_data(OrderMeta::PAID_USD, $payment->amountUsd);
		$order->update_meta_data(OrderMeta::CURRENCY, $payment->currency->symbol . ' (' . $payment->currency->chain . ')');
		if ($payment->chain !== null) {
			$order->update_meta_data(OrderMeta::CHAIN, $payment->chain);
		}
		if ($payment->explorerUrl !== null) {
			$order->update_meta_data(OrderMeta::EXPLORER_URL, esc_url_raw($payment->explorerUrl));
		}
		if ($payment->customerUuid !== null) {
			$order->update_meta_data(OrderMeta::CUSTOMER_UUID, $payment->customerUuid);
		}
		self::keep_management_link($order, $payment);

		$processingFee = 0;
		foreach ($payment->fees as $line) {
			if ($line->type === 'processingFee') {
				$processingFee += self::cents($line->amountUsd);
			}
		}
		if ($processingFee > 0) {
			$order->update_meta_data(OrderMeta::PROCESSING_FEE_USD, self::usd($processingFee));
		}

		$received = self::cents($payment->amountUsd) - $processingFee;
		$expected = self::expected_cents($order);
		if ($expected === null || $received < $expected) {
			$order->update_meta_data(OrderMeta::AMOUNT_MISMATCH, '1');
			$order->update_status('on-hold', $expected === null
				? sprintf(
					/* translators: 1: amount received in USD, 2: transaction hash */
					__('QBitFlow payment received for $%1$s (tx %2$s), but the order\'s amount in USD could not be checked. Check it before fulfilling.', 'qbitflow-for-woocommerce'),
					self::usd($received),
					$payment->txHash
				)
				: sprintf(
					/* translators: 1: amount received in USD, 2: amount expected in USD, 3: transaction hash */
					__('QBitFlow payment received for $%1$s, but this order requires $%2$s (tx %3$s). Check it before fulfilling.', 'qbitflow-for-woocommerce'),
					self::usd($received),
					self::usd($expected),
					$payment->txHash
				));
			$order->save();
			Log::warning(sprintf('Order #%d: paid %s USD, expected %s USD; put on hold.', $order->get_id(), self::usd($received), $expected === null ? '?' : self::usd($expected)));

			return true;
		}

		$note = sprintf(
			/* translators: 1: amount and token paid, 2: USD value, 3: transaction hash, 4: where the confirmation came from */
			__('QBitFlow payment completed: %1$s (≈ $%2$s). Tx: %3$s. Confirmed by %4$s.', 'qbitflow-for-woocommerce'),
			$payment->amount . ' ' . $payment->currency->symbol,
			$payment->amountUsd,
			$payment->txHash,
			$source
		);
		if ($processingFee > 0) {
			$note .= ' ' . sprintf(
				/* translators: %s: processing fee in USD */
				__('The customer also paid QBitFlow\'s $%s processing fee.', 'qbitflow-for-woocommerce'),
				self::usd($processingFee)
			);
		}
		$order->add_order_note($note);
		$order->payment_complete($payment->txHash);
		Log::info(sprintf('Order #%d paid (%s, %s).', $order->get_id(), $payment->uuid, $source));

		return true;
	}

	/**
	 * The order's current checkout expired unpaid: the order fails (the customer can pay again
	 * from their account; a failed order holds no stock).
	 *
	 * @return bool Whether the order changed.
	 */
	public static function expire(WC_Order $order, string $sessionUuid, string $source): bool
	{
		return OrderLock::run($order->get_id(), static function () use ($order, $sessionUuid, $source): bool {
			$fresh = wc_get_order($order->get_id());

			return $fresh instanceof WC_Order && self::expire_locked($fresh, $sessionUuid, $source);
		});
	}

	private static function expire_locked(WC_Order $order, string $sessionUuid, string $source): bool
	{
		if (! OrderMeta::is_current_session($order, $sessionUuid) || $order->is_paid()) {
			return false;
		}
		if ($order->get_meta(OrderMeta::SUPERSEDED) === OrderMeta::bare_uuid($sessionUuid)) {
			return false; // Replaced by a new checkout for changed amounts: the customer is still paying.
		}
		if (! in_array($order->get_status(), ['pending', 'on-hold'], true)) {
			return false;
		}
		$order->update_meta_data(OrderMeta::LAST_STATUS, 'expired');
		$order->update_status('failed', sprintf(
			/* translators: %s: where the information came from */
			__('QBitFlow checkout expired unpaid (%s). The customer can pay again from their account.', 'qbitflow-for-woocommerce'),
			$source
		));

		return true;
	}

	/**
	 * The payment's page (receipt, invoice, refund request) is only in the webhook's payload: kept
	 * from it even when the return page or a check completed the order first.
	 *
	 * @return bool Whether the link was added (to save).
	 */
	private static function keep_management_link(WC_Order $order, Payment $payment): bool
	{
		if (! $payment instanceof PaymentCompleted || $payment->managementPageLink === null || (string) $order->get_meta(OrderMeta::MANAGEMENT_LINK) !== '') {
			return false;
		}
		$order->update_meta_data(OrderMeta::MANAGEMENT_LINK, esc_url_raw($payment->managementPageLink));

		return true;
	}

	/**
	 * Reads the order's checkout from QBitFlow and applies what it says.
	 *
	 * @return string One of the class constants.
	 */
	public static function refresh(WC_Order $order, string $source, float $timeout = 10.0): string
	{
		if ($order->is_paid() || $order->get_meta(OrderMeta::LAST_STATUS) === 'completed') {
			return self::COMPLETED;
		}
		$uuid = (string) $order->get_meta(OrderMeta::SESSION_UUID);
		$client = $uuid !== '' ? ClientFactory::make($timeout, $timeout < 10.0 ? 0 : 1) : null;
		if ($client === null) {
			return self::UNKNOWN;
		}

		try {
			$session = $client->checkoutSessions->get($uuid);
			switch ($session->status) {
				case CheckoutSessionStatusValue::COMPLETED:
					self::complete($order, $client->payments->get($uuid), $source);

					return self::COMPLETED;
				case CheckoutSessionStatusValue::EXPIRED:
					self::expire($order, $uuid, $source);

					return self::EXPIRED;
				case CheckoutSessionStatusValue::WAITING_CONFIRMATION:
					if ($order->get_meta(OrderMeta::LAST_STATUS) !== 'waitingConfirmation') {
						$order->update_meta_data(OrderMeta::LAST_STATUS, 'waitingConfirmation');
						$order->save();
					}

					return self::OPEN;
				default:
					self::note_failed_attempt($order, $session->lastAttempt);

					return self::OPEN;
			}
		} catch (NotFoundException) {
			// A session QBitFlow no longer keeps (long expired), or a payment not readable yet.
			return self::UNKNOWN;
		} catch (OrderBusyException) {
			// The webhook (or another check) is applying it right now.
			return self::UNKNOWN;
		} catch (QBitFlowException $e) {
			Log::warning(sprintf('Order #%d: QBitFlow checkout not read (%s).', $order->get_id(), $e->getMessage()));

			return self::UNKNOWN;
		}
	}

	/**
	 * A failed attempt is not final (the customer may retry until the checkout expires): a
	 * note, once per attempt, for the merchant.
	 */
	private static function note_failed_attempt(WC_Order $order, ?\QBitFlowWC\Vendor\QBitFlow\Models\Attempt $attempt): void
	{
		if ($attempt === null) {
			return;
		}
		$code = (string) ($attempt->code ?? '');
		$message = trim(wp_strip_all_tags((string) ($attempt->message ?? '')));
		$at = $attempt->at->getTimestamp();
		$key = $code . '@' . $at;
		if ($order->get_meta(OrderMeta::LAST_ATTEMPT_NOTED) === $key) {
			return;
		}
		$order->update_meta_data(OrderMeta::LAST_ATTEMPT_NOTED, $key);
		$order->update_meta_data(OrderMeta::LAST_ATTEMPT, ['code' => $code, 'message' => $message, 'at' => $at]);
		if ($message !== '' && $code !== '') {
			$reason = sprintf('%s (%s)', $message, $code);
		} else {
			$reason = $message !== '' ? $message : ($code !== '' ? '(' . $code . ')' : __('unknown reason', 'qbitflow-for-woocommerce'));
		}
		$order->add_order_note(sprintf(
			/* translators: %s: why the attempt failed, with its code */
			__('Crypto payment attempt failed: %s. The customer can try again until the checkout expires.', 'qbitflow-for-woocommerce'),
			$reason
		));
		$order->save();
	}

	/**
	 * What the payment must cover, in USD cents: the checkout's amount; more if the order's
	 * total grew since the checkout opened (WooCommerce reuses a pending order when the cart
	 * changes), in proportion; the current total at today's rate when no checkout amount was
	 * recorded or the order's currency changed since. Null when it can't be told (a currency
	 * without a rate): the order is then held, never paid.
	 */
	private static function expected_cents(WC_Order $order): ?int
	{
		$total = (float) $order->get_total();
		$stored = (string) $order->get_meta(OrderMeta::AMOUNT_USD);
		$currencyAtCheckout = (string) $order->get_meta(OrderMeta::ORDER_CURRENCY);
		$sameCurrency = $currencyAtCheckout === '' || $currencyAtCheckout === (string) $order->get_currency();

		if ($stored !== '' && $sameCurrency) {
			$expected = self::cents($stored);
			$atCheckout = (float) $order->get_meta(OrderMeta::ORDER_TOTAL);
			if ($atCheckout > 0 && $total > $atCheckout + 0.004) {
				$expected = (int) ceil($expected * $total / $atCheckout);
			}

			return $expected;
		}

		$rate = Currency::usd_rate($order);
		if ($rate === null) {
			return null;
		}
		$current = (int) round($total * $rate * 100);

		return $stored !== '' ? max(self::cents($stored), $current) : $current;
	}

	/** Cents from a USD decimal string ("10", "132.5", "5.0021"). */
	public static function cents(string $usd): int
	{
		return (int) round((float) $usd * 100);
	}

	private static function usd(int $cents): string
	{
		return number_format($cents / 100, 2, '.', '');
	}
}
