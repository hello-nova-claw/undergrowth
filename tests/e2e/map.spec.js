const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { expectNoA11yViolations } = require( './a11y' );

/**
 * Publishes a post whose body repeats fixture words, so the e2e provider
 * gives it a known vector. Titles hold no fixture word.
 *
 * @param {Object} requestUtils Request utilities.
 * @param {string} title        Post title.
 * @param {Object} words        Number of times to repeat each fixture word.
 * @return {Promise<Object>} The post.
 */
async function publish( requestUtils, title, words ) {
	const content = Object.entries( words )
		.map( ( [ word, count ] ) => Array( count ).fill( word ).join( ' ' ) )
		.join( ' ' );

	return requestUtils.createPost( {
		title,
		content: `<p>${ content }</p>`,
		status: 'publish',
	} );
}

// Selectors use ids, classes and names, not text, because this spec also
// runs in Hebrew and in the en_XA pseudo-locale.
test.describe( 'Map tab', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'is not offered before any cluster is made', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'edit.php', 'page=undergrowth-links' );

		await expect( page.locator( '.nav-tab' ) ).toHaveCount( 2 );
		await expect( page.locator( '.nav-tab[href*="tab=map"]' ) ).toHaveCount(
			0
		);
	} );

	test.describe( 'with two clusters, one a gap', () => {
		let roots;

		test.beforeEach(
			async ( { requestUtils, admin, context, baseURL } ) => {
				// Three identical posts: the middle of their cluster is a post.
				for ( const title of [ 'First', 'Second', 'Third' ] ) {
					await publish( requestUtils, title, { yacon: 6 } );
				}

				// Three posts pulling apart: none sits at their middle.
				roots = await publish( requestUtils, 'Roots', { oca: 6 } );
				await publish( requestUtils, 'Sweet roots', {
					oca: 6,
					syrup: 3,
				} );
				await publish( requestUtils, 'Roots on paper', {
					oca: 6,
					ledger: 3,
				} );

				const pass = await requestUtils.request.get(
					'wp-admin/index.php?undergrowth_e2e_cluster=1'
				);
				expect( pass.ok() ).toBe( true );

				await context.addCookies( [
					{
						name: 'undergrowth_e2e_fixture',
						value: 'tight-gaps',
						url: baseURL,
					},
				] );
				await admin.visitAdminPage(
					'edit.php',
					'page=undergrowth-links&tab=map'
				);
			}
		);

		test( 'lists each cluster and marks the gap, with no WCAG 2.2 AA violations', async ( {
			page,
		} ) => {
			await expect( page.locator( '.nav-tab-active' ) ).toHaveAttribute(
				'href',
				/tab=map/
			);
			await expect( page.locator( '.wrap h2' ) ).toHaveCount( 2 );
			await expect(
				page.locator( 'ul.undergrowth-cluster' )
			).toHaveCount( 2 );
			await expect(
				page.locator( 'ul.undergrowth-cluster' ).first().locator( 'li' )
			).toHaveCount( 3 );
			await expect(
				page.locator( 'ul.undergrowth-cluster' ).last().locator( 'li' )
			).toHaveCount( 3 );

			// The gap is said right under its own cluster's heading, and only there.
			await expect( page.locator( '.undergrowth-gap' ) ).toHaveCount( 1 );
			await expect(
				page.locator(
					`#undergrowth-cluster-${ roots.id } + p.undergrowth-gap`
				)
			).toHaveCount( 1 );
			await expect(
				page.locator(
					`ul[aria-labelledby="undergrowth-cluster-${ roots.id }"] li a`
				)
			).toHaveCount( 3 );
			await expect(
				page
					.locator(
						`ul[aria-labelledby="undergrowth-cluster-${ roots.id }"] li a`
					)
					.first()
			).toHaveAttribute( 'href', new RegExp( `post=${ roots.id }&` ) );

			await expectNoA11yViolations( page, '.wrap' );
		} );

		test( 'fits a narrow screen', async ( { page } ) => {
			await page.setViewportSize( { width: 390, height: 800 } );

			const overflow = await page
				.locator( '.wrap' )
				.evaluate( ( wrap ) => wrap.scrollWidth - wrap.clientWidth );

			expect( overflow ).toBeLessThanOrEqual( 0 );
			await expectNoA11yViolations( page, '.wrap' );
		} );

		test( 'is read by keyboard from the tab to each post', async ( {
			page,
		} ) => {
			const tab = page.locator( '.nav-tab-active' );

			await tab.focus();
			await expect( tab ).toBeFocused();

			await page.keyboard.press( 'Tab' );
			await expect(
				page.locator( 'ul.undergrowth-cluster li a' ).first()
			).toBeFocused();

			// Every post in both clusters is reached in order, nothing else between.
			for ( let i = 1; i < 6; i++ ) {
				await page.keyboard.press( 'Tab' );
				await expect(
					page.locator( 'ul.undergrowth-cluster li a' ).nth( i )
				).toBeFocused();
			}

			await page.keyboard.press( 'Enter' );
			await expect( page ).toHaveURL( /post\.php\?post=\d+&action=edit/ );
		} );
	} );
} );
