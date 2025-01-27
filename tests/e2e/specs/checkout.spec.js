/* eslint-disable jest/expect-expect */
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
	placeOrder,
	validateGoCardlessPayment,
	runWpCliCommand,
} = require('../utils');
const { products, customer } = require('../config');

test.describe('Checkout Tests', () => {
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

	const checkouts = [true, false];

	checkouts.forEach((isBlock) => {
		const blockText = isBlock ? '[Block Checkout]' : '[Checkout]';

		// Covers critical flow: Supported Country and Currency
		test(`${blockText} GoCardless is available for specific countries only - @foundational`, async ({
			page,
		}) => {
			const nonSupportedCountry = {
				country: 'IN',
				countryName: 'India',
				state: 'GJ',
				stateName: 'Gujarat',
			};
			await addToCart(page, products.simple);
			await goToCheckout(page, isBlock);
			await fillBillingDetails(
				page,
				{ ...customer.billing, ...nonSupportedCountry },
				isBlock
			);

			let goCardlessLocator =
				'ul.wc_payment_methods li.payment_method_gocardless';
			if (isBlock) {
				goCardlessLocator =
					'label[for="radio-control-wc-payment-method-options-gocardless"]';
			}
			await expect(page.locator(goCardlessLocator)).not.toBeVisible();

			await fillBillingDetails(page, customer.billing, isBlock);
			await expect(page.locator(goCardlessLocator).first()).toBeVisible();
		});

		test(`${blockText} GoCardless is available for specific currency only - @foundational`, async ({
			page,
		}) => {
			await runWpCliCommand(
				'wp option update woocommerce_currency "INR"'
			);
			await addToCart(page, products.simple);
			await goToCheckout(page, isBlock);
			await fillBillingDetails(page, customer.billing, isBlock);

			let goCardlessLocator =
				'ul.wc_payment_methods li.payment_method_gocardless';
			if (isBlock) {
				goCardlessLocator =
					'label[for="radio-control-wc-payment-method-options-gocardless"]';
			}
			await expect(page.locator(goCardlessLocator)).not.toBeVisible();

			await runWpCliCommand(
				'wp option update woocommerce_currency "USD"'
			);
			await goToCheckout(page, isBlock);
			await fillBillingDetails(page, customer.billing, isBlock);
			await expect(page.locator(goCardlessLocator).first()).toBeVisible();
		});

		// Covers critical flow: Checkout flow.
		test(`${blockText} Guest customer can place order with GoCardless payment gateway - @foundational`, async ({
			browser,
		}) => {
			const context = await browser.newContext({
				storageState: {},
			});
			const page = await context.newPage();
			await addToCart(page, products.simple);
			await goToCheckout(page, isBlock);
			await fillBillingDetails(page, {
				...customer.billing,
				email: 'joe.' + Date.now() + '@example.com',
			}, isBlock);
			const orderId = await placeGoCardlessOrder(page, {
				saveMethod: false,
				isBlock,
			});
			await validateGoCardlessPayment(adminPage, orderId);
		});

		test(`${blockText} Guest customer can create account and place order with GoCardless payment gateway - @foundational`, async ({
			browser,
		}) => {
			const context = await browser.newContext({
				storageState: {},
			});
			const page = await context.newPage();
			await addToCart(page, products.simple);
			await goToCheckout(page, isBlock);
			await fillBillingDetails(page, {
				...customer.billing,
				email: 'joe.' + Date.now() + '@example.com',
			}, isBlock);
			if (isBlock) {
				await page
					.locator(
						'.wc-block-checkout__create-account input[type="checkbox"]'
					)
					.check();
				await page.waitForTimeout(2000);
			} else {
				await page.locator('#createaccount').check();
			}
			const orderId = await placeGoCardlessOrder(page, {
				saveMethod: false,
				isBlock,
			});
			await validateGoCardlessPayment(adminPage, orderId);
		});

		// Covers critical flow: Checkout flow.
		test(`${blockText} Customer can place order with GoCardless payment gateway - @foundational`, async ({
			page,
		}) => {
			await addToCart(page, products.simple);
			await goToCheckout(page, isBlock);
			await fillBillingDetails(page, customer.billing, isBlock);
			const orderId = await placeGoCardlessOrder(page, {
				saveMethod: false,
				isBlock,
			});
			await validateGoCardlessPayment(adminPage, orderId);

			const customerId = await runWpCliCommand(
				'wp user meta get customer _gocardless_customer_id'
			);
			await expect(customerId).not.toBeFalsy();
		});

		// Covers critical flow: Add new payment method.
		test(`${blockText} Customer can save payment details for future checkout. - @foundational`, async ({
			page,
		}) => {
			await addToCart(page, products.simple);
			await goToCheckout(page, isBlock);
			await fillBillingDetails(page, customer.billing, isBlock);

			const orderId = await placeGoCardlessOrder(page, {
				saveMethod: true,
				isBlock,
			});
			await validateGoCardlessPayment(adminPage, orderId);

			await page.goto('/my-account/payment-methods/');
			await expect(
				page
					.locator('td.woocommerce-PaymentMethod', {
						hasText: 'Community Federal Savings Bank ending in 56',
					})
					.last()
			).toBeVisible();
		});

		test(`${blockText} Customer can place order with saved payment method - @foundational`, async ({
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
				await goToCheckout(page, isBlock);
				await fillBillingDetails(page, customer.billing, isBlock);
				await placeGoCardlessOrder(page, { saveMethod: true, isBlock });
			}

			await addToCart(page, products.simple);
			await goToCheckout(page, isBlock);
			await fillBillingDetails(page, customer.billing, isBlock);

			if (isBlock) {
				await page
					.locator(
						'#radio-control-wc-payment-method-options-gocardless'
					)
					.check();
				await page
					.locator(
						'input[name="radio-control-wc-payment-method-saved-tokens"]'
					)
					.first()
					.check();
			} else {
				await page
					.locator(
						'li.woocommerce-SavedPaymentMethods-token input[name="wc-gocardless-payment-token"]'
					)
					.first()
					.check();
			}
			const orderId = await placeOrder(page, isBlock);
			await validateGoCardlessPayment(adminPage, orderId);
		});

		test(`${blockText} Guest customer can sign up to subscription using GoCardless payment gateway - @foundational`, async ({
			browser,
		}) => {
			const context = await browser.newContext({
				storageState: {},
			});
			const page = await context.newPage();
			await addToCart(page, products.subscription);
			await goToCheckout(page, isBlock);
			await fillBillingDetails(page, {
				...customer.billing,
				email: 'joe.' + Date.now() + '@example.com',
			}, isBlock);

			const orderId = await placeGoCardlessOrder(page, {
				saveMethod: false,
				isBlock,
			});
			await validateGoCardlessPayment(adminPage, orderId);
			await adminPage
				.locator('.woocommerce_subscriptions_related_orders tr td a')
				.first()
				.click();
			await expect(
				await adminPage
					.locator('#order_status')
					.evaluate((el) => el.value)
			).toEqual('wc-active');
		});

		// Covers critical flow: Cross check the Subscription flow with Gocardless.
		test(`${blockText} Customer can sign up to subscription using GoCardless payment gateway - @foundational`, async ({
			page,
		}) => {
			await addToCart(page, products.subscription);
			await goToCheckout(page, isBlock);
			await fillBillingDetails(page, customer.billing, isBlock);

			const orderId = await placeGoCardlessOrder(page, {
				saveMethod: false,
				isBlock,
			});
			await validateGoCardlessPayment(adminPage, orderId);
			await adminPage
				.locator('.woocommerce_subscriptions_related_orders tr td a')
				.first()
				.click();
			await expect(
				await adminPage
					.locator('#order_status')
					.evaluate((el) => el.value)
			).toEqual('wc-active');
		});
	});

	// Covers critical flow: Cross check the Subscription flow with Gocardless.
	test('Subscription renewal using GoCardless payment gateway - @foundational', async ({
		page,
	}) => {
		await addToCart(page, products.subscription);
		await goToCheckout(page, true);
		await fillBillingDetails(page, customer.billing, true);

		const orderId = await placeGoCardlessOrder(page, {
			saveMethod: false,
			isBlock: true,
		});
		await validateGoCardlessPayment(adminPage, orderId);
		await adminPage
			.locator('.woocommerce_subscriptions_related_orders tr td a')
			.first()
			.click();
		await expect(
			await adminPage.locator('#order_status').evaluate((el) => el.value)
		).toEqual('wc-active');

		await adminPage
			.locator("select[name='wc_order_action']")
			.selectOption('wcs_process_renewal');
		await adminPage.on('dialog', (dialog) => dialog.accept());
		await adminPage.locator('#actions button.wc-reload').click();
		// await expect(
		// 	adminPage
		// 		.locator(
		// 			'#message.updated.notice.notice-success.is-dismissible'
		// 		)
		// 		.first()
		// ).toContainText('Subscription updated.');
		await expect(
			adminPage
				.locator(
					'.woocommerce_subscriptions_related_orders tr td mark.order-status'
				)
				.first()
		).toContainText('Processing');
	});
});
