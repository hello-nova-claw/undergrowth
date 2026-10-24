#!/usr/bin/env node
/**
 * Runs pa11y-ci against the plugin's admin screens and the Growth stage
 * block on the front end, in WordPress Playground, so no Docker is needed.
 *
 * The site comes from tests/e2e/playground/pa11y.json, the e2e test
 * provider and the small demo blog. The pages to test are in .pa11yci.js, which reads the site's
 * address from WP_BASE_URL.
 *
 * Usage: npm run test:a11y
 */

import { spawn } from 'node:child_process';
import { once } from 'node:events';
import http from 'node:http';
import { join, resolve } from 'node:path';
import { buildPlugin, pluginMounts } from './plugin-mounts.cjs';

const root = resolve( import.meta.dirname, '..' );
const host = '127.0.0.1';
const port = Number( process.env.UNDERGROWTH_PA11Y_PORT || 9410 );

/**
 * Says what went wrong and stops.
 *
 * @param {string} message What went wrong.
 */
function fail( message ) {
	process.stderr.write( message + '\n' );
	process.exit( 1 );
}

// The CLI has no option for the address it listens on, and listens on every
// network interface by default. Only this computer should reach the site.
const listen = http.Server.prototype.listen;
http.Server.prototype.listen = function ( portArg, ...rest ) {
	if ( 'number' === typeof portArg && 'string' !== typeof rest[ 0 ] ) {
		return listen.call( this, portArg, host, ...rest );
	}

	return listen.call( this, portArg, ...rest );
};

try {
	buildPlugin( root );
} catch ( error ) {
	fail( error.message );
}

const mount = pluginMounts( root );

mount.push(
	{
		hostPath: join( root, 'tests/e2e/mu-plugins' ),
		vfsPath: '/wordpress/wp-content/mu-plugins',
	},
	{
		hostPath: join( root, 'demo' ),
		vfsPath: '/wordpress/wp-content/undergrowth-demo',
	}
);

const { runCLI } = await import( '@wp-playground/cli' );

let server;

try {
	server = await runCLI( {
		command: 'server',
		port,
		quiet: true,
		blueprint: join( root, 'tests/e2e/playground/pa11y.json' ),
		mount,
	} );
} catch ( error ) {
	fail( error.message );
}

// The CLI also listens once to see whether the port is free, so the override
// above is checked on the server that serves the site.
if ( host !== server.server.address()?.address ) {
	await server[ Symbol.asyncDispose ]();
	fail( `Playground did not listen on ${ host } only.` );
}

// Asynchronously, since this same process serves the site.
const pa11y = spawn(
	'npx',
	[ 'pa11y-ci', '--config', join( root, '.pa11yci.js' ) ],
	{
		cwd: root,
		stdio: 'inherit',
		shell: 'win32' === process.platform,
		env: { ...process.env, WP_BASE_URL: server.serverUrl },
	}
);

const [ status ] = await once( pa11y, 'exit' );

await server[ Symbol.asyncDispose ]();

process.exit( status ?? 1 );
