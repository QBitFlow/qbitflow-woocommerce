<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration\Support;

use QBitFlowWC\Orders\OrderMeta;
use QBitFlowWC\Settings;
use QBitFlowWC\Webhooks\EndpointManager;
use WC_Order;
use WP_REST_Request;
use WP_REST_Response;

/** Deliveries to the real REST route, signed like QBitFlow signs them. */
abstract class WebhookTestCase extends TestCase
{
	protected const SESSION = 'pay@019a0000-0000-7000-8000-0000000000a1';

	public function set_up(): void
	{
		parent::set_up();
		$GLOBALS['wp_rest_server'] = null;
		$this->connect('test', Fixtures::SECRET);
	}

	protected function connect(string $mode, string $secret): void
	{
		update_option('qbitflow_wc_webhook_' . $mode, [
			'uuid' => '019a0000-0000-7000-8000-0000000e0001',
			'secret' => $secret,
			'mode' => $mode,
			'url' => EndpointManager::url(),
			'space' => 'space-1',
			'connected_at' => time(),
		]);
	}

	protected function deliver(array $event, ?string $secret = null, ?int $timestamp = null, string $route = '/qbitflow-wc/v2/webhook'): WP_REST_Response
	{
		[$body, $signature] = Fixtures::signed($event, $secret ?? Fixtures::SECRET, $timestamp);

		return $this->post_raw($body, $signature, $route);
	}

	protected function post_raw(string $body, string $signature, string $route = '/qbitflow-wc/v2/webhook'): WP_REST_Response
	{
		$request = new WP_REST_Request('POST', $route);
		$request->set_header('Content-Type', 'application/json');
		$request->set_header('QBitFlow-Signature', $signature);
		$request->set_body($body);

		return rest_get_server()->dispatch($request);
	}

	/** An order with an open checkout, as SessionManager leaves it. */
	protected function order_with_session(string $amountUsd = '10.00', string $mode = 'test'): WC_Order
	{
		$order = $this->make_order([['price' => $amountUsd]]);
		$order->update_meta_data(OrderMeta::SESSION_UUID, self::SESSION);
		$order->update_meta_data(OrderMeta::AMOUNT_USD, $amountUsd);
		$order->update_meta_data(OrderMeta::ORDER_TOTAL, $amountUsd);
		$order->update_meta_data(OrderMeta::MODE, $mode);
		$order->update_meta_data(OrderMeta::LAST_STATUS, 'created');
		$order->save();

		return $order;
	}

	/** A payment.completed event for the order's session. */
	protected function payment_completed(WC_Order $order, string $amountUsd = '10', string $id = 'evt_pay_1'): array
	{
		$event = Fixtures::event('payment.completed');
		$event['id'] = $id;
		$event['data'] = Fixtures::payment(self::SESSION, $amountUsd, $amountUsd, [], [
			'reference' => 'wc-' . Settings::store_id() . '-' . $order->get_id(),
			'metadata' => ['source' => 'woocommerce', 'store_id' => Settings::store_id(), 'order_id' => (string) $order->get_id(), 'token' => OrderMeta::token($order)],
			'test' => true,
		]);

		return $event;
	}
}
