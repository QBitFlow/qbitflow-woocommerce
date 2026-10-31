<?php

declare(strict_types=1);

namespace QBitFlowWC\Admin;

use QBitFlowWC\Gateway;
use QBitFlowWC\Webhooks\EndpointManager;
use RuntimeException;

defined('ABSPATH') || exit;

/**
 * The plugin's admin actions (admin-post.php, nonce- and capability-checked), as links so they
 * work inside WooCommerce's settings form. Each handler does its work and returns where to go
 * back; the result is shown as a one-shot notice.
 */
final class Actions
{
	public const CONNECT_WEBHOOK = 'qbitflow_wc_connect_webhook';

	public const REFRESH_STATUS = 'qbitflow_wc_refresh_status';

	public const REFRESH_ORDER = 'qbitflow_wc_refresh_order';

	/** @var array<string, array{0: callable(): string, 1: string}> */
	private static array $handlers = [];

	public static function register(): void
	{
		self::add(self::CONNECT_WEBHOOK, [self::class, 'connect_webhook'], 'manage_woocommerce');
		self::add(self::REFRESH_STATUS, [self::class, 'refresh_status'], 'manage_woocommerce');
		self::add(AccountingExport::ACTION, [AccountingExport::class, 'handle'], 'manage_woocommerce');
	}

	/** Adds a handler (`$handler` returns the URL to go back to). */
	public static function add(string $action, callable $handler, string $capability): void
	{
		self::$handlers[$action] = [$handler, $capability];
		add_action('admin_post_' . $action, static function () use ($action): void {
			wp_safe_redirect(self::run($action));
			exit;
		});
	}

	/**
	 * Checks the capability and nonce, then runs the action's handler.
	 *
	 * @return string Where to redirect.
	 */
	public static function run(string $action): string
	{
		[$handler, $capability] = self::$handlers[$action];
		$suffix = isset($_GET['order_id']) ? (string) absint(wp_unslash($_GET['order_id'])) : '';
		if (! current_user_can($capability)) {
			wp_die(esc_html__('You are not allowed to do this.', 'qbitflow-for-woocommerce'), '', ['response' => 403]);
		}
		check_admin_referer($action . $suffix);

		return $handler();
	}

	/** A nonce-protected link to an action. @param array<string, scalar> $args */
	public static function url(string $action, array $args = []): string
	{
		return wp_nonce_url(
			add_query_arg(['action' => $action] + $args, admin_url('admin-post.php')),
			$action . (isset($args['order_id']) ? (string) $args['order_id'] : '')
		);
	}

	public static function connect_webhook(): string
	{
		try {
			EndpointManager::connect();
			StatusPanel::forget();
			Notices::add(__('Webhook connected: QBitFlow now confirms payments and refunds to this store.', 'qbitflow-for-woocommerce'));
		} catch (RuntimeException $e) {
			Notices::add($e->getMessage(), 'error');
		}

		return Gateway::settings_url();
	}

	public static function refresh_status(): string
	{
		StatusPanel::data(true);

		return Gateway::settings_url();
	}
}
