<?php

/**
 * Plugin Name: QBitFlow for WooCommerce
 * Plugin URI: https://qbitflow.app/woocommerce
 * Description: Accept cryptocurrency payments in WooCommerce via QBitFlow. Non-custodial — funds go directly to your wallet.
 * Version: 2.0.0
 * Author: QBitFlow
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: qbitflow-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.4
 * Tested up to: 7.1
 * Requires PHP: 8.2
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 11.2
 */

// This file must stay parseable by old PHP versions: it only checks the version and loads the
// plugin. Everything else lives in src/ and needs PHP 8.2.

if (! defined('ABSPATH')) {
	exit;
}

define('QBITFLOW_WC_VERSION', '2.0.0');
define('QBITFLOW_WC_FILE', __FILE__);
define('QBITFLOW_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('QBITFLOW_WC_PLUGIN_URL', plugin_dir_url(__FILE__));

if (version_compare(PHP_VERSION, '8.2', '<')) {
	add_action('admin_notices', function () {
		echo '<div class="notice notice-error"><p>' . sprintf(
			/* translators: %s: the PHP version the site runs */
			esc_html__('QBitFlow for WooCommerce needs PHP 8.2 or later (this site runs PHP %s). The plugin is inactive until PHP is upgraded.', 'qbitflow-for-woocommerce'),
			esc_html(PHP_VERSION)
		) . '</p></div>';
	});
	return;
}

if (! file_exists(QBITFLOW_WC_PLUGIN_DIR . 'vendor-prefixed/autoload.php')) {
	add_action('admin_notices', function () {
		echo '<div class="notice notice-error"><p>' .
			esc_html__('QBitFlow for WooCommerce is missing its bundled libraries. Reinstall the plugin from WordPress.org, or run "composer install" in a development copy.', 'qbitflow-for-woocommerce') .
			'</p></div>';
	});
	return;
}

require_once QBITFLOW_WC_PLUGIN_DIR . 'vendor-prefixed/autoload.php';

// The plugin's own classes (QBitFlowWC\…, the bundled libraries excepted).
spl_autoload_register(function ($class) {
	if (strpos($class, 'QBitFlowWC\\') !== 0 || strpos($class, 'QBitFlowWC\\Vendor\\') === 0) {
		return;
	}
	$file = QBITFLOW_WC_PLUGIN_DIR . 'src/' . str_replace('\\', '/', substr($class, strlen('QBitFlowWC\\'))) . '.php';
	if (is_readable($file)) {
		require $file;
	}
});

// Declare HPOS and block-checkout compatibility early.
add_action('before_woocommerce_init', function () {
	if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', QBITFLOW_WC_FILE, true);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', QBITFLOW_WC_FILE, true);
	}
});

add_action('woocommerce_blocks_loaded', array('QBitFlowWC\\Blocks\\PaymentMethod', 'register'));

add_action('plugins_loaded', array('QBitFlowWC\\Plugin', 'boot'), 11);

register_activation_hook(__FILE__, array('QBitFlowWC\\Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('QBitFlowWC\\Plugin', 'deactivate'));
