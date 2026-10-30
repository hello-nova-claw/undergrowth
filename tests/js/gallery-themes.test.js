const { describe, it, expect } = require( '@jest/globals' );
const { readFileSync } = require( 'fs' );
const { join } = require( 'path' );

const { galleryThemes, themesFrom } = require( '../../bin/gallery-themes' );

const blueprint = join( __dirname, '../e2e/playground/gallery.json' );

describe( 'galleryThemes', () => {
	it( 'lists every theme the gallery blueprint installs, in its order', () => {
		const themes = galleryThemes();

		expect( themes[ 0 ] ).toBe( 'twentytwentyfive' );
		expect( themes.length ).toBeGreaterThan( 1 );
		expect( new Set( themes ).size ).toBe( themes.length );
		expect( themes.every( ( slug ) => /^[a-z0-9-]+$/.test( slug ) ) ).toBe(
			true
		);
	} );

	it( 'reads the list the blueprint writes for its install step', () => {
		const { steps } = JSON.parse( readFileSync( blueprint, 'utf8' ) );
		const list = steps.find( ( step ) => 'writeFile' === step.step );

		expect( steps.some( ( step ) => 'installTheme' === step.step ) ).toBe(
			false
		);
		expect( themesFrom( list.data ) ).toEqual( galleryThemes() );
	} );

	it( 'is served by the test site for every theme', () => {
		// The cookie's allow-list is PHP, so the slugs are listed there too.
		const php = readFileSync(
			join( __dirname, '../e2e/mu-plugins/undergrowth-e2e.php' ),
			'utf8'
		);
		const [ , allowed ] = php.match(
			/'undergrowth_e2e_theme',\s*array\(([^)]*)\)/
		);

		for ( const slug of galleryThemes() ) {
			expect( allowed ).toContain( `'${ slug }'` );
		}
	} );

	it( 'ignores blank lines and spaces around a slug', () => {
		expect( themesFrom( ' ollie \n\nfrost\n' ) ).toEqual( [
			'ollie',
			'frost',
		] );
	} );
} );
