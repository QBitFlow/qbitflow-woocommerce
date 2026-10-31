<?php

declare(strict_types=1);

namespace QBitFlowWC;

use QBitFlowWC\Admin\StatusPanel;
use QBitFlowWC\Support\ApiKey;

defined('ABSPATH') || exit;

/**
 * The gateway's settings (the `woocommerce_qbitflow_settings` option WooCommerce manages) and
 * the store's id, read without building the gateway.
 */
final class Settings
{
	public const OPTION = 'woocommerce_qbitflow_settings';

	public const STORE_ID_OPTION = 'qbitflow_wc_store_id';

	/** @return array<string, mixed> */
	public static function all(): array
	{
		$settings = get_option(self::OPTION, []);

		return is_array($settings) ? $settings : [];
	}

	public static function get(string $key, mixed $default = null): mixed
	{
		return self::all()[$key] ?? $default;
	}

	/** The key as stored (saving trims it): a stray blank inside or around it makes it invalid. */
	public static function api_key(): string
	{
		return (string) self::get('api_key', '');
	}

	/** The key's mode (`live`, `test`), `''` without a key or for one not in the current format. */
	public static function mode(): string
	{
		return ApiKey::mode(self::api_key());
	}

	/** @return 'ok'|'legacy'|'invalid'|'empty' */
	public static function key_format(): string
	{
		return ApiKey::format(self::api_key());
	}

	public static function enabled(): bool
	{
		return 'yes' === self::get('enabled', 'no');
	}

	/** Taxes, shipping and fees as separate lines on the QBitFlow checkout (default on). */
	public static function itemize(): bool
	{
		return 'no' !== self::get('itemize', 'yes');
	}

	/** Who pays QBitFlow's processing fee as saved: `account` (default), `customer` or `store`. */
	public static function fee_choice(): string
	{
		$choice = self::get('processing_fee', 'account');

		return in_array($choice, ['customer', 'store'], true) ? (string) $choice : 'account';
	}

	/**
	 * What to send as the checkout's `fees.processingFee`: true (customer pays), false (store
	 * pays), null (the QBitFlow account decides: omit it). Always null for a member's key (the
	 * organization decides), and while that is unknown: never an explicit value on a guess.
	 * `$resolve` lets one `me()` call find out (checkout creation only; cached after).
	 */
	public static function processing_fee(bool $resolve = false): ?bool
	{
		$choice = self::fee_choice();
		if ('account' === $choice || StatusPanel::member($resolve) !== false) {
			return null;
		}

		return 'customer' === $choice;
	}

	public static function debug(): bool
	{
		return 'yes' === self::get('debug', 'no');
	}

	/** The webhook signing secret pasted by hand (a key that cannot register endpoints). */
	public static function manual_webhook_secret(): string
	{
		return trim((string) self::get('webhook_secret', ''));
	}

	/**
	 * This store's id: 8 characters `[a-z0-9]`, made once per site address. It prefixes every
	 * reference the plugin sends, so two stores sharing a QBitFlow account never collide, and lets
	 * the webhook tell this store's events from the account's others. A copy of the site at
	 * another address (a staging clone) gets its own id, so its orders can't pass for this one's.
	 */
	public static function store_id(): string
	{
		$site = self::site();
		$stored = get_option(self::STORE_ID_OPTION, '');
		if (is_string($stored) && preg_match('/^[a-z0-9]{8}$/', $stored) === 1) {
			$stored = ['id' => $stored, 'site' => $site]; // 2.0 betas stored the id alone.
			update_option(self::STORE_ID_OPTION, $stored, true);
		}
		if (is_array($stored) && preg_match('/^[a-z0-9]{8}$/', (string) ($stored['id'] ?? '')) === 1 && ($stored['site'] ?? '') === $site) {
			return (string) $stored['id'];
		}

		$id = '';
		$alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
		for ($i = 0; $i < 8; $i++) {
			$id .= $alphabet[random_int(0, strlen($alphabet) - 1)];
		}
		update_option(self::STORE_ID_OPTION, ['id' => $id, 'site' => $site], true);

		return $id;
	}

	/** The site address as stored (not per request), without scheme or trailing slash. */
	private static function site(): string
	{
		$url = strtolower(untrailingslashit((string) get_option('siteurl')));

		return (string) preg_replace('#^https?://#', '', $url);
	}
}
