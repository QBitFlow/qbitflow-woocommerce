<?php

declare(strict_types=1);

namespace QBitFlowWC\Admin;

use QBitFlowWC\Checkout\AcceptedCoins;
use QBitFlowWC\Checkout\SessionManager;
use QBitFlowWC\ClientFactory;
use QBitFlowWC\Currency;
use QBitFlowWC\Gateway;
use QBitFlowWC\Security\PhpSecurity;
use QBitFlowWC\Security\SiteHealth;
use QBitFlowWC\Settings;
use QBitFlowWC\Support\ApiErrors;
use QBitFlowWC\Vendor\QBitFlow\Exceptions\NotFoundException;
use QBitFlowWC\Vendor\QBitFlow\Exceptions\QBitFlowException;
use QBitFlowWC\Vendor\QBitFlow\QBitFlow;
use QBitFlowWC\Webhooks\EndpointManager;

defined('ABSPATH') || exit;

/**
 * The connection panel on the gateway's settings screen: which QBitFlow account and mode the
 * key opens, what the store's wallets accept, the webhook's state, the store currency and the
 * PHP security check. Read from the API at most every 10 minutes (or on "Refresh").
 */
final class StatusPanel
{
	public const TRANSIENT = 'qbitflow_wc_status';

	/** Set for 10 minutes when QBitFlow answers that the store's wallets accept nothing. */
	public const NOT_READY_TRANSIENT = 'qbitflow_wc_not_ready';

	/** Set for 10 minutes when QBitFlow answers that this member's key may not open checkouts. */
	public const CHECKOUTS_OFF_TRANSIENT = 'qbitflow_wc_checkouts_off';

	/** The organization's policy that lets its members open their own checkouts. */
	public const CHECKOUTS_POLICY = 'members.products';

	/** A checkout id that never exists: reading it tells the policy apart (403) from the lookup (404). */
	private const NO_CHECKOUT = '00000000-0000-0000-0000-000000000000';

	/** Whether the key is a member's: kept without expiry (a key's space never changes), tagged with the key's hash, not autoloaded. */
	public const MEMBER_OPTION = 'qbitflow_wc_member';

	private const TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Whether the key belongs to an organization's member: true or false once known, null when
	 * not (no key, or never asked). Cached; with `$resolve` one `me()` call settles it (only on
	 * paths that call the API anyway, never while rendering a page); null again when that fails.
	 */
	public static function member(bool $resolve = false): ?bool
	{
		$key = self::key_hash();
		$cached = get_option(self::MEMBER_OPTION);
		if (is_array($cached) && ($cached['key'] ?? '') === $key && is_bool($cached['member'] ?? null)) {
			return $cached['member'];
		}
		$status = get_transient(self::TRANSIENT);
		if (is_array($status) && ($status['key'] ?? '') === $key && ($status['connected'] ?? false) && ($status['space'] ?? null) !== null) {
			return (bool) $status['member'];
		}
		if (! $resolve) {
			return null;
		}

		$client = ClientFactory::make(10.0, 0);
		if ($client === null) {
			return null;
		}
		try {
			$space = $client->me()->space;
		} catch (QBitFlowException) {
			return null;
		}
		if ($space === null) {
			return null; // No space in the answer: unknown, not "organization".
		}
		$member = $space->userUuid !== null;
		self::remember_member($key, $member);

		return $member;
	}

	private static function key_hash(): string
	{
		return substr(hash('sha256', Settings::api_key()), 0, 16);
	}

	private static function remember_member(string $key, bool $member): void
	{
		update_option(self::MEMBER_OPTION, ['key' => $key, 'member' => $member], false);
	}

