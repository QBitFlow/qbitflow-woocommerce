<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration\Webhooks;

use QBitFlowWC\Admin\Actions;
use QBitFlowWC\Admin\Notices;
use QBitFlowWC\Tests\Integration\Support\Fixtures;
use QBitFlowWC\Tests\Integration\Support\TestCase;
use QBitFlowWC\Webhooks\EndpointManager;
use RuntimeException;

final class EndpointManagerTest extends TestCase
{
	private function connect_api(bool $test = true): void
	{
		$url = EndpointManager::url();
		$this->api->on('GET', '#^/me$#', 200, Fixtures::me($test));
		$this->api->on('GET', '#^/webhooks/endpoints$#', 200, Fixtures::page([
			Fixtures::endpoint('019a0000-0000-7000-8000-0000000e0001', $url, $test),
			Fixtures::endpoint('019a0000-0000-7000-8000-0000000e0002', 'https://elsewhere.example.com/hook', $test),
		]));
		$this->api->on('DELETE', '#^/webhooks/endpoints/019a0000-0000-7000-8000-0000000e0001$#', 204);
		$this->api->on('POST', '#^/webhooks/endpoints$#', 201, Fixtures::endpoint('019a0000-0000-7000-8000-0000000e0003', $url, $test) + ['secret' => 'whsec_new']);
	}

	public function test_connect_deletes_same_url_then_creates(): void
	{
		$this->connect_api();

		$stored = EndpointManager::connect();

		$this->assertCount(1, $this->api->requests('DELETE'));
		$this->assertSame('/webhooks/endpoints/019a0000-0000-7000-8000-0000000e0001', $this->api->requests('DELETE')[0]['path']);
		$body = $this->api->lastRequest('POST', '#^/webhooks/endpoints$#')['json'];
		$this->assertSame(EndpointManager::url(), $body['url']);
		$this->assertEqualsCanonicalizing(['payment.completed', 'checkout.expired', 'refund.requested', 'refund.approved', 'refund.rejected'], $body['events']);
		$this->assertFalse($body['includeMembers']);
		$this->assertStringStartsWith('WooCommerce: ', $body['description']);
		$this->assertSame('019a0000-0000-7000-8000-0000000e0003', $stored['uuid']);
		$this->assertSame('whsec_new', EndpointManager::secret());
		$this->assertSame('space-1', $stored['space']);
		$this->assertFalse(EndpointManager::needs_connect());
	}

	public function test_url_defaults_to_the_rest_route(): void
	{
		$this->assertSame(rest_url('qbitflow-wc/v2/webhook'), EndpointManager::url());
	}

	public function test_dev_override_is_registered_and_compared(): void
	{
		add_filter('qbitflow_wc_webhook_url', static fn (): string => 'http://host.docker.internal:8889/wp-json/qbitflow-wc/v2/webhook');
		$this->connect_api();

		$stored = EndpointManager::connect();

		$this->assertSame('http://host.docker.internal:8889/wp-json/qbitflow-wc/v2/webhook', $this->api->lastRequest('POST', '#^/webhooks/endpoints$#')['json']['url']);
		$this->assertSame('http://host.docker.internal:8889/wp-json/qbitflow-wc/v2/webhook', $stored['url']);
		$this->assertSame('/webhooks/endpoints/019a0000-0000-7000-8000-0000000e0001', $this->api->requests('DELETE')[0]['path']);
		$this->assertFalse(EndpointManager::needs_connect());
	}

	/** @dataProvider invalid_overrides */
	public function test_invalid_override_falls_back_to_the_rest_route(mixed $value): void
	{
		add_filter('qbitflow_wc_webhook_url', static fn (): mixed => $value);

		$this->assertSame(rest_url('qbitflow-wc/v2/webhook'), EndpointManager::url());
	}

	public static function invalid_overrides(): array
	{
		return [['ftp://example.com/hook'], ['/relative/path'], ['javascript:alert(1)'], ['not a url'], [''], [null], [42]];
	}

