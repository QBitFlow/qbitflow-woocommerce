<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QBitFlowWC\Support\ApiErrors;
use QBitFlowWC\Vendor\QBitFlow\Exceptions\FieldError;
use QBitFlowWC\Vendor\QBitFlow\Exceptions\ValidationException;

final class ApiErrorsTest extends TestCase
{
	public function test_describes_message_status_code_request_and_each_field_with_its_reason(): void
	{
		$e = new ValidationException(
			'validation failed',
			400,
			'validation_failed',
			['errors' => [
				['field' => 'priceUsd', 'message' => 'too small', 'reason' => 'min'],
				['field' => 'successUrl', 'message' => 'must be https'],
			]],
			'req-1',
			[new FieldError('priceUsd', 'too small'), new FieldError('successUrl', 'must be https')],
		);

		$text = ApiErrors::describe($e);

		$this->assertStringContainsString('validation failed', $text);
		$this->assertStringContainsString('status 400', $text);
		$this->assertStringContainsString('code validation_failed', $text);
		$this->assertStringContainsString('request req-1', $text);
		$this->assertStringContainsString('priceUsd: too small [min]', $text);
		$this->assertStringContainsString('successUrl: must be https', $text);
		$this->assertStringNotContainsString('successUrl: must be https [', $text);
	}
}
