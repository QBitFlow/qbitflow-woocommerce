<?php

declare(strict_types=1);

namespace QBitFlowWC\Tests\Integration\Webhooks;

use QBitFlowWC\Settings;
use QBitFlowWC\Tests\Integration\Support\Fixtures;
use QBitFlowWC\Tests\Integration\Support\WebhookTestCase;

final class ReceiverTest extends WebhookTestCase
{
	public function test_payment_completed_completes_order(): void
	{
		$order = $this->order_with_session();

		$response = $this->deliver($this->payment_completed($order));

		$this->assertSame(200, $response->get_status());
		$this->assertSame(['received' => true], $response->get_data());
		$order = wc_get_order($order->get_id());
		$this->assertSame('processing', $order->get_status());
		$notes = implode("\n", wc_list_pluck(wc_get_order_notes(['order_id' => $order->get_id()]), 'content'));
		$this->assertStringContainsString('Confirmed by webhook', $notes);
	}

	public function test_no_outbound_http_during_delivery(): void
	{
		$order = $this->order_with_session();

		$this->deliver($this->payment_completed($order));

		$this->assertSame([], $this->api->requests());
	}

	public function test_bad_signature_400(): void
	{
		$order = $this->order_with_session();

		$response = $this->deliver($this->payment_completed($order), 'whsec_wrong');

		$this->assertSame(400, $response->get_status());
		$this->assertSame('pending', wc_get_order($order->get_id())->get_status());
	}

	public function test_missing_signature_400(): void
	{
		$order = $this->order_with_session();

		$this->assertSame(400, $this->post_raw((string) wp_json_encode($this->payment_completed($order)), '')->get_status());
	}

	public function test_stale_timestamp_400(): void
	{
		$order = $this->order_with_session();

		$response = $this->deliver($this->payment_completed($order), null, time() - 3600);

		$this->assertSame(400, $response->get_status());
	}

	public function test_no_secret_503(): void
	{
		delete_option('qbitflow_wc_webhook_test');
		$order = $this->order_with_session();

		$this->assertSame(503, $this->deliver($this->payment_completed($order))->get_status());
	}

	public function test_manual_secret_verifies(): void
	{
		delete_option('qbitflow_wc_webhook_test');
		$this->set_settings(['webhook_secret' => 'whsec_manual']);
		$order = $this->order_with_session();

		$this->assertSame(200, $this->deliver($this->payment_completed($order), 'whsec_manual')->get_status());
		$this->assertTrue(wc_get_order($order->get_id())->is_paid());
	}

	public function test_body_over_1_mib_413_without_parsing(): void
	{
		$body = '{"pad":"' . str_repeat('a', 1048576) . '"}';

		$this->assertSame(413, $this->post_raw($body, 't=1,v1=00')->get_status());
	}

	public function test_oversized_invalid_json_413_before_wordpress_parses_it(): void
	{
		$request = new \WP_REST_Request('POST', '/qbitflow-wc/v2/webhook');
		$request->set_header('Content-Type', 'application/json');
		$request->set_body(str_repeat('a', 1048577));

		$response = rest_get_server()->dispatch($request);

		$this->assertSame(413, $response->get_status());
	}

	public function test_oversized_body_413_whatever_the_route_case(): void
	{
		$request = new \WP_REST_Request('POST', '/QBITFLOW-WC/V2/Webhook');
		$request->set_header('Content-Type', 'application/json');
		$request->set_body(str_repeat('a', 1048577));

		$this->assertSame(413, rest_get_server()->dispatch($request)->get_status());
	}

	public function test_unknown_order_answers_200(): void
	{
		$order = $this->order_with_session();
		$event = $this->payment_completed($order);
		$event['data']['uuid'] = 'pay@019a0000-0000-7000-8000-0000000000ff';
		$event['data']['metadata']['order_id'] = '999999';

		$this->assertSame(200, $this->deliver($event)->get_status());
		$this->assertSame('pending', wc_get_order($order->get_id())->get_status());
	}

	public function test_foreign_store_metadata_answers_200(): void
	{
		$order = $this->order_with_session();
		$event = $this->payment_completed($order);
		$event['data']['uuid'] = 'pay@019a0000-0000-7000-8000-0000000000ff';
		$event['data']['metadata']['store_id'] = 'otherst1';

		$this->assertSame(200, $this->deliver($event)->get_status());
		$this->assertSame('pending', wc_get_order($order->get_id())->get_status());
	}

