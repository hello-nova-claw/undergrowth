#!/usr/bin/env node
/**
 * Starts WordPress Playground on this computer with the owner's own API keys,
 * for testing Undergrowth against the real OpenAI and Anthropic APIs.
 *
 * The keys come from .env.local, which git ignores, and reach WordPress as
 * wp-config.php constants. They never go into a URL, a file in the
 * repository or the terminal. The e2e test provider is not mounted, so every
 * request goes to the real API.
 *
 * Usage: npm run playground:real
 */

import {
	existsSync,
	mkdtempSync,
	readFileSync,
	rmSync,
	writeFileSync,
} from 'node:fs';
import http from 'node:http';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { parseEnv } from 'node:util';

const root = resolve( import.meta.dirname, '..' );
const envFile = join( root, '.env.local' );
const host = '127.0.0.1';

// WP-Cron starts itself with a request back to the site, which Playground's
// automatic login answers with a redirect, so it never runs. Instead, as on
// a server with a real cron job, this script runs it, pausing this long
// between runs.
const cronPause = 10;

/**
 * Says what went wrong and stops.
 *
 * @param {string} message What went wrong.
 */
function fail( message ) {
	process.stderr.write( message + '\n' );
	process.exit( 1 );
}

if ( ! existsSync( envFile ) ) {
	fail(
		'No .env.local found. Copy .env.local.example to .env.local, put your OpenAI key in it, and run this again.'
	);
}

const env = parseEnv( readFileSync( envFile, 'utf8' ) );
const openaiKey = ( env.OPENAI_API_KEY ?? '' ).trim();
const anthropicKey = ( env.ANTHROPIC_API_KEY ?? '' ).trim();
const wxr = ( env.WXR ?? '' ).trim();

if ( '' === openaiKey ) {
	fail( 'OPENAI_API_KEY is empty in .env.local.' );
}

if ( '' !== wxr && ! existsSync( resolve( root, wxr ) ) ) {
	fail( 'The WXR file named in .env.local does not exist.' );
}

const consts = {
	UNDERGROWTH_OPENAI_API_KEY: openaiKey,
	DISABLE_WP_CRON: true,
};

if ( '' !== anthropicKey ) {
	consts.UNDERGROWTH_ANTHROPIC_API_KEY = anthropicKey;
}

// The blueprint holds the keys, so it lives in a folder only this user can
// read, outside the repository, and is deleted on exit.
const blueprintDir = mkdtempSync( join( tmpdir(), 'undergrowth-playground-' ) );
const blueprintFile = join( blueprintDir, 'blueprint.json' );
const steps = [ { step: 'defineWpConfigConsts', consts } ];

if ( '' !== wxr ) {
	steps.push( {
		step: 'importWxr',
		file: { resource: 'vfs', path: '/undergrowth-import/export.xml' },
	} );
}

// Activating after the import starts reading the imported posts at once.
// Logging in comes last: defining constants first would drop the one the
// login step sets.
steps.push(
	{ step: 'activatePlugin', pluginPath: 'undergrowth/undergrowth.php' },
	{ step: 'login' }
);

writeFileSync(
	blueprintFile,
	JSON.stringify( {
		$schema: 'https://playground.wordpress.net/blueprint-schema.json',
		preferredVersions: { php: '8.3', wp: 'latest' },
		features: { networking: true },
		landingPage: '/wp-admin/options-general.php?page=undergrowth',
		steps,
	} ),
	{ mode: 0o600 }
);

function cleanUp() {
	rmSync( blueprintDir, { recursive: true, force: true } );
}

process.on( 'exit', cleanUp );

for ( const signal of [ 'SIGINT', 'SIGTERM', 'SIGHUP' ] ) {
	process.on( signal, () => process.exit( 0 ) );
}

// The CLI has no option for the address it listens on, and listens on every
// network interface by default. Only this computer should reach the site.
const listen = http.Server.prototype.listen;
http.Server.prototype.listen = function ( port, ...rest ) {
	if ( 'number' === typeof port && 'string' !== typeof rest[ 0 ] ) {
		return listen.call( this, port, host, ...rest );
	}

	return listen.call( this, port, ...rest );
};

const mount = [
	{ hostPath: root, vfsPath: '/wordpress/wp-content/plugins/undergrowth' },
];

if ( '' !== wxr ) {
	mount.push( {
		hostPath: resolve( root, wxr ),
		vfsPath: '/undergrowth-import/export.xml',
	} );
}

const { runCLI } = await import( '@wp-playground/cli' );

let server;

try {
	server = await runCLI( {
		command: 'server',
		blueprint: blueprintFile,
		mount,
	} );
} catch ( error ) {
	fail( error.message );
}

const siteUrl = server.serverUrl;

async function runCron() {
	try {
		// The cookie tells the automatic login that it has already happened.
		await fetch( new URL( '/wp-cron.php', siteUrl ), {
			headers: { Cookie: 'playground_auto_login_already_happened=1' },
			redirect: 'manual',
		} );
	} catch {
		// The next run tries again.
	}

	setTimeout( runCron, cronPause * 1000 ).unref();
}

runCron();

process.stdout.write(
	[
		'',
		`Undergrowth is running at ${ siteUrl } with your own keys.`,
		'Posts are read in the background, a few at a time; Settings > Undergrowth counts them.',
		'Stop with Ctrl+C. The site and the temporary blueprint are deleted.',
		'',
	].join( '\n' )
);
