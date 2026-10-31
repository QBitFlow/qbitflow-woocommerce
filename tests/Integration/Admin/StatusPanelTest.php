<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration\Admin;

use QBitFlowWC\Admin\StatusPanel;
use QBitFlowWC\Checkout\SessionManager;
use QBitFlowWC\Tests\Integration\Support\Fixtures;
use QBitFlowWC\Tests\Integration\Support\TestCase;
use QBitFlowWC\Webhooks\EndpointManager;

final class StatusPanelTest extends TestCase
{
	private function ready_api(): void
	{
		$this->member_status_api([Fixtures::currency(), Fixtures::currency('SOL', 'SOL')]);
	}

	private static function rendered(): string
	{
		ob_start();
		StatusPanel::render();

		return (string) ob_get_clean();
	}

	public function test_reports_org_mode_currencies(): void
	{
		$this->ready_api();

		$data = StatusPanel::data();

		$this->assertTrue($data['connected']);
		$this->assertSame('Example Shop', $data['organization']);
		$this->assertTrue($data['member']);
		$this->assertTrue($data['test']);
		$this->assertSame(['USDC (BASE)', 'SOL (BASE)'], $data['currencies']);
		$this->assertNull($data['webhook']);
		$this->assertFalse(StatusPanel::known_not_ready());
	}

	public function test_not_ready_when_no_currency(): void
	{
		$this->api->on('GET', '#^/me$#', 200, Fixtures::me());
		$this->api->on('GET', '#^/wallets/accepted-currencies$#', 200, Fixtures::page([]));

		StatusPanel::data();

		$this->assertTrue(StatusPanel::known_not_ready());
	}

	public function test_cached_until_refresh(): void
	{
		$this->ready_api();

		StatusPanel::data();
		StatusPanel::data();
		$this->assertCount(1, $this->api->requests('GET', '#^/me$#'));

		StatusPanel::data(true);
		$this->assertCount(2, $this->api->requests('GET', '#^/me$#'));
	}

	public function test_cache_ignored_after_key_change(): void
	{
		$this->ready_api();
		StatusPanel::data();

		$this->set_settings(['api_key' => self::LIVE_KEY]);
		StatusPanel::data();

		$this->assertCount(2, $this->api->requests('GET', '#^/me$#'));
	}

	public function test_api_error_shown_not_thrown(): void
	{
		$this->api->onError('GET', '#^/me$#', 401, 'unauthorized', 'Invalid API key');

		$data = StatusPanel::data();

		$this->assertFalse($data['connected']);
		$this->assertSame('Invalid API key', $data['error']);
	}

	public function test_webhook_state_from_api(): void
	{
		$this->ready_api();
		update_option('qbitflow_wc_webhook_test', ['uuid' => '019a0000-0000-7000-8000-0000000e0004', 'secret' => Fixtures::SECRET, 'mode' => 'test', 'url' => EndpointManager::url(), 'space' => 'space-1', 'connected_at' => time()]);
		$this->api->on('GET', '#^/webhooks/endpoints/019a0000-0000-7000-8000-0000000e0004$#', 200, Fixtures::endpoint('019a0000-0000-7000-8000-0000000e0004', EndpointManager::url(), true, [
			'failingSince' => gmdate('Y-m-d\TH:i:s\Z', time() - 7200),
			'lastDeliveredAt' => gmdate('Y-m-d\TH:i:s\Z', time() - 9000),
		]));

		$webhook = StatusPanel::data()['webhook'];

		$this->assertSame('failing', $webhook['state']);
		$this->assertTrue($webhook['url_matches']);
		$this->assertTrue($webhook['space_matches']);
	}

	public function test_deleted_endpoint_is_missing(): void
	{
		$this->ready_api();
		update_option('qbitflow_wc_webhook_test', ['uuid' => '019a0000-0000-7000-8000-0000000e0004', 'secret' => Fixtures::SECRET, 'mode' => 'test', 'url' => EndpointManager::url(), 'space' => 'space-1', 'connected_at' => time()]);
		$this->api->onError('GET', '#^/webhooks/endpoints/019a0000-0000-7000-8000-0000000e0004$#', 404, 'not_found', 'Not found');

		$this->assertSame(['state' => 'missing'], StatusPanel::data()['webhook']);
	}

	public function test_legacy_key_says_old_format_without_calling_the_api(): void
	{
		$this->set_settings(['api_key' => 'sk_019a0000-0000-7000-8000-000000000001_live_secret']);

		$data = \QBitFlowWC\Admin\StatusPanel::data(true);

		$this->assertFalse($data['connected']);
		$this->assertStringContainsString('old format', (string) $data['error']);
	}

	public function test_panel_says_return_addresses_are_off_while_known(): void
	{
		$this->ready_api();

		$this->assertStringNotContainsString('turned off return addresses', self::rendered());
		set_transient(SessionManager::REDIRECTS_OFF_TRANSIENT, 1, HOUR_IN_SECONDS);
		$this->assertStringContainsString('Your organization turned off return addresses for members', self::rendered());
		delete_transient(SessionManager::REDIRECTS_OFF_TRANSIENT);
	}

	public function test_member_whose_checkouts_are_off_is_not_ready(): void
	{
		$this->api->on('GET', '#^/me$#', 200, Fixtures::me(true, true));
		$this->api->on('GET', '#^/wallets/accepted-currencies$#', 200, Fixtures::page([Fixtures::currency()]));
		$this->api->onError('GET', self::CHECKOUTS_PROBE, 403, 'policy_disabled', 'Policy disabled', ['policy' => 'members.products']);

		$html = self::rendered();

		$this->assertTrue(StatusPanel::checkouts_off());
		$this->assertTrue(StatusPanel::known_not_ready());
		$this->assertStringContainsString('turned off its members&#039; own checkouts', $html);
	}

	public function test_member_whose_checkouts_are_back_on_is_ready_again(): void
	{
		set_transient(StatusPanel::CHECKOUTS_OFF_TRANSIENT, 1, HOUR_IN_SECONDS);
		$this->ready_api();

		StatusPanel::data(true);

		$this->assertFalse(StatusPanel::checkouts_off());
		$this->assertFalse(StatusPanel::known_not_ready());
	}

	public function test_another_policy_or_error_leaves_the_checkouts_state_alone(): void
	{
		$this->api->on('GET', '#^/me$#', 200, Fixtures::me(true, true));
		$this->api->on('GET', '#^/wallets/accepted-currencies$#', 200, Fixtures::page([Fixtures::currency()]));
		$this->api->onError('GET', self::CHECKOUTS_PROBE, 403, 'policy_disabled', 'Policy disabled', ['policy' => 'members.something']);

		StatusPanel::data(true);

		$this->assertFalse(StatusPanel::checkouts_off());
	}

	public function test_organization_key_is_not_probed(): void
	{
		$this->api->on('GET', '#^/me$#', 200, Fixtures::me(true, false));
		$this->api->on('GET', '#^/wallets/accepted-currencies$#', 200, Fixtures::page([Fixtures::currency()]));

		StatusPanel::data(true);

		$this->assertCount(0, $this->api->requests('GET', self::CHECKOUTS_PROBE));
	}
}