	public function test_finds_order_by_metadata_when_session_meta_is_missing(): void
	{
		$order = $this->make_order([['price' => '10.00']]);
		$event = $this->payment_completed($order);

		$this->deliver($event);

		$this->assertTrue(wc_get_order($order->get_id())->is_paid());
	}

	public function test_clone_payment_for_the_same_order_number_is_ignored(): void
	{
		// A staging copy with the same store id and order numbers: its order has another token.
		$order = $this->make_order([['price' => '10.00']]);
		\QBitFlowWC\Orders\OrderMeta::token($order);
		$event = $this->payment_completed($order);
		$event['data']['uuid'] = 'pay@019a0000-0000-7000-8000-0000000000ff';
		$event['data']['metadata']['token'] = str_repeat('0', 32);

		$this->assertSame(200, $this->deliver($event)->get_status());
		$this->assertFalse(wc_get_order($order->get_id())->is_paid());
	}

	public function test_duplicate_event_is_noop(): void
	{
		$order = $this->order_with_session();
		$event = $this->payment_completed($order);

		$this->deliver($event);
		$notes = count(wc_get_order_notes(['order_id' => $order->get_id()]));
		$this->assertSame(200, $this->deliver($event)->get_status());

		$this->assertCount($notes, wc_get_order_notes(['order_id' => $order->get_id()]));
	}

	public function test_checkout_expired_fails_unpaid_order(): void
	{
		$order = $this->order_with_session();
		$event = Fixtures::event('checkout.expired');
		$event['data']['uuid'] = self::SESSION;
		$event['data']['metadata'] = ['source' => 'woocommerce', 'store_id' => Settings::store_id(), 'order_id' => (string) $order->get_id(), 'token' => \QBitFlowWC\Orders\OrderMeta::token($order)];

		$this->assertSame(200, $this->deliver($event)->get_status());

		$this->assertSame('failed', wc_get_order($order->get_id())->get_status());
	}

	public function test_webhook_test_event_200(): void
	{
		$this->assertSame(200, $this->deliver(Fixtures::event('webhook.test'))->get_status());
	}

	public function test_handler_throwable_answers_500(): void
	{
		add_action('qbitflow_wc_webhook_event', static function (): void {
			throw new \RuntimeException('boom');
		});
		$order = $this->order_with_session();

		$this->assertSame(500, $this->deliver($this->payment_completed($order))->get_status());
	}

	public function test_busy_order_answers_500_so_qbitflow_retries(): void
	{
		global $wpdb;
		$order = $this->order_with_session();
		$wpdb->insert($wpdb->options, ['option_name' => 'qbitflow_wc_lock_' . $order->get_id(), 'option_value' => (string) time(), 'autoload' => 'no']);

		$this->assertSame(500, $this->deliver($this->payment_completed($order))->get_status());
		$this->assertFalse(wc_get_order($order->get_id())->is_paid());
	}

	public function test_legacy_route_410(): void
	{
		$response = $this->post_raw('{"uuid":"x"}', '', '/qbitflow-wc/webhook');

		$this->assertSame(410, $response->get_status());
		$this->assertStringContainsString('/qbitflow-wc/v2/webhook', $response->get_data()['error']);
	}

	public function test_other_mode_delivery_is_acknowledged_and_ignored(): void
	{
		$this->connect('live', 'whsec_live');
		$order = $this->order_with_session();
		$event = $this->payment_completed($order);

		$response = $this->deliver($event, 'whsec_live');

		$this->assertSame(200, $response->get_status());
		$this->assertSame('pending', wc_get_order($order->get_id())->get_status());
	}

	public function test_event_of_the_other_mode_than_the_order_is_ignored(): void
	{
		$order = $this->order_with_session('10.00', 'live');

		$this->assertSame(200, $this->deliver($this->payment_completed($order))->get_status());
		$this->assertSame('pending', wc_get_order($order->get_id())->get_status());
	}

	public function test_not_json_400(): void
	{
		$body = 'not json';

		$this->assertSame(400, $this->post_raw($body, \QBitFlowWC\Vendor\QBitFlow\Webhooks\Webhook::sign($body, Fixtures::SECRET))->get_status());
	}
}
