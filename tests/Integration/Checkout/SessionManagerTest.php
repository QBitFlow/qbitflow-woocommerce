<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration\Checkout;

use QBitFlowWC\Admin\StatusPanel;
use QBitFlowWC\Checkout\CheckoutException;
use QBitFlowWC\Checkout\SessionManager;
use QBitFlowWC\Customer\CustomerResolver;
use QBitFlowWC\Orders\OrderMeta;
use QBitFlowWC\Settings;
use QBitFlowWC\Tests\Integration\Support\Fixtures;
use QBitFlowWC\Tests\Integration\Support\TestCase;
use WC_Order;

final class SessionManagerTest extends TestCase
{
	private const SESSION = 'pay@019a0000-0000-7000-8000-0000000000a1';

	private const SESSION_2 = 'pay@019a0000-0000-7000-8000-0000000000a2';

	private const CUSTOMER = '019a0000-0000-7000-8000-0000000c0001';

	public function set_up(): void
	{
		parent::set_up();
		$this->originalSession = WC()->session;
		$this->api->on('GET', '#^/customers$#', 200, Fixtures::page([Fixtures::customer(self::CUSTOMER, CustomerResolver::reference('ada@example.com'))]));
	}

	/** @var \WC_Session|null WooCommerce's session before a test replaced it. */
	private $originalSession;

	public function tear_down(): void
	{
		WC()->session = $this->originalSession; // A customer session set by a test stays with that test.
		parent::tear_down();
	}

	private function itemized_order(): WC_Order
	{
		return $this->make_order([['name' => 'Blue T-shirt', 'price' => '2.00', 'qty' => 1]], [
			'shipping' => '1.00',
			'shipping_label' => 'Flat rate',
			'taxes' => [['label' => 'VAT (20%)', 'amount' => '0.60']],
		]);
	}

