<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration\Frontend;

use QBitFlowWC\Frontend\OrderPages;
use QBitFlowWC\Orders\OrderMeta;
use QBitFlowWC\Tests\Integration\Support\Fixtures;
use QBitFlowWC\Tests\Integration\Support\TestCase;

final class OrderPagesTest extends TestCase
{
	private const SESSION = 'pay@019a0000-0000-7000-8000-0000000000a1';

	public function test_thankyou_completes_when_paid_server_side(): void
	{
		$order = $this->make_order();
		$order->update_meta_data(OrderMeta::SESSION_UUID, self::SESSION);
		$order->update_meta_data(OrderMeta::AMOUNT_USD, '10.00');
		$order->save();
		$this->api->on('GET', '#^/checkout-sessions/pay@#', 200, Fixtures::session_status(self::SESSION, 'completed'));
		$this->api->on('GET', '#^/payments/pay@#', 200, Fixtures::payment(self::SESSION, '10', '10'));

		ob_start();
		OrderPages::thankyou($order->get_id());
		$html = (string) ob_get_clean();

		$this->assertTrue(wc_get_order($order->get_id())->is_paid());
		$this->assertStringContainsString('Crypto payment confirmed', $html);
	}

	public function test_thankyou_records_a_failed_attempt_note(): void
	{
		$order = $this->make_order();
		$order->update_meta_data(OrderMeta::SESSION_UUID, self::SESSION);
		$order->save();
		$this->api->on('GET', '#^/checkout-sessions/pay@#', 200, Fixtures::session_status(self::SESSION, 'created', [
			'lastAttempt' => ['status' => 'failed', 'code' => 'tx_mismatch', 'message' => 'Wrong amount sent', 'at' => '2026-10-22T10:00:00Z'],
		]));

		ob_start();
		OrderPages::thankyou($order->get_id());
		ob_end_clean();

		$notes = array_filter(wc_get_order_notes(['order_id' => $order->get_id()]), static fn ($n) => str_contains($n->content, '(tx_mismatch)'));
		$this->assertCount(1, $notes);
		$this->assertSame('pending', wc_get_order($order->get_id())->get_status());
	}

	public function test_thankyou_shows_processing_while_open(): void
	{
		$order = $this->make_order();
		$order->update_meta_data(OrderMeta::SESSION_UUID, self::SESSION);
		$order->save();
		$this->api->on('GET', '#^/checkout-sessions/pay@#', 200, Fixtures::session_status(self::SESSION, 'waitingConfirmation'));

		ob_start();
		OrderPages::thankyou($order->get_id());

		$this->assertStringContainsString('Payment processing', (string) ob_get_clean());
		$this->assertSame('waitingConfirmation', wc_get_order($order->get_id())->get_meta(OrderMeta::LAST_STATUS));
	}

	public function test_thankyou_no_http_when_already_paid(): void
	{
		$order = $this->make_order();
		$order->update_meta_data(OrderMeta::TX_HASH, '0xfeed');
		$order->save();
		$order->payment_complete('0xfeed');

		ob_start();
		OrderPages::thankyou($order->get_id());
		ob_end_clean();

		$this->assertSame([], $this->api->requests());
	}

	public function test_invoice_action_present_with_management_link(): void
	{
		$order = $this->make_order();
		$order->update_meta_data(OrderMeta::MANAGEMENT_LINK, 'https://qbitflow.app/user/x/manage?uuid=' . self::SESSION);
		$order->save();

		$actions = OrderPages::my_account_actions([], $order);

		$this->assertSame('https://qbitflow.app/user/x/manage?uuid=' . self::SESSION, $actions['qbitflow_invoice']['url']);
	}

	public function test_email_shows_transaction(): void
	{
		$order = $this->make_order();
		$order->update_meta_data(OrderMeta::TX_HASH, '0xfeed');
		$order->save();

		ob_start();
		OrderPages::email($order, false, true);

		$this->assertStringContainsString('0xfeed', (string) ob_get_clean());
	}
}
