<?php

declare(strict_types=1);

namespace QBitFlowWC;

use QBitFlowWC\Http\WpHttpClient;
use QBitFlowWC\Support\ApiKey;
use QBitFlowWC\Vendor\Nyholm\Psr7\Factory\Psr17Factory;
use QBitFlowWC\Vendor\QBitFlow\QBitFlow;

defined('ABSPATH') || exit;

/**
 * Builds the SDK client from the settings, on the WordPress HTTP API.
 */
final class ClientFactory
{
	public const DEFAULT_TIMEOUT = 20.0;

	public const DEFAULT_MAX_RETRIES = 2;

	/**
	 * The client, or null without an API key (or with one the SDK refuses: logged).
	 *
	 * The base URL is the SDK's (`https://api.qbitflow.app/v2`) unless `QBITFLOW_WC_API_BASE_URL`
	 * is defined (staging). `qbitflow_wc_client_options` adjusts `timeout` (seconds per
	 * attempt), `maxRetries` and `baseUrl`.
	 */
	public static function make(?float $timeout = null, ?int $maxRetries = null): ?QBitFlow
	{
		$key = Settings::api_key();
		$format = ApiKey::format($key);
		if ($format === 'empty') {
			return null;
		}
		if ($format !== 'ok') {
			// A legacy key is refused by the API (401) and a malformed one by the SDK: skip both.
			Log::error('QBitFlow client not created: the API key is in ' . ($format === 'legacy' ? 'the old' : 'an unrecognised') . ' format.');

			return null;
		}

		/**
		 * Filters the QBitFlow client's options.
		 *
		 * @param array{timeout: float, maxRetries: int, baseUrl: ?string} $options
		 */
		$options = (array) apply_filters('qbitflow_wc_client_options', [
			'timeout' => $timeout ?? self::DEFAULT_TIMEOUT,
			'maxRetries' => $maxRetries ?? self::DEFAULT_MAX_RETRIES,
			'baseUrl' => defined('QBITFLOW_WC_API_BASE_URL') ? (string) constant('QBITFLOW_WC_API_BASE_URL') : null,
		]);
		$timeout = (float) ($options['timeout'] ?? self::DEFAULT_TIMEOUT);
		$factory = new Psr17Factory();

		try {
			return new QBitFlow(
				apiKey: $key,
				baseUrl: ($options['baseUrl'] ?? null) ?: null,
				timeout: $timeout,
				maxRetries: (int) ($options['maxRetries'] ?? self::DEFAULT_MAX_RETRIES),
				httpClient: new WpHttpClient($timeout, $factory),
				requestFactory: $factory,
				streamFactory: $factory,
			);
		} catch (\Throwable) {
			// The SDK's message may quote the key: log the fact only.
			Log::error('QBitFlow client not created: the SDK refused the API key.');

			return null;
		}
	}
}
