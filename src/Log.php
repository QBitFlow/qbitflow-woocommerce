<?php

declare(strict_types=1);

namespace QBitFlowWC;

defined('ABSPATH') || exit;

/**
 * The plugin's log (WooCommerce → Status → Logs, source `qbitflow`). Errors are always
 * written; the rest only with the Debug log setting on. Never log a key or a secret.
 */
final class Log
{
	public const SOURCE = 'qbitflow';

	/** @param array<string, mixed> $context */
	public static function error(string $message, array $context = []): void
	{
		self::write('error', $message, $context);
	}

	/** @param array<string, mixed> $context */
	public static function warning(string $message, array $context = []): void
	{
		self::write('warning', $message, $context);
	}

	/** @param array<string, mixed> $context */
	public static function info(string $message, array $context = []): void
	{
		if (Settings::debug()) {
			self::write('info', $message, $context);
		}
	}

	/** @param array<string, mixed> $context */
	public static function debug(string $message, array $context = []): void
	{
		if (Settings::debug()) {
			self::write('debug', $message, $context);
		}
	}

	/** @param array<string, mixed> $context */
	private static function write(string $level, string $message, array $context): void
	{
		if (! function_exists('wc_get_logger')) {
			return;
		}
		wc_get_logger()->log($level, $message, ['source' => self::SOURCE] + $context);
	}
}
