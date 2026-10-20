const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

// Selectors use ids, classes and names, not text, because this spec also
// runs in Hebrew and in the en_XA pseudo-locale.
test.describe( 'Link suggestions screen', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	// A failed test must never leave suggestions switched off for the next.
	test.afterEach( async ( { admin, page } ) => {
		await admin.visitAdminPage( 'edit.php', 'page=undergrowth-links' );

		const toggle = page.locator(
			'input[name="undergrowth_suggest_links"]'
		);

		if ( ! ( await toggle.isChecked() ) ) {
			await toggle.check();
			await page.locator( '.wrap #submit' ).click();
		}
	} );

	test( 'has no WCAG 2.2 AA violations before anything is suggested', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'edit.php', 'page=undergrowth-links' );

		await expect( page.locator( '.wp-list-table' ) ).toHaveCount( 0 );
		await expectNoA11yViolations( page, '.wrap' );
	} );

	test.describe( 'with suggestions', () => {
		test.beforeEach( async ( { requestUtils, admin } ) => {
			await requestUtils.createPost( {
				title: 'Oca',
				content: '<p>Oca grows here. I keep yacon nearby.</p>',
				status: 'publish',
			} );
			await requestUtils.createPost( {
				title: 'Yacon',
				content: '<p>Yacon syrup is sweet.</p>',
				status: 'publish',
			} );
			await admin.visitAdminPage( 'edit.php', 'page=undergrowth-links' );
		} );

		test( 'lists them with no WCAG 2.2 AA violations', async ( {
			page,
		} ) => {
			await expect(
				page.locator( '.wp-list-table tbody tr' )
			).toHaveCount( 2 );
			await expect(
				page.locator( '.wp-list-table q' ).first()
			).toBeVisible();
			await expectNoA11yViolations( page, '.wrap' );
		} );

		test( 'vetoes one by keyboard and moves focus to the result', async ( {
			page,
		} ) => {
			const rows = page.locator( '.wp-list-table tbody tr' );
			const veto = rows.first().locator( 'button[type="submit"]' );

			await veto.focus();
			await expect( veto ).toBeFocused();
			await page.keyboard.press( 'Enter' );

			await expect( page.locator( '#undergrowth-notice' ) ).toBeFocused();
			await expect( rows ).toHaveCount( 1 );
		} );

		test( 'can be switched off and on', async ( { page } ) => {
			const toggle = page.locator(
				'input[name="undergrowth_suggest_links"]'
			);

			await toggle.uncheck();
			await page.locator( '.wrap #submit' ).click();

			await expect( page.locator( '#undergrowth-notice' ) ).toBeFocused();
			await expect( toggle ).not.toBeChecked();
			await expect( page.locator( '.wp-list-table' ) ).toHaveCount( 0 );
			await expectNoA11yViolations( page, '.wrap' );
		} );
	} );
} );
