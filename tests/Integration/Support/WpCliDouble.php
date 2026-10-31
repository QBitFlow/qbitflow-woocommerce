<?php

declare(strict_types=1);

// A minimal stand-in for WP-CLI's `WP_CLI` class (the WP test environment does not load it):
// it records what a command prints, and `error()`/`halt()` throw instead of exiting.

namespace QBitFlowWC\Tests\Integration\Support {
	final class WpCliExit extends \RuntimeException
	{
	}
}

namespace {
	if (! class_exists('WP_CLI', false)) {
		final class WP_CLI
		{
			/** @var list<array{0: string, 1: string}> Kind (line, success, warning) and message. */
			public static array $out = [];

			public static function reset(): void
			{
				self::$out = [];
			}

			/** @return list<string> */
			public static function messages(?string $kind = null): array
			{
				$found = [];
				foreach (self::$out as [$k, $m]) {
					if ($kind === null || $k === $kind) {
						$found[] = $m;
					}
				}

				return $found;
			}

			public static function line(string $message = ''): void
			{
				self::$out[] = ['line', $message];
			}

			public static function success(string $message): void
			{
				self::$out[] = ['success', $message];
			}

			public static function warning(string $message): void
			{
				self::$out[] = ['warning', $message];
			}

			public static function error(string $message, $exit = true): void
			{
				self::$out[] = ['error', $message];
				if ($exit) {
					throw new \QBitFlowWC\Tests\Integration\Support\WpCliExit($message, 1);
				}
			}

			public static function halt(int $code): void
			{
				throw new \QBitFlowWC\Tests\Integration\Support\WpCliExit('halt', $code);
			}
		}
	}
}
