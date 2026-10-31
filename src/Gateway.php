<?php

declare(strict_types=1);

namespace QBitFlowWC;

use QBitFlowWC\Admin\AccountingExport;
use QBitFlowWC\Admin\StatusPanel;
use QBitFlowWC\Checkout\AcceptedCoins;
use QBitFlowWC\Checkout\CheckoutException;
use QBitFlowWC\Checkout\SessionManager;
use WC_Payment_Gateway;

defined('ABSPATH') || exit;

/**
 * The QBitFlow payment gateway (id `qbitflow`): the customer pays on QBitFlow's hosted
 * checkout; webhooks, the return page and a background job confirm the order.
 *
 * Refunds are sent from the QBitFlow dashboard (the merchant's wallet signs them) and recorded
 * here automatically, so the gateway doesn't offer WooCommerce's "Refund via QBitFlow" button.
 */
class Gateway extends WC_Payment_Gateway
{
	public const ID = 'qbitflow';

	public function __construct()
	{
		$this->id = self::ID;
		$this->icon = QBITFLOW_WC_PLUGIN_URL . 'assets/img/qbitflow-icon.png';
		$this->has_fields = false;
		$this->method_title = __('QBitFlow', 'qbitflow-for-woocommerce');
		$this->method_description = __('Accept cryptocurrency payments via QBitFlow. Non-custodial — funds go directly to your wallet via smart contracts.', 'qbitflow-for-woocommerce');
		$this->supports = ['products'];

		$this->init_form_fields();
		$this->init_settings();

		$this->title = 'QBitFlow';
		$this->description = (string) $this->get_option('description');
		if (Settings::processing_fee() === true) {
			$this->description = trim($this->description . ' ' . __('A small processing fee is added on the QBitFlow checkout.', 'qbitflow-for-woocommerce'));
		}
		$this->enabled = (string) $this->get_option('enabled');

		add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
	}

	/** The logo at a fixed size: block themes don't load the stylesheet that limits a gateway icon, and the file is 581 px wide. */
	public function get_icon(): string
	{
		$icon = sprintf(
			'<img src="%s" alt="%s" width="24" height="26" style="width:auto;height:1.6em;max-height:1.6em;vertical-align:middle;margin-left:.5em" />',
			esc_url($this->icon),
			esc_attr($this->get_title())
		);

		return (string) apply_filters('woocommerce_gateway_icon', $icon, $this->id); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter.
	}

	public static function settings_url(): string
	{
		return admin_url('admin.php?page=wc-settings&tab=checkout&section=' . self::ID);
	}

	public function init_form_fields(): void
	{
		$this->form_fields = [
			'enabled' => [
				'title' => __('Enable/Disable', 'qbitflow-for-woocommerce'),
				'type' => 'checkbox',
				'label' => __('Enable QBitFlow Crypto Payments', 'qbitflow-for-woocommerce'),
				'default' => 'no',
			],
			'description' => [
				'title' => __('Description', 'qbitflow-for-woocommerce'),
				'type' => 'textarea',
				'description' => __('The payment method description shown to customers at checkout.', 'qbitflow-for-woocommerce'),
				'default' => __('Pay with crypto — funds go straight to your wallet, non-custodial and secure.', 'qbitflow-for-woocommerce'),
				'desc_tip' => true,
			],
			'api_key' => [
				'title' => __('API Key', 'qbitflow-for-woocommerce'),
				'type' => 'password',
				'description' => __('From the QBitFlow dashboard (Settings → API keys). A test key runs on test networks; a live key takes real payments.', 'qbitflow-for-woocommerce'),
				'default' => '',
				'desc_tip' => true,
			],
			'itemize' => [
				'title' => __('Itemized checkout', 'qbitflow-for-woocommerce'),
				'type' => 'checkbox',
				'label' => __('Show taxes, shipping and fees as separate lines on the QBitFlow checkout', 'qbitflow-for-woocommerce'),
				'description' => __('Off: the customer sees one amount, the order total.', 'qbitflow-for-woocommerce'),
				'default' => 'yes',
			],
			'show_coins' => [
				'title' => __('Accepted coins', 'qbitflow-for-woocommerce'),
				'type' => 'checkbox',
				'label' => __('Show the coins your wallets accept at checkout', 'qbitflow-for-woocommerce'),
				'default' => 'yes',
			],
			'processing_fee' => $this->processing_fee_field(),
			'webhook_secret' => [
				'title' => __('Webhook signing secret', 'qbitflow-for-woocommerce'),
				'type' => 'password',
				'description' => __('Leave empty and use "Connect webhook" above. Only if your API key cannot register webhooks: create the endpoint in the QBitFlow dashboard with the URL shown above and paste its whsec_… secret here.', 'qbitflow-for-woocommerce'),
				'default' => '',
			],
			'debug' => [
				'title' => __('Debug Log', 'qbitflow-for-woocommerce'),
				'type' => 'checkbox',
				'label' => __('Enable logging', 'qbitflow-for-woocommerce'),
				'default' => 'no',
				'description' => __('Logs QBitFlow events under WooCommerce → Status → Logs (source "qbitflow"). Errors are always logged.', 'qbitflow-for-woocommerce'),
			],
		];
	}

