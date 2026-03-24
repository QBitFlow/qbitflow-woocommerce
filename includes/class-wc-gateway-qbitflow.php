<?php

/**
 * WooCommerce QBitFlow Payment Gateway.
 */

if (! defined('ABSPATH')) {
	exit;
}

class WC_Gateway_QBitFlow extends WC_Payment_Gateway
{

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		$this->id                 = 'qbitflow';
		$this->icon               = QBITFLOW_WC_PLUGIN_URL . 'assets/img/qbitflow-icon.png';
		$this->has_fields         = false;
		$this->method_title       = __('QBitFlow', 'qbitflow-woocommerce');
		$this->method_description = __('Accept cryptocurrency payments via QBitFlow. Non-custodial — funds go directly to your wallet via smart contracts.', 'qbitflow-woocommerce');

		// Supported features
		$this->supports = array(
			'products',
			'refunds',
		);

		// Load settings
		$this->init_form_fields();
		$this->init_settings();

		$this->title       = "QBitFlow";
		$this->description = "Pay with cryptocurrency (ETH, SOL, USDC, USDT, and more). Powered by QBitFlow — non-custodial, secure, instant.";
		$this->enabled     = $this->get_option('enabled');

		// Save settings
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

		// Thank you page
		add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));

		// Customer email instructions
		add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);

		add_filter(
			'woocommerce_my_account_my_orders_actions',
			array($this, 'add_invoice_action'),
			10,
			2
		);

		add_action('add_meta_boxes', array($this, 'add_order_meta_box'));
	}

	/**
	 * Admin settings fields.
	 */
	public function init_form_fields()
	{
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __('Enable/Disable', 'qbitflow-woocommerce'),
				'type'    => 'checkbox',
				'label'   => __('Enable QBitFlow Crypto Payments', 'qbitflow-woocommerce'),
				'default' => 'no',
			),
			'api_key'        => array(
				'title'       => __('API Key', 'qbitflow-woocommerce'),
				'type'        => 'password',
				'description' => __('Get your API key from the QBitFlow dashboard at qbitflow.app.', 'qbitflow-woocommerce'),
				'default'     => '',
				'desc_tip'    => true,
			),
			'debug'          => array(
				'title'       => __('Debug Log', 'qbitflow-woocommerce'),
				'type'        => 'checkbox',
				'label'       => __('Enable logging', 'qbitflow-woocommerce'),
				'default'     => 'no',
				'description' => sprintf(
					__('Log events to %s', 'qbitflow-woocommerce'),
					'<code>' . WC_Log_Handler_File::get_log_file_path('qbitflow') . '</code>'
				),
			),
		);
	}

	/**
	 * Check if the gateway is available.
	 */
	public function is_available()
	{
		if ('yes' !== $this->enabled) {
			return false;
		}
		if (empty($this->get_option('api_key'))) {
			return false;
		}
		return true;
	}

	/**
	 * Process the payment — create QBitFlow session and redirect.
	 */
	public function process_payment($order_id)
	{
		$order   = wc_get_order($order_id);

		// Build product name from order items
		$items        = $order->get_items();
		$product_name = '';
		if (count($items) === 1) {
			$item         = reset($items);
			$product_name = $item->get_name();
		} else {
			$product_name = sprintf(__('Order #%s', 'qbitflow-woocommerce'), $order->get_order_number());
		}

		// Build description
		$item_names = array();
		foreach ($items as $item) {
			$qty          = $item->get_quantity();
			$item_names[] = $item->get_name() . ($qty > 1 ? " ×{$qty}" : '');
		}
		$description = implode(', ', $item_names);
		if (strlen($description) > 200) {
			$description = substr($description, 0, 197) . '…';
		}

		// Create QBitFlow payment session
		$body = array(
			'productName' => $product_name,
			'description'  => $description,
			'price'        => floatval($order->get_total()),
			'webhookUrl'  => rest_url('qbitflow-wc/webhook'),
			'successUrl'  => $this->get_return_url($order),
			'cancelUrl'   => $order->get_cancel_order_url_raw(),
		);

		// Attach QBitFlow customer UUID if available
		$customer_uuid = QBitFlow_Customer_Sync::get_customer_uuid($order);
		if ($customer_uuid) {
			$body['customerUuid'] = $customer_uuid;
		} else {
			// Fallback: pass email so QBitFlow can at least identify them
			$email = $order->get_billing_email();
			if ($email) {
				$body['customerEmail'] = $email;
			}
		}

		$this->log('Creating payment session for order #' . $order_id);

		$response = QBitFlow_API::create_payment_session($body);

		if (is_wp_error($response)) {
			$this->log('API error: ' . $response->get_error_message());
			wc_add_notice(__(
				'Unable to create crypto payment session. Please try again.',
				'qbitflow-woocommerce'
			), 'error');
			return array('result' => 'failure');
		}

		if (empty($response['link'])) {
			$msg = $response['message'] ?? 'Unknown API error';
			$this->log("API error: {$msg}");
			wc_add_notice(
				__('Crypto payment error: ', 'qbitflow-woocommerce') . $msg,
				'error'
			);
			return array('result' => 'failure');
		}

		// Store session UUID on the order
		$order->update_meta_data(self::$meta_key_session_uuid, sanitize_text_field(
			$response['uuid']
		));
		$order->update_meta_data(self::$meta_key_payment_link, esc_url_raw($response['link']));
		$order->update_meta_data(self::$meta_key_last_status, 'created');
		$order->save();

		$order->update_status('pending', __('Awaiting QBitFlow crypto payment.', 'qbitflow-woocommerce'));

		$this->log('Redirecting order #' . $order_id . ' to QBitFlow checkout: ' .
			$response['link']);

		return array(
			'result'   => 'success',
			'redirect' => $response['link'],
		);
	}

	/**
	 * Thank you page content.
	 */
	public function thankyou_page($order_id)
	{
		$order   = wc_get_order($order_id);
		$tx_hash = $order->get_meta(self::$meta_key_tx_hash);
		$status  = $order->get_meta(self::$meta_key_last_status);
		$management_link = $order->get_meta(self::$meta_key_management_link);

		echo '<div class="qbitflow-thankyou" style="margin: 20px 0; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;">';

		if ($status === 'completed' && $tx_hash) {
			echo '<h2>' . esc_html__('Crypto Payment Confirmed ✓', 'qbitflow-woocommerce') .
				'</h2>';
			echo '<p>' . esc_html__(
				'Your payment has been confirmed on the blockchain.',
				'qbitflow-woocommerce'
			) . '</p>';
			echo '<p><strong>' . esc_html__('Transaction Hash:', 'qbitflow-woocommerce') .
				'</strong> <code>' . esc_html($tx_hash) . '</code></p>';
		} elseif (in_array($status, array('pending', 'waitingConfirmation'), true)) {
			echo '<h2>' . esc_html__('Payment Processing…', 'qbitflow-woocommerce') . '</h2>';
			echo '<p>' . esc_html__('Your crypto payment is being confirmed on the blockchain. You\'ll receive an email once it\'s complete.', 'qbitflow-woocommerce') . '</p>';
		}

		if ($management_link) {
			echo '<p style="margin-top: 15px;">';
			echo '<a href="' . esc_url($management_link) . '" target="_blank" class="button" style="background: #2c2c2c; color: #fff; padding: 10px 20px; border-radius: 5px; text-decoration: none; display: inline-block;">';
			echo esc_html__('View Payment Details & Invoice', 'qbitflow-woocommerce');
			echo '</a></p>';
		}

		echo '</div>';
	}

	/**
	 * Email instructions.
	 */
	public function email_instructions($order, $sent_to_admin, $plain_text = false)
	{
		if ($this->id !== $order->get_payment_method()) {
			return;
		}

		$tx_hash         = $order->get_meta(self::$meta_key_tx_hash);
		$management_link = $order->get_meta(self::$meta_key_management_link);

		if ($plain_text) {
			if ($tx_hash) {
				echo "\n" . __('Transaction Hash:', 'qbitflow-woocommerce') . ' ' . $tx_hash .
					"\n";
			}
			if ($management_link) {
				echo __('View your payment details & invoice:', 'qbitflow-woocommerce') . ' ' .
					$management_link . "\n";
			}
		} else {
			if ($tx_hash) {
				echo '<p><strong>' . esc_html__('Crypto Transaction Hash:', 'qbitflow-woocommerce') . '</strong> <code>' . esc_html($tx_hash) . '</code></p>';
			}
			if ($management_link) {
				echo '<p><a href="' . esc_url($management_link) . '" target="_blank" style="background: #2c2c2c; color: #fff; padding: 8px 16px; border-radius: 4px; text-decoration:none; display: inline-block;">';
				echo esc_html__('View Payment Details & Invoice →', 'qbitflow-woocommerce');
				echo '</a></p>';
			}
		}
	}

	/**
	 * Process refund — redirect merchant to QBitFlow dashboard for crypto refund.
	 */
	public function process_refund($order_id, $amount = null, $reason = '')
	{
		$order        = wc_get_order($order_id);
		$session_uuid = $order->get_meta(self::$meta_key_session_uuid);
		$tx_hash      = $order->get_meta(self::$meta_key_tx_hash);

		// Build dashboard link
		$dashboard_link = 'https://qbitflow.app/dashboard';

		$order->add_order_note(
			sprintf(
				__('Refund of %1$s requested.%2$sReason: %3$s%4$sSession: %5$s%6$sTx Hash:%7$s%8$s⚠️ Crypto refunds cannot be processed automatically. Please process this refund manually from your QBitFlow dashboard: %9$s%10$sOnce completed, update this order\'s notes with the refund transaction hash for your records.', 'qbitflow-woocommerce'),
				wc_price($amount),
				"\n",
				$reason ?: 'N/A',
				"\n",
				$session_uuid ?: 'N/A',
				"\n",
				$tx_hash ?: 'N/A',
				"\n\n",
				$dashboard_link,
				"\n"
			)
		);

		// Add admin notice to remind merchant
		set_transient(
			'qbitflow_refund_notice_' . $order_id,
			array(
				'amount'         => $amount,
				'dashboard_link' => $dashboard_link,
				'order_id'       => $order_id,
			),
			60 * 5 // 5 minutes
		);

		return true;
	}

	/**
	 * Show admin notice after refund with link to QBitFlow dashboard.
	 */
