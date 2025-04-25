#!/usr/bin/env node

const fs = require('fs');

const path = `${process.cwd()}/.wp-env.json`;

let config = fs.existsSync(path)
	? require(path)
	: {
			plugins: [
				'https://downloads.wordpress.org/plugin/woocommerce.zip',
				'https://downloads.wordpress.org/plugin/email-log.zip',
				'.',
			],
		};

const args = {};
process.argv.slice(2, process.argv.length).forEach((arg) => {
	if (arg.slice(0, 2) === '--') {
		const param = arg.split('=');
		const paramName = param[0].slice(2, param[0].length);
		const paramValue = param.length > 1 ? param[1] : true;
		if (paramName === 'plugin') {
			// Replace "." with paramValue in the plugins array
            config.plugins = config.plugins.map((plugin) =>
                plugin === '.' ? paramValue : plugin
            );
		} else {
			args[paramName] = paramValue;
		}
	}
});

if ('latest' === args.core) {
	delete args.core;
	delete config.core;
}

config = {
	...config,
	...args,
};

try {
	fs.writeFileSync(path, JSON.stringify(config));
} catch (err) {
	console.error(err);
}
