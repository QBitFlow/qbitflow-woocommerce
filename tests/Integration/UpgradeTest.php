<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration;

use QBitFlowWC\Admin\StatusPanel;
use QBitFlowWC\Checkout\AcceptedCoins;
use QBitFlowWC\Orders\OrderMeta;
use QBitFlowWC\Orders\PaymentSync;
use QBitFlowWC\Refunds\RefundSync;
use QBitFlowWC\Settings;
use QBitFlowWC\Tests\Integration\Support\Fixtures;
use QBitFlowWC\Tests\Integration\Support\TestCase;
use QBitFlowWC\Upgrade;

final class UpgradeTest extends TestCase
{
	public function test_upgrade_from_1x_cleans_up(): void
	{
		delete_option(Upgrade::VERSION_OPTION);
		delete_option(Settings::STORE_ID_OPTION);
		update_option('qbitflow_cuuid_' . md5('ada@example.com'), 'old-uuid', false);
		set_transient('qbitflow_refund_' . md5('x'), 'none', 60);
		update_option('unrelated_option', 'kept');

		Upgrade::maybe_run();

		$this->assertFalse(get_option('qbitflow_cuuid_' . md5('ada@example.com')));
		$this->assertFalse(get_transient('qbitflow_refund_' . md5('x')));
		$this->assertSame('kept', get_option('unrelated_option'));
		$this->assertMatchesRegularExpression('/^[a-z0-9]{8}$/', Settings::store_id());
		$this->assertSame(Upgrade::VERSION, (int) get_option(Upgrade::VERSION_OPTION));
	}

	public function test_version_is_four(): void
	{
		$this->assertSame(4, Upgrade::VERSION);
	}

	public function test_upgrade_drops_the_cached_account_settings(): void
	{
		update_option('qbitflow_wc_account_fee', ['key' => 'k', 'value' => true, 'checked_at' => time()], false);
		set_transient('qbitflow_wc_account_settings', ['key' => 'k', 'values' => []], 600);
		update_option(Settings::OPTION, ['enabled' => 'yes', 'processing_fee' => 'account']);
		update_option(Upgrade::VERSION_OPTION, 3);

		Upgrade::maybe_run();

		$this->assertFalse(get_option('qbitflow_wc_account_fee'));
		$this->assertFalse(get_transient('qbitflow_wc_account_settings'));
		$this->assertSame('account', Settings::fee_choice());
		$this->assertSame(4, (int) get_option(Upgrade::VERSION_OPTION));
	}

	public function test_customer_pays_fee_yes_becomes_customer(): void
	{
		update_option(Settings::OPTION, ['enabled' => 'yes', 'customer_pays_fee' => 'yes']);
		update_option(Upgrade::VERSION_OPTION, 2);

		Upgrade::maybe_run();

		$this->assertSame('customer', Settings::fee_choice());
		$this->assertArrayNotHasKey('customer_pays_fee', Settings::all());
		$this->assertSame('yes', Settings::get('enabled'));
	}

	public function test_customer_pays_fee_no_or_unset_becomes_account(): void
	{
		foreach ([['customer_pays_fee' => 'no'], []] as $old) {
			update_option(Settings::OPTION, ['enabled' => 'yes'] + $old);
			update_option(Upgrade::VERSION_OPTION, 2);

			Upgrade::maybe_run();

			$this->assertSame('account', Settings::get('processing_fee'));
			$this->assertSame('account', Settings::fee_choice());
		}
	}

	public function test_upgrade_keeps_a_choice_already_made(): void
	{
		update_option(Settings::OPTION, ['processing_fee' => 'store', 'customer_pays_fee' => 'yes']);
		update_option(Upgrade::VERSION_OPTION, 2);

		Upgrade::maybe_run();

		$this->assertSame('store', Settings::fee_choice());
	}

	public function test_upgrade_does_not_create_settings_for_a_fresh_site(): void
	{
		delete_option(Settings::OPTION);
		update_option(Upgrade::VERSION_OPTION, 2);

		Upgrade::maybe_run();

		$this->assertFalse(get_option(Settings::OPTION));
	}

	public function test_store_id_is_stable_but_changes_with_the_site_address(): void
	{
		$id = Settings::store_id();
		$this->assertSame($id, Settings::store_id());

		$staging = static fn () => 'https://staging.example.com';
		add_filter('pre_option_siteurl', $staging);
		$clone = Settings::store_id();
		remove_filter('pre_option_siteurl', $staging);

		$this->assertNotSame($id, $clone);
	}

