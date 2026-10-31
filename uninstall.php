<?php

/**
 * Runs when the plugin is deleted (not deactivated): removes what the plugin stored, and
 * deletes the store's webhook endpoints on QBitFlow (best effort).
 *
 * Orders keep their QBitFlow meta: it is part of their payment history.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

$qbitflow_wc_dir = __DIR__ . '/';

// Delete the remote endpoints while the key is still known.
if (version_compare(PHP_VERSION, '8.2', '>=') && file_exists($qbitflow_wc_dir . 'vendor-prefixed/autoload.php')) {
	require_once $qbitflow_wc_dir . 'vendor-prefixed/autoload.php';
	if (! defined('QBITFLOW_WC_PLUGIN_DIR')) {
		define('QBITFLOW_WC_PLUGIN_DIR', $qbitflow_wc_dir);
	}
	if (! defined('QBITFLOW_WC_VERSION')) {
		define('QBITFLOW_WC_VERSION', '2.0.0');
	}
	spl_autoload_register(static function ($class) use ($qbitflow_wc_dir) {
		if (strpos($class, 'QBitFlowWC\\') === 0 && strpos($class, 'QBitFlowWC\\Vendor\\') !== 0) {
			$file = $qbitflow_wc_dir . 'src/' . str_replace('\\', '/', substr($class, strlen('QBitFlowWC\\'))) . '.php';
			if (is_readable($file)) {
				require $file;
			}
		}
	});
	try {
		$qbitflow_wc_stored = QBitFlowWC\Webhooks\EndpointManager::stored();
		$qbitflow_wc_client = $qbitflow_wc_stored !== null ? QBitFlowWC\ClientFactory::make(5.0, 0) : null;
		if ($qbitflow_wc_client !== null) {
			$qbitflow_wc_client->webhooks->endpoints->delete($qbitflow_wc_stored['uuid']);
		}
	} catch (Throwable) {
		// Best effort: the endpoint can be deleted from the QBitFlow dashboard.
	}
}

if (function_exists('as_unschedule_all_actions')) {
	as_unschedule_all_actions('qbitflow_wc_reconcile', [], 'qbitflow');
	as_unschedule_all_actions('qbitflow_wc_sync_refunds', [], 'qbitflow');
	as_unschedule_all_actions('qbitflow_wc_refresh_coins', [], 'qbitflow');
}

foreach (
	[
		'woocommerce_qbitflow_settings',
		'qbitflow_wc_store_id',
		'qbitflow_wc_webhook_test',
		'qbitflow_wc_webhook_live',
		'qbitflow_wc_db_version',
		'qbitflow_wc_pending_refunds',
		'qbitflow_wc_member',
		'qbitflow_wc_account_fee', // 2.0 betas.
	] as $qbitflow_wc_option
) {
	delete_option($qbitflow_wc_option);
}
delete_transient('qbitflow_wc_status');
delete_transient('qbitflow_wc_not_ready');
delete_transient('qbitflow_wc_checkouts_off');
delete_transient('qbitflow_wc_no_redirects');
delete_transient('qbitflow_wc_coins');
delete_transient('qbitflow_wc_coins_backoff');

global $wpdb;
// 1.x's customer map, the transients, and order locks a crashed request left behind.
foreach (['qbitflow_cuuid_', '_transient_qbitflow_', '_transient_timeout_qbitflow_', 'qbitflow_wc_lock_'] as $qbitflow_wc_prefix) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Lookup by prefix at uninstall; no API equivalent.
	$qbitflow_wc_names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($qbitflow_wc_prefix) . '%'));
	foreach ($qbitflow_wc_names as $qbitflow_wc_name) {
		delete_option((string) $qbitflow_wc_name);
	}
}

// 1.x (before 1.1.0) cached customer ids on users.
delete_metadata('user', 0, '_qbitflow_customer_uuid', '', true);
