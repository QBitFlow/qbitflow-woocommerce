<?php

declare(strict_types=1);

namespace QBitFlowWC\Admin;

use QBitFlowWC\Checkout\SessionManager;
use QBitFlowWC\Settings;

defined('ABSPATH') || exit;

/**
 * One-shot admin notices for the current user (the result of an admin action, shown after its
 * redirect).
 */
final class Notices
{
	private const PREFIX = 'qbitflow_wc_notice_';

	public static function init(): void
	{
		add_action('admin_notices', [self::class, 'render']);
		add_action('admin_notices', [self::class, 'render_key_notice']);
		add_action('admin_notices', [self::class, 'render_redirects_notice']);
		add_action('admin_notices', [self::class, 'render_checkouts_notice']);
	}

	public static function add(string $message, string $type = 'success'): void
	{
		set_transient(self::PREFIX . get_current_user_id(), ['message' => $message, 'type' => $type], 5 * MINUTE_IN_SECONDS);
	}

	/** @return array{message: string, type: string}|null */
	public static function take(): ?array
	{
		$key = self::PREFIX . get_current_user_id();
		$notice = get_transient($key);
		if (! is_array($notice)) {
			return null;
		}
		delete_transient($key);

		return $notice;
	}

	public static function render(): void
	{
		$notice = self::take();
		if ($notice === null) {
			return;
		}
		$type = in_array($notice['type'], ['success', 'error', 'warning', 'info'], true) ? $notice['type'] : 'info';
		printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), wp_kses_post($notice['message']));
	}

	/** Admins only, never customers: the organization turned off members' return addresses. */
	public static function render_redirects_notice(): void
	{
		self::render_policy_notice(SessionManager::redirects_off(), 'warning', StatusPanel::redirects_off_message());
	}

	/** Admins only, never customers: the organization turned off its members' own checkouts. */
	public static function render_checkouts_notice(): void
	{
		self::render_policy_notice(StatusPanel::checkouts_off(), 'error', StatusPanel::checkouts_off_message());
	}

	/** A notice about one of the organization's member policies, while it applies. */
	private static function render_policy_notice(bool $applies, string $type, string $message): void
	{
		if (! $applies || ! current_user_can('manage_woocommerce')) {
			return;
		}
		printf('<div class="notice notice-%s"><p>%s</p></div>', esc_attr($type), esc_html($message));
	}

	public static function legacy_key_message(): string
	{
		return __('This API key uses the old format; create a new key in the QBitFlow dashboard.', 'qbitflow-for-woocommerce');
	}

	/** On the QBitFlow settings screen: a key in the old format (or not a key) is called out. */
	public static function render_key_notice(): void
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading where we are, not acting.
		if (sanitize_key((string) ($_GET['section'] ?? '')) !== 'qbitflow' || ! current_user_can('manage_woocommerce')) {
			return;
		}
		$format = Settings::key_format();
		if ($format === 'legacy') {
			printf('<div class="notice notice-error"><p>%s</p></div>', esc_html(self::legacy_key_message()));
		} elseif ($format === 'invalid') {
			printf('<div class="notice notice-error"><p>%s</p></div>', esc_html__('The API key is not a QBitFlow key (it starts with sk_).', 'qbitflow-for-woocommerce'));
		}
	}
}
