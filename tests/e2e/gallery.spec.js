/**
 * Theme gallery: the Garden page and one demo post in each theme, at two
 * widths, as readers see them.
 *
 * Run with `npm run gallery`. Not part of the normal e2e run: it asserts
 * nothing about the pages, it only records them for a design review.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { test } = require( '@wordpress/e2e-test-utils-playwright' );
const { galleryThemes } = require( '../../bin/gallery-themes.cjs' );

const OUTPUT = path.resolve(
	process.env.UNDERGROWTH_GALLERY_DIR || 'artifacts/gallery'
);
const WIDTHS = [ 1280, 390 ];
const SKIPPED = path.join( OUTPUT, 'skipped.json' );

// Filled in once the blueprint's page and post are found, since the shots
// need their IDs.
const addresses = {};

/**
 * Adds a theme to the list the contact sheet reads.
 *
 * @param {string} theme Theme slug.
 */
function recordSkipped( theme ) {
	const skipped = fs.existsSync( SKIPPED )
		? JSON.parse( fs.readFileSync( SKIPPED, 'utf8' ) )
		: [];

	if ( ! skipped.includes( theme ) ) {
		skipped.push( theme );
	}

	fs.writeFileSync( SKIPPED, JSON.stringify( skipped ) + '\n' );
}

test.describe( 'Theme gallery', () => {
	// Readers are not logged in, so the shots have no admin bar.
	test.use( { storageState: { cookies: [], origins: [] } } );

	test.beforeAll( async ( { requestUtils } ) => {
		// Playground answers requests before its blueprint has finished, so
		// the page its last step makes is waited for, up to ten minutes.
		let garden;
		let post;

		for ( let tries = 0; tries < 120 && ! garden; tries++ ) {
			[ garden ] = await requestUtils.rest( {
				path: '/wp/v2/pages',
				params: { slug: 'garden' },
			} );

			if ( ! garden ) {
				await new Promise( ( resolve ) => setTimeout( resolve, 5000 ) );
			}
		}

		if ( garden ) {
			[ post ] = await requestUtils.rest( {
				path: '/wp/v2/posts',
				params: { slug: 'undergrowth-gallery' },
			} );
		}

		if ( ! garden || ! post ) {
			throw new Error(
				`The gallery blueprint did not make the ${
					garden ? 'gallery post' : 'Garden page'
				}. Playground's output above says which step failed.`
			);
		}

		addresses.page = `/?page_id=${ garden.id }`;
		addresses.post = `/?p=${ post.id }`;

		// A list left by an earlier run would name themes this run has.
		fs.mkdirSync( OUTPUT, { recursive: true } );
		fs.rmSync( SKIPPED, { force: true } );
	} );

	for ( const theme of galleryThemes() ) {
		test( theme, async ( { page, context, baseURL } ) => {
			await context.addCookies( [
				{ name: 'undergrowth_e2e_theme', value: theme, url: baseURL },
			] );

			for ( const [ kind, address ] of Object.entries( addresses ) ) {
				await page.goto( address );

				// The test plugin serves the default theme when the one asked
				// for is not installed, and core names the theme on the body.
				if (
					( await page
						.locator( `body.wp-theme-${ theme }` )
						.count() ) === 0
				) {
					recordSkipped( theme );
					test.skip( true, `${ theme } is not installed.` );
				}

				for ( const width of WIDTHS ) {
					await page.setViewportSize( { width, height: 800 } );
					await page.screenshot( {
						path: path.join(
							OUTPUT,
							`gallery-${ theme }--${ kind }--${ width }.png`
						),
						fullPage: true,
						animations: 'disabled',
						caret: 'hide',
						// "Tended 4 seconds ago" moves with the time each
						// shot is taken.
						mask: [
							page.locator(
								'.wp-block-undergrowth-garden-feed time'
							),
						],
						maskColor: '#c3c4c7',
					} );
				}
			}
		} );
	}
} );
