<?php

declare(strict_types=1);

namespace QBitFlowWC\Security;

defined('ABSPATH') || exit;

/**
 * Whether the site's PHP has the php2shell fixes (CVE-2026-109618, CVE-2026-109619), and the
 * php.ini settings the advisories recommend meanwhile. Pure: give it a version and ini values.
 *
 * Only upgrading PHP fixes these interpreter bugs; this check informs, it never blocks.
 *
 * @see https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w
 * @see https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp
 */
final class PhpSecurity
{
	/** The first release of each branch with the fixes. */
	public const PATCHED = [
		'8.2' => '8.2.35',
		'8.3' => '8.3.36',
		'8.4' => '8.4.27',
		'8.5' => '8.5.12',
	];

	public const ADVISORIES = [
		'CVE-2026-109618' => 'https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w',
		'CVE-2026-109619' => 'https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp',
	];

	/** The PHP release in use predates its branch's fix. */
	public const ISSUE_UNPATCHED = 'unpatched';

	/** A branch the PHP project ships no fixed release for (7.4–8.1: source backports only). */
	public const ISSUE_NO_OFFICIAL_FIX = 'no_official_fix';

	/** `session.upload_progress.enabled` is on: a confirmed path of CVE-2026-109619. */
	public const ISSUE_UPLOAD_PROGRESS = 'upload_progress';

	/** PHP 8.5 before 8.5.12 with `fatal_error_backtraces` on: the confirmed RCE path. */
	public const ISSUE_FATAL_BACKTRACES = 'fatal_error_backtraces';

	/**
	 * @param array<string, mixed> $ini `session.upload_progress.enabled`, `fatal_error_backtraces` (ini_get() values).
	 *
	 * @return array{status: 'good'|'recommended'|'critical', issues: list<string>, fixed_in: ?string, distribution_build: bool}
	 */
	public static function assess(string $version, array $ini): array
	{
		$issues = [];
		$fixedIn = null;
		$branch = implode('.', array_slice(explode('.', (string) preg_replace('/[^0-9.].*$/', '', $version)), 0, 2));

		if (isset(self::PATCHED[$branch])) {
			$fixedIn = self::PATCHED[$branch];
			if (version_compare(self::numeric($version), $fixedIn, '<')) {
				$issues[] = self::ISSUE_UNPATCHED;
				if ($branch === '8.5' && self::enabled($ini['fatal_error_backtraces'] ?? false)) {
					$issues[] = self::ISSUE_FATAL_BACKTRACES;
				}
			}
		} elseif (version_compare($branch, '8.2', '<')) {
			$issues[] = self::ISSUE_NO_OFFICIAL_FIX;
		}

		$critical = $issues !== [];
		if (self::enabled($ini['session.upload_progress.enabled'] ?? false)) {
			$issues[] = self::ISSUE_UPLOAD_PROGRESS;
		}

		return [
			'status' => $critical ? 'critical' : ($issues !== [] ? 'recommended' : 'good'),
			'issues' => $issues,
			'fixed_in' => $fixedIn,
			// Debian/Ubuntu/RHEL builds carry a suffix and backport fixes without changing the version.
			'distribution_build' => preg_match('/^[0-9.]+[-+~]/', $version) === 1,
		];
	}

	/** The site's own PHP and settings. */
	public static function current(): array
	{
		return self::assess(PHP_VERSION, [
			'session.upload_progress.enabled' => ini_get('session.upload_progress.enabled'),
			'fatal_error_backtraces' => ini_get('fatal_error_backtraces'),
		]);
	}

	private static function numeric(string $version): string
	{
		return (string) preg_replace('/[^0-9.].*$/', '', $version);
	}

	private static function enabled(mixed $value): bool
	{
		if (is_bool($value)) {
			return $value;
		}

		return in_array(strtolower(trim((string) $value)), ['1', 'on', 'true', 'yes'], true);
	}
}
