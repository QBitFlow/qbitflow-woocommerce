<?php

declare(strict_types=1);

namespace QBitFlowWC\Cli;

use QBitFlowWC\Admin\AccountingExport;
use QBitFlowWC\Admin\Notices;
use QBitFlowWC\Admin\StatusPanel;
use QBitFlowWC\Checkout\SessionManager;
use QBitFlowWC\Currency;
use QBitFlowWC\Gateway;
use QBitFlowWC\Orders\OrderFinder;
use QBitFlowWC\Orders\PaymentSync;
use QBitFlowWC\Security\PhpSecurity;
use QBitFlowWC\Settings;
use QBitFlowWC\Webhooks\EndpointManager;
use RuntimeException;
use WP_CLI;

defined('ABSPATH') || exit;

/**
 * Manage the QBitFlow connection: check it, connect the webhook, re-check orders and export
 * accounting data.
 *
 * Every command that needs QBitFlow stops with an error (non-zero exit) when the API key is
 * missing or not a QBitFlow key.
 */
final class Command
{
	/**
	 * Shows the connection state: key, account, accepted currencies, webhook, PHP, currency.
	 *
	 * Reads QBitFlow again (the 10-minute cache is bypassed). Exits non-zero when QBitFlow
	 * cannot be reached with the key. `--format=json` prints one JSON object with a fixed set
	 * of keys.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : `table` (default, one `name: value` line each) or `json`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp qbitflow status --format=json
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc_args
	 */
	public function status(array $args, array $assoc_args): void
	{
		$this->require_key();
		$data = StatusPanel::data(true);
		$stored = EndpointManager::stored();
		$webhook = $data['webhook'];
		$webhook = ($webhook ?? []) + ['state' => $stored === null ? 'none' : 'unknown', 'stored' => $stored !== null];
		$webhook['stored'] = $stored !== null;
		if ($stored === null && Settings::manual_webhook_secret() !== '') {
			$webhook['state'] = 'manual';
		}
		$php = PhpSecurity::current();

		$out = [
			'connected' => $data['connected'],
			'error' => $data['error'],
			'key_format' => Settings::key_format(),
			'organization' => $data['organization'],
			'member' => $data['member'],
			'test' => $data['test'],
			'space' => $data['space'],
			'currencies' => $data['currencies'],
			'webhook' => $webhook,
			'php' => ['version' => PHP_VERSION, 'status' => $php['status'], 'issues' => $php['issues']],
			'currency' => ['store' => (string) get_woocommerce_currency(), 'usd_rate' => Currency::usd_rate()],
			'redirects_off' => SessionManager::redirects_off(),
			'checkouts_off' => StatusPanel::checkouts_off(),
		];

		if (($assoc_args['format'] ?? 'table') === 'json') {
			WP_CLI::line((string) wp_json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		} else {
			foreach ($out as $name => $value) {
				WP_CLI::line($name . ': ' . self::plain($value));
			}
		}

		if (! $data['connected']) {
			WP_CLI::halt(1);
		}
	}

	/**
	 * Registers this store's webhook endpoint in QBitFlow and stores its signing secret.
	 *
	 * ## EXAMPLES
	 *
	 *     wp qbitflow connect-webhook
	 *
	 * @subcommand connect-webhook
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc_args
	 */
	public function connect_webhook(array $args, array $assoc_args): void
	{
		$this->require_key();
		try {
			$endpoint = EndpointManager::connect();
		} catch (RuntimeException $e) {
			WP_CLI::error($e->getMessage());

			return;
		}
		StatusPanel::forget();
		WP_CLI::success(sprintf('Webhook connected: %s', $endpoint['url']));
	}

	/**
	 * Asks QBitFlow about orders and applies what it answers (paid, expired, still open).
	 *
	 * With `--all`, checks the most recent unpaid QBitFlow orders (a bounded list: the count
	 * checked is printed). Exits non-zero when an order id is not a QBitFlow order.
	 *
	 * ## OPTIONS
	 *
	 * [<order-id>...]
	 * : Orders to check.
	 *
	 * [--all]
	 * : Check the unpaid orders with a QBitFlow checkout.
	 *
	 * ## EXAMPLES
	 *
	 *     wp qbitflow reconcile 12 13
	 *     wp qbitflow reconcile --all
	 *
	 * @param list<string>          $args
	 * @param array<string, mixed>  $assoc_args
	 */
	public function reconcile(array $args, array $assoc_args): void
	{
		$all = ! empty($assoc_args['all']);
		if ($args === [] && ! $all) {
			WP_CLI::error('Give one or more order ids, or --all.');

			return;
		}
		$this->require_key();

		$failed = 0;
		$orders = [];
		foreach (array_unique(array_map('strval', $args)) as $id) {
			$order = ctype_digit($id) ? wc_get_order((int) $id) : false;
			if (! $order instanceof \WC_Order || $order->get_payment_method() !== Gateway::ID) {
				WP_CLI::warning(sprintf('Order %s is not a QBitFlow order.', $id));
				++$failed;
				continue;
			}
			$orders[$order->get_id()] = $order;
		}
		if ($all) {
			$found = OrderFinder::unpaid_with_session();
			foreach ($found as $order) {
				$orders[$order->get_id()] = $order;
			}
			WP_CLI::line(sprintf('Checked %d order(s): the most recent unpaid QBitFlow orders of the last 48 hours (at most 50).', count($found)));
		}

		$counts = [PaymentSync::COMPLETED => 0, PaymentSync::EXPIRED => 0, PaymentSync::OPEN => 0, PaymentSync::UNKNOWN => 0];
		foreach ($orders as $order) {
			$result = PaymentSync::refresh($order, 'wp-cli');
			++$counts[$result];
			WP_CLI::line(sprintf('Order #%d: %s', $order->get_id(), $result));
			if ($result === PaymentSync::UNKNOWN) {
				WP_CLI::warning(sprintf('Order #%d could not be checked (QBitFlow unreachable, busy, or the checkout is gone).', $order->get_id()));
				++$failed;
			}
		}

		WP_CLI::line(sprintf(
			'checked %d: %d completed, %d expired, %d open, %d unknown, %d failed',
			count($orders),
			$counts[PaymentSync::COMPLETED],
			$counts[PaymentSync::EXPIRED],
			$counts[PaymentSync::OPEN],
			$counts[PaymentSync::UNKNOWN],
			$failed
		));

		if ($failed > 0) {
			WP_CLI::halt(1);
		}
	}

	/**
	 * Exports the accounting data of a date range (UTC days) as CSV or JSON.
	 *
	 * ## OPTIONS
	 *
	 * --from=<date>
	 * : First day, YYYY-MM-DD.
	 *
	 * --to=<date>
	 * : Last day, YYYY-MM-DD.
	 *
	 * [--format=<format>]
	 * : `csv` (default) or `json`.
	 *
	 * [--output=<file>]
	 * : Write to this file instead of printing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp qbitflow export --from=2026-01-01 --to=2026-01-31 --output=jan.csv
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc_args
	 */
	public function export(array $args, array $assoc_args): void
	{
		$from = (string) ($assoc_args['from'] ?? '');
		$to = (string) ($assoc_args['to'] ?? '');
		$format = (string) ($assoc_args['format'] ?? 'csv');
		$error = AccountingExport::validate($from, $to, $format);
		if ($error !== null) {
			WP_CLI::error($error);

			return;
		}
		if (($assoc_args['output'] ?? null) === true || ($assoc_args['output'] ?? null) === '') {
			WP_CLI::error('--output needs a file path.');

			return;
		}
		$this->require_key();

		try {
			$file = AccountingExport::fetch($from, $to, $format);
		} catch (RuntimeException $e) {
			WP_CLI::error($e->getMessage());

			return;
		}

		$output = (string) ($assoc_args['output'] ?? '');
		if ($output === '') {
			WP_CLI::line(rtrim($file['body'], "\r\n"));

			return;
		}
		if (@file_put_contents($output, $file['body']) === false) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			WP_CLI::error(sprintf('Cannot write %s.', $output));

			return;
		}
		WP_CLI::success(sprintf('Wrote %s (%d bytes).', $output, strlen($file['body'])));
	}

	/** Stops with the key-format message unless the stored key is a QBitFlow key. */
	private function require_key(): void
	{
		$message = match (Settings::key_format()) {
			'ok' => null,
			'empty' => 'No API key yet: set one in WooCommerce > Settings > Payments > QBitFlow.',
			'legacy' => Notices::legacy_key_message(),
			default => 'The API key is not a QBitFlow key (it starts with sk_).',
		};
		if ($message !== null) {
			WP_CLI::error($message);
		}
	}

	private static function plain(mixed $value): string
	{
		if (is_bool($value)) {
			return $value ? 'yes' : 'no';
		}
		if ($value === null) {
			return '-';
		}
		if (is_array($value)) {
			return $value === [] ? '-' : (string) wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}

		return (string) $value;
	}
}
