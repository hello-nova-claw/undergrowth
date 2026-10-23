const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

// Selectors use ids, classes, names and values, not text, because this spec
// also runs in Hebrew and in the en_XA pseudo-locale.
test.describe( 'Growth stages table views, sorting and bulk actions', () => {
	let oca;
	let yacon;
	let mashua;

	test.beforeEach( async ( { requestUtils, admin } ) => {
		await requestUtils.deleteAllPosts();

		oca = await requestUtils.createPost( {
			title: 'Oca',
			content: '<p>Oca grows here.</p>',
			status: 'publish',
		} );
		yacon = await requestUtils.createPost( {
			title: 'Yacon',
			content: '<p>Yacon syrup is sweet.</p>',
			status: 'publish',
		} );
		mashua = await requestUtils.createPost( {
			title: 'Mashua',
			content: '<p>Mashua climbs the fence.</p>',
			status: 'publish',
		} );

		await admin.visitAdminPage(
			'edit.php',
			'page=undergrowth-links&tab=stages'
		);
	} );

	/**
	 * Lists the post IDs in the table, in order, from the row checkboxes.
	 *
	 * @param {import('@playwright/test').Page} page Page under test.
	 * @return {Promise<number[]>} Post IDs.
	 */
	async function rows( page ) {
		const values = await page
			.locator( '#the-list input[name="post[]"]' )
			.evaluateAll( ( boxes ) => boxes.map( ( box ) => box.value ) );

		return values.map( Number );
	}

	test( 'bulk-pins and filters to Pinned by keyboard', async ( { page } ) => {
		for ( const id of [ oca.id, mashua.id ] ) {
			const box = page.locator( `#cb-select-${ id }` );
			await box.focus();
			await page.keyboard.press( 'Space' );
			await expect( box ).toBeChecked();
		}

		const menu = page.locator( '#bulk-action-selector-top' );
		await menu.focus();
		await menu.selectOption( 'pin' );
		await page.keyboard.press( 'Tab' );
		await expect( page.locator( '#doaction' ) ).toBeFocused();
		await page.keyboard.press( 'Enter' );

		const notice = page.locator( '#undergrowth-notice' );
		await expect( notice ).toBeFocused();
		await expect( notice ).toHaveClass( /notice-success/ );
		await expect( page ).not.toHaveURL( /undergrowth_pinned/ );
		await expect( page ).toHaveURL( /tab=stages/ );
		await expect(
			page.locator( '.column-pinned .undergrowth-pinned' )
		).toHaveCount( 2 );
		await expectNoA11yViolations( page, '#undergrowth-notice' );

		const pinned = page.locator( '.subsubsub .pinned a' );
		await expect( pinned.locator( '.count' ) ).toContainText( '2' );
		await pinned.focus();
		await page.keyboard.press( 'Enter' );

		await expect( pinned ).toHaveAttribute( 'aria-current', 'page' );
		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 2 );
		await expect
			.poll( async () => ( await rows( page ) ).sort() )
			.toEqual( [ oca.id, mashua.id ].sort() );
		await expectNoA11yViolations( page, '.wrap' );

		const unpinned = page.locator( '.subsubsub .unpinned a' );
		await unpinned.focus();
		await page.keyboard.press( 'Enter' );

		await expect( unpinned ).toHaveAttribute( 'aria-current', 'page' );
		await expect.poll( () => rows( page ) ).toEqual( [ yacon.id ] );
	} );

	test( 'sorts by stage and by pin by keyboard', async ( { page } ) => {
		// Pin Yacon at Evergreen from its own row action.
		const select = page.locator( `#undergrowth-stage-${ yacon.id }` );
		await select.focus();
		await select.selectOption( 'evergreen' );
		await page.keyboard.press( 'Tab' );
		await page.keyboard.press( 'Enter' );
		await expect( page.locator( '#undergrowth-notice' ) ).toBeFocused();

		const stage = page.locator( '#stage a' );
		await stage.focus();
		await expect( stage ).toBeFocused();
		await page.keyboard.press( 'Enter' );

		await expect( page.locator( '#stage' ) ).toHaveAttribute(
			'aria-sort',
			'ascending'
		);
		await expect
			.poll( async () => ( await rows( page ) ).at( -1 ) )
			.toBe( yacon.id );

		await page.locator( '#stage a' ).focus();
		await page.keyboard.press( 'Enter' );

		await expect( page.locator( '#stage' ) ).toHaveAttribute(
			'aria-sort',
			'descending'
		);
		await expect
			.poll( async () => ( await rows( page ) )[ 0 ] )
			.toBe( yacon.id );
		await expectNoA11yViolations( page, '.wrap' );

		// Pinned sorts pinned posts first the first time.
		await page.locator( '#pinned a' ).focus();
		await page.keyboard.press( 'Enter' );

		await expect( page.locator( '#pinned' ) ).toHaveAttribute(
			'aria-sort',
			'descending'
		);
		await expect
			.poll( async () => ( await rows( page ) )[ 0 ] )
			.toBe( yacon.id );
	} );
} );
