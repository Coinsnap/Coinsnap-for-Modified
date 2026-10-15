# Coinsnap for Modified Shop

![Coinsnap for Modified Shop](https://resources.coinsnap.org/products/modified/images/cover.png)

**Bitcoin payment module for Modified Shop — accept Bitcoin and Lightning with Coinsnap.**

Coinsnap for Modified Shop is an open-source Bitcoin payment module for stores based on modified eCommerce Shopsoftware. It integrates Bitcoin payments into the existing checkout and order workflow.

Customers can pay with **Bitcoin via Lightning or on-chain**. When using Coinsnap, merchants do not need to operate their own Bitcoin or Lightning payment infrastructure.

Coinsnap is **Bitcoin payment software for merchants** and a brand of **Onlineshop24 d.o.o., Serbia**.

## Key features

- Accept **Bitcoin and Lightning payments** in Modified Shop
- Support for **Lightning and Bitcoin on-chain**
- Integrated into the Modified Shop checkout and order workflow
- Payment status handling in the shop backend
- No own Lightning node or Bitcoin payment server required
- **Non-custodial / self-custody** approach
- Settlement to a compatible Bitcoin or Lightning wallet
- Installation via FTP without modifying the Modified Shop core
- Open source under the GPL-3.0 License

## Requirements

The current repository release is **1.0.0**.

The module requires:

- A **Modified Shop installation** with administrator access
- **PHP 8.0 or newer**
- FTP access to the shop server
- A **Coinsnap merchant account**

For Bitcoin settlement, you can use a compatible Bitcoin or Lightning wallet. One option is the [Coinsnap Wallet](https://coinsnap.io/wallet/), which combines a self-custodial Bitcoin wallet with merchant and point-of-sale functionality.

## How it works

1. Create a [Coinsnap merchant account](https://app.coinsnap.io/register).
2. Download the Coinsnap for Modified Shop module from this repository.
3. Upload the module files to the root directory of your Modified Shop installation via FTP.
4. Install Coinsnap under **Modules → Payment Methods**.
5. Enter your Coinsnap **Store ID** and **API key**.
6. Configure the payment status mapping and available payment zones.
7. Enable the payment method and perform a test transaction.

## Installation

### 1. Download the module

Download the current version from this GitHub repository and extract the archive locally.

### 2. Upload the module files

Upload the contents of the `shoproot` directory to the root directory of your Modified Shop installation while preserving the directory structure.

The package contains the relevant files for:

- `callback`
- `includes`
- `lang`

### 3. Install the payment module

In the Modified Shop administration:

1. Go to **Modules → Payment Methods**.
2. Find **Coinsnap**.
3. Click **Install**.
4. Coinsnap will then appear in the list of installed payment modules.

### 4. Configure Coinsnap

Open the Coinsnap payment settings and:

1. Enable the Coinsnap module.
2. Enter your **Coinsnap Store ID**.
3. Enter your **Coinsnap API key**.
4. Configure the desired order/payment statuses.
5. Configure permitted payment zones if required.
6. Save the settings.

Your Store ID and API key are available in your Coinsnap merchant account.

## Resources

- [Coinsnap for Modified Shop product page](https://coinsnap.io/shop-systems/modified-shop/)
- [Coinsnap Modified Shop user guide](https://www.coinsnap.io/user-guides/modified-shop-bitcoin-user-guide/)
- [Coinsnap Modified Shop demo store](https://modified-shop.coinsnap.org/)
- [Coinsnap Wallet](https://coinsnap.io/wallet/)
- [Coinsnap API documentation](https://docs.coinsnap.io/)
- [Coinsnap merchant account](https://app.coinsnap.io/register)
- [Coinsnap website](https://coinsnap.io/)
- [Telegram community and support](https://t.me/coinsnap)

> **Brand note:** Coinsnap is Bitcoin payment software for merchants and is not the provider of the similarly named coin identifier app "CoinSnap".

## Frequently Asked Questions

### What do I need to accept Bitcoin in Modified Shop?

You need a Modified Shop installation with administrator access, PHP 8.0 or newer, FTP access to the shop server and a Coinsnap merchant account.

### Can customers pay with both Lightning and Bitcoin on-chain?

Yes. Coinsnap supports Bitcoin payments via both the Lightning Network and Bitcoin on-chain.

### Which Bitcoin or Lightning wallets can I use?

You can use a compatible Bitcoin or Lightning wallet for settlement. One option is the [Coinsnap Wallet](https://coinsnap.io/wallet/).

Other compatible wallets may include solutions such as Wallet of Satoshi, Blink and Zeus, depending on the settlement setup you choose.

### Does Coinsnap hold my Bitcoin?

Coinsnap follows a non-custodial approach and does not hold merchant Bitcoin. Payments are settled to the merchant's configured wallet or payment destination.

### Do I need to modify the Modified Shop core?

No. The module is installed by uploading its files into the existing Modified Shop directory structure and enabling it as a payment module.

### Do I need my own Lightning node?

No. When using Coinsnap, no own Lightning node or Bitcoin payment server is required.

### Where do I find my Store ID and API key?

They are available in your Coinsnap merchant account and are entered in the Modified Shop Coinsnap payment configuration.

### Is the module open source?

Yes. The source code is available in this repository under the GPL-3.0 License.

### Where can I get support?

Use the support area in your Coinsnap merchant account or join the official [Coinsnap Telegram channel](https://t.me/coinsnap).

## Changelog

### 1.0.0

- Initial public release.
