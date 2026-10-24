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
 * @param {string}   path           Address under wp-admin/.
 * @param {Object}   options
 * @param {string[]} options.ignore Rules this screen is known to break.
 * @param {string}   options.hide   Core's elements to leave out.
 * @param {string}   options.root   The plugin's markup on the screen.
 * @param {string}   options.ready  What shows the screen is the one asked
 *                                  for. The run fails if it never appears.
 * @return {Object} A pa11y-ci URL entry.
 */
function adminScreen(
	path,
	{ ignore = [], hide = '', root = '.wrap', ready = root } = {}
) {
	return {
		url: `${ base }/wp-login.php`,
		rootElement: root,
		ignore,
		hideElements: hide,
		actions: [
			'set field #user_login to admin',
			'set field #user_pass to password',
			'click element #wp-submit',
			'wait for element #wpadminbar to be added',
			`navigate to ${ base }/wp-admin/${ path }`,
			`wait for element ${ ready } to be added`,
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

// A list table's paging arrows that lead nowhere are greyed out and hidden
// from screen readers. WCAG 1.4.3 exempts inactive controls from contrast.
const DISABLED_PAGING = '.tablenav-pages-navspan.disabled';

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
		adminScreen( 'options-general.php?page=undergrowth', {
			ignore: [ SETTINGS_API_TABLE ],
		} ),
		adminScreen( 'edit.php?page=undergrowth-links', {
			ignore: [ LIST_TABLE, DUPLICATE_NONCE_ID ],
			hide: DISABLED_PAGING,
			ready: '.undergrowth-links',
		} ),
		adminScreen( 'edit.php?page=undergrowth-links&tab=stages', {
			ignore: [ LIST_TABLE, DUPLICATE_NONCE_ID ],
			hide: DISABLED_PAGING,
			ready: '.undergrowth-stages',
		} ),
		// Without a cluster the Map tab falls back to Suggestions.
		adminScreen( 'edit.php?page=undergrowth-links&tab=map', {
			ready: '.undergrowth-map-counts',
		} ),
		// The widget shows only with a question or a meter line.
		adminScreen( 'index.php', { root: '#undergrowth' } ),
	],
};
