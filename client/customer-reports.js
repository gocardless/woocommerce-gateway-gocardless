/* eslint-disable react/react-in-jsx-scope */
/**
 * External dependencies
 */

import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';

addFilter(
	'woocommerce_admin_report_table',
	'woocommerce-gateway-gocardless',
	(reportTableData) => {
		if (reportTableData.endpoint !== 'customers') {
			return reportTableData;
		}

		const { mandate_url_format: mandateUrlFormat } = window.wc_gocardless_reports_params;

		reportTableData.headers = [
			...reportTableData.headers,
			{
				label: __(
					'Direct Debit Mandates',
					'woocommerce-gateway-gocardless'
				),
				key: 'direct_debit_mandates',
			},
		];

		if (
			!reportTableData.items ||
			!reportTableData.items.data ||
			!reportTableData.items.data.length
		) {
			return reportTableData;
		}

		const newRows = reportTableData.rows.map((row, index) => {
			const customer = reportTableData.items.data[index];
			const mandates = customer.direct_debit_mandates || [];
			const newRow = [
				...row,
				{
					display: (
						<span>
							{mandates.map((mandate, i) => (
								<span key={mandate}>
									{i > 0 && ', '}
									<a
										target="_blank"
										rel="noopener noreferrer"
										href={mandateUrlFormat?.replace(
											'%s',
											mandate
										)}
									>
										{mandate}
									</a>
								</span>
							))}
						</span>
					),
					value: mandates?.join(', '),
				},
			];
			return newRow;
		});

		reportTableData.rows = newRows;

		return reportTableData;
	}
);
