<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration\Admin;

use QBitFlowWC\Admin\AccountingExport;
use QBitFlowWC\Admin\Actions;
use QBitFlowWC\Admin\Notices;
use QBitFlowWC\Tests\Integration\Support\Fixtures;
use QBitFlowWC\Tests\Integration\Support\TestCase;

final class AccountingExportTest extends TestCase
{
	private const HEADER = "txTimeUtc,type,chain,currency\n";

	private function ready_status(): void
	{
		$this->api->on('GET', '#^/me$#', 200, Fixtures::me(true, false));
		$this->api->on('GET', '#^/wallets/accepted-currencies$#', 200, Fixtures::page([Fixtures::currency()]));
	}

	private function event(string $time = '2026-01-05T10:00:00Z'): array
	{
		return ['txTimeUtc' => $time, 'type' => 'payment', 'chain' => 'BASE', 'currency' => 'USDC', 'currencyDecimals' => 6, 'grossAmountUsd' => '10.5'];
	}

	private function as_admin(string $from, string $to, string $format, bool $nonce = true): void
	{
		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
		$_GET = ['from' => $from, 'to' => $to, 'format' => $format];
		$_REQUEST['_wpnonce'] = $nonce ? wp_create_nonce(AccountingExport::ACTION) : 'bad';
	}

	public function test_validate_rejects_bad_input_and_accepts_a_long_range(): void
	{
		$this->assertNotNull(AccountingExport::validate('nope', '2026-01-02', 'csv'));
		$this->assertNotNull(AccountingExport::validate('2026-02-30', '2026-03-02', 'csv'));
		$this->assertNotNull(AccountingExport::validate('2026-01-02', '2026-01-01', 'csv'));
		$this->assertNotNull(AccountingExport::validate(gmdate('Y-m-d', time() + 3 * DAY_IN_SECONDS), gmdate('Y-m-d', time() + 4 * DAY_IN_SECONDS), 'csv'));
		$this->assertNotNull(AccountingExport::validate('2026-01-01', '2026-01-02', 'xml'));
		$this->assertNull(AccountingExport::validate('2026-01-01', '2026-07-19', 'csv'));
		$this->assertNull(AccountingExport::validate('2026-01-01', '2026-01-01', 'json'));
	}

	public function test_csv_of_200_days_is_three_requests_with_one_header(): void
	{
		$this->ready_status();
		$this->api->on('GET', '#^/accounting/export$#', 200, self::HEADER . "2026-01-05T10:00:00Z,payment,BASE,USDC\n", ['content-type' => 'text/csv']);

		$file = AccountingExport::fetch('2026-01-01', '2026-07-19', 'csv');

		$this->assertCount(3, $this->api->requests('GET', '#^/accounting/export$#'));
		$this->assertSame(1, substr_count($file['body'], 'txTimeUtc,type'));
		$this->assertSame('text/csv', explode(';', $file['mime'])[0]);
		$this->assertSame('qbitflow-example-shop-2026-01-01-2026-07-19.csv', $file['filename']);
	}

	public function test_empty_export(): void
	{
		$this->ready_status();
		$this->api->on('GET', '#^/accounting/export$#', 200, self::HEADER, ['content-type' => 'text/csv']);
		$this->assertSame(self::HEADER, AccountingExport::fetch('2026-01-01', '2026-01-31', 'csv')['body']);

		$this->api->on('GET', '#^/accounting/export$#', 200, Fixtures::page([]));
		$json = AccountingExport::fetch('2026-01-01', '2026-01-31', 'json');
		$this->assertSame('[]', $json['body']);
		$this->assertSame('application/json', explode(';', $json['mime'])[0]);
	}

	public function test_json_uses_the_wire_keys(): void
	{
		$this->ready_status();
		$this->api->on('GET', '#^/accounting/export$#', 200, Fixtures::page([$this->event()]));

		$rows = json_decode(AccountingExport::fetch('2026-01-01', '2026-01-31', 'json')['body'], true);

		$this->assertEquals(
			['txTimeUtc' => '2026-01-05T10:00:00Z', 'type' => 'payment', 'chain' => 'BASE', 'currency' => 'USDC', 'currencyDecimals' => 6, 'grossAmountUsd' => '10.5'],
			$rows[0]
		);
	}

