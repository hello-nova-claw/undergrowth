/**
 * Screenshot catalogue: every screen in every state, locale and width.
 *
 * Run with `npm run screenshots`. Not part of the normal e2e run: it asserts
 * nothing about the screens, it only records them for review.
 */
const path = require( 'path' );
const { test } = require( '@wordpress/e2e-test-utils-playwright' );

const OUTPUT = path.resolve(
	process.env.UNDERGROWTH_SCREENSHOTS_DIR || 'artifacts/screenshots'
);
const LOCALES = [ 'en_US', 'he_IL', 'en_XA' ];
const WIDTHS = [ 1280, 390 ];

const SETTINGS = [ 'options-general.php', 'page=undergrowth' ];
const SUGGESTIONS = [ 'edit.php', 'page=undergrowth-links' ];

// Notices are reached by the address the screen redirects to after each
// action, which shows exactly what the user sees without changing any data.
const NOTICES = [
	'vetoed',
	'saved',
	'unchanged',
	'gone',
	'accepted',
	'edited',
	'no_word',
	'not_proposed',
];

const WITHOUT_POSTS = [
	{ screen: 'settings', state: 'no-key', page: SETTINGS },
	{
		screen: 'settings',
		state: 'key-saved',
		page: SETTINGS,
		fixture: 'key-saved',
	},
	{
		screen: 'settings',
		state: 'last-error',
		page: SETTINGS,
		fixture: 'last-error',
	},
	{
		screen: 'suggestions',
		state: 'no-provider',
		page: SUGGESTIONS,
		fixture: 'no-provider',
	},
	{ screen: 'suggestions', state: 'empty', page: SUGGESTIONS },
];

const WITH_POSTS = [
	{ screen: 'suggestions', state: 'table', page: SUGGESTIONS },
	{
		screen: 'suggestions',
		state: 'off',
		page: SUGGESTIONS,
		fixture: 'suggestions-off',
	},
	...NOTICES.map( ( notice ) => ( {
		screen: 'suggestions',
		state: `notice-${ notice.replace( '_', '-' ) }`,
		page: [
			SUGGESTIONS[ 0 ],
			`${ SUGGESTIONS[ 1 ] }&undergrowth_notice=${ notice }${
				'accepted' === notice ? '&revision=1' : ''
			}`,
		],
		notice: true,
	} ) ),
];

// Posts share fixture words, so the e2e provider suggests links between them.
const POSTS = [
	{ title: 'Oca', content: '<p>Oca grows here. I keep yacon nearby.</p>' },
	{ title: 'Yacon', content: '<p>Yacon syrup is sweet.</p>' },
	{
		title: 'Seed ledger',
		content: '<p>The ledger lists every oca and yacon I planted.</p>',
	},
];

/**
 * Masks what changes between runs of the same build.
 *
 * Nonces sit in hidden fields and never show. The one date on our screens,
 * in the last-error notice, comes from a fixed fixture time, so it stays.
 *
 * @param {import('@playwright/test').Page} page Page to capture.
 * @return {import('@playwright/test').Locator[]} Regions to mask.
 */
function volatile( page ) {
	return [
		// WordPress version, which moves with "latest".
		page.locator( '#footer-upgrade' ),
		// Gravatar loads over the network, sometimes after the shot.
		page.locator( '#wpadminbar .avatar' ),
	];
}

// Update checks run in the background and can find an update between two
// runs, which adds an admin bar item and shifts everything after it.
const HIDE_UPDATES = `
	#wp-admin-bar-updates,
	.update-nag,
	#adminmenu .update-plugins {
		display: none !important;
	}
`;

/**
 * Defines one test per state and locale, capturing every width.
 *
 * @param {Object[]} states States to capture.
 */
function capture( states ) {
	for ( const locale of LOCALES ) {
		for ( const entry of states ) {
			test( `${ entry.screen } ${ entry.state } ${ locale }`, async ( {
				admin,
				page,
				context,
				baseURL,
			} ) => {
				const cookies = [ [ 'undergrowth_e2e_locale', locale ] ];

				if ( entry.fixture ) {
					cookies.push( [
						'undergrowth_e2e_fixture',
						entry.fixture,
					] );
				}

				await context.addCookies(
					cookies.map( ( [ name, value ] ) => ( {
						name,
						value,
						url: baseURL,
					} ) )
				);

				await admin.visitAdminPage( ...entry.page );

				// A build without this notice would only show the plain table.
				if (
					entry.notice &&
					( await page.locator( '#undergrowth-notice' ).count() ) ===
						0
				) {
					test.skip( true, `This build has no ${ entry.state }.` );
				}

				for ( const width of WIDTHS ) {
					await page.setViewportSize( { width, height: 800 } );
					await page.screenshot( {
						path: path.join(
							OUTPUT,
							`${ entry.screen }--${ entry.state }--${ locale }--${ width }.png`
						),
						fullPage: true,
						animations: 'disabled',
						caret: 'hide',
						mask: volatile( page ),
						maskColor: '#c3c4c7',
						style: HIDE_UPDATES,
					} );
				}
			} );
		}
	}
}

test.describe( 'Screenshot catalogue', () => {
	test.describe( 'without posts', () => {
		test.beforeAll( async ( { requestUtils } ) => {
			await requestUtils.deleteAllPosts();
		} );

		capture( WITHOUT_POSTS );
	} );

	test.describe( 'with posts', () => {
		test.beforeAll( async ( { requestUtils } ) => {
			await requestUtils.deleteAllPosts();

			for ( const post of POSTS ) {
				await requestUtils.createPost( { ...post, status: 'publish' } );
			}
		} );

		capture( WITH_POSTS );
	} );
} );
