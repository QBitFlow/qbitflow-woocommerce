<?php

$qbitflow_wc_root = dirname(__DIR__, 2);
$qbitflow_wc_tests_dir = getenv('WP_TESTS_DIR') ?: '/wordpress-phpunit';

require_once $qbitflow_wc_root . '/vendor/autoload.php';
require_once $qbitflow_wc_tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function () use ($qbitflow_wc_root): void {
	// wp-env installs it as woocommerce.latest-stable/.
	$woocommerce = glob(WP_PLUGIN_DIR . '/woocommerce*/woocommerce.php');
	require $woocommerce[0];
	require $qbitflow_wc_root . '/qbitflow-for-woocommerce.php';
});

tests_add_filter('setup_theme', static function (): void {
	define('WC_REMOVE_ALL_DATA', true);
	WC_Install::install();
	// HPOS on, without compatibility-mode syncing: the storage new stores get.
	update_option('woocommerce_custom_orders_table_enabled', 'yes');
	update_option('woocommerce_custom_orders_table_data_sync_enabled', 'no');
	$GLOBALS['wp_roles'] = null;
	wp_roles();
});

require $qbitflow_wc_tests_dir . '/includes/bootstrap.php';
