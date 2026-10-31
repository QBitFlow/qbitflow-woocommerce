# QBitFlow for WooCommerce

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/qbitflow-for-woocommerce)](https://wordpress.org/plugins/qbitflow-for-woocommerce/)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0%2B-purple.svg)](https://woocommerce.com/)

Accept cryptocurrency payments in your WooCommerce store via [QBitFlow](https://qbitflow.app). Non-custodial — funds go directly to your wallet via smart contracts.

## Features

- 🔐 **Non-Custodial** — Funds go directly to your wallet
- 💰 **Multiple Cryptocurrencies** — ETH, SOL, USDC, USDT, and more
- 🧾 **Itemized checkout** — Taxes, shipping and fees as separate lines, adding up exactly to the order total
- 💸 **Processing fee, your choice** — Use my QBitFlow setting, Customer pays, or Store pays (members follow their organization)
- 🔔 **One-click webhook** — Registered from the settings, verified locally with its signing secret
- 🔁 **Orders that settle themselves** — Webhooks, the return page and a background check every 15 minutes
- ↩️ **Refunds recorded automatically** — Full and partial, from QBitFlow's refund webhooks
- 🪙 **Accepted coins at checkout** — The coins your wallets accept, shown under the payment method
- ⚠️ **Failed payment attempts** — Noted on the order with their reason
- 📋 **Crypto column and QBitFlow filter** — In the orders list (HPOS and posts storage)
- 📤 **Accounting export** — CSV or JSON, any range of days, whole account
- ⌨️ **WP-CLI** — `wp qbitflow status|connect-webhook|reconcile|export|settings`
- 👥 **Member keys** — Read-only team settings; the `members.checkoutRedirects` and `members.products` policies handled
- 🧱 **Block Checkout** — Works with WooCommerce's block-based checkout
- 📦 **HPOS Compatible** — Works with High-Performance Order Storage
- 🧪 **Test Mode** — Try the full payment flow without spending a dime

## Learn More

* [Accept crypto on WooCommerce — full overview](https://qbitflow.app/woocommerce) — how the non-custodial checkout works, which chains and tokens your customers can pay with, and pricing.
* [Step-by-step setup guide](https://qbitflow.app/blog/19-How-to-accept-crypto-payments-on-WooCommerce) — install the plugin, connect your wallet, and take your first crypto payment.
* [QBitFlow documentation](https://qbitflow.app/docs) — API keys, test mode, webhooks, and full API reference.

## Installation

### From WordPress.org
1. Go to **Plugins → Add New** in your WordPress admin
2. Search for **"QBitFlow"**
3. Click **Install Now** → **Activate**

### Manual Installation
1. Download the [Plugin ZIP](https://github.com/QBitFlow/qbitflow-woocommerce/releases) and upload it via Plugins → Add New → Upload Plugin
   (or extract the `qbitflow-for-woocommerce` folder to `/wp-content/plugins/`)
2. Activate the plugin through the 'Plugins' menu in WordPress


### From Source
```bash
git clone https://github.com/QBitFlow/qbitflow-woocommerce.git
cd qbitflow-woocommerce
sudo chmod +x build.sh
./build.sh
# Copy to your WordPress plugins directory
cp -r ./build/qbitflow-for-woocommerce /path/to/wordpress/wp-content/plugins/qbitflow-for-woocommerce
```

## Configuration

1. Go to **WooCommerce → Settings → Payments → QBitFlow**
2. Enter your API key from [QBitFlow dashboard](https://qbitflow.app)
3. Enable the payment method and save
4. Click **Connect webhook** (once per mode: test and live keys have separate webhooks)

Options: **Itemized checkout** (taxes, shipping and fees as separate lines; default on), **Accepted coins** (shown at checkout) and **Processing fee**: *Use my QBitFlow setting* (default; the account's choice, the field is omitted from the checkout), *Customer pays* (`fees.processingFee: true`) or *Store pays* (`false`). A member key always follows its organization. Refund rules, receipt and webhook failure emails, the processing fee default and branding are the QBitFlow account's settings: they are changed in the QBitFlow dashboard, not from WordPress. The status panel above the settings shows the connected account, mode, accepted currencies, webhook health, store currency and PHP security.

**Stores not priced in USD**: QBitFlow prices in USD, so the gateway stays hidden until a rate is provided:

```php
add_filter('qbitflow_wc_usd_rate', function ($rate, string $currency) {
    return $currency === 'EUR' ? 1.08 : $rate; // USD per unit of $currency
}, 10, 2);
```

**Upgrading from 1.x**: create a new API key (the new format, `sk_live_…` / `sk_test_…`), paste it, then click **Connect webhook**.

**Member keys**: when the organization turns off the `members.checkoutRedirects` policy, QBitFlow answers `403 policy_disabled`; the plugin retries once without `successUrl`/`cancelUrl` (idempotency key suffixed `-nr`), remembers it for an hour (transient `qbitflow_wc_no_redirects`) and shows an admin notice. When it turns off `members.products`, the member may not open checkouts at all: a `403 policy_disabled` from a checkout, or from the status check (it reads a checkout that doesn't exist; QBitFlow checks the policy before the lookup, so `403` means off and `404` on), hides QBitFlow at checkout for 10 minutes (transient `qbitflow_wc_checkouts_off`, cleared by the next check that answers `404` or by a key change), with an admin notice.

**WP-CLI**:

```bash
wp qbitflow status [--format=json]
wp qbitflow connect-webhook
wp qbitflow reconcile <order-id>... | --all
wp qbitflow export --from=2026-01-01 --to=2026-01-31 --output=jan.csv   # csv or json
```

## Test Mode — Try Before Going Live

QBitFlow provides a **test mode** that lets you simulate the entire payment flow — checkout, on-chain confirmation, webhooks, order updates — without spending any real cryptocurrency.

### Getting Started with Test Mode

1. Log in to your [QBitFlow dashboard](https://qbitflow.app)
2. Create a **Test API Key** from the API keys section
3. Go to **WooCommerce → Settings → Payments → QBitFlow**
4. Paste your **test API key** and save
5. Place a test order — the full flow works exactly like production, but no real funds are moved

> 📖 For more details on test mode, see the [QBitFlow Test Mode Documentation](https://qbitflow.app/docs?section=test-mode).

### Going Live

When you're ready to accept real payments:

1. Create a **Live API Key** from your [QBitFlow dashboard](https://qbitflow.app)
2. Go to **WooCommerce → Settings → Payments → QBitFlow**
3. Replace the test API key with your **live API key**
4. Save changes — you're now accepting real crypto payments! 🚀

> ⚠️ **Don't forget** to switch from your test key to a live key before launching. Test keys will not process real transactions.

## How It Works

```
Checkout → QBitFlow checkout session → customer pays on-chain
        → payment.completed webhook / return page / 15-min check → order paid
```

1. Customer selects "Pay with Crypto" at checkout
2. The plugin opens a QBitFlow checkout for the order (one per order, reused while the amounts don't change) and redirects the customer
3. The customer pays with their preferred cryptocurrency
4. QBitFlow confirms the payment (signed webhook); the return page and a background check confirm it too if the webhook is late
5. The order is marked paid only when the full amount was received; otherwise it is put on hold with a note

## External Services

This plugin relies on **QBitFlow**, a third-party cryptocurrency payment service. It talks to the QBitFlow API at `https://api.qbitflow.app`, authenticating every request with the API key you enter in the settings. What is sent and when is listed in the [readme's "External services" section](readme.txt) (checkout, order checks, settings status, webhook registration, the hourly refund list, uninstall). Webhooks are verified locally: nothing is sent back to QBitFlow to verify them.

By using it you agree to QBitFlow's [Terms of Service](https://qbitflow.app/terms) and [Privacy Policy](https://qbitflow.app/privacy).

## Development

The plugin bundles the [QBitFlow PHP SDK](https://github.com/QBitFlow/qbitflow-php-sdk) v3, namespace-prefixed with [Strauss](https://github.com/BrianHenryIE/strauss) under `QBitFlowWC\Vendor\` (in `vendor-prefixed/`), so it never conflicts with another plugin's copy of the SDK or of the PSR libraries. The SDK talks HTTP through a PSR-18 adapter over the WordPress HTTP API (`src/Http/WpHttpClient.php`): proxies, `http_request_args` and `pre_http_request` apply as for any WordPress request.

```bash
composer install                   # dependencies + Strauss (vendor-prefixed/)
composer test:unit                 # unit suite (pure PHP)
npx @wordpress/env start           # WordPress + WooCommerce in Docker (needs Docker)
composer test:integration          # integration suite inside wp-env
./build.sh                         # build/qbitflow-for-woocommerce.zip
composer release-check             # all of the above, the ZIP checked and Plugin Check (see below)
```

The integration suite runs against real WordPress and WooCommerce (HPOS on, and posts storage where it matters) with a fake QBitFlow API behind `pre_http_request`, and real signed webhook deliveries. Live checks against a QBitFlow API run only with `QBITFLOW_API_KEY` and `QBITFLOW_BASE_URL` set (a test key; never production by default).

### Testing and the release gate

Before every release, run the gate from anywhere in the repository (it stops at the first failure, exits non-zero, and ends with a summary of every stage):

```bash
bin/release-check.sh               # or: composer release-check
```

In order: `composer validate`; a PHP lint of every shipped file on the minimum PHP (the plugin header's "Requires PHP", in the `php:<version>-cli` Docker image); the unit suite; wp-env (started if needed) and the integration suite; `./build.sh`; the ZIP's contents (one `qbitflow-for-woocommerce/` folder, nothing `.distignore` lists nor tests, vendor, `composer.lock` or PHPUnit files, `vendor-prefixed/` holding no file its installed package no longer has, the shipped PHP linted again, one version in the header, the constant, `uninstall.php`, readme's Stable tag and both changelogs); and Plugin Check on the built plugin (in wp-env's tests-cli, `plugin-check` activated then deactivated). It needs Docker, Composer, PHP, Node (for wp-env), rsync and zip.

### Local development constants

| Constant | Use |
|---|---|
| `QBITFLOW_WC_API_BASE_URL` | The QBitFlow API to talk to (e.g. a staging or development server) |
| `QBITFLOW_WC_WEBHOOK_URL` | The webhook URL the plugin registers and compares (see Hooks) |

### Hooks

| Hook | Type | Use |
|---|---|---|
| `qbitflow_wc_usd_rate` | filter | USD per unit of a non-USD currency (`$rate`, `$currency`, `$order`); null keeps QBitFlow hidden |
| `qbitflow_wc_client_options` | filter | SDK client `timeout`, `maxRetries`, `baseUrl` |
| `qbitflow_wc_http_request_args` | filter | WordPress HTTP API args of each request to QBitFlow (redirects and TLS verification can't be turned off) |
| `qbitflow_wc_webhook_event` | action | Runs for every verified QBitFlow event (`QBitFlowWC\Vendor\QBitFlow\Events\Event`); throwing makes QBitFlow retry |
| `QBITFLOW_WC_API_BASE_URL` | constant | Another API base URL (staging) |
| `QBITFLOW_WC_WEBHOOK_URL` | constant | Local development only (never needed in production): the webhook URL the plugin registers and compares, e.g. an http address a development QBitFlow server can reach for an https store. Only an absolute http(s) URL is accepted; anything else is ignored and logged. Also filterable with `qbitflow_wc_webhook_url` |

### Debug Logging

Enable in **WooCommerce → Settings → Payments → QBitFlow → Debug Log**. Errors are always logged.

Logs are written to: `WooCommerce → Status → Logs → qbitflow`

## Screenshots

1. [Block checkout with the accepted coins](.wordpress-org/screenshot-1.png)
2. [The QBitFlow checkout, itemized](.wordpress-org/screenshot-2.png)
3. [Classic checkout with the accepted coins](.wordpress-org/screenshot-3.png)
4. [A paid order's QBitFlow payment box](.wordpress-org/screenshot-4.png)
5. [Orders list: Crypto column, QBitFlow filter, pending refund requests](.wordpress-org/screenshot-5.png)
6. [A refund recorded on the order](.wordpress-org/screenshot-6.png)
7. [Settings: status panel, accepted coins and accounting export](.wordpress-org/screenshot-7.png)
8. [Settings: itemized checkout, accepted coins, processing fee](.wordpress-org/screenshot-8.png)

## Contributing

Contributions are welcome! Please open an issue or submit a pull request. Run `composer release-check` (see [Testing and the release gate](#testing-and-the-release-gate)) before proposing a release.

## License

[GPL-2.0-or-later](LICENSE)

## Support

- 📖 [Documentation](https://qbitflow.app/docs)
- 📧 [Email Support](mailto:support@qbitflow.app)
- 🐛 [Issue Tracker](https://github.com/QBitFlow/qbitflow-woocommerce/issues)