	public function test_store_id_saved_by_a_beta_is_kept(): void
	{
		update_option(Settings::STORE_ID_OPTION, 'abcd1234');

		$this->assertSame('abcd1234', Settings::store_id());
	}

	public function test_runs_once(): void
	{
		Upgrade::maybe_run();
		update_option('qbitflow_cuuid_' . md5('later@example.com'), 'kept-now', false);

		Upgrade::maybe_run();

		$this->assertSame('kept-now', get_option('qbitflow_cuuid_' . md5('later@example.com')));
	}

	public function test_1x_order_with_bare_session_reconciles(): void
	{
		$order = $this->make_order();
		$order->update_meta_data(OrderMeta::SESSION_UUID, '019a0000-0000-7000-8000-0000000000a1');
		$order->update_meta_data(OrderMeta::LAST_STATUS, 'pending');
		$order->save();
		$this->api->on('GET', '#^/checkout-sessions/019a0000-0000-7000-8000-0000000000a1$#', 200, Fixtures::session_status('pay@019a0000-0000-7000-8000-0000000000a1', 'completed'));
		$this->api->on('GET', '#^/payments/019a0000-0000-7000-8000-0000000000a1$#', 200, Fixtures::payment('pay@019a0000-0000-7000-8000-0000000000a1', '10', '10'));

		$this->assertSame(PaymentSync::COMPLETED, PaymentSync::refresh($order, 'background check'));
		$this->assertTrue(wc_get_order($order->get_id())->is_paid());
	}

	public function test_connect_notice_while_enabled_without_secret(): void
	{
		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

		ob_start();
		Upgrade::connect_notice();
		$this->assertStringContainsString('Connect webhook', (string) ob_get_clean());

		update_option('qbitflow_wc_webhook_test', ['uuid' => 'u', 'secret' => 'whsec_x', 'mode' => 'test', 'url' => '', 'space' => '', 'connected_at' => 1]);
		ob_start();
		Upgrade::connect_notice();
		$this->assertSame('', (string) ob_get_clean());
	}

	public function test_uninstall_removes_options_endpoint_and_jobs(): void
	{
		update_option('qbitflow_wc_webhook_test', ['uuid' => '019a0000-0000-7000-8000-0000000e0001', 'secret' => 'whsec_x', 'mode' => 'test', 'url' => '', 'space' => '', 'connected_at' => 1]);
		update_option(RefundSync::PENDING_OPTION, ['refund@1' => 1]);
		update_option('qbitflow_cuuid_abc', 'x');
		update_option(StatusPanel::MEMBER_OPTION, ['key' => 'abc', 'member' => false], false);
		update_option('qbitflow_wc_lock_123', (string) (time() - 3600), false); // A lock a crashed request left.
		\QBitFlowWC\Orders\Reconciler::schedule();
		as_enqueue_async_action(AcceptedCoins::REFRESH_HOOK, [], 'qbitflow', true);
		$this->api->on('DELETE', '#^/webhooks/endpoints/019a0000-0000-7000-8000-0000000e0001$#', 204);
		$order = $this->make_order();
		$order->update_meta_data(OrderMeta::TX_HASH, '0xkept');
		$order->save();

		if (! defined('WP_UNINSTALL_PLUGIN')) {
			define('WP_UNINSTALL_PLUGIN', 'qbitflow-for-woocommerce/qbitflow-for-woocommerce.php');
		}
		include dirname(__DIR__, 2) . '/uninstall.php';

		$this->assertCount(1, $this->api->requests('DELETE'));
		foreach (['woocommerce_qbitflow_settings', 'qbitflow_wc_store_id', 'qbitflow_wc_webhook_test', RefundSync::PENDING_OPTION, 'qbitflow_cuuid_abc', StatusPanel::MEMBER_OPTION, 'qbitflow_wc_lock_123'] as $option) {
			$this->assertFalse(get_option($option), $option);
		}
		$this->assertFalse(as_has_scheduled_action('qbitflow_wc_reconcile'));
		$this->assertFalse(as_has_scheduled_action(RefundSync::SYNC_HOOK));
		$this->assertFalse(as_has_scheduled_action(AcceptedCoins::REFRESH_HOOK));
		$this->assertSame('0xkept', wc_get_order($order->get_id())->get_meta(OrderMeta::TX_HASH));
	}
}
