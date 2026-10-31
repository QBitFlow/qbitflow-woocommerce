<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration\Orders;

use QBitFlowWC\Orders\OrderMeta;
use QBitFlowWC\Orders\PaymentSync;
use QBitFlowWC\Tests\Integration\Support\Fixtures;
use QBitFlowWC\Tests\Integration\Support\TestCase;
use QBitFlowWC\Vendor\QBitFlow\Models\Payment;
use QBitFlowWC\Vendor\QBitFlow\Models\PaymentCompleted;
use WC_Order;

final class PaymentSyncTest extends TestCase
{
	private const SESSION = 'pay@019a0000-0000-7000-8000-0000000000a1';

	private function order_with_session(string $amountUsd = '10.00'): WC_Order
	{
		$order = $this->make_order([['price' => $amountUsd]]);
		$order->update_meta_data(OrderMeta::SESSION_UUID, self::SESSION);
		$order->update_meta_data(OrderMeta::AMOUNT_USD, $amountUsd);
		$order->save();

		return $order;
	}

	private static function completed(string $amountUsd = '10', array $fees = [], array $overrides = []): PaymentCompleted
	{
		return PaymentCompleted::fromArray(Fixtures::payment(self::SESSION, $amountUsd, $amountUsd, $fees, $overrides));
	}

	public function test_complete_marks_processing_with_tx_hash(): void
	{
		$order = $this->order_with_session();

		$this->assertTrue(PaymentSync::complete($order, self::completed(), 'webhook'));

		$order = wc_get_order($order->get_id());
		$this->assertSame('processing', $order->get_status());
		$this->assertTrue($order->is_paid());
		$hash = '0x1111111111111111111111111111111111111111111111111111111111111111';
		$this->assertSame($hash, $order->get_transaction_id());
		$this->assertSame($hash, $order->get_meta(OrderMeta::TX_HASH));
		$this->assertSame('BASE', $order->get_meta(OrderMeta::CHAIN));
		$this->assertStringStartsWith('https://', $order->get_meta(OrderMeta::EXPLORER_URL));
		$this->assertStringContainsString('manage?uuid=', $order->get_meta(OrderMeta::MANAGEMENT_LINK));
		$this->assertSame('USDC (BASE)', $order->get_meta(OrderMeta::CURRENCY));
		$this->assertSame('10', $order->get_meta(OrderMeta::PAID_USD));
		$this->assertSame('completed', $order->get_meta(OrderMeta::LAST_STATUS));
	}

	public function test_complete_is_idempotent(): void
	{
		$order = $this->order_with_session();
		PaymentSync::complete($order, self::completed(), 'webhook');
		$notes = count(wc_get_order_notes(['order_id' => $order->get_id()]));

		$this->assertFalse(PaymentSync::complete(wc_get_order($order->get_id()), self::completed(), 'return page'));
		$this->assertCount($notes, wc_get_order_notes(['order_id' => $order->get_id()]));
	}

	public function test_webhook_after_the_return_page_adds_the_payment_page(): void
	{
		$order = $this->order_with_session();
		$read = Payment::fromArray(Fixtures::payment(self::SESSION, '10', '10'));
		PaymentSync::complete($order, $read, 'return page');
		$this->assertSame('', wc_get_order($order->get_id())->get_meta(OrderMeta::MANAGEMENT_LINK));
		$notes = count(wc_get_order_notes(['order_id' => $order->get_id()]));

		$this->assertFalse(PaymentSync::complete(wc_get_order($order->get_id()), self::completed(), 'webhook'));

		$order = wc_get_order($order->get_id());
		$this->assertStringContainsString('manage?uuid=', $order->get_meta(OrderMeta::MANAGEMENT_LINK));
		$this->assertCount($notes, wc_get_order_notes(['order_id' => $order->get_id()]));
	}

