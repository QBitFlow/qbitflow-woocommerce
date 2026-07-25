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
		$this->description = $this->get_option('description');
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

		// Refund handling lives on QBitFlow. On every order edit screen we pull
		// the latest refund record (read-only, cached) so the meta box can show
		// its status and surface a "sync" action when QBitFlow has settled a
		// refund on-chain that WooCommerce hasn't recorded yet. No state change
		// happens on this GET — see handle_apply_refund() for the mutation path.
		add_action('current_screen', array($this, 'sync_refund_on_order_screen'));

		// Order-screen notices: the "sync this refund" prompt (rendered outside
		// the order form so its POST button isn't swallowed by a nested form) and
		// the one-time success notice after a sync. The actual state change runs
		// in handle_apply_refund(), registered at plugin init on admin_post — not
		// here, because WooCommerce doesn't build gateways on admin-post.php.
		add_action('admin_notices', array($this, 'maybe_show_refund_sync_prompt'));
		add_action('admin_notices', array($this, 'maybe_show_refund_synced_notice'));
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
			'description'    => array(
				'title'       => __('Description', 'qbitflow-for-woocommerce'),
				'type'        => 'textarea',
				'description' => __('The payment method description shown to customers at checkout.', 'qbitflow-for-woocommerce'),
				'default'     => __('Pay with crypto — funds go straight to your wallet, non-custodial and secure.', 'qbitflow-for-woocommerce'),
				'desc_tip'    => true,
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
	 * Derive the environment from the configured API key.
	 *
	 * Keys are formatted `sk_<id>_<live|test>_<secret>`, so the mode is the
	 * third underscore-separated segment. Returns 'live', 'test', or '' when
	 * no key is set or the format isn't recognised.
	 */
	private function get_api_mode()
	{
		$key = (string) $this->get_option('api_key');
		if ('' === $key) {
			return '';
		}
		$parts = explode('_', $key);
		$mode  = $parts[2] ?? '';
		return in_array($mode, array('live', 'test'), true) ? $mode : '';
	}

	/**
	 * Render the settings screen with a test/live mode badge and a webhook
	 * registration reminder above the standard WooCommerce settings table.
	 */
	public function admin_options()
	{
		$mode        = $this->get_api_mode();
		$webhook_url = rest_url('qbitflow-wc/webhook');

		echo '<h2>' . esc_html__('QBitFlow', 'qbitflow-for-woocommerce') . '</h2>';

		// Mode badge — tells the merchant at a glance which environment their
		// current API key targets.
		if ('test' === $mode) {
			echo '<p style="font-size:13px">';
			echo '<span style="display:inline-block;padding:3px 10px;border-radius:3px;background:#f0b849;color:#1d2327;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px;vertical-align:middle">' .
				esc_html__('Test mode', 'qbitflow-for-woocommerce') . '</span> ';
			echo esc_html__('Your API key is a test key. Payments run on blockchain testnets using faucet funds, so you can try the entire payment flow without spending any real cryptocurrency. Swap in a live key when you are ready to accept real payments.', 'qbitflow-for-woocommerce');
			echo '</p>';
		} elseif ('live' === $mode) {
			echo '<p style="font-size:13px">';
			echo '<span style="display:inline-block;padding:3px 10px;border-radius:3px;background:#28a745;color:#fff;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px;vertical-align:middle">' .
				esc_html__('Live mode', 'qbitflow-for-woocommerce') . '</span> ';
			echo esc_html__('Your API key is a live key — real cryptocurrency payments are processed.', 'qbitflow-for-woocommerce');
			echo '</p>';
		} elseif ('' !== (string) $this->get_option('api_key')) {
			echo '<p style="font-size:13px">';
			echo '<span style="display:inline-block;padding:3px 10px;border-radius:3px;background:#dc3545;color:#fff;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px;vertical-align:middle">' .
				esc_html__('Unknown key', 'qbitflow-for-woocommerce') . '</span> ';
			echo esc_html__('The API key format is not recognised. Copy the key again from your QBitFlow dashboard.', 'qbitflow-for-woocommerce');
			echo '</p>';
		} else {
			echo '<p style="font-size:13px">';
			echo '<span style="display:inline-block;padding:3px 10px;border-radius:3px;background:#6c757d;color:#fff;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px;vertical-align:middle">' .
				esc_html__('No API key', 'qbitflow-for-woocommerce') . '</span> ';
			echo esc_html__('Enter your QBitFlow API key below to enable payments.', 'qbitflow-for-woocommerce');
			echo '</p>';
		}

		// Webhook registration reminder. The dashboard has separate Test and
		// Live webhook sections, so point the merchant to the one matching their
		// key (defaulting to Test when the key isn't a live key).
		$section = 'live' === $mode
			? __('Live', 'qbitflow-for-woocommerce')
			: __('Test', 'qbitflow-for-woocommerce');

		echo '<div class="notice notice-info inline" style="margin:12px 0;padding:10px 12px">';
		echo '<p style="margin:0 0 6px"><strong>' . esc_html__('Don\'t forget to register your webhook', 'qbitflow-for-woocommerce') . '</strong></p>';
		echo '<p style="margin:0 0 6px">';
		printf(
			/* translators: %s: the QBitFlow dashboard webhook section (Test or Live) matching the API key */
			esc_html__('Add the URL below in your QBitFlow dashboard under Settings → Webhooks → %s section, so payment confirmations reach your store:', 'qbitflow-for-woocommerce'),
			esc_html($section)
		);
		echo '</p>';
		echo '<p style="margin:0"><code style="user-select:all;word-break:break-all">' . esc_html($webhook_url) . '</code></p>';
		echo '</div>';

		echo '<table class="form-table">';
		$this->generate_settings_html();
		echo '</table>';
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
	 * re-fetch. The underlying lookup is transient-cached (see get_refund_cached).
	 *
	 * @var array<int,array|null>
	 */
	private static $refund_request_cache = array();

	/**
	 * Per-request store of "does QBitFlow report a settled refund that WC hasn't
	 * recorded yet?" keyed by order ID. Filled by sync_refund_on_order_screen()
	 * so render_refund_section() can surface the sync action without re-deriving.
	 *
	 * @var array<int,bool>
	 */
	private static $refund_needs_sync = array();

	/**
	 * ID of the order currently being viewed on an admin order screen, captured
	 * by sync_refund_on_order_screen() so admin_notices callbacks (which run
	 * later in the page render) know which order to act on. 0 when not on one.
	 *
	 * @var int
	 */
	private static $screen_order_id = 0;

	/**
	 * On the admin order screen, load the latest refund record for display.
	 *
	 * READ-ONLY. Hooked to `current_screen` so it runs once per admin page load,
	 * before meta boxes render. It fetches (via a short-lived transient cache)
	 * the current refund state so the meta box can show it, and computes whether
	 * QBitFlow has settled a refund on-chain that WooCommerce hasn't mirrored
	 * yet. It never writes order meta and never creates a WC refund — that only
	 * happens through the explicit, nonce-protected handle_apply_refund().
	 */
	public function sync_refund_on_order_screen($screen)
	{
		if (! $screen instanceof WP_Screen || ! is_admin()) {
			return;
		}

		// Only merchants who can edit orders should trigger the refund lookup.
		if (! current_user_can('edit_shop_orders')) {
			return;
		}

		$order_screen_ids = array('shop_order', 'edit-shop_order');
		if (function_exists('wc_get_page_screen_id')) {
			$order_screen_ids[] = wc_get_page_screen_id('shop-order');
		}
		if (! in_array($screen->id, $order_screen_ids, true)) {
			return;
		}

		// Classic: ?post=123. HPOS: ?id=123 on the WC orders page. This only
		// identifies which order screen WordPress core is already rendering; it
		// triggers no state change, so no nonce applies.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection (which order page is being viewed). No state changes triggered by this read.
		$order_id = absint($_GET['post'] ?? $_GET['id'] ?? 0);
		if (! $order_id) {
			return;
		}

		$order = wc_get_order($order_id);
		if (! $order || $order->get_payment_method() !== $this->id) {
			return;
		}

		$session_uuid = $order->get_meta(self::$meta_key_session_uuid);
		if (! $session_uuid) {
			return;
		}

		self::$screen_order_id = $order->get_id();

		$refund = self::get_refund_cached($session_uuid);
		self::$refund_request_cache[$order->get_id()] = is_array($refund) ? $refund : null;
		self::$refund_needs_sync[$order->get_id()]    = is_array($refund)
			? $this->refund_needs_sync($order, $refund)
			: false;
	}

	/**
	 * Fetch a refund record for a transaction, cached in a short-lived transient
	 * so repeated admin screen refreshes don't hammer the API.
	 *
	 * Caches negative lookups too (as a sentinel) to avoid refetch storms while
	 * a refund hasn't been requested. TTL is deliberately short so a freshly
	 * settled refund surfaces within a minute.
	 *
	 * @return array|null The refund record, or null when none exists.
	 */
	private static function get_refund_cached($session_uuid)
	{
		$key    = 'qbitflow_refund_' . md5((string) $session_uuid);
		$cached = get_transient($key);

		if ($cached !== false) {
			return is_array($cached) ? $cached : null;
		}

		$refund = QBitFlow_API::get_refund_by_transaction($session_uuid);
		$value  = is_array($refund) ? $refund : 'none';
		set_transient($key, $value, MINUTE_IN_SECONDS);

		return is_array($refund) ? $refund : null;
	}

	/**
	 * Whether QBitFlow reports a completed refund (approved + on-chain txHash)
	 * that WooCommerce hasn't mirrored yet. Pure read — no side effects.
	 */
	private function refund_needs_sync($order, $refund)
	{
		if (($refund['status'] ?? '') !== 'approved' || empty($refund['txHash'])) {
			return false;
		}
		if ($order->get_meta(self::$meta_key_refund_tx_hash)) {
			return false;
		}
		return 'refunded' !== $order->get_status();
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
	 * Explicit handler that mirrors an approved QBitFlow refund into WooCommerce.
	 *
	 * This is the ONLY code path that writes order meta / creates a WC refund for
	 * a QBitFlow refund. It is reachable solely via a nonce-protected POST from
	 * the order meta box, is gated on `edit_shop_orders`, and re-fetches the
	 * refund fresh from the API (posted data is never trusted). It then defers
	 * to maybe_complete_wc_refund() for the idempotent write.
	 */
	public function handle_apply_refund()
	{
		$order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;

		if (! current_user_can('edit_shop_orders')) {
			wp_die(esc_html__('You do not have permission to sync QBitFlow refunds.', 'qbitflow-for-woocommerce'));
		}

		check_admin_referer('qbitflow_apply_refund_' . $order_id);

		$order = $order_id ? wc_get_order($order_id) : false;
		if (! $order || $order->get_payment_method() !== $this->id) {
			wp_die(esc_html__('Invalid QBitFlow order.', 'qbitflow-for-woocommerce'));
		}

		$session_uuid = $order->get_meta(self::$meta_key_session_uuid);
		$refund       = $session_uuid ? QBitFlow_API::get_refund_by_transaction($session_uuid) : null;

		if (is_array($refund)) {
			// Persist the snapshot once the refund is terminal so future admin
			// views are cheap. Terminal refunds are immutable on QBitFlow's side.
			if (self::is_terminal_refund_status($refund['status'] ?? '')) {
				$order->update_meta_data(self::$meta_key_refund_snapshot, wp_json_encode($refund));
				$order->save();
			}
			$this->maybe_complete_wc_refund($order, $refund);
		}

		// Bust the cached lookup so the redirected screen shows the new state.
		if ($session_uuid) {
			delete_transient('qbitflow_refund_' . md5((string) $session_uuid));
		}

		$redirect = wp_get_referer();
		if (! $redirect) {
			$redirect = admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id);
		}
		wp_safe_redirect(add_query_arg('qbitflow_refund_synced', '1', $redirect));
		exit;
	}

	/**
	 * Show a one-time success notice after handle_apply_refund() redirects back.
	 */
	public function maybe_show_refund_synced_notice()
	{
		if (! current_user_can('edit_shop_orders')) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag from our own post/redirect/get; renders a success notice only, no state change.
		if (empty($_GET['qbitflow_refund_synced'])) {
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>' .
			esc_html__('QBitFlow refund synced to WooCommerce.', 'qbitflow-for-woocommerce') .
			'</p></div>';
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

		// Refund section. Prefer the record collected by sync_refund_on_order_screen.
		// Fall back to a cached lookup if the screen hook didn't run (defensive).
		$refund = self::$refund_request_cache[$order->get_id()] ?? null;
		if ($refund === null && $session_uuid && ! array_key_exists($order->get_id(), self::$refund_request_cache)) {
			$refund = self::get_refund_cached($session_uuid);
		}

		if (is_array($refund)) {
			$needs_sync = self::$refund_needs_sync[$order->get_id()]
				?? $this->refund_needs_sync($order, $refund);
			$this->render_refund_section($refund, $order, $needs_sync);
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
	 *
	 * When $needs_sync is true, QBitFlow reports a settled refund that WC hasn't
	 * recorded yet; we render a plain-language notice plus a nonce-protected
	 * "Sync to WooCommerce" button that posts to handle_apply_refund().
	 */
	private function render_refund_section($refund, $order = null, $needs_sync = false)
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

		// When QBitFlow has settled a refund WooCommerce hasn't recorded yet, the
		// actionable "Sync refund to WooCommerce" button is rendered as an admin
		// notice (see maybe_show_refund_sync_prompt) rather than here — a POST form
		// inside the order meta box would be nested in the order's own <form> and
		// silently dropped by the browser.
		if ($needs_sync && $order) {
			echo '<p style="margin:10px 0 0;color:#8a6d3b">' . esc_html__(
				'QBitFlow reports a completed refund not yet recorded here — use the "Sync refund to WooCommerce" notice at the top of this screen.',
				'qbitflow-for-woocommerce'
			) . '</p>';
		}
	}

	/**
	 * Admin notice offering to sync a settled QBitFlow refund into WooCommerce.
	 *
	 * Rendered on the order screen (outside the order form) so its nonce-protected
	 * POST reaches admin-post.php intact. Shown only when sync_refund_on_order_screen()
	 * flagged the current order as needing a sync.
	 */
	public function maybe_show_refund_sync_prompt()
	{
		if (! current_user_can('edit_shop_orders')) {
			return;
		}

		$order_id = self::$screen_order_id;
		if (! $order_id || empty(self::$refund_needs_sync[$order_id])) {
			return;
		}

		echo '<div class="notice notice-warning">';
		echo '<p style="margin-bottom:6px"><strong>' . esc_html__('QBitFlow refund ready to sync', 'qbitflow-for-woocommerce') . '</strong><br>';
		echo esc_html__('QBitFlow reports a completed refund that is not yet recorded on this order. Sync it to mark the order refunded and record the refund transaction.', 'qbitflow-for-woocommerce') . '</p>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:10px">';
		echo '<input type="hidden" name="action" value="qbitflow_apply_refund">';
		echo '<input type="hidden" name="order_id" value="' . esc_attr($order_id) . '">';
		wp_nonce_field('qbitflow_apply_refund_' . $order_id);
		echo '<button type="submit" class="button button-primary">' .
			esc_html__('Sync refund to WooCommerce', 'qbitflow-for-woocommerce') .
			'</button>';
		echo '</form>';
		echo '</div>';
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
