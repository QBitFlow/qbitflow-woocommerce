<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration\Support;

use PHPUnit\Framework\Assert;
use WpOrg\Requests\Utility\CaseInsensitiveDictionary;

/**
 * A fake QBitFlow API behind the WordPress HTTP API (`pre_http_request`): the plugin's real
 * client, adapter and SDK run; only the network is replaced. Any outbound request it has no
 * route for fails the test, so a test also proves which calls did not happen.
 */
final class FakeApi
{
	public const BASE = 'https://api.qbitflow.app/v2';

	/** @var array<string, list<array{status:int, body:string, headers:array<string,string>}>> */
	private array $routes = [];

	/** @var array<string, int> How many responses of each route were served. */
	private array $served = [];

	/** @var list<array{method:string, url:string, path:string, headers:array<string,string>, body:string, json:mixed}> */
	private array $requests = [];

	/** @var \Closure */
	private $filter;

	public static function install(): self
	{
		$api = new self();
		$api->filter = \Closure::fromCallable([$api, 'intercept']);
		add_filter('pre_http_request', $api->filter, 10, 3);

		return $api;
	}

	public function uninstall(): void
	{
		remove_filter('pre_http_request', $this->filter, 10);
	}

	/**
	 * Queues a response for `$method` on a path matching `$pathRegex` (relative to /v2, e.g.
	 * `#^/checkout-sessions/payment$#`). Responses of one route are served in the order they
	 * were queued; the last one is served again once all were used.
	 *
	 * @param array<mixed>|string   $body
	 * @param array<string, string> $headers
	 */
	public function on(string $method, string $pathRegex, int $status, array|string $body = '', array $headers = []): self
	{
		$this->routes[strtoupper($method) . ' ' . $pathRegex][] = [
			'status' => $status,
			'body' => is_array($body) ? (string) wp_json_encode($body) : $body,
			'headers' => $headers + ['content-type' => 'application/json'],
		];

		return $this;
	}

	/**
	 * An API error the way the API answers it (`{"error": "<message>", "code", "details"}`).
	 *
	 * @param array<string, mixed> $details
	 */
	public function onError(string $method, string $pathRegex, int $status, string $code, string $message = 'error', array $details = []): self
	{
		$body = ['error' => $message, 'code' => $code];
		if ($details !== []) {
			$body['details'] = $details;
		}

		return $this->on($method, $pathRegex, $status, $body);
	}

	/** @return list<array{method:string, url:string, path:string, headers:array<string,string>, body:string, json:mixed}> */
	public function requests(?string $method = null, ?string $pathRegex = null): array
	{
		return array_values(array_filter(
			$this->requests,
			static fn (array $r): bool => ($method === null || $r['method'] === strtoupper($method))
				&& ($pathRegex === null || preg_match($pathRegex, $r['path']) === 1)
		));
	}

	/** @return array{method:string, url:string, path:string, headers:array<string,string>, body:string, json:mixed} */
	public function lastRequest(string $method, string $pathRegex): array
	{
		$found = $this->requests($method, $pathRegex);
		Assert::assertNotEmpty($found, "no {$method} request matched {$pathRegex}");

		return $found[array_key_last($found)];
	}

	/**
	 * @param false|array<mixed>|\WP_Error $preempt
	 * @param array<string, mixed>         $args
	 * @return array<string, mixed>|\WP_Error
	 */
	private function intercept($preempt, array $args, string $url)
	{
		if (! str_starts_with($url, self::BASE)) {
			Assert::fail("unexpected outbound HTTP request to {$url}");
		}
		$path = (string) parse_url(substr($url, strlen(self::BASE)), PHP_URL_PATH);
		$method = strtoupper((string) ($args['method'] ?? 'GET'));
		$headers = [];
		foreach ((array) ($args['headers'] ?? []) as $name => $value) {
			$headers[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : (string) $value;
		}
		$body = (string) ($args['body'] ?? '');
		$this->requests[] = [
			'method' => $method,
			'url' => $url,
			'path' => $path,
			'headers' => $headers,
			'body' => $body,
			'json' => $body === '' ? null : json_decode($body, true),
		];

		foreach ($this->routes as $key => $queue) {
			[$routeMethod, $regex] = explode(' ', $key, 2);
			if ($routeMethod !== $method || preg_match($regex, $path) !== 1) {
				continue;
			}
			$index = $this->served[$key] ?? 0;
			$response = $queue[min($index, count($queue) - 1)];
			$this->served[$key] = $index + 1;

			return [
				'headers' => new CaseInsensitiveDictionary($response['headers']),
				'body' => $response['body'],
				'response' => ['code' => $response['status'], 'message' => get_status_header_desc($response['status'])],
				'cookies' => [],
				'filename' => null,
			];
		}

		Assert::fail("FakeApi has no route for {$method} {$path}");
	}
}
