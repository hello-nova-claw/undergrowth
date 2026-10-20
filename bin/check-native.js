/**
 * Fails when the plugin reaches for something WordPress already provides.
 *
 * Checks what PHPCS, ESLint and stylelint cannot: inline style attributes in
 * src/, hard-coded colours in CSS under src/ and assets/, PHP mocking libraries, and npm packages
 * that are neither `@wordpress/*` nor on the allowlist below.
 * See "Use what WordPress already has" in CONTRIBUTING.md.
 *
 * Usage: node bin/check-native.js [root]
 */

const { existsSync, readdirSync, readFileSync } = require( 'fs' );
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
 * Returns the one-based line an offset falls on.
 *
 * @param {string} text   File contents.
 * @param {number} offset Character offset.
 * @return {number} Line number.
 */
function lineAt( text, offset ) {
	return text.slice( 0, offset ).split( '\n' ).length;
}

/**
 * Returns the offset of the quote that closes a string in a JSX expression.
 *
 * @param {string} markup File contents.
 * @param {number} start  Offset of the opening quote.
 * @return {number} Offset of the closing quote, or the end of the text.
 */
function stringEnd( markup, start ) {
	for ( let pos = start + 1; pos < markup.length; pos++ ) {
		if ( markup[ pos ] === '\\' ) {
			pos++;
		} else if ( markup[ pos ] === markup[ start ] ) {
			return pos;
		}
	}

	return markup.length;
}

/**
 * Returns where an attribute value ends.
 *
 * Reads a quoted value (also with PHP-escaped quotes), a JSX expression with
 * nested braces, or an unquoted value.
 *
 * @param {string} markup File contents.
 * @param {number} start  Offset of the value's first character.
 * @return {number} Offset just past the value.
 */
function valueEnd( markup, start ) {
	const quote = /^\\?["']/.exec( markup.slice( start, start + 2 ) );

	if ( quote ) {
		const close = markup.indexOf( quote[ 0 ], start + quote[ 0 ].length );

		return close === -1 ? markup.length : close + quote[ 0 ].length;
	}

	if ( markup[ start ] === '{' ) {
		let depth = 0;

		for ( let pos = start; pos < markup.length; pos++ ) {
			if ( '"\'`'.includes( markup[ pos ] ) ) {
				pos = stringEnd( markup, pos );
				continue;
			}

			depth += { '{': 1, '}': -1 }[ markup[ pos ] ] || 0;

			if ( depth === 0 ) {
				return pos + 1;
			}
		}

		return markup.length;
	}

	return start + /^[^\s>]*/.exec( markup.slice( start ) )[ 0 ].length;
}

/**
 * Finds style attributes in PHP, HTML or JSX opening tags.
 *
 * Each tag is read attribute by attribute, so a variable named style, or the
 * word style inside another attribute's value, does not count, and a > inside
 * a quoted value does not end the tag. Tags may span lines.
 *
 * @param {string} markup File contents.
 * @return {number[]} One-based line numbers of each style attribute.
 */
function findInlineStyles( markup ) {
	const found = [];
	// Embedded PHP, a JSX spread such as { ...props }, or a name with an
	// optional =.
	const attribute = /\s*(?:(<\?)|(\{)|([^\s=<>/"'{}]+)\s*(=\s*)?)/y;

	for ( const tag of markup.matchAll( /<[a-z][\w.:-]*/gi ) ) {
		attribute.lastIndex = tag.index + tag[ 0 ].length;

		for (
			let match = attribute.exec( markup );
			match;
			match = attribute.exec( markup )
		) {
			const [ whole, php, spread, name, equals ] = match;

			if ( php ) {
				const close = markup.indexOf( '?>', attribute.lastIndex );
				attribute.lastIndex = close === -1 ? markup.length : close + 2;
				continue;
			}

			if ( spread ) {
				attribute.lastIndex = valueEnd(
					markup,
					match.index + whole.length - 1
				);
				continue;
			}

			if ( name.toLowerCase() === 'style' && equals ) {
				found.push(
					lineAt( markup, match.index + whole.indexOf( name ) )
				);
			}

			if ( equals ) {
				attribute.lastIndex = valueEnd( markup, attribute.lastIndex );
			}
		}
	}

	return found;
}

/**
 * Finds hex colours in declaration values, leaving id selectors and comments
 * alone.
 *
 * @param {string} css File contents.
 * @return {number[]} One-based line numbers.
 */
function findHexColours( css ) {
	const colour =
		/:[^;{}]*?(#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8}))\b(?![^;{}]*\{)/gi;

	// Blank out comments but keep their newlines, so line numbers hold.
	const code = css.replace( /\/\*[\s\S]*?\*\//g, ( comment ) =>
		comment.replace( /[^\n]/g, ' ' )
	);

	return Array.from( code.matchAll( colour ), ( match ) =>
		lineAt( code, match.index + match[ 0 ].indexOf( match[ 1 ] ) )
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

	const css = existsSync( join( root, 'assets' ) )
		? walk( join( root, 'assets' ) ).filter( ( path ) =>
				/\.s?css$/.test( path )
			)
		: [];

	for ( const path of [ ...css, ...walk( join( root, 'src' ) ) ] ) {
		const text = readFileSync( path, 'utf8' );

		if ( /\.(?:php|html?|jsx?|tsx?)$/.test( path ) ) {
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
