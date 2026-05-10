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

## Installation

### From WordPress.org
1. Go to **Plugins → Add New** in your WordPress admin
2. Search for **"QBitFlow"**
3. Click **Install Now** → **Activate**

### Manual Installation
1. Download the latest release from [Releases](https://github.com/QBitFlow/qbitflow-for-woocommerce/releases)
2. Upload to `/wp-content/plugins/`
3. Activate in WordPress admin

### From Source
```bash
git clone https://github.com/QBitFlow/qbitflow-for-woocommerce.git
cd qbitflow-for-woocommerce
# Copy to your WordPress plugins directory
cp -r . /path/to/wordpress/wp-content/plugins/qbitflow-for-woocommerce
```

## Configuration

1. Go to **WooCommerce → Settings → Payments → QBitFlow**
2. Enter your API key from [qbitflow.app](https://qbitflow.app)
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

1. [Customers select QBitFlow at checkout](screenshots/checkout.png)
2. [Order received page after a successful crypto payment](screenshots/order-received.png)
3. [Admin order view with the QBitFlow payment block](screenshots/wc-order.png)
4. [Pending refund request shown directly in the order meta box](screenshots/wc-order-with-refund-request.png)
5. [Order auto-marked Refunded once the refund settles on-chain](screenshots/wc-order-with-refund-accepted.png)
6. [Plugin settings (API key, debug logging)](screenshots/plugin-settings.png)

## Contributing

Contributions are welcome! Please open an issue or submit a pull request.

## License

[GPL-2.0-or-later](LICENSE)

## Support

- 📖 [Documentation](https://qbitflow.app/docs)
- 📧 [Email Support](mailto:support@qbitflow.app)
- 🐛 [Issue Tracker](https://github.com/QBitFlow/qbitflow-for-woocommerce/issues)