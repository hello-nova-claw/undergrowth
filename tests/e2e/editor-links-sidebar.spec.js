const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );
const { skipWelcomeGuide } = require( './preferences' );

const SIDEBAR = '.undergrowth-links-sidebar';

/**
 * Opens the Undergrowth sidebar from its toolbar button, by keyboard.
 *
 * @param {import('@playwright/test').Page} page Editor page.
 */
async function openSidebar( page ) {
	const toggle = page.locator(
		'button[aria-controls="undergrowth-links:undergrowth-links"]'
	);

	await toggle.focus();
	await expect( toggle ).toBeFocused();
	await page.keyboard.press( 'Enter' );
	await expect(
		page.locator( `${ SIDEBAR } .undergrowth-links-loaded` )
	).toBeVisible();
}

/**
 * Reads a post's suggestions through the sidebar's own route.
 *
 * @param {Object} requestUtils Request utilities.
 * @param {number} id           Post ID.
 * @return {Promise<Object>} Outgoing and incoming suggestions.
 */
function linksOf( requestUtils, id ) {
	return requestUtils.rest( { path: `/undergrowth/v1/posts/${ id }/links` } );
}

// Selectors use classes and attributes, not text, because this spec also
// runs in Hebrew and in the en_XA pseudo-locale.
test.describe( 'Editor links sidebar', () => {
	let oca;
	let yacon;

	test.beforeAll( async ( { requestUtils } ) => {
		await skipWelcomeGuide( requestUtils );
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();

		// The e2e provider counts fixture words, so Oca suggests Yacon.
		oca = await requestUtils.createPost( {
			title: 'Oca',
			content:
				'<!-- wp:paragraph --><p>Oca grows here. I keep yacon nearby.</p><!-- /wp:paragraph -->',
			status: 'publish',
		} );
		yacon = await requestUtils.createPost( {
			title: 'Yacon',
			content:
				'<!-- wp:paragraph --><p>Yacon syrup is sweet.</p><!-- /wp:paragraph -->',
			status: 'publish',
		} );
	} );

	test( 'accepts by keyboard in the editor, undoably, and keeps it on save', async ( {
		admin,
		editor,
		page,
		pageUtils,
		requestUtils,
	} ) => {
		expect(
			( await linksOf( requestUtils, oca.id ) ).outgoing.map(
				( item ) => item.target
			)
		).toContain( yacon.id );

		await admin.editPost( oca.id );
		await openSidebar( page );

		const sidebar = page.locator( SIDEBAR );
		const suggestion = sidebar
			.locator( '.undergrowth-suggestion' )
			.filter( { has: page.locator( `a[href="${ yacon.link }"]` ) } );
		const accept = suggestion.locator( '.undergrowth-accept' );
		const link = editor.canvas.locator( `p a[href="${ yacon.link }"]` );

		await expect( suggestion.locator( 'mark' ) ).toBeVisible();
		await expectNoA11yViolations( page, SIDEBAR );

		await accept.focus();
		await expect( accept ).toBeFocused();
		await page.keyboard.press( 'Enter' );

		// The words are linked in the paragraph, and nothing is saved yet.
		await expect( link ).toHaveCount( 1 );
		await expect( accept ).toHaveCount( 0 );
		await expect( suggestion.locator( '.description' ) ).toBeFocused();
		expect(
			( await linksOf( requestUtils, oca.id ) ).outgoing.map(
				( item ) => item.target
			)
		).toContain( yacon.id );
		await expectNoA11yViolations( page, SIDEBAR );

		// Undo takes the link out again, and Redo puts it back.
		await page.locator( '.editor-history__undo' ).click();
		await expect( link ).toHaveCount( 0 );
		await expect( accept ).toBeVisible();
		await page.locator( '.editor-history__redo' ).click();
		await expect( link ).toHaveCount( 1 );

		await pageUtils.pressKeys( 'primary+s' );

		// Saved with the link, so the suggestion is accepted and leaves the list.
		await expect( suggestion ).toHaveCount( 0 );
		expect(
			( await linksOf( requestUtils, oca.id ) ).outgoing.map(
				( item ) => item.target
			)
		).not.toContain( yacon.id );
	} );

	test( 'saving after Undo leaves the suggestion as it was', async ( {
		admin,
		editor,
		page,
		pageUtils,
		requestUtils,
	} ) => {
		await admin.editPost( oca.id );
		await openSidebar( page );

		const suggestion = page
			.locator( `${ SIDEBAR } .undergrowth-suggestion` )
			.filter( { has: page.locator( `a[href="${ yacon.link }"]` ) } );

		await suggestion.locator( '.undergrowth-accept' ).click();
		await expect(
			editor.canvas.locator( `p a[href="${ yacon.link }"]` )
		).toHaveCount( 1 );
		await page.locator( '.editor-history__undo' ).click();
		await expect(
			editor.canvas.locator( `p a[href="${ yacon.link }"]` )
		).toHaveCount( 0 );

		// Undo leaves nothing to save, so the title changes too, and the test
		// waits for the save itself rather than for the editor's buttons.
		await editor.canvas.locator( '.editor-post-title__input' ).click();
		await page.keyboard.press( 'End' );
		await page.keyboard.type( ' notes' );

		const saved = page.waitForResponse(
			( response ) =>
				[ 'POST', 'PUT' ].includes( response.request().method() ) &&
				response.url().includes( `posts/${ oca.id }` ) &&
				! response.url().includes( 'autosaves' )
		);

		await pageUtils.pressKeys( 'primary+s' );
		expect( ( await saved ).ok() ).toBe( true );

		expect(
			( await linksOf( requestUtils, oca.id ) ).outgoing.map(
				( item ) => item.target
			)
		).toContain( yacon.id );
	} );

	test( 'vetoes by keyboard and moves focus to the list', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await admin.editPost( oca.id );
		await openSidebar( page );

		const suggestion = page
			.locator( `${ SIDEBAR } .undergrowth-suggestion` )
			.filter( { has: page.locator( `a[href="${ yacon.link }"]` ) } );
		const veto = suggestion.locator( '.undergrowth-veto' );

		await veto.focus();
		await page.keyboard.press( 'Enter' );

		await expect( suggestion ).toHaveCount( 0 );
		await expect(
			page.locator( `${ SIDEBAR } .undergrowth-outgoing` )
		).toBeFocused();
		expect(
			( await linksOf( requestUtils, oca.id ) ).outgoing.map(
				( item ) => item.target
			)
		).not.toContain( yacon.id );
	} );

	test( 'chooses other words by keyboard, as the Suggestions tab does', async ( {
		admin,
		page,
	} ) => {
		await admin.editPost( oca.id );
		await openSidebar( page );

		const suggestion = page
			.locator( `${ SIDEBAR } .undergrowth-suggestion` )
			.filter( { has: page.locator( `a[href="${ yacon.link }"]` ) } );
		const choose = suggestion.locator( '.undergrowth-choose' );

		await choose.focus();
		await page.keyboard.press( 'Enter' );

		const [ first, last ] = [
			suggestion.locator( 'select' ).first(),
			suggestion.locator( 'select' ).last(),
		];

		// The form opens on the first word's choice.
		await expect( first ).toBeFocused();
		await expectNoA11yViolations( page, SIDEBAR );

		// "I keep yacon nearby." Choose "keep yacon": words 2 to 3.
		await first.selectOption( '1' );
		await last.selectOption( '2' );
		await page.keyboard.press( 'Tab' );
		await page.keyboard.press( 'Tab' );
		await page.keyboard.press( 'Enter' );

		await expect( suggestion.locator( 'mark' ) ).toHaveText( 'keep yacon' );
		await expect( choose ).toBeFocused();
	} );

	test( 'offers nothing once the sentence has changed', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.editPost( oca.id );
		await openSidebar( page );

		const suggestion = page
			.locator( `${ SIDEBAR } .undergrowth-suggestion` )
			.filter( { has: page.locator( `a[href="${ yacon.link }"]` ) } );

		await expect(
			suggestion.locator( '.undergrowth-accept' )
		).toBeVisible();

		const paragraph = editor.canvas
			.locator( 'p.wp-block-paragraph' )
			.first();

		await paragraph.click();
		await page.keyboard.press( 'End' );
		await page.keyboard.press( 'Backspace' );

		await expect( suggestion.locator( '.undergrowth-accept' ) ).toHaveCount(
			0
		);
		await expect( suggestion.locator( '.description' ) ).toBeVisible();
	} );

	test( 'lists the posts that could link here, with no Accept', async ( {
		admin,
		page,
	} ) => {
		await admin.editPost( yacon.id );
		await openSidebar( page );

		const incoming = page.locator( `${ SIDEBAR } .undergrowth-incoming` );

		await expect(
			incoming.locator( `a[href*="post=${ oca.id }"]` )
		).toBeVisible();
		await expect( incoming.locator( 'button' ) ).toHaveCount( 0 );
		await expectNoA11yViolations( page, SIDEBAR );
	} );
} );
