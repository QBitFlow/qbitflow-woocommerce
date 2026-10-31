<?php

declare(strict_types=1);

namespace QBitFlowWC\Support;

use QBitFlowWC\Vendor\QBitFlow\Exceptions\QBitFlowException;

defined('ABSPATH') || exit;

/** An API error as one readable log line (never shown to customers). */
final class ApiErrors
{
	/** Message, status, code, request id, then each field error as `field: message [reason]`. */
	public static function describe(QBitFlowException $e): string
	{
		$text = sprintf(
			'%s (status %d, code %s, request %s)',
			$e->getErrorMessage() !== '' ? $e->getErrorMessage() : $e->getMessage(),
			$e->getStatus(),
			$e->getApiCode(),
			$e->getRequestId()
		);

		$raw = $e->getDetails()['errors'] ?? [];
		$fields = [];
		foreach ($e->getFieldErrors() as $i => $error) {
			$reason = '';
			if (method_exists($error, 'getReason')) {
				$reason = (string) $error->getReason();
			} elseif (is_array($raw[$i] ?? null)) {
				$reason = (string) ($raw[$i]['reason'] ?? '');
			}
			$fields[] = $error->field . ': ' . $error->message . ($reason !== '' ? ' [' . $reason . ']' : '');
		}

		return $fields === [] ? $text : $text . ': ' . implode('; ', $fields);
	}

	/** Whether QBitFlow refused because the organization turned `$policy` off for this member's key. */
	public static function policy_off(QBitFlowException $e, string $policy): bool
	{
		return $e->getStatus() === 403 && $e->getApiCode() === 'policy_disabled' && ($e->getDetails()['policy'] ?? '') === $policy;
	}
}
