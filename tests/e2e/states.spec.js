/**
 * Screenshot catalogue: every screen in every state, locale and width.
 *
 * Run with `npm run screenshots`. Not part of the normal e2e run: it asserts
 * nothing about the screens, it only records them for review.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { test } = require( '@wordpress/e2e-test-utils-playwright' );

const OUTPUT = path.resolve(
	process.env.UNDERGROWTH_SCREENSHOTS_DIR || 'artifacts/screenshots'
);
const LOCALES = [ 'en_US', 'he_IL', 'en_XA' ];
const WIDTHS = [ 1280, 390 ];

const SETTINGS = [ 'options-general.php', 'page=undergrowth' ];
const SUGGESTIONS = [ 'edit.php', 'page=undergrowth-links' ];
const STAGES = [ 'edit.php', 'page=undergrowth-links&tab=stages' ];
const MAP = [ 'edit.php', 'page=undergrowth-links&tab=map' ];
const DASHBOARD = [ 'index.php' ];
const POSTS_LIST = [ 'edit.php', '' ];
const PROFILE = [ 'profile.php', '' ];

// Notices are reached by the address the screen redirects to after each
// action, which shows exactly what the user sees without changing any data.
const NOTICES = [
	'vetoed',
	'unchanged',
	'gone',
	'accepted',
	'edited',
	'no_word',
	'not_proposed',
	'needs_admin',
];

// Notices the growth stages tab returns with after a pin or unpin.
const STAGE_NOTICES = [
	'pinned',
	'unpinned',
	'stage_refused',
	'stage_composting',
	'not_pinned',
	'stage_not_saved',
];

// Notices the Dashboard returns with after a prompt is dismissed or snoozed.
const PROMPT_NOTICES = [ 'prompt_dismissed', 'prompt_snoozed', 'prompt_gone' ];

const WITHOUT_POSTS = [
	// The test site's own provider is code, which the OpenAI states stand aside.
	{
		screen: 'settings',
		state: 'no-key',
		page: SETTINGS,
		fixture: 'no-provider',
	},
	{ screen: 'settings', state: 'code-provider', page: SETTINGS },
	{
		screen: 'settings',
		state: 'code-provider-key-saved',
		page: SETTINGS,
		fixture: 'code-provider-key-saved',
	},
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
		screen: 'settings',
		state: 'file-last-error',
		page: SETTINGS,
		fixture: 'file-last-error',
	},
	{
		screen: 'settings',
		state: 'prompt-key-saved',
		page: SETTINGS,
		fixture: 'prompt-key-saved',
	},
	{
		screen: 'settings',
		state: 'prompt-last-error',
		page: SETTINGS,
		fixture: 'prompt-last-error',
	},
	{
		screen: 'settings',
		state: 'prompt-refused',
		page: SETTINGS,
		fixture: 'prompt-refused',
	},
	{
		screen: 'settings',
		state: 'discord-saved',
		page: SETTINGS,
		fixture: 'discord-saved',
	},
	{
		screen: 'settings',
		state: 'discord-last-error',
		page: SETTINGS,
		fixture: 'discord-last-error',
	},
	{
		screen: 'settings',
		state: 'discord-invalid',
		page: SETTINGS,
		fixture: 'discord-invalid',
	},
	{
		screen: 'suggestions',
		state: 'no-provider',
		page: SUGGESTIONS,
		fixture: 'no-provider',
	},
	{ screen: 'profile', state: 'discord-user', page: PROFILE },
	// Where a new owner lands after activating, with the Settings link.
	{ screen: 'plugins', state: 'row', page: [ 'plugins.php', '' ] },
	// The first-run notice, right after activating on a site with no key.
	{
		screen: 'plugins',
		state: 'first-run',
		page: [ 'plugins.php', '' ],
		fixture: 'first-run',
	},
	// Settings right after the first key is saved, linking on to suggestions.
	{
		screen: 'settings',
		state: 'first-key-saved',
		page: SETTINGS,
		fixture: 'first-key-saved',
	},
	{ screen: 'suggestions', state: 'empty', page: SUGGESTIONS },
	{ screen: 'stages', state: 'empty', page: STAGES },
	// The threshold meter. Without a fixture the site has no Jetpack.
	{ screen: 'dashboard', state: 'meter-no-stats', page: DASHBOARD },
	...[ 'meter-below', 'meter-crossed', 'meter-no-answer' ].map(
		( state ) => ( {
			screen: 'dashboard',
			state,
			page: DASHBOARD,
			fixture: state,
		} )
	),
	{
		screen: 'dashboard',
		state: 'help-meter',
		page: DASHBOARD,
		fixture: 'meter-below',
		help: 'undergrowth-meter',
	},
	{
		screen: 'settings',
		state: 'help-meter',
		page: SETTINGS,
		help: 'undergrowth-meter',
	},
	// Each tab of Posts > Undergrowth, and Compost on the Posts list.
	...[ 'overview', 'suggestions', 'stages', 'map', 'compost' ].map(
		( part ) => ( {
			screen: 'suggestions',
			state: `help-${ part }`,
			page: SUGGESTIONS,
			help: `undergrowth-${ part }`,
		} )
	),
	{
		screen: 'posts',
		state: 'help-compost',
		page: POSTS_LIST,
		help: 'undergrowth-compost',
	},
	// The full detail of what each part sends, kept out of the fields.
	...[ 'links', 'prompts', 'discord' ].map( ( part ) => ( {
		screen: 'settings',
		state: `help-${ part }`,
		page: SETTINGS,
		help: `undergrowth-${ part }`,
		fixture: 'links' === part ? 'key-saved' : undefined,
	} ) ),
	{
		screen: 'settings',
		state: 'help-links-code-provider',
		page: SETTINGS,
		help: 'undergrowth-links',
	},
];

const WITH_POSTS = [
	{ screen: 'settings', state: 'index-complete', page: SETTINGS },
	{
		screen: 'settings',
		state: 'index-partial',
		page: SETTINGS,
		fixture: 'index-partial',
	},
	{ screen: 'suggestions', state: 'table', page: SUGGESTIONS },
	{
		screen: 'suggestions',
		state: 'off',
		page: SUGGESTIONS,
		fixture: 'suggestions-off',
	},
	...NOTICES.map( ( notice ) => ( {
		screen: 'suggestions',
		state: `notice-${ notice.replaceAll( '_', '-' ) }`,
		page: [
			SUGGESTIONS[ 0 ],
			`${ SUGGESTIONS[ 1 ] }&undergrowth_notice=${ notice }${
				'accepted' === notice ? '&revision=1' : ''
			}`,
		],
		notice: true,
	} ) ),
	{ screen: 'stages', state: 'table', page: STAGES },
	...[
		[ 'view-seedling', 'undergrowth_stage=seedling' ],
		[ 'view-empty', 'undergrowth_stage=evergreen' ],
		[ 'sorted-by-stage', 'orderby=stage&order=desc' ],
		[ 'sorted-by-pin', 'orderby=pinned&order=desc' ],
	].map( ( [ state, query ] ) => ( {
		screen: 'stages',
		state,
		page: [ STAGES[ 0 ], `${ STAGES[ 1 ] }&${ query }` ],
	} ) ),
	{
		screen: 'stages',
		state: 'notice-bulk-pinned',
		page: [
			STAGES[ 0 ],
			`${ STAGES[ 1 ] }&undergrowth_pinned=2&undergrowth_skipped=1`,
		],
		notice: true,
	},
	{
		screen: 'dashboard',
		state: 'prompt',
		page: DASHBOARD,
		fixture: 'prompt',
	},
	...PROMPT_NOTICES.map( ( notice ) => ( {
		screen: 'dashboard',
		state: `notice-${ notice.replaceAll( '_', '-' ) }`,
		page: [ DASHBOARD[ 0 ], `undergrowth_notice=${ notice }` ],
		notice: true,
	} ) ),
	...STAGE_NOTICES.map( ( notice ) => ( {
		screen: 'stages',
		state: `notice-${ notice.replaceAll( '_', '-' ) }`,
		page: [
			STAGES[ 0 ],
			`${ STAGES[ 1 ] }&undergrowth_notice=${ notice }`,
		],
		notice: true,
	} ) ),
];

// Shown after one post is composted, so every list it would be in is covered.
const WITH_COMPOST = [
	{ screen: 'posts', state: 'composting', page: POSTS_LIST },
	{
		screen: 'posts',
		state: 'composting-view',
		page: [ 'edit.php', 'undergrowth_stage=composting' ],
	},
	{
		screen: 'posts',
		state: 'filtered-by-stage',
		page: [ 'edit.php', 'undergrowth_stage=seedling' ],
	},
	{
		screen: 'posts',
		state: 'sorted-by-stage',
		page: [ 'edit.php', 'orderby=undergrowth_stage&order=desc' ],
	},
	// The notices the Posts screen returns with after a row or bulk action.
	...[
		[ 'pinned', 'undergrowth_pinned=2' ],
		[ 'unpinned', 'undergrowth_unpinned=1' ],
		[ 'composted', 'undergrowth_composted=1' ],
		[ 'returned', 'undergrowth_returned=2' ],
		[ 'skipped', 'undergrowth_composted=1&undergrowth_skipped=1' ],
	].map( ( [ state, query ] ) => ( {
		screen: 'posts',
		state: `notice-${ state }`,
		page: [ 'edit.php', query ],
		notice: true,
	} ) ),
	{ screen: 'suggestions', state: 'with-composting', page: SUGGESTIONS },
	{ screen: 'stages', state: 'with-composting', page: STAGES },
];

// Shown after a composting post was found near a new cluster. The signal
// and the notices after dismissing it are on the Map tab.
const WITH_RESURFACING = [
	{ screen: 'map', state: 'resurfacing', page: MAP },
	...[ 'resurfacing_dismissed', 'resurfacing_gone' ].map( ( notice ) => ( {
		screen: 'map',
		state: `notice-${ notice.replaceAll( '_', '-' ) }`,
		page: [ MAP[ 0 ], `${ MAP[ 1 ] }&undergrowth_notice=${ notice }` ],
		notice: true,
	} ) ),
];

// Shown after a clustering pass made two clusters, one of them a gap.
const WITH_CLUSTERS = [
	{ screen: 'map', state: 'clusters', page: MAP, fixture: 'tight-gaps' },
];

// Filled in once the badge's posts exist, since the shots need their IDs.
const badgePosts = {};

// The growth-stage block, on the front end where readers see it and in the
// editor where the author places it. A composted post shows no badge.
const WITH_BADGE = [
	{
		screen: 'block-growth-stage',
		state: 'front-seedling',
		front: () => `/?p=${ badgePosts.seedling }`,
	},
	{
		screen: 'block-growth-stage',
		state: 'front-composting',
		front: () => `/?p=${ badgePosts.composting }`,
	},
	{
		screen: 'block-growth-stage',
		state: 'editor-seedling',
		edit: () => badgePosts.seedling,
	},
	{
		screen: 'block-growth-stage',
		state: 'editor-composting',
		edit: () => badgePosts.composting,
	},
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
		// Core Dashboard widgets show news, dates and checks that change.
		page.locator(
			'#dashboard_primary, #dashboard_activity, #dashboard_site_health'
		),
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
 * Names a shot `<screen>--<state>--<locale>--<width>.png`.
 *
 * The comparison pairs before and after shots by file name alone, so the name
 * must not change unless the state itself does.
 *
 * @param {Object} entry  State being captured.
 * @param {string} locale Locale code.
 * @param {number} width  Viewport width in pixels.
 * @return {string} Path of the PNG file.
 */
