<?php

declare(strict_types=1);

namespace QBitFlowWC\Webhooks;

use QBitFlowWC\Log;
use QBitFlowWC\Orders\OrderFinder;
use QBitFlowWC\Orders\OrderMeta;
use QBitFlowWC\Orders\PaymentSync;
use QBitFlowWC\Refunds\RefundSync;
use QBitFlowWC\Settings;
use QBitFlowWC\Vendor\QBitFlow\Enums\EventType;
use QBitFlowWC\Vendor\QBitFlow\Events\Event;
use QBitFlowWC\Vendor\QBitFlow\Exceptions\WebhookSignatureException;
use QBitFlowWC\Vendor\QBitFlow\Models\PaymentCompleted;
use QBitFlowWC\Vendor\QBitFlow\Models\PaymentSessionData;
use QBitFlowWC\Vendor\QBitFlow\Models\SubscriptionSessionData;
use QBitFlowWC\Vendor\QBitFlow\Webhooks\Webhook;
use QBitFlowWC\Vendor\QBitFlow\Webhooks\WebhookRouter;
use Throwable;
use WC_Order;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

/**
 * The webhook endpoint: `POST /wp-json/qbitflow-wc/v2/webhook`.
 *
 * - The signature is verified locally (`QBitFlow-Signature`, the endpoint's secret): no
 *   outbound HTTP while handling a delivery.
 * - A body over 1 MiB is refused before anything reads it.
 * - Anything that is not this store's (another integration's event on the same account, an
 *   order deleted since) answers 200: a 4xx/5xx makes QBitFlow retry, then disable the endpoint.
 * - A handler error answers 500 (QBitFlow retries) and never escapes as a fatal error.
 * - Deliveries are at least once: each event is applied once per order (its id is kept).
 */
final class Receiver
{
	public const LEGACY_NAMESPACE = 'qbitflow-wc';

	/** How many event ids an order remembers. */
	private const SEEN = 20;

	public static function init(): void
	{
		add_action('rest_api_init', [self::class, 'register_routes']);
		add_filter('rest_pre_dispatch', [self::class, 'refuse_oversized'], 10, 3);
	}

	/**
	 * Refuses an oversized delivery before WordPress parses it (the REST server decodes JSON
	 * bodies before the route's callback runs).
	 *
	 * @param mixed $result
	 * @return mixed
	 */
	public static function refuse_oversized($result, $server, WP_REST_Request $request)
	{
		// Every route of the plugin's namespaces, compared case-insensitively as WordPress matches routes.
		$route = strtolower($request->get_route());
		if ($result === null && (str_starts_with($route, '/' . EndpointManager::ROUTE_NAMESPACE . '/') || str_starts_with($route, '/' . self::LEGACY_NAMESPACE . '/'))
			&& strlen((string) $request->get_body()) > Webhook::MAX_BODY_BYTES) {
			return self::answer(413, ['error' => 'body too large']);
		}

		return $result;
	}

	public static function register_routes(): void
	{
		// Public: QBitFlow can't present a WordPress credential. The HMAC signature, verified in
		// handle(), is the authentication.
		register_rest_route(EndpointManager::ROUTE_NAMESPACE, EndpointManager::ROUTE, [
			'methods' => 'POST',
			'callback' => [self::class, 'handle'],
			'permission_callback' => '__return_true',
		]);
		register_rest_route(self::LEGACY_NAMESPACE, '/webhook', [
			'methods' => 'POST',
			'callback' => [self::class, 'legacy'],
			'permission_callback' => '__return_true',
		]);
	}

	public static function handle(WP_REST_Request $request): WP_REST_Response
	{
		try {
			$body = (string) $request->get_body();
			if (strlen($body) > Webhook::MAX_BODY_BYTES) {
				return self::answer(413, ['error' => 'body too large']);
			}
			$secret = EndpointManager::secret();
			if ($secret === null) {
				Log::error('Webhook received, but no signing secret is configured: use "Connect webhook" in the QBitFlow settings.');

				return self::answer(503, ['error' => 'not configured']);
			}
			$signature = (string) $request->get_header('qbitflow_signature');

			$result = self::router($secret)->handle($body, $signature);
			if ($result->status === 400 && $result->error instanceof WebhookSignatureException && self::signed_for_other_mode($body, $signature)) {
				// The other mode's endpoint (e.g. test deliveries after switching to a live key): acknowledged, ignored.
				Log::info('Webhook for the other mode (test/live) acknowledged and ignored.');

				return self::answer(200, ['received' => true]);
			}

			return self::answer($result->status, (array) json_decode($result->responseBody(), true));
		} catch (Throwable $e) {
			Log::error('Webhook handling failed: ' . $e->getMessage());

			return self::answer(500, ['error' => 'internal error']);
		}
	}