	public function test_webhook_after_a_held_mismatch_adds_the_payment_page(): void
	{
		$order = $this->order_with_session('132.50');
		PaymentSync::complete($order, Payment::fromArray(Fixtures::payment(self::SESSION, '100.00', '100.00')), 'return page');

		$this->assertFalse(PaymentSync::complete(wc_get_order($order->get_id()), self::completed('100.00'), 'webhook'));

		$order = wc_get_order($order->get_id());
		$this->assertSame('on-hold', $order->get_status());
		$this->assertStringContainsString('manage?uuid=', $order->get_meta(OrderMeta::MANAGEMENT_LINK));
	}

	public function test_amount_mismatch_goes_on_hold(): void
	{
		$order = $this->order_with_session('132.50');

		$this->assertTrue(PaymentSync::complete($order, self::completed('100.00'), 'webhook'));

		$order = wc_get_order($order->get_id());
		$this->assertSame('on-hold', $order->get_status());
		$this->assertFalse($order->is_paid());
		$this->assertSame('1', $order->get_meta(OrderMeta::AMOUNT_MISMATCH));
		$this->assertStringContainsString('$100.00', wc_get_order_notes(['order_id' => $order->get_id(), 'limit' => 1])[0]->content);
	}

	public function test_order_total_grown_since_checkout_goes_on_hold(): void
	{
		$order = $this->order_with_session('10.00');
		$order->update_meta_data(OrderMeta::ORDER_TOTAL, '10.00');
		$order->set_total('100.00');
		$order->save();

		PaymentSync::complete($order, self::completed('10.00'), 'webhook');

		$order = wc_get_order($order->get_id());
		$this->assertSame('on-hold', $order->get_status());
		$this->assertFalse($order->is_paid());
		$this->assertStringContainsString('requires $100.00', wc_get_order_notes(['order_id' => $order->get_id(), 'limit' => 1])[0]->content);
	}

	public function test_currency_changed_since_checkout_requires_current_total_at_rate(): void
	{
		// Opened as JPY 1500 (≈ $10), then switched to USD 15 by a multi-currency plugin.
		$order = $this->order_with_session('10.00');
		$order->update_meta_data(OrderMeta::ORDER_TOTAL, '1500');
		$order->update_meta_data(OrderMeta::ORDER_CURRENCY, 'JPY');
		$order->set_total('15.00');
		$order->save();

		PaymentSync::complete($order, self::completed('10.00'), 'webhook');

		$this->assertSame('on-hold', wc_get_order($order->get_id())->get_status());
	}

	public function test_currency_changed_to_one_without_rate_goes_on_hold(): void
	{
		$order = $this->order_with_session('10.00');
		$order->update_meta_data(OrderMeta::ORDER_CURRENCY, 'USD');
		$order->set_currency('EUR');
		$order->save();

		PaymentSync::complete($order, self::completed('10.00'), 'webhook');

		$this->assertSame('on-hold', wc_get_order($order->get_id())->get_status());
	}

	public function test_without_stored_amount_checks_the_current_total(): void
	{
		$short = $this->make_order([['price' => '10.00']]);
		$short->update_meta_data(OrderMeta::SESSION_UUID, self::SESSION);
		$short->save();
		PaymentSync::complete($short, self::completed('5.00'), 'checkout');
		$this->assertSame('on-hold', wc_get_order($short->get_id())->get_status());

		$exact = $this->make_order([['price' => '10.00']]);
		PaymentSync::complete($exact, self::completed('10'), 'checkout');
		$this->assertSame('processing', wc_get_order($exact->get_id())->get_status());
	}

	public function test_without_stored_amount_or_rate_goes_on_hold(): void
	{
		$order = $this->make_order([['price' => '10.00']], ['currency' => 'EUR']);

		PaymentSync::complete($order, self::completed('50'), 'checkout');

		$this->assertSame('on-hold', wc_get_order($order->get_id())->get_status());
	}

	public function test_amounts_written_differently_still_match(): void
	{
		$order = $this->order_with_session('132.50');

		PaymentSync::complete($order, self::completed('132.5'), 'webhook');

		$this->assertSame('processing', wc_get_order($order->get_id())->get_status());
	}

