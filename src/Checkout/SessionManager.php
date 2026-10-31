<?php

declare(strict_types=1);

namespace QBitFlowWC\Checkout;

use QBitFlowWC\Admin\StatusPanel;
use QBitFlowWC\ClientFactory;
use QBitFlowWC\Currency;
use QBitFlowWC\Customer\CustomerResolver;
use QBitFlowWC\Gateway;
use QBitFlowWC\Log;
use QBitFlowWC\Orders\OrderMeta;
use QBitFlowWC\Orders\PaymentSync;
use QBitFlowWC\Settings;
use QBitFlowWC\Support\ApiErrors;
use QBitFlowWC\Support\Text;
use QBitFlowWC\Vendor\QBitFlow\Enums\CheckoutSessionStatusValue;
use QBitFlowWC\Vendor\QBitFlow\Exceptions\ConflictException;
use QBitFlowWC\Vendor\QBitFlow\Exceptions\NetworkException;
use QBitFlowWC\Vendor\QBitFlow\Exceptions\NotFoundException;
use QBitFlowWC\Vendor\QBitFlow\Exceptions\PermissionDeniedException;
use QBitFlowWC\Vendor\QBitFlow\Exceptions\QBitFlowException;
use QBitFlowWC\Vendor\QBitFlow\Exceptions\ServerException;
use QBitFlowWC\Vendor\QBitFlow\Exceptions\ValidationException;
use QBitFlowWC\Vendor\QBitFlow\Models\CheckoutSessionStatus;
use QBitFlowWC\Vendor\QBitFlow\Params\CreatePaymentSessionParams;
use QBitFlowWC\Vendor\QBitFlow\QBitFlow;
use QBitFlowWC\Vendor\QBitFlow\RequestOptions;
use WC_Order;

defined('ABSPATH') || exit;

