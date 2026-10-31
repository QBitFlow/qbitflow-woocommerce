<?php

declare(strict_types=1);

namespace QBitFlowWC\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use QBitFlowWC\Checkout\AcceptedCoins;
use QBitFlowWC\Gateway;

defined('ABSPATH') || exit;

/**
 * The gateway in the block-based checkout.
 */
final class PaymentMethod extends AbstractPaymentMethodType
{
	/** @var string */
	protected $name = Gateway::ID;

	private ?Gateway $gateway = null;

	/** Hooked on `woocommerce_blocks_loaded`. */
	public static function register(): void
	{
		add_action('woocommerce_blocks_payment_method_type_registration', static function (PaymentMethodRegistry $registry): void {
			$registry->register(new self());
		});
	}

	public function initialize(): void
	{
		$this->settings = (array) get_option('woocommerce_qbitflow_settings', []);
		$gateways = WC()->payment_gateways()->payment_gateways();
		$this->gateway = ($gateways[Gateway::ID] ?? null) instanceof Gateway ? $gateways[Gateway::ID] : null;
	}

	public function is_active(): bool
	{
		return $this->gateway !== null && $this->gateway->is_available();
	}

	/** @return list<string> */
	public function get_payment_method_script_handles(): array
	{
		$asset_path = QBITFLOW_WC_PLUGIN_DIR . 'assets/js/blocks/qbitflow-blocks.asset.php';
		$version = QBITFLOW_WC_VERSION;
		$dependencies = [];
		if (file_exists($asset_path)) {
			$asset = require $asset_path;
			$version = $asset['version'] ?? $version;
			$dependencies = $asset['dependencies'] ?? $dependencies;
		}

		wp_register_script(
			'wc-qbitflow-blocks',
			QBITFLOW_WC_PLUGIN_URL . 'assets/js/blocks/qbitflow-blocks.js',
			$dependencies,
			$version,
			true
		);

		return ['wc-qbitflow-blocks'];
	}

	/** @return array<string, mixed> */
	public function get_payment_method_data(): array
	{
		return [
			'title' => $this->gateway ? $this->gateway->get_title() : __('Pay with Crypto', 'qbitflow-for-woocommerce'),
			'description' => $this->gateway ? $this->gateway->get_description() : '',
			'acceptedCoins' => AcceptedCoins::label(),
			'icon' => QBITFLOW_WC_PLUGIN_URL . 'assets/img/qbitflow-icon.png',
			'supports' => $this->gateway ? array_values(array_filter($this->gateway->supports, [$this->gateway, 'supports'])) : [],
		];
	}
}
