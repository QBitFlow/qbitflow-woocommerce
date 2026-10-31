<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QBitFlowWC\Support\ApiKey;

final class KeyFormatTest extends TestCase
{
	private const ID = '019a0000-0000-7000-8000-000000000001';

	/** @return array<string, array{string, string, string}> */
	public static function keys(): array
	{
		$id = self::ID;

		return [
			'test' => ["sk_test_{$id}_abc", 'test', 'ok'],
			'live' => ["sk_live_{$id}_abc", 'live', 'ok'],
			'legacy test' => ["sk_{$id}_test_abc", '', 'legacy'],
			'legacy live' => ["sk_{$id}_live_abc", '', 'legacy'],
			'pk' => ['pk_x', '', 'invalid'],
			'no id' => ['sk_test', '', 'invalid'],
			'bad id' => ['sk_test_x_y', '', 'invalid'],
			'space inside' => ["sk_test_{$id}_a c", '', 'invalid'],
			'newline' => ["sk_test_{$id}_abc\n", '', 'invalid'],
			'non-ascii' => ['sk_test_é', '', 'invalid'],
			'empty' => ['', '', 'empty'],
		];
	}

	/** @dataProvider keys */
	public function test_mode_and_format(string $key, string $mode, string $format): void
	{
		$this->assertSame($mode, ApiKey::mode($key));
		$this->assertSame($format, ApiKey::format($key));
	}
}
