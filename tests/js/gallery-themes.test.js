const { describe, it, expect } = require( '@jest/globals' );
const { readFileSync } = require( 'fs' );
const { join } = require( 'path' );

const { galleryThemes, themesFrom } = require( '../../bin/gallery-themes' );

const blueprint = join( __dirname, '../e2e/playground/gallery.json' );

describe( 'galleryThemes', () => {
	it( 'lists every theme the gallery blueprint installs, in its order', () => {
		const themes = galleryThemes();

		expect( themes[ 0 ] ).toBe( 'twentytwentyfive' );
		expect( themes ).toHaveLength( 10 );
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

	it( 'ignores blank lines and spaces around a slug', () => {
		expect( themesFrom( ' ollie \n\nfrost\n' ) ).toEqual( [
			'ollie',
			'frost',
		] );
	} );
} );
