const { defineConfig } = require( '@playwright/test' );
const baseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

module.exports = defineConfig( {
	...baseConfig,
	testDir: './tests/e2e',
	testMatch: '**/*.spec.js',
	webServer: {
		...baseConfig.webServer,
		command: 'npm run env:start',
	},
} );
