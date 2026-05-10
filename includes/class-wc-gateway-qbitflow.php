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
		$this->method_title       = __('QBitFlow', 'qbitflow-for-woocommerce');
		$this->method_description = __('Accept cryptocurrency payments via QBitFlow. Non-custodial — funds go directly to your wallet via smart contracts.', 'qbitflow-for-woocommerce');

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

		// Refund handling lives on QBitFlow, so on every order edit screen we
		// pull the latest refund record and — when it's been approved on-chain —
		// auto-create the matching WC refund so the order header shows
		// "Refunded" without the merchant having to do a manual entry.
		add_action('current_screen', array($this, 'sync_refund_on_order_screen'));
	}

	/**
	 * Admin settings fields.
	 */
	public function init_form_fields()
	{
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __('Enable/Disable', 'qbitflow-for-woocommerce'),
				'type'    => 'checkbox',
				'label'   => __('Enable QBitFlow Crypto Payments', 'qbitflow-for-woocommerce'),
				'default' => 'no',
			),
			'api_key'        => array(
				'title'       => __('API Key', 'qbitflow-for-woocommerce'),
				'type'        => 'password',
				'description' => __('Get your API key from the QBitFlow dashboard at qbitflow.app.', 'qbitflow-for-woocommerce'),
				'default'     => '',
				'desc_tip'    => true,
			),
			'debug'          => array(
				'title'       => __('Debug Log', 'qbitflow-for-woocommerce'),
				'type'        => 'checkbox',
				'label'       => __('Enable logging', 'qbitflow-for-woocommerce'),
				'default'     => 'no',
				'description' => sprintf(
					/* translators: %s: filesystem path to the WooCommerce qbitflow log file */
					__('Log events to %s', 'qbitflow-for-woocommerce'),
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
			/* translators: %s: WooCommerce order number */
			$product_name = sprintf(__('Order #%s', 'qbitflow-for-woocommerce'), $order->get_order_number());
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

		// Always attach a QBitFlow customer UUID — the previous on_order_created
		// hook may have skipped (manual order, legacy checkout) or its API call
		// may have failed silently. ensure_uuid_for_order() does an authoritative
		// find-by-email lookup and creates the customer from the order's billing
		// fields when needed, so the QBitFlow checkout never has to re-prompt
		// the buyer for details WooCommerce already collected.
		$customer_uuid = QBitFlow_Customer_Sync::ensure_uuid_for_order($order);
		if ($customer_uuid) {
			$body['customerUUID'] = $customer_uuid;
		}

		$this->log('Creating payment session for order #' . $order_id);

		$response = QBitFlow_API::create_payment_session($body);

		if (is_wp_error($response)) {
			$this->log('API error: ' . $response->get_error_message());
			wc_add_notice(__(
				'Unable to create crypto payment session. Please try again.',
				'qbitflow-for-woocommerce'
			), 'error');
			return array('result' => 'failure');
		}

		if (empty($response['link'])) {
			$msg = $response['message'] ?? 'Unknown API error';
			$this->log("API error: {$msg}");
			wc_add_notice(
				__('Crypto payment error: ', 'qbitflow-for-woocommerce') . $msg,
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

		$order->update_status('pending', __('Awaiting QBitFlow crypto payment.', 'qbitflow-for-woocommerce'));

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
			echo '<h2>' . esc_html__('Crypto Payment Confirmed ✓', 'qbitflow-for-woocommerce') .
				'</h2>';
			echo '<p>' . esc_html__(
				'Your payment has been confirmed on the blockchain.',
				'qbitflow-for-woocommerce'
			) . '</p>';
			echo '<p><strong>' . esc_html__('Transaction Hash:', 'qbitflow-for-woocommerce') .
				'</strong> <code>' . esc_html($tx_hash) . '</code></p>';
		} elseif (in_array($status, array('pending', 'waitingConfirmation'), true)) {
			echo '<h2>' . esc_html__('Payment Processing…', 'qbitflow-for-woocommerce') . '</h2>';
			echo '<p>' . esc_html__('Your crypto payment is being confirmed on the blockchain. You\'ll receive an email once it\'s complete.', 'qbitflow-for-woocommerce') . '</p>';
		}

		if ($management_link) {
			echo '<p style="margin-top: 15px;">';
			echo '<a href="' . esc_url($management_link) . '" target="_blank" class="button" style="background: #2c2c2c; color: #fff; padding: 10px 20px; border-radius: 5px; text-decoration: none; display: inline-block;">';
			echo esc_html__('View Payment Details & Invoice', 'qbitflow-for-woocommerce');
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
				echo "\n" . esc_html__('Transaction Hash:', 'qbitflow-for-woocommerce') . ' ' . esc_html($tx_hash) . "\n";
			}
			if ($management_link) {
				echo esc_html__('View your payment details & invoice:', 'qbitflow-for-woocommerce') . ' ' . esc_url($management_link) . "\n";
			}
		} else {
			if ($tx_hash) {
				echo '<p><strong>' . esc_html__('Crypto Transaction Hash:', 'qbitflow-for-woocommerce') . '</strong> <code>' . esc_html($tx_hash) . '</code></p>';
			}
			if ($management_link) {
				echo '<p><a href="' . esc_url($management_link) . '" target="_blank" style="background: #2c2c2c; color: #fff; padding: 8px 16px; border-radius: 4px; text-decoration:none; display: inline-block;">';
				echo esc_html__('View Payment Details & Invoice →', 'qbitflow-for-woocommerce');
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
				/* translators: 1: refund amount (formatted), 2,4,6,8,10: line breaks, 3: refund reason, 5: QBitFlow session UUID, 7: payment tx hash, 9: link to the QBitFlow dashboard */
				__('Refund of %1$s requested.%2$sReason: %3$s%4$sSession: %5$s%6$sTx Hash:%7$s%8$s⚠️ Crypto refunds cannot be processed automatically. Please process this refund manually from your QBitFlow dashboard: %9$s%10$sOnce completed, update this order\'s notes with the refund transaction hash for your records.', 'qbitflow-for-woocommerce'),
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

		return true;
	}

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
				'name' => __('Invoice', 'qbitflow-for-woocommerce'),
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
			__('QBitFlow Payment', 'qbitflow-for-woocommerce'),
			array($this, 'render_order_meta_box'),
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * Per-request store of refund records keyed by order ID. Filled by
	 * sync_refund_on_order_screen() so render_order_meta_box() doesn't have to
	 * re-call the API. Not persisted anywhere — the user wants every page
	 * load to reflect the live state on QBitFlow.
	 *
	 * @var array<int,array|null>
	 */
	private static $refund_request_cache = array();

	/**
	 * Fetch the latest refund record for the order being viewed and, when the
	 * refund has been approved on-chain, auto-create the matching WC refund
	 * so the order's header status flips to "refunded".
	 *
	 * Hooked to `current_screen` so it runs once per admin page load, before
	 * meta boxes render. Pending refunds always refetch; terminal refunds
	 * (approved/refused/failed) are immutable so we snapshot them on the
	 * order and stop hitting the API.
	 */
	public function sync_refund_on_order_screen($screen)
	{
		if (! $screen instanceof WP_Screen || ! is_admin()) {
			return;
		}

		$order_screen_ids = array('shop_order', 'edit-shop_order');
		if (function_exists('wc_get_page_screen_id')) {
			$order_screen_ids[] = wc_get_page_screen_id('shop-order');
		}
		if (! in_array($screen->id, $order_screen_ids, true)) {
			return;
		}

		// Classic: ?post=123. HPOS: ?id=123 on the WC orders page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection (which order page is being viewed). No state changes triggered by this read.
		$order_id = absint($_GET['post'] ?? $_GET['id'] ?? 0);
		if (! $order_id) {
			return;
		}

		$order = wc_get_order($order_id);
		if (! $order || $order->get_payment_method() !== $this->id) {
			return;
		}

		// Already snapshotted in a terminal state? That state is immutable —
		// reuse it without touching the API.
		$snapshot = $order->get_meta(self::$meta_key_refund_snapshot);
		if ($snapshot) {
			$decoded = json_decode((string) $snapshot, true);
			if (is_array($decoded) && self::is_terminal_refund_status($decoded['status'] ?? '')) {
				self::$refund_request_cache[$order->get_id()] = $decoded;
				$this->maybe_complete_wc_refund($order, $decoded);
				return;
			}
		}

		$session_uuid = $order->get_meta(self::$meta_key_session_uuid);
		if (! $session_uuid) {
			return;
		}

		$refund = QBitFlow_API::get_refund_by_transaction($session_uuid);
		self::$refund_request_cache[$order->get_id()] = is_array($refund) ? $refund : null;

		if (is_array($refund)) {
			// Persist the snapshot once the refund reaches a terminal state so
			// subsequent admin views don't refetch it. Pending stays live.
			if (self::is_terminal_refund_status($refund['status'] ?? '')) {
				$order->update_meta_data(self::$meta_key_refund_snapshot, wp_json_encode($refund));
				$order->save();
			}
			$this->maybe_complete_wc_refund($order, $refund);
		}
	}

	/**
	 * Whether a refund status is terminal (immutable on QBitFlow's side).
	 *
	 * Schema: pending | approved | refused | failed. Everything except
	 * `pending` is final.
	 */
	private static function is_terminal_refund_status($status)
	{
		return in_array($status, array('approved', 'refused', 'failed'), true);
	}

	/**
	 * Idempotently mirror an approved-and-paid QBitFlow refund into WC.
	 *
	 * Triggers only when status === "approved" AND a `txHash` is present
	 * (the on-chain refund actually went through). We persist the refund tx
	 * hash on the order to avoid re-creating the WC refund on subsequent loads.
	 * Only full refunds are supported — `wc_create_refund()` is called for
	 * the entire order total.
	 */
	private function maybe_complete_wc_refund($order, $refund)
	{
		if (($refund['status'] ?? '') !== 'approved' || empty($refund['txHash'])) {
			return;
		}

		// Already mirrored — nothing to do.
		if ($order->get_meta(self::$meta_key_refund_tx_hash)) {
			return;
		}

		// Don't double-refund if a manual refund already covers this order.
		if ('refunded' === $order->get_status()) {
			$order->update_meta_data(self::$meta_key_refund_tx_hash, sanitize_text_field($refund['txHash']));
			$order->save();
			return;
		}

		$tx_hash = sanitize_text_field($refund['txHash']);
		$reason  = (string) ($refund['reason'] ?? '');

		$wc_refund = wc_create_refund(array(
			'order_id'       => $order->get_id(),
			'amount'         => $order->get_total(),
			'reason'         => sprintf(
				/* translators: %1$s: customer's stated reason; %2$s: refund tx hash */
				__('QBitFlow refund completed. Reason: %1$s | Tx: %2$s', 'qbitflow-for-woocommerce'),
				$reason !== '' ? $reason : '—',
				$tx_hash
			),
			'refund_payment' => false,
			'restock_items'  => false,
		));

		if (is_wp_error($wc_refund)) {
			$this->log('Auto-refund failed for order #' . $order->get_id() . ': ' . $wc_refund->get_error_message());
			return;
		}

		$order->update_meta_data(self::$meta_key_refund_tx_hash, $tx_hash);
		$order->add_order_note(sprintf(
			/* translators: %s: refund transaction hash */
			__('QBitFlow refund completed on-chain. Refund tx: %s', 'qbitflow-for-woocommerce'),
			$tx_hash
		));
		$order->save();

		$this->log('Order #' . $order->get_id() . ' marked refunded (refund tx ' . $tx_hash . ')');
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
			echo '<p>' . esc_html__('Not a QBitFlow payment.', 'qbitflow-for-woocommerce') . '</p>';
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
			echo '<tr><td><strong>' . esc_html__('Status', 'qbitflow-for-woocommerce') .
				'</strong></td>';
			echo '<td><span style="color:' . esc_attr($color) . ';font-weight:bold">' .
				esc_html(ucfirst($status)) . '</span></td></tr>';
		}

		if ($session_uuid) {
			echo '<tr><td><strong>' . esc_html__('Session', 'qbitflow-for-woocommerce') .
				'</strong></td>';
			echo '<td><code style="font-size:11px;word-break:break-all">' . esc_html(
				$session_uuid
			) . '</code></td></tr>';
		}

		if ($tx_hash) {
			echo '<tr><td><strong>' . esc_html__('Tx Hash', 'qbitflow-for-woocommerce') .
				'</strong></td>';
			echo '<td><code style="font-size:11px;word-break:break-all">' . esc_html($tx_hash) .
				'</code></td></tr>';
		}

		if ($customer_uuid) {
			echo '<tr><td><strong>' . esc_html__('Customer', 'qbitflow-for-woocommerce') .
				'</strong></td>';
			echo '<td><code style="font-size:11px;word-break:break-all">' . esc_html(
				$customer_uuid
			) . '</code></td></tr>';
		}

		if ($management_link) {
			echo '<tr><td colspan="2" style="padding-top:8px">';
			echo '<a href="' . esc_url($management_link) . '" target="_blank" class="button button-primary button-small">';
			echo esc_html__('View Invoice / Management Page', 'qbitflow-for-woocommerce');
			echo '</a></td></tr>';
		}

		echo '</table>';

		// Refund section. Prefer the snapshot collected by sync_refund_on_order_screen.
		// Fall back to a fresh API call if the screen hook didn't run (defensive).
		$refund = self::$refund_request_cache[$order->get_id()] ?? null;
		if ($refund === null && $session_uuid && ! array_key_exists($order->get_id(), self::$refund_request_cache)) {
			$refund = QBitFlow_API::get_refund_by_transaction($session_uuid);
		}

		if (is_array($refund)) {
			$this->render_refund_section($refund);
		}
	}

	/**
	 * Render the "Refund Request" block of the meta box.
	 *
	 * Field names follow the QBitFlow `RefundEntry` schema:
	 *   status: pending | approved | refused | failed
	 *   reason         — buyer's stated reason
	 *   merchantMessage — merchant's response (denial reason, internal note)
	 *   txHash         — on-chain refund hash, present when status=approved & paid
	 *   respondedAt    — timestamp the merchant acted on the request
	 */
	private function render_refund_section($refund)
	{
		$status = $refund['status'] ?? '';

		$labels = array(
			'pending'  => __('Pending', 'qbitflow-for-woocommerce'),
			'approved' => __('Approved', 'qbitflow-for-woocommerce'),
			'refused'  => __('Refused', 'qbitflow-for-woocommerce'),
			'failed'   => __('Failed', 'qbitflow-for-woocommerce'),
		);
		$colors = array(
			'pending'  => '#ffc107',
			'approved' => '#28a745',
			'refused'  => '#6c757d',
			'failed'   => '#dc3545',
		);

		echo '<hr style="margin:12px 0;">';
		echo '<p style="margin:0 0 6px;font-weight:bold">' . esc_html__('Refund Request', 'qbitflow-for-woocommerce') . '</p>';
		echo '<table class="widefat" style="border:0">';

		if ($status) {
			$color = $colors[$status] ?? '#666';
			$label = $labels[$status] ?? ucfirst($status);
			echo '<tr><td><strong>' . esc_html__('Status', 'qbitflow-for-woocommerce') . '</strong></td>';
			echo '<td><span style="color:' . esc_attr($color) . ';font-weight:bold">' . esc_html($label) . '</span></td></tr>';
		}

		if (! empty($refund['reason'])) {
			echo '<tr><td><strong>' . esc_html__('Reason', 'qbitflow-for-woocommerce') . '</strong></td>';
			echo '<td>' . esc_html($refund['reason']) . '</td></tr>';
		}

		if (! empty($refund['merchantMessage'])) {
			$merchant_label = $status === 'refused' || $status === 'failed'
				? __('Merchant response', 'qbitflow-for-woocommerce')
				: __('Merchant note', 'qbitflow-for-woocommerce');
			echo '<tr><td><strong>' . esc_html($merchant_label) . '</strong></td>';
			echo '<td>' . esc_html($refund['merchantMessage']) . '</td></tr>';
		}

		if (! empty($refund['txHash'])) {
			echo '<tr><td><strong>' . esc_html__('Refund tx hash', 'qbitflow-for-woocommerce') . '</strong></td>';
			echo '<td><code style="font-size:11px;word-break:break-all">' . esc_html($refund['txHash']) . '</code></td></tr>';
		}

		if (! empty($refund['respondedAt'])) {
			$ts = strtotime((string) $refund['respondedAt']);
			if ($ts) {
				echo '<tr><td><strong>' . esc_html__('Responded', 'qbitflow-for-woocommerce') . '</strong></td>';
				echo '<td>' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $ts)) . '</td></tr>';
			}
		}

		echo '<tr><td colspan="2" style="padding-top:8px">';
		echo '<a href="https://qbitflow.app/refunds" target="_blank" class="button button-small">';
		echo esc_html__('Manage Refunds &rarr;', 'qbitflow-for-woocommerce');
		echo '</a></td></tr>';

		echo '</table>';
	}

	public static $meta_key_session_uuid    = '_qbitflow_session_uuid';
	public static $meta_key_payment_link    = '_qbitflow_payment_link';
	public static $meta_key_last_status     = '_qbitflow_last_status';
	public static $meta_key_tx_hash         = '_qbitflow_tx_hash';
	public static $meta_key_management_link = '_qbitflow_management_link';
	public static $meta_key_customer_uuid   = '_qbitflow_customer_uuid';
	public static $meta_key_refund_tx_hash  = '_qbitflow_refund_tx_hash';
	public static $meta_key_refund_snapshot = '_qbitflow_refund_snapshot';
}
