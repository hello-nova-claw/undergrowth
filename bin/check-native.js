/**
 * Fails when the plugin reaches for something WordPress already provides.
 *
 * Checks what PHPCS, ESLint and stylelint cannot: inline style attributes in
 * PHP, hard-coded colours in CSS, PHP mocking libraries, and npm packages
 * that are neither `@wordpress/*` nor on the allowlist below.
 * See "Use what WordPress already has" in CONTRIBUTING.md.
 *
 * Usage: node bin/check-native.js [root]
 */

const { readdirSync, readFileSync } = require( 'fs' );
const { join, relative, sep } = require( 'path' );

// Tooling with no WordPress equivalent. Adding to this list is a review
// decision, so it happens in a pull request, not here by habit.
const ALLOWED_PACKAGES = [
	'@axe-core/playwright',
	'@jest/globals',
	'@playwright/test',
	'@wp-playground/cli',
	'jest',
	'pa11y-ci',
	'pixelmatch',
	'pngjs',
];

const MOCKING_LIBRARIES = [
	'mockery/mockery',
	'10up/wp_mock',
	'brain/monkey',
	'php-mock/php-mock',
];

const DEPENDENCY_LISTS = [
	'dependencies',
	'devDependencies',
	'peerDependencies',
	'optionalDependencies',
];

/**
 * Returns the line numbers that match a pattern.
 *
 * @param {string} text    File contents.
 * @param {RegExp} pattern Pattern to test each line with.
 * @return {number[]} One-based line numbers.
 */
function matchingLines( text, pattern ) {
	return text
		.split( '\n' )
		.map( ( line, index ) => ( pattern.test( line ) ? index + 1 : 0 ) )
		.filter( Boolean );
}

/**
 * Finds style attributes in PHP or HTML, quoted or escaped.
 *
 * @param {string} php File contents.
 * @return {number[]} One-based line numbers.
 */
function findInlineStyles( php ) {
	return matchingLines( php, /(?<![\w$-])style\s*=\s*\\?["']/i );
}

/**
 * Finds hex colours in declaration values, leaving id selectors alone.
 *
 * @param {string} css File contents.
 * @return {number[]} One-based line numbers.
 */
function findHexColours( css ) {
	return matchingLines(
		css,
		/:[^;{}]*#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})\b/i
	);
}

/**
 * Finds PHP mocking libraries in composer.json.
 *
 * @param {Object} composer Parsed composer.json.
 * @return {string[]} Package names.
 */
function findMockingLibraries( composer ) {
	return [ 'require', 'require-dev' ]
		.flatMap( ( list ) => Object.keys( composer[ list ] || {} ) )
		.filter( ( name ) => MOCKING_LIBRARIES.includes( name ) );
}

/**
 * Finds npm packages that are neither `@wordpress/*` nor allowlisted.
 *
 * @param {Object} pkg Parsed package.json.
 * @return {string[]} Package names.
 */
function findFrameworks( pkg ) {
	return DEPENDENCY_LISTS.flatMap( ( list ) =>
		Object.keys( pkg[ list ] || {} )
	).filter(
		( name ) =>
			! name.startsWith( '@wordpress/' ) &&
			! ALLOWED_PACKAGES.includes( name )
	);
}

/**
 * Lists every file under a directory, sorted.
 *
 * @param {string} dir Directory.
 * @return {string[]} Absolute paths.
 */
function walk( dir ) {
	return readdirSync( dir, { withFileTypes: true } )
		.flatMap( ( entry ) =>
			entry.isDirectory()
				? walk( join( dir, entry.name ) )
				: [ join( dir, entry.name ) ]
		)
		.sort();
}

/**
 * Returns the line a JSON key first appears on, or 1.
 *
 * @param {string} json JSON text.
 * @param {string} key  Key to look for.
 * @return {number} One-based line number.
 */
function keyLine( json, key ) {
	return matchingLines( json, new RegExp( `"${ key }"\\s*:` ) )[ 0 ] || 1;
}

/**
 * Runs every check against a plugin checkout.
 *
 * @param {string} root Plugin root.
 * @return {{file: string, line: number, message: string}[]} Breaches.
 */
function check( root ) {
	const breaches = [];
	const name = ( path ) => relative( root, path ).split( sep ).join( '/' );

	for ( const path of walk( join( root, 'src' ) ) ) {
		const text = readFileSync( path, 'utf8' );

		if ( path.endsWith( '.php' ) ) {
			for ( const line of findInlineStyles( text ) ) {
				breaches.push( {
					file: name( path ),
					line,
					message:
						'Inline style attribute. Use core admin classes, or an enqueued stylesheet.',
				} );
			}
		}

		if ( /\.s?css$/.test( path ) ) {
			for ( const line of findHexColours( text ) ) {
				breaches.push( {
					file: name( path ),
					line,
					message:
						'Hard-coded colour. Use an admin colour scheme custom property such as --wp-admin-theme-color.',
				} );
			}
		}
	}

	const composer = readFileSync( join( root, 'composer.json' ), 'utf8' );
	for ( const pkg of findMockingLibraries( JSON.parse( composer ) ) ) {
		breaches.push( {
			file: 'composer.json',
			line: keyLine( composer, pkg ),
			message: `${ pkg } mocks WordPress. Use WP_UnitTestCase, factories, core filters and pre_http_request.`,
		} );
	}

	const npm = readFileSync( join( root, 'package.json' ), 'utf8' );
	for ( const pkg of findFrameworks( JSON.parse( npm ) ) ) {
		breaches.push( {
			file: 'package.json',
			line: keyLine( npm, pkg ),
			message: `${ pkg } is not a @wordpress package. Use the WordPress equivalent, or add it to ALLOWED_PACKAGES in bin/check-native.js with a reason in the pull request.`,
		} );
	}

	return breaches;
}

if ( require.main === module ) {
	const breaches = check( process.argv[ 2 ] || process.cwd() );

	for ( const { file, line, message } of breaches ) {
		process.stderr.write(
			process.env.GITHUB_ACTIONS
				? `::error file=${ file },line=${ line }::${ message }\n`
				: `${ file }:${ line }: ${ message }\n`
		);
	}

	process.exitCode = breaches.length ? 1 : 0;
}

module.exports = {
	findInlineStyles,
	findHexColours,
	findMockingLibraries,
	findFrameworks,
	check,
};
