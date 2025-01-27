/* eslint-disable camelcase */

jQuery(function ($) {
	/**
	 * GoCardless Checkout Handler class.
	 *
	 * @class WC_GoCardless_Checkout_Handler
	 * @since 2.7.0
	 */
	class WC_GoCardless_Checkout_Handler {
		/**
		 * Constructor.
		 *
		 * @since 2.7.0
		 *
		 * @param {Object} args Arguments.
		 */
		constructor(args) {
			this.id = 'gocardless';
			this.order_id = args.order_id;
			this.is_test = args.is_test;
			this.ajax_url = args.ajax_url;
			this.generic_error = args.generic_error;
			this.is_order_pay_page = args.is_order_pay_page;
			this.create_billing_request_nonce =
				args.create_billing_request_nonce;
			this.complete_billing_request_nonce =
				args.complete_billing_request_nonce;

			if (!window.GoCardlessDropin) {
				// eslint-disable-next-line no-console
				console.error('GoCardless Dropin is not loaded.');
				return;
			}

			if ($('form.checkout').length) {
				this.form = $('form.checkout');
				this.handleCheckoutPage();
			} else if ($('form#order_review').length) {
				this.form = $('form#order_review');
				this.handlePayPage();
			}
		}

		/**
		 * Handle required actions on the checkout page.
		 */
		handleCheckoutPage() {
			// Display the drop-in on the checkout page after the order is placed.
			this.form.on(`checkout_place_order_success`, (e, data) =>
				this.handleOrderSuccess(data)
			);
		}

		/**
		 * Handle required actions on the Order > Pay page.
		 */
		handlePayPage() {
			this.form.on('submit', () => {
				if (
					$(
						'#order_review input[name=payment_method]:checked'
					).val() === this.id &&
					($('#order_review input[name=wc-gocardless-payment-token]')
						.length === 0 ||
						$(
							'#order_review input[name=wc-gocardless-payment-token]:checked'
						).val() === 'new')
				) {
					return this.validatePayment();
				}
			});

			// Auto-select the new payment method on order pay page in case we don't have any default saved payment methods.
			$(document).ready(function () {
				$(document.body).trigger('wc-credit-card-form-init');
			});
		}

		/**
		 * Validate that order pay page has billing request fulfilled.
		 */
		validatePayment() {
			if (this.form.is('.processing')) {
				// bail when already processing.
				return false;
			}

			// Check if billing request already fulfilled.
			if (this.hasBillingRequestId()) {
				return true;
			}

			// Block the form.
			this.blockUI();

			// Handle the submission.
			this.handleSubmission();

			// Prevent the form from submitting.
			return false;
		}

		handleSubmission() {
			this.createBillingRequestFlow()
				.then((data) => {
					const { billing_request_flow_id } = data;
					const handler = this.initDropIn({
						billingRequestFlowID: billing_request_flow_id,
						environment: this.is_test ? 'sandbox' : 'live',
						// eslint-disable-next-line no-unused-vars, @typescript-eslint/no-unused-vars
						onSuccess: (billingRequest, billingRequestFlow) => {
							if (
								billingRequest &&
								['fulfilling', 'fulfilled'].includes(
									billingRequest.status
								) &&
								billingRequest.id
							) {
								this.closeDropIn();
								$(
									'input[name="wc-gocardless-billing-request-id"]'
								).val(billingRequest.id);
								this.form.trigger('submit');
							} else {
								this.renderError(this.generic_error);
								this.unblockUI();
							}
						},
						// eslint-disable-next-line no-unused-vars, @typescript-eslint/no-unused-vars
						onExit: (error, metadata) => {
							if (error) {
								// eslint-disable-next-line no-console
								console.error(error);
							}
						},
					});
					handler.open();
					this.unblockUI();
				})
				.catch((error) => {
					// eslint-disable-next-line no-console
					console.error(error);
					this.renderError(this.generic_error);
					this.unblockUI();
				});
		}

		/**
		 * Create a billing request flow.
		 *
		 * @return {Promise<Object>} Response from the server.
		 */
		createBillingRequestFlow() {
			return new Promise((resolve, reject) => {
				const data = {
					security: this.create_billing_request_nonce,
					order_id: this.order_id,
				};

				// Check if save payment method is checked.
				if ($('#wc-gocardless-new-payment-method').is(':checked')) {
					data['wc-gocardless-new-payment-method'] = true;
				}

				let ajaxUrl = this.ajax_url.replace(
					'%%endpoint%%',
					'gocardless_create_billing_request_flow'
				);

				// Append the query string change_payment_method on the order pay page if needed.
				const urlParams = new URLSearchParams(window.location.search);
				if (
					this.is_order_pay_page &&
					urlParams.has('change_payment_method')
				) {
					ajaxUrl += `&change_payment_method=${urlParams.get(
						'change_payment_method'
					)}`;
				}

				$.ajax({
					url: ajaxUrl,
					type: 'POST',
					cache: false,
					data,
					complete: (response) => {
						const result = response.responseJSON;
						if (result && result.success) {
							return resolve(result.data);
						}

						return reject(result);
					},
				});
			});
		}

		/**
		 * Check if the form has billing request ID.
		 *
		 * @return {boolean} Return true if the form has billing request ID.
		 */
		hasBillingRequestId() {
			return $('input[name="wc-gocardless-billing-request-id"]').val();
		}

		/**
		 * Handle place order success event.
		 *
		 * This function is called when the order is placed successfully and it will open the GoCardless drop-in.
		 *
		 * @param {Object} data
		 * @return {boolean|void} return true if the order is not for this payment method, return void if the order is for this payment method.
		 */
		handleOrderSuccess(data) {
			const { billing_request_flow_id, result, order_id, redirect, billing_request_nonce } =
				data;
			if (
				result !== 'success' ||
				$('#payment input[name="payment_method"]:checked').val() !==
					this.id ||
				!billing_request_flow_id
			) {
				return true;
			}

			const handler = this.initDropIn({
				billingRequestFlowID: billing_request_flow_id,
				environment: this.is_test ? 'sandbox' : 'live',
				onSuccess: (billingRequest, billingRequestFlow) => {
					this.handleDropInSuccess(
						order_id,
						billingRequest,
						billingRequestFlow,
						billing_request_nonce
					);
				},
				// eslint-disable-next-line no-unused-vars, @typescript-eslint/no-unused-vars
				onExit: (error, metadata) => {
					if (error) {
						// eslint-disable-next-line no-console
						console.error(error);
					}
					window.location.href = redirect;
				},
			});
			handler.open();
			data.redirect = '#';
		}

		/**
		 * Handle the drop-in success.
		 *
		 * @param {string}      orderId             Order ID.
		 * @param {Object}      billingRequest      Billing Request object from GoCardless.
		 * @param {Object}      billingRequestFlow  Billing Request Flow object from GoCardless.
		 * @param {string|null} billingRequestNonce Billing Request Nonce.
		 */
		handleDropInSuccess(orderId, billingRequest, billingRequestFlow, billingRequestNonce = null) {
			this.blockUI();
			if (
				billingRequest &&
				['fulfilling', 'fulfilled'].includes(billingRequest.status)
			) {
				this.closeDropIn();
				$.ajax({
					type: 'POST',
					url: this.ajax_url.replace(
						'%%endpoint%%',
						'gocardless_complete_billing_request_flow'
					),
					data: {
						security: billingRequestNonce || this.complete_billing_request_nonce,
						order_id: orderId,
						billing_request_id: billingRequest.id,
						billing_request_flow_id: billingRequestFlow.id,
						save_customer_token: $(
							'#wc-gocardless-new-payment-method'
						).is(':checked')
							? 'yes'
							: 'no',
					},
					success: (response) => {
						if (response.success) {
							window.location.href = response.data.redirect_url;
						} else {
							this.unblockUI();
							if (response.data?.message) {
								this.renderError(response.data.message);
							} else {
								this.renderError(this.generic_error);
							}
						}
					},
					error: (response) => {
						this.unblockUI();
						// eslint-disable-next-line no-console
						console.error(response);
						this.renderError(this.generic_error);
					},
				});
			} else {
				this.renderError(this.generic_error);
			}
		}

		/**
		 * Render an error message on the checkout page.
		 *
		 * @param {string} error_message
		 */
		renderError(error_message) {
			$(
				'.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message, .is-error, .is-success'
			).remove();
			this.form.prepend(
				'<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><div class="woocommerce-error">' +
					error_message +
					'</div></div>'
			);
			this.form.removeClass('processing').unblock();
			this.form
				.find('.input-text, select, input:checkbox')
				.trigger('validate')
				.trigger('blur');

			// Scroll to notices.
			let scrollElement = $(
				'.woocommerce-NoticeGroup-updateOrderReview, .woocommerce-NoticeGroup-checkout'
			);
			if (!scrollElement.length) {
				scrollElement = this.form;
			}
			$('html, body').animate(
				{
					scrollTop: scrollElement.offset().top - 100,
				},
				1000
			);

			// Trigger the checkout_error event.
			$(document.body).trigger('checkout_error', [error_message]);
		}

		/**
		 * Block UI.
		 *
		 * @param {string} message Message to display.
		 */
		blockUI(message = null) {
			this.form.block({
				message,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6,
				},
			});
		}

		/**
		 * Unblock UI.
		 */
		unblockUI() {
			this.form.unblock();
		}

		/**
		 * Close the drop-in.
		 */
		closeDropIn() {
			if (this.handler) {
				this.handler.exit();
			}
		}

		/**
		 * Initialize the drop-in.
		 *
		 * @param {Object} options Options.
		 * @return {Object} GoCardless Dropin handler.
		 */
		initDropIn(options) {
			const { GoCardlessDropin } = window;
			this.handler = GoCardlessDropin.create(options);
			return this.handler;
		}
	}

	// Initialize the handler.
	window.wc_gocardless_checkout_handler = new WC_GoCardless_Checkout_Handler(
		window.wc_gocardless_checkout_params
	);
});
