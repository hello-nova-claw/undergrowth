const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

const PANEL = '.undergrowth-editor-panel';

/**
 * Opens the Undergrowth panel in the editor's Post tab by keyboard.
 *
 * @param {import('@playwright/test').Page} page Editor page.
 */
async function openPanel( page ) {
	const toggle = page.locator( `${ PANEL } .components-panel__body-toggle` );

	await toggle.focus();
	await expect( toggle ).toBeFocused();

	if ( ( await toggle.getAttribute( 'aria-expanded' ) ) !== 'true' ) {
		await page.keyboard.press( 'Enter' );
	}

	await expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );
}

/**
 * Stores the editor preferences on the server before the editor loads.
 *
 * On a site where the editor has never been opened, it loads its stored
 * preferences after the test utilities turn the welcome guide off, and the
 * guide comes back over the panel.
 *
 * @param {Object} requestUtils Request utilities.
 */
async function skipWelcomeGuide( requestUtils ) {
	await requestUtils.rest( {
		method: 'POST',
		path: '/wp/v2/users/me',
		data: {
			meta: {
				persisted_preferences: {
					'core/edit-post': { welcomeGuide: false },
					_modified: new Date().toISOString(),
				},
			},
		},
	} );
}

/**
 * Reads a post's stage through the panel's own route.
 *
 * @param {Object} requestUtils Request utilities.
 * @param {number} id           Post ID.
 * @return {Promise<Object>} The stage.
 */
function stageOf( requestUtils, id ) {
	return requestUtils.rest( { path: `/undergrowth/v1/posts/${ id }/stage` } );
}

// Selectors use classes and attributes, not text, because this spec also
// runs in Hebrew and in the en_XA pseudo-locale.
test.describe( 'Editor stage panel', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await skipWelcomeGuide( requestUtils );
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'pins a stage by keyboard, with no WCAG 2.2 AA violations', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'Oca',
			content: '<p>Oca grows here.</p>',
			status: 'publish',
		} );

		await admin.editPost( post.id );
		await openPanel( page );

		const panel = page.locator( PANEL );
		const choice = panel.locator( 'select' );

		await expect( panel.locator( '.undergrowth-stage' ) ).toBeVisible();
		await expect( choice ).toHaveValue( 'seedling' );
		await expectNoA11yViolations( page, PANEL );

		// Tab from the panel's toggle past the stage choice to Pin. Arrow keys
		// on a closed select differ between platforms, so the stage the post
		// has is the one pinned; choosing another is covered in Jest.
		await page.keyboard.press( 'Tab' );
		await expect( choice ).toBeFocused();
		await page.keyboard.press( 'Tab' );
		await expect( panel.locator( '.undergrowth-pin' ) ).toBeFocused();
		await page.keyboard.press( 'Enter' );

		// The pressed button is replaced by Unpin, so focus moves to the result.
		await expect( panel.locator( '.undergrowth-unpin' ) ).toBeVisible();
		await expect( panel.locator( '.undergrowth-stage' ) ).toBeFocused();
		await expect( choice ).toHaveCount( 0 );
		expect( await stageOf( requestUtils, post.id ) ).toMatchObject( {
			stage: 'seedling',
			pinned: true,
		} );
		await expectNoA11yViolations( page, PANEL );

		// The Growth stages tab shows the same pin.
		await admin.visitAdminPage(
			'edit.php',
			'page=undergrowth-links&tab=stages'
		);
		await expect(
			page.locator( `#undergrowth-stage-${ post.id }` )
		).toHaveCount( 0 );
		await expect( page.locator( '.undergrowth-pinned' ) ).toHaveCount( 1 );
	} );

	test( 'unpins, composts and returns a post', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'Yacon',
			content: '<p>Yacon syrup is sweet.</p>',
			status: 'publish',
		} );

		await requestUtils.rest( {
			method: 'POST',
			path: `/undergrowth/v1/posts/${ post.id }/stage`,
			data: { action: 'pin', stage: 'budding' },
		} );
		await admin.editPost( post.id );
		await openPanel( page );

		const panel = page.locator( PANEL );

		await panel.locator( '.undergrowth-unpin' ).click();
		await expect( panel.locator( 'select' ) ).toHaveValue( 'seedling' );

		await panel.locator( '.undergrowth-compost' ).click();
		await expect( panel.locator( '.undergrowth-return' ) ).toBeVisible();
		await expect( panel.locator( 'select' ) ).toHaveCount( 0 );
		expect( ( await stageOf( requestUtils, post.id ) ).stage ).toBe(
			'composting'
		);
		await expectNoA11yViolations( page, PANEL );

		await panel.locator( '.undergrowth-return' ).click();
		await expect( panel.locator( '.undergrowth-compost' ) ).toBeVisible();
		expect( ( await stageOf( requestUtils, post.id ) ).stage ).toBe(
			'seedling'
		);
	} );

	test( 'offers nothing for a draft', async ( { admin, page } ) => {
		await admin.createNewPost();
		await openPanel( page );

		const panel = page.locator( PANEL );

		await expect(
			panel.locator( '.undergrowth-not-in-garden' )
		).toBeVisible();
		await expect(
			panel.locator(
				'select, .components-button:not(.components-panel__body-toggle)'
			)
		).toHaveCount( 0 );
		await expectNoA11yViolations( page, PANEL );
	} );
} );
