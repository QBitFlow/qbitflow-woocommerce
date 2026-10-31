<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration;

use QBitFlowWC\Gateway;
use QBitFlowWC\Tests\Integration\Support\TestCase;

final class SmokeTest extends TestCase
{
	public function test_gateway_registered(): void
	{
		$gateways = WC()->payment_gateways()->payment_gateways();

		$this->assertInstanceOf(Gateway::class, $gateways['qbitflow']);
		$this->assertTrue(class_exists('WC_Gateway_QBitFlow'));
	}

	public function test_sdk_classes_are_prefixed(): void
	{
		$this->assertTrue(class_exists('QBitFlowWC\Vendor\QBitFlow\QBitFlow'));
		$this->assertTrue(interface_exists('QBitFlowWC\Vendor\Psr\Http\Client\ClientInterface'));
	}

	public function test_hpos_is_on(): void
	{
		$this->assertTrue(\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled());
	}
}
