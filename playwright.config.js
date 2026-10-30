const { defineConfig } = require( '@playwright/test' );
const baseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

module.exports = defineConfig( {
	...baseConfig,
	testDir: './tests/e2e',
	testMatch: '**/*.spec.js',
	// The screenshot catalogue and the theme gallery have their own configs:
	// npm run screenshots and npm run gallery.
	testIgnore: [ 'states.spec.js', 'gallery.spec.js' ],
	// A test that passed only on a retry is named, not hidden.
	reporter: [ ...baseConfig.reporter, [ './tests/e2e/retry-reporter.js' ] ],
	webServer: {
		...baseConfig.webServer,
		command: 'npm run env:start',
	},
} );
