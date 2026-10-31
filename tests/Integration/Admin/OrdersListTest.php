<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration\Admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use QBitFlowWC\Admin\OrdersList;
use QBitFlowWC\Orders\OrderMeta;
use QBitFlowWC\Tests\Integration\Support\TestCase;
use WC_Order;
use WP_Query;

/** The Crypto column and the QBitFlow filter, on both order storages. */
final class OrdersListTest extends TestCase
{
	private const HASH = '0x1234567890abcdef1234567890abcdef12345678abcd';

	public function set_up(): void
	{
		// The storage is switched before the test makes any order.
		update_option('woocommerce_custom_orders_table_enabled', $this->dataName() === 'posts' ? 'no' : 'yes');
		parent::set_up();
	}

	public function tear_down(): void
	{
		unset($_GET['qbitflow_filter']);
		parent::tear_down();
		update_option('woocommerce_custom_orders_table_enabled', 'yes');
	}

	/** @return array<string, array{string}> */
	public static function storages(): array
	{
		return ['hpos' => ['yes'], 'posts' => ['no']];
	}

	private function storage(string $hpos): void
	{
		$this->assertSame($hpos === 'yes', OrderUtil::custom_orders_table_usage_is_enabled());
	}

	private function paid(): WC_Order
	{
		$order = $this->make_order();
		$order->update_meta_data(OrderMeta::SESSION_UUID, 'pay@019a0000-0000-7000-8000-0000000000a1');
		$order->update_meta_data(OrderMeta::LAST_STATUS, 'completed');
		$order->update_meta_data(OrderMeta::TX_HASH, self::HASH);
		$order->update_meta_data(OrderMeta::CURRENCY, 'USDC (BASE)');
		$order->update_meta_data(OrderMeta::CHAIN, 'BASE');
		$order->update_meta_data(OrderMeta::EXPLORER_URL, 'https://basescan.org/tx/' . self::HASH);
		$order->save();
		$order->payment_complete(self::HASH);

		return wc_get_order($order->get_id());
	}

	private function awaiting(string $uuid = 'pay@019a0000-0000-7000-8000-0000000000b1'): WC_Order
	{
		$order = $this->make_order();
		$order->update_meta_data(OrderMeta::SESSION_UUID, $uuid);
		$order->save();

		return $order;
	}

	private function with_refund(string $status): WC_Order
	{
		$order = $this->paid();
		$order->update_meta_data(OrderMeta::REFUNDS, ['refund@1' => ['status' => $status, 'percent' => '50']]);
		$order->save();

		return wc_get_order($order->get_id());
	}

