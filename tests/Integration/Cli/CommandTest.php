<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration\Cli;

use QBitFlowWC\Checkout\SessionManager;
use QBitFlowWC\Cli\Command;
use QBitFlowWC\Orders\OrderMeta;
use QBitFlowWC\Tests\Integration\Support\Fixtures;
use QBitFlowWC\Tests\Integration\Support\TestCase;
use QBitFlowWC\Tests\Integration\Support\WpCliExit;
use WP_CLI;

require_once dirname(__DIR__) . '/Support/WpCliDouble.php';

final class CommandTest extends TestCase
{
	private const SESSION = 'pay@019a0000-0000-7000-8000-0000000000a1';

	public function set_up(): void
	{
		parent::set_up();
		WP_CLI::reset();
	}

	private function ready_status(): void
	{
		$this->api->on('GET', '#^/me$#', 200, Fixtures::me(true, false));
		$this->api->on('GET', '#^/wallets/accepted-currencies$#', 200, Fixtures::page([Fixtures::currency()]));
	}

	private function qbitflow_order(): \WC_Order
	{
		$order = $this->make_order();
		$order->update_meta_data(OrderMeta::SESSION_UUID, self::SESSION);
		$order->save();

		return $order;
	}

	/** Runs a command, returning the exit code (0 unless it errored or halted). */
	private function run_cli(callable $call): int
	{
		try {
			$call();
		} catch (WpCliExit $e) {
			return $e->getCode();
		}

		return 0;
	}

	public function test_status_json_is_stable(): void
	{
		$this->ready_status();
		set_transient(SessionManager::REDIRECTS_OFF_TRANSIENT, 1, HOUR_IN_SECONDS);

		$code = $this->run_cli(fn () => (new Command())->status([], ['format' => 'json']));

		$this->assertSame(0, $code);
		$json = json_decode(WP_CLI::messages('line')[0], true);
		foreach (['connected', 'organization', 'member', 'test', 'key_format', 'currencies', 'webhook', 'php', 'currency', 'redirects_off'] as $key) {
			$this->assertArrayHasKey($key, $json);
		}
		$this->assertTrue($json['connected']);
		$this->assertSame('ok', $json['key_format']);
		$this->assertTrue($json['redirects_off']);
		$this->assertSame(['USDC (BASE)'], $json['currencies']);
		$this->assertSame('none', $json['webhook']['state']);
		$this->assertContains($json['php']['status'], ['good', 'recommended', 'critical']);
		$this->assertSame('USD', $json['currency']['store']);
	}

	public function test_status_reports_a_connected_webhook(): void
	{
		$this->ready_status();
		$uuid = '019a0000-0000-7000-8000-0000000000e1';
		update_option('qbitflow_wc_webhook_test', ['uuid' => $uuid, 'secret' => 's', 'mode' => 'test', 'url' => \QBitFlowWC\Webhooks\EndpointManager::url(), 'space' => '', 'connected_at' => time()]);
		$this->api->on('GET', '#^/webhooks/endpoints/#', 200, Fixtures::endpoint($uuid, \QBitFlowWC\Webhooks\EndpointManager::url()));

		$this->run_cli(fn () => (new Command())->status([], ['format' => 'json']));

		$webhook = json_decode(WP_CLI::messages('line')[0], true)['webhook'];
		$this->assertTrue($webhook['stored']);
		$this->assertSame('ok', $webhook['state']);
	}

	public function test_status_table_prints_lines(): void
	{
		$this->ready_status();

		$this->assertSame(0, $this->run_cli(fn () => (new Command())->status([], [])));

		$this->assertStringContainsString('connected', implode("\n", WP_CLI::messages('line')));
	}

	public function test_status_without_a_key_errors(): void
	{
		$this->set_settings(['api_key' => '']);

		$code = $this->run_cli(fn () => (new Command())->status([], ['format' => 'json']));

		$this->assertSame(1, $code);
		$this->assertCount(0, $this->api->requests());
	}

