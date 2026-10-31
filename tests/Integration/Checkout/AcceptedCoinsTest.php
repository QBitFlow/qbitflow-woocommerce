<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration\Checkout;

use QBitFlowWC\Blocks\PaymentMethod;
use QBitFlowWC\Checkout\AcceptedCoins;
use QBitFlowWC\Gateway;
use QBitFlowWC\Tests\Integration\Support\Fixtures;
use QBitFlowWC\Tests\Integration\Support\TestCase;

final class AcceptedCoinsTest extends TestCase
{
	public function set_up(): void
	{
		parent::set_up();
		delete_transient(AcceptedCoins::TRANSIENT);
		delete_transient(AcceptedCoins::BACKOFF_TRANSIENT);
		if (function_exists('as_unschedule_all_actions')) {
			as_unschedule_all_actions(AcceptedCoins::REFRESH_HOOK);
		}
	}

	private function gateway(): Gateway
	{
		return WC()->payment_gateways()->payment_gateways()['qbitflow'];
	}

	private function fields(): string
	{
		ob_start();
		$this->gateway()->payment_fields();

		return (string) ob_get_clean();
	}

	private function block_data(): array
	{
		$method = new PaymentMethod();
		$method->initialize();

		return $method->get_payment_method_data();
	}

	private function warm(): void
	{
		$this->api->on('GET', '#^/wallets/accepted-currencies$#', 200, Fixtures::page([Fixtures::currency() + ['address' => '0xabc'], Fixtures::currency('SOL', 'SOL')]));
		AcceptedCoins::refresh();
	}

	public function test_cold_cache_renders_without_http(): void
	{
		$fields = $this->fields();
		$data = $this->block_data();

		$this->assertSame([], $this->api->requests());
		$this->assertStringNotContainsString('USDC', $fields);
		$this->assertSame('', $data['acceptedCoins']);
		$this->assertCount(1, as_get_scheduled_actions(['hook' => AcceptedCoins::REFRESH_HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING], 'ids'));
	}

	public function test_warm_cache_renders_in_classic_and_blocks(): void
	{
		$this->warm();

		$this->assertStringContainsString('USDC (Base) (test)', $this->fields());
		$this->assertStringContainsString('USDC (Base) (test)', $this->block_data()['acceptedCoins']);
		$this->assertCount(1, $this->api->requests());
	}

	public function test_show_coins_no_hides_the_line(): void
	{
		$this->warm();
		$this->set_settings(['show_coins' => 'no']);

		$this->assertStringNotContainsString('USDC', $this->fields());
		$this->assertSame('', $this->block_data()['acceptedCoins']);
	}

	public function test_empty_list_hides_the_line(): void
	{
		$this->api->on('GET', '#^/wallets/accepted-currencies$#', 200, Fixtures::page([]));
		AcceptedCoins::refresh();

		$this->assertSame('', AcceptedCoins::label());
	}

	public function test_failed_refresh_keeps_it_cold(): void
	{
		$this->api->onError('GET', '#^/wallets/accepted-currencies$#', 500, 'internal');
		AcceptedCoins::refresh();

		$this->assertSame('', AcceptedCoins::label());
	}

	public function test_failed_refresh_backs_off_scheduling(): void
	{
		add_filter('qbitflow_wc_client_options', static fn (array $o): array => ['maxRetries' => 0] + $o);
		$this->api->onError('GET', '#^/wallets/accepted-currencies$#', 503, 'unavailable');
		AcceptedCoins::refresh();
		$pending = static fn (): int => count(as_get_scheduled_actions(['hook' => AcceptedCoins::REFRESH_HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING], 'ids'));

		for ($i = 0; $i < 3; $i++) {
			$this->fields();
		}
		$this->assertSame(0, $pending());

		delete_transient(AcceptedCoins::BACKOFF_TRANSIENT);
		$this->fields();
		$this->fields();
		$this->assertSame(1, $pending());
	}

	public function test_garbage_entries_in_the_cache_are_skipped(): void
	{
		set_transient(AcceptedCoins::TRANSIENT, ['junk', ['symbol' => 'SOL', 'chain' => 'SOL', 'test' => false]], HOUR_IN_SECONDS);

		$this->assertStringContainsString('SOL', AcceptedCoins::label());
	}

	public function test_refresh_stores_and_later_renders(): void
	{
		$this->assertSame('', AcceptedCoins::label());
		$this->warm();

		$this->assertStringContainsString('USDC', AcceptedCoins::label());
		$this->assertStringContainsString('SOL', AcceptedCoins::label());
	}

	public function test_output_is_escaped(): void
	{
		set_transient(AcceptedCoins::TRANSIENT, [['symbol' => '<b>X</b>', 'chain' => 'ETH', 'test' => false]], HOUR_IN_SECONDS);

		$this->assertStringNotContainsString('<b>', $this->fields());
	}
}
