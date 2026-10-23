#!/usr/bin/env node
/**
 * Lists the plugin's own files for mounting into WordPress Playground.
 *
 * Playground serves every file under a mount, dotfiles included, so
 * mounting the whole repository would put .env.local, and the API keys in
 * it, at a URL. Only what the plugin zip ships is mounted, each path from
 * the working copy so edits still show.
 *
 * Run directly, it prints the mounts, relative to the current folder, as
 * wp-playground-cli arguments for package.json.
 */

const { existsSync } = require( 'fs' );
const { join } = require( 'path' );

const SHIPPED = [
	'undergrowth.php',
	'uninstall.php',
	'readme.txt',
	'LICENSE',
	'src',
	'build',
	'assets',
	'languages',
];

const PLUGIN = '/wordpress/wp-content/plugins/undergrowth';

/**
 * Lists the mounts for a copy of the plugin. A path the copy lacks, such as
 * build/ before the first build, is left out.
 *
 * @param {string} dir Folder holding the plugin.
 * @return {{hostPath: string, vfsPath: string}[]} Mounts.
 */
function pluginMounts( dir ) {
	return SHIPPED.filter( ( path ) => existsSync( join( dir, path ) ) ).map(
		( path ) => ( {
			hostPath: join( dir, path ),
			vfsPath: `${ PLUGIN }/${ path }`,
		} )
	);
}

module.exports = { pluginMounts };

if ( require.main === module ) {
	process.stdout.write(
		pluginMounts( '.' )
			.map(
				( { hostPath, vfsPath } ) =>
					`--mount=${ hostPath }:${ vfsPath }`
			)
			.join( ' ' )
	);
}
