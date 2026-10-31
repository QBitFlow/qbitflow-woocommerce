<?php

declare(strict_types=1);

namespace QBitFlowWC;

use QBitFlowWC\Admin\Actions;
use QBitFlowWC\Webhooks\EndpointManager;

defined('ABSPATH') || exit;

/**
 * One-time data upgrades, and the notice that asks to connect the webhook (1.x stores had it
 * set up by hand for the v1 API, which 2.0 no longer uses).
 */
final class Upgrade
{
	public const VERSION_OPTION = 'qbitflow_wc_db_version';

	public const VERSION = 4;

	public static function init(): void
	{
		self::maybe_run();
		add_action('admin_notices', [self::class, 'connect_notice']);
	}

	public static function maybe_run(): void
	{
		$from = (int) get_option(self::VERSION_OPTION, 0);
		if ($from >= self::VERSION) {
			return;
		}

		Settings::store_id();
		if ($from < 2) {
			self::delete_like('qbitflow_cuuid_');          // 1.x email → customer map (2.0 finds customers by reference).
			self::delete_like('_transient_qbitflow_refund_'); // 1.x refund lookups.
			self::delete_like('_transient_timeout_qbitflow_refund_');
		}
		if ($from < 3) {
			self::migrate_processing_fee();
		}
		if ($from < 4) {
			// 2.0 betas cached the QBitFlow account's settings: they are the dashboard's now.
			delete_option('qbitflow_wc_account_fee');
			delete_transient('qbitflow_wc_account_settings');
		}
		update_option(self::VERSION_OPTION, self::VERSION, true);
		Log::info(sprintf('Data upgraded from version %d to %d.', $from, self::VERSION));
	}

	/** 2.0 betas' yes/no `customer_pays_fee` becomes the three-way `processing_fee` (`yes` → customer, else the account decides). */
	private static function migrate_processing_fee(): void
	{
		$settings = get_option(Settings::OPTION);
		if (! is_array($settings)) {
			return;
		}
		if (! isset($settings['processing_fee'])) {
			$settings['processing_fee'] = 'yes' === ($settings['customer_pays_fee'] ?? 'no') ? 'customer' : 'account';
		}
		unset($settings['customer_pays_fee']);
		update_option(Settings::OPTION, $settings);
	}

	/** While the gateway is on and no webhook secret is set: payments are only confirmed by the background check. */
	public static function connect_notice(): void
	{
		if (! current_user_can('manage_woocommerce') || ! Settings::enabled() || ! EndpointManager::needs_connect()) {
			return;
		}
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ($screen !== null && ! in_array($screen->id, ['dashboard', 'plugins', 'woocommerce_page_wc-settings', 'woocommerce_page_wc-orders', 'edit-shop_order'], true)) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <a class="button button-primary" href="%s">%s</a></p></div>',
			esc_html__('QBitFlow:', 'qbitflow-for-woocommerce'),
			esc_html__('connect the webhook so payments and refunds are confirmed to your store right away (until then, orders are checked every 15 minutes).', 'qbitflow-for-woocommerce'),
			esc_url(Actions::url(Actions::CONNECT_WEBHOOK)),
			esc_html__('Connect webhook', 'qbitflow-for-woocommerce')
		);
	}

	/** Deletes options by name prefix, through delete_option() so object caches follow. */
	public static function delete_like(string $prefix): void
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-time lookup of options by prefix; no API equivalent.
		$names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($prefix) . '%'));
		foreach ($names as $name) {
			delete_option((string) $name);
		}
	}
}
