/* eslint-disable jest/no-done-callback */
/**
 * External dependencies
 */
const { test, expect } = require('@playwright/test');

/**
 * Internal dependencies
 */
const {
	connectWithGoCardless,
	goToCheckout,
	fillBillingDetails,
	placeGoCardlessOrder,
	createPreOrderProduct,
	validateGoCardlessPayment,
	completePreOrder,
	runWpCliCommand,
} = require('../utils');
const { customer } = require('../config');

test.describe('Pre-Orders Tests', () => {
	// Set customer as logged-in user.
	let adminPage;
	const customerBilling = {
		...customer.billing,
		country: 'GB',
		countryName: 'United Kingdom',
		city: 'London',
		postcode: 'WC2N 5DU',
	};
	test.use({ storageState: process.env.CUSTOMERSTATE });

	test.beforeAll(async ({ browser }) => {
		adminPage = await browser.newPage({
			storageState: process.env.ADMINSTATE,
		});

		await runWpCliCommand('wp option update woocommerce_currency "GBP"');
		await connectWithGoCardless(adminPage);
	});

	// Covers critical flow: Supported Country and Currency
	test('[Charge upon release] GoCardless should work with Pre-Orders - @foundational', async ({
		page,
	}) => {
		const productId = await createPreOrderProduct(adminPage, {
			whenToCharge: 'upon_release',
		});
		await page.goto('/?p=' + productId);
		await page.locator('.single_add_to_cart_button').click();
		await expect(
			page.getByRole('link', { name: 'View cart' }).first()
		).toBeVisible();
		await goToCheckout(page, true);
		await fillBillingDetails(page, customerBilling, true);
		const orderId = await placeGoCardlessOrder(page, {
			saveMethod: false,
			isBlock: true,
			currency: 'GBP',
			customerBilling,
		});

		// Verify order status is Pre-Ordered.
		await adminPage.goto(`/wp-admin/post.php?post=${orderId}&action=edit`);
		const orderStatus = await adminPage.locator(
			'select[name="order_status"]'
		);
		await expect(await orderStatus.evaluate((el) => el.value)).toBe(
			'wc-pre-ordered'
		);
		await expect(
			adminPage
				.locator(
					'#woocommerce-gocardless-webhook-events ul.order_notes li',
					{ hasText: 'payments confirmed' }
				)
				.first()
		).not.toBeVisible();

		// Complete pre-order.
		await completePreOrder(adminPage, orderId);

		// Verify order status is On-Hold.
		// Note: Order status will be on-hold as we didn't simulated the webhook event for pre-orders.
		await adminPage.goto(`/wp-admin/post.php?post=${orderId}&action=edit`);
		await expect(
			await adminPage.locator('#order_status').evaluate((el) => el.value)
		).toEqual('wc-on-hold');
		await expect(
			adminPage
				.locator('#woocommerce-order-notes ul.order_notes li', {
					hasText: 'GoCardless payment created with',
				})
				.first()
		).toBeVisible();
	});

	// Covers critical flow: Supported Country and Currency
	test('[Upfront Charge] GoCardless should work with Pre-Orders - @foundational', async ({
		page,
	}) => {
		const productId = await createPreOrderProduct(adminPage, {
			whenToCharge: 'upfront',
		});
		await page.goto('/?p=' + productId);
		await page.locator('.single_add_to_cart_button').click();
		await expect(
			page.getByRole('link', { name: 'View cart' }).first()
		).toBeVisible();
		await goToCheckout(page, true);
		await fillBillingDetails(page, customerBilling, true);
		const orderId = await placeGoCardlessOrder(page, {
			saveMethod: false,
			isBlock: true,
			currency: 'GBP',
			customerBilling,
		});

		// Verify order status is Pre-Ordered.
		const nRetries = 5;
		for (let i = 0; i < nRetries; i++) {
			await adminPage.goto(
				`/wp-admin/post.php?post=${orderId}&action=edit`
			);
			const orderStatus = await adminPage
				.locator('select[name="order_status"]')
				.evaluate((el) => el.value);
			if (orderStatus === 'wc-pre-ordered') {
				break;
			} else {
				await page.waitForTimeout(10000); // wait for webhook to be processed
			}
		}
		const orderStatus = await adminPage
			.locator('select[name="order_status"]')
			.evaluate((el) => el.value);
		await expect(orderStatus).toBe('wc-pre-ordered');
		await expect(
			adminPage
				.locator(
					'#woocommerce-gocardless-webhook-events ul.order_notes li',
					{ hasText: 'payments confirmed' }
				)
				.first()
		).toBeVisible();

		// Complete pre-order.
		await completePreOrder(adminPage, orderId);

		// Verify order status is processing.
		await validateGoCardlessPayment(adminPage, orderId);
	});
});