	public function test_processing_fee_recorded_when_paid_more(): void
	{
		$order = $this->order_with_session('120.00');
		$fees = [
			['type' => 'custom', 'label' => 'VAT (20%)', 'amountUsd' => '20.00'],
			['type' => 'processingFee', 'label' => 'Processing fee', 'amountUsd' => '1.20'],
		];
		$payment = PaymentCompleted::fromArray(Fixtures::payment(self::SESSION, '121.20', '100.00', $fees));

		PaymentSync::complete($order, $payment, 'webhook');

		$order = wc_get_order($order->get_id());
		$this->assertSame('processing', $order->get_status());
		$this->assertSame('1.20', $order->get_meta(OrderMeta::PROCESSING_FEE_USD));
		$this->assertSame('120.00', $order->get_total());
		$notes = implode("\n", wc_list_pluck(wc_get_order_notes(['order_id' => $order->get_id()]), 'content'));
		$this->assertStringContainsString('$1.20 processing fee', $notes);
	}

	public function test_second_payment_from_another_session_is_flagged(): void
	{
		$order = $this->order_with_session();
		PaymentSync::complete($order, self::completed(), 'webhook');
		$other = Payment::fromArray(Fixtures::payment('pay@019a0000-0000-7000-8000-0000000000b2', '10', '10', [], ['txHash' => '0x2222']));

		$this->assertFalse(PaymentSync::complete(wc_get_order($order->get_id()), $other, 'webhook'));

		$this->assertStringContainsString('second payment', wc_get_order_notes(['order_id' => $order->get_id(), 'limit' => 1])[0]->content);
	}

	public function test_expire_only_current_session_and_unpaid(): void
	{
		$order = $this->order_with_session();

		$this->assertFalse(PaymentSync::expire($order, 'pay@019a0000-0000-7000-8000-0000000000ff', 'webhook'));
		$this->assertSame('pending', wc_get_order($order->get_id())->get_status());

		$this->assertTrue(PaymentSync::expire($order, '019a0000-0000-7000-8000-0000000000a1', 'webhook'));
		$this->assertSame('failed', wc_get_order($order->get_id())->get_status());

		$this->assertFalse(PaymentSync::expire(wc_get_order($order->get_id()), self::SESSION, 'webhook'));
	}

	public function test_expire_never_touches_a_paid_order(): void
	{
		$order = $this->order_with_session();
		PaymentSync::complete($order, self::completed(), 'webhook');

		$this->assertFalse(PaymentSync::expire(wc_get_order($order->get_id()), self::SESSION, 'webhook'));
		$this->assertSame('processing', wc_get_order($order->get_id())->get_status());
	}

	private function hold_lock(WC_Order $order, int $since): void
	{
		global $wpdb;
		$wpdb->insert($wpdb->options, ['option_name' => 'qbitflow_wc_lock_' . $order->get_id(), 'option_value' => (string) $since, 'autoload' => 'no']);
	}

	public function test_complete_refuses_while_another_request_holds_the_order(): void
	{
		$order = $this->order_with_session();
		$this->hold_lock($order, time());

		try {
			PaymentSync::complete($order, self::completed(), 'return page');
			$this->fail('expected an OrderBusyException');
		} catch (\QBitFlowWC\Orders\OrderBusyException) {
		}
		$this->assertFalse(wc_get_order($order->get_id())->is_paid());
	}

