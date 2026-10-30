#!/usr/bin/env node
/**
 * Starts WordPress Playground on this computer for testing Undergrowth with
 * real embeddings: free ones from Ollama on this computer, or OpenAI's with
 * the owner's own key. An Anthropic key, also optional, turns on questions.
 *
 * The keys come from .env.local, which git ignores, and reach WordPress as
 * wp-config.php constants. They never go into a URL, a file in the
 * repository or the terminal. The e2e test provider is not mounted, so every
 * request goes to a real model.
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
import { buildPlugin, pluginMounts } from './plugin-mounts.cjs';
import {
	autoAccept,
	autoAcceptMessage,
	startMessage,
} from './playground-real-text.cjs';

const root = resolve( import.meta.dirname, '..' );
const envFile = join( root, '.env.local' );
const host = '127.0.0.1';
const ollama = 'http://127.0.0.1:11434';
const ollamaModel = 'nomic-embed-text';

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

const env = existsSync( envFile )
	? parseEnv( readFileSync( envFile, 'utf8' ) )
	: {};
const openaiKey = ( env.OPENAI_API_KEY ?? '' ).trim();
const anthropicKey = ( env.ANTHROPIC_API_KEY ?? '' ).trim();
// WXR=demo and WXR=demo-small name the public-domain demo blog in demo/.
const demoWxr = {
	demo: 'demo/undergrowth-demo.xml',
	'demo-small': 'demo/undergrowth-demo-small.xml',
};
const wxrSetting = ( env.WXR ?? '' ).trim();
const wxr = Object.hasOwn( demoWxr, wxrSetting )
	? demoWxr[ wxrSetting ]
	: wxrSetting;
const embeddings = ( env.UNDERGROWTH_EMBEDDINGS ?? '' ).trim();
const acceptAll = autoAccept( env );

if ( ! [ '', 'ollama', 'openai' ].includes( embeddings ) ) {
	fail( 'UNDERGROWTH_EMBEDDINGS in .env.local must be ollama or openai.' );
}

if ( 'openai' === embeddings && '' === openaiKey ) {
	fail( 'UNDERGROWTH_EMBEDDINGS is openai, but OPENAI_API_KEY is empty.' );
}

const local = 'ollama' === embeddings || '' === openaiKey;

if ( local ) {
	let models;

	try {
		const response = await fetch( new URL( '/api/tags', ollama ), {
			signal: AbortSignal.timeout( 3000 ),
		} );
		models = ( await response.json() ).models ?? [];
	} catch {
		fail(
			`Ollama is not running on ${ ollama }. Start it with \`brew services start ollama\` or \`ollama serve\`, then run \`ollama pull ${ ollamaModel }\`.`
		);
	}

	if ( ! models.some( ( model ) => model.name.startsWith( ollamaModel ) ) ) {
		fail(
			`Ollama is running but has no ${ ollamaModel }. Run \`ollama pull ${ ollamaModel }\`.`
		);
	}
}

if ( '' !== wxr && ! existsSync( resolve( root, wxr ) ) ) {
	fail( 'The WXR file named in .env.local does not exist.' );
}

// A local site, so Posts > Undergrowth has its Dev tools tab.
const consts = { DISABLE_WP_CRON: true, WP_ENVIRONMENT_TYPE: 'local' };

// In local mode an OpenAI key is left out, so nothing is sent to OpenAI.
if ( ! local ) {
	consts.UNDERGROWTH_OPENAI_API_KEY = openaiKey;
}

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

let stop = () => process.exit( 0 );

for ( const signal of [ 'SIGINT', 'SIGTERM', 'SIGHUP' ] ) {
	process.on( signal, () => stop() );
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

try {
	buildPlugin( root );
} catch ( error ) {
	fail( error.message );
}

const mount = pluginMounts( root );

if ( local ) {
	mount.push( {
		hostPath: join( root, 'docs/examples/local-embeddings.php' ),
		vfsPath:
			'/wordpress/wp-content/mu-plugins/undergrowth-local-embeddings.php',
	} );
}

if ( '' !== wxr ) {
	mount.push( {
		hostPath: resolve( root, wxr ),
		vfsPath: '/undergrowth-import/export.xml',
	} );
}

// The demo blog's illustrations, inside the plugin folder, where the plugin
// answers the importer's download of each from disk, so the demo imports
// with its pictures without reaching GitHub. The zip does not ship them,
// so plugin-mounts.cjs leaves them out. The import runs before the plugin
// is activated, so a must-use plugin loads the one file that answers the
// downloads; the plugin's own require_once of the same file is then a no-op.
if ( Object.hasOwn( demoWxr, wxrSetting ) ) {
	const demoImagesLoader = join(
		blueprintDir,
		'undergrowth-demo-images.php'
	);
	writeFileSync(
		demoImagesLoader,
		[
			'<?php',
			"defined( 'ABSPATH' ) || exit;",
			"require_once WP_PLUGIN_DIR . '/undergrowth/src/demo-images.php';",
			'',
		].join( '\n' ),
		{ mode: 0o600 }
	);
	mount.push(
		{
			hostPath: join( root, 'demo/images' ),
			vfsPath: '/wordpress/wp-content/plugins/undergrowth/demo/images',
		},
		{
			hostPath: demoImagesLoader,
			vfsPath:
				'/wordpress/wp-content/mu-plugins/undergrowth-demo-images.php',
		}
	);
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

// Tells whether every post is read: none is waiting, and no read, backfill
// or cycle is queued. A post whose read failed is retried hourly, so it
// would hold this up; the count says so.
const readyCode = `<?php
require '/wordpress/wp-load.php';
$counts = undergrowth_index_counts();
$queued = false;
foreach ( (array) _get_cron_array() as $events ) {
	foreach ( array( 'undergrowth_embed_post', 'undergrowth_backfill', 'undergrowth_cluster_cycle' ) as $hook ) {
		foreach ( $events[ $hook ] ?? array() as $event ) {
			$queued = $queued || false === $event['schedule'];
		}
	}
}
echo wp_json_encode( array( 'ready' => $counts['total'] > 0 && $counts['indexed'] >= $counts['total'] && ! $queued ) );
`;

// The same as \`wp undergrowth accept --all\` and \`wp undergrowth run-cycle\`,
// which Playground has no WP-CLI for: the Dev tools' own job and cycle, as
// the admin user, so every Accept rule applies.
const acceptCode = `<?php
require '/wordpress/wp-load.php';
wp_set_current_user( 1 );
if ( ! undergrowth_start_dev_job( 'accept' ) ) {
	echo wp_json_encode( array( 'busy' => true ) );
	return;
}
$job    = undergrowth_run_dev_job( null );
$counts = null === $job ? array() : $job['counts'];
undergrowth_dev_run_cycle();
echo wp_json_encode( array( 'counts' => (object) $counts, 'groups' => count( undergrowth_cluster_map() ) ) );
`;

/**
 * Once every post is read, accepts every suggestion and runs the cycle, once.
 * While a job started from the Dev tools tab is running, it waits for it.
 */