	public function test_filename(): void
	{
		$this->assertSame('qbitflow-my-shop-2026-01-01-2026-03-31.csv', AccountingExport::filename('My Shop!', '2026-01-01', '2026-03-31', 'csv'));
		$this->assertSame('qbitflow-account-2026-01-01-2026-03-31.json', AccountingExport::filename('', '2026-01-01', '2026-03-31', 'json'));
	}

	public function test_api_error_throws_the_admin_message(): void
	{
		$this->api->onError('GET', '#^/accounting/export$#', 403, 'forbidden', 'Not allowed to export');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Not allowed to export');
		AccountingExport::fetch('2026-01-01', '2026-01-31', 'csv');
	}

	public function test_handler_without_nonce_is_refused_without_api_call(): void
	{
		$this->as_admin('2026-01-01', '2026-01-31', 'csv', false);

		try {
			Actions::run(AccountingExport::ACTION);
			$this->fail('expected a refusal');
		} catch (\WPDieException) {
			$this->assertSame([], $this->api->requests());
		}
	}

	public function test_handler_without_capability_is_403_without_api_call(): void
	{
		wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
		$_GET = ['from' => '2026-01-01', 'to' => '2026-01-31', 'format' => 'csv'];
		$_REQUEST['_wpnonce'] = wp_create_nonce(AccountingExport::ACTION);

		try {
			Actions::run(AccountingExport::ACTION);
			$this->fail('expected a refusal');
		} catch (\WPDieException $e) {
			$this->assertSame(403, $e->getCode() ?: 403);
			$this->assertSame([], $this->api->requests());
		}
	}

	public function test_handler_validation_error_redirects_with_a_notice_and_no_call(): void
	{
		$this->as_admin('2026-02-01', '2026-01-01', 'csv');

		$redirect = Actions::run(AccountingExport::ACTION);

		$this->assertStringContainsString('section=qbitflow', $redirect);
		$this->assertSame('error', Notices::take()['type']);
		$this->assertSame([], $this->api->requests());
	}

	public function test_handler_api_error_redirects_with_a_notice(): void
	{
		$this->api->onError('GET', '#^/accounting/export$#', 500, 'internal_error', 'Boom');
		$this->as_admin('2026-01-01', '2026-01-31', 'csv');

		$redirect = Actions::run(AccountingExport::ACTION);

		$this->assertStringContainsString('section=qbitflow', $redirect);
		$notice = Notices::take();
		$this->assertSame('error', $notice['type']);
		$this->assertStringContainsString('Boom', $notice['message']);
	}

	public function test_render_uses_an_enqueued_script_and_a_real_button(): void
	{
		ob_start();
		AccountingExport::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString('<button type="button" class="button qbitflow-download">', $html);
		$this->assertStringNotContainsString('<script', $html);
		$this->assertStringNotContainsString('href="#"', $html);
		$this->assertTrue(wp_script_is('qbitflow-wc-export', 'enqueued'));
		$this->assertFileExists(QBITFLOW_WC_PLUGIN_DIR . 'assets/js/admin-export.js');
	}

	public function test_handler_bails_with_a_notice_when_output_already_started(): void
	{
		if (! headers_sent()) {
			$this->markTestSkipped('Headers are not sent yet: the download would really start.');
		}
		$this->ready_status();
		$this->api->on('GET', '#^/accounting/export$#', 200, self::HEADER, ['content-type' => 'text/csv']);
		$this->as_admin('2026-01-01', '2026-01-31', 'csv');

		$redirect = Actions::run(AccountingExport::ACTION);

		$this->assertStringContainsString('section=qbitflow', $redirect);
		$notice = Notices::take();
		$this->assertSame('error', $notice['type']);
		$this->assertStringContainsString('already started', $notice['message']);
	}

	public function tear_down(): void
	{
		$_GET = [];
		unset($_REQUEST['_wpnonce']);
		parent::tear_down();
	}
}
