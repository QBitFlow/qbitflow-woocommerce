<?php

/**
 * Plugin Name: QBitFlow for WooCommerce
 * Plugin URI: https://qbitflow.app
 * Description: Accept cryptocurrency payments in WooCommerce via QBitFlow. Non-custodial — funds go directly to your wallet.
 * Version: 1.1.0
 * Author: QBitFlow
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: qbitflow-for-woocommerce
 * Requires at least: 5.8
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.7
 */

if (! defined('ABSPATH')) {
	exit;
}

define('QBITFLOW_WC_VERSION', '1.1.0');
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

	// Admin notice for pending refund requests
	add_action('admin_notices', 'qbitflow_wc_pending_refunds_notice');
}

/**
 * Register the webhook REST endpoint.
 */
function qbitflow_wc_register_webhook()
{
	register_rest_route('qbitflow-wc', '/webhook', array(
		'methods'             => 'POST',
		'callback'            => 'qbitflow_wc_handle_webhook',
		// Public endpoint: QBitFlow's webhook delivery cannot present a WordPress credential.
		// Auth boundary is the HMAC signature verified inside the callback via QBitFlow_API::verify_webhook_signature().
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

	// Look up the order via meta_query — webhooks correlate to orders only
	// through the QBitFlow session UUID stored in order meta, so this is
	// unavoidable. Works for both classic post-table orders and HPOS-stored
	// orders; the old postmeta fallback never matched HPOS rows anyway.
	// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required: webhook → order correlation by session UUID; runs once per webhook delivery.
	$orders = wc_get_orders(array(
		'limit'      => 1,
		'meta_query' => array(
			array(
				'key'   => WC_Gateway_QBitFlow::$meta_key_session_uuid,
				'value' => $session_uuid,
			),
		),
	));
	// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query

	if (empty($orders)) {
		return new WP_REST_Response(array('error' => 'Order not found'), 404);
	}

	$order = $orders[0];

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
				wc_reduce_stock_levels($order);
				if (WC()->cart) {
					WC()->cart->empty_cart();
				}

				$order->payment_complete($session_uuid);
				$order->add_order_note(
					sprintf(
						/* translators: %s: QBitFlow session UUID */
						__('QBitFlow payment completed. Session: %s', 'qbitflow-for-woocommerce'),
						$session_uuid ?: 'N/A'
					)
				);
			}
			break;
		case 'pending':
		case 'waitingConfirmation':
			if ($order->get_status() === 'pending') {
				$order->update_status('on-hold', __('QBitFlow: Awaiting blockchain confirmation.', 'qbitflow-for-woocommerce'));
			}
			break;
		case 'failed':
			$message = sanitize_text_field($data['status']['message'] ?? 'Payment failed');
			$order->update_status(
				'failed',
				sprintf(
					/* translators: %s: failure message returned by QBitFlow */
					__('QBitFlow payment failed: %s', 'qbitflow-for-woocommerce'),
					$message
				)
			);
			break;
		case 'cancelled':
			$order->update_status('cancelled', __('QBitFlow payment cancelled by customer.', 'qbitflow-for-woocommerce'));
			break;
		case 'expired':
			$order->update_status('cancelled', __('QBitFlow payment session expired.', 'qbitflow-for-woocommerce'));
			break;
	}

	return new WP_REST_Response(array('received' => true), 200);
}

/**
 * Admin notice: alert merchant when pending refund requests exist.
 *
 * Refunds are handled outside WordPress (the merchant approves/refuses on the
 * QBitFlow dashboard), so we always refetch — no transient cache. A
 * per-request static guard avoids double-fetching inside the same page render.
 */
function qbitflow_wc_pending_refunds_notice()
{
	if (! current_user_can('manage_woocommerce')) {
		return;
	}

	// Handle one-hour dismissal
	if (isset($_GET['qbitflow_dismiss_refunds']) && check_admin_referer('qbitflow_dismiss_refunds')) {
		set_transient('qbitflow_refunds_dismissed_' . get_current_user_id(), true, HOUR_IN_SECONDS);
		wp_safe_redirect(remove_query_arg(array('qbitflow_dismiss_refunds', '_wpnonce')));
		exit;
	}

	if (get_transient('qbitflow_refunds_dismissed_' . get_current_user_id())) {
		return;
	}

	static $count = null;
	if ($count === null) {
		$result = QBitFlow_API::get_pending_refunds();
		$count  = (! is_wp_error($result) && is_array($result)) ? count($result) : 0;
	}

	if ($count < 1) {
		return;
	}

	$dismiss_url = wp_nonce_url(add_query_arg('qbitflow_dismiss_refunds', '1'), 'qbitflow_dismiss_refunds');
?>
	<div class="notice notice-warning" style="display:flex;align-items:center;gap:12px;padding:12px 16px;">
		<p style="margin:0;flex:1">
			<strong><?php esc_html_e('QBitFlow — Pending Refund Requests', 'qbitflow-for-woocommerce'); ?></strong>
			&nbsp;
			<?php
			printf(
				esc_html(
					/* translators: %d: number of pending refund requests */
					_n(
						'You have %d pending refund request.',
						'You have %d pending refund requests.',
						$count,
						'qbitflow-for-woocommerce'
					)
				),
				(int) $count
			);
			?>
		</p>
		<a href="https://qbitflow.app/refunds" target="_blank" class="button button-primary button-small">
			<?php esc_html_e('Review on QBitFlow &rarr;', 'qbitflow-for-woocommerce'); ?>
		</a>
		<a href="<?php echo esc_url($dismiss_url); ?>" style="white-space:nowrap;font-size:12px;">
			<?php esc_html_e('Dismiss for 1 hour', 'qbitflow-for-woocommerce'); ?>
		</a>
	</div>
<?php
}

/**
 * Add plugin action links.
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
	$settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=qbitflow') . '">' . __('Settings', 'qbitflow-for-woocommerce') . '</a>';
	array_unshift($links, $settings_link);
	return $links;
});
