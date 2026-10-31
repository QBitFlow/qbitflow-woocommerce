<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration;

use QBitFlowWC\ClientFactory;
use QBitFlowWC\Tests\Integration\Support\Fixtures;
use QBitFlowWC\Tests\Integration\Support\TestCase;

final class ClientFactoryTest extends TestCase
{
	public function test_null_without_key(): void
	{
		$this->set_settings(['api_key' => '']);

		$this->assertNull(ClientFactory::make());
	}

	public function test_null_for_a_key_the_sdk_refuses(): void
	{
		$this->set_settings(['api_key' => 'not-a-key']);

		$this->assertNull(ClientFactory::make());
	}

	public function test_base_url_filter(): void
	{
		$this->api->uninstall();
		$urls = [];
		add_filter('pre_http_request', static function ($pre, $args, $url) use (&$urls) {
			$urls[] = $url;

			return ['headers' => [], 'body' => wp_json_encode(Fixtures::me()), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
		}, 10, 3);
		add_filter('qbitflow_wc_client_options', static fn (array $o): array => ['baseUrl' => 'https://staging.example.com/v2'] + $o);

		ClientFactory::make()->me();

		$this->assertSame(['https://staging.example.com/v2/me'], $urls);
	}

	public function test_default_timeout_and_retries(): void
	{
		$seen = null;
		add_filter('qbitflow_wc_client_options', static function (array $o) use (&$seen): array {
			$seen = $o;

			return $o;
		});

		ClientFactory::make();

		$this->assertSame(20.0, $seen['timeout']);
		$this->assertSame(2, $seen['maxRetries']);
		$this->assertNull($seen['baseUrl']);
	}

	public function test_null_for_a_legacy_key(): void
	{
		$this->set_settings(['api_key' => 'sk_019a0000-0000-7000-8000-000000000001_test_secret']);

		$this->assertNull(ClientFactory::make());
	}
}
