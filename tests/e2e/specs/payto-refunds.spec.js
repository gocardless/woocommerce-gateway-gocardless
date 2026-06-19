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
	placePayToOrder,
	validateGoCardlessPayment,
	runWpCliCommand,
	processRefund,
	clearCart,
	enablePayToInSettings,
} = require('../utils');
const { products, customer } = require('../config');

test.describe('PayTo Refunds Tests', () => {
	const auBilling = {
		...customer.billing,
		...customer.addresses.becs,
		email: 'test-pay_out@test.com',
	};

	let adminPage;

	test.use({ storageState: process.env.CUSTOMERSTATE });

	test.beforeAll(async ({ browser }) => {
		adminPage = await browser.newPage({
			storageState: process.env.ADMINSTATE,
		});

		adminPage.on('dialog', (dialog) => dialog.accept());
		await runWpCliCommand('wp option update woocommerce_currency "AUD"');
		await connectWithGoCardless(adminPage);
		await enablePayToInSettings(adminPage, true);
	});

	test.afterAll(async () => {
		await runWpCliCommand('wp option update woocommerce_currency "USD"');
		await enablePayToInSettings(adminPage, false);
		await adminPage.close();
	});

	test('Merchant can issue partial refund on PayTo order - @foundational', async ({
		page,
	}) => {
		test.slow();
		const isBlock = true;

		await clearCart(page);
		await addToCart(page, products.simple2);
		await goToCheckout(page, isBlock);
		await page.waitForTimeout(1000);
		await fillBillingDetails(page, auBilling, isBlock);
		await placePayToOrder(page, { saveMethod: false, isBlock });

		await clearCart(page);
		await addToCart(page, products.simple);
		await goToCheckout(page, isBlock);
		await fillBillingDetails(page, auBilling, isBlock);

		const orderId = await placePayToOrder(page, { saveMethod: false, isBlock });
		await validateGoCardlessPayment(adminPage, orderId, false, true);

		await adminPage.goto(`/wp-admin/post.php?post=${orderId}&action=edit`);
		await processRefund(adminPage, '1.00');
		await expect(
			adminPage
				.locator('#woocommerce-order-notes ul.order_notes li', {
					hasText: 'Refunded $1.00',
				})
				.first()
		).toBeVisible();

		await adminPage.goto(`/wp-admin/post.php?post=${orderId}&action=edit`);
		await processRefund(adminPage, '2.00');
		await expect(
			adminPage
				.locator('#woocommerce-order-notes ul.order_notes li', {
					hasText: 'Refunded $2.00',
				})
				.first()
		).toBeVisible();
	});

	test('Merchant can issue full refund on PayTo order - @foundational', async ({
		page,
	}) => {
		await clearCart(page);
		const isBlock = true;
		await addToCart(page, products.simple);
		await goToCheckout(page, isBlock);
		await page.waitForTimeout(1000);
		await fillBillingDetails(page, auBilling, isBlock);

		const orderId = await placePayToOrder(page, { saveMethod: false, isBlock });
		await validateGoCardlessPayment(adminPage, orderId, false, true);

		await adminPage.goto(`/wp-admin/post.php?post=${orderId}&action=edit`);
		await processRefund(adminPage, '10.00');
		await expect(
			adminPage
				.locator('#woocommerce-order-notes ul.order_notes li', {
					hasText: 'Refunded $10.00',
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
