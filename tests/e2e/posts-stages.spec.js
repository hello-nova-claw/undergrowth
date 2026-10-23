const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

// Selectors use ids, classes, names and values, not text, because this spec
// also runs in Hebrew and in the en_XA pseudo-locale.
test.describe( 'Growth stages on the Posts list', () => {
	let oca;
	let yacon;
	let mashua;

	test.beforeEach( async ( { requestUtils } ) => {
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
	} );

	/**
	 * Ticks posts and applies a bulk action, all by keyboard.
	 *
	 * @param {import('@playwright/test').Page} page   Page under test.
	 * @param {number[]}                        ids    Posts to tick.
	 * @param {string}                          action Bulk action value.
	 */
	async function bulk( page, ids, action ) {
		for ( const id of ids ) {
			const box = page.locator( `#cb-select-${ id }` );
			await box.focus();
			await page.keyboard.press( 'Space' );
			await expect( box ).toBeChecked();
		}

		const menu = page.locator( '#bulk-action-selector-top' );
		await menu.focus();
		await menu.selectOption( action );
		await page.keyboard.press( 'Tab' );
		await expect( page.locator( '#doaction' ) ).toBeFocused();
		await page.keyboard.press( 'Enter' );

		await expect( page.locator( '#undergrowth-notice' ) ).toBeFocused();
	}

	/**
	 * Lists the post IDs in the table, in order.
	 *
	 * @param {import('@playwright/test').Page} page Page under test.
	 * @return {Promise<number[]>} Post IDs.
	 */
	async function rows( page ) {
		const ids = await page
			.locator( '#the-list tr[id^="post-"]' )
			.evaluateAll( ( trs ) => trs.map( ( tr ) => tr.id ) );

		return ids.map( ( id ) => Number( id.replace( 'post-', '' ) ) );
	}

	test( 'filters by a stage by keyboard', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'edit.php' );
		await bulk( page, [ oca.id ], 'undergrowth_pin' );
		await expect(
			page.locator( `#post-${ oca.id } .undergrowth-pinned` )
		).toHaveCount( 1 );

		const filter = page.locator( '#undergrowth-stage-filter' );
		await expect(
			page.locator( 'label[for="undergrowth-stage-filter"]' )
		).toHaveCount( 1 );
		await expectNoA11yViolations( page, '#undergrowth-stage-filter' );

		await filter.focus();
		await filter.selectOption( 'pinned' );
		await page.keyboard.press( 'Tab' );
		await expect( page.locator( '#post-query-submit' ) ).toBeFocused();
		await page.keyboard.press( 'Enter' );

		await expect( page ).toHaveURL( /undergrowth_stage=pinned/ );
		await expect( filter ).toHaveValue( 'pinned' );
		await expect.poll( () => rows( page ) ).toEqual( [ oca.id ] );

		await filter.focus();
		await filter.selectOption( 'sprout' );
		await page.keyboard.press( 'Tab' );
		await page.keyboard.press( 'Enter' );

		await expect( page ).toHaveURL( /undergrowth_stage=sprout/ );
		await expect( page.locator( '#the-list .no-items' ) ).toHaveCount( 1 );

		await filter.focus();
		await filter.selectOption( 'seedling' );
		await page.keyboard.press( 'Tab' );
		await page.keyboard.press( 'Enter' );

		await expect( page ).toHaveURL( /undergrowth_stage=seedling/ );
		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 3 );
		await expectNoA11yViolations( page, '#undergrowth-stage-filter' );
	} );

	test( 'sorts by stage by keyboard', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'edit.php' );
		await bulk( page, [ oca.id ], 'undergrowth_compost' );

		const sort = page.locator( '#undergrowth_stage a' );
		await sort.focus();
		await expect( sort ).toBeFocused();
		await page.keyboard.press( 'Enter' );

		await expect( page ).toHaveURL( /orderby=undergrowth_stage/ );
		await expect( page.locator( '#undergrowth_stage' ) ).toHaveAttribute(
			'aria-sort',
			'ascending'
		);
		// Seedlings first, Composting after Evergreen.
		await expect
			.poll( async () => ( await rows( page ) ).at( -1 ) )
			.toBe( oca.id );

		await page.locator( '#undergrowth_stage a' ).focus();
		await page.keyboard.press( 'Enter' );

		await expect( page.locator( '#undergrowth_stage' ) ).toHaveAttribute(
			'aria-sort',
			'descending'
		);
		await expect
			.poll( async () => ( await rows( page ) )[ 0 ] )
			.toBe( oca.id );
		await expectNoA11yViolations( page, '#undergrowth_stage' );
	} );

	test( 'composts two posts with the bulk action by keyboard', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'edit.php' );
		await bulk( page, [ oca.id, yacon.id ], 'undergrowth_compost' );

		const notice = page.locator( '#undergrowth-notice' );
		await expect( notice ).toHaveClass( /notice-success/ );
		await expect( page ).not.toHaveURL( /undergrowth_composted/ );
		await expectNoA11yViolations( page, '#undergrowth-notice' );

		for ( const id of [ oca.id, yacon.id ] ) {
			await expect(
				page.locator( `#post-${ id } .post-state` )
			).toHaveCount( 1 );
		}
		await expect(
			page.locator( `#post-${ mashua.id } .post-state` )
		).toHaveCount( 0 );

		const view = page.locator( '.subsubsub .undergrowth_composting a' );
		await expect( view.locator( '.count' ) ).toContainText( '2' );
		await view.focus();
		await page.keyboard.press( 'Enter' );

		await expect( view ).toHaveAttribute( 'aria-current', 'page' );
		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 2 );
		for ( const id of [ oca.id, yacon.id ] ) {
			await expect( page.locator( `#post-${ id }` ) ).toHaveCount( 1 );
		}
		// The view takes the place of the stage dropdown.
		await expect( page.locator( '#undergrowth-stage-filter' ) ).toHaveCount(
			0
		);
	} );
} );
