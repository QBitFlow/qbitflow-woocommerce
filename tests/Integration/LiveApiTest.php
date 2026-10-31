<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration;

use QBitFlowWC\Checkout\SessionManager;
use QBitFlowWC\ClientFactory;
use QBitFlowWC\Orders\OrderMeta;
use QBitFlowWC\Tests\Integration\Support\TestCase;

/**
 * End to end against a real QBitFlow API (a test-mode key), through the plugin's adapter.
 * Skipped unless QBITFLOW_API_KEY is set; fails when it is set without QBITFLOW_BASE_URL (never
 * aims at production by accident). Every checkout it opens is expired.
 *
 *   npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/qbitflow-for-woocommerce \
 *     env QBITFLOW_API_KEY=sk_… QBITFLOW_BASE_URL=https://… vendor/bin/phpunit -c phpunit-integration.xml.dist --group live
 *
 * @group live
 */
final class LiveApiTest extends TestCase
{
	public function set_up(): void
	{
		parent::set_up();
		$key = (string) getenv('QBITFLOW_API_KEY');
		$base = (string) getenv('QBITFLOW_BASE_URL');
		if (trim($key) === '') {
			$this->markTestSkipped('QBITFLOW_API_KEY not set: live checks skipped.');
		}
		if (trim($base) === '') {
			$this->fail('QBITFLOW_API_KEY is set without QBITFLOW_BASE_URL: refusing to default to production.');
		}
		if (! str_contains($key, '_test_')) {
			$this->fail('Live checks write data: use a test-mode key.');
		}
		$this->api->uninstall();
		$this->set_settings(['api_key' => $key]);
		add_filter('qbitflow_wc_client_options', static fn (array $o): array => ['baseUrl' => $base] + $o);
	}

	public function test_me_through_the_adapter(): void
	{
		$me = ClientFactory::make()->me();

		$this->assertTrue($me->space->test);
	}

	public function test_itemized_checkout_round_trip(): void
	{
		$order = $this->make_order([['name' => 'Live test item', 'price' => '1.00']], [
			'shipping' => '0.50',
			'taxes' => [['label' => 'VAT (20%)', 'amount' => '0.30']],
		]);
		$client = ClientFactory::make();

		$link = SessionManager::start($order);
		$uuid = (string) wc_get_order($order->get_id())->get_meta(OrderMeta::SESSION_UUID);
		try {
			$this->assertStringContainsString($uuid, $link);
			$this->assertSame('created', $client->checkoutSessions->get($uuid)->status);
			$this->assertSame($link, SessionManager::start(wc_get_order($order->get_id())), 'reused');
		} finally {
			$client->checkoutSessions->expire($uuid);
		}
		$this->assertSame('expired', $client->checkoutSessions->get($uuid)->status);
	}
}
