<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration\Orders;

use QBitFlowWC\Checkout\AcceptedCoins;
use QBitFlowWC\Orders\OrderMeta;
use QBitFlowWC\Orders\Reconciler;
use QBitFlowWC\Refunds\RefundSync;
use QBitFlowWC\Tests\Integration\Support\Fixtures;
use QBitFlowWC\Tests\Integration\Support\TestCase;
use WC_Order;

final class ReconcilerTest extends TestCase
{
	private function order_with(string $session, string $amount = '10.00'): WC_Order
	{
		$order = $this->make_order([['price' => $amount]]);
		$order->update_meta_data(OrderMeta::SESSION_UUID, $session);
		$order->update_meta_data(OrderMeta::AMOUNT_USD, $amount);
		$order->update_meta_data(OrderMeta::LAST_STATUS, 'created');
		$order->save();

		return $order;
	}

	public function test_run_completes_paid_and_fails_expired(): void
	{
		$paid = $this->order_with('pay@019a0000-0000-7000-8000-0000000000a1');
		$expired = $this->order_with('pay@019a0000-0000-7000-8000-0000000000a2');
		$this->api->on('GET', '#^/checkout-sessions/pay@019a0000-0000-7000-8000-0000000000a1$#', 200, Fixtures::session_status('pay@019a0000-0000-7000-8000-0000000000a1', 'completed'));
		$this->api->on('GET', '#^/payments/pay@019a0000-0000-7000-8000-0000000000a1$#', 200, Fixtures::payment('pay@019a0000-0000-7000-8000-0000000000a1', '10', '10'));
		$this->api->on('GET', '#^/checkout-sessions/pay@019a0000-0000-7000-8000-0000000000a2$#', 200, Fixtures::session_status('pay@019a0000-0000-7000-8000-0000000000a2', 'expired'));

		Reconciler::run();

		$this->assertSame('processing', wc_get_order($paid->get_id())->get_status());
		$this->assertSame('failed', wc_get_order($expired->get_id())->get_status());
		$notes = implode("\n", wc_list_pluck(wc_get_order_notes(['order_id' => $paid->get_id()]), 'content'));
		$this->assertStringContainsString('background check', $notes);
	}

	public function test_run_skips_paid_old_and_held_orders(): void
	{
		$paid = $this->order_with('pay@019a0000-0000-7000-8000-0000000000a1');
		$paid->payment_complete('0xabc');
		$old = $this->order_with('pay@019a0000-0000-7000-8000-0000000000a2');
		$old->set_date_created(time() - 3 * DAY_IN_SECONDS);
		$old->set_date_modified(time() - 3 * DAY_IN_SECONDS);
		$old->save();
		$held = $this->order_with('pay@019a0000-0000-7000-8000-0000000000a3');
		$held->update_meta_data(OrderMeta::LAST_STATUS, 'completed');
		$held->set_status('on-hold');
		$held->save();

		Reconciler::run();

		$this->assertSame([], $this->api->requests());
	}

	public function test_held_orders_never_crowd_out_recent_ones(): void
	{
		for ($i = 0; $i < 51; $i++) {
			$held = $this->order_with(sprintf('pay@019a0000-0000-7000-8000-%012d', 1000 + $i));
			$held->update_meta_data(OrderMeta::LAST_STATUS, 'completed');
			$held->set_status('on-hold');
			$held->save();
		}
		$recent = $this->order_with('pay@019a0000-0000-7000-8000-0000000000a9');
		$this->api->on('GET', '#^/checkout-sessions/pay@019a0000-0000-7000-8000-0000000000a9$#', 200, Fixtures::session_status('pay@019a0000-0000-7000-8000-0000000000a9', 'completed'));
		$this->api->on('GET', '#^/payments/pay@019a0000-0000-7000-8000-0000000000a9$#', 200, Fixtures::payment('pay@019a0000-0000-7000-8000-0000000000a9', '10', '10'));

		Reconciler::run();

		$this->assertTrue(wc_get_order($recent->get_id())->is_paid());
		$this->assertCount(1, $this->api->requests('GET', '#^/checkout-sessions/#'));
	}

	public function test_run_does_nothing_without_key(): void
	{
		$this->order_with('pay@019a0000-0000-7000-8000-0000000000a1');
		$this->set_settings(['api_key' => '']);

		Reconciler::run();

		$this->assertSame([], $this->api->requests());
	}

	public function test_scheduled_once(): void
	{
		Reconciler::unschedule();

		Reconciler::schedule();
		Reconciler::schedule();

		$this->assertCount(1, as_get_scheduled_actions(['hook' => Reconciler::HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING], 'ids'));
		$this->assertCount(1, as_get_scheduled_actions(['hook' => RefundSync::SYNC_HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING], 'ids'));

		Reconciler::unschedule();
		$this->assertFalse(as_has_scheduled_action(Reconciler::HOOK));
	}

	public function test_unschedule_also_drops_a_queued_coins_refresh(): void
	{
		Reconciler::schedule();
		as_enqueue_async_action(AcceptedCoins::REFRESH_HOOK, [], 'qbitflow', true);

		// What deactivating the plugin runs: none of its jobs is left to run without it.
		Reconciler::unschedule();

		$this->assertFalse(as_has_scheduled_action(Reconciler::HOOK));
		$this->assertFalse(as_has_scheduled_action(RefundSync::SYNC_HOOK));
		$this->assertFalse(as_has_scheduled_action(AcceptedCoins::REFRESH_HOOK));
	}
}