	public function test_creates_session_with_expected_body(): void
	{
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));
		$order = $this->itemized_order();

		$redirect = SessionManager::start($order);

		$this->assertSame('https://qbitflow.app/checkout?uuid=' . self::SESSION, $redirect);
		$request = $this->api->lastRequest('POST', '#^/checkout-sessions/payment$#');
		$body = $request['json'];
		$this->assertSame('Blue T-shirt', $body['productName']);
		$this->assertSame('Blue T-shirt', $body['description']);
		$this->assertSame('2.00', $body['priceUsd']);
		$this->assertSame([
			['label' => 'Flat rate', 'amountUsd' => '1.00'],
			['label' => 'VAT (20%)', 'amountUsd' => '0.60'],
		], $body['fees']['items']);
		$this->assertArrayNotHasKey('processingFee', $body['fees']);
		$reference = 'wc-' . Settings::store_id() . '-' . $order->get_id();
		$this->assertSame($reference, $body['reference']);
		$this->assertSame(['source' => 'woocommerce', 'store_id' => Settings::store_id(), 'order_id' => (string) $order->get_id(), 'token' => OrderMeta::token(wc_get_order($order->get_id()))], $body['metadata']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $body['metadata']['token']);
		$this->assertSame($order->get_checkout_order_received_url(), $body['successUrl']);
		$this->assertSame($order->get_cancel_order_url_raw(), $body['cancelUrl']);
		$this->assertSame(self::CUSTOMER, $body['customerUuid']);
		$this->assertMatchesRegularExpression('/^' . preg_quote($reference, '/') . '-[0-9a-f]{16}-1$/', $request['headers']['idempotency-key']);

		$order = wc_get_order($order->get_id());
		$this->assertSame(self::SESSION, $order->get_meta(OrderMeta::SESSION_UUID));
		$this->assertSame('3.60', $order->get_meta(OrderMeta::AMOUNT_USD));
		$this->assertSame('3.60', $order->get_meta(OrderMeta::ORDER_TOTAL));
		$this->assertSame('USD', $order->get_meta(OrderMeta::ORDER_CURRENCY));
		$this->assertSame('test', $order->get_meta(OrderMeta::MODE));
		$this->assertSame('created', $order->get_meta(OrderMeta::LAST_STATUS));
		$this->assertSame('pending', $order->get_status());
	}

	public function test_names_the_api_refuses_are_made_acceptable(): void
	{
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));
		$order = $this->make_order([['name' => '27" Monitor; [Blue]', 'price' => '2.00', 'qty' => 2]], [
			'shipping' => '1.00',
			'shipping_label' => 'Express | 24h',
			'fees' => [['name' => 'Gift wrap {deluxe}', 'amount' => '0.50']],
		]);

		SessionManager::start($order);

		$body = $this->api->lastRequest('POST', '#^/checkout-sessions/payment$#')['json'];
		$this->assertSame('27″ Monitor, (Blue)', $body['productName']);
		$this->assertSame('27″ Monitor, (Blue) ×2', $body['description']);
		$this->assertSame(['Express / 24h', 'Gift wrap (deluxe)'], array_column($body['fees']['items'], 'label'));
	}

	public function test_processing_fee_true_when_customer_pays(): void
	{
		$this->set_settings(['processing_fee' => 'customer']);
		$this->api->on('GET', '#^/me$#', 200, Fixtures::me());
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));

		SessionManager::start($this->itemized_order());

		$this->assertTrue($this->api->lastRequest('POST', '#^/checkout-sessions/payment$#')['json']['fees']['processingFee']);
	}

	public function test_processing_fee_false_when_store_pays(): void
	{
		$this->set_settings(['processing_fee' => 'store']);
		$this->api->on('GET', '#^/me$#', 200, Fixtures::me());
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));

		SessionManager::start($this->itemized_order());

		$this->assertFalse($this->api->lastRequest('POST', '#^/checkout-sessions/payment$#')['json']['fees']['processingFee']);
	}

	public function test_account_choice_never_asks_who_the_key_belongs_to(): void
	{
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));

		SessionManager::start($this->itemized_order());

		$this->assertCount(0, $this->api->requests('GET', '#^/me$#'));
	}

	public function test_member_key_omits_the_fee_whatever_was_saved_and_asks_once(): void
	{
		$this->set_settings(['processing_fee' => 'customer']);
		$this->api->on('GET', '#^/me$#', 200, Fixtures::me(true, true));
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION_2));

		SessionManager::start($this->itemized_order());
		SessionManager::start($this->itemized_order());

		foreach ($this->api->requests('POST', '#^/checkout-sessions/payment$#') as $request) {
			$this->assertArrayNotHasKey('processingFee', $request['json']['fees']);
		}
		$this->assertCount(1, $this->api->requests('GET', '#^/me$#'));
	}

	public function test_unknown_membership_with_a_failing_me_call_omits_the_fee(): void
	{
		$this->set_settings(['processing_fee' => 'customer']);
		add_filter('qbitflow_wc_client_options', static fn (array $o): array => ['maxRetries' => 0] + $o);
		$this->api->onError('GET', '#^/me$#', 503, 'unavailable', 'down');
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));

		SessionManager::start($this->itemized_order());

		$this->assertArrayNotHasKey('processingFee', $this->api->lastRequest('POST', '#^/checkout-sessions/payment$#')['json']['fees']);
	}

	public function test_account_mode_omits_the_fee_and_never_reads_the_account_settings(): void
	{
		$this->set_settings(['processing_fee' => 'account']);
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));

		SessionManager::start($this->itemized_order());

		$this->assertArrayNotHasKey('processingFee', $this->api->lastRequest('POST', '#^/checkout-sessions/payment$#')['json']['fees']);
		$this->assertCount(0, $this->api->requests('GET', '#^/settings#'));
	}

	public function test_changing_the_fee_choice_replaces_the_open_checkout(): void
	{
		$this->api->on('GET', '#^/me$#', 200, Fixtures::me());
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION_2));
		$this->api->on('GET', '#^/checkout-sessions/pay@#', 200, Fixtures::session_status(self::SESSION, 'created'));
		$this->api->on('POST', '#^/checkout-sessions/pay@.*/expire$#', 200, Fixtures::session_status(self::SESSION, 'expired'));
		$order = $this->itemized_order();
		SessionManager::start($order);

		$this->set_settings(['processing_fee' => 'customer']);
		$redirect = SessionManager::start(wc_get_order($order->get_id()));

		$this->assertStringEndsWith(self::SESSION_2, $redirect);
		$this->assertCount(1, $this->api->requests('POST', '#/expire$#'));
		$this->assertCount(2, $this->api->requests('POST', '#^/checkout-sessions/payment$#'));
	}

	public function test_not_itemized_sends_no_fee_items(): void
	{
		$this->set_settings(['itemize' => 'no']);
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));

		SessionManager::start($this->itemized_order());

		$body = $this->api->lastRequest('POST', '#^/checkout-sessions/payment$#')['json'];
		$this->assertSame('3.60', $body['priceUsd']);
		$this->assertArrayNotHasKey('items', $body['fees']);
	}

	public function test_multiple_items_named_after_the_order(): void
	{
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));
		$order = $this->make_order([['name' => 'Mug', 'price' => '1.00', 'qty' => 2], ['name' => 'Cap', 'price' => '1.50']]);

		SessionManager::start($order);

		$body = $this->api->lastRequest('POST', '#^/checkout-sessions/payment$#')['json'];
		$this->assertSame('Order #' . $order->get_order_number(), $body['productName']);
		$this->assertSame('Mug ×2, Cap', $body['description']);
	}

	public function test_second_call_reuses_open_session(): void
	{
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));
		$this->api->on('GET', '#^/checkout-sessions/pay@#', 200, Fixtures::session_status(self::SESSION, 'created'));
		$order = $this->itemized_order();

		$first = SessionManager::start($order);
		$second = SessionManager::start(wc_get_order($order->get_id()));

		$this->assertSame($first, $second);
		$this->assertCount(1, $this->api->requests('POST', '#^/checkout-sessions/payment$#'));
	}

	public function test_waiting_confirmation_returns_existing_link(): void
	{
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));
		$this->api->on('GET', '#^/checkout-sessions/pay@#', 200, Fixtures::session_status(self::SESSION, 'waitingConfirmation'));
		$order = $this->itemized_order();
		$first = SessionManager::start($order);

		$this->assertSame($first, SessionManager::start(wc_get_order($order->get_id())));
		$this->assertCount(0, $this->api->requests('POST', '#/expire$#'));
	}

	public function test_waiting_confirmation_with_changed_amounts_is_refused(): void
	{
		// The customer pays a small checkout, then grows the order (WooCommerce reuses the
		// pending order): the in-flight payment must not stand for the new amount.
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));
		$this->api->on('GET', '#^/checkout-sessions/pay@#', 200, Fixtures::session_status(self::SESSION, 'waitingConfirmation'));
		$order = $this->itemized_order();
		SessionManager::start($order);
		$order = wc_get_order($order->get_id());
		$order->set_total('100.00');
		$order->save();

		try {
			SessionManager::start($order);
			$this->fail('expected a CheckoutException');
		} catch (CheckoutException $e) {
			$this->assertStringContainsString('being confirmed', $e->getMessage());
		}
		$this->assertCount(1, $this->api->requests('POST', '#^/checkout-sessions/payment$#'));
		$this->assertCount(0, $this->api->requests('POST', '#/expire$#'));
	}

	public function test_changed_cart_expires_then_recreates(): void
	{
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION_2));
		$this->api->on('GET', '#^/checkout-sessions/pay@#', 200, Fixtures::session_status(self::SESSION, 'created'));
		$this->api->on('POST', '#^/checkout-sessions/pay@.*/expire$#', 200, Fixtures::session_status(self::SESSION, 'expired'));
		$order = $this->itemized_order();
		SessionManager::start($order);

		$order = wc_get_order($order->get_id());
		$order->set_total('4.60');
		$order->save();
		$redirect = SessionManager::start($order);

		$this->assertStringEndsWith(self::SESSION_2, $redirect);
		$this->assertStringContainsString(self::SESSION, $this->api->lastRequest('POST', '#/expire$#')['path']);
		$creates = $this->api->requests('POST', '#^/checkout-sessions/payment$#');
		$this->assertCount(2, $creates);
		$this->assertStringEndsWith('-2', $creates[1]['headers']['idempotency-key']);
		$this->assertSame(self::SESSION_2, wc_get_order($order->get_id())->get_meta(OrderMeta::SESSION_UUID));
	}

	public function test_replaced_checkout_expiring_does_not_fail_the_order(): void
	{
		// The cart changed: the old checkout is expired, then the new create fails. Its
		// checkout.expired (or a later check) must not fail an order the customer is still paying.
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));
		$this->api->onError('POST', '#^/checkout-sessions/payment$#', 503, 'unavailable', 'down');
		$this->api->on('GET', '#^/checkout-sessions/pay@#', 200, Fixtures::session_status(self::SESSION, 'created'));
		$this->api->on('POST', '#/expire$#', 200, Fixtures::session_status(self::SESSION, 'expired'));
		add_filter('qbitflow_wc_client_options', static fn (array $o): array => ['maxRetries' => 0] + $o);
		$order = $this->itemized_order();
		SessionManager::start($order);
		$order = wc_get_order($order->get_id());
		$order->set_total('9.99');
		$order->save();
		try {
			SessionManager::start($order);
		} catch (CheckoutException) {
		}

		$this->assertFalse(\QBitFlowWC\Orders\PaymentSync::expire(wc_get_order($order->get_id()), self::SESSION, 'webhook'));
		$this->assertSame('pending', wc_get_order($order->get_id())->get_status());
	}

	/** A shopper's own WooCommerce session (what checkouts run in). */
	private function customer_session(): void
	{
		WC()->session = new \WC_Session_Handler();
		WC()->session->init();
	}

	/** The order as the classic checkout places it from the cart, just before it asks the gateway to pay. */
	private function placed_from_cart(WC_Order $order, string $via = 'checkout'): WC_Order
	{
		$order->set_created_via($via);
		$order->save();
		if ($via === 'store-api') {
			do_action('woocommerce_store_api_checkout_update_order_meta', $order);
		} else {
			do_action('woocommerce_checkout_order_processed', $order->get_id(), [], $order);
		}

		return $order;
	}

	/** @return list<string> */
	private static function notes(WC_Order $order): array
	{
		return array_map(static fn ($n) => $n->content, wc_get_order_notes(['order_id' => $order->get_id()]));
	}

	public function test_a_new_order_after_a_cart_change_expires_the_previous_orders_checkout(): void
	{
		// WooCommerce resumes the customer's pending order only for an unchanged cart: a changed
		// cart is a new order. The previous order's checkout must not stay payable beside it.
		$this->customer_session();
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION_2));
		$this->api->on('POST', '#^/checkout-sessions/pay@.*/expire$#', 200, Fixtures::session_status(self::SESSION, 'expired'));
		$previous = $this->placed_from_cart($this->itemized_order());
		SessionManager::start($previous);

		$order = $this->placed_from_cart($this->make_order([['name' => 'Blue T-shirt', 'price' => '2.00'], ['name' => 'Cap', 'price' => '3.00']]), 'store-api');
		$redirect = SessionManager::start($order);

		$this->assertStringEndsWith(self::SESSION_2, $redirect);
		$expires = $this->api->requests('POST', '#/expire$#');
		$this->assertCount(1, $expires);
		$this->assertStringContainsString(self::SESSION, $expires[0]['path']);
		$previous = wc_get_order($previous->get_id());
		$this->assertSame('expired', $previous->get_meta(OrderMeta::LAST_STATUS));
		$this->assertContains(sprintf(
			'This order\'s QBitFlow checkout %s was closed because the customer started a new order (#%s) from their cart.',
			self::SESSION,
			$order->get_order_number()
		), self::notes($previous));
		// Its checkout.expired leaves it pending (WooCommerce's own unpaid-order handling applies).
		$this->assertFalse(\QBitFlowWC\Orders\PaymentSync::expire($previous, self::SESSION, 'webhook'));
		$this->assertSame('pending', wc_get_order($previous->get_id())->get_status());
	}

	public function test_the_same_order_again_expires_nothing(): void
	{
		$this->customer_session();
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));
		$this->api->on('GET', '#^/checkout-sessions/pay@#', 200, Fixtures::session_status(self::SESSION, 'created'));
		$order = $this->placed_from_cart($this->itemized_order());
		SessionManager::start($order);

		SessionManager::start($this->placed_from_cart(wc_get_order($order->get_id())));

		$this->assertCount(0, $this->api->requests('POST', '#/expire$#'));
	}

	public function test_paying_another_order_from_its_pay_page_leaves_the_previous_checkout_open(): void
	{
		// My Account → Pay on order A, then on order B: both are the customer's choice, not a changed cart.
		$this->customer_session();
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION_2));
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session('pay@019a0000-0000-7000-8000-0000000000a3'));
		$a = $this->placed_from_cart($this->itemized_order());
		SessionManager::start($a);
		$b = $this->make_order([['price' => '7.00']]);
		$b->set_created_via('checkout');
		$b->save();

		SessionManager::start($b); // The order-pay page: no cart checkout.
		$c = $this->make_order([['price' => '9.00']]);
		$c->set_created_via('checkout');
		$c->save();
		SessionManager::start($c);

		$this->assertCount(0, $this->api->requests('POST', '#/expire$#'));
		$this->assertSame('created', wc_get_order($a->get_id())->get_meta(OrderMeta::LAST_STATUS));
	}

	public function test_a_guest_with_another_email_leaves_the_previous_checkout_open(): void
	{
		$this->customer_session();
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION_2));
		$this->api->on('GET', '#^/customers$#', 200, Fixtures::page([]));
		$this->api->on('POST', '#^/customers$#', 201, Fixtures::customer(self::CUSTOMER, CustomerResolver::reference('grace@example.com')));
		$a = $this->placed_from_cart($this->itemized_order());
		SessionManager::start($a);

		SessionManager::start($this->placed_from_cart($this->make_order([['price' => '7.00']], ['email' => 'grace@example.com'])));

		$this->assertCount(0, $this->api->requests('POST', '#/expire$#'));
	}

	public function test_a_guest_with_the_same_email_in_another_case_is_the_same_shopper(): void
	{
		$this->customer_session();
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION_2));
		$this->api->on('POST', '#/expire$#', 200, Fixtures::session_status(self::SESSION, 'expired'));
		SessionManager::start($this->placed_from_cart($this->itemized_order()));

		SessionManager::start($this->placed_from_cart($this->make_order([['price' => '7.00']], ['email' => 'Ada@Example.com'])));

		$this->assertCount(1, $this->api->requests('POST', '#/expire$#'));
	}

	public function test_an_admin_created_order_paid_first_is_left_open(): void
	{
		// The store sent the customer an invoice (an admin-created order); they paid it from its
		// page, then checked out a cart.
		$this->customer_session();
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION_2));
		$invoice = $this->itemized_order();
		$invoice->set_created_via('admin');
		$invoice->save();
		SessionManager::start($invoice);
		// Even if a cart checkout had recorded it, an admin-created order is never replaced.
		WC()->session->set('qbitflow_wc_open_order', $invoice->get_id());

		SessionManager::start($this->placed_from_cart($this->make_order([['price' => '7.00']])));

		$this->assertCount(0, $this->api->requests('POST', '#/expire$#'));
		$this->assertSame('created', wc_get_order($invoice->get_id())->get_meta(OrderMeta::LAST_STATUS));
	}

	public function test_a_previous_order_being_paid_stops_the_new_checkout(): void
	{
		// Never two payments: the customer waits for the one being confirmed, as for one order.
		$this->customer_session();
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));
		$this->api->onError('POST', '#/expire$#', 409, 'tx_already_sent', 'A transaction was already sent');
		$previous = $this->placed_from_cart($this->itemized_order());
		SessionManager::start($previous);

		try {
			SessionManager::start($this->placed_from_cart($this->make_order([['price' => '7.00']])));
			$this->fail('expected a CheckoutException');
		} catch (CheckoutException $e) {
			$this->assertSame(sprintf('A crypto payment for your order #%s is being confirmed. Please wait for it to complete before placing a new order.', $previous->get_order_number()), $e->getMessage());
		}
		$this->assertCount(1, $this->api->requests('POST', '#^/checkout-sessions/payment$#'));
		$previous = wc_get_order($previous->get_id());
		$this->assertSame('created', $previous->get_meta(OrderMeta::LAST_STATUS));
		$this->assertSame('', $previous->get_meta(OrderMeta::SUPERSEDED));
	}

	public function test_qbitflow_unreachable_while_closing_the_previous_checkout_retries_on_the_next_attempt(): void
	{
		$this->customer_session();
		add_filter('qbitflow_wc_client_options', static fn (array $o): array => ['maxRetries' => 0] + $o);
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION_2));
		$this->api->onError('POST', '#/expire$#', 503, 'unavailable', 'down');
		$this->api->on('POST', '#/expire$#', 200, Fixtures::session_status(self::SESSION, 'expired'));
		$previous = $this->placed_from_cart($this->itemized_order());
		SessionManager::start($previous);
		$order = $this->placed_from_cart($this->make_order([['price' => '7.00']]));

		try {
			SessionManager::start($order);
			$this->fail('expected a CheckoutException');
		} catch (CheckoutException $e) {
			$this->assertStringContainsString('could not be reached', $e->getMessage());
		}
		$this->assertCount(1, $this->api->requests('POST', '#^/checkout-sessions/payment$#'), 'no second checkout while the first is open');
		$this->assertSame('created', wc_get_order($previous->get_id())->get_meta(OrderMeta::LAST_STATUS));

		$redirect = SessionManager::start($this->placed_from_cart(wc_get_order($order->get_id())));

		$this->assertStringEndsWith(self::SESSION_2, $redirect);
		$this->assertCount(2, $this->api->requests('POST', '#/expire$#'));
		$this->assertSame('expired', wc_get_order($previous->get_id())->get_meta(OrderMeta::LAST_STATUS));
	}

	public function test_a_paid_or_other_customers_previous_order_is_not_touched(): void
	{
		$this->customer_session();
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION_2));
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session('pay@019a0000-0000-7000-8000-0000000000a3'));
		$paid = $this->placed_from_cart($this->itemized_order());
		SessionManager::start($paid);
		$paid = wc_get_order($paid->get_id());
		$paid->payment_complete('0xabc');

		$other = $this->placed_from_cart($this->make_order([['price' => '7.00']]));
		SessionManager::start($other);
		$mine = $this->make_order([['price' => '8.00']]);
		$mine->set_customer_id(self::factory()->user->create());
		$mine->save();
		SessionManager::start($this->placed_from_cart($mine));

		$this->assertCount(0, $this->api->requests('POST', '#/expire$#'));
	}

	public function test_expired_session_gets_a_new_one_with_a_new_key(): void
	{
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION_2));
		$this->api->on('GET', '#^/checkout-sessions/pay@#', 200, Fixtures::session_status(self::SESSION, 'expired'));
		$order = $this->itemized_order();
		SessionManager::start($order);

		SessionManager::start(wc_get_order($order->get_id()));

		$keys = array_map(static fn ($r) => $r['headers']['idempotency-key'], $this->api->requests('POST', '#^/checkout-sessions/payment$#'));
		$this->assertCount(2, array_unique($keys));
	}

	public function test_changed_amounts_while_a_payment_was_sent_are_refused(): void
	{
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));
		$this->api->on('GET', '#^/checkout-sessions/pay@#', 200, Fixtures::session_status(self::SESSION, 'created'));
		$this->api->onError('POST', '#/expire$#', 409, 'tx_already_sent', 'A transaction was already sent');
		$order = $this->itemized_order();
		SessionManager::start($order);
		$order = wc_get_order($order->get_id());
		$order->set_total('9.99');
		$order->save();

		$this->expectExceptionMessage('being confirmed');
		SessionManager::start($order);
	}

	public function test_completed_session_completes_order_and_returns_thank_you(): void
	{
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));
		$this->api->on('GET', '#^/checkout-sessions/pay@#', 200, Fixtures::session_status(self::SESSION, 'completed'));
		$this->api->on('GET', '#^/payments/pay@#', 200, Fixtures::payment(self::SESSION, '3.60', '2.00'));
		$order = $this->itemized_order();
		SessionManager::start($order);

		$redirect = SessionManager::start(wc_get_order($order->get_id()));

		$this->assertSame(wc_get_order($order->get_id())->get_checkout_order_received_url(), $redirect);
		$this->assertTrue(wc_get_order($order->get_id())->is_paid());
	}

	public function test_reference_taken_by_a_paid_payment_completes_the_order(): void
	{
		$this->api->onError('POST', '#^/checkout-sessions/payment$#', 409, 'conflict', 'reference already used', ['field' => 'reference']);
		$order = $this->itemized_order();
		$this->api->on('GET', '#^/payments$#', 200, Fixtures::page([Fixtures::payment(self::SESSION, '3.60', '2.00', [], [
			'reference' => OrderMeta::reference($order),
			'metadata' => ['source' => 'woocommerce', 'store_id' => Settings::store_id(), 'order_id' => (string) $order->get_id(), 'token' => OrderMeta::token($order)],
		])]));

		$redirect = SessionManager::start($order);

		$this->assertSame($order->get_checkout_order_received_url(), $redirect);
		$this->assertTrue(wc_get_order($order->get_id())->is_paid());
	}

	public function test_reference_held_by_another_stores_payment_is_not_taken(): void
	{
		$this->api->onError('POST', '#^/checkout-sessions/payment$#', 409, 'conflict', 'reference already used', ['field' => 'reference']);
		$order = $this->itemized_order();
		$this->api->on('GET', '#^/payments$#', 200, Fixtures::page([Fixtures::payment(self::SESSION, '3.60', '2.00', [], [
			'reference' => OrderMeta::reference($order),
			'metadata' => ['source' => 'woocommerce', 'store_id' => Settings::store_id(), 'order_id' => (string) $order->get_id(), 'token' => str_repeat('0', 32)],
		])]));

		try {
			SessionManager::start($order);
			$this->fail('expected a CheckoutException');
		} catch (CheckoutException $e) {
			$this->assertStringContainsString('still open', $e->getMessage());
		}
		$this->assertFalse(wc_get_order($order->get_id())->is_paid());
	}

	public function test_reference_taken_by_an_open_checkout_asks_to_wait(): void
	{
		$this->api->onError('POST', '#^/checkout-sessions/payment$#', 409, 'conflict', 'reference already used', ['field' => 'reference']);
		$this->api->on('GET', '#^/payments$#', 200, Fixtures::page([]));

		$this->expectExceptionMessage('still open');
		SessionManager::start($this->itemized_order());
	}

	public function test_merchant_not_ready_message(): void
	{
		$this->api->onError('POST', '#^/checkout-sessions/payment$#', 409, 'merchant_not_ready', 'Merchant not ready', ['reason' => 'noWallet']);

		try {
			SessionManager::start($this->itemized_order());
			$this->fail('expected a CheckoutException');
		} catch (CheckoutException $e) {
			$this->assertStringContainsString('not available right now', $e->getMessage());
		}
		$this->assertTrue(StatusPanel::known_not_ready());
	}

	public function test_member_whose_checkouts_are_off_hides_the_gateway(): void
	{
		delete_transient(StatusPanel::CHECKOUTS_OFF_TRANSIENT);
		$this->policy_off('members.products');

		try {
			SessionManager::start($this->itemized_order());
			$this->fail('expected a CheckoutException');
		} catch (CheckoutException $e) {
			$this->assertStringContainsString('not available right now', $e->getMessage());
		}
		$this->assertCount(1, $this->api->requests('POST', self::PAY));
		$this->assertTrue(StatusPanel::known_not_ready());
		delete_transient(StatusPanel::CHECKOUTS_OFF_TRANSIENT);
	}

	public function test_changing_the_api_key_forgets_checkouts_off(): void
	{
		set_transient(StatusPanel::CHECKOUTS_OFF_TRANSIENT, 1, HOUR_IN_SECONDS);
		$gateway = WC()->payment_gateways()->payment_gateways()['qbitflow'];

		$_POST['woocommerce_qbitflow_api_key'] = self::LIVE_KEY;
		$gateway->process_admin_options();
		unset($_POST['woocommerce_qbitflow_api_key']);

		$this->assertFalse(StatusPanel::checkouts_off());
	}

	public function test_test_mode_cap_message(): void
	{
		$this->api->onError('POST', '#^/checkout-sessions/payment$#', 400, 'validation_failed', 'validation failed', ['max' => '5', 'errors' => [['field' => 'priceUsd', 'message' => 'too large']]]);

		$this->expectExceptionMessage('at most $5');
		SessionManager::start($this->itemized_order());
	}

	private const PAY = '#^/checkout-sessions/payment$#';

	private function policy_off(string $policy = 'members.checkoutRedirects'): void
	{
		$this->api->onError('POST', self::PAY, 403, 'policy_disabled', 'policy disabled', ['policy' => $policy, 'field' => 'successUrl']);
	}

	public function test_redirects_policy_off_retries_without_urls(): void
	{
		delete_transient(SessionManager::REDIRECTS_OFF_TRANSIENT);
		$this->policy_off();
		$this->api->on('POST', self::PAY, 201, self::created_session(self::SESSION));
		$order = $this->itemized_order();

		$redirect = SessionManager::start($order);

		$this->assertSame('https://qbitflow.app/checkout?uuid=' . self::SESSION, $redirect);
		$posts = $this->api->requests('POST', self::PAY);
		$this->assertCount(2, $posts);
		$this->assertArrayHasKey('successUrl', $posts[0]['json']);
		$this->assertArrayNotHasKey('successUrl', $posts[1]['json']);
		$this->assertArrayNotHasKey('cancelUrl', $posts[1]['json']);
		$this->assertMatchesRegularExpression('/-1-nr$/', $posts[1]['headers']['idempotency-key']);
		$this->assertStringEndsNotWith('-nr', $posts[0]['headers']['idempotency-key']);
		$this->assertNotFalse(get_transient(SessionManager::REDIRECTS_OFF_TRANSIENT));
		$this->assertSame(self::SESSION, wc_get_order($order->get_id())->get_meta(OrderMeta::SESSION_UUID));
		delete_transient(SessionManager::REDIRECTS_OFF_TRANSIENT);
	}

	public function test_known_redirects_off_sends_no_urls_at_once(): void
	{
		set_transient(SessionManager::REDIRECTS_OFF_TRANSIENT, 1, HOUR_IN_SECONDS);
		$this->api->on('POST', self::PAY, 201, self::created_session(self::SESSION));

		SessionManager::start($this->itemized_order());

		$posts = $this->api->requests('POST', self::PAY);
		$this->assertCount(1, $posts);
		$this->assertArrayNotHasKey('successUrl', $posts[0]['json']);
		$this->assertArrayNotHasKey('cancelUrl', $posts[0]['json']);
		$this->assertMatchesRegularExpression('/-1-nr$/', $posts[0]['headers']['idempotency-key']);
		delete_transient(SessionManager::REDIRECTS_OFF_TRANSIENT);
	}

	public function test_a_failed_retry_does_not_remember_redirects_off(): void
	{
		delete_transient(SessionManager::REDIRECTS_OFF_TRANSIENT);
		$this->policy_off();
		$this->api->onError('POST', self::PAY, 403, 'forbidden', 'no');

		try {
			SessionManager::start($this->itemized_order());
			$this->fail('expected a CheckoutException');
		} catch (CheckoutException) {
			$this->assertFalse(get_transient(SessionManager::REDIRECTS_OFF_TRANSIENT));
		}
	}

	public function test_changing_the_api_key_forgets_redirects_off(): void
	{
		set_transient(SessionManager::REDIRECTS_OFF_TRANSIENT, 1, HOUR_IN_SECONDS);
		$gateway = WC()->payment_gateways()->payment_gateways()['qbitflow'];

		$_POST['woocommerce_qbitflow_api_key'] = self::LIVE_KEY;
		$gateway->process_admin_options();
		unset($_POST['woocommerce_qbitflow_api_key']);

		$this->assertFalse(get_transient(SessionManager::REDIRECTS_OFF_TRANSIENT));
	}

	public function test_another_policy_is_not_retried(): void
	{
		delete_transient(SessionManager::REDIRECTS_OFF_TRANSIENT);
		$this->policy_off('members.something');

		try {
			SessionManager::start($this->itemized_order());
			$this->fail('expected a CheckoutException');
		} catch (CheckoutException $e) {
			$this->assertStringContainsString('Unable to start the crypto payment', $e->getMessage());
		}
		$this->assertCount(1, $this->api->requests('POST', self::PAY));
		$this->assertFalse(get_transient(SessionManager::REDIRECTS_OFF_TRANSIENT));
	}

	public function test_failed_retry_is_not_retried_again(): void
	{
		delete_transient(SessionManager::REDIRECTS_OFF_TRANSIENT);
		$this->policy_off();
		$this->api->onError('POST', self::PAY, 403, 'forbidden', 'no');

		try {
			SessionManager::start($this->itemized_order());
			$this->fail('expected a CheckoutException');
		} catch (CheckoutException $e) {
			$this->assertStringContainsString('Unable to start the crypto payment', $e->getMessage());
		}
		$this->assertCount(2, $this->api->requests('POST', self::PAY));
		delete_transient(SessionManager::REDIRECTS_OFF_TRANSIENT);
	}

	public function test_log_line_carries_every_field_and_reason(): void
	{
		$lines = [];
		add_filter('woocommerce_logger_log_message', static function ($message) use (&$lines) {
			$lines[] = (string) $message;

			return $message;
		});
		$this->api->onError('POST', self::PAY, 400, 'validation_failed', 'validation failed', ['errors' => [
			['field' => 'productName', 'message' => 'too short', 'reason' => 'minLength'],
			['field' => 'description', 'message' => 'bad characters', 'reason' => 'pattern'],
		]]);

		try {
			SessionManager::start($this->itemized_order());
		} catch (CheckoutException) {
		}

		$log = implode("\n", $lines);
		$this->assertStringContainsString('productName: too short [minLength]', $log);
		$this->assertStringContainsString('description: bad characters [pattern]', $log);
	}

	public function test_https_hint_survives_other_failing_fields(): void
	{
		$this->api->onError('POST', self::PAY, 400, 'validation_failed', 'validation failed', ['errors' => [
			['field' => 'priceUsd', 'message' => 'bad'],
			['field' => 'successUrl', 'message' => 'must be https'],
		]]);
		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

		$this->expectExceptionMessage('needs the store to use HTTPS');
		SessionManager::start($this->itemized_order());
	}

	public function test_test_cap_hint_survives_other_failing_fields(): void
	{
		$this->api->onError('POST', self::PAY, 400, 'validation_failed', 'validation failed', ['max' => '5', 'errors' => [
			['field' => 'fees', 'message' => 'too large'],
		]]);

		$this->expectExceptionMessage('at most $5');
		SessionManager::start($this->itemized_order());
	}

	public function test_api_down_message(): void
	{
		$this->api->onError('POST', '#^/checkout-sessions/payment$#', 503, 'unavailable', 'down');
		add_filter('qbitflow_wc_client_options', static fn (array $o): array => ['maxRetries' => 0] + $o);

		$this->expectExceptionMessage('could not be reached');
		SessionManager::start($this->itemized_order());
	}

	public function test_non_usd_order_without_rate_is_refused_without_calling(): void
	{
		$order = $this->make_order([['price' => '2.00']], ['currency' => 'EUR']);

		try {
			SessionManager::start($order);
			$this->fail('expected a CheckoutException');
		} catch (CheckoutException $e) {
			$this->assertStringContainsString('EUR', $e->getMessage());
		}
		$this->assertSame([], $this->api->requests());
	}

	public function test_non_usd_order_converted_at_the_filter_rate(): void
	{
		add_filter('qbitflow_wc_usd_rate', static fn ($rate, string $currency) => $currency === 'EUR' ? 1.10 : $rate, 10, 2);
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));

		SessionManager::start($this->make_order([['price' => '3.00']], ['currency' => 'EUR']));

		$this->assertSame('3.30', $this->api->lastRequest('POST', '#^/checkout-sessions/payment$#')['json']['priceUsd']);
	}

	public function test_order_cancel_expires_open_session(): void
	{
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));
		$this->api->on('POST', '#/expire$#', 200, Fixtures::session_status(self::SESSION, 'expired'));
		$order = $this->itemized_order();
		SessionManager::start($order);

		wc_get_order($order->get_id())->update_status('cancelled');

		$this->assertCount(1, $this->api->requests('POST', '#/expire$#'));
		$this->assertSame('expired', wc_get_order($order->get_id())->get_meta(OrderMeta::LAST_STATUS));
	}

	public function test_process_payment_returns_redirect_or_failure(): void
	{
		$gateway = WC()->payment_gateways()->payment_gateways()['qbitflow'];
		$this->api->on('POST', '#^/checkout-sessions/payment$#', 201, self::created_session(self::SESSION));

		$this->assertSame(
			['result' => 'success', 'redirect' => 'https://qbitflow.app/checkout?uuid=' . self::SESSION],
			$gateway->process_payment($this->itemized_order()->get_id())
		);

		$this->api->onError('POST', '#^/checkout-sessions/payment$#', 409, 'merchant_not_ready', 'Merchant not ready');
		$this->assertSame(['result' => 'failure'], $gateway->process_payment($this->itemized_order()->get_id()));
		$this->assertNotEmpty(wc_get_notices('error'));
		wc_clear_notices();
	}
}
