/**
 * External dependencies
 */
const { test } = require('@playwright/test');

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
	validateGoCardlessPayment,
	blockPlaceGoCardlessOrderSchemeWise,
} = require('../utils');
const { products, customer, currencies } = require('../config');

test.describe('Direct Debit Scheme Tests', () => {
	test.beforeAll(async ({ browser }) => {
		const adminPage = await browser.newPage({
			storageState: process.env.ADMINSTATE,
		});

		// Make sure GoCardless is connected
		await connectWithGoCardless(adminPage);

		// Update Direct Debit Scheme to auto
		await adminPage.goto(
			'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=gocardless'
		);
		await adminPage
			.locator('#woocommerce_gocardless_scheme')
			.selectOption('');
		await saveSettings(adminPage);
	});

	test.afterAll(async ({ browser }) => {
		const adminPage = await browser.newPage({
			storageState: process.env.ADMINSTATE,
		});

		// Update Direct Debit Scheme to auto
		await adminPage.goto(
			'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=gocardless'
		);
		await adminPage
			.locator('#woocommerce_gocardless_scheme')
			.selectOption('');
		await saveSettings(adminPage);

		await runWpCliCommand('wp option update woocommerce_currency "USD"');
	});

	test.use({ storageState: process.env.ADMINSTATE });

	// Test for Autogiro Scheme not feasible due to verified mandate flow.
	const schemes = {
		ach: 'ACH',
		bacs: 'BACS',
		becs: 'BECS',
		becs_nz: 'BECS NZ',
		betalingsservice: 'Betalingsservice',
		pad: 'PAD (Pre-Authorized Debit)',
		sepa_core: 'SEPA Core',
	};

	for (const scheme in schemes) {
		if (scheme === 'betalingsservice') {
			continue; // Skip test temporarily and look into it later getting issue in sandbox, maybe a temporary issue.
		}
		// eslint-disable-next-line jest/expect-expect
		test(`${schemes[scheme]} - @foundational`, async ({ page }) => {
			const isBlock = true;
			const currency = currencies[scheme] || 'USD';
			await runWpCliCommand(
				`wp option update woocommerce_currency "${currency}"`
			);

			await page.goto(
				'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=gocardless'
			);
			await page
				.locator('#woocommerce_gocardless_scheme')
				.selectOption(scheme);
			await page.locator('#woocommerce_gocardless_enabled').check();
			await saveSettings(page);

			// Payment
			await addToCart(page, products.simple);
			await goToCheckout(page, isBlock);
			await fillBillingDetails(page, customer.addresses[scheme], isBlock);
			const orderId = await blockPlaceGoCardlessOrderSchemeWise(
				page,
				{
					saveMethod: false,
					isBlock,
					currency,
				},
				scheme
			);
			await validateGoCardlessPayment(page, orderId);
		});
	}
});
