/**
 * Screenshot catalogue: every screen in every state, locale and width.
 *
 * Run with `npm run screenshots`. Not part of the normal e2e run: it asserts
 * nothing about the screens, it only records them for review.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { test } = require( '@wordpress/e2e-test-utils-playwright' );
const { skipWelcomeGuide } = require( './preferences' );
const { STYLED } = require( './supports' );

const OUTPUT = path.resolve(
	process.env.UNDERGROWTH_SCREENSHOTS_DIR || 'artifacts/screenshots'
);
const LOCALES = [ 'en_US', 'he_IL', 'en_XA' ];
const WIDTHS = [ 1280, 390 ];

const SETTINGS = [ 'options-general.php', 'page=undergrowth' ];
const SUGGESTIONS = [ 'edit.php', 'page=undergrowth-links' ];
const ORPHANS = [ 'edit.php', 'page=undergrowth-links&tab=orphans' ];
const STAGES = [ 'edit.php', 'page=undergrowth-links&tab=stages' ];
const MAP = [ 'edit.php', 'page=undergrowth-links&tab=map' ];
const DEV = [ 'edit.php', 'page=undergrowth-links&tab=dev' ];
const DASHBOARD = [ 'index.php' ];
const STUDIO = [ 'edit.php', 'page=undergrowth-studio' ];
const POSTS_LIST = [ 'edit.php', '' ];
const PROFILE = [ 'profile.php', '' ];

// Notices are reached by the address the screen redirects to after each
// action, which shows exactly what the user sees without changing any data.
const NOTICES = [
	'vetoed',
	'unvetoed',
	'unveto_gone',
	'nothing_selected',
	'unchanged',
	'gone',
	'accepted',
	'edited',
	'no_word',
	'not_proposed',
	'needs_admin',
	'words_saved',
	'words_invalid',
	'words_unsafe',
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

const DEV_NOTICES = [
	'dev_accept_started',
	'dev_reset_started',
	'dev_cycle_queued',
	'dev_busy',
];
// Notices the Prompt studio returns with after a choice on a question.
const PROMPT_NOTICES = [
	'prompt_dismissed',
	'prompt_saved',
	'prompt_replaced',
	'prompt_not_replaced',
	'prompt_removed',
	'prompt_restored',
	'prompt_gone',
	'more_written&undergrowth_count=2',
	'more_partial&undergrowth_count=1',
	'more_failed',
	'more_none',
	'more_wait',
	'more_waiting',
];

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
		state: 'accept-last-error',
		page: SETTINGS,
		fixture: 'accept-last-error',
	},
	{
		screen: 'settings',
		state: 'file-last-error',
		page: SETTINGS,
		fixture: 'file-last-error',
	},
	{
		screen: 'settings',
		state: 'post-types-by-code',
		page: SETTINGS,
		fixture: 'post-types-by-code',
	},
	{
		screen: 'settings',
		state: 'prompt-key-saved',
		page: SETTINGS,
		fixture: 'prompt-key-saved',
	},
	{ screen: 'studio', state: 'off', page: STUDIO },
	{
		screen: 'studio',
		state: 'empty',
		page: STUDIO,
		fixture: 'prompt-key-saved',
	},
	{
		screen: 'studio',
		state: 'idea-bank-empty',
		page: [ STUDIO[ 0 ], `${ STUDIO[ 1 ] }&view=saved` ],
		fixture: 'prompt-key-saved',
	},
	{
		screen: 'settings',
		state: 'prompt-asked',
		page: SETTINGS,
		fixture: 'prompt-asked',
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
	{
		screen: 'profile',
		state: 'digest-on',
		page: PROFILE,
		fixture: 'digest-on',
	},
	{
		screen: 'privacy',
		state: 'policy-guide',
		page: [ 'options-privacy.php', 'tab=policyguide' ],
		// Opens Undergrowth's section. The plugin's name is never translated.
		open: async ( page ) => {
			const trigger = page.locator(
				'.privacy-settings-accordion-trigger',
				{ hasText: 'Undergrowth' }
			);

			if ( ( await trigger.count() ) === 0 ) {
				return false;
			}

			await trigger.click();

			return true;
		},
	},
	// Where a new owner lands after activating, with the Settings link.
	{ screen: 'plugins', state: 'row', page: [ 'plugins.php', '' ] },
	// The first-run notice, right after activating on a site with no key.
	{
		screen: 'plugins',
		state: 'first-run',
		page: [ 'plugins.php', '' ],
		fixture: 'first-run',
	},
	// Plugins after Dismiss for good on the first-run notice.
	{
		screen: 'plugins',
		state: 'notice-first-run-dismissed',
		page: [ 'plugins.php', 'undergrowth_notice=first_run_dismissed' ],
		fixture: 'no-provider',
		notice: true,
	},
	{
		screen: 'plugins',
		state: 'notice-first-run-restored',
		page: [ 'plugins.php', 'undergrowth_notice=first_run_restored' ],
		fixture: 'first-run',
		notice: true,
	},
	// Settings right after the first key is saved, linking on to suggestions.
	{
		screen: 'settings',
		state: 'first-key-saved',
		page: SETTINGS,
		fixture: 'first-key-saved',
	},
	{ screen: 'suggestions', state: 'empty', page: SUGGESTIONS },
	{ screen: 'orphans', state: 'empty', page: ORPHANS },
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
	...[ 'overview', 'suggestions', 'orphans', 'stages', 'map', 'compost' ].map(
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
	{
		screen: 'settings',
		state: 'backfill-stalled',
		page: SETTINGS,
		fixture: 'backfill-stalled',
	},
	{ screen: 'suggestions', state: 'table', page: SUGGESTIONS },
	{
		screen: 'suggestions',
		state: 'choose-words',
		page: SUGGESTIONS,
		open: async ( page ) => {
			const choose = page.locator( '.row-actions .choose a' ).first();

			if ( ( await choose.count() ) === 0 ) {
				return false;
			}

			await choose.click();
			await page.locator( 'form.undergrowth-choose-words' ).waitFor();

			return true;
		},
	},
	// Posts that could link to one post, as its row on Posts opens it.
	{
		screen: 'suggestions',
		state: 'links-in',
		page: SUGGESTIONS,
		open: async ( page, admin ) => {
			const box = page.locator( 'input[name="link[]"]' ).first();

			if ( ( await box.count() ) === 0 ) {
				return false;
			}

			const target = ( await box.getAttribute( 'value' ) ).split(
				'-'
			)[ 1 ];

			await admin.visitAdminPage(
				SUGGESTIONS[ 0 ],
				`${ SUGGESTIONS[ 1 ] }&undergrowth_to=${ target }`
			);

			return true;
		},
	},
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
	// The one notice after Accept selected, built from the table's own
	// suggestions, so nothing is changed: all linked, then one refused.
	...[
		[ 'notice-accepted-selected', [ 'accepted', 'accepted' ] ],
		[ 'notice-accepted-selected-refused', [ 'accepted', 'edited' ] ],
	].map( ( [ state, outcomes ] ) => ( {
		screen: 'suggestions',
		state,
		page: SUGGESTIONS,
		notice: true,
		open: async ( page, admin ) => {
			const boxes = page.locator( 'input[name="link[]"]' );

			if ( ( await boxes.count() ) < outcomes.length ) {
				return false;
			}

			const results = [];

			for ( const [ index, outcome ] of outcomes.entries() ) {
				const pair = await boxes.nth( index ).getAttribute( 'value' );

				results.push( `${ pair }.${ outcome }` );
			}

			await admin.visitAdminPage(
				SUGGESTIONS[ 0 ],
				`${
					SUGGESTIONS[ 1 ]
				}&undergrowth_notice=accepted_selected&undergrowth_results=${ results.join(
					','
				) }`
			);

			return true;
		},
	} ) ),
	{ screen: 'orphans', state: 'table', page: ORPHANS },
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
	{
		screen: 'studio',
		state: 'batch',
		page: STUDIO,
		fixture: 'prompt-studio',
	},
	{
		screen: 'studio',
		state: 'idea-bank',
		page: [ STUDIO[ 0 ], `${ STUDIO[ 1 ] }&view=saved` ],
		fixture: 'prompt-studio',
	},
	{
		screen: 'studio',
		state: 'help-studio',
		page: STUDIO,
		fixture: 'prompt-studio',
		help: 'undergrowth-studio',
	},
	...PROMPT_NOTICES.map( ( notice ) => ( {
		screen: 'studio',
		state: `notice-${ notice.split( '&' )[ 0 ].replaceAll( '_', '-' ) }`,
		page: [
			STUDIO[ 0 ],
			`${ STUDIO[ 1 ] }&undergrowth_notice=${ notice }`,
		],
		fixture: 'prompt-studio',
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
	// The Dev tools tab is on local sites only, and here only for these shots.
	{ screen: 'dev', state: 'tab', page: DEV, devTools: true },
	{
		screen: 'dev',
		state: 'confirm-reset',
		page: [ DEV[ 0 ], `${ DEV[ 1 ] }&confirm=reset` ],
		devTools: true,
	},
	...DEV_NOTICES.map( ( notice ) => ( {
		screen: 'dev',
		state: `notice-${ notice.replaceAll( '_', '-' ) }`,
		page: [ DEV[ 0 ], `${ DEV[ 1 ] }&undergrowth_notice=${ notice }` ],
		notice: true,
		devTools: true,
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

// Shown after a clustering pass made two clusters, one of them a gap, with
// two posts outside any group: one too far from the rest, one read since.
const WITH_CLUSTERS = [
	{ screen: 'map', state: 'clusters', page: MAP, fixture: 'tight-gaps' },
	// Every group is proposed a hub, as a dense one would be.
	{ screen: 'map', state: 'hub-proposal', page: MAP, fixture: 'hubs' },
	...[ 'hub_started', 'hub_vetoed', 'hub_gone' ].map( ( notice ) => ( {
		screen: 'map',
		state: `notice-${ notice.replaceAll( '_', '-' ) }`,
		page: [ MAP[ 0 ], `${ MAP[ 1 ] }&undergrowth_notice=${ notice }` ],
		notice: true,
	} ) ),
];

// Shown after a pass found two groups and a post near both: a pair on the
// Cross-pollination list, and the notices after dismissing one.
const WITH_POLLINATION = [
	{ screen: 'map', state: 'pollination', page: MAP },
	...[ 'pollination_dismissed', 'pollination_gone' ].map( ( notice ) => ( {
		screen: 'map',
		state: `notice-${ notice.replaceAll( '_', '-' ) }`,
		page: [ MAP[ 0 ], `${ MAP[ 1 ] }&undergrowth_notice=${ notice }` ],
		notice: true,
	} ) ),
	// The surprise scale with its wildest step chosen, not yet saved.
	{
		screen: 'settings',
		state: 'surprise-wild',
		page: SETTINGS,
		open: async ( page ) => {
			const wild = page.locator( '#undergrowth_surprise_5' );

			if ( ( await wild.count() ) === 0 ) {
				return false;
			}

			await wild.check();

			return true;
		},
	},
];

// The same, once the first group has a hub draft.
const WITH_HUB_DRAFT = [ { screen: 'map', state: 'hub-draft', page: MAP } ];

// The same, with so many posts outside a group that their list starts
// collapsed.
const WITH_MANY_UNGROUPED = [
	{ screen: 'map', state: 'ungrouped-collapsed', page: MAP },
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
	// Colour and padding set, shown once, as readers see them.
	{
		screen: 'block-growth-stage',
		state: 'editor-styled',
		edit: () => badgePosts.styled,
	},
];

// Filled in once the list's posts exist, since the shots need their IDs.
const listPosts = {};

// "More like this", on the front end where readers see it and in the editor
// with its settings and the posts on the list in the sidebar. A post with
// nothing close shows readers nothing.
const WITH_MORE_LIKE_THIS = [
	{
		screen: 'block-more-like-this',
		state: 'front-list',
		front: () => `/?p=${ listPosts.source }`,
	},
	{
		screen: 'block-more-like-this',
		state: 'editor-list',
		list: () => listPosts.source,
	},
	{
		screen: 'block-more-like-this',
		state: 'editor-empty',
		list: () => listPosts.alone,
	},
	// Colour and padding set, shown once, as readers see them.
	{
		screen: 'block-more-like-this',
		state: 'editor-styled',
		list: () => listPosts.styled,
	},
];

// Filled in once the index's pages exist, since the shots need their IDs.
const gardenIndexPages = {};

// The garden index on a page, after a cluster pass: one group and the posts
// in none. In the editor, also filtered to a stage no post has reached.
const WITH_GARDEN_INDEX = [
	{
		screen: 'block-garden-index',
		state: 'front-groups',
		front: () => `/?page_id=${ gardenIndexPages.all }`,
	},
	{
		screen: 'block-garden-index',
		state: 'editor-groups',
		edit: () => gardenIndexPages.all,
	},
	{
		screen: 'block-garden-index',
		state: 'editor-empty',
		edit: () => gardenIndexPages.evergreen,
	},
];

// Filled in once the feed's page exists, since the shots need its ID.
const gardenFeedPages = {};

// The garden feed on a page: the featured first post, the rest, and the
// page links. Then filtered by type through the query args, filtered to a
// stage no post has reached, and in the editor.
const WITH_GARDEN_FEED = [
	{
		screen: 'block-garden-feed',
		state: 'front-feed',
		front: () => `/?page_id=${ gardenFeedPages.feed }`,
	},
	{
		screen: 'block-garden-feed',
		state: 'front-filtered',
		front: () => `/?page_id=${ gardenFeedPages.feed }&type=aside`,
	},
	{
		screen: 'block-garden-feed',
		state: 'front-none-match',
		front: () => `/?page_id=${ gardenFeedPages.feed }&stage=evergreen`,
	},
	{
		screen: 'block-garden-feed',
		state: 'editor-feed',
		edit: () => gardenFeedPages.feed,
	},
];

// The garden filters above the feed: every topic, then one chosen with a
// type, and in the editor.
const WITH_GARDEN_FILTERS = [
	{
		screen: 'block-garden-filters',
		state: 'front-all',
		front: () => `/?page_id=${ gardenFeedPages.filters }`,
	},
	{
		screen: 'block-garden-filters',
		state: 'front-chosen',
		front: () =>
			`/?page_id=${ gardenFeedPages.filters }&topic=roots&type=standard`,
	},
	{
		screen: 'block-garden-filters',
		state: 'editor-filters',
		edit: () => gardenFeedPages.filters,
	},
];

// Filled in once the list's posts exist, since the shots need their IDs.
const linkedFromPosts = {};

// The "Linked from" block on a post another post links to through an
// accepted suggestion, and in the editor on a post nothing links to yet.
const WITH_LINKED_FROM = [
	{
		screen: 'block-linked-from',
		state: 'front-linked',
		front: () => `/?p=${ linkedFromPosts.linked }`,
	},
	{
		screen: 'block-linked-from',
		state: 'editor-linked',
		edit: () => linkedFromPosts.linked,
	},
	{
		screen: 'block-linked-from',
		state: 'editor-empty',
		edit: () => linkedFromPosts.empty,
	},
];

// Filled in once the panel's posts exist, since the shots need their IDs.
const panelPosts = {};

// The Undergrowth panel in the editor's Post tab, in each state the author
// can leave a post in, and for a draft, which has no stage yet.
const WITH_PANEL = [ 'seedling', 'pinned', 'composting', 'draft' ].map(
	( state ) => ( {
		screen: 'editor-panel',
		state,
		panel: () => panelPosts[ state ],
		// A build from before the panel has none to show.
		open: ( page ) =>
			page.evaluate( () =>
				Boolean( window.wp.plugins?.getPlugin( 'undergrowth' ) )
			),
	} )
);

// Filled in once the post exists, since the panel's shot needs its ID.
const tendPosts = {};

// An Evergreen post untouched for two years carries a "Worth a look" note
// on the Growth stages tab and in its editor panel.
const WITH_TEND = [
	// A build without the note shows none, and is listed as skipped.
	{
		screen: 'stages',
		state: 'worth-a-look',
		page: STAGES,
		open: async ( page ) =>
			( await page.locator( '.undergrowth-tend' ).count() ) > 0,
	},
	{
		screen: 'editor-panel',
		state: 'worth-a-look',
		panel: () => tendPosts.stale,
		open: ( page ) =>
			page.evaluate(
				async ( id ) =>
					Boolean( window.wp.plugins?.getPlugin( 'undergrowth' ) ) &&
					Boolean(
						(
							await window.wp.apiFetch( {
								path: `/undergrowth/v1/posts/${ id }/stage`,
							} )
						).tend
					),
				tendPosts.stale
			),
	},
];

// Filled in once the sidebar's posts exist, since the shots need their IDs.
const sidebarPosts = {};

// The Undergrowth sidebar in the editor: a post with suggestions of its own,
// and a post others could link to.
const WITH_SIDEBAR = [ 'suggestions', 'incoming' ].map( ( state ) => ( {
	screen: 'editor-sidebar',
	state,
	sidebar: () => sidebarPosts[ state ],
	// A build from before the sidebar has none to show.
	open: ( page ) =>
		page.evaluate( () =>
			Boolean( window.wp.plugins?.getPlugin( 'undergrowth-links' ) )
		),
} ) );

// Filled in once the draft exists, since the shots need its ID.
const scanPosts = {};

// Scan this draft in the sidebar, before anything is sent: with the provider
// named beside the button, and with no provider to send to. The results go
// when the sidebar closes, which it does at each width, so they are not shot.
const WITH_SCAN = [
	{ state: 'offer' },
	{ state: 'no-provider', fixture: 'no-provider' },
].map( ( entry ) => ( {
	...entry,
	screen: 'editor-scan',
	sidebar: () => scanPosts.draft,
	// A build from before the scan has no route for it.
	open: ( page ) =>
		page.evaluate(
			( id ) =>
				window.wp
					.apiFetch( { path: `/undergrowth/v1/posts/${ id }/scan` } )
					.then(
						() => true,
						() => false
					),
			scanPosts.draft
		),
} ) );

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
		// "Tended 4 seconds ago" moves with the time each shot is taken.
		page.locator( '.wp-block-undergrowth-garden-feed time' ),
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
 * Shows the Undergrowth panel in the editor's Post tab.
 *
 * The editor closes its sidebar when the window narrows, so this runs again
 * at each width. The panel is last in the sidebar, which scrolls on its own,
 * so it is scrolled into view.
 *
 * @param {import('@playwright/test').Page} page Editor page.
 */
