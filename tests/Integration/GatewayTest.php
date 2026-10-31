<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration;

use QBitFlowWC\Admin\StatusPanel;
use QBitFlowWC\Gateway;
use QBitFlowWC\Tests\Integration\Support\Fixtures;
use QBitFlowWC\Tests\Integration\Support\TestCase;

final class GatewayTest extends TestCase
{
	private function gateway(): Gateway
	{
		return WC()->payment_gateways()->payment_gateways()['qbitflow'];
	}

	public function test_icon_has_a_fixed_size(): void
	{
		$icon = $this->gateway()->get_icon();

		$this->assertStringContainsString('qbitflow-icon.png', $icon);
		$this->assertStringContainsString('max-height:1.6em', $icon);
		$this->assertStringContainsString('width="24"', $icon);
	}

	public function test_available_with_key_in_usd(): void
	{
		$this->assertTrue($this->gateway()->is_available());
	}

	public function test_unavailable_without_key(): void
	{
		$this->set_settings(['api_key' => '']);

		$this->assertFalse($this->gateway()->is_available());
	}

	public function test_unavailable_when_disabled(): void
	{
		$this->set_settings(['enabled' => 'no']);

		$this->assertFalse($this->gateway()->is_available());
	}

	public function test_unavailable_for_eur_without_rate(): void
	{
		update_option('woocommerce_currency', 'EUR');

		$this->assertFalse($this->gateway()->is_available());
	}

	public function test_available_for_eur_with_rate_filter(): void
	{
		update_option('woocommerce_currency', 'EUR');
		add_filter('qbitflow_wc_usd_rate', static fn ($rate, string $currency) => $currency === 'EUR' ? 1.08 : $rate, 10, 2);

		$this->assertTrue($this->gateway()->is_available());
	}

	public function test_non_positive_rate_is_ignored(): void
	{
		update_option('woocommerce_currency', 'EUR');
		add_filter('qbitflow_wc_usd_rate', static fn () => 0);

		$this->assertFalse($this->gateway()->is_available());
	}

	public function test_unavailable_while_known_not_ready(): void
	{
		set_transient(StatusPanel::NOT_READY_TRANSIENT, 1, 60);

		$this->assertFalse($this->gateway()->is_available());
		$this->assertSame([], $this->api->requests());
	}

	private const FEE_LINE = 'A small processing fee is added on the QBitFlow checkout.';

	public function test_description_mentions_processing_fee_when_customer_pays(): void
	{
		$this->api->on('GET', '#^/me$#', 200, Fixtures::me());
		$this->api->on('GET', '#^/wallets/accepted-currencies$#', 200, ['items' => [Fixtures::currency()], 'hasMore' => false]);
		StatusPanel::data(true);
		$this->set_settings(['processing_fee' => 'customer', 'description' => 'Pay with crypto.']);

		$this->assertSame('Pay with crypto. ' . self::FEE_LINE, $this->gateway()->get_description());
	}

	public function test_description_has_no_fee_line_when_the_store_pays(): void
	{
		$this->set_settings(['processing_fee' => 'store', 'description' => 'Pay with crypto.']);

		$this->assertSame('Pay with crypto.', $this->gateway()->get_description());
	}

	public function test_account_mode_description_makes_no_call_and_no_line(): void
	{
		$this->set_settings(['processing_fee' => 'account', 'description' => 'Pay.']);

		$this->assertSame('Pay.', $this->gateway()->get_description());
		$this->assertSame([], $this->api->requests());
	}

	public function test_member_key_shows_the_organizations_choice_disabled(): void
	{
		$this->member_status_api();
		StatusPanel::data(true);
		$this->set_settings(['processing_fee' => 'customer', 'description' => 'Pay.']);

		$html = $this->fee_field_html();

		$this->assertStringContainsString('disabled', $html);
		$this->assertStringContainsString('Set by your organization', $html);
		$this->assertSame('Pay.', $this->gateway()->get_description());
	}

	public function test_own_key_gets_the_three_choices(): void
	{
		$html = $this->fee_field_html();

		$this->assertStringNotContainsString('disabled', $html);
		foreach (['value="account"', 'value="customer"', 'value="store"'] as $option) {
			$this->assertStringContainsString($option, $html);
		}
	}

	public function test_saving_never_stores_a_value_for_a_member(): void
	{
		$this->member_status_api();
		$this->set_settings(['processing_fee' => 'store']);
		StatusPanel::data(true);

		$this->assertSame('store', $this->gateway()->validate_processing_fee_field('processing_fee', 'customer'));
	}

	public function test_saving_refuses_an_unknown_choice(): void
	{
		$this->assertSame('account', $this->gateway()->validate_processing_fee_field('processing_fee', 'bogus'));
		$this->assertSame('customer', $this->gateway()->validate_processing_fee_field('processing_fee', 'customer'));
	}

