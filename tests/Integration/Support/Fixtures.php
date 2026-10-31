<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration\Support;

use QBitFlowWC\Vendor\QBitFlow\Webhooks\Webhook;

/**
 * API answers and webhook events, from the SDK's fixtures (the API docs' examples, in
 * `tests/Fixtures/events`), adjusted per test.
 */
final class Fixtures
{
	public const SECRET = 'whsec_test_secret';

	/** @return array<string, mixed> An event envelope from the fixture of `$type`. */
	public static function event(string $type): array
	{
		$event = json_decode((string) file_get_contents(dirname(__DIR__, 2) . "/Fixtures/events/{$type}.json"), true);
		$event['test'] = true;
		$event['data']['test'] = true;

		return $event;
	}

	/** The body and `QBitFlow-Signature` header of a delivery. @return array{0: string, 1: string} */
	public static function signed(array $event, string $secret = self::SECRET, ?int $timestamp = null): array
	{
		$body = (string) wp_json_encode($event);

		return [$body, Webhook::sign($body, $secret, $timestamp)];
	}

	/** @return array<string, mixed> */
	public static function me(bool $test = true, bool $member = false): array
	{
		$space = [
			'uuid' => 'space-1',
			'organizationUuid' => 'org-1',
			'organizationName' => 'Example Shop',
			'test' => $test,
			'role' => 'admin',
			'createdAt' => '2026-09-01T12:00:00Z',
		];
		if ($member) {
			$space['userUuid'] = 'member-1';
			$space['member'] = ['userUuid' => 'member-1', 'firstName' => 'Ada', 'email' => 'ada@example.com'];
			$space['organizationFeePercent'] = '5';
		} else {
			$space['trustedAt'] = '2026-09-01T12:00:00Z';
		}

		return ['credential' => 'apiKey', 'apiKeyUuid' => 'key-1', 'role' => $member ? 'user' : 'admin', 'space' => $space];
	}

	/** @return array<string, mixed> */
	public static function currency(string $code = 'BASE:USDC', string $symbol = 'USDC'): array
	{
		return ['id' => 8, 'code' => $code, 'symbol' => $symbol, 'name' => 'USD Coin', 'chain' => 'BASE', 'decimals' => 6, 'test' => true];
	}

	/** @return array{items: list<mixed>, hasMore: bool} */
	public static function page(array $items): array
	{
		return ['items' => $items, 'hasMore' => false];
	}

	/**
	 * A payment (`GET /payments/:uuid`, and `payment.completed`'s data) for an order's session.
	 *
	 * @param list<array{type: string, label: string, amountUsd: string}> $fees
	 * @return array<string, mixed>
	 */
	public static function payment(string $uuid, string $amountUsd, string $priceUsd, array $fees = [], array $overrides = []): array
	{
		$data = self::event('payment.completed')['data'];
		$data['uuid'] = $uuid;
		$data['amountUsd'] = $amountUsd;
		$data['priceUsd'] = $priceUsd;
		$data['fees'] = $fees;

		return array_replace($data, $overrides);
	}

	/** @return array<string, mixed> */
	public static function session_status(string $uuid, string $status, array $overrides = []): array
	{
		$data = ['uuid' => $uuid, 'status' => $status];
		if (in_array($status, ['created', 'waitingConfirmation'], true)) {
			$data['expiresAt'] = gmdate('Y-m-d\TH:i:s\Z', time() + 3600);
		}

		return array_replace($data, $overrides);
	}

	/** @return array<string, mixed> */
	public static function endpoint(string $uuid, string $url, bool $test = true, array $overrides = []): array
	{
		return array_replace([
			'uuid' => $uuid,
			'test' => $test,
			'url' => $url,
			'events' => ['checkout.expired', 'payment.completed', 'refund.approved', 'refund.rejected', 'refund.requested'],
			'includeMembers' => false,
			'payloadVersion' => 'v2',
			'createdAt' => '2026-10-01T12:00:00Z',
		], $overrides);
	}

	/** @return array<string, mixed> */
	public static function customer(string $uuid, string $reference, string $email = 'ada@example.com'): array
	{
		return [
			'uuid' => $uuid,
			'firstName' => 'Ada',
			'lastName' => 'Lovelace',
			'email' => $email,
			'verified' => false,
			'reference' => $reference,
			'createdAt' => '2026-10-01T12:00:00Z',
			'test' => true,
		];
	}

	/** A refund (`refund.*` events' data). @return array<string, mixed> */
	public static function refund(string $uuid, string $txUuid, string $status, string $percent = '100', string $initiatedBy = 'customer', array $overrides = []): array
	{
		$data = self::event('refund.approved')['data'];
		$data['uuid'] = $uuid;
		$data['txUuid'] = $txUuid;
		$data['status'] = $status;
		$data['refundPercent'] = $percent;
		$data['initiatedBy'] = $initiatedBy;
		if ($status !== 'approved') {
			unset($data['txHash'], $data['settlement'], $data['explorerUrl']);
		}
		if ($status === 'pending') {
			unset($data['respondedAt']);
		}

		return array_replace($data, $overrides);
	}
}
