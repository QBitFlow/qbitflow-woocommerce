<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration\Refunds;

use QBitFlowWC\Orders\OrderMeta;
use QBitFlowWC\Refunds\RefundSync;
use QBitFlowWC\Tests\Integration\Support\Fixtures;
use QBitFlowWC\Tests\Integration\Support\WebhookTestCase;
use WC_Order;

final class RefundSyncTest extends WebhookTestCase
{
	private const REFUND = 'refund@019a0000-0000-7000-8000-0000000f0001';

	private function paid_order(string $total = '100.00'): WC_Order
	{
		$order = $this->order_with_session($total);
		$order->payment_complete('0xpaid');

		return wc_get_order($order->get_id());
	}

	private function refund_event(string $type, string $status, string $percent = '100', string $id = 'evt_r1', string $initiatedBy = 'customer', string $refund = self::REFUND): array
	{
		$event = Fixtures::event($type);
		$event['id'] = $id;
		$event['data'] = Fixtures::refund($refund, self::SESSION, $status, $percent, $initiatedBy, ['test' => true]);

		return $event;
	}

	public function test_requested_adds_note_and_pending(): void
	{
		$order = $this->paid_order();

		$this->assertSame(200, $this->deliver($this->refund_event('refund.requested', 'pending'))->get_status());

		$this->assertSame(1, RefundSync::pending_count());
		$this->assertStringContainsString('requested a QBitFlow refund (100%)', wc_get_order_notes(['order_id' => $order->get_id(), 'limit' => 1])[0]->content);
		$this->assertSame('pending', wc_get_order($order->get_id())->get_meta(OrderMeta::REFUNDS)[self::REFUND]['status']);
	}

	public function test_approved_full_creates_wc_refund_for_total(): void
	{
		$order = $this->paid_order();
		$this->deliver($this->refund_event('refund.requested', 'pending'));

		$this->deliver($this->refund_event('refund.approved', 'approved', '100', 'evt_r2'));

		$order = wc_get_order($order->get_id());
		$this->assertSame('100.00', number_format((float) $order->get_total_refunded(), 2));
		$this->assertSame('refunded', $order->get_status());
		$this->assertSame(0, RefundSync::pending_count());
		$this->assertNotEmpty($order->get_meta(OrderMeta::REFUND_TX_HASH));
	}

	public function test_a_reason_ending_in_a_full_stop_is_not_doubled(): void
	{
		$order = $this->paid_order();
		$event = $this->refund_event('refund.requested', 'pending');
		$event['data']['reason'] = 'Wrong size.';

		$this->deliver($event);

		$note = wc_get_order_notes(['order_id' => $order->get_id(), 'limit' => 1])[0]->content;
		$this->assertStringContainsString('Reason: Wrong size. Approve', $note);
		$this->assertStringNotContainsString('..', $note);
	}

	public function test_the_refund_line_shows_a_short_tx_and_the_note_the_full_one(): void
	{
		$order = $this->paid_order();
		$event = $this->refund_event('refund.approved', 'approved');
		$event['data']['txHash'] = '0x' . str_repeat('ab', 32);

		$this->deliver($event);

		$order = wc_get_order($order->get_id());
		$reason = (string) $order->get_refunds()[0]->get_reason();
		$this->assertStringContainsString(self::REFUND, $reason);
		$this->assertStringContainsString('tx 0xabab…abab', $reason);
		$this->assertStringNotContainsString(str_repeat('ab', 32), $reason);
		$this->assertStringContainsString('0x' . str_repeat('ab', 32), wc_get_order_notes(['order_id' => $order->get_id(), 'limit' => 1])[0]->content);
	}

	public function test_partial_percent_in_notes(): void
	{
		$order = $this->paid_order();

		$this->deliver($this->refund_event('refund.requested', 'pending', '12.50'));

		$this->assertStringContainsString('(12.5%)', wc_get_order_notes(['order_id' => $order->get_id(), 'limit' => 1])[0]->content);
	}

	/**
	 * round(total × percent / 100, 2), as the design says: 35 % of $8.68 is $3.038 → $3.04 (the
	 * partial refund once recorded $3.03), 12.5 % of $8.68 is $1.085 → $1.09, 33.333 % of $100 → $33.33.
	 *
	 * @dataProvider partial_refunds
	 */
	public function test_approved_partial_rounds_to_the_nearest_cent(string $total, string $percent, string $expected): void
	{
		$order = $this->paid_order($total);

		$this->deliver($this->refund_event('refund.approved', 'approved', $percent));

		$order = wc_get_order($order->get_id());
		$this->assertSame($expected, number_format((float) $order->get_total_refunded(), 2));
		$this->assertSame('processing', $order->get_status());
	}

	/** @return array<string, array{string, string, string}> */
	public static function partial_refunds(): array
	{
		return [
			'35 % of 8.68 rounds up' => ['8.68', '35', '3.04'],
			'12.5 % of 8.68, half a cent, rounds up' => ['8.68', '12.5', '1.09'],
			'33.333 % of 100 rounds down' => ['100.00', '33.333', '33.33'],
			'50 % of 8.69, half a cent, rounds up' => ['8.69', '50', '4.35'],
		];
	}

