const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

// Selectors use ids, classes and names, not text, because this spec also
// runs in Hebrew and in the en_XA pseudo-locale.
test.describe( 'Orphans on Posts > Undergrowth', () => {
	let oca;
	let yacon;

	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'has no WCAG 2.2 AA violations with no posts', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'edit.php',
			'page=undergrowth-links&tab=orphans'
		);

		await expect(
			page.locator( 'table.undergrowth-orphans .no-items' )
		).toHaveCount( 1 );
		await expectNoA11yViolations( page, '.wrap' );
	} );

	test.describe( 'with posts', () => {
		test.beforeEach( async ( { requestUtils, admin } ) => {
			oca = await requestUtils.createPost( {
				title: 'Oca',
				content: '<p>Oca grows here. I keep yacon nearby.</p>',
				status: 'publish',
			} );
			yacon = await requestUtils.createPost( {
				title: 'Yacon',
				content: '<p>Yacon syrup is sweet.</p>',
				status: 'publish',
			} );
			await admin.visitAdminPage(
				'edit.php',
				'page=undergrowth-links&tab=orphans'
			);
		} );

		test( 'lists each orphan with its suggestions and no WCAG 2.2 AA violations', async ( {
			page,
		} ) => {
			const rows = page.locator( 'table.undergrowth-orphans tbody tr' );

			await expect( rows ).toHaveCount( 2 );
			await expect(
				page.locator(
					`[form="undergrowth-accept-${ oca.id }-${ yacon.id }"]`
				)
			).toHaveCount( 1 );
			await expectNoA11yViolations( page, '.wrap' );
		} );

		test( 'accepts a link in by keyboard, and the post leaves the list', async ( {
			page,
		} ) => {
			const rows = page.locator( 'table.undergrowth-orphans tbody tr' );
			const accept = page.locator(
				`[form="undergrowth-accept-${ oca.id }-${ yacon.id }"]`
			);

			await accept.focus();
			await expect( accept ).toBeFocused();
			await page.keyboard.press( 'Enter' );

			const notice = page.locator( '#undergrowth-notice' );
			await expect( notice ).toBeFocused();
			await expect( notice ).toHaveClass( /notice-success/ );
			await expect( page ).toHaveURL( /tab=orphans/ );
			await expect( rows ).toHaveCount( 1 );
			await expect(
				page.locator( `#undergrowth-accept-${ oca.id }-${ yacon.id }` )
			).toHaveCount( 0 );
			await expectNoA11yViolations( page, '.wrap' );
		} );

		test( 'vetoes a link in by keyboard and stays on the tab', async ( {
			page,
		} ) => {
			const veto = page.locator(
				`[form="undergrowth-veto-${ yacon.id }-${ oca.id }"]`
			);

			await veto.focus();
			await page.keyboard.press( 'Enter' );

			await expect( page.locator( '#undergrowth-notice' ) ).toBeFocused();
			await expect( page ).toHaveURL( /tab=orphans/ );
			await expect( veto ).toHaveCount( 0 );
			await expect(
				page.locator( 'table.undergrowth-orphans tbody tr' )
			).toHaveCount( 2 );
		} );
	} );
} );
