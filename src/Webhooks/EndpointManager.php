<?php

declare(strict_types=1);

namespace QBitFlowWC\Webhooks;

use QBitFlowWC\Admin\Notices;
use QBitFlowWC\ClientFactory;
use QBitFlowWC\Log;
use QBitFlowWC\Settings;
use QBitFlowWC\Support\Text;
use QBitFlowWC\Vendor\QBitFlow\Enums\EventType;
use QBitFlowWC\Vendor\QBitFlow\Exceptions\ConflictException;
use QBitFlowWC\Vendor\QBitFlow\Exceptions\PermissionDeniedException;
use QBitFlowWC\Vendor\QBitFlow\Exceptions\QBitFlowException;
use QBitFlowWC\Vendor\QBitFlow\Params\CreateWebhookEndpointParams;
use RuntimeException;

defined('ABSPATH') || exit;

/**
 * The store's webhook endpoint on QBitFlow: registered in one click, its signing secret kept
 * per mode (a test key's endpoint never verifies live deliveries).
 *
 * An endpoint's secret is only returned when it is created (reading or rotating it is
 * dashboard-only), so connecting again replaces the store's endpoint: the new one is created and
 * stored first, then the old ones with the same URL are deleted.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are built escaped (esc_html__) and shown through Notices (wp_kses_post).
final class EndpointManager
{
	public const EVENTS = [
		EventType::PAYMENT_COMPLETED,
		EventType::CHECKOUT_EXPIRED,
		EventType::REFUND_REQUESTED,
		EventType::REFUND_APPROVED,
		EventType::REFUND_REJECTED,
	];

	public const ROUTE_NAMESPACE = 'qbitflow-wc/v2';

	public const ROUTE = '/webhook';

	private static bool $override_logged = false;

	/**
	 * The address registered on QBitFlow and compared everywhere. For local development only,
	 * `QBITFLOW_WC_WEBHOOK_URL` (or the `qbitflow_wc_webhook_url` filter) replaces it, e.g. an
	 * http address a development QBitFlow server can reach; only an absolute http(s) URL is accepted, anything else
	 * falls back to the REST route (and is logged once).
	 */
	public static function url(): string
	{
		$default = rest_url(self::ROUTE_NAMESPACE . self::ROUTE);
		$override = apply_filters('qbitflow_wc_webhook_url', defined('QBITFLOW_WC_WEBHOOK_URL') ? constant('QBITFLOW_WC_WEBHOOK_URL') : '');
		if ($override === '' || $override === null || $override === false) {
			return $default;
		}
		if (is_string($override) && preg_match('#^https?://[^\s/?\#]+#i', $override) === 1 && wp_parse_url($override, PHP_URL_HOST)) {
			return $override;
		}
		if (! self::$override_logged) {
			self::$override_logged = true;
			Log::warning('QBITFLOW_WC_WEBHOOK_URL ignored: it must be an absolute http(s) URL.');
		}

		return $default;
	}

	/**
	 * The endpoint registered for `$mode` (default: the API key's).
	 *
	 * @return array{uuid: string, secret: string, mode: string, url: string, space: string, connected_at: int}|null
	 */
	public static function stored(?string $mode = null): ?array
	{
		$mode ??= Settings::mode();
		if ($mode === '') {
			return null;
		}
		$stored = get_option(self::option($mode));

		return is_array($stored) && ! empty($stored['uuid']) && ! empty($stored['secret']) ? $stored : null;
	}

	/**
	 * The secret that verifies deliveries: the connected endpoint's for the key's mode, else
	 * the one pasted in the settings; null when there is none.
	 */
	public static function secret(): ?string
	{
		$stored = self::stored();
		if ($stored !== null) {
			return (string) $stored['secret'];
		}
		$manual = Settings::manual_webhook_secret();

		return $manual !== '' ? $manual : null;
	}

	public static function needs_connect(): bool
	{
		return Settings::key_format() === 'ok' && self::secret() === null;
	}

	/**
	 * Registers this store's endpoint (replacing an endpoint with the same URL) and stores its
	 * secret.
	 *
	 * @return array{uuid: string, secret: string, mode: string, url: string, space: string, connected_at: int}
	 *
	 * @throws RuntimeException With a message for the merchant.
	 */
	public static function connect(): array
	{
		$client = Settings::key_format() === 'ok' ? ClientFactory::make() : null;
		if ($client === null) {
			$format = Settings::key_format();
			if ($format === 'legacy') {
				throw new RuntimeException(esc_html(Notices::legacy_key_message()));
			}
			if ($format === 'invalid') {
				throw new RuntimeException(esc_html__('The API key is not a QBitFlow key (it starts with sk_).', 'qbitflow-for-woocommerce'));
			}
			throw new RuntimeException(esc_html__('Enter and save your QBitFlow API key first.', 'qbitflow-for-woocommerce'));
		}
		$url = self::url();

		try {
			$space = (string) ($client->me()->space?->uuid ?? '');
			$previous = [];
			foreach ($client->webhooks->endpoints->iterate() as $endpoint) {
				if (untrailingslashit($endpoint->url) === untrailingslashit($url)) {
					$previous[] = $endpoint->uuid;
				}
			}
			$site = Text::line(wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES), 180);
			$params = new CreateWebhookEndpointParams(
				url: $url,
				events: self::EVENTS,
				includeMembers: false,
				description: $site !== '' ? 'WooCommerce: ' . $site : 'WooCommerce',
			);
			try {
				$created = $client->webhooks->endpoints->create($params);
			} catch (ConflictException $e) {
				// The space is at its endpoint limit (10 per space and mode, 409 `conflict`): free the
				// slots of this store's own endpoint(s) (the exact same URL, never another
				// integration's), then create once more. Without one of ours, the API's text is shown.
				if ($e->getApiCode() !== 'conflict' || $previous === []) {
					throw $e;
				}
				foreach ($previous as $uuid) {
					$client->webhooks->endpoints->delete($uuid);
				}
				$previous = [];
				$created = $client->webhooks->endpoints->create($params);
			}
		} catch (PermissionDeniedException $e) {
			throw new RuntimeException($e->getApiCode() === 'policy_disabled'
				? esc_html__('This API key is not allowed to manage webhooks (your organization turned off members.webhooks). Create the endpoint in the QBitFlow dashboard with the URL shown here, then paste its signing secret in "Webhook signing secret".', 'qbitflow-for-woocommerce')
				// Another refusal (a role): the API's own text says who may.
				: esc_html($e->getErrorMessage() !== '' ? $e->getErrorMessage() : __('This API key is not allowed to manage webhooks.', 'qbitflow-for-woocommerce')), 0, $e);
		} catch (QBitFlowException $e) {
			throw new RuntimeException(sprintf(
				/* translators: %s: the error QBitFlow returned */
				esc_html__('QBitFlow could not register the webhook: %s', 'qbitflow-for-woocommerce'),
				esc_html($e->getErrorMessage() !== '' ? $e->getErrorMessage() : $e->getMessage())
			), 0, $e);
		}

		// The new endpoint is stored before the old ones go: a failure never leaves the store
		// without a working webhook.
		$mode = $created->test ? 'test' : 'live';
		$stored = [
			'uuid' => $created->uuid,
			'secret' => $created->secret,
			'mode' => $mode,
			'url' => $created->url,
			'space' => $space,
			'connected_at' => time(),
		];
		update_option(self::option($mode), $stored, false);

		foreach (array_diff($previous, [$created->uuid]) as $uuid) {
			try {
				$client->webhooks->endpoints->delete($uuid);
			} catch (QBitFlowException $e) {
				Log::warning(sprintf('Old webhook endpoint %s not deleted (%s): delete it in the QBitFlow dashboard.', $uuid, $e->getMessage()));
			}
		}

		return $stored;
	}

	/** Forgets both modes' endpoints (uninstall). */
	public static function forget(): void
	{
		delete_option(self::option('test'));
		delete_option(self::option('live'));
	}

	private static function option(string $mode): string
	{
		return 'qbitflow_wc_webhook_' . $mode;
	}
}
// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
