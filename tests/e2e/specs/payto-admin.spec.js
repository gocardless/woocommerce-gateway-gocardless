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
	saveSettings,
	enablePayToInSettings,
	runWpCliCommand,
	fillBillingDetails,
} = require('../utils');
const {
	paytoPaymentMethodTitle,
	paytoPaymentMethodDescription,
	products,
	customer,
} = require('../config');

test.describe('PayTo admin', () => {
	test.use({ storageState: process.env.ADMINSTATE });
	const auBilling = { ...customer.billing, ...customer.addresses.becs };

	test.beforeAll(async ({ browser }) => {
		await runWpCliCommand('wp option update woocommerce_currency "AUD"');
	});

	test.afterAll(async () => {
		await runWpCliCommand('wp option update woocommerce_currency "USD"');
	});

	/**
	 * - Setup and Configuration
	 *   - GoCardless Settings > PayTo > Enable / Disable PayTo
	 *   - GoCardless Settings > PayTo > Title / Description
	 *   - GoCardless Settings > Save Bank account
	 */
	test('Store owner can configure GoCardless PayTo payment gateway - @foundational', async ({
		page,
	}) => {
		// Make sure GoCardless is connected
		await connectWithGoCardless(page);

		await page.goto(
			'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=gocardless'
		);
		await expect(
			page.getByRole('heading', {
				name: 'Bank pay (open banking and direct debit via GoCardless)',
			})
		).toBeVisible();

		await page.locator('#woocommerce_gocardless_payto_enabled').uncheck();
		await saveSettings(page);

		// Make sure GoCardless is not visible on traditional checkout page
		await addToCart(page, products.simple);
		await goToCheckout(page);
		await fillBillingDetails(page, auBilling);
		await expect(
			page.locator('ul.wc_payment_methods li.payment_method_gocardless_payto')
		).not.toBeVisible();

		// Make sure GoCardless is not visible on block checkout page
		await goToCheckout(page, true);
		await fillBillingDetails(page, auBilling, true);
		await expect(
			page.locator(
				'label[for="radio-control-wc-payment-method-options-gocardless_payto"]'
			)
		).not.toBeVisible();

		await page.goto(
			'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=gocardless'
		);
		await page.locator('#woocommerce_gocardless_payto_enabled').check();
		await page
			.locator('#woocommerce_gocardless_payto_title')
			.fill(paytoPaymentMethodTitle);
		await page
			.locator('#woocommerce_gocardless_payto_description')
			.fill(paytoPaymentMethodDescription);
		await page
			.locator('#woocommerce_gocardless_saved_bank_accounts')
			.uncheck();
		await saveSettings(page);

		// Make sure GoCardless is visible on traditional checkout page
		await goToCheckout(page);
		await fillBillingDetails(page, auBilling);
		await expect(
			page.locator('ul.wc_payment_methods li.payment_method_gocardless_payto')
		).toBeVisible();
		await expect(
			page.locator(
				'li.payment_method_gocardless_payto label[for="payment_method_gocardless_payto"]'
			)
		).toContainText(paytoPaymentMethodTitle);
		await page.locator(
			'li.payment_method_gocardless_payto label[for="payment_method_gocardless_payto"]'
		).click();
		await expect(
			page.locator('.payment_box.payment_method_gocardless_payto p', {
				hasText: paytoPaymentMethodDescription,
			})
		).toBeVisible();

		// Make sure GoCardless is visible on block checkout page
		await goToCheckout(page, true);
		await fillBillingDetails(page, auBilling, true);
		await expect(
			page.locator(
				'label[for="radio-control-wc-payment-method-options-gocardless_payto"]'
			)
		).toBeVisible();
		await page.locator(
			'label[for="radio-control-wc-payment-method-options-gocardless_payto"]'
		).click();
		await expect(
			page.locator(
				'#radio-control-wc-payment-method-options-gocardless_payto__label span'
			)
		).toContainText(paytoPaymentMethodTitle);
		await expect(
			page.locator(
				'.wc-block-components-radio-control-accordion-content',
				{
					hasText: paytoPaymentMethodDescription,
				}
			)
		).toBeVisible();
		await expect(
			page.locator(
				".wc-block-components-payment-methods__save-card-info input[type='checkbox']"
			)
		).not.toBeVisible();

		// Enable Save Bank Account.
		await page.goto(
			'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=gocardless'
		);
		await page
			.locator('#woocommerce_gocardless_saved_bank_accounts')
			.check();
		await saveSettings(page);

		// Verify save bank account on traditional checkout page.
		await goToCheckout(page);
		await fillBillingDetails(page, auBilling);
		await page.locator(
			'li.payment_method_gocardless_payto label[for="payment_method_gocardless_payto"]'
		).click();
		await expect(
			page.locator('#wc-gocardless_payto-new-payment-method')
		).toBeVisible();

		// Verify save bank account on block checkout page.
		await goToCheckout(page, true);
		await fillBillingDetails(page, auBilling, true);
		await page
			.locator(
				'label[for="radio-control-wc-payment-method-options-gocardless_payto"]'
			)
			.click();
		await expect(
			page.locator(
				".wc-block-components-payment-methods__save-card-info input[type='checkbox']"
			)
		).toBeVisible();
	});
});
