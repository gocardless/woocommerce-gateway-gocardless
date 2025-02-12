=== WooCommerce GoCardless Gateway ===
Contributors: gocardless, woocommerce, automattic
Tags:         gocardless, woocommerce, direct debit, instant bank pay
Tested up to: 6.7
Stable tag:   2.9.1
License:      GPL-3.0-or-later
License URI:  https://spdx.org/licenses/GPL-3.0-or-later.html

Extends WooCommerce with a GoCardless gateway. A GoCardless merchant account is required.

== Description ==

This is a feature plugin for accepting payments via [GoCardless](https://gocardless.com/).  It requires [WooCommerce](https://wordpress.org/plugins/woocommerce/) to be installed before the WooCommerce GoCardless Gateway can be activated.

= Compatibility =

This extension is compatible with:

- [Woo Subscriptions](https://woo.com/products/woocommerce-subscriptions/)

= Test Account Setup =

You can create a user on [gocardless.com](https://gocardless.com) for live transactions and [on the sandbox](https://manage-sandbox.gocardless.com/) for test transactions. When you first set up a site, you’ll be prompted to create a user for the correct GoCardless environment when setting up the webhooks.

= Development =

**Install Dependencies & Build**

The plugin uses Webpack to build the assets. To build the assets, follow these steps:

- Run `npm install` to install the dependencies.
- Run `npm run build:webpack` to build the asset files. You can also run `npm run start:webpack` to watch the files and rebuild them automatically when they change.

You can find the source files in the `assets` and `client` directories.

== Frequently Asked Questions ==

= Does GoCardless support BACS? =

Yes, the GoCardless extension supports BACS and the payment methods mentioned in the [GoCardless API documentation](https://developer.gocardless.com/api-reference/#overview-supported-direct-debit-schemes).

= I’m based in country x – can I use GoCardless on my site? =

While GoCardless can collect from customers from many countries, note that GoCardless can only on-board merchants from Austria, Belgium, Finland, France, Germany, Ireland, Luxembourg, Netherlands, Spain, Sweden, United Kingdom, United States, and Canada.

= Do I need to fill in the webhook secret? =

Yes, you need to manually fill in the webhook secret and configure the webhook endpoint. It cannot be automated, so we made it as painless as possible.

= Why are my orders not redirecting to the thank you page or marked as paid? =

If you use a security or firewall plugin or have a firewall application on your server, be sure that you allow requests from GoCardless in that plugin’s settings. GoCardless sends webhooks from the following IP addresses which you may wish to add to your firewall’s approved list:

- 35.204.73.47
- 35.204.191.250
- 35.204.214.181

= Everything is set up and customers are ordering using this gateway, so why am I not receiving payouts from GoCardless? =

Ensure that you’re verified by GoCardless, as no payouts are made until this is done. Keep an eye out for emails from GoCardless, and check your Spam folder, as you are also notified when funds are waiting.

= Does GoCardless support tokenization? =

Yes. Customers may opt to securely store payment information for future checkout.

= Can I use GoCardless with pre-orders? =

GoCardless includes support for [WooCommerce Pre-Orders](https://woocommerce.com/products/woocommerce-pre-orders/) (separate purchase).

= Can I use GoCardless with subscriptions? =

Yes, it’s possible to use GoCardless to accept automatic, recurring payments for [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/) (separate purchase).

= Do I need to Create an App? =

Merchants need only click the Connect button to hook up their GoCardless account. It’s not necessary to Create an App.

Create an App is for developers seeking to code their own applications from scratch and not use the GoCardless for WooCommerce extension or any other already made.

= Does this work with Pro and Plus packages? =

Yes. As of version 2.4.5, WooCommerce GoCardless includes support for merchants upgrading to Pro and/or Plus. It:

- Notifies via webhook with event “mandate_replaced” with the new mandate in the payload.
- Gives an error response about mandate replacement when payment is created with the old mandate.

= How is it decided which payment method gets selected between Instant Bank Pay, Direct Debit, or a combination of both? =

The selection is automated and depends on two things: first, whether Instant Bank payments are supported for both the customer and the merchant, and second, the items being purchased. For example, **Instant Bank Pay** will be used for simple product purchases where the customer’s billing address is in the UK (GBP), Germany (EURO), and France (EURO). Similarly, for the same setup, subscription product purchases will be completed using the **Instant Bank Payment** and **Direct Debit (mandate)** setup flow. For countries and currencies where instant payment is not supported, the Direct **Debit (mandate)** only flow will be used.

= Why does the GoCardless gateway classify all transactions as "recurrent"? =

GoCardless collects payments by setting up a **Direct Debit (Mandate)**. This Direct Debit mandate can be used to collect future payments. Even for a simple product, GoCardless sets up a Direct Debit and collects the payment under the mandate. As a result, every payment—whether for a one-time purchase or a subscription—is processed via this Direct Debit (Mandate), which is recurrent. Therefore, transactions appear as recurrent.

It is important to note that for merchants and customers in the UK (GBP), Germany (EUR), and France (EUR), GoCardless supports **Instant Bank Pay (IBP)**. IBP allows for one-off payments that are collected instantly without setting up a Direct Debit, and these transactions are not marked as recurrent.

= How can I connect a single GoCardless account to multiple WooCommerce stores using this plugin? =

The GoCardless plugin uses the OAuth flow to connect with your GoCardless account. With OAuth, only the most recently connected access token remains active. This means if you use the "Connect with GoCardless" button on multiple stores, the connection for the most recently connected store will remain active, while connections for previously connected stores will be disabled.

To connect a single GoCardless account to multiple stores, follow these steps:

1. **Create an Access Token:** Log in to your GoCardless Dashboard and create an access token. Follow the instructions here: [How to create an access token](https://hub.gocardless.com/s/article/How-to-create-an-access-token?language=en_GB).

2. **Set the Access Token in WordPress:** Open your wp-config.php file and define the `WC_GOCARDLESS_ACCESS_TOKEN` constant. Set the created access token as its value.

```
define('WC_GOCARDLESS_ACCESS_TOKEN', 'your-access-token-here');
```

3. **Add the Code Snippet:** Add the code snippet below to your theme's functions.php file or to a site-specific plugin:

```
add_filter( 'option_woocommerce_gocardless_settings', function ( $settings ) {
	if ( ! is_array( $settings ) ) {
		return $settings;
	}

	// Set custom access token. "WC_GOCARDLESS_ACCESS_TOKEN" should be defined in wp-config.php.
	if ( defined( 'WC_GOCARDLESS_ACCESS_TOKEN' ) && WC_GOCARDLESS_ACCESS_TOKEN ) {
		$settings['access_token'] = WC_GOCARDLESS_ACCESS_TOKEN;
		$settings['testmode']     = 'no'; // Set to 'yes' for sandbox mode.
	}

	return $settings;
} );
```

Following these steps will allow you to connect a single GoCardless account to multiple WooCommerce stores.

= Does the plugin use any external services? =

Yes, it uses [GoCardless](https://gocardless.com/) ([privacy policy](https://gocardless.com/privacy/)) to accept payments and [WooCommerce](https://woocommerce.com/) ([privacy policy](https://automattic.com/privacy/)) to connect GoCardless accounts to individual WooCommerce stores.

== Screenshots ==

1. Payment Methods screen showing where to enable the GoCardless payment gateway.
2. GoCardless settings screen showing the initial state.
3. GoCardless settings screen showing a store connected to ad configured with GoCardless to use the ACH direct debit scheme.

== Changelog ==

= 2.9.0 - 2025-02-12 =
* Add - Handle the payment "chargeback_settled" webhook event.
* Fix - Ensure subscription renewals work properly after a failed renewal order.
* Update - Plugin ownership from WooCommerce to GoCardless.
* Dev - Bump WooCommerce "tested up to" version 9.6.
* Dev - Bump WooCommerce minimum supported version to 9.4.
* Dev - Added release and repo management actions.
* Dev - Exclude the WooCommerce Comment Hook sniff from our PHPCS configuration.
* Dev - Updates GitHub `actions/cache`, `actions/upload` and `actions/download` to v4 due to deprecation.

= 2.8.3 - 2024-12-09 =
* Dev - Bump WooCommerce "tested up to" version 9.5.
* Dev - Bump WooCommerce minimum supported version to 9.3.

= 2.8.2 - 2024-10-28 =
* Dev - Bump WordPress "tested up to" version 6.7.
* Dev - Bump WordPress minimum supported version to 6.5.
* Dev - Bump WooCommerce "tested up to" version 9.4.
* Dev - Bump WooCommerce minimum supported version to 9.2.
* Dev - Fix failing E2E tests.

= 2.8.1 - 2024-08-20 =
* Fix - Ensure payments work correctly when user accounts are automatically created during the checkout process.
* Add - Plugin banner and icon images for WordPress.org.
* Dev - Bump WooCommerce "tested up to" version 9.2.
* Dev - Bump WooCommerce minimum supported version to 9.0.

= 2.8.0 - 2024-08-05 =
* Add - Display a note informing the customer if JavaScript is disabled or not supported in their browser.
* Fix - Ensure the "Cancel Payment & Subscription" order action is only visible while the payment status is in "pending_submission".
* Fix - Ensure disconnecting your GoCardless account works properly.
* Fix - Ensure the GoCardless payment method is available only for supported currencies.
* Fix - Ensure the GoCardless payment gateway works correctly for guest customers when a default customer location is not set.
* Dev - Bump WooCommerce "tested up to" version 9.1.
* Dev - Bump WooCommerce minimum supported version to 8.9.
* Dev - Bump WordPress "tested up to" version 6.6.
* Dev - Bump WordPress minimum supported version to 6.4.
* Dev - Add an FAQs section to the readme file, including new details on how to connect multiple WooCommerce stores to a single GoCardless account.
* Dev - Removed old backward compatibility code, which was added to handle the update of the plugin's main file to `woocommerce-gateway-gocardless.php`.
* Dev - Upgraded NPM and Composer packages and updated the Node version to v20 to modernize the developer experience.
* Dev - Add E2E tests for each Direct Debit scheme.

= 2.7.2 - 2024-07-29 =
* Add - New toggle option to enable or disable Instant Bank Pay.
* Dev - PHPCS fixes and various code improvements.

= 2.7.1 - 2024-06-26 =
* Fix - Ensure the webhook secret is generated with enough entropy.

= 2.7.0 - 2024-06-03 =
* Add - Support for the GoCardless Billing Request API, allowing customers to stay on a merchant's site when completing payment in the checkout flow.
* Add - Support for Instant Bank Pay for one-off payments.
* Add - Support for Success+ (intelligent retries) for payment creation.
* Add - Show the GoCardless payment status in the order list table and order preview.
* Add - Handle the mandate "cancelled", "expired", "failed", and "blocked" webhook events.
* Add - Display the refund ID and reference information in the order note related to a refund.
* Add - Save the GoCardless customer ID in user meta to use for creating all future payments/mandates.
* Add - Ensure the Country is auto-selected in the payment modal based on pre-filled customer data.
* Add - Screenshots to readme file.
* Dev - Add Code of Conduct and License files.
* Dev - Bump WooCommerce "tested up to" version 8.9.
* Dev - Bump WooCommerce minimum supported version to 8.7.
* Fix - Only display the schemes that are available to the merchant in the GoCardless settings.
* Fix - Replace the middleware URL from `connect.woocommerce.com` to `api.woocommerce.com/integrations`.
* Tweak - Improved the process of adding the webhook secret information.
* Tweak - Update the payment method title to "Pay by bank" and change the logo to the GoCardless logo.

= 2.6.4 - 2024-04-01 =
* Dev - Bump WooCommerce "tested up to" version 8.7.
* Dev - Bump WooCommerce minimum supported version to 8.4.
* Dev - Bump WordPress "tested up to" version 6.5.

= 2.6.3 - 2024-02-05 =
* Fix - Allow customers to update subscription payment methods via Woo Subscriptions.
* Dev - Bump WooCommerce "tested up to" version 8.5.
* Dev - Bump WooCommerce minimum supported version to 8.3.
* Dev - Bump WordPress minimum supported version to 6.3.

= 2.6.2 - 2024-01-08 =
* Tweak - Bump PHP "tested up to" version 8.3.
* Dev - Declare compatibility with Product Editor.
* Dev - Bump WooCommerce "tested up to" version 8.4.
* Dev - Bump WooCommerce minimum supported version to 8.2.
* Dev - Bump PHP minimum supported version to 7.4.

[See changelog details prior to 2024 here](https://github.com/gocardless/woocommerce-gateway-gocardless/blob/trunk/changelog.txt).
