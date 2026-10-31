# Changelog

All notable changes to this project will be documented in this file.

## [2.0.0] - 2026-10-31

Moves the plugin to QBitFlow API v2 through the official PHP SDK v3. Requires PHP 8.2,
WordPress 6.4 and WooCommerce 8.0.

### Added
- The QBitFlow PHP SDK v3 (3.1.1), bundled and namespace-prefixed (Strauss, `QBitFlowWC\Vendor\`), on a PSR-18 adapter over the WordPress HTTP API (no redirects, TLS always verified)
- Itemized checkout: shipping, taxes (per rate, e.g. "VAT (20%)") and fees as checkout fee lines, at most 10, always summing to the order total (amounts in integer cents)
- Processing fee setting with three choices: "Use my QBitFlow setting" (default, `fees.processingFee` omitted), "Customer pays" (`true`) and "Store pays" (`false`); the fee paid is recorded on the order; a member key always follows its organization. A 1.x "customer pays" setting migrates to "Customer pays", anything else to "Use my QBitFlow setting"
- Accepted coins under the payment method at checkout (classic and block), read from a one-hour cache and never fetched during a customer request
- Failed payment attempts noted on the order (meta box and order notes)
- Orders list: Crypto column and QBitFlow filter (HPOS and posts storage)
- Accounting export (CSV or JSON, any range of days, ranges over 92 days fetched in windows, whole account) in the settings and as `wp qbitflow export`
- WP-CLI: `wp qbitflow status`, `connect-webhook`, `reconcile`, `export`
- New API key format `sk_<live|test>_<uuid>_<secret>`; the old format is called out in the settings
- Member keys and the `members.checkoutRedirects` policy: on `403 policy_disabled` one retry without return addresses (idempotency key suffixed `-nr`), remembered for an hour, with an admin notice
- Member keys and the `members.products` policy: while the organization keeps its members from opening their own checkouts (`403 policy_disabled`, learned from a checkout or from the status check), QBitFlow is hidden at checkout and the settings and an admin notice say why
- Release gate `bin/release-check.sh` (`composer release-check`): lint on the minimum PHP, unit and integration suites, build, ZIP checks and Plugin Check
- "Connect webhook": registers the store's endpoint (`payment.completed`, `checkout.expired`, `refund.*`) and stores its secret per mode; manual secret field for keys without the `members.webhooks` policy
- Webhook receiver at `/wp-json/qbitflow-wc/v2/webhook`: local signature verification, 1 MiB cap before reading, 200 for anything not this store's, per-order event deduplication, 500 (retry) on handler errors
- Reconciliation: the return page and an Action Scheduler job (every 15 min, orders of the last 48 h) read the checkout and settle the order
- Refunds from webhooks: requests noted and listed (hourly resync), approved refunds recorded as WooCommerce refunds of `refundPercent` of the order total (rounded to the cent, capped to the remaining amount), rejections noted
- Settings status panel: account, organization or member space, mode, accepted currencies, webhook health, store currency, PHP security
- Site Health test for php2shell (CVE-2026-109618, CVE-2026-109619)
- Order meta box from local meta, with explorer links, refunds and "Refresh from QBitFlow"
- Filters `qbitflow_wc_usd_rate`, `qbitflow_wc_client_options`, `qbitflow_wc_http_request_args`; action `qbitflow_wc_webhook_event`; constant `QBITFLOW_WC_API_BASE_URL`
- Unit suite and integration suite (wp-env, WooCommerce, HPOS, fake API, signed deliveries)

### Changed
- Checkout sessions carry `reference` `wc-<store id>-<order id>`, metadata `{source, store_id, order_id}` and an `Idempotency-Key`; one payable checkout per order (reused, or expired when the amounts change; never reused for changed amounts while a payment is in flight)
- Store text the API refuses (`" ; | [ ] { } < > ~ ^ \` and control characters) is replaced by look-alikes in names, descriptions, fee labels and customer details
- Webhook, return page and background check never apply a payment twice at once (per-order lock); a replaced checkout expiring never fails the order
- The store id follows the site address and each order carries a random token in its checkout metadata, so a staging copy sharing the account can't complete production orders
- An order is marked paid only when the received amount covers the order (on hold otherwise, also when the order grew or changed currency since its checkout)
- A failed attempt no longer fails the order; an expired checkout fails it (retryable from My Account); cancelling an order expires its checkout
- Customers are found or created by reference `wc-<sha256(email)>`; the 1.x email map is removed on upgrade
- The gateway no longer declares `refunds` support (crypto refunds are signed in the QBitFlow dashboard)

- The gateway icon is shown at a fixed size (block themes don't limit it)
- The accounting export download discards output buffers first, never sends a wrong `Content-Length` and refuses to start when output was already sent; its script is an enqueued admin script and its control a real button

### Removed
- The hand-written v1 API client, the remote `/webhooks/verify` call per delivery, the "Sync refund" button and the per-page refund lookups

### Fixed
- An order the return page or the background check completed before the webhook arrived never got its payment page link (receipt, invoice, refund request): the webhook now adds it
- An approved refund is recorded under the order's lock: a delivery QBitFlow retries while the first is still running no longer records it twice
- Stores not priced in USD were charged their total as US dollars: QBitFlow is now hidden for them unless `qbitflow_wc_usd_rate` provides a rate
- Webhooks for unknown orders answered 404, making QBitFlow retry and eventually disable the endpoint

## [1.1.5] - 2026-07-30
- Updating Screenshots
- Ready for publishing

## [1.1.2] - 2026-07-25

### Added
- Test/Live mode badge on the settings screen, derived from the API key format (`sk_<id>_<live|test>_<secret>`), with a note that test mode runs on blockchain testnets using faucet funds (no real cryptocurrency spent)
- Webhook registration reminder on the settings screen showing the store's webhook URL and pointing to the matching Test/Live section in the QBitFlow dashboard
- "External services" section in the readme documenting the QBitFlow API: what data is sent and when, plus Terms of Service and Privacy Policy links (WordPress.org requirement)
- Explicit, nonce-protected, capability-gated (`edit_shop_orders`) `admin_post_qbitflow_apply_refund` handler that mirrors an approved on-chain refund into WooCommerce (creates the full refund via `wc_create_refund()`, marks the order Refunded, records the refund tx hash, and adds an order note)
- "Sync refund to WooCommerce" button, rendered as an admin notice at the top of the order screen, shown only when QBitFlow reports a settled refund WooCommerce hasn't recorded yet; the QBitFlow meta box always shows the current refund status

### Changed
- The admin order screen refund sync is now strictly read-only — no order meta is written and no WooCommerce refund is created on page load (previously done on a GET request without a nonce). All state changes now require the explicit sync action
- Refund status lookups on the order screen are cached in a short-lived transient to avoid repeated API calls on screen refreshes

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