	/** Who pays QBitFlow's processing fee: a choice, or for a member's key the organization's (read only). */
	private function processing_fee_field(): array
	{
		$field = [
			'title' => __('Processing fee', 'qbitflow-for-woocommerce'),
			'type' => 'select',
			'default' => 'account',
		];
		if (StatusPanel::member() === true) {
			return $field + [
				'options' => ['account' => __('Set by your organization', 'qbitflow-for-woocommerce')],
				'disabled' => true,
				'description' => __('Your organization decides who pays the fee for its members.', 'qbitflow-for-woocommerce'),
			];
		}

		return $field + [
			'options' => [
				'account' => __('Use my QBitFlow setting', 'qbitflow-for-woocommerce'),
				'customer' => __('Customer pays', 'qbitflow-for-woocommerce'),
				'store' => __('Store pays', 'qbitflow-for-woocommerce'),
			],
			'description' => __('"Customer pays" adds the fee as a last line on the QBitFlow checkout, so you receive the full order total: the customer then pays slightly more than the WooCommerce order total, and the fee is recorded on the order. "Use my QBitFlow setting" follows the choice made in your QBitFlow dashboard; only "Customer pays" also mentions the fee on your checkout page.', 'qbitflow-for-woocommerce'),
		];
	}

	/** A member's field is read only: keep what was saved. Otherwise one of the three choices. */
	public function validate_processing_fee_field(string $key, mixed $value): string
	{
		if (StatusPanel::member() === true) {
			return Settings::fee_choice();
		}
		if ($value === null) {
			return Settings::fee_choice(); // Not posted (stale form): keep what was saved.
		}
		$value = is_string($value) ? wp_unslash($value) : '';

		return in_array($value, ['account', 'customer', 'store'], true) ? $value : 'account';
	}

	/** A member's key shows the field's single (organization) option as selected. */
	public function get_option($key, $empty_value = null)
	{
		if ('processing_fee' === $key && StatusPanel::member() === true) {
			return 'account';
		}

		return parent::get_option($key, $empty_value);
	}

	/**
	 * Available with a key, a currency QBitFlow can price (USD, or a rate from
	 * `qbitflow_wc_usd_rate`), and unless QBitFlow recently said the store's wallets accept
	 * nothing. Never calls the API.
	 */
	public function is_available(): bool
	{
		if (! parent::is_available() || Settings::key_format() !== 'ok') {
			return false;
		}
		if (Currency::usd_rate() === null) {
			return false;
		}

		return ! StatusPanel::known_not_ready();
	}

