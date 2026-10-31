<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration\Admin;

use QBitFlowWC\Admin\Actions;
use QBitFlowWC\Admin\Notices;
use QBitFlowWC\Admin\OrderMetaBox;
use QBitFlowWC\Orders\OrderMeta;
use QBitFlowWC\Tests\Integration\Support\Fixtures;
use QBitFlowWC\Tests\Integration\Support\TestCase;

final class OrderMetaBoxTest extends TestCase
{
	private const SESSION = 'pay@019a0000-0000-7000-8000-0000000000a1';

	public function test_renders_from_meta_without_http(): void
	{
		$order = $this->make_order();
		$order->update_meta_data(OrderMeta::SESSION_UUID, self::SESSION);
		$order->update_meta_data(OrderMeta::LAST_STATUS, 'completed');
		$order->update_meta_data(OrderMeta::TX_HASH, '0xfeed');
		$order->update_meta_data(OrderMeta::EXPLORER_URL, 'https://basescan.org/tx/0xfeed');
		$order->update_meta_data(OrderMeta::PROCESSING_FEE_USD, '1.20');
		$order->update_meta_data(OrderMeta::REFUNDS, ['refund@1' => ['status' => 'approved', 'percent' => '50', 'amount' => '5 USDC', 'tx_hash' => '0xr', 'explorer_url' => 'https://basescan.org/tx/0xr']]);
		$order->save();
		$order->payment_complete('0xfeed');

		ob_start();
		OrderMetaBox::render(wc_get_order($order->get_id()));
		$html = (string) ob_get_clean();

		$this->assertStringContainsString('https://basescan.org/tx/0xfeed', $html);
		$this->assertStringContainsString('$1.20, paid by the customer', $html);
		$this->assertStringContainsString('Refunded — 50% (5 USDC)', $html);
		$this->assertStringContainsString('action=' . Actions::REFRESH_ORDER, $html);
		$this->assertStringContainsString('Refund on QBitFlow', $html);
		$this->assertStringContainsString('table-layout:fixed', $html, 'long ids wrap inside the box');
		$this->assertSame([], $this->api->requests());
	}

	public function test_other_gateway(): void
	{
		$order = $this->make_order();
		$order->set_payment_method('bacs');
		$order->save();

		ob_start();
		OrderMetaBox::render($order);

		$this->assertStringContainsString('Not a QBitFlow payment', (string) ob_get_clean());
	}

	public function test_refresh_action_completes_paid_order(): void
	{
		$order = $this->make_order();
		$order->update_meta_data(OrderMeta::SESSION_UUID, self::SESSION);
		$order->save();
		$this->api->on('GET', '#^/checkout-sessions/pay@#', 200, Fixtures::session_status(self::SESSION, 'completed'));
		$this->api->on('GET', '#^/payments/pay@#', 200, Fixtures::payment(self::SESSION, '10', '10'));
		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
		$_GET['order_id'] = (string) $order->get_id();
		$_REQUEST['_wpnonce'] = wp_create_nonce(Actions::REFRESH_ORDER . $order->get_id());

		$redirect = Actions::run(Actions::REFRESH_ORDER);

		$this->assertStringContainsString((string) $order->get_id(), $redirect);
		$this->assertTrue(wc_get_order($order->get_id())->is_paid());
		$this->assertSame('success', Notices::take()['type']);
	}

	public function test_refresh_action_records_a_failed_attempt_note(): void
	{
		$order = $this->make_order();
		$order->update_meta_data(OrderMeta::SESSION_UUID, self::SESSION);
		$order->save();
		$this->api->on('GET', '#^/checkout-sessions/pay@#', 200, Fixtures::session_status(self::SESSION, 'created', [
			'lastAttempt' => ['status' => 'failed', 'code' => 'tx_mismatch', 'message' => 'Wrong amount sent', 'at' => '2026-10-22T10:00:00Z'],
		]));
		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
		$_GET['order_id'] = (string) $order->get_id();
		$_REQUEST['_wpnonce'] = wp_create_nonce(Actions::REFRESH_ORDER . $order->get_id());

		Actions::run(Actions::REFRESH_ORDER);

		$notes = array_filter(wc_get_order_notes(['order_id' => $order->get_id()]), static fn ($n) => str_contains($n->content, '(tx_mismatch)'));
		$this->assertCount(1, $notes);
	}

	public function test_shows_last_failed_attempt_escaped_in_site_timezone(): void
	{
		update_option('timezone_string', 'Europe/Paris');
		$order = $this->make_order();
		$order->update_meta_data(OrderMeta::SESSION_UUID, self::SESSION);
		$order->update_meta_data(OrderMeta::LAST_ATTEMPT, ['code' => 'tx_mismatch', 'message' => '<b>Wrong</b> amount', 'at' => strtotime('2026-10-22T10:00:00Z')]);
		$order->save();

		ob_start();
		OrderMetaBox::render(wc_get_order($order->get_id()));
		$html = (string) ob_get_clean();
		update_option('timezone_string', '');

		$this->assertStringContainsString('Last failed attempt', $html);
		$this->assertStringContainsString('tx_mismatch', $html);
		$this->assertStringContainsString('&lt;b&gt;Wrong&lt;/b&gt; amount', $html);
		$this->assertStringNotContainsString('<b>Wrong', $html);
		$this->assertStringContainsString('12:00', $html);
		$this->assertSame([], $this->api->requests());
	}

	public function test_paid_order_labels_the_attempt_as_earlier(): void
	{
		$order = $this->make_order();
		$order->update_meta_data(OrderMeta::SESSION_UUID, self::SESSION);
		$order->update_meta_data(OrderMeta::LAST_ATTEMPT, ['code' => 'tx_mismatch', 'message' => 'Wrong amount', 'at' => 1792000000]);
		$order->save();
		$order->payment_complete('0xfeed');

		ob_start();
		OrderMetaBox::render(wc_get_order($order->get_id()));
		$html = (string) ob_get_clean();

		$this->assertStringContainsString('Earlier failed attempt (the customer paid afterwards)', $html);
		$this->assertStringNotContainsString('Last failed attempt', $html);
		$this->assertStringContainsString('tx_mismatch', $html);
	}

	public function test_refresh_nonce_is_bound_to_the_order(): void
	{
		$order = $this->make_order();
		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
		$_GET['order_id'] = (string) $order->get_id();
		$_REQUEST['_wpnonce'] = wp_create_nonce(Actions::REFRESH_ORDER . ($order->get_id() + 1));

		$this->expectException(\WPDieException::class);
		Actions::run(Actions::REFRESH_ORDER);
	}

	public function tear_down(): void
	{
		unset($_GET['order_id'], $_REQUEST['_wpnonce']);
		parent::tear_down();
	}
}
