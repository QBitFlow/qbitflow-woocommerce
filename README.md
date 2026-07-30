# QBitFlow for WooCommerce

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/qbitflow-for-woocommerce)](https://wordpress.org/plugins/qbitflow-for-woocommerce/)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-7.0%2B-purple.svg)](https://woocommerce.com/)

Accept cryptocurrency payments in your WooCommerce store via [QBitFlow](https://qbitflow.app). Non-custodial — funds go directly to your wallet via smart contracts.

## Features

- 🔐 **Non-Custodial** — Funds go directly to your wallet
- 💰 **Multiple Cryptocurrencies** — ETH, SOL, USDC, USDT, and more
- 🧱 **Block Checkout** — Works with WooCommerce's new block-based checkout
- 👥 **Customer Sync** — Automatic customer profile sync for analytics
- 🧾 **Invoices** — Hosted invoice and payment management pages
- 🔔 **Webhooks** — Verified server-to-server payment confirmation
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
1. Download the [Plugin ZIP](https://github.com/QBitFlow/qbitflow-for-woocommerce/releases) and upload it via Plugins → Add New → Upload Plugin
   (or extract the `qbitflow-for-woocommerce` folder to `/wp-content/plugins/`)
2. Activate the plugin through the 'Plugins' menu in WordPress


### From Source
```bash
git clone https://github.com/QBitFlow/qbitflow-for-woocommerce.git
cd qbitflow-for-woocommerce
sudo chmod +x build.sh
./build.sh
# Copy to your WordPress plugins directory
cp -r ./build/qbitflow-for-woocommerce /path/to/wordpress/wp-content/plugins/qbitflow-for-woocommerce
```

## Configuration

1. Go to **WooCommerce → Settings → Payments → QBitFlow**
2. Enter your API key from [QBitFlow dashboard](https://qbitflow.app)
3. Enable the payment method
4. Save changes

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
Customer checkout → QBitFlow payment page → On-chain payment → Webhook → Order updated
```

1. Customer selects "Pay with Crypto" at checkout
2. Redirected to QBitFlow's secure payment page
3. Pays with their preferred cryptocurrency
4. Payment confirmed on-chain, order auto-updated
5. Customer receives invoice with transaction details

## External Services

This plugin relies on **QBitFlow**, a third-party cryptocurrency payment service, to function. QBitFlow processes crypto payments non-custodially (funds go directly to your wallet via smart contracts). Without it, the gateway cannot create payments or process refunds. The plugin communicates with the QBitFlow API at `https://api.qbitflow.app`, authenticating every request with the API key you enter in the plugin settings.

What data is sent, and when:

- **At checkout** — the buyer's email, first/last name, phone number, and billing address, plus the order and transaction details (amount, currency, order reference), to find or create the customer record on QBitFlow and create the payment session the buyer is redirected to.
- **On the admin order screen / when you sync a refund** — the transaction identifier for that order, to look up its current refund status.
- **When QBitFlow sends a payment webhook** — the received webhook payload and its signature, sent back to QBitFlow to verify the notification is authentic before acting on it.

No data is sent to QBitFlow outside of these actions.

This service is provided by QBitFlow. By using it you agree to their [Terms of Service](https://qbitflow.app/terms) and [Privacy Policy](https://qbitflow.app/privacy).

## Development

### Local Setup with Docker

```bash
git clone https://github.com/QBitFlow/qbitflow-for-woocommerce.git
cd qbitflow-for-woocommerce

# Create a Docker WordPress environment
# Mount the plugin directory to wp-content/plugins/qbitflow-for-woocommerce
```

### Debug Logging

Enable in **WooCommerce → Settings → Payments → QBitFlow → Debug Log**

Logs are written to: `WooCommerce → Status → Logs → qbitflow`

## Screenshots

1. [Customers select QBitFlow at checkout](.wordpress-org/screenshot-1.png)
2. [Order received page after a successful crypto payment](.wordpress-org/screenshot-2.png)
3. [Admin order view with the QBitFlow payment block](.wordpress-org/screenshot-3.png)
4. [Pending refund request shown directly in the order meta box](.wordpress-org/screenshot-4.png)
5. [Order synced to Refunded once the refund settles on-chain](.wordpress-org/screenshot-5.png)
6. [Plugin settings (API key, debug logging)](.wordpress-org/screenshot-6.png)

## Contributing

Contributions are welcome! Please open an issue or submit a pull request.

## License

[GPL-2.0-or-later](LICENSE)

## Support

- 📖 [Documentation](https://qbitflow.app/docs)
- 📧 [Email Support](mailto:support@qbitflow.app)
- 🐛 [Issue Tracker](https://github.com/QBitFlow/qbitflow-for-woocommerce/issues)