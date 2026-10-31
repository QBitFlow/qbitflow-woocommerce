<?php

declare(strict_types=1);

namespace QBitFlowWC\Security;

defined('ABSPATH') || exit;

/**
 * A Site Health test (Tools → Site Health) for php2shell: the PHP release, and the php.ini
 * settings the advisories recommend until PHP is upgraded.
 */
final class SiteHealth
{
	public const TEST = 'qbitflow_php_security';

	public static function init(): void
	{
		add_filter('site_status_tests', [self::class, 'register']);
	}

	/**
	 * @param array<string, mixed> $tests
	 * @return array<string, mixed>
	 */
	public static function register(array $tests): array
	{
		$tests['direct'][self::TEST] = [
			'label' => __('PHP is patched against php2shell', 'qbitflow-for-woocommerce'),
			'test' => [self::class, 'run'],
		];

		return $tests;
	}

	/** @return array<string, mixed> The Site Health result. */
	public static function run(): array
	{
		$result = PhpSecurity::current();
		$summary = self::summary($result);

		return [
			'label' => $summary['label'],
			'status' => $result['status'],
			'badge' => [
				'label' => __('Security', 'qbitflow-for-woocommerce'),
				'color' => $result['status'] === 'good' ? 'blue' : 'red',
			],
			'description' => '<p>' . implode('</p><p>', array_map('esc_html', $summary['lines'])) . '</p>',
			'actions' => self::actions(),
			'test' => self::TEST,
		];
	}

	/**
	 * A plain-language summary of an assessment, shared with the QBitFlow settings screen.
	 *
	 * @param array{status: string, issues: list<string>, fixed_in: ?string, distribution_build: bool} $result
	 * @return array{label: string, lines: list<string>}
	 */
	public static function summary(array $result): array
	{
		$lines = [];
		foreach ($result['issues'] as $issue) {
			$lines[] = match ($issue) {
				PhpSecurity::ISSUE_UNPATCHED => sprintf(
					/* translators: 1: running PHP version, 2: first fixed release of that branch */
					__('PHP %1$s is affected by the php2shell vulnerabilities (CVE-2026-109618, CVE-2026-109619), which let an unauthenticated attacker run code on the server. Ask your host to upgrade PHP to %2$s or later.', 'qbitflow-for-woocommerce'),
					PHP_VERSION,
					(string) $result['fixed_in']
				),
				PhpSecurity::ISSUE_NO_OFFICIAL_FIX => sprintf(
					/* translators: %s: running PHP version */
					__('PHP %s has no official fix for the php2shell vulnerabilities (CVE-2026-109618, CVE-2026-109619). Ask your host to move to PHP 8.2.35, 8.3.36, 8.4.27 or 8.5.12 or later.', 'qbitflow-for-woocommerce'),
					PHP_VERSION
				),
				PhpSecurity::ISSUE_FATAL_BACKTRACES => __('Until PHP is upgraded, set fatal_error_backtraces=0 in php.ini and restart PHP: it closes the confirmed attack path on PHP 8.5.', 'qbitflow-for-woocommerce'),
				PhpSecurity::ISSUE_UPLOAD_PROGRESS => __('session.upload_progress.enabled is on. Unless a plugin needs upload progress, set it to 0 in php.ini and restart PHP: it is one of the php2shell attack paths, and the PHP security team recommends turning it off even once patched.', 'qbitflow-for-woocommerce'),
				default => '',
			};
		}
		if ($result['status'] === 'critical' && $result['distribution_build']) {
			$lines[] = __('This PHP comes from your Linux distribution, which may have backported the fixes without changing the version number: check its security advisory before acting.', 'qbitflow-for-woocommerce');
		}
		if ($lines === []) {
			$lines[] = sprintf(
				/* translators: %s: running PHP version */
				__('PHP %s includes the fixes for the php2shell vulnerabilities (CVE-2026-109618, CVE-2026-109619).', 'qbitflow-for-woocommerce'),
				PHP_VERSION
			);
		}

		return [
			'label' => match ($result['status']) {
				'critical' => __('PHP is vulnerable to php2shell', 'qbitflow-for-woocommerce'),
				'recommended' => __('PHP is patched; one php2shell hardening setting is recommended', 'qbitflow-for-woocommerce'),
				default => __('PHP is patched against php2shell', 'qbitflow-for-woocommerce'),
			},
			'lines' => array_values(array_filter($lines)),
		];
	}

	private static function actions(): string
	{
		$links = [];
		foreach (PhpSecurity::ADVISORIES as $cve => $url) {
			$links[] = sprintf('<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url($url), esc_html($cve));
		}

		return '<p>' . implode(' · ', $links) . '</p>';
	}
}
