/**
 * Folds changelog fragments into readme.txt.
 *
 * Each pull request adds its changelog line as changelog/<issue>-<slug>.txt
 * instead of editing readme.txt, so open pull requests never conflict over
 * the same spot in the changelog. At release time this script appends every
 * fragment under = Unreleased =, in issue number order, and deletes them.
 *
 * Usage: npm run changelog
 */

const {
	readdirSync,
	readFileSync,
	writeFileSync,
	unlinkSync,
} = require( 'fs' );
const { join } = require( 'path' );

const UNRELEASED = '= Unreleased =';

/**
 * Splits a fragment into changelog entries, one per non-empty line.
 *
 * @param {string} text Fragment contents.
 * @return {string[]} Entries without their bullet.
 */
function parseFragment( text ) {
	return text
		.split( /\r?\n/ )
		.map( ( line ) => line.trim().replace( /^\*\s*/, '' ) )
		.filter( Boolean );
}

/**
 * Lists the fragments in a directory, ordered by issue number, then name.
 *
 * @param {string} dir The changelog directory.
 * @return {{name: string, entries: string[]}[]} Fragments.
 */
function readFragments( dir ) {
	const issue = ( name ) => parseInt( name, 10 ) || Infinity;

	return readdirSync( dir )
		.filter( ( name ) => name.endsWith( '.txt' ) )
		.sort( ( a, b ) => issue( a ) - issue( b ) || a.localeCompare( b ) )
		.map( ( name ) => ( {
			name,
			entries: parseFragment( readFileSync( join( dir, name ), 'utf8' ) ),
		} ) );
}

/**
 * Appends entries to the end of the Unreleased section of a readme.
 *
 * @param {string}   readme  The readme.txt contents.
 * @param {string[]} entries Entries to add.
 * @return {string} The new readme.txt contents.
 */
function foldInto( readme, entries ) {
	if ( ! entries.length ) {
		return readme;
	}

	const lines = readme.split( '\n' );
	const start = lines.findIndex( ( line ) => line.trim() === UNRELEASED );

	if ( start === -1 ) {
		throw new Error( `readme.txt has no "${ UNRELEASED }" section.` );
	}

	let at = start + 1;
	for ( let i = start + 1; i < lines.length; i++ ) {
		if ( /^=.*=$/.test( lines[ i ].trim() ) ) {
			break;
		}
		if ( lines[ i ].startsWith( '*' ) ) {
			at = i + 1;
		}
	}

	lines.splice( at, 0, ...entries.map( ( entry ) => `* ${ entry }` ) );

	return lines.join( '\n' );
}

/**
 * Folds every fragment under root/changelog into root/readme.txt.
 *
 * @param {string} root Plugin root.
 * @return {number} How many fragments were folded.
 */
function fold( root ) {
	const dir = join( root, 'changelog' );
	const fragments = readFragments( dir );

	if ( ! fragments.length ) {
		return 0;
	}

	const readme = join( root, 'readme.txt' );

	writeFileSync(
		readme,
		foldInto(
			readFileSync( readme, 'utf8' ),
			fragments.flatMap( ( fragment ) => fragment.entries )
		)
	);

	for ( const { name } of fragments ) {
		unlinkSync( join( dir, name ) );
	}

	return fragments.length;
}

module.exports = { parseFragment, readFragments, foldInto, fold };

if ( require.main === module ) {
	const count = fold( process.argv[ 2 ] || process.cwd() );
	process.stdout.write(
		`Folded ${ count } changelog fragment${
			count === 1 ? '' : 's'
		} into readme.txt.\n`
	);
}
