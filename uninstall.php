<?php
/**
 * Runs on plugin deletion (not deactivation).
 * Cleans up all data stored by QBitFlow for WooCommerce.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

// Remove plugin settings
delete_option('woocommerce_qbitflow_settings');

// Remove the persistent email → UUID map. There's no first-class WP API
// for "delete options by prefix", so a direct LIKE query is the standard
// pattern. Caching is irrelevant here — uninstall runs once and the rows
// are about to vanish anyway.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bulk option cleanup by prefix; no public API equivalent. Cache irrelevant in uninstall.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like('qbitflow_cuuid_') . '%'
	)
);

// Remove QBitFlow customer UUID from all user meta (legacy from pre-1.1.0 versions).
delete_metadata('user', 0, '_qbitflow_customer_uuid', '', true);
