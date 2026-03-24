<?php
if (! defined('ABSPATH')) {
	exit;
}

class QBitFlow_Customer_Sync
{

	private static string $meta_key_uuid = '_qbitflow_customer_uuid';

	/**
	 * Register hooks.
	 */
	public static function init()
	{
		// Sync when a new user registers
		add_action('user_register', array(__CLASS__, 'on_user_register'), 20);

		// Sync when profile is updated (catches name/phone changes)
		add_action('profile_update', array(__CLASS__, 'on_profile_update'), 20, 2);

		// Sync at checkout for guest users or users not yet synced
		add_action('woocommerce_checkout_order_created', array(
			__CLASS__,
			'on_order_created'
		), 20);
	}

	/**
	 * Sync a registered user to QBitFlow.
	 */
	private static function sync_customer($user_id, $customer_data)
	{
		// Skip if already synced
		if (get_user_meta($user_id, self::$meta_key_uuid, true)) {
			return;
		}

		if (empty($customer_data['email'])) {
			return;
		}

		$customer_data['reference'] = "wp_user_{$user_id}";

		if (! empty($customer_data['phone'])) {
			$customer_data['phone'] = $customer_data['phone'];
		}

		$result = QBitFlow_API::create_customer($customer_data);

		if (is_wp_error($result)) {
			self::log('Failed to sync user #' . $user_id . ': ' . $result->get_error_message());
			return;
		}

		$uuid = sanitize_text_field($result['uuid'] ?? '');
		if (empty($uuid)) {
			self::log('Customer created for user #' . $user_id . ' but no UUID returned.');
			return;
		}

		update_user_meta($user_id, self::$meta_key_uuid, $uuid);
		self::log("User #{$user_id} synced → {$uuid}");
	}


	/**
	 * Sync on user registration.
	 */
	public static function on_user_register($user_id)
	{
		$user = get_userdata($user_id);
		if (! $user || empty($user->user_email)) {
			return;
		}

		self::sync_customer($user_id, array(
			'email'     => $user->user_email,
			'name'      => $user->first_name,
			'lastName' => $user->last_name,
		));
	}

	/**
	 * Sync on profile update (only if not already synced).
	 */
	public static function on_profile_update($user_id, $old_user_data)
	{
		// Only sync if not already synced
		if (get_user_meta($user_id, self::$meta_key_uuid, true)) {
			return;
		}

		$user = get_userdata($user_id);
		if (! $user || empty($user->user_email)) {
			return;
		}

		self::sync_customer($user_id, array(
			'email'     => $user->user_email,
			'name'      => $user->first_name,
			'lastName' => $user->last_name,
		));
	}

	/**
	 * Sync at checkout — handles guest users and unsynced registered users.
	 */
	public static function on_order_created($order)
	{
		$email = $order->get_billing_email();
		if (empty($email)) {
			return;
		}

		$user_id = $order->get_customer_id(); // 0 for guests

		// For registered users, check if already synced
		if ($user_id && get_user_meta($user_id, self::$meta_key_uuid, true)) {
			return;
		}

		// For guests, check if we already synced this email (stored on previous orders)
		if (! $user_id) {
			$existing_uuid = self::find_guest_customer_uuid($email);
			if ($existing_uuid) {
				$order->update_meta_data(self::$meta_key_uuid, $existing_uuid);
				$order->save();
				return;
			}
		}

		$customer_data = array(
			'email'     => $email,
			'name'      => $order->get_billing_first_name(),
			'lastName' => $order->get_billing_last_name(),
			'phone'     => $order->get_billing_phone(),
			'reference' => $user_id ? "wp_user_{$user_id}" : "guest_{$email}",
		);

		self::log("Creating customer for order #{$order->get_id()} (email: {$email}) $customer_data[reference]");

		$result = QBitFlow_API::create_customer($customer_data);

		if (is_wp_error($result)) {
			self::log('Failed to create customer: ' . $result->get_error_message());
			return;
		}

		$uuid = sanitize_text_field($result['uuid'] ?? '');
		if (empty($uuid)) {
			self::log('Customer created but no UUID returned.');
			return;
		}

		// Store on user if registered
		if ($user_id) {
			update_user_meta($user_id, self::$meta_key_uuid, $uuid);
		}

		// Always store on the order
		$order->update_meta_data(self::$meta_key_uuid, $uuid);
		$order->save();

		self::log("Customer synced: {$email} → {$uuid}");
	}

	/**
	 * Get the QBitFlow customer UUID for a given user/order.
	 */
	public static function get_customer_uuid($order)
	{
		// Check order meta first (covers guests)
		$uuid = $order->get_meta(self::$meta_key_uuid);
		if ($uuid) {
			return $uuid;
		}

		// Check user meta for registered users
		$user_id = $order->get_customer_id();
		if ($user_id) {
			return get_user_meta($user_id, self::$meta_key_uuid, true);
		}

		return '';
	}

	/**
	 * Find a previously synced guest customer UUID by email.
	 */
	private static function find_guest_customer_uuid($email)
	{
		$orders = wc_get_orders(array(
			'billing_email' => $email,
			'meta_key'      => self::$meta_key_uuid,
			'limit'         => 1,
			'orderby'       => 'date',
			'order'         => 'DESC',
		));

		if (! empty($orders)) {
			return $orders[0]->get_meta(self::$meta_key_uuid);
		}

		return '';
	}

	/**
	 * Log messages.
	 */
	private static function log($message)
	{
		$settings = get_option('woocommerce_qbitflow_settings', array());
		if ('yes' === ($settings['debug'] ?? 'no')) {
			$logger = wc_get_logger();
			$logger->info('[Customer Sync] ' . $message, array('source' => 'qbitflow'));
		}
	}
}