/**
 * Opens the QBitFlow checkout for an order, at most one payable checkout per order.
 *
 * - The order's reference (`wc-<store>-<order>`) is unique while a checkout can be paid, so
 *   QBitFlow itself refuses a second payable checkout for the order.
 * - An open checkout for the same amounts is reused (back button, double click, a retry);
 *   changed amounts expire it first; a paid one completes the order instead.
 * - The create is idempotent per attempt: a request retried by the SDK or a double submit
 *   opens one checkout.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CheckoutException messages are built escaped (esc_html__) and only shown through wc_add_notice(); other exceptions are rethrown, never output.
final class SessionManager
{
	/** The attempt counter, part of the idempotency key (a new checkout after an expiry needs a new key). */
	private const ATTEMPT = '_qbitflow_attempt';

	/** Set for an hour once the organization turned off its members' return addresses. */
	public const REDIRECTS_OFF_TRANSIENT = 'qbitflow_wc_no_redirects';

	private const REDIRECTS_POLICY = 'members.checkoutRedirects';

	/** In the customer's WooCommerce session: the last order a cart checkout sent to a QBitFlow checkout. */
	private const OPEN_ORDER_KEY = 'qbitflow_wc_open_order';

	/** The order a cart checkout (classic or Store API) is placing in this request, if any. */
	private static ?int $cartCheckoutOrder = null;

	public static function init(): void
	{
		add_action('woocommerce_order_status_cancelled', [self::class, 'on_cancelled'], 10, 2);
		// Fired by the cart checkouts only (not by the order-pay page), before they ask the gateway to pay.
		add_action('woocommerce_checkout_order_processed', [self::class, 'placed_from_cart'], 10, 1);
		add_action('woocommerce_store_api_checkout_update_order_meta', [self::class, 'placed_from_cart'], 10, 1);
	}

	/** @param int|WC_Order $order The order the cart checkout is placing. */
	public static function placed_from_cart($order): void
	{
		$id = $order instanceof WC_Order ? $order->get_id() : absint($order);
		self::$cartCheckoutOrder = $id > 0 ? $id : null;
	}

	/**
	 * @return string Where to send the customer (the QBitFlow checkout, or the thank-you page
	 *                when the order turned out paid).
	 *
	 * @throws CheckoutException
	 */
	public static function start(WC_Order $order): string
	{
		$client = ClientFactory::make();
		if ($client === null) {
			throw new CheckoutException(self::unavailable());
		}
		$rate = Currency::usd_rate($order);
		if ($rate === null) {
			throw new CheckoutException(sprintf(
				/* translators: %s: currency code */
				esc_html__('Crypto payments are not available in %s.', 'qbitflow-for-woocommerce'),
				esc_html($order->get_currency())
			));
		}

		$processingFee = Settings::processing_fee(true);
		$amounts = OrderAmounts::fromOrder($order, Settings::itemize(), $rate);
		if ($amounts->totalCents <= 0) {
			throw new CheckoutException(esc_html__('This order has nothing to pay.', 'qbitflow-for-woocommerce'));
		}
		$fingerprint = substr(hash('sha256', $amounts->fingerprint() . '|' . ($processingFee === null ? 'acct' : ($processingFee ? 'fee' : 'nofee'))), 0, 16);

		try {
			self::supersede_previous($client, $order);
			$redirect = self::reuse($client, $order, $fingerprint)
				?? self::create($client, $order, $amounts, $processingFee, $fingerprint);
			self::remember($order);

			return $redirect;
		} catch (QBitFlowException $e) {
			throw self::translate($e, $order);
		}
	}

	/** Expires an order's open checkout (the order was cancelled), best effort. */
	public static function on_cancelled(int $orderId, ?WC_Order $order = null): void
	{
		$order ??= wc_get_order($orderId) ?: null;
		if (! $order instanceof WC_Order || $order->get_payment_method() !== Gateway::ID || $order->is_paid()) {
			return;
		}
		$uuid = (string) $order->get_meta(OrderMeta::SESSION_UUID);
		if ($uuid === '' || in_array($order->get_meta(OrderMeta::LAST_STATUS), ['completed', 'expired'], true)) {
			return;
		}
		$client = ClientFactory::make(10.0, 1);
		if ($client === null) {
			return;
		}
		try {
			$client->checkoutSessions->expire($uuid);
			$order->update_meta_data(OrderMeta::LAST_STATUS, 'expired');
			$order->save_meta_data();
			Log::info(sprintf('Order #%d cancelled: its QBitFlow checkout %s was expired.', $order->get_id(), $uuid));
		} catch (QBitFlowException $e) {
			// 409 tx_already_sent: the customer is paying; the payment will complete the order.
			Log::warning(sprintf('Order #%d cancelled, its QBitFlow checkout %s not expired: %s', $order->get_id(), $uuid, $e->getMessage()));
		}
	}

	/**
	 * WooCommerce resumes the customer's pending order only while the cart is unchanged: a changed
	 * cart (or total) is placed as a new order. When a cart checkout places such a new order, the
	 * previous cart order's open checkout is expired first, so the customer never has two payable
	 * checkouts; its checkout.expired leaves that order pending (WooCommerce's own handling of
	 * unpaid orders applies). Only between two orders placed from the cart by the same shopper
	 * (same account, or the same email for a guest): an order paid from its own page (My Account,
	 * an invoice) is the customer's choice and is never replaced.
	 *
	 * @throws CheckoutException  The previous order is being paid (never two payments at once).
	 * @throws QBitFlowException  QBitFlow could not expire it: no new checkout this time, the next
	 *                            attempt tries again.
	 */
	private static function supersede_previous(QBitFlow $client, WC_Order $order): void
	{
		$session = self::customer_session();
		if ($session === null || self::$cartCheckoutOrder !== $order->get_id()) {
			return;
		}
		$previousId = absint($session->get(self::OPEN_ORDER_KEY));
		if ($previousId === 0 || $previousId === $order->get_id()) {
			return;
		}
		$previous = wc_get_order($previousId);
		if (! $previous instanceof WC_Order || ! self::replaceable($previous, $order)) {
			$session->set(self::OPEN_ORDER_KEY, null);

			return;
		}
		$uuid = (string) $previous->get_meta(OrderMeta::SESSION_UUID);

		// Marked first, as for changed amounts: its checkout.expired must not fail it.
		$previous->update_meta_data(OrderMeta::SUPERSEDED, OrderMeta::bare_uuid($uuid));
		$previous->save_meta_data();
		try {
			$client->checkoutSessions->expire($uuid);
		} catch (NotFoundException) {
			// Gone at QBitFlow already: nothing left to pay.
		} catch (ConflictException $e) {
			if ($e->getApiCode() !== 'tx_already_sent') {
				throw $e;
			}
			$previous->delete_meta_data(OrderMeta::SUPERSEDED);
			$previous->save_meta_data();
			Log::info(sprintf('Order #%d not opened: the customer is paying their previous order #%d (QBitFlow checkout %s).', $order->get_id(), $previous->get_id(), $uuid));
			throw new CheckoutException(sprintf(
				/* translators: %s: the previous order's number */
				esc_html__('A crypto payment for your order #%s is being confirmed. Please wait for it to complete before placing a new order.', 'qbitflow-for-woocommerce'),
				esc_html($previous->get_order_number())
			), 0, $e);
		}
		$session->set(self::OPEN_ORDER_KEY, null);
		$previous->update_meta_data(OrderMeta::LAST_STATUS, 'expired');
		$previous->add_order_note(sprintf(
			/* translators: 1: QBitFlow checkout id, 2: the new order's number */
			__('This order\'s QBitFlow checkout %1$s was closed because the customer started a new order (#%2$s) from their cart.', 'qbitflow-for-woocommerce'),
			$uuid,
			$order->get_order_number()
		));
		$previous->save();
		Log::info(sprintf('Order #%d: its QBitFlow checkout %s was expired, the customer placed order #%d from their cart.', $previous->get_id(), $uuid, $order->get_id()));
	}

	/** Whether `$previous` is an unpaid cart order of the same shopper, with a checkout still open. */
	private static function replaceable(WC_Order $previous, WC_Order $order): bool
	{
		if ($previous->get_payment_method() !== Gateway::ID || $previous->is_paid() || $previous->get_status() !== 'pending'
			|| ! in_array($previous->get_created_via(), ['checkout', 'store-api'], true)
			|| $previous->get_meta(OrderMeta::LAST_STATUS) !== 'created' || (string) $previous->get_meta(OrderMeta::SESSION_UUID) === '') {
			return false;
		}
		if ($previous->get_customer_id() !== $order->get_customer_id()) {
			return false;
		}

		// Two guests in one browser are the same shopper only with the same email.
		return $order->get_customer_id() !== 0
			|| strtolower(trim($previous->get_billing_email())) === strtolower(trim($order->get_billing_email()));
	}

	/** A cart checkout's order now has the shopper's open checkout (none once it is paid). */
	private static function remember(WC_Order $order): void
	{
		$session = self::customer_session();
		if ($session === null || self::$cartCheckoutOrder !== $order->get_id()) {
			return;
		}
		$fresh = wc_get_order($order->get_id());
		$session->set(self::OPEN_ORDER_KEY, $fresh instanceof WC_Order && ! $fresh->is_paid() ? $fresh->get_id() : null);
	}

	/** The shopper's WooCommerce session, when the checkout runs in one (not in WP-CLI or cron). */
	private static function customer_session(): ?\WC_Session
	{
		$session = function_exists('WC') ? WC()->session : null;

		return $session instanceof \WC_Session ? $session : null;
	}

	/** @return string|null Where to go when the order's existing checkout settles it; null to open a new one. */
	private static function reuse(QBitFlow $client, WC_Order $order, string $fingerprint): ?string
	{
		$uuid = (string) $order->get_meta(OrderMeta::SESSION_UUID);
		if ($uuid === '') {
			return null;
		}
		try {
			$session = $client->checkoutSessions->get($uuid);
		} catch (NotFoundException) {
			return null;
		}
		$link = (string) $order->get_meta(OrderMeta::PAYMENT_LINK);

		switch ($session->status) {
			case CheckoutSessionStatusValue::COMPLETED:
				self::complete_quietly($order, $client->payments->get($uuid));

				return $order->get_checkout_order_received_url();
			case CheckoutSessionStatusValue::WAITING_CONFIRMATION:
				// A payment is on its way: never a second checkout. It only pays the amounts it was
				// opened for; if the order changed since, the customer must wait for it.
				if ($order->get_meta(OrderMeta::FINGERPRINT) !== $fingerprint) {
					throw new CheckoutException(self::in_flight());
				}

				return $link !== '' ? $link : $order->get_checkout_order_received_url();
			case CheckoutSessionStatusValue::CREATED:
				if ($link !== '' && $order->get_meta(OrderMeta::FINGERPRINT) === $fingerprint) {
					return $link;
				}

				return self::expire_open($client, $order, $session);
			default:
				return null;
		}
	}

	/**
	 * Expires an open checkout whose amounts changed; null once done. Paid or being paid
	 * meanwhile (`tx_already_sent`): it pays the old amounts only, so the customer waits for it
	 * (the order is checked against what it received when it completes).
	 */
	private static function expire_open(QBitFlow $client, WC_Order $order, CheckoutSessionStatus $session): ?string
	{
		// Marked first: its checkout.expired (or a check after a failed create) must not fail an
		// order whose customer is paying a new checkout.
		$order->update_meta_data(OrderMeta::SUPERSEDED, OrderMeta::bare_uuid($session->uuid));
		$order->save_meta_data();
		try {
			$client->checkoutSessions->expire($session->uuid);
			$order->update_meta_data(OrderMeta::LAST_STATUS, 'expired');
			Log::info(sprintf('Order #%d: its amounts changed, QBitFlow checkout %s expired.', $order->get_id(), $session->uuid));

			return null;
		} catch (ConflictException $e) {
			$order->delete_meta_data(OrderMeta::SUPERSEDED);
			$order->save_meta_data();
			if ($e->getApiCode() === 'tx_already_sent') {
				throw new CheckoutException(self::in_flight(), 0, $e);
			}
			throw $e;
		}
	}

	private static function in_flight(): string
	{
		return esc_html__('A crypto payment for this order is being confirmed. Please wait for it to complete before changing the order.', 'qbitflow-for-woocommerce');
	}

	private static function create(QBitFlow $client, WC_Order $order, OrderAmounts $amounts, ?bool $processingFee, string $fingerprint): string
	{
		$attempt = (int) $order->get_meta(self::ATTEMPT) + 1;
		$reference = OrderMeta::reference($order);
		$customer = CustomerResolver::resolve($order, $client);
		$key = $reference . '-' . $fingerprint . '-' . $attempt;
		$withUrls = ! self::redirects_off();
		// A body without return addresses never reuses the key of one with them.
		$params = self::params($order, $amounts, $processingFee, $customer, $withUrls);

		try {
			try {
				$created = $client->checkoutSessions->createPayment($params, new RequestOptions(idempotencyKey: $withUrls ? $key : $key . '-nr'));
			} catch (PermissionDeniedException $e) {
				if (! ApiErrors::policy_off($e, self::REDIRECTS_POLICY) || ! $params->successUrl) {
					throw $e;
				}
				// A member whose organization turned off return addresses: the same checkout
				// without them (customers land on the organization's page).
				Log::warning(sprintf('Order #%d: %s; opening the checkout without return addresses.', $order->get_id(), ApiErrors::describe($e)));
				$created = $client->checkoutSessions->createPayment(
					self::params($order, $amounts, $processingFee, $customer, false),
					new RequestOptions(idempotencyKey: $key . '-nr')
				);
				// Remembered only once the retry worked: a failing retry says nothing of the policy.
				set_transient(self::REDIRECTS_OFF_TRANSIENT, 1, HOUR_IN_SECONDS);
			}
		} catch (ConflictException $e) {
			$paid = self::paid_by_reference($client, $order, $e);
			if ($paid !== null) {
				return $paid;
			}
			throw $e;
		}

		$order->update_meta_data(self::ATTEMPT, $attempt);
		$order->delete_meta_data(OrderMeta::SUPERSEDED);
		$order->update_meta_data(OrderMeta::SESSION_UUID, $created->uuid);
		$order->update_meta_data(OrderMeta::PAYMENT_LINK, esc_url_raw($created->link));
		$order->update_meta_data(OrderMeta::FINGERPRINT, $fingerprint);
		$order->update_meta_data(OrderMeta::AMOUNT_USD, $amounts->totalUsd());
		$order->update_meta_data(OrderMeta::ORDER_TOTAL, (string) $order->get_total());
		$order->update_meta_data(OrderMeta::ORDER_CURRENCY, (string) $order->get_currency());
		$order->update_meta_data(OrderMeta::MODE, Settings::mode());
		$order->update_meta_data(OrderMeta::LAST_STATUS, 'created');
		$order->add_order_note(sprintf(
			/* translators: 1: amount in USD, 2: QBitFlow checkout id */
			__('Awaiting QBitFlow crypto payment of $%1$s (checkout %2$s).', 'qbitflow-for-woocommerce'),
			$amounts->totalUsd(),
			$created->uuid
		));
		$order->save();
		Log::info(sprintf('Order #%d: QBitFlow checkout %s opened for %s USD.', $order->get_id(), $created->uuid, $amounts->totalUsd()));

		return $created->link;
	}

	/** @param array<string, string> $customer */
	private static function params(WC_Order $order, OrderAmounts $amounts, ?bool $processingFee, array $customer, bool $withUrls): CreatePaymentSessionParams
	{
		return new CreatePaymentSessionParams(
			productName: self::product_name($order),
			description: self::description($order),
			priceUsd: $amounts->priceUsd(),
			reference: OrderMeta::reference($order),
			successUrl: $withUrls ? $order->get_checkout_order_received_url() : null,
			cancelUrl: $withUrls ? $order->get_cancel_order_url_raw() : null,
			customerUuid: $customer['customerUuid'] ?? null,
			customerReference: $customer['customerReference'] ?? null,
			fees: $amounts->toFees($processingFee),
			metadata: [
				'source' => 'woocommerce',
				'store_id' => Settings::store_id(),
				'order_id' => (string) $order->get_id(),
				'token' => OrderMeta::token($order),
			],
		);
	}

	/** True while QBitFlow said recently that return addresses are off for this key. */
	public static function redirects_off(): bool
	{
		return (bool) get_transient(self::REDIRECTS_OFF_TRANSIENT);
	}

	/**
	 * The reference is taken: a checkout for this order the plugin lost track of. If it was
	 * paid, complete the order; otherwise the customer must wait for it to expire.
	 */
	private static function paid_by_reference(QBitFlow $client, WC_Order $order, ConflictException $e): ?string
	{
		if (($e->getDetails()['field'] ?? '') !== 'reference' && ! str_contains($e->getMessage(), 'reference')) {
			return null;
		}
		$wait = new CheckoutException(esc_html__('A previous crypto checkout for this order is still open. Please wait a few minutes and try again, or contact the store.', 'qbitflow-for-woocommerce'), 0, $e);
		try {
			$payment = $client->payments->getByReference(OrderMeta::reference($order));
		} catch (NotFoundException) {
			throw $wait;
		}
		if (! OrderMeta::metadata_matches($order, $payment->metadata)) {
			// Paid for another order holding this reference (a copy of the site): not this order's.
			Log::error(sprintf('Order #%d: reference %s is held by payment %s made for another store or order.', $order->get_id(), OrderMeta::reference($order), $payment->uuid));
			throw $wait;
		}
		self::complete_quietly($order, $payment);

		return $order->get_checkout_order_received_url();
	}

	/** Completes the order unless another request (the webhook) is doing it right now. */
	private static function complete_quietly(WC_Order $order, \QBitFlowWC\Vendor\QBitFlow\Models\Payment $payment): void
	{
		try {
			PaymentSync::complete($order, $payment, __('the checkout', 'qbitflow-for-woocommerce'));
		} catch (\QBitFlowWC\Orders\OrderBusyException) {
			// Being completed by another request.
		}
	}

	/** The customer-facing message for an API error (the details go to the log). */
	private static function translate(QBitFlowException $e, WC_Order $order): CheckoutException
	{
		Log::error(sprintf('Order #%d: QBitFlow checkout not opened: %s', $order->get_id(), ApiErrors::describe($e)));

		if ($e instanceof ConflictException && $e->getApiCode() === 'merchant_not_ready') {
			set_transient(StatusPanel::NOT_READY_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS);

			return new CheckoutException(self::unavailable(), 0, $e);
		}
		if (ApiErrors::policy_off($e, StatusPanel::CHECKOUTS_POLICY)) {
			set_transient(StatusPanel::CHECKOUTS_OFF_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS);

			return new CheckoutException(self::unavailable(), 0, $e);
		}
		if ($e instanceof ValidationException) {
			$fields = array_map(static fn ($f): string => $f->field, $e->getFieldErrors());
			$max = (string) ($e->getDetails()['max'] ?? '');
			if (Settings::mode() === 'test' && $max === '5') {
				return new CheckoutException(esc_html__('This store is in QBitFlow test mode, where a checkout is at most $5. Use a smaller order to test.', 'qbitflow-for-woocommerce'), 0, $e);
			}
			if (array_intersect($fields, ['successUrl', 'cancelUrl']) !== [] && current_user_can('manage_woocommerce')) {
				return new CheckoutException(esc_html__('QBitFlow live mode needs the store to use HTTPS (its return addresses were refused).', 'qbitflow-for-woocommerce'), 0, $e);
			}
		}
		if ($e instanceof NetworkException || $e instanceof ServerException) {
			return new CheckoutException(esc_html__('The crypto payment service could not be reached. Please try again in a moment.', 'qbitflow-for-woocommerce'), 0, $e);
		}

		return new CheckoutException(esc_html__('Unable to start the crypto payment. Please try again or choose another payment method.', 'qbitflow-for-woocommerce'), 0, $e);
	}

	private static function unavailable(): string
	{
		return esc_html__('Crypto payments are not available right now. Please choose another payment method.', 'qbitflow-for-woocommerce');
	}

	/** The single item's name, or "Order #N" (2 to 100 characters). */
	private static function product_name(WC_Order $order): string
	{
		$items = $order->get_items();
		/* translators: %s: order number */
		$fallback = sprintf(__('Order #%s', 'qbitflow-for-woocommerce'), $order->get_order_number());
		if (count($items) !== 1) {
			return $fallback;
		}
		$name = self::one_line((string) reset($items)->get_name(), 100);

		return mb_strlen($name) >= 2 ? $name : $fallback;
	}

	/** "Item ×2, Other item" (at most 500 characters), or null when too short. */
	private static function description(WC_Order $order): ?string
	{
		$names = [];
		foreach ($order->get_items() as $item) {
			$qty = (int) $item->get_quantity();
			$names[] = self::one_line((string) $item->get_name(), 500) . ($qty > 1 ? " ×{$qty}" : '');
		}
		$description = Text::line(implode(', ', $names), 500);

		return mb_strlen($description) >= 2 ? $description : null;
	}

	/** Store text as one line the API accepts (its name and text rules). */
	private static function one_line(string $text, int $max): string
	{
		return Text::line(wp_strip_all_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')), $max);
	}
}
// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
