/**
 * External dependencies
 */
const { test, expect } = require('@playwright/test');

/**
 * Internal dependencies
 */
const {
	addToCart,
	connectWithGoCardless,
	goToCheckout,
	fillBillingDetails,
	placeGoCardlessOrder,
	validateGoCardlessPayment,
	clearEmailLogs,
	runWpCliCommand,
} = require('../utils');
const { products, customer, paymentMethodTitle } = require('../config');

test.describe('Email Tests', () => {
	let adminPage;
	test.use({ storageState: process.env.CUSTOMERSTATE });

	test.beforeAll(async ({ browser }) => {
		adminPage = await browser.newPage({
			storageState: process.env.ADMINSTATE,
		});
		await connectWithGoCardless(adminPage);
		await runWpCliCommand('wp option update woocommerce_currency "USD"');
	});

	/**
	 * Covers critical flow:
	 * - Email Confirmation for Merchant After Successful Order
	 * - Email Confirmation for Customer After Successful Order
	 */
	test('Store admin and Customer should get an email with Order Details - @foundational', async ({
		page,
	}) => {
		await clearEmailLogs(adminPage);
		await addToCart(page, products.simple);
		await goToCheckout(page, true);
		await fillBillingDetails(page, customer.billing, true);

		const orderId = await placeGoCardlessOrder(page, {
			saveMethod: false,
			isBlock: true,
		});
		await validateGoCardlessPayment(adminPage, orderId);

		await adminPage.goto('/wp-admin/admin.php?page=email-log');
		// Verify store admin email.
		const emailRow = await adminPage
			.locator('#the-list tr', {
				hasText: `[woocommerce-gateway-gocardless]: New order #${orderId}`,
			})
			.first();
		await emailRow.locator('td.sent_date').hover();
		await emailRow.locator('.view-content a').click();
		await expect(
			await adminPage
				.locator('#body_content_inner tr', {
					hasText: 'Payment method:',
				})
				.first()
				.locator('td')
		).toContainText(paymentMethodTitle);
		await adminPage.locator('#TB_closeWindowButton').click();

		// Verify customer email.
		const customerEmailRow = await adminPage
			.locator('#the-list tr', {
				hasText:
					'Your woocommerce-gateway-gocardless order has been received!',
			})
			.first();
		await customerEmailRow.locator('td.sent_date').hover();
		await customerEmailRow.locator('.view-content a').click();
		await expect(
			await adminPage
				.locator('#body_content_inner tr', {
					hasText: 'Payment method:',
				})
				.first()
				.locator('td')
		).toContainText(paymentMethodTitle);
	});
});
