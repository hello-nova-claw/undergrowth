/**
 * Compares two screenshot catalogues and writes the pull request comment.
 *
 * Prints Markdown to stdout: a before/after table of the shots that changed or
 * are new, then the unchanged ones in a collapsed list. Always exits 0 when
 * the folders can be read, because a visual change must never fail a build.
 *
 * Usage:
 *   node bin/compare-screenshots.mjs <before> <after> <image base URL>
 *
 * The image base URL is the folder holding before/ and after/, for example
 * https://github.com/OWNER/REPO/blob/SHA/pr-12, and "?raw=true" is added to
 * each image so it renders from a private repository too.
 */

import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import pixelmatch from 'pixelmatch';
import { PNG } from 'pngjs';

const MARKER = '<!-- undergrowth-screenshots -->';

// GitHub refuses comments over 65,536 characters. The table of changed shots
// stays well under it, and a collapsed list that would push the comment over
// becomes a link to the folder instead.
const MAX_ROWS = 60;
const MAX_LENGTH = 60000;

/**
 * Lists the PNG files in a folder, or none when it is missing.
 *
 * @param {string} dir Folder to read.
 * @return {string[]} File names, sorted.
 */
function pngs( dir ) {
	return existsSync( dir )
		? readdirSync( dir )
				.filter( ( name ) => name.endsWith( '.png' ) )
				.sort()
		: [];
}

/**
 * Lists the shots the catalogue skipped because the build lacks the state.
 *
 * @param {string} dir Folder to read.
 * @return {string[]} Shot file names, sorted.
 */
function skipped( dir ) {
	return existsSync( dir )
		? readdirSync( dir )
				.filter( ( name ) => name.endsWith( '.png.skipped' ) )
				.map( ( name ) => name.slice( 0, -'.skipped'.length ) )
				.sort()
		: [];
}

/**
 * Tells whether two PNG files differ by any pixel.
 *
 * @param {string} a First file.
 * @param {string} b Second file.
 * @return {boolean} True when the size or any pixel differs.
 */
function differs( a, b ) {
	const one = PNG.sync.read( readFileSync( a ) );
	const two = PNG.sync.read( readFileSync( b ) );

	if ( one.width !== two.width || one.height !== two.height ) {
		return true;
	}

	return (
		pixelmatch( one.data, two.data, null, one.width, one.height, {
			threshold: 0.1,
		} ) > 0
	);
}

/**
 * Sorts every shot into new, changed, removed or unchanged, and lists the
 * ones the pull request's build skipped.
 *
 * The skipped list is not a fifth outcome: a state the base had and the pull
 * request lacks is both removed and skipped, and appears in both lists.
 *
 * @param {string} before Folder of shots from the base branch.
 * @param {string} after  Folder of shots from the pull request.
 * @return {{added: string[], changed: string[], removed: string[], unchanged: string[], skipped: string[]}} Shot names by outcome.
 */
function compare( before, after ) {
	const old = new Set( pngs( before ) );
	const now = pngs( after );
	const result = {
		added: [],
		changed: [],
		removed: [],
		unchanged: [],
		skipped: skipped( after ),
	};

	for ( const name of now ) {
		if ( ! old.has( name ) ) {
			result.added.push( name );
		} else if ( differs( join( before, name ), join( after, name ) ) ) {
			result.changed.push( name );
		} else {
			result.unchanged.push( name );
		}
	}

	const current = new Set( now );
	result.removed = [ ...old ].filter( ( name ) => ! current.has( name ) );

	return result;
}

/**
 * Builds the comment body.
 *
 * @param {ReturnType<typeof compare>} result Shot names by outcome.
 * @param {string}                     base   URL of the folder holding before/ and after/.
 * @return {string} Markdown.
 */