// 	public function show_refund_notice()
// 	{
// 		$screen = get_current_screen();
// 		if (! $screen) {
// 			return;
// 		}

// 		// Check if we're on an order page
// 		$order_id = isset($_GET['id']) ? absint($_GET['id']) : (isset($_GET['post']) ?
// 			absint($_GET['post']) : 0);
// 		if (! $order_id) {
// 			return;
// 		}

// 		$notice = get_transient('qbitflow_refund_notice_' . $order_id);
// 		if (! $notice) {
// 			return;
// 		}

// 		delete_transient('qbitflow_refund_notice_' . $order_id);

// ?>
// 		<div class="notice notice-warning is-dismissible" style="border-left-color: #f0b849;">
// 			<h3 style="margin: 0.5em 0;">⚠️ <?php esc_html_e(
// 												'QBitFlow — Crypto Refund Required',
// 												'qbitflow-woocommerce'
// 											); ?></h3>
// 			<p>
// 				<?php
// 				printf(
// 					esc_html__(
// 						'A refund of %s has been recorded in WooCommerce for order #%d.',
// 						'qbitflow-woocommerce'
// 					),
// 					wc_price($notice['amount']),
// 					$notice['order_id']
// 				);
// 				?>
// 			</p>
// 			<p>
// 				<?php esc_html_e('Blockchain transactions are irreversible — you must process this refund manually from your QBitFlow dashboard.', 'qbitflow-woocommerce'); ?>
// 			</p>
// 			<p style="margin-bottom: 1em;">
// 				<a href="<?php echo esc_url($notice['dashboard_link']); ?>"
// 					target="_blank"
// 					class="button button-primary"
// 					style="background: #2c2c2c; border-color: #2c2c2c; color: #fff;">
// 					<?php esc_html_e('Go to QBitFlow Dashboard →', 'qbitflow-woocommerce'); ?>
// 				</a>
// 			</p>
// 		</div>
// <?php
// 	}


	/**
	 * Add "View Invoice" button to My Account → Orders.
	 */
	public function add_invoice_action($actions, $order)
	{
		if ($order->get_payment_method() !== $this->id) {
			return $actions;
		}

		$management_link = $order->get_meta(self::$meta_key_management_link);
		if ($management_link) {
			$actions['qbitflow_invoice'] = array(
				'url'  => $management_link,
				'name' => __('Invoice', 'qbitflow-woocommerce'),
			);
		}

		return $actions;
	}

	/**
	 * Log messages.
	 */
	public function log($message)
	{
		if ('yes' === $this->get_option('debug')) {
			$logger = wc_get_logger();
			$logger->info($message, array('source' => 'qbitflow'));
		}
	}



	
	//////////////////// Meta box \\\\\\\\\\\\\\\\\\\\

	/**
	 * Add meta box to order page.
	 */
	public function add_order_meta_box()
	{
		$screen = class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController') && wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id('shop-order')
			: 'shop_order';

		add_meta_box(
			'qbitflow-payment-info',
			__('QBitFlow Payment', 'qbitflow-woocommerce'),
			array($this, 'render_order_meta_box'),
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * Render the meta box content.
	 */
	public function render_order_meta_box($post_or_order)
	{
		$order = $post_or_order instanceof WP_Post
			? wc_get_order($post_or_order->ID)
			: $post_or_order;

		if (! $order || $order->get_payment_method() !== $this->id) {
			echo '<p>' . esc_html__('Not a QBitFlow payment.', 'qbitflow-woocommerce') . '</p>';
			return;
		}

		$session_uuid    = $order->get_meta(self::$meta_key_session_uuid);
		$tx_hash         = $order->get_meta(self::$meta_key_tx_hash);
		$status          = $order->get_meta(self::$meta_key_last_status);
		$customer_uuid   = $order->get_meta(self::$meta_key_customer_uuid);
		$management_link = $order->get_meta(self::$meta_key_management_link);

		echo '<table class="widefat" style="border:0">';

		if ($status) {
			$status_colors = array(
				'completed'           => '#28a745',
				'pending'             => '#ffc107',
				'waitingConfirmation' => '#17a2b8',
				'failed'              => '#dc3545',
				'cancelled'           => '#6c757d',
				'expired'             => '#6c757d',
				'created'             => '#007bff',
			);
			$color = $status_colors[$status] ?? '#666';
			echo '<tr><td><strong>' . esc_html__('Status', 'qbitflow-woocommerce') .
				'</strong></td>';
			echo '<td><span style="color:' . esc_attr($color) . ';font-weight:bold">' .
				esc_html(ucfirst($status)) . '</span></td></tr>';
		}

		if ($session_uuid) {
			echo '<tr><td><strong>' . esc_html__('Session', 'qbitflow-woocommerce') .
				'</strong></td>';
			echo '<td><code style="font-size:11px;word-break:break-all">' . esc_html(
				$session_uuid
			) . '</code></td></tr>';
		}

		if ($tx_hash) {
			echo '<tr><td><strong>' . esc_html__('Tx Hash', 'qbitflow-woocommerce') .
				'</strong></td>';
			echo '<td><code style="font-size:11px;word-break:break-all">' . esc_html($tx_hash) .
				'</code></td></tr>';
		}

		if ($customer_uuid) {
			echo '<tr><td><strong>' . esc_html__('Customer', 'qbitflow-woocommerce') .
				'</strong></td>';
			echo '<td><code style="font-size:11px;word-break:break-all">' . esc_html(
				$customer_uuid
			) . '</code></td></tr>';
		}

		if ($management_link) {
			echo '<tr><td colspan="2" style="padding-top:8px">';
			echo '<a href="' . esc_url($management_link) . '" target="_blank" class="button button-primary button-small">';
			echo esc_html__('View Invoice / Management Page', 'qbitflow-woocommerce');
			echo '</a></td></tr>';
		}

		echo '</table>';
	}


	//////////////////// Metadata keys \\\\\\\\\\\\\\\\\\\\

	public static $meta_key_uuid = '_qbitflow_customer_uuid';
	public static $meta_key_session_uuid = '_qbitflow_session_uuid';
	public static $meta_key_payment_link = '_qbitflow_payment_link';
	public static $meta_key_last_status = '_qbitflow_last_status';
	public static $meta_key_tx_hash = '_qbitflow_tx_hash';
	public static $meta_key_management_link = '_qbitflow_management_link';
	public static $meta_key_customer_uuid = '_qbitflow_customer_uuid';
}
