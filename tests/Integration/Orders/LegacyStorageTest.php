<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration\Orders;

use Automattic\WooCommerce\Utilities\OrderUtil;
use QBitFlowWC\Orders\OrderFinder;
use QBitFlowWC\Orders\OrderMeta;
use QBitFlowWC\Tests\Integration\Support\WebhookTestCase;

/**
 * Stores still on the posts order storage (HPOS off): wc_get_orders() takes no meta query
 * there, so the finder queries the posts table.
 */
final class LegacyStorageTest extends WebhookTestCase
{
	public function set_up(): void
	{
		update_option('woocommerce_custom_orders_table_enabled', 'no');
		parent::set_up();
	}

	public function tear_down(): void
	{
		parent::tear_down();
		update_option('woocommerce_custom_orders_table_enabled', 'yes');
	}

	public function test_posts_storage_is_active(): void
	{
		$this->assertFalse(OrderUtil::custom_orders_table_usage_is_enabled());
	}

	public function test_by_session_on_posts_storage(): void
	{
		$order = $this->order_with_session();

		$this->assertSame($order->get_id(), OrderFinder::by_session('019a0000-0000-7000-8000-0000000000a1')?->get_id());
		$this->assertNull(OrderFinder::by_session('pay@019a0000-0000-7000-8000-0000000000ff'));
	}

	public function test_unpaid_with_session_on_posts_storage(): void
	{
		$unpaid = $this->order_with_session();
		$paid = $this->make_order();
		$paid->update_meta_data(OrderMeta::SESSION_UUID, 'pay@019a0000-0000-7000-8000-0000000000b2');
		$paid->payment_complete('0xabc');
		$other = $this->make_order();
		$other->set_payment_method('bacs');
		$other->update_meta_data(OrderMeta::SESSION_UUID, 'pay@019a0000-0000-7000-8000-0000000000b3');
		$other->save();

		$ids = array_map(static fn ($o) => $o->get_id(), OrderFinder::unpaid_with_session());

		$this->assertSame([$unpaid->get_id()], $ids);
	}

	public function test_payment_webhook_on_posts_storage(): void
	{
		$order = $this->order_with_session();

		$this->assertSame(200, $this->deliver($this->payment_completed($order))->get_status());

		$this->assertTrue(wc_get_order($order->get_id())->is_paid());
	}
}
