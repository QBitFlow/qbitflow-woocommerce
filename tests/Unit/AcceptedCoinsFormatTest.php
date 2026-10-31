<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QBitFlowWC\Checkout\AcceptedCoins;

final class AcceptedCoinsFormatTest extends TestCase
{
	private static function coin(string $symbol, string $chain, bool $test = false): array
	{
		return ['symbol' => $symbol, 'chain' => $chain, 'test' => $test];
	}

	public function test_groups_by_symbol_in_first_seen_order(): void
	{
		$this->assertSame('USDC (Ethereum, Base), ETH, SOL', AcceptedCoins::format([
			self::coin('USDC', 'ETH'),
			self::coin('ETH', 'ETH'),
			self::coin('USDC', 'BASE'),
			self::coin('SOL', 'SOL'),
		]));
	}

	public function test_native_coin_is_bare_even_on_several_chains(): void
	{
		$this->assertSame('ETH', AcceptedCoins::format([
			['symbol' => 'ETH', 'chain' => 'ETH', 'test' => false, 'token' => false],
			['symbol' => 'ETH', 'chain' => 'BASE', 'test' => false, 'token' => false],
		]));
	}

	public function test_token_with_explicit_flag_lists_its_chain(): void
	{
		$this->assertSame('USDC (Solana)', AcceptedCoins::format([['symbol' => 'USDC', 'chain' => 'SOL', 'test' => false, 'token' => true]]));
	}

	public function test_unknown_chain_shows_raw(): void
	{
		$this->assertSame('USDT (ARB)', AcceptedCoins::format([self::coin('USDT', 'ARB')]));
	}

	public function test_test_currencies_are_marked(): void
	{
		$this->assertSame('USDC (Base) (test), ETH (test)', AcceptedCoins::format([
			self::coin('USDC', 'BASE', true),
			self::coin('ETH', 'ETH', true),
		]));
	}

	public function test_empty_is_empty(): void
	{
		$this->assertSame('', AcceptedCoins::format([]));
	}
}
