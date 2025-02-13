# WooCommerce GoCardless Gateway

> Extends WooCommerce with a GoCardless gateway. A GoCardless merchant account is required.

![WordPress tested up to version](https://img.shields.io/badge/WordPress-v6.5%20tested-success.svg) [![GPLv3+ License](https://img.shields.io/github/license/gocardless/woocommerce-gateway-gocardless.svg)](https://github.com/gocardless/woocommerce-gateway-gocardless/blob/trunk/LICENSE.md)
[![E2E test](https://github.com/gocardless/woocommerce-gateway-gocardless/actions/workflows/e2e.yml/badge.svg)](https://github.com/gocardless/woocommerce-gateway-gocardless/actions/workflows/e2e.yml) [![QIT Tests](https://github.com/gocardless/woocommerce-gateway-gocardless/actions/workflows/qit.yml/badge.svg)](https://github.com/gocardless/woocommerce-gateway-gocardless/actions/workflows/qit.yml) [![PHP Unit](https://github.com/gocardless/woocommerce-gateway-gocardless/actions/workflows/phpunit.yml/badge.svg)](https://github.com/gocardless/woocommerce-gateway-gocardless/actions/workflows/phpunit.yml)
[![PHP Compatibility](https://github.com/gocardless/woocommerce-gateway-gocardless/actions/workflows/php-compatibility.yml/badge.svg)](https://github.com/gocardless/woocommerce-gateway-gocardless/actions/workflows/php-compatibility.yml) [![PHP Coding Standards](https://github.com/gocardless/woocommerce-gateway-gocardless/actions/workflows/phpcs.yml/badge.svg)](https://github.com/gocardless/woocommerce-gateway-gocardless/actions/workflows/phpcs.yml)

## Overview

This is a feature plugin for accepting payments via [GoCardless](https://gocardless.com/). It requires [WooCommerce](https://wordpress.org/plugins/woocommerce/) to be installed before the WooCommerce GoCardless Gateway can be activated.

## Compatibility

This extension is compatible with:

- [Woo Subscriptions](https://woo.com/products/woocommerce-subscriptions/)

## Setup

Install the following plugins on your dev site:

- WooCommerce

### Test account setup

You can create a user on [gocardless.com](https://gocardless.com) for live transactions and [on the sandbox](https://manage-sandbox.gocardless.com/) for test transactions. When you first set up a site, you’ll be prompted to create a user for the correct GoCardless environment when setting up the webhooks.

## Screenshots

Where to enable GoCardless payment gateway:
<img src="/.wordpress-org/screenshot-1.png" alt="Payment Methods screen showing where to enable the GoCardless payment gateway." width="300">

Default GoCardless configuration:
<img src="/.wordpress-org/screenshot-2.png" alt="GoCardless settings screen showing the initial state." width="300">

Properly connected GoCardless configuration:
<img src="/.wordpress-org/screenshot-3.png" alt="GoCardless settings screen showing a store connected to ad configured with GoCardless to use the ACH direct debit scheme." width="300">

## Development

### Requirements

- PHP: 7.4+
- WordPress: 6.5+
- WooCommerce: 9.4+
- Node: v20
- NPM: v10

### Install dependencies & build

- `nvm install` - Ensures the required Node version is installed.
- `npm install` - Installs the necessary dependencies.
- `npm run build` - Creates a .zip archive containing the extension files for release or testing on other sites.
- `npm run lint:js` - Runs ESLint on JavaScript files.
- `npm run test:unit` - Executes PHPUnit tests.

## Run E2E Tests

### Prerequisites

- Create a [sandbox GoCardless](https://manage-sandbox.gocardless.com/) account for test transactions.

### Dependencies for Local Testing

- Add sandbox GoCardless credentials to the `.env` file, which can be found in the `./tests/e2e/config` directory.

```
GOCARDLESS_EMAIL=********
GOCARDLESS_PASSWORD=********
```

### Run E2E Tests Locally

1. Run `npm install`.
2. Run `npx playwright install`.
3. Run `npm run env:install-plugins`
4. Run `npm run env:start`  (Note: Please start Docker before executing this command).
5. Add environment variables to the `/tests/e2e/config/.env` file (as mentioned above).
6. Run `npm run test:e2e-local`.

### Run E2E Tests in the Pull Request

- Add the `needs: e2e testing` label to the pull request; it will initiate the E2E test GitHub action to run tests against the PR.
