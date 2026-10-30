const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );
const { uploadFeaturedImage } = require( './featured-image' );
const { skipWelcomeGuide } = require( './preferences' );
const { STYLED, expectSupportsOnce } = require( './supports' );

const FEED = '.wp-block-undergrowth-garden-feed';
const TITLE = '.wp-block-undergrowth-garden-feed-title';

/**
 * Publishes a garden page holding the feed.
 *
 * @param {Object} requestUtils Request utilities.
 * @param {Object} attributes   Block attributes.
 * @return {Promise<Object>} The page.
 */
async function feedPage( requestUtils, attributes = {} ) {
	return requestUtils.createPage( {
		title: 'Garden',
		content: `<!-- wp:undergrowth/garden-feed ${ JSON.stringify(
			attributes
		) } /-->`,
		status: 'publish',
	} );
}

// Selectors use classes and block names, not text, because this spec also
// runs in Hebrew and in the en_XA pseudo-locale. Post titles stay as written.
test.describe( 'Garden feed block', () => {
	let posts;

	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllPages();
		await skipWelcomeGuide( requestUtils );

		// Tags outlive deleted posts, so an earlier test's tag is reused.
		const [ found ] = await requestUtils.rest( {
			path: '/wp/v2/tags',
			params: { slug: 'roots' },
		} );
		const roots =
			found ??
			( await requestUtils.rest( {
				method: 'POST',
				path: '/wp/v2/tags',
				data: { name: 'Roots', slug: 'roots' },
			} ) );

		// Two entries with a picture, one without, so the grid holds both.
		const picture = await uploadFeaturedImage( requestUtils );

		posts = {};
		for ( const [ name, format, tags, featured ] of [
			[ 'Oca', 'standard', [ roots.id ], picture ],
			[ 'Yacon', 'aside', [], 0 ],
			[ 'Mashua', 'standard', [], picture ],
		] ) {
			posts[ name ] = await requestUtils.rest( {
				method: 'POST',
				path: '/wp/v2/posts',
				data: {
					title: name,
					content: `<p>${ name } grows here.</p>`,
					excerpt: `${ name } in a line.`,
					status: 'publish',
					format,
					tags,
					featured_media: featured,
				},
			} );
		}

		// Modified dates count in seconds, so Oca is tended a moment later
		// and leads the feed although it was planted first.
		await new Promise( ( resolve ) => setTimeout( resolve, 1100 ) );
		await requestUtils.rest( {
			method: 'POST',
			path: `/wp/v2/posts/${ posts.Oca.id }`,
			data: { content: '<p>Oca grows here, and more.</p>' },
		} );
	} );

	test( 'lists the garden newest tended first and filters it by the query args', async ( {
		page,
		requestUtils,
	} ) => {
		const garden = await feedPage( requestUtils, { fontSize: 'small' } );

		await page.goto( `/?page_id=${ garden.id }` );

		const feed = page.locator( FEED );
		await expect( feed ).toHaveClass( /has-small-font-size/ );
		await expect( feed.locator( TITLE ) ).toHaveText( [
			'Oca',
			'Mashua',
			'Yacon',
		] );
		// Every entry with a picture shows it, above its title and as wide
		// as the entry, and says nothing more to a screen reader.
		const pictured = feed.locator( '.has-image' );
		await expect( pictured ).toHaveCount( 2 );
		await expect( feed.locator( 'img[alt=""]' ) ).toHaveCount( 2 );
		const entry = await pictured.first().boundingBox();
		const image = await pictured.first().locator( 'img' ).boundingBox();
		const title = await pictured.first().locator( TITLE ).boundingBox();
		expect( Math.round( image.width ) ).toBe( Math.round( entry.width ) );
		expect( image.y + image.height ).toBeLessThanOrEqual( title.y );
		// The entry without a picture is a text card: its type's icon in a
		// column on the left, never a link, with the card's title beside
		// it; the two cards share one left edge, and each names its type
		// and stage for a theme to style.
		const card = feed.locator( '.has-icon' );
		await expect( card ).toHaveCount( 1 );
		await expect( card ).toHaveAttribute( 'data-type', 'aside' );
		await expect( card ).toHaveAttribute( 'data-stage', /^[a-z]+$/ );
		await expect( pictured.first() ).toHaveAttribute(
			'data-type',
			'standard'
		);
		await expect(
			card.locator(
				'.wp-block-undergrowth-garden-feed-icon svg[aria-hidden="true"]'
			)
		).toHaveCount( 1 );
		await expect( card.locator( 'a' ) ).toHaveCount( 1 );
		const icon = await card
			.locator( '.wp-block-undergrowth-garden-feed-icon' )
			.boundingBox();
		const cardTitle = await card.locator( TITLE ).boundingBox();
		const [ fontSize, rtl ] = await card.evaluate( ( li ) => [
			parseFloat( window.getComputedStyle( li ).fontSize ),
			window.getComputedStyle( li ).direction === 'rtl',
		] );
		// The edge the text starts from, which is the right one in Hebrew.
		const start = ( box ) => ( rtl ? box.x + box.width : box.x );
		expect(
			Math.abs( start( icon ) - start( image ) )
		).toBeLessThanOrEqual( 1 );
		expect(
			Math.abs( start( cardTitle ) - start( icon ) )
		).toBeLessThanOrEqual( 2 * fontSize + 1 );
		expect(
			rtl
				? cardTitle.x + cardTitle.width <= icon.x
				: cardTitle.x >= icon.x + icon.width
		).toBe( true );
		// Each stage's glyph sits in the title's link, drawn in the link's
		// colour and left out of its name; the stage is named after it.
		await expect(
			feed.locator( `${ TITLE } svg[aria-hidden="true"]` )
		).toHaveCount( 3 );
		await expect( feed.locator( '.screen-reader-text' ) ).toHaveCount( 3 );
		const lead = feed.locator( TITLE ).first();
		await expect( lead ).toHaveAccessibleName( 'Oca' );
		expect(
			await lead.evaluate( ( link ) => [
				window.getComputedStyle( link.querySelector( 'svg' ) )
					.stroke === window.getComputedStyle( link ).color,
				link.getBoundingClientRect().height >= 24,
			] )
		).toEqual( [ true, true ] );
		await expect( feed.locator( 'time[datetime]' ) ).toHaveCount( 3 );
		// Only the aside has a type; a standard post carries no label.
		await expect(
			feed.locator( '.wp-block-undergrowth-garden-feed-type' )
		).toHaveCount( 1 );
		await expectNoA11yViolations( page, FEED );

		await page.goto( `/?page_id=${ garden.id }&type=aside` );
		await expect( page.locator( `${ FEED } ${ TITLE }` ) ).toHaveText( [
			'Yacon',
		] );

		await page.goto( `/?page_id=${ garden.id }&topic=roots` );
		await expect( page.locator( `${ FEED } ${ TITLE }` ) ).toHaveText( [
			'Oca',
		] );

		// No post is Evergreen yet, so readers are told nothing matches.
		await page.goto( `/?page_id=${ garden.id }&stage=evergreen` );
		await expect(
			page.locator( `${ FEED } .wp-block-undergrowth-garden-feed-none` )
		).toBeVisible();
		await expectNoA11yViolations( page, FEED );
	} );

	test( 'pages through the garden by keyboard, keeping the filter', async ( {
		page,
		requestUtils,
	} ) => {
		const garden = await feedPage( requestUtils, { postsPerPage: 1 } );

		await page.goto( `/?page_id=${ garden.id }&type=standard` );
		await expect( page.locator( `${ FEED } ${ TITLE }` ) ).toHaveText( [
			'Oca',
		] );
		await expectNoA11yViolations( page, FEED );

		const next = page.locator(
			`${ FEED } .wp-block-undergrowth-garden-feed-pages a.next`
		);
		await next.focus();
		await page.keyboard.press( 'Enter' );

		await expect( page ).toHaveURL( /type=standard/ );
		await expect( page ).toHaveURL( /garden-page=2/ );
		await expect( page.locator( `${ FEED } ${ TITLE }` ) ).toHaveText( [
			'Mashua',
		] );
		// A picture shows on every page, not only the first.
		await expect( page.locator( `${ FEED } .has-image img` ) ).toHaveCount(
			1
		);
	} );

	test( 'keeps the images of a block saved before the setting was renamed', async ( {
		page,
		requestUtils,
	} ) => {
		const garden = await feedPage( requestUtils, { showFeatured: true } );

		await page.goto( `/?page_id=${ garden.id }` );
		await expect( page.locator( `${ FEED } .has-image img` ) ).toHaveCount(
			2
		);

		const bare = await feedPage( requestUtils, { showImages: false } );

		await page.goto( `/?page_id=${ bare.id }` );
		await expect( page.locator( `${ FEED } ${ TITLE }` ) ).toHaveCount( 3 );
		await expect( page.locator( `${ FEED } img` ) ).toHaveCount( 0 );
	} );

	test( 'previews in the editor and changes the order and layout by keyboard', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		const garden = await feedPage( requestUtils );

		await admin.editPost( garden.id );

		const preview = editor.canvas.locator( `${ FEED } ${ FEED }` );
		await expect( preview.locator( TITLE ) ).toHaveCount( 3 );
		await expectNoA11yViolations( page, [
			'iframe[name="editor-canvas"]',
			FEED,
		] );

		await editor.selectBlocks(
			editor.canvas.locator( '[data-type="undergrowth/garden-feed"]' )
		);
		// Through the store, since the toggle's name is translated.
		await page.evaluate( () =>
			window.wp.data
				.dispatch( 'core/edit-post' )
				.openGeneralSidebar( 'edit-post/block' )
		);
		const order = page
			.locator( '.block-editor-block-inspector select' )
			.first();
		const newest = await order
			.locator( 'option[value="newest"]' )
			.textContent();
		await order.focus();
		// A closed select picks the option whose name is typed, in any locale.
		await page.keyboard.type( newest );
		await expect( order ).toHaveValue( 'newest' );

		await expect( preview.locator( TITLE ) ).toHaveText( [
			'Mashua',
			'Yacon',
			'Oca',
		] );

		// The Layout select is the last in the panel. The arrow keys move a
		// closed select between its two options in any locale, and the
		// server render picks the layout up.
		const layout = page
			.locator( '.block-editor-block-inspector select' )
			.last();
		await expect( layout ).toHaveValue( 'grid' );
		await expect( preview ).not.toHaveClass( /is-layout-masonry/ );
		await layout.focus();
		await page.keyboard.press( 'ArrowDown' );
		await expect( layout ).toHaveValue( 'masonry' );
		await expect( layout ).toBeFocused();
		await expect( preview ).toHaveClass( /is-layout-masonry/ );
		await expect( preview.locator( TITLE ) ).toHaveCount( 3 );

		// And back to the grid.
		await page.keyboard.press( 'ArrowUp' );
		await expect( layout ).toHaveValue( 'grid' );
		await expect( preview ).not.toHaveClass( /is-layout-masonry/ );
		await expectNoA11yViolations( page, '.block-editor-block-inspector' );
	} );

	test( 'shows colour and spacing once in the editor', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		// On a post, which the check opens by its ID.
		const styled = await requestUtils.createPost( {
			title: 'Styled',
			content: `<!-- wp:undergrowth/garden-feed ${ JSON.stringify(
				STYLED
			) } /-->`,
			status: 'publish',
		} );

		await expectSupportsOnce( {
			page,
			admin,
			editor,
			postId: styled.id,
			name: 'undergrowth/garden-feed',
			rendered: 'ul',
		} );
	} );
} );
