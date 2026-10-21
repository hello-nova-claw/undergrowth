/**
 * Keeps a regenerated POT file from changing unless a string did.
 *
 * With line numbers, any change that moves a line shifts references all
 * through the POT, and the creation date changes on every run, so open pull
 * requests conflict there even when no string changed. File names alone
 * still tell translators where a string is used.
 *
 * Usage: node bin/stable-pot.js languages/undergrowth.pot
 */

const { readFileSync, writeFileSync } = require( 'fs' );

/**
 * Rewrites each block of #: lines as one file per line, each file once.
 *
 * @param {string} pot POT contents.
 * @return {string} POT contents without line numbers.
 */
function stripLineNumbers( pot ) {
	const out = [];
	let seen = null;

	for ( const line of pot.split( '\n' ) ) {
		if ( ! line.startsWith( '#: ' ) ) {
			seen = null;
			out.push( line );
			continue;
		}

		seen = seen || new Set();

		for ( const reference of line.slice( 3 ).trim().split( /\s+/ ) ) {
			const file = reference.replace( /:\d+$/, '' );

			if ( ! seen.has( file ) ) {
				seen.add( file );
				out.push( `#: ${ file }` );
			}
		}
	}

	return out.join( '\n' );
}

/**
 * Removes the POT-Creation-Date header, which gettext treats as optional.
 *
 * @param {string} pot POT contents.
 * @return {string} POT contents without the creation date.
 */
function dropCreationDate( pot ) {
	return pot.replace( /^"POT-Creation-Date: [^"]*"\n/m, '' );
}

module.exports = { stripLineNumbers, dropCreationDate };

if ( require.main === module ) {
	const file = process.argv[ 2 ];
	writeFileSync(
		file,
		dropCreationDate( stripLineNumbers( readFileSync( file, 'utf8' ) ) )
	);
}
