<?php
if (! defined('ABSPATH')) {
	exit;
}

class QBitFlow_API
{

	/**
	 * Get the API key from gateway settings.
	 */
	private static function get_api_key()
	{
		$settings = get_option('woocommerce_qbitflow_settings', array());
		return $settings['api_key'] ?? '';
	}

	/**
	 * Make an API request to QBitFlow.
	 */
	private static function request($method, $endpoint, $body = null)
	{
		$api_key = self::get_api_key();
		if (empty($api_key)) {
			return new WP_Error('qbitflow_no_api_key', 'QBitFlow API key not configured.');
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'X-API-Key'    => $api_key,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'timeout' => 30,
		);


		if ($body) {
			$args['body'] = wp_json_encode($body);
		}

		$response = wp_remote_request(QBITFLOW_WC_API_BASE . $endpoint, $args);

		if (is_wp_error($response)) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);
		$data = json_decode(wp_remote_retrieve_body($response), true);

		if ($code >= 400) {
			return new WP_Error(
				'qbitflow_api_error',
				$data['error'] ?? "API error (HTTP {$code})",
				array('status' => $code, 'response' => $data)
			);
		}

		return $data;
	}

	/**
	 * Create a customer on QBitFlow.
	 */
	public static function create_customer($args)
	{
		$body = array(
			'email' => $args['email'],
		);

		if (! empty($args['name'])) {
			$body['name'] = $args['name'];
		}
		if (! empty($args['lastName'])) {
			$body['lastName'] = $args['lastName'];
		}
		if (! empty($args['phone'])) {
			$body['phoneNumber'] = $args['phone'];
		}
		if (! empty($args['reference'])) {
			$body['reference'] = $args['reference'];
		}

		return self::request('POST', '/customer/', $body);
	}

	/**
	 * Create a payment session.
	 */
	public static function create_payment_session($body)
	{
		return self::request('POST', '/transaction/session-checkout/', $body);
	}


	/**
	 * Verify webhook signature by sending data back to QBitFlow API for validation.
	 * Webhook secrets are managed by QBitFlow and not stored in the plugin, so we rely on API verification for security.
	 */
	public static function verify_webhook_signature($payload, $signature, $timestamp)
	{
		$api_key = self::get_api_key();
		if (empty($api_key)) {
			return false;
		}

		$body = array(
			'payload'   => $payload,
			'receivedSignature' => $signature,
			'receivedTimestamp' => $timestamp,
		);

		$response = self::request('POST', '/webhooks/verify', $body);

		if (is_wp_error($response)) {
			return false;
		}

		$result = ! empty($response['message']) && str_contains(
			$response['message'],
			'verified'
		);
		return $result;
	}
}
