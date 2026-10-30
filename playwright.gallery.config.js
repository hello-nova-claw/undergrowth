const path = require( 'path' );
const { defineConfig } = require( '@playwright/test' );
const { pluginMounts } = require( './bin/plugin-mounts.cjs' );

// Its own port, so the gallery can run beside the catalogue or `npm run
// playground`.
const port = process.env.UNDERGROWTH_PLAYGROUND_PORT || '9420';
// "debug" makes Playground print each blueprint step and what its PHP
// wrote, which is how a step that fails quietly is found.
const verbosity = process.env.UNDERGROWTH_PLAYGROUND_VERBOSITY || 'normal';

process.env.WP_BASE_URL ??= `http://127.0.0.1:${ port }`;

const baseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

const plugin = path.resolve( '.' );
const muPlugins = path.resolve( __dirname, 'tests/e2e/mu-plugins' );
const demo = path.resolve( __dirname, 'demo' );
const blueprint = path.resolve(
	__dirname,
	'tests/e2e/playground/gallery.json'
);

module.exports = defineConfig( {
	...baseConfig,
	testDir: './tests/e2e',
	testMatch: 'gallery.spec.js',
	// A gallery run never retries: a theme that fails should show as missing.
	retries: 0,
	reporter: process.env.CI ? [ [ 'github' ], [ 'list' ] ] : [ [ 'list' ] ],
	// Playground's own output stays in the log: a gallery run is for
	// looking at, and a blueprint step that fails is the first thing to see.
	webServer: {
		stdout: 'pipe',
		stderr: 'pipe',
		command: [
			`npx wp-playground-cli server --port=${ port } --verbosity=${ verbosity }`,
			...pluginMounts( plugin ).map(
				( { hostPath, vfsPath } ) =>
					`--mount="${ hostPath }:${ vfsPath }"`
			),
			`--mount="${ muPlugins }:/wordpress/wp-content/mu-plugins"`,
			`--mount="${ demo }:/wordpress/wp-content/undergrowth-demo"`,
			`--blueprint="${ blueprint }"`,
		].join( ' ' ),
		url: `${ process.env.WP_BASE_URL }/wp-login.php`,
		// Reading the demo blog and downloading nine themes take a while.
		timeout: 600_000,
		reuseExistingServer: ! process.env.CI,
	},
} );
