<?php

declare(strict_types=1);

namespace QBitFlowWC\Checkout;

use QBitFlowWC\ClientFactory;
use QBitFlowWC\Log;
use QBitFlowWC\Settings;
use QBitFlowWC\Vendor\QBitFlow\Exceptions\QBitFlowException;
use QBitFlowWC\Vendor\QBitFlow\Models\Currency;

defined('ABSPATH') || exit;

/**
 * The coins the store accepts, shown at checkout. Read from a 1 hour cache only: it is filled
 * by the status panel and by an Action Scheduler job, never during a customer request. A cold,
 * empty or failed list shows nothing.
 */
final class AcceptedCoins
{
	public const TRANSIENT = 'qbitflow_wc_coins';

	public const REFRESH_HOOK = 'qbitflow_wc_refresh_coins';

	/** Set for 5 minutes after a failed refresh: no new refresh is queued meanwhile. */
	public const BACKOFF_TRANSIENT = 'qbitflow_wc_coins_backoff';

	private const GROUP = 'qbitflow';

	private const CHAINS = ['ETH' => 'Ethereum', 'BASE' => 'Base', 'SOL' => 'Solana'];

	public static function init(): void
	{
		add_action(self::REFRESH_HOOK, [self::class, 'refresh']);
	}

	/** The line for the checkout ("Accepted coins: ..."), or '' when cold, empty or turned off. Never calls the API. */
	public static function label(): string
	{
		if (Settings::get('show_coins', 'yes') === 'no') {
			return '';
		}
		$coins = get_transient(self::TRANSIENT);
		if (! is_array($coins)) {
			self::schedule_refresh();

			return '';
		}
		$list = self::format(array_values(array_filter($coins, 'is_array')));

		return $list === '' ? '' : sprintf(
			/* translators: %s: list of coins, e.g. "USDC (Ethereum, Base), ETH" */
			__('Accepted coins: %s', 'qbitflow-for-woocommerce'),
			$list
		);
	}

	/** Reads the accepted currencies from QBitFlow and stores them. Called by Action Scheduler. */
	public static function refresh(): void
	{
		$client = ClientFactory::make(10.0, 0);
		if ($client === null) {
			return;
		}
		try {
			self::store($client->wallets->listAcceptedCurrencies());
		} catch (QBitFlowException $e) {
			set_transient(self::BACKOFF_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS);
			Log::error('Accepted coins refresh failed: ' . $e->getMessage());
		}
	}

	/** @param list<Currency> $currencies */
	public static function store(array $currencies): void
	{
		$coins = [];
		foreach ($currencies as $currency) {
			$coins[] = [
				'symbol' => $currency->symbol,
				'chain' => $currency->chain,
				'test' => $currency->test,
				'token' => $currency->address !== null,
			];
		}
		set_transient(self::TRANSIENT, $coins, HOUR_IN_SECONDS);
		delete_transient(self::BACKOFF_TRANSIENT);
	}

	public static function forget(): void
	{
		delete_transient(self::TRANSIENT);
	}

	/**
	 * "USDC (Ethereum, Base), ETH, SOL": grouped by symbol in first-seen order; a token lists
	 * its chains, a native coin is bare. `token` defaults to "symbol differs from chain".
	 *
	 * @param list<array{symbol: string, chain: string, test: bool, token?: bool}> $coins
	 */
	public static function format(array $coins): string
	{
		$groups = [];
		foreach ($coins as $coin) {
			$symbol = (string) ($coin['symbol'] ?? '');
			if ($symbol === '') {
				continue;
			}
			$chain = (string) ($coin['chain'] ?? '');
			$token = (bool) ($coin['token'] ?? ($symbol !== $chain));
			$groups[$symbol] ??= ['chains' => [], 'token' => $token, 'test' => true];
			$groups[$symbol]['token'] = $groups[$symbol]['token'] || $token;
			$groups[$symbol]['test'] = $groups[$symbol]['test'] && (bool) ($coin['test'] ?? false);
			$name = self::CHAINS[$chain] ?? $chain;
			if ($name !== '' && ! in_array($name, $groups[$symbol]['chains'], true)) {
				$groups[$symbol]['chains'][] = $name;
			}
		}

		$parts = [];
		foreach ($groups as $symbol => $group) {
			$part = (string) $symbol;
			if ($group['token'] && $group['chains'] !== []) {
				$part .= ' (' . implode(', ', $group['chains']) . ')';
			}
			if ($group['test']) {
				$part .= ' (test)';
			}
			$parts[] = $part;
		}

		return implode(', ', $parts);
	}

	private static function schedule_refresh(): void
	{
		if (get_transient(self::BACKOFF_TRANSIENT) || Settings::key_format() !== 'ok' || ! function_exists('as_enqueue_async_action') || ! function_exists('as_has_scheduled_action')) {
			return;
		}
		if (! as_has_scheduled_action(self::REFRESH_HOOK, [], self::GROUP)) {
			as_enqueue_async_action(self::REFRESH_HOOK, [], self::GROUP, true);
		}
	}
}