	public function test_key_change_drops_the_member_flag(): void
	{
		$this->member_status_api();
		StatusPanel::data(true);
		$this->assertTrue(StatusPanel::member());

		$this->set_settings(['api_key' => self::LIVE_KEY]);

		$this->assertNull(StatusPanel::member());
		StatusPanel::forget();
		$this->assertFalse(get_option(StatusPanel::MEMBER_OPTION));
	}

	public function test_a_save_without_the_field_keeps_the_choice(): void
	{
		$this->set_settings(['processing_fee' => 'store']);

		$this->assertSame('store', $this->gateway()->validate_processing_fee_field('processing_fee', null));
	}

	public function test_a_disabled_field_is_emitted_once(): void
	{
		$this->member_status_api();
		StatusPanel::data(true);

		preg_match('/<select[^>]*>/', $this->fee_field_html(), $tag);
		$this->assertSame(1, substr_count($tag[0], ' disabled'));
	}

	public function test_a_space_less_answer_is_unknown_not_organization(): void
	{
		$this->api->on('GET', '#^/me$#', 200, ['credential' => 'apiKey', 'apiKeyUuid' => 'key-1', 'role' => 'admin']);

		$this->assertNull(StatusPanel::member(true));
		$this->assertFalse(get_option(StatusPanel::MEMBER_OPTION));
	}

	private function fee_field_html(): string
	{
		$gateway = $this->gateway();
		$gateway->init_form_fields();

		return $gateway->generate_select_html('processing_fee', $gateway->get_form_fields()['processing_fee']);
	}

	public function test_supports_only_products(): void
	{
		$this->assertSame(['products'], $this->gateway()->supports);
		$this->assertFalse($this->gateway()->supports('refunds'));
	}

	public function test_admin_options_renders_status_panel_without_the_key(): void
	{
		$this->api->on('GET', '#^/me$#', 200, \QBitFlowWC\Tests\Integration\Support\Fixtures::me());
		$this->api->on('GET', '#^/wallets/accepted-currencies$#', 200, ['items' => [\QBitFlowWC\Tests\Integration\Support\Fixtures::currency()], 'hasMore' => false]);

		ob_start();
		$this->gateway()->admin_options();
		$html = (string) ob_get_clean();
		ob_start();
		StatusPanel::render();
		$panel = (string) ob_get_clean();

		$this->assertStringContainsString('Test mode', $html);
		$this->assertStringContainsString('Example Shop', $panel);
		$this->assertStringContainsString('USDC (BASE)', $panel);
		$this->assertStringContainsString('Connect webhook', $panel);
		$this->assertStringContainsString(rest_url('qbitflow-wc/v2/webhook'), $panel);
		$this->assertStringNotContainsString(self::TEST_KEY, $panel);
	}

	public function test_unavailable_for_a_legacy_key(): void
	{
		$this->set_settings(['api_key' => 'sk_019a0000-0000-7000-8000-000000000001_test_secret']);

		$this->assertFalse($this->gateway()->is_available());
	}

	public function test_legacy_key_notice_on_the_settings_screen(): void
	{
		$this->set_settings(['api_key' => 'sk_019a0000-0000-7000-8000-000000000001_test_secret']);
		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
		$_GET['section'] = 'qbitflow';

		ob_start();
		\QBitFlowWC\Admin\Notices::render_key_notice();
		$html = (string) ob_get_clean();
		unset($_GET['section']);

		$this->assertStringContainsString('old format', $html);
	}

	/** @dataProvider malformed_keys */
	public function test_malformed_key_never_fatals(string $key): void
	{
		$this->set_settings(['api_key' => $key]);
		$this->api->uninstall();
		$calls = 0;
		add_filter('pre_http_request', static function ($pre) use (&$calls) {
			$calls++;

			return $pre;
		});

		$this->assertNull(\QBitFlowWC\ClientFactory::make());
		\QBitFlowWC\Orders\Reconciler::run();
		$this->assertStringContainsString('not a QBitFlow key', (string) \QBitFlowWC\Admin\StatusPanel::data(true)['error']);
		$this->assertFalse($this->gateway()->is_available());

		$order = $this->make_order();
		$result = $this->gateway()->process_payment($order->get_id());
		$this->assertSame('failure', $result['result']);
		$this->assertNotEmpty(wc_get_notices('error'));
		$this->assertSame(0, $calls);
		wc_clear_notices();
	}

	/** @return array<string, array{string}> */
	public static function malformed_keys(): array
	{
		return [
			'trailing newline' => ["sk_test_019a0000-0000-7000-8000-000000000001_abc\n"],
			'inner space' => ['sk_test_019a0000-0000-7000-8000-000000000001_a c'],
			'non-ascii' => ['sk_test_é'],
		];
	}
}
