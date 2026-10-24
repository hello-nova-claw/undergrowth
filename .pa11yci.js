/**
 * pa11y-ci's pages and rules. bin/pa11y.mjs starts the site and sets
 * WP_BASE_URL; run it with `npm run test:a11y`.
 *
 * Each page is tested inside the plugin's own markup only, as the axe checks
 * in tests/e2e/a11y.js are, because violations in WordPress core screens are
 * not ours to fix.
 */

const base = ( process.env.WP_BASE_URL || 'http://127.0.0.1:9410' ).replace(
	/\/$/,
	''
);

/**
 * Logs in as Playground's admin user, then opens an admin screen.
 *
 * @param {string}   path   Address under wp-admin/.
 * @param {string[]} ignore Rules this screen is known to break, and why.
 * @return {Object} A pa11y-ci URL entry.
 */
function adminScreen( path, ignore = [] ) {
	return {
		url: `${ base }/wp-login.php`,
		rootElement: '.wrap',
		ignore,
		actions: [
			'set field #user_login to admin',
			'set field #user_pass to password',
			'click element #wp-submit',
			'wait for element #wpadminbar to be added',
			`navigate to ${ base }/wp-admin/${ path }`,
			'wait for element .wrap to be added',
		],
	};
}

// HTML_CodeSniffer reads WordPress's own list tables as tables with several
// levels of headers, which they are not, and flags them on core's Posts
// screen too.
const LIST_TABLE = 'WCAG2AA.Principle1.Guideline1_3.1_3_1.H43.HeadersRequired';

// Each row action is its own small form with its own nonce field, and
// wp_nonce_field() always gives the field the id _wpnonce. Hidden inputs are
// never read out, and WCAG 2.2 removed 4.1.1 Parsing, the rule this is.
const DUPLICATE_NONCE_ID = 'WCAG2AA.Principle4.Guideline4_1.4_1_1.F77';

// The Settings API draws every section as <table class="form-table"
// role="presentation">, which HTML_CodeSniffer rejects for holding labels.
const SETTINGS_API_TABLE = 'WCAG2AA.Principle1.Guideline1_3.1_3_1.F92,ARIA4';

module.exports = {
	defaults: {
		standard: 'WCAG2AA',
		runners: [ 'axe', 'htmlcs' ],
		// Fails on violations only, as tests/e2e/a11y.js does. What axe
		// cannot decide, such as the contrast of a select drawn with a
		// background image, is reported as a warning.
		levelCapWhenNeedsReview: 'warning',
		timeout: 60000,
		chromeLaunchConfig: {
			args: [ '--no-sandbox' ],
		},
	},
	urls: [
		{
			// The blueprint publishes this post with the Growth stage block.
			url: `${ base }/?name=undergrowth-pa11y`,
			rootElement: '.wp-block-undergrowth-growth-stage',
		},
		adminScreen( 'options-general.php?page=undergrowth', [
			SETTINGS_API_TABLE,
		] ),
		adminScreen( 'edit.php?page=undergrowth-links', [
			LIST_TABLE,
			DUPLICATE_NONCE_ID,
		] ),
		adminScreen( 'edit.php?page=undergrowth-links&tab=stages', [
			LIST_TABLE,
			DUPLICATE_NONCE_ID,
		] ),
		adminScreen( 'edit.php?page=undergrowth-links&tab=map' ),
	],
};
