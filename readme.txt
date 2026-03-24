=== QBitFlow for WooCommerce — Crypto Payments ===
Contributors: qbitflow
Tags: crypto, cryptocurrency, payments, woocommerce, bitcoin, ethereum, solana, usdc, usdt, web3
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
WC requires at least: 7.0
WC tested up to: 9.6
License: MPL-2.0
License URI: https://opensource.org/licenses/MPL-2.0

Accept cryptocurrency payments in WooCommerce via QBitFlow. Non-custodial — funds go directly to your wallet via smart contracts.

== Description ==

**QBitFlow for WooCommerce** lets you accept cryptocurrency payments directly in your WooCommerce store. Powered by smart contracts, payments go straight to your wallet — no middleman, no custody risk.

= Features =

* **Non-Custodial** — Funds go directly to your wallet via smart contracts
* **Multiple Cryptocurrencies** — ETH, SOL, USDC, USDT, and more
* **Block Checkout Support** — Works with both classic and block-based checkout
* **Automatic Customer Sync** — Customer profiles synced to QBitFlow for analytics
* **Invoice & Management Pages** — Hosted payment details pages for customers
* **Webhook Verified** — Secure server-to-server payment confirmation
* **Real-time Order Updates** — Order status updates automatically on payment
* **Refund Support** — Mark refunds in WooCommerce (manual crypto refund via the QBitFlow dashboard)
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

1. Upload the `qbitflow-woocommerce` folder to `/wp-content/plugins/`
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

Refunds are marked in WooCommerce but must be processed manually from your QBitFlow dashboard, as blockchain transactions are irreversible. When you initiate a refund in WooCommerce, you'll be directed to the QBitFlow dashboard to complete it.

= Can I test the plugin without real payments? =

Yes! Create a Test API Key from your QBitFlow dashboard and use it in the plugin settings. You can simulate the entire payment flow without spending any cryptocurrency. See the [Test Mode Documentation](https://qbitflow.app/docs?section=test-mode) for details.

= What happens if I forget to switch from test to live mode? =

Test API keys will not process real transactions. Make sure to replace your test key with a live key before launching your store.

== Screenshots ==

1. Checkout with QBitFlow payment option (screenshots/payment-page.png)
2. QBitFlow payment page (screenshots/checkout-page.png)
3. Admin order page with payment details (screenshots/admin-order-page.png)
4. Plugin settings page (screenshots/plugin-settings-page.png)

== Changelog ==

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

= 1.0.0 =
Initial release.