	public function test_a_malformed_key_errors_without_a_request(): void
	{
		$this->set_settings(['api_key' => "sk_test_x\n y"]);

		$this->assertSame(1, $this->run_cli(fn () => (new Command())->status([], [])));
		$this->assertSame(1, $this->run_cli(fn () => (new Command())->reconcile([], ['all' => true])));
		$this->assertSame(1, $this->run_cli(fn () => (new Command())->connect_webhook([], [])));
		$this->assertCount(0, $this->api->requests());
	}

	public function test_reconcile_refreshes_exactly_the_given_orders(): void
	{
		$a = $this->qbitflow_order();
		$b = $this->qbitflow_order();
		$other = $this->qbitflow_order();
		$this->api->on('GET', '#^/checkout-sessions/#', 200, Fixtures::session_status(self::SESSION, 'created'));

		$code = $this->run_cli(fn () => (new Command())->reconcile([(string) $a->get_id(), (string) $b->get_id()], []));

		$this->assertSame(0, $code);
		$this->assertCount(2, $this->api->requests('GET', '#^/checkout-sessions/#'));
		$text = implode("\n", WP_CLI::messages());
		$this->assertStringContainsString('#' . $a->get_id() . ': open', $text);
		$this->assertStringContainsString('#' . $b->get_id() . ': open', $text);
		$this->assertStringNotContainsString('#' . $other->get_id(), $text);
	}

	public function test_reconcile_a_foreign_order_warns_and_fails_at_the_end(): void
	{
		$a = $this->qbitflow_order();
		$foreign = $this->make_order();
		$foreign->set_payment_method('bacs');
		$foreign->save();
		$this->api->on('GET', '#^/checkout-sessions/#', 200, Fixtures::session_status(self::SESSION, 'created'));

		$code = $this->run_cli(fn () => (new Command())->reconcile([(string) $foreign->get_id(), '999999', (string) $a->get_id()], []));

		$this->assertSame(1, $code);
		$this->assertCount(2, WP_CLI::messages('warning'));
		$this->assertCount(1, $this->api->requests('GET', '#^/checkout-sessions/#'));
	}

	public function test_reconcile_unknown_fails_with_a_warning_and_summary(): void
	{
		$a = $this->qbitflow_order();
		$this->api->onError('GET', '#^/checkout-sessions/#', 500, 'internal', 'down');

		$code = $this->run_cli(fn () => (new Command())->reconcile([(string) $a->get_id()], []));

		$this->assertSame(1, $code);
		$this->assertCount(1, WP_CLI::messages('warning'));
		$this->assertContains('checked 1: 0 completed, 0 expired, 0 open, 1 unknown, 1 failed', WP_CLI::messages('line'));
	}

	public function test_reconcile_all_open_exits_zero_with_summary(): void
	{
		$a = $this->qbitflow_order();
		$this->api->on('GET', '#^/checkout-sessions/#', 200, Fixtures::session_status(self::SESSION, 'created'));

		$code = $this->run_cli(fn () => (new Command())->reconcile([(string) $a->get_id()], []));

		$this->assertSame(0, $code);
		$this->assertContains('checked 1: 0 completed, 0 expired, 1 open, 0 unknown, 0 failed', WP_CLI::messages('line'));
	}

	public function test_reconcile_refreshes_a_duplicated_id_once(): void
	{
		$a = $this->qbitflow_order();
		$this->api->on('GET', '#^/checkout-sessions/#', 200, Fixtures::session_status(self::SESSION, 'created'));

		$this->run_cli(fn () => (new Command())->reconcile([(string) $a->get_id(), (string) $a->get_id()], []));

		$this->assertCount(1, $this->api->requests('GET', '#^/checkout-sessions/#'));
	}