function comment( result, base ) {
	const image = ( side, name ) =>
		`<img src="${ base }/${ side }/${ name }?raw=true" width="400" alt="${ name }, ${ side }">`;
	const link = ( side, name ) =>
		`[${ name }](${ base }/${ side }/${ name }?raw=true)`;
	const total =
		result.added.length + result.changed.length + result.unchanged.length;
	const lines = [ MARKER, '## Screenshots', '' ];

	if (
		0 === total &&
		0 === result.removed.length &&
		0 === result.skipped.length
	) {
		lines.push(
			'No screenshots were taken. Check the workflow run for errors.'
		);
		return lines.join( '\n' );
	}

	lines.push(
		`${ result.changed.length } changed, ${ result.added.length } new, ${ result.removed.length } removed, ${ result.unchanged.length } unchanged, ${ result.skipped.length } not in this build. Each shot is \`<screen>--<state>--<locale>--<width>\`.`,
		''
	);

	const rows = [
		...result.changed.map( ( name ) => [ name, 'changed' ] ),
		...result.added.map( ( name ) => [ name, 'new' ] ),
	];

	if ( rows.length > 0 ) {
		lines.push( '| Shot | Before | After |', '| --- | --- | --- |' );

		for ( const [ name, kind ] of rows.slice( 0, MAX_ROWS ) ) {
			lines.push(
				`| \`${ name }\` (${ kind }) | ${
					'new' === kind ? '' : image( 'before', name )
				} | ${ image( 'after', name ) } |`
			);
		}

		if ( rows.length > MAX_ROWS ) {
			lines.push(
				'',
				`${
					rows.length - MAX_ROWS
				} more changed or new shots are in the workflow artifact and in [after/](${ base.replace(
					'/blob/',
					'/tree/'
				) }/after).`
			);
		}

		lines.push( '' );
	} else {
		lines.push( 'No changed or new shots.', '' );
	}

	const tree = ( side ) =>
		`${ base.replace( '/blob/', '/tree/' ) }/${ side }`;
	const sections = [];

	if ( result.removed.length > 0 ) {
		sections.push( {
			lines: [
				'<details><summary>Removed</summary>',
				'',
				...result.removed.map(
					( name ) => `- ${ link( 'before', name ) }`
				),
				'',
				'</details>',
				'',
			],
			short: `${ result.removed.length } removed shots are in [before/](${ tree(
				'before'
			) }).`,
		} );
	}

	if ( result.skipped.length > 0 ) {
		sections.push( {
			lines: [
				'<details><summary>Not in this build</summary>',
				'',
				'These states are in the catalogue, but this build does not have them, so they were skipped.',
				'',
				...result.skipped.map( ( name ) => `- \`${ name }\`` ),
				'',
				'</details>',
				'',
			],
			short: `${ result.skipped.length } states are in the catalogue but not in this build, so they were skipped. The workflow artifact has their markers.`,
		} );
	}

	if ( result.unchanged.length > 0 ) {
		sections.push( {
			lines: [
				'<details><summary>Unchanged</summary>',
				'',
				'| Shot | Before and after (identical) |',
				'| --- | --- |',
				...result.unchanged.map(
					( name ) =>
						`| ${ link( 'after', name ) } | <img src="${ base }/after/${ name }?raw=true" width="200" alt="${ name }"> |`
				),
				'',
				'</details>',
			],
			short: `${ result.unchanged.length } unchanged shots are in [after/](${ tree(
				'after'
			) }).`,
		} );
	}

	// The workflow adds its warnings and a link to the run, under 1,000
	// characters, so the budget leaves room. Its image base URL holds a
	// placeholder as long as the SHA that replaces it.
	let length = lines.join( '\n' ).length;

	for ( const section of sections ) {
		const full = section.lines.join( '\n' ).length + 1;

		if ( length + full <= MAX_LENGTH ) {
			lines.push( ...section.lines );
			length += full;
		} else {
			lines.push( section.short, '' );
			length += section.short.length + 2;
		}
	}

	return lines.join( '\n' );
}

if ( import.meta.url === pathToFileURL( resolve( process.argv[ 1 ] ) ).href ) {
	const [ before, after, base ] = process.argv.slice( 2 );

	if ( ! before || ! after || ! base ) {
		process.stderr.write(
			'Usage: node bin/compare-screenshots.mjs <before> <after> <image base URL>\n'
		);
		process.exit( 2 );
	}

	process.stdout.write( comment( compare( before, after ), base ) + '\n' );
}