async function showPanel( page ) {
	// Through the editor's store, since the toolbar button is named in the
	// site's language.
	await page.evaluate( () =>
		window.wp.data
			.dispatch( 'core/edit-post' )
			.openGeneralSidebar( 'edit-post/document' )
	);

	const panel = page.locator( '.undergrowth-editor-panel' );
	const toggle = panel.locator( '.components-panel__body-toggle' );

	if ( ( await toggle.getAttribute( 'aria-expanded' ) ) !== 'true' ) {
		await toggle.click();
	}

	// The stage comes from the server after the panel opens.
	await panel
		.locator( '.undergrowth-stage, .undergrowth-not-in-garden' )
		.first()
		.waitFor();
	await panel.locator( '> :last-child' ).scrollIntoViewIfNeeded();
}

/**
 * Shows the Undergrowth sidebar in the editor.
 *
 * The editor closes its sidebar when the window narrows, so this runs again
 * at each width.
 *
 * @param {import('@playwright/test').Page} page Editor page.
 */
async function showSidebar( page ) {
	// Through the editor's store, since the toolbar button is named in the
	// site's language.
	await page.evaluate( () =>
		window.wp.data
			.dispatch( 'core/edit-post' )
			.openGeneralSidebar( 'undergrowth-links/undergrowth-links' )
	);
	await page
		.locator( '.undergrowth-links-sidebar .undergrowth-links-loaded' )
		.waitFor();

	// A draft's sidebar also offers a scan, described once it has asked.
	if (
		await page.evaluate(
			() =>
				'publish' !==
				window.wp.data
					.select( 'core/editor' )
					.getCurrentPostAttribute( 'status' )
		)
	) {
		await page
			.locator( '.undergrowth-links-sidebar .undergrowth-scan' )
			.waitFor();
	}
}

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

				if ( entry.devTools ) {
					cookies.push( [ 'undergrowth_e2e_dev_tools', '1' ] );
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
					// Block previews are rendered by the server after load.
					await editor.canvas
						.locator(
							'.wp-block-undergrowth-growth-stage p, .wp-block-undergrowth-growth-stage .components-placeholder, .wp-block-undergrowth-garden-index ul, .wp-block-undergrowth-garden-index .components-placeholder, .wp-block-undergrowth-garden-feed ul, .wp-block-undergrowth-garden-feed .components-placeholder, .wp-block-undergrowth-garden-filters form, .wp-block-undergrowth-garden-filters .components-placeholder, .wp-block-undergrowth-linked-from ul, .wp-block-undergrowth-linked-from .components-placeholder'
						)
						.first()
						.waitFor();
				} else if ( entry.list ) {
					await admin.editPost( entry.list() );
					const block = editor.canvas.locator(
						'[data-type="undergrowth/more-like-this"]'
					);
					// The preview is rendered by the server after load.
					await block
						.locator( 'nav, .components-placeholder' )
						.first()
						.waitFor();
					await editor.selectBlocks( block );
					// Through the store, since the toggle's name is translated.
					await page.evaluate( () =>
						window.wp.data
							.dispatch( 'core/edit-post' )
							.openGeneralSidebar( 'edit-post/block' )
					);
					// The posts on the list come from their own route.
					await page
						.locator(
							'.undergrowth-more-like-this-panel :is(ul, p, .components-notice)'
						)
						.first()
						.waitFor();
				} else if ( entry.panel ) {
					await admin.editPost( entry.panel() );
				} else if ( entry.sidebar ) {
					await admin.editPost( entry.sidebar() );
				} else {
					await admin.visitAdminPage( ...entry.page );
				}

				// Opens what this state is about, such as a row's form. A build
				// without it is listed as skipped, as a missing notice is.
				const opened = entry.open
					? await entry.open( page, admin )
					: true;

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
					! opened ||
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

					if ( entry.panel ) {
						await showPanel( page );
					}

					if ( entry.sidebar ) {
						await showSidebar( page );
					}
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

			// Too far from every other post to join a group.
			await requestUtils.createPost( {
				title: 'Bread',
				content: '<p>Sourdough loaves.</p>',
				status: 'publish',
			} );

			const response = await requestUtils.request.get(
				'wp-admin/index.php?undergrowth_e2e_cluster=1'
			);

			if ( ! response.ok() ) {
				throw new Error(
					`Clustering failed with HTTP ${ response.status() }.`
				);
			}

			// Read after the groups were found.
			await requestUtils.createPost( {
				title: 'Yacon pickles',
				content: `<p>${ 'yacon '.repeat( 6 ).trim() }</p>`,
				status: 'publish',
			} );
		} );

		capture( WITH_CLUSTERS );

		test.describe( 'and many posts outside a group', () => {
			test.beforeAll( async ( { requestUtils } ) => {
				for ( let i = 1; i <= 10; i++ ) {
					await requestUtils.createPost( {
						title: `Note ${ i }`,
						content: '<p>A short note.</p>',
						status: 'publish',
					} );
				}
			} );

			capture( WITH_MANY_UNGROUPED );
		} );

		test.describe( 'and a hub draft', () => {
			test.beforeAll( async ( { requestUtils } ) => {
				const response = await requestUtils.request.get(
					'wp-admin/index.php?undergrowth_e2e_hub=1'
				);

				if ( ! response.ok() ) {
					throw new Error(
						`Starting a hub failed with HTTP ${ response.status() }.`
					);
				}
			} );

			test.afterAll( async ( { requestUtils } ) => {
				await requestUtils.deleteAllPages();
			} );

			capture( WITH_HUB_DRAFT );
		} );
	} );

	test.describe( 'with a cross-pollination pair', () => {
		test.beforeAll( async ( { requestUtils } ) => {
			await requestUtils.deleteAllPosts();

			// Two groups of repeated fixture words, and one post with both.
			for ( const [ title, content ] of [
				[ 'First', 'yacon '.repeat( 6 ) ],
				[ 'Second', 'yacon '.repeat( 6 ) ],
				[ 'Third', 'yacon '.repeat( 6 ) ],
				[ 'Roots', 'oca '.repeat( 6 ) ],
				[ 'Old roots', 'oca '.repeat( 6 ) ],
				[ 'Deep roots', 'oca '.repeat( 6 ) ],
				[ 'Both', 'oca '.repeat( 3 ) + 'yacon '.repeat( 3 ) ],
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

		capture( WITH_POLLINATION );
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

			badgePosts.styled = (
				await requestUtils.createPost( {
					title: 'Mashua',
					content: `<!-- wp:paragraph --><p>Oca grows here.</p><!-- /wp:paragraph --><!-- wp:undergrowth/growth-stage ${ JSON.stringify(
						STYLED
					) } /-->`,
					status: 'publish',
				} )
			).id;

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

	test.describe( 'with a More like this list', () => {
		test.beforeAll( async ( { requestUtils } ) => {
			await requestUtils.deleteAllPosts();
			await skipWelcomeGuide( requestUtils );

			// The test site's provider counts fixture words, so posts that
			// share "oca" are close and the ledger is close to nothing.
			for ( const [ name, title, content, excerpt ] of [
				[
					'yacon',
					'Yacon',
					'Oca and yacon share a bed.',
					'Two roots, one bed.',
				],
				[ 'syrup', 'Syrup', 'Oca boiled down to syrup.', '' ],
				[ 'alone', 'Ledger', 'Ledger ledger ledger.', '' ],
				[ 'source', 'Oca', 'Oca oca oca.', '' ],
			] ) {
				listPosts[ name ] = (
					await requestUtils.createPost( {
						title,
						excerpt,
						content: `<!-- wp:paragraph --><p>${ content }</p><!-- /wp:paragraph --><!-- wp:undergrowth/more-like-this {"showExcerpt":true} /-->`,
						status: 'publish',
					} )
				).id;
			}
		} );

		test.beforeAll( async ( { requestUtils } ) => {
			listPosts.styled = (
				await requestUtils.createPost( {
					title: 'Oca again',
					content: `<!-- wp:paragraph --><p>Oca oca oca.</p><!-- /wp:paragraph --><!-- wp:undergrowth/more-like-this ${ JSON.stringify(
						STYLED
					) } /-->`,
					status: 'publish',
				} )
			).id;
		} );

		capture( WITH_MORE_LIKE_THIS );
	} );

	test.describe( 'with a garden index', () => {
		test.beforeAll( async ( { requestUtils } ) => {
			await requestUtils.deleteAllPosts();
			await requestUtils.deleteAllPages();
			await skipWelcomeGuide( requestUtils );

			// Three alike posts make one group; Bread is near none of them.
			for ( const [ title, word ] of [
				[ 'Oca', 'yacon' ],
				[ 'Yacon', 'yacon' ],
				[ 'Mashua', 'yacon' ],
				[ 'Bread', '' ],
			] ) {
				await requestUtils.createPost( {
					title,
					content: `<p>${ Array( 6 ).fill( word ).join( ' ' ) }</p>`,
					status: 'publish',
				} );
			}

			await requestUtils.request.get(
				'wp-admin/index.php?undergrowth_e2e_cluster=1'
			);

			for ( const [ key, attributes ] of [
				[ 'all', '' ],
				[ 'evergreen', ' {"stage":"evergreen"}' ],
			] ) {
				gardenIndexPages[ key ] = (
					await requestUtils.createPage( {
						title: 'Garden',
						content: `<!-- wp:undergrowth/garden-index${ attributes } /-->`,
						status: 'publish',
					} )
				).id;
			}
		} );

		capture( WITH_GARDEN_INDEX );
	} );

	test.describe( 'with a garden feed', () => {
		test.beforeAll( async ( { requestUtils } ) => {
			await requestUtils.deleteAllPosts();
			await requestUtils.deleteAllPages();
			await skipWelcomeGuide( requestUtils );

			// Tags outlive deleted posts, so an earlier run's tags are reused.
			const tags = {};
			for ( const slug of [ 'roots', 'leaves' ] ) {
				const [ found ] = await requestUtils.rest( {
					path: '/wp/v2/tags',
					params: { slug },
				} );
				tags[ slug ] =
					found ??
					( await requestUtils.rest( {
						method: 'POST',
						path: '/wp/v2/tags',
						data: { name: slug, slug },
					} ) );
			}

			for ( const [ title, format, excerpt, tag ] of [
				[
					'Oca',
					'standard',
					'Small tubers that keep through winter.',
					'roots',
				],
				[ 'Yacon', 'aside', 'Sweet roots, eaten raw.', 'roots' ],
				[ 'Mashua', 'standard', '', 'leaves' ],
				[
					'Ulluco',
					'standard',
					'Bright, waxy and slow to grow.',
					'roots',
				],
			] ) {
				await requestUtils.rest( {
					method: 'POST',
					path: '/wp/v2/posts',
					data: {
						title,
						excerpt,
						content: `<p>${ title } grows here.</p>`,
						status: 'publish',
						format,
						tags: [ tags[ tag ].id ],
					},
				} );
			}

			gardenFeedPages.feed = (
				await requestUtils.createPage( {
					title: 'Garden',
					content:
						'<!-- wp:undergrowth/garden-feed {"postsPerPage":3} /-->',
					status: 'publish',
				} )
			).id;

			gardenFeedPages.filters = (
				await requestUtils.createPage( {
					title: 'Garden',
					content:
						'<!-- wp:undergrowth/garden-filters /--><!-- wp:undergrowth/garden-feed {"postsPerPage":3} /-->',
					status: 'publish',
				} )
			).id;
		} );

		capture( WITH_GARDEN_FEED );
		capture( WITH_GARDEN_FILTERS );
	} );

	test.describe( 'with an Evergreen post worth a look', () => {
		test.beforeAll( async ( { requestUtils } ) => {
			await requestUtils.deleteAllPosts();
			await skipWelcomeGuide( requestUtils );

			// Created with an old date, which stays its last edit.
			const date = new Date( Date.now() - 2 * 365 * 24 * 3600 * 1000 );
			tendPosts.stale = (
				await requestUtils.createPost( {
					title: 'Oca',
					content: '<p>Oca grows here.</p>',
					status: 'publish',
					date: date.toISOString().slice( 0, 19 ),
				} )
			).id;

			// A build from before the panel has no route to pin through, and
			// its shots are listed as skipped. Any other failure stops the
			// run, rather than capturing a post with no note.
			await requestUtils
				.rest( {
					method: 'POST',
					path: `/undergrowth/v1/posts/${ tendPosts.stale }/stage`,
					data: { action: 'pin', stage: 'evergreen' },
				} )
				.catch( ( error ) => {
					if ( error?.code !== 'rest_no_route' ) {
						throw error;
					}
				} );
		} );

		capture( WITH_TEND );
	} );

	test.describe( 'with a "Linked from" block', () => {
		test.beforeAll( async ( { requestUtils } ) => {
			await requestUtils.deleteAllPosts();
			await skipWelcomeGuide( requestUtils );

			// Oca's sentence about yacon is suggested as a link to Yacon.
			const oca = await requestUtils.createPost( {
				title: 'Oca',
				content:
					'<!-- wp:paragraph --><p>Oca grows here. I keep yacon nearby.</p><!-- /wp:paragraph --><!-- wp:undergrowth/linked-from /-->',
				status: 'publish',
			} );
			const yacon = await requestUtils.createPost( {
				title: 'Yacon',
				content:
					'<!-- wp:paragraph --><p>Yacon syrup is sweet.</p><!-- /wp:paragraph --><!-- wp:undergrowth/linked-from /-->',
				status: 'publish',
			} );

			linkedFromPosts.linked = yacon.id;
			linkedFromPosts.empty = oca.id;

			// Accepted through the suggestion's own Accept form, as the
			// author's click would send it.
			const screen = await requestUtils.request.get(
				'wp-admin/edit.php?page=undergrowth-links'
			);
			const form = [
				...( await screen.text() ).matchAll(
					/<form method="post" action="[^"]*" id="undergrowth-accept-[^"]*">([\s\S]*?)<\/form>/g
				),
			].find( ( match ) =>
				match[ 1 ].includes( `name="source" value="${ oca.id }"` )
			);

			if ( ! form ) {
				throw new Error( 'No suggestion for Oca to link to Yacon.' );
			}

			const fields = Object.fromEntries(
				[
					...form[ 1 ].matchAll(
						/<input type="hidden"[^>]*name="([^"]*)" value="([^"]*)"/g
					),
				].map( ( [ , name, value ] ) => [ name, value ] )
			);

			await requestUtils.request.post( 'wp-admin/admin-post.php', {
				form: fields,
			} );

			// A failed Accept would otherwise capture an empty list under
			// the "linked" states' names.
			const front = await requestUtils.request.get( `?p=${ yacon.id }` );

			if (
				! ( await front.text() ).includes(
					`<li><a href="${ oca.link }">Oca</a></li>`
				)
			) {
				throw new Error( 'Accepting the link did not list Oca.' );
			}
		} );

		capture( WITH_LINKED_FROM );
	} );

	test.describe( 'with the editor panel', () => {
		test.beforeAll( async ( { requestUtils } ) => {
			await requestUtils.deleteAllPosts();

			await skipWelcomeGuide( requestUtils );

			for ( const [ state, title, action ] of [
				[ 'seedling', 'Oca', null ],
				[ 'pinned', 'Yacon', { action: 'pin', stage: 'budding' } ],
				[ 'composting', 'Seed ledger', { action: 'compost' } ],
				[ 'draft', 'Mashua', null ],
			] ) {
				panelPosts[ state ] = (
					await requestUtils.createPost( {
						title,
						content: '<p>Oca grows here.</p>',
						status: 'draft' === state ? 'draft' : 'publish',
					} )
				).id;

				if ( ! action ) {
					continue;
				}

				// Through the panel's own route, as the author would. A build
				// from before the panel has no route, and its shots are
				// listed as skipped. Any other failure would capture the
				// post unchanged under this state's name, so it stops the run.
				const route = `/undergrowth/v1/posts/${ panelPosts[ state ] }/stage`;
				const answer = await requestUtils
					.rest( { method: 'POST', path: route, data: action } )
					.catch( ( error ) => {
						if ( error?.code === 'rest_no_route' ) {
							return null;
						}

						throw error;
					} );

				if (
					answer &&
					( 'pinned' === state
						? ! answer.pinned
						: 'composting' !== answer.stage )
				) {
					throw new Error( `The ${ state } post did not change.` );
				}
			}
		} );

		capture( WITH_PANEL );
	} );

	test.describe( 'with the editor sidebar', () => {
		test.beforeAll( async ( { requestUtils } ) => {
			await requestUtils.deleteAllPosts();
			await skipWelcomeGuide( requestUtils );

			// Oca suggests a link to Yacon, so Yacon has one that could link to it.
			sidebarPosts.suggestions = (
				await requestUtils.createPost( {
					title: 'Oca',
					content:
						'<!-- wp:paragraph --><p>Oca grows here. I keep yacon nearby.</p><!-- /wp:paragraph -->',
					status: 'publish',
				} )
			).id;
			sidebarPosts.incoming = (
				await requestUtils.createPost( {
					title: 'Yacon',
					content:
						'<!-- wp:paragraph --><p>Yacon syrup is sweet.</p><!-- /wp:paragraph -->',
					status: 'publish',
				} )
			).id;
		} );

		capture( WITH_SIDEBAR );
	} );

	test.describe( 'with a draft to scan', () => {
		test.beforeAll( async ( { requestUtils } ) => {
			await requestUtils.deleteAllPosts();
			await skipWelcomeGuide( requestUtils );

			await requestUtils.createPost( {
				title: 'Yacon',
				content:
					'<!-- wp:paragraph --><p>Yacon syrup is sweet.</p><!-- /wp:paragraph -->',
				status: 'publish',
			} );
			scanPosts.draft = (
				await requestUtils.createPost( {
					title: 'Planting notes',
					content:
						'<!-- wp:paragraph --><p>I keep yacon syrup by the door.</p><!-- /wp:paragraph -->',
					status: 'draft',
				} )
			).id;
		} );

		capture( WITH_SCAN );
	} );
} );
