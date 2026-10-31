<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration\Http;

use QBitFlowWC\ClientFactory;
use QBitFlowWC\Http\NetworkException;
use QBitFlowWC\Http\WpHttpClient;
use QBitFlowWC\Tests\Integration\Support\FakeApi;
use QBitFlowWC\Tests\Integration\Support\Fixtures;
use QBitFlowWC\Tests\Integration\Support\TestCase;
use QBitFlowWC\Vendor\Nyholm\Psr7\Factory\Psr17Factory;

final class WpHttpClientTest extends TestCase
{
	private function client(): WpHttpClient
	{
		return new WpHttpClient(7.0, new Psr17Factory());
	}

	public function test_sends_method_url_headers_body(): void
	{
		$this->api->on('POST', '#^/echo$#', 200, ['ok' => true]);
		$factory = new Psr17Factory();
		$request = $factory->createRequest('POST', FakeApi::BASE . '/echo')
			->withHeader('X-API-Key', 'sk_x')
			->withHeader('Content-Type', 'application/json')
			->withHeader('User-Agent', 'qbitflow-php/3.0.0')
			->withBody($factory->createStream('{"a":1}'));

		$this->client()->sendRequest($request);

		$sent = $this->api->lastRequest('POST', '#^/echo$#');
		$this->assertSame('sk_x', $sent['headers']['x-api-key']);
		$this->assertSame('application/json', $sent['headers']['content-type']);
		$this->assertSame('{"a":1}', $sent['body']);
	}

	public function test_maps_status_headers_body_back(): void
	{
		$this->api->on('POST', '#^/thing$#', 201, ['id' => 'x'], ['Idempotent-Replayed' => 'true', 'X-Request-Id' => 'req-1']);
		$factory = new Psr17Factory();

		$response = $this->client()->sendRequest($factory->createRequest('POST', FakeApi::BASE . '/thing'));

		$this->assertSame(201, $response->getStatusCode());
		$this->assertSame('true', $response->getHeaderLine('Idempotent-Replayed'));
		$this->assertSame('req-1', $response->getHeaderLine('x-request-id'));
		$this->assertSame('{"id":"x"}', (string) $response->getBody());
	}

	public function test_never_follows_redirects_and_always_verifies_tls(): void
	{
		$captured = null;
		$capture = static function (array $args) use (&$captured): array {
			$captured = $args;

			return $args;
		};
		add_filter('http_request_args', $capture);
		// A filter of ours trying to turn them back on is overridden.
		add_filter('qbitflow_wc_http_request_args', static fn (array $args): array => ['redirection' => 5, 'sslverify' => false] + $args);
		$this->api->on('GET', '#^/r$#', 200, []);

		$this->client()->sendRequest((new Psr17Factory())->createRequest('GET', FakeApi::BASE . '/r'));

		$this->assertSame(0, $captured['redirection']);
		$this->assertTrue($captured['sslverify']);
		$this->assertSame(7.0, $captured['timeout']);
		$this->assertStringContainsString('qbitflow-for-woocommerce/' . QBITFLOW_WC_VERSION, $captured['user-agent']);
	}

	public function test_request_args_filter_applies(): void
	{
		add_filter('qbitflow_wc_http_request_args', static fn (array $args): array => ['timeout' => 3] + $args, 10, 1);
		add_filter('qbitflow_wc_http_request_args', static function (array $args): array {
			$args['headers']['X-Extra'] = 'yes';

			return $args;
		});
		$this->api->on('GET', '#^/f$#', 200, []);

		$this->client()->sendRequest((new Psr17Factory())->createRequest('GET', FakeApi::BASE . '/f'));

		$this->assertSame('yes', $this->api->lastRequest('GET', '#^/f$#')['headers']['x-extra']);
	}

	public function test_wp_error_becomes_network_exception(): void
	{
		$this->api->uninstall();
		add_filter('pre_http_request', static fn () => new \WP_Error('http_request_failed', 'cURL error 28: timed out'));
		$request = (new Psr17Factory())->createRequest('GET', FakeApi::BASE . '/down');

		try {
			$this->client()->sendRequest($request);
			$this->fail('expected a NetworkException');
		} catch (NetworkException $e) {
			$this->assertSame('cURL error 28: timed out', $e->getMessage());
			$this->assertSame($request, $e->getRequest());
		}
	}

	public function test_sdk_call_round_trips_through_the_adapter(): void
	{
		$this->api->on('GET', '#^/me$#', 200, Fixtures::me());

		$me = ClientFactory::make()->me();

		$this->assertSame('Example Shop', $me->space->organizationName);
		$this->assertSame(self::TEST_KEY, $this->api->lastRequest('GET', '#^/me$#')['headers']['x-api-key']);
	}

	public function test_sdk_network_failure_surfaces_as_sdk_network_exception(): void
	{
		$this->api->uninstall();
		add_filter('pre_http_request', static fn () => new \WP_Error('http_request_failed', 'Could not resolve host'));

		$this->expectException(\QBitFlowWC\Vendor\QBitFlow\Exceptions\NetworkException::class);
		ClientFactory::make(5.0, 0)->me();
	}
}