	/** The ids the list would show for `?qbitflow_filter=$value`, through the storage's hook. */
	private function listed(string $hpos, string|array|null $value): array
	{
		if ($value === null) {
			unset($_GET['qbitflow_filter']);
		} else {
			$_GET['qbitflow_filter'] = $value;
		}
		if ($hpos === 'yes') {
			$args = apply_filters('woocommerce_order_list_table_prepare_items_query_args', ['limit' => -1, 'return' => 'ids', 'type' => 'shop_order']);
			$ids = wc_get_orders($args);
		} else {
			set_current_screen('edit-shop_order');
			$vars = apply_filters('request', ['post_type' => 'shop_order']);
			$ids = (new WP_Query($vars + ['post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true]))->posts;
		}
		$ids = array_map('intval', $ids);
		sort($ids);

		return $ids;
	}

	/** @dataProvider storages */
	public function test_column_is_registered_after_status(string $hpos): void
	{
		$this->storage($hpos);
		OrdersList::init();
		$hook = $hpos === 'yes' ? 'manage_woocommerce_page_wc-orders_columns' : 'manage_edit-shop_order_columns';

		$columns = apply_filters($hook, ['cb' => '', 'order_number' => 'Order', 'order_status' => 'Status', 'order_total' => 'Total']);

		$this->assertSame(['cb', 'order_number', 'order_status', 'qbitflow', 'order_total'], array_keys($columns));
	}

	/** @dataProvider storages */
	public function test_paid_cell_shows_token_chain_and_linked_short_hash(string $hpos): void
	{
		$this->storage($hpos);
		$html = OrdersList::cell($this->paid());

		$this->assertStringContainsString('USDC (BASE)', $html);
		$this->assertStringContainsString('BASE', $html);
		$this->assertStringContainsString('0x1234…abcd', $html);
		$this->assertStringNotContainsString(self::HASH, strip_tags($html));
		$this->assertStringContainsString('href="https://basescan.org/tx/' . self::HASH . '"', $html);
		$this->assertStringContainsString('rel="noopener noreferrer"', $html);
		$this->assertStringContainsString('target="_blank"', $html);
	}

	/** @dataProvider storages */
	public function test_cell_is_escaped_and_http_only(string $hpos): void
	{
		$this->storage($hpos);
		$order = $this->paid();
		$order->update_meta_data(OrderMeta::CURRENCY, '<script>x</script>');
		$order->update_meta_data(OrderMeta::EXPLORER_URL, 'javascript:alert(1)');
		$order->save();

		$html = OrdersList::cell(wc_get_order($order->get_id()));

		$this->assertStringNotContainsString('<script>', $html);
		$this->assertStringNotContainsString('javascript:', $html);
	}

	/** @dataProvider storages */
	public function test_refund_and_mismatch_badges(string $hpos): void
	{
		$this->storage($hpos);

		$this->assertStringContainsString('Refund requested', OrdersList::cell($this->with_refund('pending')));
		$this->assertStringContainsString('Partially refunded', OrdersList::cell($this->with_refund('approved')));
		$full = $this->with_refund('approved');
		wc_create_refund(['order_id' => $full->get_id(), 'amount' => $full->get_total()]);
		$cell = OrdersList::cell(wc_get_order($full->get_id()));
		$this->assertStringContainsString('Refunded', $cell);
		$this->assertStringNotContainsString('Partially', $cell);

		$held = $this->make_order();
		$held->update_meta_data(OrderMeta::SESSION_UUID, 'pay@019a0000-0000-7000-8000-0000000000c1');
		$held->update_meta_data(OrderMeta::LAST_STATUS, 'completed');
		$held->update_meta_data(OrderMeta::TX_HASH, self::HASH);
		$held->update_meta_data(OrderMeta::AMOUNT_MISMATCH, '1');
		$held->save();
		$held->update_status('on-hold');
		$this->assertStringContainsString('Amount mismatch', OrdersList::cell(wc_get_order($held->get_id())));

		$this->assertStringNotContainsString('Refund', OrdersList::cell($this->paid()));
		$this->assertStringNotContainsString('mismatch', OrdersList::cell($this->paid()));
	}

	/** @dataProvider storages */
	public function test_other_gateway_has_an_empty_cell(string $hpos): void
	{
		$this->storage($hpos);
		$order = $this->make_order();
		$order->set_payment_method('bacs');
		$order->save();

		$this->assertSame('', OrdersList::cell($order));
	}

	/** @dataProvider storages */
	public function test_filter_all_returns_only_qbitflow_orders(string $hpos): void
	{
		$this->storage($hpos);
		$paid = $this->paid();
		$waiting = $this->awaiting();
		$other = $this->make_order();
		$other->set_payment_method('bacs');
		$other->save();

		$this->assertSame([$paid->get_id(), $waiting->get_id()], $this->listed($hpos, 'all'));
	}

	/** @dataProvider storages */
	public function test_filter_awaiting_returns_only_unpaid_orders_with_a_session(string $hpos): void
	{
		$this->storage($hpos);
		$this->paid();
		$this->make_order(); // QBitFlow, no session yet.
		$waiting = $this->awaiting();

		$this->assertSame([$waiting->get_id()], $this->listed($hpos, 'awaiting'));
	}

	/** @dataProvider storages */
	public function test_filter_refund_requested_returns_only_pending_refunds(string $hpos): void
	{
		$this->storage($hpos);
		$this->paid();
		$this->with_refund('approved');
		$pending = $this->with_refund('pending');
		$this->awaiting();

		$this->assertSame([$pending->get_id()], $this->listed($hpos, 'refund_requested'));
	}

	/** @dataProvider storages */
	public function test_unknown_filter_value_is_ignored(string $hpos): void
	{
		$this->storage($hpos);
		$this->paid();
		$other = $this->make_order();
		$other->set_payment_method('bacs');
		$other->save();
		$everything = $this->listed($hpos, null);

		$this->assertCount(2, $everything);
		$this->assertSame($everything, $this->listed($hpos, 'bogus'));
	}

	/** @dataProvider storages */
	public function test_dropdown_lists_the_choices_and_keeps_the_selection(string $hpos): void
	{
		$this->storage($hpos);
		$_GET['qbitflow_filter'] = 'awaiting';

		ob_start();
		OrdersList::dropdown();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString('name="qbitflow_filter"', $html);
		$this->assertStringContainsString('value="refund_requested"', $html);
		$this->assertMatchesRegularExpression('/value="awaiting"\s+selected=\'selected\'|selected=.selected.[^>]*value="awaiting"|value="awaiting"[^>]*selected/', $html);
	}

	/** @dataProvider storages */
	public function test_manual_on_hold_of_a_paid_order_shows_no_mismatch(string $hpos): void
	{
		$this->storage($hpos);
		$order = $this->paid();
		$order->update_status('on-hold');

		$this->assertStringNotContainsString('mismatch', OrdersList::cell(wc_get_order($order->get_id())));
	}

	/** @dataProvider storages */
	public function test_resolved_mismatch_loses_the_badge(string $hpos): void
	{
		$this->storage($hpos);
		$order = $this->paid();
		$order->update_meta_data(OrderMeta::AMOUNT_MISMATCH, '1');
		$order->update_status('on-hold');
		$this->assertStringContainsString('Amount mismatch', OrdersList::cell(wc_get_order($order->get_id())));

		$order = wc_get_order($order->get_id());
		$order->update_status('processing');

		$this->assertStringNotContainsString('mismatch', OrdersList::cell(wc_get_order($order->get_id())));
	}

	/** @dataProvider storages */
	public function test_array_filter_value_is_ignored_without_warning(string $hpos): void
	{
		$this->storage($hpos);
		$this->paid();

		$this->assertSame($this->listed($hpos, null), $this->listed($hpos, ['x']));
	}
}
