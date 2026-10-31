<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QBitFlowWC\Security\PhpSecurity;

final class PhpSecurityTest extends TestCase
{
	private const SAFE_INI = ['session.upload_progress.enabled' => '0', 'fatal_error_backtraces' => '0'];

	public function test_unpatched_branch_release_is_critical(): void
	{
		$result = PhpSecurity::assess('8.3.35', self::SAFE_INI);

		$this->assertSame('critical', $result['status']);
		$this->assertSame([PhpSecurity::ISSUE_UNPATCHED], $result['issues']);
		$this->assertSame('8.3.36', $result['fixed_in']);
	}

	public function test_patched_releases_are_good(): void
	{
		foreach (['8.2.35', '8.3.36', '8.4.27', '8.5.12', '8.4.30'] as $version) {
			$this->assertSame('good', PhpSecurity::assess($version, self::SAFE_INI)['status'], $version);
		}
	}

	public function test_unpatched_85_with_fatal_error_backtraces_lists_both(): void
	{
		$result = PhpSecurity::assess('8.5.11', ['fatal_error_backtraces' => '1'] + self::SAFE_INI);

		$this->assertSame('critical', $result['status']);
		$this->assertSame([PhpSecurity::ISSUE_UNPATCHED, PhpSecurity::ISSUE_FATAL_BACKTRACES], $result['issues']);
	}

	public function test_fatal_error_backtraces_is_irrelevant_once_patched(): void
	{
		$this->assertSame('good', PhpSecurity::assess('8.5.12', ['fatal_error_backtraces' => '1'] + self::SAFE_INI)['status']);
	}

	public function test_upload_progress_on_a_patched_php_is_a_recommendation(): void
	{
		$result = PhpSecurity::assess('8.4.27', ['session.upload_progress.enabled' => '1'] + self::SAFE_INI);

		$this->assertSame('recommended', $result['status']);
		$this->assertSame([PhpSecurity::ISSUE_UPLOAD_PROGRESS], $result['issues']);
	}

	public function test_newer_branch_is_good(): void
	{
		$this->assertSame('good', PhpSecurity::assess('8.6.0', self::SAFE_INI)['status']);
	}

	public function test_branch_without_official_fix_is_critical(): void
	{
		$result = PhpSecurity::assess('8.1.30', self::SAFE_INI);

		$this->assertSame('critical', $result['status']);
		$this->assertSame([PhpSecurity::ISSUE_NO_OFFICIAL_FIX], $result['issues']);
		$this->assertNull($result['fixed_in']);
	}

	public function test_distribution_builds_are_flagged(): void
	{
		$this->assertTrue(PhpSecurity::assess('8.3.6-1ubuntu1.4', self::SAFE_INI)['distribution_build']);
		$this->assertFalse(PhpSecurity::assess('8.3.6', self::SAFE_INI)['distribution_build']);
	}

	public function test_ini_values_on_and_true_count_as_enabled(): void
	{
		foreach (['1', 'On', 'on', 'true', 'yes'] as $value) {
			$result = PhpSecurity::assess('8.4.27', ['session.upload_progress.enabled' => $value] + self::SAFE_INI);
			$this->assertSame('recommended', $result['status'], $value);
		}
		$this->assertSame('good', PhpSecurity::assess('8.4.27', ['session.upload_progress.enabled' => false] + self::SAFE_INI)['status']);
	}
}
