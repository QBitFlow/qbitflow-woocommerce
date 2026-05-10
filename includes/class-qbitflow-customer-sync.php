<?php
if (! defined('ABSPATH')) {
	exit;
}

class QBitFlow_Customer_Sync
{

	private static string $meta_key_uuid = '_qbitflow_customer_uuid';

	/**
	 * Register hooks.
	 *
	 * We deliberately do NOT sync at `user_register` or `profile_update`. At
	 * those moments WooCommerce hasn't collected the buyer's billing details
	 * yet — first/last name on the WP user are typically empty, there's no
	 * address or phone, and the email is just whatever they signed up with.
	 * Creating a QBitFlow customer record from that thin data and then
	 * caching its UUID against the WP user would mean every later order from
	 * the same account gets linked to a placeholder customer, even when the
	 * order's billing fields contain the real, complete information.
	 *
	 * Instead, we sync exactly once per order — at the moment WooCommerce
	 * gives us the completed checkout — using the order's billing fields as
	 * the authoritative source.
	 */
	public static function init()
	{
		add_action('woocommerce_checkout_order_created', array(__CLASS__, 'on_order_created'), 20);
	}

	public static function on_order_created($order)
	{
		self::ensure_uuid_for_order($order);
	}

	/**
	 * Resolve (and persist) the QBitFlow customer UUID for an order.
	 *
	 * Identity model:
	 *   - Buyer = the email on this order's billing form. NOT the WP account
	 *     that placed the order. The same WP user can ship to themselves on
	 *     one order and to a friend on the next; each is a distinct buyer
	 *     and gets a distinct QBitFlow customer.
	 *   - The persistent **email → UUID** map (`qbitflow_cuuid_<md5>` options)
	 *     is the source of truth. Order meta `_qbitflow_customer_uuid` is
	 *     just a denormalisation for admin display — we re-derive it from
	 *     the email map on every call so a buyer who changes their email
	 *     mid-checkout doesn't end up sending the previous email's UUID.
	 *   - User meta is never read or written for the UUID.
	 *
	 * Called from `woocommerce_checkout_order_created` and again from
	 * `process_payment()` as a fallback, so a payment session never goes
	 * out without a `customerUUID` — that's what saves the buyer from
	 * re-entering details on the QBitFlow hosted checkout.
	 *
	 * @return string UUID, or '' when we have no email or the API is unreachable.
	 */
	public static function ensure_uuid_for_order($order)
	{
		$email = $order->get_billing_email();
		if (empty($email)) {
			// No email on the order — best we can do is whatever was previously
			// cached on the order itself.
			return (string) $order->get_meta(self::$meta_key_uuid);
		}

		// Persistent local map: email → UUID. Hits the QBitFlow API only on
		// first sight of a given email. A new email on a second order from
		// the same WP account resolves to a fresh customer record.
		$uuid = self::get_uuid_for_email($email);
		if ($uuid) {
			if ($uuid !== (string) $order->get_meta(self::$meta_key_uuid)) {
				$order->update_meta_data(self::$meta_key_uuid, $uuid);
				$order->save();
			}
			self::log("Order #{$order->get_id()} reused customer {$uuid} for {$email}");
			return $uuid;
		}

		$user_id = $order->get_customer_id();

		$customer_data = array(
			'email'     => $email,
			'name'      => $order->get_billing_first_name(),
			'lastName'  => $order->get_billing_last_name(),
			'phone'     => $order->get_billing_phone(),
			'address'   => self::build_address_from_order($order),
			'reference' => $user_id ? "wp_user_{$user_id}" : "guest_{$email}",
		);

		self::log("ensure_uuid_for_order #{$order->get_id()} ({$email}) — find_or_create");

		$result = QBitFlow_API::find_or_create_customer($customer_data);
		if (is_wp_error($result)) {
			self::log('find_or_create failed: ' . $result->get_error_message());
			return '';
		}

		$uuid = sanitize_text_field((string) $result);
		if (empty($uuid)) {
			return '';
		}

		self::cache_uuid_for_email($email, $uuid);
		$order->update_meta_data(self::$meta_key_uuid, $uuid);
		$order->save();

		self::log("Linked order #{$order->get_id()} → customer {$uuid} (cached for {$email})");
		return $uuid;
	}

	/**
	 * Persistent email → UUID map.
	 *
	 * One wp_options row per unique email (autoload disabled). Lookup is
	 * O(1) via the option_name index; each row is ~80 bytes. The mapping
	 * is the source of truth across orders and across plugin upgrades.
	 */
	private static function uuid_option_key($email)
	{
		return 'qbitflow_cuuid_' . md5(strtolower(trim((string) $email)));
	}

	public static function get_uuid_for_email($email)
	{
		if (empty($email) || ! is_email($email)) {
			return '';
		}
		$value = get_option(self::uuid_option_key($email), '');
		return is_string($value) ? $value : '';
	}

	public static function cache_uuid_for_email($email, $uuid)
	{
		if (empty($email) || empty($uuid) || ! is_email($email)) {
			return;
		}
		update_option(self::uuid_option_key($email), sanitize_text_field($uuid), false);
	}

	/**
	 * Build the address string from a WooCommerce order's billing fields.
	 *
	 * QBitFlow's customer schema treats `address` as a free-text string, so we
	 * flatten the WC billing fields into a single comma-separated line. The
	 * structured version stays in the WC order itself (used for shipping,
	 * invoicing, etc.) — this string is purely for display on the QBitFlow side.
	 *
	 * Returns null when there's nothing to send.
	 */
	private static function build_address_from_order($order)
	{
		$parts = array_filter(array(
			trim((string) $order->get_billing_address_1()),
			trim((string) $order->get_billing_address_2()),
			trim((string) $order->get_billing_city()),
			trim((string) $order->get_billing_state()),
			trim((string) $order->get_billing_postcode()),
			trim((string) $order->get_billing_country()),
		), 'strlen');

		if (empty($parts)) {
			return null;
		}

		return implode(', ', $parts);
	}

	/**
	 * Read the cached UUID from order meta. No API calls, no user_meta.
	 *
	 * Used by render paths (admin order meta box). Anything that creates a
	 * payment session must call `ensure_uuid_for_order()` instead.
	 */
	public static function get_customer_uuid($order)
	{
		return (string) $order->get_meta(self::$meta_key_uuid);
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
