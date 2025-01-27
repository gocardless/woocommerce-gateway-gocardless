(function () {
	document.addEventListener('DOMContentLoaded', function () {
		const webhookSetup = document.querySelector(
			'.wcgc-webhook-secret-setup'
		);
		const openWebhookSettingsBtn = webhookSetup.querySelector('.open');
		const closeWebhookSettingsBtn = webhookSetup.querySelector('.close');

		if (openWebhookSettingsBtn) {
			openWebhookSettingsBtn.addEventListener('click', function () {
				webhookSetup.classList.remove('connected');
			});
		}

		if (closeWebhookSettingsBtn) {
			closeWebhookSettingsBtn.addEventListener('click', function () {
				webhookSetup.classList.add('connected');
			});
		}
	});

	jQuery(document).ready(function () {
		const params = window.wc_gocardless_admin_params || {};
		jQuery(document).on('click', '.copy-to-clipboard', function (e) {
			e.preventDefault();
			const btn = jQuery(this);
			btn.parent('.wcgc-webhook-secret-field')
				.find('input[type="text"]')
				.first()
				.trigger('select');
			try {
				document.execCommand('copy');
				const copyText = btn.html();
				btn.text(params.copied_text);
				setTimeout(function () {
					btn.html(copyText);
				}, 1500);
				btn.trigger('focus');
			} catch (err) {
				// eslint-disable-next-line no-alert
				window.alert(params.copy_error);
			}
		});

		jQuery(document).on('click', '.regenerate-secret', function () {
			const btn = jQuery(this);
			const originalText = btn.html();
			btn.text(params.loading_text);
			jQuery('.webhook-secret-error').remove();

			jQuery.ajax({
				type: 'POST',
				url: params.ajax_url.replace(
					'%%endpoint%%',
					'gocardless_regenerate_webhook_secret'
				),
				data: {
					security: params.regenerate_webhook_secret_nonce,
				},
				success: (response) => {
					if (response.success && response.data) {
						const webhookSecret = response.data.webhook_secret;
						const webhookSecretField = jQuery(
							'#woocommerce_gocardless_webhook_secret'
						);
						webhookSecretField.val(webhookSecret);
						webhookSecretField.trigger('change');
						btn.html(originalText);
					} else {
						if (response.data && response.data.message) {
							btn.after(
								`<span class="webhook-secret-error">${params.generic_error}: ${response.data.message}</span>`
							);
						} else {
							btn.after(
								`<span class="webhook-secret-error">${params.generic_error}</span>`
							);
						}
						btn.html(originalText);
					}
				},
				error: () => {
					btn.after(
						`<span class="webhook-secret-error">${params.generic_error}</span>`
					);
					btn.html(originalText);
				},
			});
		});
	});
})();
