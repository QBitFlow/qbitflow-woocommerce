# Changelog

All notable changes to this project will be documented in this file.

## [1.1.1] - 2026-07-23

### Added
- `Requires Plugins: woocommerce` header so WordPress enforces the WooCommerce dependency before the plugin can be activated (WP 6.5+)
- `build.sh` + `.distignore` build pipeline that produces a clean distributable ZIP from a single source of truth

### Changed
- The checkout payment method description is now an editable **Description** setting (default: "Pay with crypto — funds go straight to your wallet, non-custodial and secure.") instead of a hardcoded string

### Removed
- Directory assets and development-only files (`.wordpress-org/`, raw `screenshots/`, dev docs) are no longer included in the distributed plugin ZIP

## [1.1.0] - 2026-05-08

### Added
- Admin notice when pending refund requests exist, with a direct link to `qbitflow.app/refunds`
- Refund request details (status, amount, reason, note) displayed in admin order meta box
- `address` field support for customer sync — the WC billing address is flattened into a single string and sent to QBitFlow's free-text `address` field (the structured address stays in WC for shipping/invoicing)
- Find-or-create customer flow at checkout: every payment session now carries a `customerUUID`, looked up via `GET /customer/email/{email}` and only created when missing — the QBitFlow hosted checkout no longer re-prompts the buyer for details WooCommerce already collected
- `QBitFlow_API::get_customer_by_email()` and `QBitFlow_API::find_or_create_customer()` helpers
- `QBitFlow_Customer_Sync::ensure_uuid_for_order()` — guarantees a UUID is attached to an order before payment session creation, with a fallback that fires from `process_payment()` when the earlier `woocommerce_checkout_order_created` sync was skipped or failed

### Changed
- Customer identity is now keyed on the **order's billing email**, not the WP account that placed the order. Previously the plugin synced WP users to QBitFlow on `user_register`/`profile_update` and cached the resulting UUID in user meta — which meant a buyer's real billing details (entered at checkout) never reached QBitFlow if the WP account had already been linked to a placeholder customer record. Removed both eager hooks; sync now happens exclusively at `woocommerce_checkout_order_created` (and again as a `process_payment` fallback) using the order's billing fields as the authoritative source.
- Added a persistent **email → UUID** map (`qbitflow_cuuid_<md5>` wp_options, autoload disabled). `ensure_uuid_for_order` now looks up the QBitFlow UUID by the order's current billing email on every call, so a single WP account placing two orders with two different emails gets two distinct customer records (and a buyer who corrects their email mid-checkout doesn't end up sending the previous email's UUID). Order meta is now a derived denormalisation rather than the source of truth. Cleaned up on uninstall.
- Refund data is now refetched on every admin page load — refund handling happens outside WordPress, so the previous 5-minute transient cache could show stale state. Both the pending-refunds admin notice and the per-order refund block in the meta box now reflect the live state on QBitFlow.
- Refund display fields aligned with the QBitFlow `RefundEntry` schema: `status` (pending/approved/refused/failed), `reason`, `merchantMessage` (shown as the merchant's response when the refund was refused/failed), `txHash` (the on-chain refund hash, shown when the refund is approved), and `respondedAt`. Removed the bogus `amount` and `message` fields that didn't exist in the schema.

### Added
- Auto-mark order as **Refunded** in WooCommerce when QBitFlow reports the refund as `approved` with a non-empty `txHash`. Triggers `wc_create_refund()` for the full order total (only full refunds are supported), suppresses the gateway re-refund (`refund_payment: false`), persists the refund tx hash on the order, and adds an order note. Idempotent — won't refund twice on subsequent page loads.
- Snapshot terminal-state refunds on the order (`_qbitflow_refund_snapshot` meta). Once a refund reaches `approved`, `refused`, or `failed` it can't change again, so we persist the full record on the order and stop re-fetching it on every admin view. Pending refunds still always refetch — those *can* change.
- `uninstall.php` to clean up plugin options and user meta on deletion
- `languages/qbitflow-for-woocommerce.pot` translation template
- `.wordpress-org/` directory assets (banners, icons, and 6 listing screenshots)
- `Tested up to: 7.0` to plugin header
- Inline comment on the webhook `permission_callback` documenting that signature verification is the auth boundary
- Plugin Check pass — all errors resolved (translators comments on every placeholder, escape-output on the plain-text email branch, refactored webhook order lookup to `meta_query`); remaining structural warnings annotated with justified `phpcs:ignore` comments

### Changed (slug)
- Plugin slug renamed from `qbitflow-woocommerce` to **`qbitflow-for-woocommerce`** to comply with the WordPress.org "WooCommerce" trademark policy (allowed patterns are `for/with/using/and woocommerce`). The plugin folder, main PHP file, text domain, and `.pot` filename were all updated. Pre-existing settings option name (`woocommerce_qbitflow_settings`) and gateway ID (`qbitflow`) are unchanged, so existing installs preserve their API key and order history.

### Fixed
- Updated payment session endpoint to `/transaction/session-checkout/new/payment`
- Fixed `customerUUID` field casing in payment session body
- Fixed undefined `$order_id` in webhook handler when order found via `wc_get_orders()` (could silently fail stock reduction)
- Fixed potential fatal error when `WC()->cart` is null during REST webhook processing
- Removed no-op `$phone = $phone` assignment in customer sync
- `WC tested up to` synced to 9.7 (plugin header now matches readme.txt)

### Changed
- License standardized to GPL-2.0-or-later across `LICENSE`, plugin header, `readme.txt`, and `README.md` (resolves prior MPL/GPL declaration mismatch — required for WordPress.org compatibility)

## [1.0.0] - 2026-03-23

### Added
- One-time crypto payments via QBitFlow
- WooCommerce block checkout support
- Classic shortcode checkout support
- Automatic customer sync to QBitFlow
- Webhook signature verification via QBitFlow API
- Invoice and management page links for customers
- Admin order meta box with payment status, tx hash, and links
- "View Invoice" button on My Account → Orders
- Email instructions with transaction hash and invoice link
- Debug logging support
- HPOS (High-Performance Order Storage) compatibility