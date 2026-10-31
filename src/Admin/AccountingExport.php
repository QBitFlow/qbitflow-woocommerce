<?php

declare(strict_types=1);

namespace QBitFlowWC\Admin;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use QBitFlowWC\ClientFactory;
use QBitFlowWC\Vendor\QBitFlow\Exceptions\QBitFlowException;
use RuntimeException;

defined('ABSPATH') || exit;

/**
 * Downloads QBitFlow's accounting export (CSV or JSON, any date range) from the settings page.
 * The SDK splits ranges over 92 days into one request per window. The export covers the whole
 * QBitFlow account (or the member's space), not only this store's orders.
 */
final class AccountingExport
{
	public const ACTION = 'qbitflow_wc_export';

	/** Why the range or format is refused, or null when it is fine. All dates are UTC days. */
	public static function validate(string $from, string $to, string $format): ?string
	{
		$start = self::parse($from);
		$end = self::parse($to);
		if ($start === null || $end === null) {
			return __('Enter the first and the last day as dates (YYYY-MM-DD).', 'qbitflow-for-woocommerce');
		}
		if (! in_array($format, ['csv', 'json'], true)) {
			return __('Choose CSV or JSON.', 'qbitflow-for-woocommerce');
		}
		if ($start > $end) {
			return __('The first day must not be after the last day.', 'qbitflow-for-woocommerce');
		}
		if ($from > gmdate('Y-m-d')) {
			return __('The first day is in the future: nothing to export yet.', 'qbitflow-for-woocommerce');
		}

		return null;
	}

	/**
	 * Fetches the export.
	 *
	 * @return array{filename: string, mime: string, body: string}
	 * @throws RuntimeException With a message for the admin.
	 */
	public static function fetch(string $from, string $to, string $format): array
	{
		// The exception messages are shown as admin notices (escaped there with wp_kses_post) or printed by WP-CLI as plain text.
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		$error = self::validate($from, $to, $format);
		if ($error !== null) {
			throw new RuntimeException($error);
		}
		$client = ClientFactory::make(60.0, 1);
		if ($client === null) {
			throw new RuntimeException(__('Enter a valid QBitFlow API key first.', 'qbitflow-for-woocommerce'));
		}

		try {
			if ($format === 'csv') {
				$body = $client->accounting->exportCsvRange($from, $to);
			} else {
				$rows = [];
				foreach ($client->accounting->exportJsonRange($from, $to) as $event) {
					$rows[] = self::wire($event);
				}
				$body = (string) wp_json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			}
		} catch (QBitFlowException $e) {
			$message = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : $e->getMessage();
			throw new RuntimeException(
				/* translators: %s: the error QBitFlow returned */
				sprintf(__('QBitFlow could not export the data: %s', 'qbitflow-for-woocommerce'), $message),
				0,
				$e
			);
		}

		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

		$data = StatusPanel::data();

		return [
			'filename' => self::filename((string) ($data['organization'] ?? ''), $from, $to, $format),
			'mime' => $format === 'csv' ? 'text/csv; charset=utf-8' : 'application/json; charset=utf-8',
			'body' => $body,
		];
	}

	public static function filename(string $space, string $from, string $to, string $format): string
	{
		$space = sanitize_title($space);

		return sprintf('qbitflow-%s-%s-%s.%s', $space !== '' ? $space : 'account', $from, $to, $format === 'json' ? 'json' : 'csv');
	}

	/** The admin-post handler: streams the file, or goes back to the settings with a notice. */
	public static function handle(): string
	{
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Actions::run checked the nonce.
		$from = sanitize_text_field(wp_unslash((string) ($_GET['from'] ?? '')));
		$to = sanitize_text_field(wp_unslash((string) ($_GET['to'] ?? '')));
		$format = sanitize_key(wp_unslash((string) ($_GET['format'] ?? '')));
		// phpcs:enable

		try {
			$file = self::fetch($from, $to, $format);
		} catch (RuntimeException $e) {
			Notices::add($e->getMessage(), 'error');

			return \QBitFlowWC\Gateway::settings_url();
		}
		if (headers_sent()) {
			// Something already printed: a download would be corrupted by it.
			Notices::add(__('The download could not start because the page had already started to send output. Check for a plugin or a PHP notice printing text early, then try again.', 'qbitflow-for-woocommerce'), 'error');

			return \QBitFlowWC\Gateway::settings_url();
		}
		self::stream($file);
	}

	/** @param array{filename: string, mime: string, body: string} $file */
	private static function stream(array $file): never
	{
		// Output buffers would add to the file, and zlib output compression would change its length
		// (no Content-Length is sent, so the response simply streams): both off before the headers.
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		if (function_exists('ini_set')) {
			@ini_set('zlib.output_compression', 'Off'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- Best effort for a file download: a host may forbid it.
		}
		nocache_headers();
		header('Content-Type: ' . $file['mime']);
		header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
		echo $file['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A file download.
		exit;
	}

	/** The section on the settings page. */
	public static function render(): void
	{
		$today = gmdate('Y-m-d');
		echo '<h3>' . esc_html__('Accounting export', 'qbitflow-for-woocommerce') . '</h3>';
		echo '<p class="description">' . esc_html__('Download every payment, refund and fee of your QBitFlow account (or of the member space your key opens) for a range of days, in UTC. It covers the whole account, not only this store\'s orders.', 'qbitflow-for-woocommerce') . '</p>';
		printf(
			'<p id="qbitflow-wc-export" data-url="%s">'
			. '<label>%s <input type="date" class="qbitflow-from" value="%s" max="%s"></label> '
			. '<label>%s <input type="date" class="qbitflow-to" value="%s" max="%s"></label> '
			. '<select class="qbitflow-format"><option value="csv">CSV</option><option value="json">JSON</option></select> '
			. '<button type="button" class="button qbitflow-download">%s</button></p>',
			esc_url(Actions::url(self::ACTION)),
			esc_html__('From', 'qbitflow-for-woocommerce'),
			esc_attr(gmdate('Y-m-01')),
			esc_attr($today),
			esc_html__('to', 'qbitflow-for-woocommerce'),
			esc_attr($today),
			esc_attr($today),
			esc_html__('Download', 'qbitflow-for-woocommerce')
		);
		// The settings page is one form, so the button builds a link instead of nesting a form.
		wp_enqueue_script('qbitflow-wc-export', QBITFLOW_WC_PLUGIN_URL . 'assets/js/admin-export.js', [], QBITFLOW_WC_VERSION, true);
	}

	private static function parse(string $date): ?DateTimeImmutable
	{
		$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

		return $parsed !== false && $parsed->format('Y-m-d') === $date ? $parsed : null;
	}

	/**
	 * An event as the API sent it: the model's properties are the wire keys; an absent value is
	 * left out (as in the API's JSON).
	 *
	 * @return array<string, mixed>
	 */
	private static function wire(object $event): array
	{
		$row = [];
		foreach (get_object_vars($event) as $key => $value) {
			if ($value === null) {
				continue;
			}
			$row[$key] = $value instanceof DateTimeInterface
				? (new DateTimeImmutable('@' . $value->getTimestamp()))->format('Y-m-d\TH:i:s\Z')
				: $value;
		}

		return $row;
	}
}
