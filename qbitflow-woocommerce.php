<?php

/**
 * Plugin Name: QBitFlow for WooCommerce
 * Plugin URI: https://qbitflow.app
 * Description: Accept cryptocurrency payments in WooCommerce via QBitFlow. Non-custodial — funds go directly to your wallet.
 * Version: 1.0.0
 * Author: QBitFlow
 * Author URI: https://qbitflow.app
 * License: MPL-2.0
 * License URI: https://opensource.org/licenses/MPL-2.0
 * Text Domain: qbitflow-woocommerce
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.6
 */

if (! defined('ABSPATH')) {
	exit;
}

define('QBITFLOW_WC_VERSION', '1.0.0');
define('QBITFLOW_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('QBITFLOW_WC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('QBITFLOW_WC_API_BASE', 'https://api.qbitflow.app/v1');

/**
 * Declare HPOS compatibility — must run early.
 */
add_action('before_woocommerce_init', function () {
	if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
	}
});

/**
 * Register block checkout support — must run before plugins_loaded.
 */
add_action('woocommerce_blocks_loaded', function () {
	if (! class_exists('Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry')) {
		return;
	}

	require_once QBITFLOW_WC_PLUGIN_DIR . 'includes/class-wc-gateway-qbitflow-blocks.php';

	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry) {
			$registry->register(new WC_Gateway_QBitFlow_Blocks());
		}
	);
});

/**
 * Initialize the gateway on plugins_loaded.
 */
add_action('plugins_loaded', 'qbitflow_wc_init', 11);

function qbitflow_wc_init()
{
	if (! class_exists('WooCommerce')) {
		add_action('admin_notices', function () {
			echo '<div class="error"><p><strong>QBitFlow for WooCommerce</strong> requires WooCommerce to be installed and active.</p></div>';
		});
		return;
	}

	// Load classes
	require_once QBITFLOW_WC_PLUGIN_DIR . 'includes/class-qbitflow-api.php';
	require_once QBITFLOW_WC_PLUGIN_DIR . 'includes/class-qbitflow-customer-sync.php';
	require_once QBITFLOW_WC_PLUGIN_DIR . 'includes/class-wc-gateway-qbitflow.php';

	// Initialize customer sync
	QBitFlow_Customer_Sync::init();

	// Register the gateway
	add_filter('woocommerce_payment_gateways', function ($gateways) {
		$gateways[] = 'WC_Gateway_QBitFlow';
		return $gateways;
	});

	// Register webhook handler
	add_action('rest_api_init', 'qbitflow_wc_register_webhook');
}

/**
 * Register the webhook REST endpoint.
 */
function qbitflow_wc_register_webhook()
{
	register_rest_route('qbitflow-wc', '/webhook', array(
		'methods'             => 'POST',
		'callback'            => 'qbitflow_wc_handle_webhook',
		'permission_callback' => '__return_true',
	));
}

/**
 * Handle incoming webhooks from QBitFlow.
 */
function qbitflow_wc_handle_webhook(WP_REST_Request $request)
{
	$payload   = $request->get_body();
	$signature = $request->get_header('X-Webhook-Signature-256');
	$timestamp = $request->get_header('X-Webhook-Timestamp');

	if (empty($timestamp)) {
		return new WP_REST_Response(array('error' => 'Invalid timestamp'), 400);
	}

	if (! QBitFlow_API::verify_webhook_signature($payload, $signature, $timestamp)) {
		return new WP_REST_Response(array('error' => 'Invalid signature'), 401);
	}

	$data = json_decode($payload, true);
	if (! $data || empty($data['uuid'])) {
		return new WP_REST_Response(array('error' => 'Invalid payload'), 400);
	}


	// Parse the webhook data
	$session_uuid = sanitize_text_field($data['uuid']);
	$status       = sanitize_text_field($data['status']['status'] ?? '');
	$tx_hash      = sanitize_text_field($data['status']['txHash'] ?? '');
	$management_link = esc_url_raw($data['managementPageLink'] ?? '');
	$customer_uuid = sanitize_text_field($data['session']['customerUUID'] ?? '');

	$orders = wc_get_orders(array(
		'meta_key'   => WC_Gateway_QBitFlow::$meta_key_session_uuid,
		'meta_value' => $session_uuid,
		'limit'      => 1,
	));

	if (empty($orders)) {
		global $wpdb;
		$order_id = $wpdb->get_var($wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
			WC_Gateway_QBitFlow::$meta_key_session_uuid,
			$session_uuid
		));
		if ($order_id) {
			$order = wc_get_order($order_id);
		}
	} else {
		$order = $orders[0];
	}

	if (empty($order)) {
		return new WP_REST_Response(array('error' => 'Order not found'), 404);
	}

	if ($tx_hash) {
		$order->update_meta_data(WC_Gateway_QBitFlow::$meta_key_tx_hash, $tx_hash);
	}
	if ($management_link) {
		$order->update_meta_data(WC_Gateway_QBitFlow::$meta_key_management_link, $management_link);
	}
	if ($customer_uuid) {
		$order->update_meta_data(WC_Gateway_QBitFlow::$meta_key_customer_uuid, $customer_uuid);
	}
	$order->update_meta_data(WC_Gateway_QBitFlow::$meta_key_last_status, $status);
	$order->save();

	switch ($status) {
		case 'completed':
			if (! $order->is_paid()) {
				// Now the payment has been completed, we can reduce stock and empty the cart if not already done at checkout
				wc_reduce_stock_levels($order_id);
				WC()->cart->empty_cart();

				$order->payment_complete($session_uuid);
				$order->add_order_note(
					sprintf(__('QBitFlow payment completed. Session: %s', 'qbitflow-woocommerce'), $session_uuid ?: 'N/A')
				);
			}
			break;
		case 'pending':
		case 'waitingConfirmation':
			if ($order->get_status() === 'pending') {
				$order->update_status('on-hold', __('QBitFlow: Awaiting blockchain confirmation.', 'qbitflow-woocommerce'));
			}
			break;
		case 'failed':
			$message = sanitize_text_field($data['status']['message'] ?? 'Payment failed');
			$order->update_status('failed', sprintf(__('QBitFlow payment failed: %s', 'qbitflow-woocommerce'), $message));
			break;
		case 'cancelled':
			$order->update_status('cancelled', __('QBitFlow payment cancelled by customer.', 'qbitflow-woocommerce'));
			break;
		case 'expired':
			$order->update_status('cancelled', __('QBitFlow payment session expired.', 'qbitflow-woocommerce'));
			break;
	}

	return new WP_REST_Response(array('received' => true), 200);
}

/**
 * Add plugin action links.
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
	$settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=qbitflow') . '">' . __('Settings', 'qbitflow-woocommerce') . '</a>';
	array_unshift($links, $settings_link);
	return $links;
});
