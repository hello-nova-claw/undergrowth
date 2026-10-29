const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );
const { skipWelcomeGuide } = require( './preferences' );

const FILTERS = '.wp-block-undergrowth-garden-filters';
const FEED = '.wp-block-undergrowth-garden-feed';
const TITLE = '.wp-block-undergrowth-garden-feed-title';
const COUNT = '.wp-block-undergrowth-garden-filters-count';
const TOPICS = '.wp-block-undergrowth-garden-filters-topics';
const SUBMIT = '.wp-block-undergrowth-garden-filters-submit';

// Enough topics to overflow the row on a phone.
const MORE_TOPICS = [
	'tubers',
	'andes',
	'winter',
	'storage',
	'seedlings',
	'harvest',
];

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
	for ( const slug of [ 'roots', 'leaves', ...MORE_TOPICS ] ) {
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

	for ( const [ title, format, ...tagged ] of [
		[ 'Oca', 'standard', 'roots', ...MORE_TOPICS ],
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
				tags: tagged.map( ( tag ) => tags[ tag ].id ),
			},
		} );
	}

	return requestUtils.createPage( {
		title: 'Garden',
		content:
			'<!-- wp:undergrowth/garden-filters {"showCount":true} /--><!-- wp:undergrowth/garden-feed /-->',
		status: 'publish',
	} );
}

/**
 * Reads where the topics and the form sit, to tell one row from two.
 *
 * @param {import('@playwright/test').Page} page The garden page.
 * @return {Promise<{topics: Object, form: Object}>} Bounding boxes.
 */
async function rowBoxes( page ) {
	return {
		topics: await page.locator( `${ FILTERS } ${ TOPICS }` ).boundingBox(),
		form: await page.locator( `${ FILTERS } form` ).boundingBox(),
	};
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
			// Without the script, the button is the only way to send the form.
			await expect(
				page.locator( `${ FILTERS } ${ SUBMIT } button` )
			).toBeVisible();

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

			// Arrow keys pick an option in any locale, where typing a
			// translated name may not. The types are sorted by their names.
			const type = page.locator( `${ FILTERS } select[name="type"]` );
			await type.focus();
			for (
				let tries = 0;
				tries < 3 && ( await type.inputValue() ) !== 'aside';
				tries++
			) {
				await page.keyboard.press( 'ArrowDown' );
			}
			await expect( type ).toHaveValue( 'aside' );
			await page.locator( `${ FILTERS } button[type="submit"]` ).focus();
			await page.keyboard.press( 'Enter' );

			// Still the garden page, by ID or by its pretty permalink.
			await expect( page ).toHaveURL(
				new RegExp( `page_id=${ garden.id }|/garden/` )
			);
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
		// A select applies itself, so the button is hidden.
		await expect(
			page.locator( `${ FILTERS } ${ SUBMIT } button` )
		).toBeHidden();
		await expectNoA11yViolations( page, FILTERS );
		await expectNoA11yViolations( page, FEED );
	} );

	test( 'is one row that becomes two on a phone, and keeps a focused topic in view', async ( {
		page,
	} ) => {
		await page.goto( `/?page_id=${ garden.id }` );

		// The label, the topics and the selects share a row.
		const wide = await rowBoxes( page );
		expect( wide.form.y ).toBeLessThan(
			wide.topics.y + wide.topics.height
		);

		await page.setViewportSize( { width: 390, height: 800 } );
		const narrow = await rowBoxes( page );
		expect( narrow.form.y ).toBeGreaterThanOrEqual(
			narrow.topics.y + narrow.topics.height
		);

		// The topics never wrap: the list scrolls sideways instead.
		const list = page.locator( `${ FILTERS } ${ TOPICS } ul` );
		const links = page.locator( `${ FILTERS } ${ TOPICS } a` );
		await expect( links ).toHaveCount( 3 + MORE_TOPICS.length );
		expect(
			await list.evaluate(
				( element ) => element.scrollWidth > element.clientWidth
			)
		).toBe( true );
		const first = await links.first().boundingBox();
		const last = await links.last().boundingBox();
		expect( Math.round( last.y ) ).toBe( Math.round( first.y ) );

		// Tab reaches the last topic, and the list scrolls so the whole
		// link sits inside it, clear of the fade at its end: the right edge
		// in English, the left edge in Hebrew.
		await links.first().focus();
		for ( let tab = 1; tab < 3 + MORE_TOPICS.length; tab++ ) {
			await page.keyboard.press( 'Tab' );
		}
		await expect( links.last() ).toBeFocused();
		const box = await list.boundingBox();
		const focused = await links.last().boundingBox();
		const { fade, rtl } = await list.evaluate( ( element ) => {
			const style = window.getComputedStyle( element );
			return {
				fade: 3 * parseFloat( style.fontSize ),
				rtl: 'rtl' === style.direction,
			};
		} );
		const clear = {
			left: box.x + ( rtl ? fade : 0 ),
			right: box.x + box.width - ( rtl ? 0 : fade ),
		};
		expect( focused.x ).toBeGreaterThanOrEqual( clear.left );
		expect( focused.x + focused.width ).toBeLessThanOrEqual( clear.right );
		await expectNoA11yViolations( page, FILTERS );
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