	/**
	 * @return array{connected: bool, error: ?string, organization: ?string, member: bool, test: ?bool, space: ?string, currencies: list<string>, webhook: ?array<string, mixed>, key: string, checked_at: int}
	 */
	public static function data(bool $refresh = false): array
	{
		$key = self::key_hash();
		$cached = get_transient(self::TRANSIENT);
		if (! $refresh && is_array($cached) && ($cached['key'] ?? '') === $key) {
			return $cached;
		}

		$data = [
			'connected' => false,
			'error' => null,
			'organization' => null,
			'member' => false,
			'test' => null,
			'space' => null,
			'currencies' => [],
			'webhook' => null,
			'key' => $key,
			'checked_at' => time(),
		];

		$client = ClientFactory::make(10.0, 0);
		if ($client === null) {
			$data['error'] = match (Settings::key_format()) {
				'empty' => __('No API key yet.', 'qbitflow-for-woocommerce'),
				'legacy' => Notices::legacy_key_message(),
				default => __('The API key is not a QBitFlow key (it starts with sk_).', 'qbitflow-for-woocommerce'),
			};
			set_transient(self::TRANSIENT, $data, self::TTL);

			return $data;
		}

		try {
			$me = $client->me();
			$data['connected'] = true;
			$data['organization'] = $me->space?->organizationName;
			$data['member'] = $me->space?->userUuid !== null;
			if ($me->space !== null) {
				self::remember_member($key, $data['member']);
			}
			$data['test'] = $me->space?->test;
			$data['space'] = $me->space?->uuid;

			$accepted = $client->wallets->listAcceptedCurrencies();
			AcceptedCoins::store($accepted);
			foreach ($accepted as $currency) {
				$data['currencies'][] = $currency->symbol . ' (' . $currency->chain . ')';
			}
			if ($data['currencies'] === []) {
				set_transient(self::NOT_READY_TRANSIENT, 1, self::TTL);
			} else {
				delete_transient(self::NOT_READY_TRANSIENT);
			}
			if ($data['member']) {
				self::check_checkouts_policy($client);
			}

			$stored = EndpointManager::stored();
			if ($stored !== null) {
				try {
					$endpoint = $client->webhooks->endpoints->get($stored['uuid']);
					$data['webhook'] = [
						'state' => $endpoint->disabledAt !== null ? 'disabled' : ($endpoint->failingSince !== null ? 'failing' : 'ok'),
						'url' => $endpoint->url,
						'url_matches' => untrailingslashit($endpoint->url) === untrailingslashit(EndpointManager::url()),
						'space_matches' => $stored['space'] === '' || $stored['space'] === $data['space'],
						'disabled_reason' => $endpoint->disabledReason,
						'failing_since' => $endpoint->failingSince?->getTimestamp(),
						'last_delivered_at' => $endpoint->lastDeliveredAt?->getTimestamp(),
					];
				} catch (NotFoundException) {
					$data['webhook'] = ['state' => 'missing'];
				}
			}
		} catch (QBitFlowException $e) {
			$data['error'] = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : $e->getMessage();
		}

		set_transient(self::TRANSIENT, $data, self::TTL);

		return $data;
	}

	/**
	 * A member's key: whether its organization lets it open checkouts. QBitFlow checks the policy
	 * before looking the checkout up, so reading one that doesn't exist answers 403 while the
	 * policy is off and 404 otherwise.
	 */
	private static function check_checkouts_policy(QBitFlow $client): void
	{
		try {
			$client->checkoutSessions->get(self::NO_CHECKOUT);
		} catch (NotFoundException) {
			delete_transient(self::CHECKOUTS_OFF_TRANSIENT);
		} catch (QBitFlowException $e) {
			if (ApiErrors::policy_off($e, self::CHECKOUTS_POLICY)) {
				set_transient(self::CHECKOUTS_OFF_TRANSIENT, 1, self::TTL);
			}
		}
	}

	/**
	 * True only when QBitFlow said so recently (no accepted currency, or a member's checkouts
	 * turned off): never an API call (used at checkout).
	 */
	public static function known_not_ready(): bool
	{
		return (bool) get_transient(self::NOT_READY_TRANSIENT) || self::checkouts_off();
	}