	public function test_export_bare_output_errors(): void
	{
		$code = $this->run_cli(fn () => (new Command())->export([], ['from' => '2026-01-01', 'to' => '2026-01-31', 'output' => true]));

		$this->assertSame(1, $code);
		$this->assertCount(0, $this->api->requests());
	}

	public function test_reconcile_needs_ids_or_all(): void
	{
		$this->assertSame(1, $this->run_cli(fn () => (new Command())->reconcile([], [])));
		$this->assertCount(0, $this->api->requests());
	}

	public function test_reconcile_all_walks_the_unpaid_orders_and_says_how_many(): void
	{
		$this->qbitflow_order();
		$this->qbitflow_order();
		$paid = $this->qbitflow_order();
		$paid->update_meta_data(OrderMeta::LAST_STATUS, 'completed');
		$paid->save();
		$this->api->on('GET', '#^/checkout-sessions/#', 200, Fixtures::session_status(self::SESSION, 'created'));

		$code = $this->run_cli(fn () => (new Command())->reconcile([], ['all' => true]));

		$this->assertSame(0, $code);
		$this->assertCount(2, $this->api->requests('GET', '#^/checkout-sessions/#'));
		$this->assertStringContainsString('Checked 2 order', implode("\n", WP_CLI::messages()));
	}

	public function test_export_prints_to_stdout(): void
	{
		$this->ready_status();
		$this->api->on('GET', '#^/accounting/export$#', 200, "txTimeUtc,type\n", ['content-type' => 'text/csv']);

		$code = $this->run_cli(fn () => (new Command())->export([], ['from' => '2026-01-01', 'to' => '2026-01-31']));

		$this->assertSame(0, $code);
		$this->assertSame(["txTimeUtc,type"], WP_CLI::messages('line'));
	}

	public function test_export_writes_the_output_file(): void
	{
		$this->ready_status();
		$this->api->on('GET', '#^/accounting/export$#', 200, "txTimeUtc,type\n", ['content-type' => 'text/csv']);
		$path = wp_tempnam('qbitflow-export');

		$code = $this->run_cli(fn () => (new Command())->export([], ['from' => '2026-01-01', 'to' => '2026-01-31', 'format' => 'csv', 'output' => $path]));

		$this->assertSame(0, $code);
		$this->assertSame("txTimeUtc,type\n", file_get_contents($path));
		$this->assertCount(0, WP_CLI::messages('line'));
		$this->assertCount(1, WP_CLI::messages('success'));
		unlink($path);
	}

	public function test_export_with_invalid_dates_errors_without_a_request(): void
	{
		$code = $this->run_cli(fn () => (new Command())->export([], ['from' => '2026-02-30', 'to' => '2026-03-01']));

		$this->assertSame(1, $code);
		$this->assertCount(0, $this->api->requests());
	}

	public function test_connect_webhook_failure_errors(): void
	{
		$this->api->on('GET', '#^/me$#', 200, Fixtures::me(true, false));
		$this->api->on('GET', '#^/webhooks/endpoints#', 200, Fixtures::page([]));
		$this->api->onError('POST', '#^/webhooks/endpoints$#', 500, 'internal', 'down');

		$this->assertSame(1, $this->run_cli(fn () => (new Command())->connect_webhook([], [])));
	}

	public function test_connect_webhook_success(): void
	{
		$this->api->on('GET', '#^/me$#', 200, Fixtures::me(true, false));
		$this->api->on('GET', '#^/webhooks/endpoints#', 200, Fixtures::page([]));
		$this->api->on('POST', '#^/webhooks/endpoints$#', 201, Fixtures::endpoint('019a0000-0000-7000-8000-0000000000e1', \QBitFlowWC\Webhooks\EndpointManager::url()) + ['secret' => 'whsec_abc']);

		$this->assertSame(0, $this->run_cli(fn () => (new Command())->connect_webhook([], [])));
		$this->assertCount(1, WP_CLI::messages('success'));
	}
}
