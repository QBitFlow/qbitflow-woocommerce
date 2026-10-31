<?php

declare(strict_types=1);

namespace QBitFlowWC\Orders;

use QBitFlowWC\Checkout\AcceptedCoins;
use QBitFlowWC\Refunds\RefundSync;
use QBitFlowWC\Settings;

defined('ABSPATH') || exit;

/**
 * Background checks (Action Scheduler, bundled with WooCommerce), so orders settle even when a
 * webhook never arrives (endpoint not connected, site briefly down):
 *
 * - every 15 minutes, unpaid QBitFlow orders with a checkout touched in the last 48 hours read it;
 * - every hour, the pending refund requests are listed again.
 */
final class Reconciler
{
	public const HOOK = 'qbitflow_wc_reconcile';

	public const GROUP = 'qbitflow';

	public const INTERVAL = 15 * MINUTE_IN_SECONDS;

	public static function init(): void
	{
		add_action(self::HOOK, [self::class, 'run']);
		AcceptedCoins::init();
		add_action('action_scheduler_init', [self::class, 'schedule']);
	}

	public static function schedule(): void
	{
		if (! function_exists('as_has_scheduled_action')) {
			return;
		}
		if (! as_has_scheduled_action(self::HOOK, [], self::GROUP)) {
			as_schedule_recurring_action(time() + 5 * MINUTE_IN_SECONDS, self::INTERVAL, self::HOOK, [], self::GROUP, true);
		}
		if (! as_has_scheduled_action(RefundSync::SYNC_HOOK, [], self::GROUP)) {
			as_schedule_recurring_action(time() + 10 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS, RefundSync::SYNC_HOOK, [], self::GROUP, true);
		}
	}

	/** Every job of the plugin, a queued accepted-coins refresh included (deactivation: none runs without it). */
	public static function unschedule(): void
	{
		if (function_exists('as_unschedule_all_actions')) {
			as_unschedule_all_actions(self::HOOK, [], self::GROUP);
			as_unschedule_all_actions(RefundSync::SYNC_HOOK, [], self::GROUP);
			as_unschedule_all_actions(AcceptedCoins::REFRESH_HOOK, [], self::GROUP);
		}
	}

	public static function run(): void
	{
		if (Settings::key_format() !== 'ok') {
			return;
		}
		foreach (OrderFinder::unpaid_with_session() as $order) {
			PaymentSync::refresh($order, __('background check', 'qbitflow-for-woocommerce'));
		}
	}
}