	public function test_approved_refund_capped_to_remaining(): void
	{
		$order = $this->paid_order();
		wc_create_refund(['order_id' => $order->get_id(), 'amount' => '80.00', 'reason' => 'manual']);

		$this->deliver($this->refund_event('refund.approved', 'approved', '100'));

		$this->assertSame('100.00', number_format((float) wc_get_order($order->get_id())->get_total_refunded(), 2));
	}

	public function test_snapshot_has_no_empty_entry(): void
	{
		$order = $this->paid_order();

		$this->deliver($this->refund_event('refund.requested', 'pending'));

		$this->assertSame([self::REFUND], array_keys(wc_get_order($order->get_id())->get_meta(OrderMeta::REFUNDS)));
		$this->assertNotContains('', wc_get_order($order->get_id())->get_meta(OrderMeta::EVENTS));
	}

	public function test_refund_recorded_before_a_crash_is_not_recorded_again(): void
	{
		// The WooCommerce refund exists (its reason names the QBitFlow refund), the snapshot doesn't.
		$order = $this->paid_order();
		wc_create_refund(['order_id' => $order->get_id(), 'amount' => '50.00', 'reason' => 'QBitFlow refund ' . self::REFUND . ' (50%), tx 0x4444.']);

		$this->deliver($this->refund_event('refund.approved', 'approved', '50'));

		$order = wc_get_order($order->get_id());
		$this->assertCount(1, $order->get_refunds());
		$this->assertNotEmpty($order->get_meta(OrderMeta::REFUNDS)[self::REFUND]['wc_refund_id']);
	}

	public function test_approved_dedup_by_refund_uuid(): void
	{
		$order = $this->paid_order();

		$this->deliver($this->refund_event('refund.approved', 'approved', '50', 'evt_a'));
		$this->deliver($this->refund_event('refund.approved', 'approved', '50', 'evt_b'));

		$this->assertCount(1, wc_get_order($order->get_id())->get_refunds());
	}

	public function test_approved_waits_while_another_request_holds_the_order(): void
	{
		global $wpdb;
		$order = $this->paid_order();
		$lock = 'qbitflow_wc_lock_' . $order->get_id();
		$wpdb->insert($wpdb->options, ['option_name' => $lock, 'option_value' => (string) time(), 'autoload' => 'no']);

		$this->assertGreaterThanOrEqual(500, $this->deliver($this->refund_event('refund.approved', 'approved', '50'))->get_status());
		$this->assertSame(0.0, (float) wc_get_order($order->get_id())->get_total_refunded());

		$wpdb->delete($wpdb->options, ['option_name' => $lock]);
		$this->assertSame(200, $this->deliver($this->refund_event('refund.approved', 'approved', '50'))->get_status());
		$this->assertSame('50.00', number_format((float) wc_get_order($order->get_id())->get_total_refunded(), 2));
	}

	public function test_merchant_initiated_approved_without_request(): void
	{
		$order = $this->paid_order();

		$this->deliver($this->refund_event('refund.approved', 'approved', '25', 'evt_m', 'merchant'));

		$this->assertSame('25.00', number_format((float) wc_get_order($order->get_id())->get_total_refunded(), 2));
		$this->assertSame(0, RefundSync::pending_count());
	}

	public function test_rejected_adds_note_and_clears_pending(): void
	{
		$order = $this->paid_order();
		$this->deliver($this->refund_event('refund.requested', 'pending'));
		$event = $this->refund_event('refund.rejected', 'rejected', '100', 'evt_r3');
		$event['data']['merchantMessage'] = 'Only one payment was received for this order';

		$this->deliver($event);

		$this->assertSame(0, RefundSync::pending_count());
		$this->assertStringContainsString('Only one payment was received', wc_get_order_notes(['order_id' => $order->get_id(), 'limit' => 1])[0]->content);
		$this->assertSame(0.0, (float) wc_get_order($order->get_id())->get_total_refunded());
	}

	public function test_bill_refunds_are_ignored(): void
	{
		$this->paid_order();
		$event = $this->refund_event('refund.requested', 'pending');
		$event['data']['txUuid'] = 'sub-hist@019a0000-0000-7000-8000-000000000b01';

		$this->assertSame(200, $this->deliver($event)->get_status());
		$this->assertSame(0, RefundSync::pending_count());
	}

	public function test_resync_counts_pending_of_this_store_only(): void
	{
		$this->paid_order();
		$this->api->on('GET', '#^/refunds$#', 200, Fixtures::page([
			Fixtures::refund(self::REFUND, self::SESSION, 'pending'),
			Fixtures::refund('refund@019a0000-0000-7000-8000-0000000f0002', 'pay@019a0000-0000-7000-8000-0000000000ee', 'pending'),
		]));

		RefundSync::resync_pending();

		$this->assertSame(1, RefundSync::pending_count());
		$this->assertStringContainsString('status=pending', $this->api->lastRequest('GET', '#^/refunds$#')['url']);
	}

	public function test_notice_reads_local_count_without_http(): void
	{
		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
		update_option(RefundSync::PENDING_OPTION, ['refund@1' => 1, 'refund@2' => 2]);

		ob_start();
		RefundSync::admin_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString('2 refund requests wait', $html);
		$this->assertSame([], $this->api->requests());
	}
}
