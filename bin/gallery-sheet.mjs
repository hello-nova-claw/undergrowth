/**
 * Writes the theme gallery's contact sheet.
 *
 * Prints Markdown to stdout: one row per theme in the gallery blueprint, with
 * the Garden page and the demo post at each width. Without a base URL each
 * cell gives the shot's size, for the job summary, where the images are only
 * in the artifact. With one, each cell shows the image, for the pull request
 * comment. Always exits 0 when the folder can be read, because the gallery
 * is for looking at, never a check.
 *
 * Usage:
 *   node bin/gallery-sheet.mjs <folder> [image base URL]
 *
 * The folder holds the PNGs named gallery-<theme>--<page|post>--<width>.png
 * and skipped.json, the themes the spec found not installed. The image base
 * URL is the folder holding the PNGs, for example
 * https://github.com/OWNER/REPO/blob/SHA/gallery-12, and "?raw=true" is
 * added to each image so it renders from a private repository too.
 */

import { existsSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { galleryThemes } from './gallery-themes.cjs';

const MARKER = '<!-- undergrowth-theme-gallery -->';
const KINDS = [
	[ 'page', 'Garden page' ],
	[ 'post', 'post' ],
];
const WIDTHS = [ 1280, 390 ];
// Four images a row: the phone shots are shown at about a third of the
// desktop ones, as the widths relate.
const SHOWN = { 1280: 320, 390: 110 };

/**
 * Reads a PNG's size from its header, without decoding the image.
 *
 * @param {string} file Path of the PNG.
 * @return {{width: number, height: number}} Size in pixels.
 */
function size( file ) {
	const header = readFileSync( file ).subarray( 16, 24 );

	return {
		width: header.readUInt32BE( 0 ),
		height: header.readUInt32BE( 4 ),
	};
}

/**
 * Lists the themes the spec recorded as not installed.
 *
 * @param {string} dir Folder of shots.
 * @return {Set<string>} Theme slugs.
 */
function skipped( dir ) {
	const file = join( dir, 'skipped.json' );

	return new Set(
		existsSync( file ) ? JSON.parse( readFileSync( file, 'utf8' ) ) : []
	);
}

/**
 * Builds the sheet.
 *
 * @param {string}  dir  Folder of shots.
 * @param {?string} base URL of the folder holding the PNGs, or null for sizes.
 * @return {string} Markdown.
 */
function sheet( dir, base ) {
	const notInstalled = skipped( dir );
	const rows = [];
	let shot = 0;
	let missing = 0;

	for ( const theme of galleryThemes() ) {
		const cells = [ `\`${ theme }\`` ];

		if ( notInstalled.has( theme ) ) {
			cells.push(
				...KINDS.flatMap( () => WIDTHS.map( () => 'not installed' ) )
			);
			rows.push( cells );
			continue;
		}

		let taken = 0;

		for ( const [ kind, label ] of KINDS ) {
			for ( const width of WIDTHS ) {
				const name = `gallery-${ theme }--${ kind }--${ width }.png`;
				const file = join( dir, name );

				if ( ! existsSync( file ) ) {
					cells.push( 'missing' );
					continue;
				}

				taken += 1;

				if ( base ) {
					cells.push(
						`<img src="${ base }/${ name }?raw=true" width="${ SHOWN[ width ] }" alt="${ theme }, ${ label }, ${ width }">`
					);
				} else {
					const { width: w, height: h } = size( file );

					cells.push( `${ w } × ${ h }` );
				}
			}
		}

		if ( taken > 0 ) {
			shot += 1;
		} else {
			missing += 1;
		}

		rows.push( cells );
	}

	const lines = [ MARKER, '## Theme gallery', '' ];

	// A theme that could not be installed is still worth a row, so the
	// sheet is empty only when there is nothing at all to say.
	if ( 0 === shot && 0 === notInstalled.size ) {
		lines.push( 'No shots were taken. Check the workflow run for errors.' );
		return lines.join( '\n' );
	}

	lines.push(
		`${ shot } themes shot, ${ notInstalled.size } not installed, ${ missing } missing. The Garden page and one demo post, with its growth stage, "Linked from" and "More like this", as readers see them. Each shot is \`gallery-<theme>--<page|post>--<width>\`.`,
		'',
		`| Theme | ${ KINDS.flatMap( ( [ , label ] ) =>
			WIDTHS.map(
				( width ) =>
					`${ label[ 0 ].toUpperCase() }${ label.slice( 1 ) }, ${ width }`
			)
		).join( ' | ' ) } |`,
		`| --- |${ ' --- |'.repeat( KINDS.length * WIDTHS.length ) }`,
		...rows.map( ( cells ) => `| ${ cells.join( ' | ' ) } |` )
	);

	return lines.join( '\n' );
}

if ( import.meta.url === pathToFileURL( resolve( process.argv[ 1 ] ) ).href ) {
	const [ dir, base ] = process.argv.slice( 2 );

	if ( ! dir ) {
		process.stderr.write(
			'Usage: node bin/gallery-sheet.mjs <folder> [image base URL]\n'
		);
		process.exit( 2 );
	}

	process.stdout.write( sheet( dir, base || null ) + '\n' );
}
