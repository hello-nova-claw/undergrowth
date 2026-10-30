/**
 * Reads the list of themes the theme gallery shoots.
 *
 * The list lives in tests/e2e/playground/gallery.json, in the file its
 * install step reads, so adding a theme is one edit there. The gallery spec
 * and the contact sheet both take the list from here.
 */

const { readFileSync } = require( 'fs' );
const { join } = require( 'path' );

const BLUEPRINT = join( __dirname, '../tests/e2e/playground/gallery.json' );
const THEMES_FILE = '/wordpress/wp-content/undergrowth-gallery-themes.txt';

/**
 * Splits the list the blueprint writes, one slug a line.
 *
 * @param {string} data Contents of the themes file.
 * @return {string[]} Theme slugs, in the blueprint's order.
 */
function themesFrom( data ) {
	return data
		.split( '\n' )
		.map( ( line ) => line.trim() )
		.filter( Boolean );
}

/**
 * Lists the gallery's themes, in the blueprint's order.
 *
 * @return {string[]} Theme slugs.
 */
function galleryThemes() {
	const { steps } = JSON.parse( readFileSync( BLUEPRINT, 'utf8' ) );
	const step = steps.find(
		( candidate ) =>
			'writeFile' === candidate.step && THEMES_FILE === candidate.path
	);

	if ( ! step ) {
		throw new Error(
			`${ BLUEPRINT } has no writeFile step for ${ THEMES_FILE }.`
		);
	}

	return themesFrom( step.data );
}

module.exports = { galleryThemes, themesFrom };
