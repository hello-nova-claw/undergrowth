const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );
const { skipWelcomeGuide } = require( './preferences' );

const FILTERS = '.wp-block-undergrowth-garden-filters';
const FEED = '.wp-block-undergrowth-garden-feed';
const TITLE = '.wp-block-undergrowth-garden-feed-title';
const COUNT = '.wp-block-undergrowth-garden-filters-count';

/**
 * Plants a small garden and a page holding the filters and the feed.
 *
 * @param {Object} requestUtils Request utilities.
 * @return {Promise<Object>} The page.
 */
async function plantGarden( requestUtils ) {
	await requestUtils.deleteAllPosts();
	await requestUtils.deleteAllPages();
	await skipWelcomeGuide( requestUtils );

	// Tags outlive deleted posts, so an earlier test's tag is reused.
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

	for ( const [ title, format, tag ] of [
		[ 'Oca', 'standard', 'roots' ],
		[ 'Yacon', 'aside', 'roots' ],
		[ 'Mashua', 'standard', 'leaves' ],
	] ) {
		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: {
				title,
				content: `<p>${ title } grows here.</p>`,
				status: 'publish',
				format,
				tags: [ tags[ tag ].id ],
			},
		} );
	}

	return requestUtils.createPage( {
		title: 'Garden',
		content:
			'<!-- wp:undergrowth/garden-filters /--><!-- wp:undergrowth/garden-feed /-->',
		status: 'publish',
	} );
}

// Selectors use classes, names and slugs, not text, because this spec also
// runs in Hebrew and in the en_XA pseudo-locale. Post titles stay as written.
test.describe( 'Garden filters block', () => {
	let garden;

	test.beforeEach( async ( { requestUtils } ) => {
		garden = await plantGarden( requestUtils );
	} );

	// axe runs as a script in the page, so the same markup is checked with
	// JavaScript on, below.
	test.describe( 'without JavaScript', () => {
		test.use( { javaScriptEnabled: false } );

		test( 'filters by topic link and by the form, with shareable addresses', async ( {
			page,
		} ) => {
			await page.goto( `/?page_id=${ garden.id }` );
			await expect( page.locator( `${ FEED } ${ TITLE }` ) ).toHaveCount(
				3
			);

			const roots = page.locator( `${ FILTERS } a[href*="topic=roots"]` );
			await roots.focus();
			await page.keyboard.press( 'Enter' );

			await expect( page ).toHaveURL( /topic=roots/ );
			await expect( page.locator( `${ FEED } ${ TITLE }` ) ).toHaveText( [
				'Yacon',
				'Oca',
			] );
			await expect(
				page.locator( `${ FILTERS } a[aria-current="true"]` )
			).toHaveAttribute( 'href', /topic=roots/ );

			// A closed select picks the option whose name is typed, in any
			// locale; the button sends the form.
			const type = page.locator( `${ FILTERS } select[name="type"]` );
			const aside = await type
				.locator( 'option[value="aside"]' )
				.textContent();
			await type.focus();
			await page.keyboard.type( aside );
			await page.locator( `${ FILTERS } button[type="submit"]` ).focus();
			await page.keyboard.press( 'Enter' );

			await expect( page ).toHaveURL( /page_id=\d+/ );
			await expect( page ).toHaveURL( /topic=roots/ );
			await expect( page ).toHaveURL( /type=aside/ );
			await expect( page.locator( `${ FEED } ${ TITLE }` ) ).toHaveText( [
				'Yacon',
			] );
		} );
	} );

	test( 'updates the feed in place and keeps the address in step', async ( {
		page,
	} ) => {
		await page.goto( `/?page_id=${ garden.id }` );
		await expect( page.locator( `${ FEED } ${ TITLE }` ) ).toHaveCount( 3 );
		const before = await page
			.locator( `${ FILTERS } ${ COUNT }` )
			.textContent();

		// Survives only while the page is not reloaded.
		await page.evaluate( () => ( window.undergrowthNoReload = true ) );

		const leaves = page.locator( `${ FILTERS } a[href*="topic=leaves"]` );
		await leaves.focus();
		await page.keyboard.press( 'Enter' );

		await expect( page ).toHaveURL( /topic=leaves/ );
		await expect( page.locator( `${ FEED } ${ TITLE }` ) ).toHaveText( [
			'Mashua',
		] );
		await expect(
			page.locator( `${ FILTERS } ${ COUNT }` )
		).not.toHaveText( before );
		await expect(
			page.locator( `${ FILTERS } ${ COUNT }` )
		).toHaveAttribute( 'role', 'status' );

		// Back to every topic, then a stage chosen from the select alone.
		await page.locator( `${ FILTERS } nav a` ).first().focus();
		await page.keyboard.press( 'Enter' );
		await expect( page.locator( `${ FEED } ${ TITLE }` ) ).toHaveCount( 3 );

		const type = page.locator( `${ FILTERS } select[name="type"]` );
		await type.selectOption( 'standard' );

		await expect( page ).toHaveURL( /type=standard/ );
		await expect( page.locator( `${ FEED } ${ TITLE }` ) ).toHaveText( [
			'Mashua',
			'Oca',
		] );
		expect( await page.evaluate( () => window.undergrowthNoReload ) ).toBe(
			true
		);
		await expectNoA11yViolations( page, FILTERS );
		await expectNoA11yViolations( page, FEED );
	} );

	test( 'previews in the editor', async ( { admin, editor, page } ) => {
		await admin.editPost( garden.id );

		const preview = editor.canvas.locator( `${ FILTERS } ${ FILTERS }` );
		await expect( preview.locator( 'select' ) ).toHaveCount( 2 );
		await expectNoA11yViolations( page, [
			'iframe[name="editor-canvas"]',
			FILTERS,
		] );

		await editor.selectBlocks(
			editor.canvas.locator( '[data-type="undergrowth/garden-filters"]' )
		);
		// Through the store, since the toggle's name is translated.
		await page.evaluate( () =>
			window.wp.data
				.dispatch( 'core/edit-post' )
				.openGeneralSidebar( 'edit-post/block' )
		);
		await expectNoA11yViolations( page, '.block-editor-block-inspector' );
	} );
} );