	public function test_legacy_key_does_not_offer_connect_and_connect_names_the_format(): void
	{
		$this->set_settings(['api_key' => 'sk_019a0000-0000-7000-8000-000000000001_live_secret']);

		$this->assertFalse(EndpointManager::needs_connect());
		try {
			EndpointManager::connect();
			$this->fail('expected a RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('old format', $e->getMessage());
			$this->assertStringNotContainsString('Enter and save', $e->getMessage());
		}
	}

	public function test_invalid_key_does_not_offer_connect_and_connect_names_the_format(): void
	{
		$this->set_settings(['api_key' => 'garbage']);

		$this->assertFalse(EndpointManager::needs_connect());
		try {
			EndpointManager::connect();
			$this->fail('expected a RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('not a QBitFlow key', $e->getMessage());
		}
	}

	public function test_empty_key_keeps_the_enter_and_save_message(): void
	{
		$this->set_settings(['api_key' => '']);

		try {
			EndpointManager::connect();
			$this->fail('expected a RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('Enter and save', $e->getMessage());
		}
	}

	public function test_old_endpoint_kept_when_creating_fails(): void
	{
		$this->api->on('GET', '#^/me$#', 200, Fixtures::me());
		$this->api->on('GET', '#^/webhooks/endpoints$#', 200, Fixtures::page([Fixtures::endpoint('019a0000-0000-7000-8000-0000000e0001', EndpointManager::url())]));
		$this->api->onError('POST', '#^/webhooks/endpoints$#', 503, 'unavailable', 'down');
		add_filter('qbitflow_wc_client_options', static fn (array $o): array => ['maxRetries' => 0] + $o);

		try {
			EndpointManager::connect();
			$this->fail('expected a RuntimeException');
		} catch (RuntimeException) {
		}

		$this->assertSame([], $this->api->requests('DELETE'));
	}

	public function test_site_name_with_disallowed_characters_still_connects(): void
	{
		update_option('blogname', 'Joe\'s "Best" | Shop; <since 1999>');
		$this->connect_api();

		EndpointManager::connect();

		$description = $this->api->lastRequest('POST', '#^/webhooks/endpoints$#')['json']['description'];
		$this->assertSame('WooCommerce: Joe\'s ″Best″ / Shop, since 1999', $description);
	}

	public function test_secret_is_per_mode(): void
	{
		$this->connect_api();
		EndpointManager::connect();

		$this->set_settings(['api_key' => self::LIVE_KEY]);

		$this->assertNull(EndpointManager::secret());
		$this->assertTrue(EndpointManager::needs_connect());
	}

	public function test_manual_secret_used_when_not_connected(): void
	{
		$this->set_settings(['webhook_secret' => ' whsec_manual ']);
		WC()->payment_gateways()->payment_gateways()['qbitflow']->validate_webhook_secret_field('webhook_secret', ' whsec_manual ');

		$this->assertSame('whsec_manual', trim((string) EndpointManager::secret()));
	}

	public function test_policy_disabled_maps_to_manual_hint(): void
	{
		$this->api->on('GET', '#^/me$#', 200, Fixtures::me(true, true));
		$this->api->onError('GET', '#^/webhooks/endpoints$#', 403, 'policy_disabled', 'Policy disabled');

		try {
			EndpointManager::connect();
			$this->fail('expected a RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('paste its signing secret', $e->getMessage());
		}
		$this->assertNull(EndpointManager::stored());
	}

	public function test_role_refusal_shows_the_api_text_as_is(): void
	{
		$text = "Only your organization's owner and admins can do this";
		$this->api->on('GET', '#^/me$#', 200, Fixtures::me(true));
		$this->api->onError('GET', '#^/webhooks/endpoints$#', 403, 'forbidden', $text);

		try {
			EndpointManager::connect();
			$this->fail('expected a RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString($text, html_entity_decode($e->getMessage(), ENT_QUOTES));
		}
		$this->assertNull(EndpointManager::stored());
	}

	private function at_cap_api(bool $ours): void
	{
		$url = EndpointManager::url();
		$items = [];
		for ($i = 1; $i <= ($ours ? 9 : 10); $i++) {
			$items[] = Fixtures::endpoint(sprintf('019a0000-0000-7000-8000-00000000f%03d', $i), 'https://other.example.com/hook' . $i, true);
		}
		if ($ours) {
			$items[] = Fixtures::endpoint('019a0000-0000-7000-8000-0000000e0001', $url, true);
		}
		$this->api->on('GET', '#^/me$#', 200, Fixtures::me(true));
		$this->api->on('GET', '#^/webhooks/endpoints$#', 200, Fixtures::page($items));
	}

	public function test_reconnect_at_the_endpoint_limit_deletes_ours_then_creates(): void
	{
		$this->at_cap_api(true);
		$this->api->onError('POST', '#^/webhooks/endpoints$#', 409, 'conflict', 'Too many endpoints');
		$this->api->on('DELETE', '#^/webhooks/endpoints/019a0000-0000-7000-8000-0000000e0001$#', 204);
		$this->api->on('POST', '#^/webhooks/endpoints$#', 201, Fixtures::endpoint('019a0000-0000-7000-8000-0000000e0003', EndpointManager::url(), true) + ['secret' => 'whsec_new']);

		$stored = EndpointManager::connect();

		$this->assertSame('whsec_new', $stored['secret']);
		$this->assertCount(2, $this->api->requests('POST', '#^/webhooks/endpoints$#'));
		$this->assertCount(1, $this->api->requests('DELETE'));
		$this->assertSame('whsec_new', EndpointManager::stored('test')['secret']);
	}

	public function test_limit_without_an_endpoint_of_ours_shows_the_api_text(): void
	{
		$this->at_cap_api(false);
		$this->api->onError('POST', '#^/webhooks/endpoints$#', 409, 'conflict', 'Too many endpoints');

		try {
			EndpointManager::connect();
			$this->fail('expected a RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('Too many endpoints', $e->getMessage());
		}
		$this->assertCount(0, $this->api->requests('DELETE'));
		$this->assertCount(1, $this->api->requests('POST', '#^/webhooks/endpoints$#'));
	}

	public function test_other_conflict_neither_deletes_nor_retries(): void
	{
		$this->at_cap_api(true);
		$this->api->onError('POST', '#^/webhooks/endpoints$#', 409, 'idempotency_key_reused', 'Key reused');

		try {
			EndpointManager::connect();
			$this->fail('expected a RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('Key reused', $e->getMessage());
		}
		$this->assertCount(0, $this->api->requests('DELETE'));
		$this->assertCount(1, $this->api->requests('POST', '#^/webhooks/endpoints$#'));
	}

	public function test_connect_without_key(): void
	{
		$this->set_settings(['api_key' => '']);

		$this->expectException(RuntimeException::class);
		EndpointManager::connect();
	}

	public function test_connect_action_requires_capability(): void
	{
		wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
		$_REQUEST['_wpnonce'] = wp_create_nonce(Actions::CONNECT_WEBHOOK);

		$this->expectException(\WPDieException::class);
		Actions::run(Actions::CONNECT_WEBHOOK);
	}

	public function test_connect_action_requires_nonce(): void
	{
		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
		$_REQUEST['_wpnonce'] = 'bad';

		$this->expectException(\WPDieException::class);
		Actions::run(Actions::CONNECT_WEBHOOK);
	}

	public function test_connect_action_connects_and_notifies(): void
	{
		$this->connect_api();
		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
		$_REQUEST['_wpnonce'] = wp_create_nonce(Actions::CONNECT_WEBHOOK);

		$redirect = Actions::run(Actions::CONNECT_WEBHOOK);

		$this->assertStringContainsString('section=qbitflow', $redirect);
		$this->assertSame('success', Notices::take()['type']);
		$this->assertSame('whsec_new', EndpointManager::secret());
	}

	public function tear_down(): void
	{
		unset($_REQUEST['_wpnonce']);
		parent::tear_down();
	}
}
