/**
 * External dependencies
 */
const { test, expect } = require('@playwright/test');

/**
 * Internal dependencies
 */
const {
	addToCart,
	runWpCliCommand,
	connectWithGoCardless,
	goToCheckout,
	saveSettings,
	fillBillingDetails,
	blockPlaceGoCardlessOrderSchemeWise,
} = require('../utils');
const { products, customer } = require('../config');

/**
 * Regression test for the Bacs dual-signature subscription persistence
 * vulnerability.
 *
 * A Bacs dual-signature mandate leaves the initial payment in
 * `pending_customer_approval` (an approval-dependent state that may never
 * progress). The plugin must NOT treat that as an in-progress collection: the
 * parent order must stay `on-hold` and the subscription must NOT become active
 * until the payment reaches a merchant-approved finite/confirmed state.
 *
 * The GoCardless sandbox cannot be made to return `pending_customer_approval`
 * on demand, so the E2E test plugin forces it via the
 * `gocardless_e2e_force_payment_status` option (a WP `http_response` filter
 * rewrites only the payment `status`), and suppresses the auto-confirm webhook
 * simulator while the option is set.
 */
test.describe('Subscription temporary activation - Bacs dual-signature', () => {
	const FORCE_OPTION = 'gocardless_e2e_force_payment_status';

	test.use({ storageState: process.env.CUSTOMERSTATE });

	test.beforeAll(async ({ browser }) => {
		const adminPage = await browser.newPage({
			storageState: process.env.ADMINSTATE,
		});

		// Make sure GoCardless is connected and the Bacs scheme (GBP) is active.
		await connectWithGoCardless(adminPage);
		await runWpCliCommand('wp option update woocommerce_currency "GBP"');

		await adminPage.goto(
			'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=gocardless'
		);
		await adminPage
			.locator('#woocommerce_gocardless_instant_bank_pay')
			.uncheck();
		await adminPage
			.locator('#woocommerce_gocardless_scheme')
			.selectOption('bacs');
		await adminPage.locator('#woocommerce_gocardless_enabled').check();
		await saveSettings(adminPage);
	});

	test.afterAll(async ({ browser }) => {
		// Always clear the forced status so other specs are unaffected.
		await runWpCliCommand(`wp option delete ${FORCE_OPTION}`);

		const adminPage = await browser.newPage({
			storageState: process.env.ADMINSTATE,
		});
		await adminPage.goto(
			'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=gocardless'
		);
		await adminPage
			.locator('#woocommerce_gocardless_scheme')
			.selectOption('');
		await saveSettings(adminPage);

		await runWpCliCommand('wp option update woocommerce_currency "USD"');
	});

	test('pending_customer_approval must not activate the subscription - @foundational', async ({
		page,
		browser,
	}) => {
		const adminPage = await browser.newPage({
			storageState: process.env.ADMINSTATE,
		});

		// Force the initial payment to report `pending_customer_approval`.
		await runWpCliCommand(
			`wp option update ${FORCE_OPTION} pending_customer_approval`
		);

		// Sign up to the subscription via the Bacs (dual-signature) hosted flow.
		await addToCart(page, products.subscription);
		await goToCheckout(page, true);
		await fillBillingDetails(page, customer.addresses.bacs, true);
		const orderId = await blockPlaceGoCardlessOrderSchemeWise(
			page,
			{
				saveMethod: false,
				isBlock: true,
				currency: 'GBP',
			},
			'bacs',
			false
		);

		// Clear the forced status immediately after the order is placed.
		await runWpCliCommand(`wp option delete ${FORCE_OPTION}`);

		// The parent order must be on-hold, NOT processing.
		await adminPage.goto(
			`/wp-admin/post.php?post=${orderId}&action=edit`
		);
		const orderStatus = await adminPage
			.locator('#order_status')
			.evaluate((el) => el.value);
		expect(orderStatus).toEqual('wc-on-hold');
		expect(orderStatus).not.toEqual('wc-processing');

		// The related subscription must NOT be active.
		await adminPage
			.locator('.woocommerce_subscriptions_related_orders tr td a')
			.first()
			.click();
		const subscriptionStatus = await adminPage
			.locator('#order_status')
			.evaluate((el) => el.value);
		expect(subscriptionStatus).not.toEqual('wc-active');
	});
});