	public function test_stale_lock_is_taken_over_and_released(): void
	{
		global $wpdb;
		$order = $this->order_with_session();
		$this->hold_lock($order, time() - 120);

		$this->assertTrue(PaymentSync::complete($order, self::completed(), 'webhook'));

		$this->assertTrue(wc_get_order($order->get_id())->is_paid());
		$this->assertNull($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'qbitflow_wc_lock_' . $order->get_id())));
	}

	public function test_complete_rechecks_a_fresh_copy_of_the_order(): void
	{
		// Two requests loaded the order before either completed it: the second sees it paid.
		$order = $this->order_with_session();
		$stale = wc_get_order($order->get_id());
		PaymentSync::complete($order, self::completed(), 'webhook');

		$this->assertFalse(PaymentSync::complete($stale, self::completed(), 'return page'));
		$notes = array_filter(wc_get_order_notes(['order_id' => $order->get_id()]), static fn ($n) => str_contains($n->content, 'QBitFlow payment completed'));
		$this->assertCount(1, $notes);
	}

	public function test_refresh_while_busy_is_unknown(): void
	{
		$order = $this->order_with_session();
		$this->hold_lock($order, time());
		$this->api->on('GET', '#^/checkout-sessions/pay@#', 200, Fixtures::session_status(self::SESSION, 'completed'));
		$this->api->on('GET', '#^/payments/pay@#', 200, Fixtures::payment(self::SESSION, '10', '10'));

		$this->assertSame(PaymentSync::UNKNOWN, PaymentSync::refresh($order, 'return page'));
	}

	public function test_refresh_completed(): void
	{
		$order = $this->order_with_session();
		$this->api->on('GET', '#^/checkout-sessions/pay@#', 200, Fixtures::session_status(self::SESSION, 'completed'));
		$this->api->on('GET', '#^/payments/pay@#', 200, Fixtures::payment(self::SESSION, '10', '10'));

		$this->assertSame(PaymentSync::COMPLETED, PaymentSync::refresh($order, 'reconciliation'));
		$this->assertTrue(wc_get_order($order->get_id())->is_paid());
	}

	public function test_refresh_expired(): void
	{
		$order = $this->order_with_session();
		$this->api->on('GET', '#^/checkout-sessions/pay@#', 200, Fixtures::session_status(self::SESSION, 'expired'));

		$this->assertSame(PaymentSync::EXPIRED, PaymentSync::refresh($order, 'reconciliation'));
		$this->assertSame('failed', wc_get_order($order->get_id())->get_status());
	}

	public function test_refresh_open_with_last_attempt_adds_note_once(): void
	{
		$order = $this->order_with_session();
		$this->api->on('GET', '#^/checkout-sessions/pay@#', 200, Fixtures::session_status(self::SESSION, 'created', [
			'lastAttempt' => ['status' => 'failed', 'code' => 'insufficient_funds', 'message' => 'Not enough USDC', 'at' => '2026-10-22T10:00:00Z'],
		]));

		$this->assertSame(PaymentSync::OPEN, PaymentSync::refresh($order, 'reconciliation'));
		PaymentSync::refresh(wc_get_order($order->get_id()), 'reconciliation');

		$order = wc_get_order($order->get_id());
		$this->assertSame('pending', $order->get_status());
		$notes = array_filter(wc_get_order_notes(['order_id' => $order->get_id()]), static fn ($n) => str_contains($n->content, 'Not enough USDC'));
		$this->assertCount(1, $notes);
	}

	private function attempt_notes(int $orderId, string $needle): array
	{
		return array_values(array_filter(wc_get_order_notes(['order_id' => $orderId]), static fn ($n) => str_contains($n->content, $needle)));
	}

	public function test_failed_attempt_note_carries_reason_code_and_retry_and_keeps_status(): void
	{
		$order = $this->order_with_session();
		$this->api->on('GET', '#^/checkout-sessions/pay@#', 200, Fixtures::session_status(self::SESSION, 'created', [
			'lastAttempt' => ['status' => 'failed', 'code' => 'tx_mismatch', 'message' => 'Wrong amount sent', 'at' => '2026-10-22T10:00:00Z'],
		]));

		PaymentSync::refresh($order, 'reconciliation');
		PaymentSync::refresh(wc_get_order($order->get_id()), 'reconciliation');

		$order = wc_get_order($order->get_id());
		$this->assertSame('pending', $order->get_status());
		$notes = $this->attempt_notes($order->get_id(), 'Wrong amount sent');
		$this->assertCount(1, $notes);
		$this->assertStringContainsString('(tx_mismatch)', $notes[0]->content);
		$this->assertStringContainsString('try again', $notes[0]->content);
		$stored = $order->get_meta(OrderMeta::LAST_ATTEMPT);
		$this->assertSame('tx_mismatch', $stored['code']);
		$this->assertSame('Wrong amount sent', $stored['message']);
		$this->assertSame(strtotime('2026-10-22T10:00:00Z'), $stored['at']);
	}

	public function test_newer_failed_attempt_adds_a_second_note(): void
	{
		$order = $this->order_with_session();
		$at = static fn (string $t) => Fixtures::session_status(self::SESSION, 'created', [
			'lastAttempt' => ['status' => 'failed', 'code' => 'tx_mismatch', 'message' => 'Wrong amount sent', 'at' => $t],
		]);
		$this->api->on('GET', '#^/checkout-sessions/pay@#', 200, $at('2026-10-22T10:00:00Z'));
		PaymentSync::refresh($order, 'reconciliation');
		$this->api->on('GET', '#^/checkout-sessions/pay@#', 200, $at('2026-10-22T10:05:00Z'));
		PaymentSync::refresh(wc_get_order($order->get_id()), 'reconciliation');

		$this->assertCount(2, $this->attempt_notes($order->get_id(), '(tx_mismatch)'));
	}

	public function test_failed_attempt_without_message_names_the_code_once(): void
	{
		$order = $this->order_with_session();
		$this->api->on('GET', '#^/checkout-sessions/pay@#', 200, Fixtures::session_status(self::SESSION, 'created', [
			'lastAttempt' => ['status' => 'failed', 'code' => 'tx_mismatch', 'at' => '2026-10-22T10:00:00Z'],
		]));

		PaymentSync::refresh($order, 'reconciliation');

		$notes = $this->attempt_notes($order->get_id(), 'tx_mismatch');
		$this->assertCount(1, $notes);
		$this->assertSame(1, substr_count($notes[0]->content, 'tx_mismatch'));
	}

	public function test_failed_attempt_message_is_stripped_of_tags(): void
	{
		$order = $this->order_with_session();
		$this->api->on('GET', '#^/checkout-sessions/pay@#', 200, Fixtures::session_status(self::SESSION, 'created', [
			'lastAttempt' => ['status' => 'failed', 'code' => 'tx_mismatch', 'message' => ' <b>Wrong</b> <script>alert(1)</script>amount ', 'at' => '2026-10-22T10:00:00Z'],
		]));

		PaymentSync::refresh($order, 'reconciliation');

		$order = wc_get_order($order->get_id());
		$this->assertStringNotContainsString('<', $order->get_meta(OrderMeta::LAST_ATTEMPT)['message']);
		$notes = $this->attempt_notes($order->get_id(), '(tx_mismatch)');
		$this->assertCount(1, $notes);
		$this->assertStringNotContainsString('<b>', $notes[0]->content);
		$this->assertStringContainsString('Wrong', $notes[0]->content);
	}

	public function test_refresh_paid_order_makes_no_call(): void
	{
		$order = $this->order_with_session();
		PaymentSync::complete($order, self::completed(), 'webhook');

		$this->assertSame(PaymentSync::COMPLETED, PaymentSync::refresh(wc_get_order($order->get_id()), 'return page'));
		$this->assertSame([], $this->api->requests());
	}

	public function test_refresh_api_down_is_unknown(): void
	{
		$order = $this->order_with_session();
		$this->api->onError('GET', '#^/checkout-sessions/pay@#', 503, 'unavailable', 'down');

		$this->assertSame(PaymentSync::UNKNOWN, PaymentSync::refresh($order, 'reconciliation'));
		$this->assertSame('pending', wc_get_order($order->get_id())->get_status());
	}
}
