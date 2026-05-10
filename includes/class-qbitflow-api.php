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
		if (! empty($args['address'])) {
			$body['address'] = $args['address'];
		}
		if (! empty($args['reference'])) {
			$body['reference'] = $args['reference'];
		}

		return self::request('POST', '/customer/', $body);
	}

	/**
	 * Look up a customer by email address.
	 *
	 * @return array|null Customer record (with `uuid`) when found, null on 404 or any error.
	 */
	public static function get_customer_by_email($email)
	{
		$email = trim((string) $email);
		if (empty($email) || ! is_email($email)) {
			return null;
		}

		$api_key = self::get_api_key();
		if (empty($api_key)) {
			return null;
		}

		$args = array(
			'method'  => 'GET',
			'headers' => array(
				'X-API-Key' => $api_key,
				'Accept'    => 'application/json',
			),
			'timeout' => 15,
		);

		$url      = QBITFLOW_WC_API_BASE . '/customer/email/' . rawurlencode($email);
		$response = wp_remote_get($url, $args);

		if (is_wp_error($response)) {
			return null;
		}

		$code = wp_remote_retrieve_response_code($response);
		if ($code === 404 || $code >= 400) {
			return null;
		}

		$data = json_decode(wp_remote_retrieve_body($response), true);
		return is_array($data) ? $data : null;
	}

	/**
	 * Find a customer by email; create one if it doesn't exist.
	 *
	 * Avoids prompting the buyer for details on the QBitFlow checkout when
	 * the merchant store already has them. Returns a sanitized UUID string
	 * on success, or WP_Error if both lookup and create failed.
	 *
	 * @param array $args Same shape as create_customer(): email (required),
	 *                    name, lastName, phone, address, reference.
	 */
	public static function find_or_create_customer($args)
	{
		if (empty($args['email']) || ! is_email($args['email'])) {
			return new WP_Error('qbitflow_invalid_email', 'A valid email address is required to sync the customer.');
		}

		$existing = self::get_customer_by_email($args['email']);
		if (! empty($existing['uuid'])) {
			return sanitize_text_field($existing['uuid']);
		}

		// QBitFlow requires `name` and `lastName` on customer creation. Callers
		// supply them whenever they can (WC billing fields, WP user profile),
		// but in the donation-form/guest path we may only have an email — so
		// fall back to deriving placeholders from the email local part. The
		// merchant can edit the customer in the QBitFlow dashboard later.
		$args = self::fill_required_customer_fields($args);

		$created = self::create_customer($args);
		if (is_wp_error($created)) {
			return $created;
		}

		$uuid = sanitize_text_field($created['uuid'] ?? '');
		if (empty($uuid)) {
			return new WP_Error('qbitflow_no_uuid', 'Customer create succeeded but no UUID returned.');
		}

		return $uuid;
	}

	/**
	 * Ensure `name` and `lastName` are populated before POST /customer.
	 *
	 * Splits the email local part on common separators (`.`, `_`, `-`, `+`) so
	 * `john.doe@example.com` becomes `John` / `Doe`. When the local part has
	 * no separator we set `lastName = "—"` as a visible placeholder.
	 */
	private static function fill_required_customer_fields($args)
	{
		if (! empty($args['name']) && ! empty($args['lastName'])) {
			return $args;
		}

		$local = explode('@', (string) $args['email']);
		$local = (string) ($local[0] ?? '');
		$parts = preg_split('/[._\-+]+/', $local, 2);
		$first = isset($parts[0]) ? ucfirst($parts[0]) : '';
		$last  = isset($parts[1]) && $parts[1] !== '' ? ucfirst($parts[1]) : '';

		if (empty($args['name'])) {
			$args['name'] = $first !== '' ? $first : 'Customer';
		}
		if (empty($args['lastName'])) {
			$args['lastName'] = $last !== '' ? $last : '—';
		}

		return $args;
	}

	/**
	 * Create a one-time payment session.
	 */
	public static function create_payment_session($body)
	{
		return self::request('POST', '/transaction/session-checkout/new/payment', $body);
	}

	/**
	 * Get all active (pending) refund requests for the organization.
	 */
	public static function get_pending_refunds()
	{
		return self::request('GET', '/transaction/refunds/all');
	}

	/**
	 * Get the refund entry for a specific transaction UUID.
	 * Returns null if no refund has been requested (404) or on any error.
	 */
	public static function get_refund_by_transaction($transaction_uuid)
	{
		$api_key = self::get_api_key();

		$args = array(
			'method'  => 'GET',
			'headers' => array(
				'Accept' => 'application/json',
			),
			'timeout' => 15,
		);

		// Endpoint is public but including the key doesn't hurt and keeps the request consistent
		if ($api_key) {
			$args['headers']['X-API-Key'] = $api_key;
		}

		$url      = QBITFLOW_WC_API_BASE . '/transaction/refunds/by-transaction/' . rawurlencode($transaction_uuid);
		$response = wp_remote_get($url, $args);

		if (is_wp_error($response)) {
			return null;
		}

		$code = wp_remote_retrieve_response_code($response);

		if ($code === 404) {
			return null; // No refund request exists for this transaction
		}

		if ($code >= 400) {
			return null;
		}

		return json_decode(wp_remote_retrieve_body($response), true);
	}

	/**
	 * Verify webhook signature via QBitFlow API.
	 * Secrets are managed server-side by QBitFlow; we delegate validation rather than storing them locally.
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

		return ! empty($response['message']) && str_contains($response['message'], 'verified');
	}
}
