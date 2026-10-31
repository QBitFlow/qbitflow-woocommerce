<?php

declare(strict_types=1);

namespace QBitFlowWC\Support;

defined('ABSPATH') || exit;

/**
 * Reads an API key's shape without WordPress or the SDK: `sk_<live|test>_<uuid>_<secret>`.
 * The 2.0 betas' `sk_<uuid>_<live|test>_<secret>` is "legacy" (the API answers 401 to it).
 * The key is never echoed or logged here.
 */
final class ApiKey
{
	private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

	/** `'live'`, `'test'`, or `''` for anything that is not a current-format key. */
	public static function mode(string $key): string
	{
		return self::format($key) === 'ok' ? explode('_', $key, 3)[1] : '';
	}

	/** @return 'ok'|'legacy'|'invalid'|'empty' */
	public static function format(string $key): string
	{
		if ($key === '') {
			return 'empty';
		}
		// `\z`, not `$`: a trailing newline must not pass. Printable ASCII only, as the SDK requires.
		if (preg_match('/^sk_(?:live|test)_' . self::UUID . '_[\x21-\x7E]+\z/', $key) === 1) {
			return 'ok';
		}
		if (preg_match('/^sk_' . self::UUID . '_(?:live|test)_[\x21-\x7E]+\z/', $key) === 1) {
			return 'legacy';
		}

		return 'invalid';
	}
}
