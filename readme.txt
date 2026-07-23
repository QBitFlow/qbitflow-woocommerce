=== QBitFlow for WooCommerce ===
Contributors: qbitflow
Tags: cryptocurrency, payments, ethereum, solana, web3
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.1
WC requires at least: 7.0
WC tested up to: 9.7
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept cryptocurrency payments in WooCommerce via QBitFlow. Non-custodial — funds go directly to your wallet via smart contracts.

== Description ==

**QBitFlow for WooCommerce** lets you accept cryptocurrency payments directly in your WooCommerce store. Powered by smart contracts, payments go straight to your wallet — no middleman, no custody risk.

= Features =

* **Non-Custodial** — Funds go directly to your wallet via smart contracts
* **Multiple Cryptocurrencies** — ETH, SOL, USDC, USDT, and more
* **Block Checkout Support** — Works with both classic and block-based checkout
* **Automatic Customer Sync** — Buyer details (email, name, phone, billing address) sync to QBitFlow at checkout, so the QBitFlow page never re-prompts for what WooCommerce already has
* **Invoice & Management Pages** — Hosted payment details pages for customers
* **Webhook Verified** — Server-to-server payment confirmation with HMAC signature verification
* **Real-time Order Updates** — Order status updates automatically on payment
* **Refunds, end-to-end** — Pending refund requests surface as an admin notice; once you approve a refund on the QBitFlow dashboard and the on-chain refund settles, the WooCommerce order is auto-marked **Refunded** with the refund tx hash recorded
* **Test Mode** — Try the full payment flow without spending any real cryptocurrency
* **Debug Logging** — Built-in logging for troubleshooting

= How It Works =

1. Customer selects "Pay with Crypto" at checkout
2. Customer is redirected to QBitFlow's secure payment page
3. Customer pays with their preferred cryptocurrency
4. Payment is confirmed on-chain and order is updated automatically
5. Customer receives invoice with transaction details

= Test Mode — Try Before Going Live =

QBitFlow provides a test mode that lets you simulate the entire payment flow — checkout, on-chain confirmation, webhooks, order updates — without spending any real cryptocurrency.

