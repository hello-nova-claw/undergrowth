const { describe, it, expect } = require( '@jest/globals' );
const fs = require( 'fs' );
const path = require( 'path' );

const root = path.resolve( __dirname, '../..' );
const pot = fs.readFileSync(
	path.join( root, 'languages/undergrowth.pot' ),
	'utf8'
);

/**
 * Returns the translators' notes written for one string in the POT.
 *
 * @param {string} msgid The string, as the POT spells it.
 * @return {string} Its notes, joined, or an empty string.
 */
function notesFor( msgid ) {
	const entry = pot
		.split( /\n\n/ )
		.find( ( block ) => block.includes( `\nmsgid "${ msgid }"` ) );

	return ( entry || '' )
		.split( '\n' )
		.filter( ( line ) => line.startsWith( '#.' ) )
		.join( ' ' );
}

describe( 'Translators notes', () => {
	// Each name read out for a button must start with the word on it, or a
	// voice-control user saying that word cannot press it (WCAG 2.5.3).
	it.each( [
		[ 'Accept the link from %1$s to %2$s', 'Accept' ],
		[ 'Veto the link from %1$s to %2$s', 'Veto' ],
		[ 'Choose words for the link from %1$s to %2$s', 'Choose words' ],
		[ 'Unpin the stage of %1$s', 'Unpin' ],
		[ 'Pin the stage of %1$s', 'Pin' ],
		[ 'Return to garden: &#8220;%1$s&#8221;', 'Return to garden' ],
		[ 'Compost &#8220;%s&#8221;', 'Compost' ],
	] )(
		'say that “%s” must begin with the words on its button',
		( msgid, button ) => {
			expect( notesFor( msgid ) ).toContain(
				`Must begin with the translation of “${ button }”`
			);
		}
	);

	it( 'give the date and time their own string', () => {
		expect( notesFor( '%1$s at %2$s' ) ).toContain( '1: date, 2: time.' );
	} );

	it( 'quote “with a gap” the way the Map heading does', () => {
		expect( pot ).toContain( 'A group marked “with a gap”' );
		expect( pot ).not.toContain( 'A group marked \\"with a gap\\"' );
	} );
} );

describe( 'Source', () => {
	// A Markdown table, such as the Map export, may still use the pipe.
	it( 'joins no actions with a bare separator', () => {
		const files = fs
			.readdirSync( path.join( root, 'src' ) )
			.filter( ( file ) => file.endsWith( '.php' ) );

		for ( const file of files ) {
			expect(
				fs.readFileSync( path.join( root, 'src', file ), 'utf8' )
			).not.toMatch( /\.\s*' \| '\s*\./ );
		}
	} );
} );
