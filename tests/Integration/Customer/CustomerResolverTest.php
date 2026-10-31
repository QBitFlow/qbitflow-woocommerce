<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration\Customer;

use QBitFlowWC\ClientFactory;
use QBitFlowWC\Customer\CustomerResolver;
use QBitFlowWC\Tests\Integration\Support\Fixtures;
use QBitFlowWC\Tests\Integration\Support\TestCase;

final class CustomerResolverTest extends TestCase
{
	private const UUID = '019a0000-0000-7000-8000-0000000c0001';

	private function reference(): string
	{
		return CustomerResolver::reference('ada@example.com');
	}

	public function test_reference_is_a_case_insensitive_hash(): void
	{
		$this->assertSame(CustomerResolver::reference(' Ada@Example.com '), $this->reference());
		$this->assertMatchesRegularExpression('/^wc-[0-9a-f]{64}$/', $this->reference());
	}

	public function test_existing_customer_by_reference(): void
	{
		$this->api->on('GET', '#^/customers$#', 200, Fixtures::page([Fixtures::customer(self::UUID, $this->reference())]));
		$order = $this->make_order();

		$this->assertSame(['customerUuid' => self::UUID], CustomerResolver::resolve($order, ClientFactory::make()));
		$this->assertStringContainsString('reference=' . $this->reference(), $this->api->lastRequest('GET', '#^/customers$#')['url']);
		$this->assertSame([], $this->api->requests('POST'));
		$this->assertSame(self::UUID, $order->get_meta('_qbitflow_customer_uuid'));
	}

	public function test_creates_when_missing_with_billing_fields(): void
	{
		$this->api->on('GET', '#^/customers$#', 200, Fixtures::page([]));
		$this->api->on('POST', '#^/customers$#', 201, Fixtures::customer(self::UUID, $this->reference()));

		$result = CustomerResolver::resolve($this->make_order(), ClientFactory::make());

		$this->assertSame(['customerUuid' => self::UUID], $result);
		$body = $this->api->lastRequest('POST', '#^/customers$#')['json'];
		$this->assertSame('Ada', $body['firstName']);
		$this->assertSame('Lovelace', $body['lastName']);
		$this->assertSame('ada@example.com', $body['email']);
		$this->assertSame('+44 20 7946 0000', $body['phoneNumber']);
		$this->assertSame('12 St James Square, London, SW1Y 4JH, GB', $body['address']);
		$this->assertSame($this->reference(), $body['reference']);
	}

	public function test_refused_details_retry_with_essentials(): void
	{
		$this->api->on('GET', '#^/customers$#', 200, Fixtures::page([]));
		$this->api->onError('POST', '#^/customers$#', 400, 'validation_failed', 'validation failed', ['errors' => [['field' => 'phoneNumber', 'message' => 'invalid']]]);
		$this->api->on('POST', '#^/customers$#', 201, Fixtures::customer(self::UUID, $this->reference()));

		$this->assertSame(['customerUuid' => self::UUID], CustomerResolver::resolve($this->make_order(), ClientFactory::make()));
		$retry = $this->api->requests('POST', '#^/customers$#')[1]['json'];
		$this->assertArrayNotHasKey('phoneNumber', $retry);
		$this->assertArrayNotHasKey('address', $retry);
	}

	public function test_api_failure_falls_back_to_reference(): void
	{
		$this->api->onError('GET', '#^/customers$#', 503, 'unavailable', 'Service unavailable');

		$result = CustomerResolver::resolve($this->make_order(), ClientFactory::make(5.0, 0));

		$this->assertSame(['customerReference' => $this->reference()], $result);
	}

	public function test_no_email_returns_empty(): void
	{
		$order = $this->make_order();
		$order->set_billing_email('');

		$this->assertSame([], CustomerResolver::resolve($order, ClientFactory::make()));
		$this->assertSame([], $this->api->requests());
	}

	public function test_guest_with_one_letter_name_gets_email_based_names(): void
	{
		$this->api->on('GET', '#^/customers$#', 200, Fixtures::page([]));
		$this->api->on('POST', '#^/customers$#', 201, Fixtures::customer(self::UUID, CustomerResolver::reference('john.doe@example.com')));
		$order = $this->make_order([['price' => '5.00']], ['email' => 'john.doe@example.com']);
		$order->set_billing_first_name('J');
		$order->set_billing_last_name('');

		CustomerResolver::resolve($order, ClientFactory::make());

		$body = $this->api->lastRequest('POST', '#^/customers$#')['json'];
		$this->assertSame('John', $body['firstName']);
		$this->assertSame('Doe', $body['lastName']);
	}
}