	/** True while QBitFlow said recently that the organization turned off this member's checkouts. */
	public static function checkouts_off(): bool
	{
		return (bool) get_transient(self::CHECKOUTS_OFF_TRANSIENT);
	}

	public static function forget(): void
	{
		delete_transient(self::TRANSIENT);
		delete_option(self::MEMBER_OPTION);
	}

	public static function render(): void
	{
		$data = self::data();
		$rows = [];

		// Account.
		if ($data['connected']) {
			$account = esc_html((string) $data['organization']);
			$account .= ' — ' . esc_html($data['member'] ? __('member space', 'qbitflow-for-woocommerce') : __('organization space', 'qbitflow-for-woocommerce'));
			$account .= ' — ' . esc_html($data['test'] ? __('test mode', 'qbitflow-for-woocommerce') : __('live mode', 'qbitflow-for-woocommerce'));
			$rows[] = [__('Account', 'qbitflow-for-woocommerce'), 'ok', $account];
		} else {
			$rows[] = [__('Account', 'qbitflow-for-woocommerce'), 'error', esc_html((string) $data['error'])];
		}

		// Accepted currencies.
		if ($data['connected']) {
			$rows[] = $data['currencies'] !== []
				? [__('Accepted', 'qbitflow-for-woocommerce'), 'ok', esc_html(implode(', ', $data['currencies']))]
				: [__('Accepted', 'qbitflow-for-woocommerce'), 'error', esc_html__('Your wallets accept no currency yet: add a wallet in the QBitFlow dashboard. Until then QBitFlow is hidden at checkout.', 'qbitflow-for-woocommerce')];
		}

		// Checkouts turned off by the organization (known from the status check or a checkout).
		if (self::checkouts_off()) {
			$rows[] = [__('Checkouts', 'qbitflow-for-woocommerce'), 'error', esc_html(self::checkouts_off_message())];
		}

		// Return addresses turned off by the organization (known from a recent checkout).
		if (SessionManager::redirects_off()) {
			$rows[] = [__('Return addresses', 'qbitflow-for-woocommerce'), 'warning', esc_html(self::redirects_off_message())];
		}

		// Webhook.
		$rows[] = [__('Webhook', 'qbitflow-for-woocommerce'), ...self::webhook_row($data)];

		// Store currency.
		$currency = (string) get_woocommerce_currency();
		$rows[] = Currency::usd_rate() !== null
			? [__('Currency', 'qbitflow-for-woocommerce'), 'ok', esc_html($currency === 'USD' ? 'USD' : sprintf(
				/* translators: %s: store currency code */
				__('%s, converted to USD by your qbitflow_wc_usd_rate filter', 'qbitflow-for-woocommerce'),
				$currency
			))]
			: [__('Currency', 'qbitflow-for-woocommerce'), 'error', esc_html(sprintf(
				/* translators: %s: store currency code */
				__('QBitFlow prices in USD and your store uses %s: QBitFlow stays hidden at checkout until a USD rate is provided through the qbitflow_wc_usd_rate filter.', 'qbitflow-for-woocommerce'),
				$currency
			))];

		// PHP.
		$php = PhpSecurity::current();
		$summary = SiteHealth::summary($php);
		$rows[] = [
			__('PHP', 'qbitflow-for-woocommerce'),
			$php['status'] === 'good' ? 'ok' : ($php['status'] === 'critical' ? 'error' : 'warning'),
			esc_html($summary['lines'][0]) . ($php['status'] === 'good' ? '' : ' <a href="' . esc_url(admin_url('site-health.php')) . '">' . esc_html__('Site Health', 'qbitflow-for-woocommerce') . '</a>'),
		];

		$colors = ['ok' => '#28a745', 'warning' => '#dba617', 'error' => '#d63638'];
		echo '<table class="widefat striped" style="max-width:900px;margin:12px 0"><tbody>';
		foreach ($rows as [$label, $state, $html]) {
			printf(
				'<tr><th style="width:120px">%s</th><td><span style="color:%s;font-weight:600">●</span> %s</td></tr>',
				esc_html($label),
				esc_attr($colors[$state] ?? '#666'),
				wp_kses_post($html) // Built escaped above, piece by piece.
			);
		}
		echo '</tbody></table>';

		printf(
			'<p><a class="button" href="%s">%s</a> <span class="description">%s</span></p>',
			esc_url(Actions::url(Actions::REFRESH_STATUS)),
			esc_html__('Refresh', 'qbitflow-for-woocommerce'),
			esc_html(sprintf(
				/* translators: %s: how long ago the status was read */
				__('Checked %s ago.', 'qbitflow-for-woocommerce'),
				human_time_diff($data['checked_at'])
			))
		);
	}

