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
} = require('../utils');
const { products, customer } = require('../config');

test.describe('My Account Tests', () => {
	// Set customer as logged-in user.
	let adminPage;
	test.use({ storageState: process.env.CUSTOMERSTATE });

	test.beforeAll(async ({ browser }) => {
		adminPage = await browser.newPage({
			storageState: process.env.ADMINSTATE,
		});

		await connectWithGoCardless(adminPage);
	});

	test('Customer can remove saved payment method - @foundational', async ({
		page,
	}) => {
		// Verify customer have saved payment method.
		await page.goto('/my-account/payment-methods/');
		const hasSavedMethod = await page
			.locator('td.woocommerce-PaymentMethod', {
				hasText: 'Community Federal Savings Bank ending in 56',
			})
			.first()
			.isVisible();
		if (!hasSavedMethod) {
			await addToCart(page, products.simple);
			await goToCheckout(page);
			await fillBillingDetails(page, customer.billing);
			await placeGoCardlessOrder(page, {
				saveMethod: true,
				isBlock: false,
			});
			await page.goto('/my-account/payment-methods/');
		}

		const paymentMethod = await page
			.locator('tr.payment-method', {
				hasText: 'Community Federal Savings Bank ending in 56',
			})
			.first();
		await paymentMethod.locator('a.delete').click();
		await expect(
			page.getByText('Payment method deleted.').first()
		).toBeVisible();
	});
});
