/* eslint-disable jest/no-done-callback */
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
	runWpCliCommand,
	processRefund,
	clearCart,
} = require('../utils');
const { products, customer } = require('../config');

test.describe('Refunds Tests', () => {
	// Set customer as logged-in user.
	const customerBilling = {
		...customer.billing,
		email: 'test-pay_out@test.com',
		country: 'GB',
		countryName: 'United Kingdom',
		city: 'London',
		postcode: 'WC2N 5DU',
	};
	test.use({ storageState: process.env.CUSTOMERSTATE });

	test.beforeAll(async ({ browser }) => {
		const adminPage = await browser.newPage({
			storageState: process.env.ADMINSTATE,
		});

		await connectWithGoCardless(adminPage);
		await runWpCliCommand('wp option update woocommerce_currency "GBP"');
	});

	// Covers critical flow: Supported Country and Currency
	test('Mechant can issue partial refund on GoCardless - @foundational', async ({
		page,
		browser,
	}) => {
		const adminPage = await browser.newPage({
			storageState: process.env.ADMINSTATE,
		});

		const isBlock = true;
		// Create an order with GoCardless to make sure we have some balance available to refund.
		await clearCart(page);
		await addToCart(page, products.simple2);
		await goToCheckout(page, isBlock);
		await fillBillingDetails(
			page,
			{ ...customer.billing, email: 'test-pay_out@test.com' },
			isBlock
		);
		await placeGoCardlessOrder(page, {
			saveMethod: false,
			isBlock,
			customerBilling,
			currency: 'GBP',
		});

		await clearCart(page);
		await addToCart(page, products.simple);
		await goToCheckout(page, isBlock);
		await fillBillingDetails(page, customerBilling, isBlock);

		const orderId = await placeGoCardlessOrder(page, {
			saveMethod: false,
			isBlock,
			customerBilling,
			currency: 'GBP',
		});
		await validateGoCardlessPayment(adminPage, orderId);

		// Refund.
		adminPage.on('dialog', (dialog) => dialog.accept());
		await adminPage.goto(`/wp-admin/post.php?post=${orderId}&action=edit`);
		await processRefund(adminPage, '1.00');
		await expect(
			adminPage
				.locator('#woocommerce-order-notes ul.order_notes li', {
					hasText: 'Refunded £1.00',
				})
				.first()
		).toBeVisible();

		await adminPage.goto(`/wp-admin/post.php?post=${orderId}&action=edit`);
		await processRefund(adminPage, '2.00');
		await expect(
			adminPage
				.locator('#woocommerce-order-notes ul.order_notes li', {
					hasText: 'Refunded £2.00',
				})
				.first()
		).toBeVisible();
	});

	// Covers critical flow: Supported Country and Currency
	test('Merchant can issue full refund on GoCardless order - @foundational', async ({
		page,
		browser,
	}) => {
		const adminPage = await browser.newPage({
			storageState: process.env.ADMINSTATE,
		});
		await clearCart(page);
		const isBlock = true;
		await addToCart(page, products.simple);
		await goToCheckout(page, isBlock);
		await fillBillingDetails(page, customerBilling, isBlock);

		const orderId = await placeGoCardlessOrder(page, {
			saveMethod: false,
			isBlock,
			customerBilling,
			currency: 'GBP',
		});
		await validateGoCardlessPayment(adminPage, orderId);

		// Refund.
		adminPage.on('dialog', (dialog) => dialog.accept());
		await adminPage.goto(`/wp-admin/post.php?post=${orderId}&action=edit`);
		await processRefund(adminPage, '10.00');
		await expect(
			adminPage
				.locator('#woocommerce-order-notes ul.order_notes li', {
					hasText: 'Refunded £10.00',
				})
				.first()
		).toBeVisible();
		const orderStatus = await adminPage.locator(
			'select[name="order_status"]'
		);
		await expect(await orderStatus.evaluate((el) => el.value)).toBe(
			'wc-refunded'
		);
	});
});
