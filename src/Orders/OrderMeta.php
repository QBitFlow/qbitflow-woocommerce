<?php

declare(strict_types=1);

namespace QBitFlowWC\Orders;

use QBitFlowWC\Settings;
use WC_Order;

defined('ABSPATH') || exit;

/**
 * The order meta the plugin keeps. The 1.x keys are unchanged, so orders paid with 1.x still
 * display.
 */
final class OrderMeta
{
	// Kept from 1.x.
	public const SESSION_UUID = '_qbitflow_session_uuid';
	public const PAYMENT_LINK = '_qbitflow_payment_link';
	public const LAST_STATUS = '_qbitflow_last_status';
	public const TX_HASH = '_qbitflow_tx_hash';
	public const MANAGEMENT_LINK = '_qbitflow_management_link';
	public const CUSTOMER_UUID = '_qbitflow_customer_uuid';
	public const REFUND_TX_HASH = '_qbitflow_refund_tx_hash';
	public const REFUND_SNAPSHOT = '_qbitflow_refund_snapshot';

	// 2.0.
	/** The amounts fingerprint the open session was created for. */
	public const FINGERPRINT = '_qbitflow_fingerprint';
	/** What the session charges in USD (price + lines, before a processing fee), e.g. "132.50". */
	public const AMOUNT_USD = '_qbitflow_amount_usd';
	/** The order total (order currency) when the session was created: a later increase is never paid by it. */
	public const ORDER_TOTAL = '_qbitflow_order_total';
	/** The order currency ORDER_TOTAL is in. */
	public const ORDER_CURRENCY = '_qbitflow_order_currency';
	/** `test` or `live`: the key's mode when the session was created. */
	public const MODE = '_qbitflow_mode';
	/** The last webhook event ids handled for the order (deduplication). */
	public const EVENTS = '_qbitflow_events';
	public const CHAIN = '_qbitflow_chain';
	public const EXPLORER_URL = '_qbitflow_explorer_url';
	/** The token paid, e.g. "USDC (BASE)". */
	public const CURRENCY = '_qbitflow_currency';
	/** What the customer paid in USD, network fee excluded. */
	public const PAID_USD = '_qbitflow_paid_usd';
	/** The processing fee the customer paid on top of the order total, in USD. */
	public const PROCESSING_FEE_USD = '_qbitflow_processing_fee_usd';
	/** Refunds seen for the order: refund uuid → snapshot (status, percent, amount, tx hash, reason…). */
	public const REFUNDS = '_qbitflow_refunds';
	/** `1` while the order is held because the payment didn't cover it (cleared once the order leaves on-hold). */
	public const AMOUNT_MISMATCH = '_qbitflow_amount_mismatch';
	/** Code of the last failed attempt already noted (so reconciliation notes it once). */
	public const LAST_ATTEMPT_NOTED = '_qbitflow_last_attempt_noted';
	/** The last failed attempt: `{code, message, at}` (`at` a Unix timestamp). */
	public const LAST_ATTEMPT = '_qbitflow_last_attempt';

	/** A random value sent with the order's checkouts: metadata naming the order must carry it. */
	public const TOKEN = '_qbitflow_token';

	/** The order's token, made (and saved) on first use. */
	public static function token(WC_Order $order): string
	{
		$token = (string) $order->get_meta(self::TOKEN);
		if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
			$token = bin2hex(random_bytes(16));
			$order->update_meta_data(self::TOKEN, $token);
			$order->save_meta_data();
		}

		return $token;
	}

	/**
	 * Whether checkout metadata was made by this store for this order: same store id and the
	 * order's token.
	 *
	 * @param array<array-key, mixed> $metadata
	 */
	public static function metadata_matches(WC_Order $order, array $metadata): bool
	{
		$token = (string) $order->get_meta(self::TOKEN);

		return ($metadata['source'] ?? null) === 'woocommerce'
			&& ($metadata['store_id'] ?? null) === Settings::store_id()
			&& (string) ($metadata['order_id'] ?? '') === (string) $order->get_id()
			&& $token !== ''
			&& hash_equals($token, (string) ($metadata['token'] ?? ''));
	}

	/** A checkout being replaced (its expiry must not fail the order): its uuid. */
	public const SUPERSEDED = '_qbitflow_superseded';

	/**
	 * An array meta value (a missing key reads `''`, which `(array)` would turn into `['']`).
	 *
	 * @return array<array-key, mixed>
	 */
	public static function list(WC_Order $order, string $key): array
	{
		$value = $order->get_meta($key);

		return is_array($value) ? $value : [];
	}

	/** The reference QBitFlow keeps for the order: `wc-<store id>-<order id>`. */
	public static function reference(WC_Order $order): string
	{
		return 'wc-' . Settings::store_id() . '-' . $order->get_id();
	}

	/** A session uuid without its `pay@` prefix (1.x stored bare uuids). */
	public static function bare_uuid(string $uuid): string
	{
		$at = strpos($uuid, '@');

		return $at === false ? $uuid : substr($uuid, $at + 1);
	}

	/** Whether `$uuid` names the order's current session (bare or prefixed). */
	public static function is_current_session(WC_Order $order, string $uuid): bool
	{
		$current = (string) $order->get_meta(self::SESSION_UUID);

		return $current !== '' && self::bare_uuid($current) === self::bare_uuid($uuid);
	}
}
