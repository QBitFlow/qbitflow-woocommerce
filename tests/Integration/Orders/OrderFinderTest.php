<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration\Orders;

use QBitFlowWC\Orders\OrderFinder;
use QBitFlowWC\Orders\OrderMeta;
use QBitFlowWC\Settings;
use QBitFlowWC\Tests\Integration\Support\TestCase;

final class OrderFinderTest extends TestCase
{
	public function test_by_session_matches_bare_and_prefixed_forms(): void
	{
		$prefixed = $this->make_order();
		$prefixed->update_meta_data(OrderMeta::SESSION_UUID, 'pay@019a0000-0000-7000-8000-000000000001');
		$prefixed->save();
		$bare = $this->make_order();
		$bare->update_meta_data(OrderMeta::SESSION_UUID, '019a0000-0000-7000-8000-000000000002');
		$bare->save();

		$this->assertSame($prefixed->get_id(), OrderFinder::by_session('019a0000-0000-7000-8000-000000000001')?->get_id());
		$this->assertSame($bare->get_id(), OrderFinder::by_session('pay@019a0000-0000-7000-8000-000000000002')?->get_id());
		$this->assertNull(OrderFinder::by_session('pay@019a0000-0000-7000-8000-000000000003'));
		$this->assertNull(OrderFinder::by_session(''));
	}

	private function metadata(\WC_Order $order, array $overrides = []): array
	{
		return $overrides + [
			'source' => 'woocommerce',
			'store_id' => Settings::store_id(),
			'order_id' => (string) $order->get_id(),
			'token' => OrderMeta::token($order),
		];
	}

	public function test_by_metadata(): void
	{
		$order = $this->make_order();

		$this->assertSame($order->get_id(), OrderFinder::by_metadata($this->metadata($order))?->get_id());
	}

	public function test_foreign_store_id_is_null(): void
	{
		$order = $this->make_order();

		$this->assertNull(OrderFinder::by_metadata($this->metadata($order, ['store_id' => 'zzzzzzzz'])));
	}

	public function test_wrong_token_is_null(): void
	{
		$order = $this->make_order();

		$this->assertNull(OrderFinder::by_metadata($this->metadata($order, ['token' => str_repeat('0', 32)])));
	}

	public function test_order_without_token_is_null(): void
	{
		$order = $this->make_order();
		$metadata = ['source' => 'woocommerce', 'store_id' => Settings::store_id(), 'order_id' => (string) $order->get_id(), 'token' => ''];

		$this->assertNull(OrderFinder::by_metadata($metadata));
	}

	public function test_unknown_order_id_is_null(): void
	{
		$order = $this->make_order();

		$this->assertNull(OrderFinder::by_metadata($this->metadata($order, ['order_id' => '999999'])));
	}

	public function test_other_source_is_null(): void
	{
		$order = $this->make_order();

		$this->assertNull(OrderFinder::by_metadata($this->metadata($order, ['source' => 'crm'])));
	}

	public function test_other_gateway_order_is_null(): void
	{
		$order = $this->make_order();
		$order->set_payment_method('bacs');
		$order->save();

		$this->assertNull(OrderFinder::by_metadata($this->metadata($order)));
	}

	public function test_unpaid_with_session(): void
	{
		$unpaid = $this->make_order();
		$unpaid->update_meta_data(OrderMeta::SESSION_UUID, 'pay@019a0000-0000-7000-8000-000000000001');
		$unpaid->save();
		$paid = $this->make_order();
		$paid->update_meta_data(OrderMeta::SESSION_UUID, 'pay@019a0000-0000-7000-8000-000000000002');
		$paid->payment_complete('0xabc');
		$old = $this->make_order();
		$old->update_meta_data(OrderMeta::SESSION_UUID, 'pay@019a0000-0000-7000-8000-000000000003');
		$old->set_date_created(time() - 3 * DAY_IN_SECONDS);
		$old->set_date_modified(time() - 3 * DAY_IN_SECONDS);
		$old->save();
		$this->make_order(); // No session.

		$ids = array_map(static fn ($o) => $o->get_id(), OrderFinder::unpaid_with_session());

		$this->assertSame([$unpaid->get_id()], $ids);
	}
}
