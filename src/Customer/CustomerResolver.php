<?php

declare(strict_types=1);

namespace QBitFlowWC\Customer;

use QBitFlowWC\Log;
use QBitFlowWC\Orders\OrderMeta;
use QBitFlowWC\Support\Text;
use QBitFlowWC\Vendor\QBitFlow\Exceptions\ConflictException;
use QBitFlowWC\Vendor\QBitFlow\Exceptions\NotFoundException;
use QBitFlowWC\Vendor\QBitFlow\Exceptions\QBitFlowException;
use QBitFlowWC\Vendor\QBitFlow\Exceptions\ValidationException;
use QBitFlowWC\Vendor\QBitFlow\Params\CreateCustomerParams;
use QBitFlowWC\Vendor\QBitFlow\QBitFlow;
use WC_Order;

defined('ABSPATH') || exit;

/**
 * Gives QBitFlow the buyer, so its checkout asks nothing WooCommerce already has (and live
 * mode can email the customer their receipt).
 *
 * The buyer is the order's billing email: its QBitFlow customer has the reference
 * `wc-<sha256(email)>`, found or created from the billing fields. When QBitFlow can't be
 * reached for that, the checkout still gets the reference (`customerReference`), which skips the
 * questions too.
 */
final class CustomerResolver
{
	public static function reference(string $email): string
	{
		return 'wc-' . hash('sha256', strtolower(trim($email)));
	}

	/**
	 * @return array{customerUuid?: string, customerReference?: string}
	 */
	public static function resolve(WC_Order $order, QBitFlow $client): array
	{
		$email = trim((string) $order->get_billing_email());
		if ($email === '' || ! is_email($email)) {
			return [];
		}
		$reference = self::reference($email);

		try {
			$uuid = self::find_or_create($client, $order, $email, $reference);
		} catch (QBitFlowException $e) {
			Log::warning(sprintf('Order #%d: QBitFlow customer not resolved (%s); sending its reference only.', $order->get_id(), $e->getMessage()));

			return ['customerReference' => $reference];
		}

		$order->update_meta_data(OrderMeta::CUSTOMER_UUID, $uuid);

		return ['customerUuid' => $uuid];
	}

	/** @throws QBitFlowException */
	private static function find_or_create(QBitFlow $client, WC_Order $order, string $email, string $reference): string
	{
		try {
			return $client->customers->getByReference($reference)->uuid;
		} catch (NotFoundException) {
			// First order with this email: create the customer below.
		}

		try {
			return $client->customers->create(self::params($order, $email, $reference, true))->uuid;
		} catch (ValidationException $e) {
			// A billing field QBitFlow refuses (a phone format, a long address): the essentials only.
			Log::info(sprintf('Order #%d: customer details refused (%s); creating with name and email only.', $order->get_id(), $e->getMessage()));

			return $client->customers->create(self::params($order, $email, $reference, false))->uuid;
		} catch (ConflictException) {
			// Created meanwhile by a concurrent checkout.
			return $client->customers->getByReference($reference)->uuid;
		}
	}

	private static function params(WC_Order $order, string $email, string $reference, bool $full): CreateCustomerParams
	{
		[$first, $last] = self::names($order, $email);
		$phone = preg_replace('/[^0-9+().\- ]/', '', (string) $order->get_billing_phone()) ?? '';
		$address = self::address($order);

		return new CreateCustomerParams(
			firstName: $first,
			email: $email,
			lastName: $last,
			phoneNumber: $full && $phone !== '' ? mb_substr($phone, 0, 32) : null,
			address: $full && $address !== '' ? mb_substr($address, 0, 500) : null,
			reference: $reference,
		);
	}

	/**
	 * First name (2 to 100 characters, as QBitFlow requires) and last name, from the billing
	 * fields or, for a guest who gave only an email, its local part.
	 *
	 * @return array{0: string, 1: ?string}
	 */
	private static function names(WC_Order $order, string $email): array
	{
		$clean = static fn (string $s): string => Text::line(wp_strip_all_tags($s), 100);
		$first = $clean((string) $order->get_billing_first_name());
		$last = $clean((string) $order->get_billing_last_name());

		if (mb_strlen($first) < 2) {
			$parts = preg_split('/[._\-+]+/', strstr($email, '@', true) ?: '', 2) ?: [];
			$first = ucfirst($clean((string) ($parts[0] ?? '')));
			if ($last === '' && isset($parts[1])) {
				$last = ucfirst($clean((string) $parts[1]));
			}
		}
		if (mb_strlen($first) < 2) {
			$first = 'Customer';
		}

		return [mb_substr($first, 0, 100), $last !== '' ? mb_substr($last, 0, 100) : null];
	}

	private static function address(WC_Order $order): string
	{
		return Text::line(implode(', ', array_filter(array_map('trim', [
			(string) $order->get_billing_address_1(),
			(string) $order->get_billing_address_2(),
			(string) $order->get_billing_city(),
			(string) $order->get_billing_state(),
			(string) $order->get_billing_postcode(),
			(string) $order->get_billing_country(),
		]), 'strlen')), 500);
	}
}