	/** The description and, from the cache only, the accepted coins (classic checkout and order-pay page). */
	public function payment_fields(): void
	{
		$description = $this->get_description();
		if ($description !== '') {
			echo wp_kses_post(wpautop(wptexturize($description)));
		}
		$coins = AcceptedCoins::label();
		if ($coins !== '') {
			echo '<p class="qbitflow-accepted-coins">' . esc_html($coins) . '</p>';
		}
	}

	public function process_admin_options(): bool
	{
		$before = Settings::api_key();
		$saved = parent::process_admin_options();
		if (Settings::api_key() !== $before) {
			StatusPanel::forget();
			AcceptedCoins::forget();
			delete_transient(StatusPanel::NOT_READY_TRANSIENT);
			delete_transient(StatusPanel::CHECKOUTS_OFF_TRANSIENT);
			delete_transient(SessionManager::REDIRECTS_OFF_TRANSIENT);
		}

		return $saved;
	}

	public function validate_api_key_field(string $key, mixed $value): string
	{
		return trim(wp_unslash((string) $value));
	}

	public function validate_webhook_secret_field(string $key, mixed $value): string
	{
		return trim(wp_unslash((string) $value));
	}

	public function admin_options(): void
	{
		echo '<h2>' . esc_html__('QBitFlow', 'qbitflow-for-woocommerce') . '</h2>';
		self::mode_badge();
		if (Settings::api_key() !== '') {
			StatusPanel::render();
			echo '<p>' . esc_html__('Refund rules, receipt emails and branding are settings of your QBitFlow account, shared by every store using it: change them in the QBitFlow dashboard.', 'qbitflow-for-woocommerce') . '</p>';
			AccountingExport::render();
		}
		echo '<table class="form-table">';
		$this->generate_settings_html();
		echo '</table>';
	}

	/**
	 * @param int $order_id
	 * @return array{result: string, redirect?: string}
	 */
	public function process_payment($order_id): array
	{
		$order = wc_get_order($order_id);
		if (! $order instanceof \WC_Order) {
			wc_add_notice(__('Order not found.', 'qbitflow-for-woocommerce'), 'error');

			return ['result' => 'failure'];
		}

		try {
			return ['result' => 'success', 'redirect' => SessionManager::start($order)];
		} catch (CheckoutException $e) {
			wc_add_notice($e->getMessage(), 'error');

			return ['result' => 'failure'];
		}
	}

	private static function mode_badge(): void
	{
		$badges = [
			'test' => ['#f0b849', '#1d2327', __('Test mode', 'qbitflow-for-woocommerce'), __('Payments run on test networks with faucet funds: try the whole flow without real cryptocurrency (test checkouts are capped at $5). Swap in a live key to accept real payments.', 'qbitflow-for-woocommerce')],
			'live' => ['#28a745', '#fff', __('Live mode', 'qbitflow-for-woocommerce'), __('Real cryptocurrency payments are processed.', 'qbitflow-for-woocommerce')],
			'unknown' => ['#dc3545', '#fff', __('Unknown key', 'qbitflow-for-woocommerce'), __('The API key format is not recognised. Copy the key again from your QBitFlow dashboard.', 'qbitflow-for-woocommerce')],
			'none' => ['#6c757d', '#fff', __('No API key', 'qbitflow-for-woocommerce'), __('Enter your QBitFlow API key below to enable payments.', 'qbitflow-for-woocommerce')],
		];
		$mode = Settings::mode();
		[$background, $color, $label, $text] = $badges[$mode !== '' ? $mode : (Settings::api_key() !== '' ? 'unknown' : 'none')];
		printf(
			'<p style="font-size:13px"><span style="display:inline-block;padding:3px 10px;border-radius:3px;background:%s;color:%s;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px;vertical-align:middle">%s</span> %s</p>',
			esc_attr($background),
			esc_attr($color),
			esc_html($label),
			esc_html($text)
		);
	}
}

class_alias(Gateway::class, 'WC_Gateway_QBitFlow');
