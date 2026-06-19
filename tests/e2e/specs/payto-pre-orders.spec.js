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
	placePayToOrder,
	createPreOrderProduct,
	validateGoCardlessPayment,
	completePreOrder,
	runWpCliCommand,
	enablePayToInSettings,
} = require('../utils');
const { customer } = require('../config');

test.describe('PayTo Pre-Orders Tests', () => {
	let adminPage;
	const auBilling = { ...customer.billing, ...customer.addresses.becs };

	test.use({ storageState: process.env.CUSTOMERSTATE });

	test.beforeAll(async ({ browser }) => {
		adminPage = await browser.newPage({
			storageState: process.env.ADMINSTATE,
		});

		await runWpCliCommand('wp option update woocommerce_currency "AUD"');
		await connectWithGoCardless(adminPage);
		await enablePayToInSettings(adminPage, true);
	});

	test.afterAll(async () => {
		await runWpCliCommand('wp option update woocommerce_currency "USD"');
		await enablePayToInSettings(adminPage, false);
		await adminPage.close();
	});

	test('[Charge upon release] PayTo should work with Pre-Orders - @foundational', async ({
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
		await fillBillingDetails(page, auBilling, true);
		const orderId = await placePayToOrder(page, { saveMethod: false, isBlock: true });

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

		await completePreOrder(adminPage, orderId);

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

	test('[Upfront Charge] PayTo should work with Pre-Orders - @foundational', async ({
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
		await fillBillingDetails(page, auBilling, true);
		const orderId = await placePayToOrder(page, { saveMethod: false, isBlock: true });

		const nRetries = 5;
		for (let i = 0; i < nRetries; i++) {
			await adminPage.goto(
				`/wp-admin/post.php?post=${orderId}&action=edit`
			);
			const orderStatus = await adminPage
				.locator('select[name="order_status"]')
				.evaluate((el) => el.value);
			const note = await adminPage
				.locator(
					'#woocommerce-gocardless-webhook-events ul.order_notes li',
					{ hasText: 'payments confirmed' }
				)
				.first()
				.isVisible();
			if (orderStatus === 'wc-pre-ordered' && note) {
				break;
			}
			await adminPage.waitForTimeout(10000);
		}

		await adminPage.goto(`/wp-admin/post.php?post=${orderId}&action=edit`);
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

		await completePreOrder(adminPage, orderId);
		await validateGoCardlessPayment(adminPage, orderId, false, true);
	});
});
