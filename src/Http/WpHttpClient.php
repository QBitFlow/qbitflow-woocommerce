<?php

declare(strict_types=1);

namespace QBitFlowWC\Http;

use QBitFlowWC\Vendor\Psr\Http\Client\ClientInterface;
use QBitFlowWC\Vendor\Psr\Http\Message\RequestInterface;
use QBitFlowWC\Vendor\Psr\Http\Message\ResponseFactoryInterface;
use QBitFlowWC\Vendor\Psr\Http\Message\ResponseInterface;
use QBitFlowWC\Vendor\Psr\Http\Message\StreamFactoryInterface;

defined('ABSPATH') || exit;

/**
 * The SDK's PSR-18 client, on the WordPress HTTP API: the site's proxy settings (`WP_PROXY_*`),
 * transports and filters (`http_request_args`, `pre_http_request`, `http_api_curl`) apply as
 * they do to WordPress's own requests, with no HTTP library bundled.
 *
 * Redirects are never followed (the API never redirects; following one would replay the API
 * key to whatever host it names) and TLS is always verified. `qbitflow_wc_http_request_args`
 * adjusts the rest per request.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A PSR-18 exception for the SDK: never output.
final class WpHttpClient implements ClientInterface
{
	public function __construct(
		private readonly float $timeout,
		private readonly ResponseFactoryInterface&StreamFactoryInterface $factory,
	) {
	}

	public function sendRequest(RequestInterface $request): ResponseInterface
	{
		$url = (string) $request->getUri();
		$headers = [];
		foreach ($request->getHeaders() as $name => $values) {
			if (strcasecmp((string) $name, 'User-Agent') !== 0) {
				$headers[(string) $name] = implode(', ', $values);
			}
		}
		$body = (string) $request->getBody();

		$args = [
			'method' => $request->getMethod(),
			'headers' => $headers,
			'body' => $body === '' ? null : $body,
			'timeout' => $this->timeout,
			'user-agent' => self::userAgent($request->getHeaderLine('User-Agent')),
		];
		/**
		 * Filters the WordPress HTTP API arguments of a request to QBitFlow.
		 *
		 * @param array<string, mixed> $args
		 * @param string               $url
		 */
		$args = (array) apply_filters('qbitflow_wc_http_request_args', $args, $url);
		$args['redirection'] = 0;
		$args['sslverify'] = true;

		$response = wp_remote_request($url, $args);
		if (is_wp_error($response)) {
			throw new NetworkException($response->get_error_message(), $request);
		}

		$psr = $this->factory->createResponse(
			(int) wp_remote_retrieve_response_code($response),
			(string) wp_remote_retrieve_response_message($response),
		);
		foreach (wp_remote_retrieve_headers($response) as $name => $value) {
			$psr = $psr->withAddedHeader((string) $name, is_array($value) ? array_map('strval', $value) : (string) $value);
		}

		return $psr->withBody($this->factory->createStream((string) wp_remote_retrieve_body($response)));
	}

	/** The SDK's user agent, plus the plugin's, WordPress's and WooCommerce's versions. */
	private static function userAgent(string $sdk): string
	{
		$parts = [$sdk !== '' ? $sdk : 'qbitflow-php', 'qbitflow-for-woocommerce/' . QBITFLOW_WC_VERSION];
		$parts[] = 'WordPress/' . get_bloginfo('version');
		if (defined('WC_VERSION')) {
			$parts[] = 'WooCommerce/' . WC_VERSION;
		}

		return implode(' ', $parts);
	}
}
// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