async function acceptWhenRead() {
	try {
		const ready = JSON.parse(
			( await server.playground.run( { code: readyCode } ) ).text
		);

		if ( ready.ready ) {
			const result = JSON.parse(
				( await server.playground.run( { code: acceptCode } ) ).text
			);

			// Another dev tools job, started from the tab, holds the site:
			// nothing was accepted or run, so try again once it is done.
			if ( ! result.busy ) {
				process.stdout.write( autoAcceptMessage( result ) );
				return;
			}
		}
	} catch ( error ) {
		process.stderr.write(
			`Could not accept the suggestions: ${ error.message }\n`
		);
		return;
	}

	setTimeout( acceptWhenRead, cronPause * 1000 ).unref();
}

if ( acceptAll ) {
	acceptWhenRead();
}

/**
 * Prints what `wp undergrowth scores` prints, which Playground has no WP-CLI
 * for: score percentiles and thresholds, never any post text.
 */
async function printScores() {
	const response = await server.playground.run( {
		code: "<?php require '/wordpress/wp-load.php'; echo implode( PHP_EOL, undergrowth_scores_report() );",
	} );

	process.stdout.write(
		`\nScores for the feedback issue:\n${ response.text }\n\n`
	);
}

stop = async () => {
	stop = () => {};

	try {
		await printScores();
	} catch {
		process.stderr.write( 'Could not read the scores.\n' );
	}

	await server[ Symbol.asyncDispose ]();
	process.exit( 0 );
};

process.stdout.write(
	startMessage( {
		siteUrl,
		local,
		model: ollamaModel,
		anthropic: '' !== anthropicKey,
		acceptAll,
	} )
);