function shotPath( entry, locale, width ) {
	return path.join(
		OUTPUT,
		`${ entry.screen }--${ entry.state }--${ locale }--${ width }.png`
	);
}

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
				editor,
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

				if ( entry.front ) {
					await page.goto( entry.front() );
				} else if ( entry.edit ) {
					await admin.editPost( entry.edit() );
					// The badge preview is rendered by the server after load.
					await editor.canvas
						.locator(
							'.wp-block-undergrowth-growth-stage p, .wp-block-undergrowth-growth-stage .components-placeholder'
						)
						.first()
						.waitFor();
				} else {
					await admin.visitAdminPage( ...entry.page );
				}

				// Opens core's Help panel on the tab this state is about.
				const helpTab = entry.help
					? page.locator( `#tab-link-${ entry.help } a` )
					: null;

				if ( helpTab && ( await helpTab.count() ) > 0 ) {
					await page.locator( '#contextual-help-link' ).click();
					await helpTab.click();
					await page
						.locator( `#tab-panel-${ entry.help }` )
						.waitFor();
					// Core slides the panel open; once it stops, scroll back to
					// the top, since opening the tab scrolls under the admin bar.
					await page.waitForFunction(
						() => ! window.jQuery( ':animated' ).length
					);
					await page.evaluate( () => window.scrollTo( 0, 0 ) );
				}

				// A build without this notice or Help tab would only show the
				// plain screen. The marker lets the comment list it, rather
				// than drop it.
				if (
					( entry.notice &&
						( await page
							.locator( '#undergrowth-notice' )
							.count() ) === 0 ) ||
					( helpTab && ( await helpTab.count() ) === 0 )
				) {
					fs.mkdirSync( OUTPUT, { recursive: true } );

					for ( const width of WIDTHS ) {
						const shot = shotPath( entry, locale, width );

						fs.rmSync( shot, { force: true } );
						fs.writeFileSync(
							`${ shot }.skipped`,
							`This build has no ${ entry.state }.\n`
						);
					}

					test.skip( true, `This build has no ${ entry.state }.` );
				}

				for ( const width of WIDTHS ) {
					const shot = shotPath( entry, locale, width );

					// A marker left by an earlier run would list this state
					// as skipped as well as captured.
					fs.rmSync( `${ shot }.skipped`, { force: true } );

					await page.setViewportSize( { width, height: 800 } );
					await page.screenshot( {
						path: shot,
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

			const created = [];

			for ( const post of POSTS ) {
				created.push(
					await requestUtils.createPost( {
						...post,
						status: 'publish',
					} )
				);
			}

			// A reader's question, for the prompt on the Dashboard.
			await requestUtils.rest( {
				method: 'POST',
				path: '/wp/v2/comments',
				data: {
					post: created[ 0 ].id,
					content: 'How do you keep oca through the winter?',
					author_name: 'A reader',
					author_email: 'reader@example.com',
					status: 'approved',
				},
			} );
		} );

		capture( WITH_POSTS );
	} );

	test.describe( 'with a composting post', () => {
		test.beforeAll( async ( { requestUtils } ) => {
			await requestUtils.deleteAllPosts();

			const created = [];

			for ( const post of POSTS ) {
				created.push(
					await requestUtils.createPost( {
						...post,
						status: 'publish',
					} )
				);
			}

			// Composted through its own row action, as the author would.
			// esc_url() writes each & in the link as &#038;.
			const list = await requestUtils.request.get( 'wp-admin/edit.php' );
			const link = ( await list.text() ).match(
				new RegExp(
					`href="([^"]*action=undergrowth_compost&#038;post=${ created[ 0 ].id }&#038;[^"]*)"`
				)
			);

			// Without a composting post every shot below would show the
			// wrong state, so a missing link or a refusal stops the run.
			if ( ! link ) {
				throw new Error( 'No Compost row action for the first post.' );
			}

			const composted = await requestUtils.request.get(
				link[ 1 ].replaceAll( '&#038;', '&' )
			);

			if ( ! composted.ok() ) {
				throw new Error(
					`Composting failed with HTTP ${ composted.status() }.`
				);
			}
		} );

		capture( WITH_COMPOST );
	} );

	test.describe( 'with a resurfacing signal', () => {
		test.beforeAll( async ( { requestUtils } ) => {
			await requestUtils.deleteAllPosts();

			// Repeating one fixture word gives posts almost the same vector.
			const publish = async ( word, titles ) => {
				const created = [];

				for ( const title of titles ) {
					created.push(
						await requestUtils.createPost( {
							title,
							content: `<p>${ Array( 6 )
								.fill( word )
								.join( ' ' ) }</p>`,
							status: 'publish',
						} )
					);
				}

				return created;
			};
			const pass = async () => {
				const response = await requestUtils.request.get(
					'wp-admin/index.php?undergrowth_e2e_cluster=1'
				);

				if ( ! response.ok() ) {
					throw new Error(
						`Clustering failed with HTTP ${ response.status() }.`
					);
				}
			};

			const [ old ] = await publish( 'oca', [ 'Oca in winter' ] );
			await publish( 'yacon', [
				'Yacon',
				'Yacon syrup',
				'Yacon leaves',
			] );

			const list = await requestUtils.request.get( 'wp-admin/edit.php' );
			const link = ( await list.text() ).match(
				new RegExp(
					`href="([^"]*action=undergrowth_compost&#038;post=${ old.id }&#038;[^"]*)"`
				)
			);

			if ( ! link ) {
				throw new Error( 'No Compost row action for the old post.' );
			}

			const composted = await requestUtils.request.get(
				link[ 1 ].replaceAll( '&#038;', '&' )
			);

			if ( ! composted.ok() ) {
				throw new Error(
					`Composting failed with HTTP ${ composted.status() }.`
				);
			}

			// The first pass is the previous cycle; the second finds the new cluster.
			await pass();
			await publish( 'oca', [
				'Oca planting',
				'Oca harvest',
				'Oca seed',
			] );
			await pass();

			// Without a signal the shot would show the plain map instead.
			const map = await requestUtils.request.get(
				'wp-admin/edit.php?page=undergrowth-links&tab=map'
			);

			if (
				! ( await map.text() ).includes(
					'value="undergrowth_dismiss_resurfacing"'
				)
			) {
				throw new Error(
					'No resurfacing signal after the second pass.'
				);
			}
		} );

		capture( WITH_RESURFACING );
	} );

	test.describe( 'with clusters', () => {
		test.beforeAll( async ( { requestUtils } ) => {
			await requestUtils.deleteAllPosts();

			// Repeated fixture words give each post a known vector. Three
			// identical posts make a cluster with a post at its middle; three
			// pulling apart make a gap. Titles hold no fixture word.
			for ( const [ title, content ] of [
				[ 'First', 'yacon '.repeat( 6 ) ],
				[ 'Second', 'yacon '.repeat( 6 ) ],
				[ 'Third', 'yacon '.repeat( 6 ) ],
				[ 'Roots', 'oca '.repeat( 6 ) ],
				[ 'Sweet roots', 'oca '.repeat( 6 ) + 'syrup '.repeat( 3 ) ],
				[
					'Roots on paper',
					'oca '.repeat( 6 ) + 'ledger '.repeat( 3 ),
				],
			] ) {
				await requestUtils.createPost( {
					title,
					content: `<p>${ content.trim() }</p>`,
					status: 'publish',
				} );
			}

			const response = await requestUtils.request.get(
				'wp-admin/index.php?undergrowth_e2e_cluster=1'
			);

			if ( ! response.ok() ) {
				throw new Error(
					`Clustering failed with HTTP ${ response.status() }.`
				);
			}
		} );

		capture( WITH_CLUSTERS );
	} );

	test.describe( 'with a growth-stage badge', () => {
		test.beforeAll( async ( { requestUtils } ) => {
			await requestUtils.deleteAllPosts();

			for ( const [ stage, title ] of [
				[ 'seedling', 'Oca' ],
				[ 'composting', 'Yacon' ],
			] ) {
				badgePosts[ stage ] = (
					await requestUtils.createPost( {
						title,
						content:
							'<!-- wp:paragraph --><p>Oca grows here.</p><!-- /wp:paragraph --><!-- wp:undergrowth/growth-stage /-->',
						status: 'publish',
					} )
				).id;
			}

			const list = await requestUtils.request.get( 'wp-admin/edit.php' );
			const link = ( await list.text() ).match(
				new RegExp(
					`href="([^"]*action=undergrowth_compost&#038;post=${ badgePosts.composting }&#038;[^"]*)"`
				)
			);

			if ( ! link ) {
				throw new Error( 'No Compost row action for the badge post.' );
			}

			await requestUtils.request.get(
				link[ 1 ].replaceAll( '&#038;', '&' )
			);
		} );

		capture( WITH_BADGE );
	} );
} );
