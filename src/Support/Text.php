<?php

declare(strict_types=1);

namespace QBitFlowWC\Support;

defined('ABSPATH') || exit;

/**
 * Store text (product, shipping and fee names, the site name, billing details) made acceptable
 * to the API's `name` and `text` rules: one line, no control or bidirectional characters, none of
 * `< > { } [ ] ` \ | ; " ~ ^`. Look-alikes replace the common ones so the text still reads the
 * same (27″ Monitor, Mug (Blue), Brand / Shop).
 */
final class Text
{
	private const REPLACE = [
		'"' => '″',
		';' => ',',
		'|' => '/',
		'[' => '(',
		']' => ')',
		'{' => '(',
		'}' => ')',
		'<' => '',
		'>' => '',
		'`' => '',
		'\\' => '',
		'~' => '',
		'^' => '',
	];

	/** One line of at most `$max` characters (an ellipsis when cut); `''` when nothing is left. */
	public static function line(string $text, int $max): string
	{
		// Control characters (line breaks and tabs become spaces) and bidirectional controls.
		$text = (string) preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text);
		$text = (string) preg_replace('/[\t\n\r]+/u', ' ', $text);
		$text = (string) preg_replace('/[\p{Cc}]/u', '', $text);
		$text = strtr($text, self::REPLACE);
		$text = trim((string) preg_replace('/\s+/u', ' ', $text));

		return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
	}
}
