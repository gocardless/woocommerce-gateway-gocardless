const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const DependencyExtractionWebpackPlugin = require('@woocommerce/dependency-extraction-webpack-plugin');
const path = require('path');

module.exports = {
	...defaultConfig,
	plugins: [
		...defaultConfig.plugins.filter(
			(plugin) =>
				plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
		new DependencyExtractionWebpackPlugin(),
	],
	entry: {
		index: path.resolve(process.cwd(), 'client/blocks', 'index.js'),
		'wc-gocardless-checkout': path.resolve(
			process.cwd(),
			'client',
			'wc-gocardless-checkout.js'
		),
		'customer-reports': path.resolve(
			process.cwd(),
			'client',
			'customer-reports.js'
		),
	},
};