	/** 1.x's URL: tell whoever still posts there (a v1 endpoint) to reconnect. */
	public static function legacy(): WP_REST_Response
	{
		return self::answer(410, ['error' => 'This is the QBitFlow for WooCommerce 1.x webhook URL. Connect the webhook again from the plugin settings: it now receives at /wp-json/qbitflow-wc/v2/webhook.']);
	}

	public static function router(string $secret): WebhookRouter
	{
		$router = (new WebhookRouter($secret))
			->on(EventType::PAYMENT_COMPLETED, static function (PaymentCompleted $payment, Event $event): void {
				self::for_order(OrderFinder::for_session($payment->uuid, $payment->metadata), $event, static function (WC_Order $order) use ($payment): void {
					PaymentSync::complete($order, $payment, __('webhook', 'qbitflow-for-woocommerce'));
				});
			})
			->on(EventType::CHECKOUT_EXPIRED, static function (PaymentSessionData $session, Event $event): void {
				if ($session instanceof SubscriptionSessionData) {
					return;
				}
				self::for_order(OrderFinder::for_session($session->uuid, $session->metadata), $event, static function (WC_Order $order) use ($session): void {
					PaymentSync::expire($order, $session->uuid, __('webhook', 'qbitflow-for-woocommerce'));
				});
			})
			->onAny(static function (Event $event): void {
				/**
				 * Fires after the plugin handled a verified QBitFlow event (any type). Throwing
				 * makes QBitFlow retry the delivery.
				 *
				 * @param Event $event
				 */
				do_action('qbitflow_wc_webhook_event', $event);
			})
			->onError(static function (?Event $event, Throwable $error): void {
				Log::warning(sprintf('Webhook %s refused: %s', $event?->id ?? '(unverified)', $error->getMessage()));
			});

		return RefundSync::attach($router);
	}

	/**
	 * Runs `$apply` for an event's order unless the order isn't this store's, belongs to the
	 * other mode, or already saw this event.
	 *
	 * @param callable(WC_Order): void $apply
	 */
	public static function for_order(?WC_Order $order, Event $event, callable $apply): void
	{
		if ($order === null) {
			Log::debug(sprintf('Webhook %s (%s) is not about an order of this store: ignored.', $event->id, $event->type));

			return;
		}
		$mode = (string) $order->get_meta(OrderMeta::MODE);
		if ($mode !== '' && ($mode === 'test') !== $event->test) {
			Log::info(sprintf('Webhook %s is %s, order #%d was created in %s mode: ignored.', $event->id, $event->test ? 'test' : 'live', $order->get_id(), $mode));

			return;
		}
		$seen = OrderMeta::list($order, OrderMeta::EVENTS);
		if (in_array($event->id, $seen, true)) {
			return;
		}

		$apply($order);

		$order = wc_get_order($order->get_id());
		if ($order instanceof WC_Order) {
			$seen[] = $event->id;
			$order->update_meta_data(OrderMeta::EVENTS, array_slice($seen, -self::SEEN));
			$order->save_meta_data();
		}
	}

	/** Whether the other mode's stored endpoint secret verifies the delivery. */
	private static function signed_for_other_mode(string $body, string $signature): bool
	{
		$other = EndpointManager::stored(Settings::mode() === 'live' ? 'test' : 'live');
		if ($other === null) {
			return false;
		}
		try {
			Webhook::verify($body, $signature, (string) $other['secret']);

			return true;
		} catch (Throwable) {
			return false;
		}
	}

	/** @param array<string, mixed> $body */
	private static function answer(int $status, array $body): WP_REST_Response
	{
		return new WP_REST_Response($body, $status);
	}
}
