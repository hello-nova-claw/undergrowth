const path = require( 'path' );
const { defineConfig } = require( '@playwright/test' );
const { pluginMounts } = require( './bin/plugin-mounts.cjs' );

const port = process.env.UNDERGROWTH_PLAYGROUND_PORT || '9400';

process.env.WP_BASE_URL ??= `http://127.0.0.1:${ port }`;

const baseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

// The plugin under test. The workflow points this at a checkout of the base
// branch to take the "before" shots with this branch's spec.
const plugin = path.resolve( process.env.UNDERGROWTH_PLUGIN_DIR || '.' );
const muPlugins = path.resolve( __dirname, 'tests/e2e/mu-plugins' );
const blueprint = path.resolve(
	__dirname,
	'tests/e2e/playground/screenshots.json'
);
const pseudo = path.resolve( __dirname, 'bin/pseudo-locale.mjs' );

module.exports = defineConfig( {
	...baseConfig,
	testDir: './tests/e2e',
	testMatch: 'states.spec.js',
	// A screenshot run never retries: a flaky state should show as missing.
	retries: 0,
	reporter: process.env.CI ? [ [ 'github' ], [ 'list' ] ] : [ [ 'list' ] ],
	webServer: {
		command: [
			`node "${ pseudo }" "${ plugin }/languages/undergrowth.pot" &&`,
			`npx wp-playground-cli server --port=${ port } --verbosity=quiet`,
			...pluginMounts( plugin ).map(
				( { hostPath, vfsPath } ) =>
					`--mount="${ hostPath }:${ vfsPath }"`
			),
			`--mount="${ muPlugins }:/wordpress/wp-content/mu-plugins"`,
			`--blueprint="${ blueprint }"`,
		].join( ' ' ),
		url: `${ process.env.WP_BASE_URL }/wp-login.php`,
		timeout: 300_000,
		reuseExistingServer: ! process.env.CI,
	},
} );
