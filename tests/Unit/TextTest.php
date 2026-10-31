<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QBitFlowWC\Support\Text;
use QBitFlowWC\Vendor\QBitFlow\Support\Validator;

final class TextTest extends TestCase
{
	/** @return iterable<string, array{string, string}> */
	public static function names(): iterable
	{
		yield 'inch mark' => ['27" Monitor', '27″ Monitor'];
		yield 'semicolon' => ['Hoodie; size M', 'Hoodie, size M'];
		yield 'brackets' => ['Mug [Blue] {XL}', 'Mug (Blue) (XL)'];
		yield 'pipe' => ['Brand | Shop', 'Brand / Shop'];
		yield 'markup' => ['<b>Bold</b> ~^`\\', 'bBold/b'];
		yield 'bidi and control' => ["Evil\u{202E}name\x07", 'Evilname'];
		yield 'whitespace' => ["  two\n lines\t", 'two lines'];
		yield 'kept' => ['O’Brien & Co (II) / prod', 'O’Brien & Co (II) / prod'];
	}

	/** @dataProvider names */
	public function test_name_is_accepted_by_the_api_rule(string $in, string $out): void
	{
		$this->assertSame($out, Text::line($in, 100));
		$this->assertTrue($out === '' || Validator::isName($out), $out);
	}

	public function test_truncates_with_ellipsis(): void
	{
		$this->assertSame(str_repeat('é', 39) . '…', Text::line(str_repeat('é', 50), 40));
		$this->assertSame('short', Text::line('short', 40));
	}

	public function test_every_disallowed_character_is_handled(): void
	{
		$all = 'a<b>c{d}e[f]g`h\\i|j;k"l~m^n';

		$this->assertTrue(Validator::isName(Text::line($all, 100)));
	}
}
