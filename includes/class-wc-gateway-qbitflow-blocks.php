<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if (! defined('ABSPATH')) {
	exit;
}

class WC_Gateway_QBitFlow_Blocks extends AbstractPaymentMethodType
{

	/**
	 * Payment method name/id/slug.
	 */
	protected $name = 'qbitflow';

	/**
	 * Gateway instance.
	 */
	private $gateway;

	/**
	 * Initializes the payment method.
	 */
	public function initialize()
	{
		$this->settings = get_option('woocommerce_qbitflow_settings', array());
		$gateways       = WC()->payment_gateways()->payment_gateways();
		$this->gateway  = isset($gateways[$this->name]) ? $gateways[$this->name] : null;
	}

	/**
	 * Returns if this payment method should be active.
	 */
	public function is_active()
	{
		return $this->gateway && $this->gateway->is_available();
	}

	/**
	 * Returns an array of scripts/handles to enqueue for the block checkout.
	 */
	public function get_payment_method_script_handles()
	{
		$asset_path = QBITFLOW_WC_PLUGIN_DIR . 'assets/js/blocks/qbitflow-blocks.asset.php';

		// Use asset file if it exists (from wp-scripts build), otherwise set defaults
		$version      = QBITFLOW_WC_VERSION;
		$dependencies = array();

		if (file_exists($asset_path)) {
			$asset        = require $asset_path;
			$version      = $asset['version'] ?? $version;
			$dependencies = $asset['dependencies'] ?? $dependencies;
		}

		wp_register_script(
			'wc-qbitflow-blocks',
			QBITFLOW_WC_PLUGIN_URL . 'assets/js/blocks/qbitflow-blocks.js',
			$dependencies,
			$version,
			true
		);

		return array('wc-qbitflow-blocks');
	}

	/**
	 * Returns an array of data to pass to the JS payment method.
	 */
	public function get_payment_method_data()
	{
		return array(
			'title'       => $this->gateway ? $this->gateway->get_title() : __('Pay with Crypto', 'qbitflow-woocommerce'),
			'description' => $this->gateway ? $this->gateway->get_description() : '',
			'icon'        => QBITFLOW_WC_PLUGIN_URL . 'assets/img/qbitflow-icon.png',
			'supports'    => $this->gateway ? array_filter($this->gateway->supports, array(
				$this->gateway,
				'supports'
			)) : array(),
		);
	}
}