	public static function checkouts_off_message(): string
	{
		return __('Your organization turned off its members\' own checkouts (the members.products policy): QBitFlow is hidden at checkout until it turns them back on.', 'qbitflow-for-woocommerce');
	}

	public static function redirects_off_message(): string
	{
		return __('Your organization turned off return addresses for members: customers land on the organization\'s page after paying. Orders still complete.', 'qbitflow-for-woocommerce');
	}

	/** @return array{0: string, 1: string} State and escaped HTML. */
	private static function webhook_row(array $data): array
	{
		$connect = sprintf(
			' <a class="button button-primary" href="%s">%s</a>',
			esc_url(Actions::url(Actions::CONNECT_WEBHOOK)),
			esc_html__('Connect webhook', 'qbitflow-for-woocommerce')
		);
		$url = '<br><code style="user-select:all">' . esc_html(EndpointManager::url()) . '</code>';
		$webhook = $data['webhook'];

		if (EndpointManager::stored() === null) {
			if (Settings::manual_webhook_secret() !== '') {
				return ['ok', esc_html__('Using the signing secret entered below.', 'qbitflow-for-woocommerce') . $url];
			}

			return ['error', esc_html__('Not connected: QBitFlow cannot confirm payments to this store yet.', 'qbitflow-for-woocommerce') . $connect . $url];
		}
		if ($webhook === null) {
			return ['ok', esc_html__('Connected.', 'qbitflow-for-woocommerce') . $url];
		}
		if ($webhook['state'] === 'missing') {
			return ['error', esc_html__('The endpoint was deleted in QBitFlow.', 'qbitflow-for-woocommerce') . $connect . $url];
		}
		if (! $webhook['url_matches'] || ! $webhook['space_matches']) {
			return ['error', esc_html__('Connected to another site address or QBitFlow account: connect again.', 'qbitflow-for-woocommerce') . $connect . $url];
		}
		if ($webhook['state'] === 'disabled') {
			return ['error', esc_html(sprintf(
				/* translators: %s: why QBitFlow disabled the endpoint */
				__('Disabled by QBitFlow (%s): payments are not confirmed automatically. Fix the cause, then connect again.', 'qbitflow-for-woocommerce'),
				(string) $webhook['disabled_reason']
			)) . $connect . $url];
		}
		$last = $webhook['last_delivered_at'] !== null
			? sprintf(
				/* translators: %s: time since the last successful delivery */
				__('last delivery %s ago', 'qbitflow-for-woocommerce'),
				human_time_diff((int) $webhook['last_delivered_at'])
			)
			: __('no delivery yet', 'qbitflow-for-woocommerce');
		if ($webhook['state'] === 'failing') {
			return ['warning', esc_html(sprintf(
				/* translators: 1: time since deliveries started failing, 2: last delivery */
				__('Deliveries are failing since %1$s ago (%2$s). Live endpoints are disabled after 3 days of failures: check that this site is reachable.', 'qbitflow-for-woocommerce'),
				human_time_diff((int) $webhook['failing_since']),
				$last
			)) . $url];
		}

		return ['ok', esc_html(sprintf(
			/* translators: %s: last delivery */
			__('Connected (%s).', 'qbitflow-for-woocommerce'),
			$last
		)) . $url];
	}
}
