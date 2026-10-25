const { defineConfig } = require( '@playwright/test' );
const baseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

module.exports = defineConfig( {
	...baseConfig,
	testDir: './tests/e2e',
	testMatch: '**/*.spec.js',
	// The screenshot catalogue has its own config: npm run screenshots.
	testIgnore: 'states.spec.js',
	// A test that passed only on a retry is named, not hidden.
	reporter: [ ...baseConfig.reporter, [ './tests/e2e/retry-reporter.js' ] ],
	webServer: {
		...baseConfig.webServer,
		command: 'npm run env:start',
	},
} );