1. Create a **Test API Key** from your [QBitFlow dashboard](https://qbitflow.app)
2. Paste it into the plugin settings at WooCommerce → Settings → Payments → QBitFlow
3. Place a test order — everything works exactly like production, but no real funds are moved

When you're ready to accept real payments, simply replace the test key with a **Live API Key** and save.

For more details, see the [QBitFlow Test Mode Documentation](https://qbitflow.app/docs?section=test-mode).

= Requirements =

* WooCommerce 7.0 or later
* PHP 7.4 or later
* A QBitFlow account ([sign up free](https://qbitflow.app))

== Installation ==

1. Upload the `qbitflow-for-woocommerce` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce → Settings → Payments → QBitFlow
4. Enter your API key from your [QBitFlow dashboard](https://qbitflow.app)
5. Enable the payment method and save

== Frequently Asked Questions ==

= Where do I get an API key? =

Sign up at [qbitflow.app](https://qbitflow.app) and generate an API key from your dashboard.

= Which cryptocurrencies are supported? =

ETH, SOL, USDC, USDT, and more. Check the QBitFlow dashboard for the full list.

= Is this custodial? =

No. QBitFlow is fully non-custodial. Payments go directly to your wallet via smart contracts.

= Does it work with the new block checkout? =

Yes. The plugin supports both the classic shortcode checkout and the new WooCommerce block-based checkout.

= How are refunds handled? =

Customers request a refund from their QBitFlow invoice page; you approve or refuse it in the QBitFlow dashboard. Once you approve a refund and the on-chain refund settles, the WooCommerce order is automatically marked **Refunded** with a note recording the refund transaction hash. While a request is still pending, an admin notice keeps it on your radar with a one-click link to the QBitFlow refund queue. Only full refunds are supported (blockchain transactions are irreversible, so partial refunds aren't applicable).

= Can I test the plugin without real payments? =

Yes! Create a Test API Key from your QBitFlow dashboard and use it in the plugin settings. You can simulate the entire payment flow without spending any cryptocurrency. See the [Test Mode Documentation](https://qbitflow.app/docs?section=test-mode) for details.

= What happens if I forget to switch from test to live mode? =

Test API keys will not process real transactions. Make sure to replace your test key with a live key before launching your store.

== Screenshots ==

1. Customers select QBitFlow as the payment method at checkout
2. Order received page after a successful crypto payment
3. Admin order view with the QBitFlow payment block (status, tx hash, customer, invoice link)
4. Pending refund request shown directly in the order meta box
5. Once the refund is approved and settled on-chain, the order is auto-marked Refunded
6. Plugin settings (API key, debug logging) under WooCommerce → Settings → Payments

== Changelog ==

= 1.1.1 =
* Added the `Requires Plugins: woocommerce` header so WordPress enforces the WooCommerce dependency before activation
* The checkout payment method description is now an editable setting (Description field) instead of a hardcoded string
* Distributed ZIP no longer includes directory assets or development-only files (`.wordpress-org`, raw screenshots, dev docs); added a `build.sh`/`.distignore` build pipeline

= 1.1.0 =
* Customer sync — find-or-create on QBitFlow at checkout (`GET /customer/email/{email}` first, `POST /customer` only when missing) so the QBitFlow checkout never re-prompts the buyer for details WooCommerce already has
* Customer identity is now keyed on the order's billing email (not the WP account), backed by a persistent email → UUID map; the same WP user placing two orders with two different emails resolves to two distinct QBitFlow customers
* Customer billing address is synced to QBitFlow as a single string (street, city, state, postal code, country)
* Refunds end-to-end — admin notice for pending refunds (live, no cache); refund block on the order meta box shows status, reason, merchantMessage, refund tx hash, respondedAt; once approved on-chain the order is auto-marked **Refunded** with the refund tx hash recorded as an order note
* Refund snapshots persisted on the order once the refund reaches a terminal state (approved/refused/failed); pending refunds keep refetching live
* Updated payment session endpoint to `/transaction/session-checkout/new/payment`; fixed `customerUUID` field casing
* Fixed undefined variable bug in webhook handler for stock reduction
* Fixed potential fatal error when cart is unavailable during REST webhook processing
* `uninstall.php` cleans up plugin options, the email → UUID map, and the legacy customer-uuid user meta on deletion
* Standardised license to GPL-2.0-or-later across LICENSE, plugin header, readme, and README (required for WordPress.org)
* Plugin header `Tested up to: 7.0` and `WC tested up to: 9.7` (in sync with readme)
* Added translation template (`languages/qbitflow-for-woocommerce.pot`)
* Added WordPress.org directory assets (banners, icons, listing screenshots)
* Documented webhook `permission_callback` auth model with inline comment
* Plugin Check pass: translators comments on every placeholder, escape-output on the plain-text email branch, refactored webhook order lookup to `meta_query`; remaining structural warnings annotated with justified `phpcs:ignore` comments
* Plugin slug renamed to `qbitflow-for-woocommerce` to comply with the WordPress.org trademark policy (settings option name and gateway ID unchanged, so existing installs preserve their API key and order history)

= 1.0.0 =
* Initial release
* One-time crypto payments via QBitFlow
* Block and classic checkout support
* Automatic customer sync
* Webhook signature verification
* Invoice and management page links
* Admin order meta box with payment details
* Email instructions with transaction details
* Test mode support

== Upgrade Notice ==

= 1.1.1 =
Adds the WooCommerce dependency header, makes the checkout description editable, and ships a cleaner plugin package. Recommended for all users.

= 1.1.0 =
API compatibility updates, automatic refund completion in WooCommerce, and a per-email customer model so two orders with two different emails resolve to distinct QBitFlow customers. Recommended for all users.

= 1.0.0 =
Initial release.