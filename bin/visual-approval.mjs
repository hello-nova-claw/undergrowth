/**
 * Counts the visual changes a pull request makes, for the Visual approval
 * status.
 *
 * Prints JSON with two counts of shots that are changed, new or removed:
 * "changes" against the base branch, and "sinceApproved" against the shots
 * the last run published for the pull request, which are the ones a
 * visual-approved label approved. A missing folder counts as empty, so every
 * shot is new against it.
 *
 * Usage:
 *   node bin/visual-approval.mjs <before> <after> <approved>
 */

import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { compare } from './compare-screenshots.mjs';

/**
 * Counts the shots that are changed, new or removed.
 *
 * @param {string} from Folder of the earlier shots.
 * @param {string} to   Folder of the later shots.
 * @return {number} How many shots differ.
 */
export function differences( from, to ) {
	const result = compare( from, to );

	return result.changed.length + result.added.length + result.removed.length;
}

if ( import.meta.url === pathToFileURL( resolve( process.argv[ 1 ] ) ).href ) {
	const [ before, after, approved ] = process.argv.slice( 2 );

	if ( ! before || ! after || ! approved ) {
		process.stderr.write(
			'Usage: node bin/visual-approval.mjs <before> <after> <approved>\n'
		);
		process.exit( 2 );
	}

	process.stdout.write(
		JSON.stringify( {
			changes: differences( before, after ),
			sinceApproved: differences( approved, after ),
		} ) + '\n'
	);
}
