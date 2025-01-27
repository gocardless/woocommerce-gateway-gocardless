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
	runWpCliCommand,
} = require('../utils');
const { products, customer } = require('../config');

test.describe('Mandate Cancel Event Tests', () => {
	// Set customer as logged-in user.
	let adminPage;
	test.use({ storageState: process.env.CUSTOMERSTATE });

	test.beforeAll(async ({ browser }) => {
		adminPage = await browser.newPage({
			storageState: process.env.ADMINSTATE,
		});

		await connectWithGoCardless(adminPage);
		await runWpCliCommand('wp option update woocommerce_currency "USD"');
	});

	test('Mandate cancel should remove the saved payment method - @foundational', async ({
		page,
	}) => {
		const isBlock = true;
		await addToCart(page, products.simple);
		await goToCheckout(page, isBlock);
		await fillBillingDetails(page, customer.billing, isBlock);

		const orderId = await placeGoCardlessOrder(page, {
			saveMethod: true,
			isBlock,
		});

		const paymentMethodSelector =
			'td.woocommerce-PaymentMethod.payment-method-method';
		await page.goto('/my-account/payment-methods/');
		await expect(
			page
				.locator(paymentMethodSelector, {
					hasText: 'Community Federal Savings Bank ending in 56',
				})
				.last()
		).toBeVisible();

		const count = await page.locator(paymentMethodSelector).count();

		await page.goto(`?cancel_mandate_order_id=${orderId}`);

		// Verify that the saved payment method removed.
		const nRetries = 5;
		for (let i = 0; i < nRetries; i++) {
			await page.goto('/my-account/payment-methods/');
			const updatedCount = await page
				.locator(paymentMethodSelector)
				.count();
			if (count > updatedCount) {
				break;
			} else {
				await page.waitForTimeout(10000); // wait for webhook to be processed
			}
		}

		await expect(await page.locator(paymentMethodSelector).count()).toBe(
			count - 1
		);
	});
});
