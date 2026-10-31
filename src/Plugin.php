<?php

declare(strict_types=1);

namespace QBitFlowWC;

defined('ABSPATH') || exit;

/**
 * Wires the plugin into WordPress and WooCommerce.
 */
final class Plugin
{
	private static bool $booted = false;

	public static function boot(): void
	{
		if (self::$booted) {
			return;
		}
		self::$booted = true;

		if (! class_exists('WooCommerce')) {
			add_action('admin_notices', static function (): void {
				echo '<div class="notice notice-error"><p>' .
					esc_html__('QBitFlow for WooCommerce requires WooCommerce to be installed and active.', 'qbitflow-for-woocommerce') .
					'</p></div>';
			});

			return;
		}

		Upgrade::init();
		Admin\Notices::init();
		Admin\Actions::register();
		Security\SiteHealth::init();
		Checkout\SessionManager::init();
		Webhooks\Receiver::init();
		Refunds\RefundSync::init();
		Orders\Reconciler::init();
		Admin\OrderMetaBox::init();
		Admin\OrdersList::init();
		Frontend\OrderPages::init();

		if (defined('WP_CLI') && WP_CLI) {
			\WP_CLI::add_command('qbitflow', Cli\Command::class);
		}

		add_filter('woocommerce_payment_gateways', static function (array $gateways): array {
			$gateways[] = Gateway::class;

			return $gateways;
		});

		add_filter('plugin_action_links_' . plugin_basename(QBITFLOW_WC_FILE), static function (array $links): array {
			array_unshift($links, sprintf(
				'<a href="%s">%s</a>',
				esc_url(Gateway::settings_url()),
				esc_html__('Settings', 'qbitflow-for-woocommerce'),
			));

			return $links;
		});
	}

	public static function activate(): void
	{
		Settings::store_id();
	}

	public static function deactivate(): void
	{
		Orders\Reconciler::unschedule();
	}
}
