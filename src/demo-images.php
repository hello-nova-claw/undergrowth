<?php
/**
 * Serves the demo blog's illustrations from the plugin's own copy while the
 * WordPress Importer runs, so the demo imports without reaching GitHub.
 *
 * The demo WXR files in demo/ name each illustration by its address in the
 * repository. A site that imports them with attachments downloads every
 * picture from there, which needs GitHub to be reachable and the main branch
 * to still hold the files. A checkout has the same files in demo/images/,
 * so the download is answered from disk when the file is there.
 *
 * @package Undergrowth
 */

/**
 * The address the demo WXR files give each illustration, with a trailing
 * slash.
 *
 * @return string
 */
function undergrowth_demo_images_url(): string {
	return 'https://raw.githubusercontent.com/hello-nova-claw/undergrowth/main/demo/images/';
}

/**
 * The plugin's own copy of the illustrations: demo/images/ in a checkout of
 * the repository. The release zip leaves demo/ out, so a plugin installed
 * from it has none.
 *
 * @return string The folder, without a trailing slash, or an empty string when the plugin has none.
 */
function undergrowth_demo_images_dir(): string {
	$dir = untrailingslashit( plugin_dir_path( UNDERGROWTH_FILE ) ) . '/demo/images';

	return is_dir( $dir ) ? $dir : '';
}

/**
 * Finds the local copy of the illustration an address names.
 *
 * @param string $dir Folder holding the illustrations, or an empty string for none.
 * @param string $url Address requested.
 * @return string Path of the file, or an empty string when the address is not under the demo image base or the folder has no file of that name.
 */
function undergrowth_demo_image_path( string $dir, string $url ): string {
	$base = undergrowth_demo_images_url();

	if ( '' === $dir || 0 !== strpos( $url, $base ) ) {
		return '';
	}

	$name = sanitize_file_name( substr( $url, strlen( $base ) ) );
	$path = $dir . '/' . $name;

	return '' !== $name && is_file( $path ) ? $path : '';
}

/**
 * Answers a streamed download with a local file, as the importer expects a
 * server to: the bytes in the file the request named, and the type and
 * length in the headers, which the importer checks against what arrived.
 * Without a file the answer is 404, so the importer moves on.
 *
 * @param string               $path Local file, or an empty string when there is none.
 * @param array<string, mixed> $args Request arguments, with the file to stream to in 'filename'.
 * @return array<string, mixed> Response, as the HTTP API returns one.
 */
function undergrowth_demo_image_response( string $path, array $args ): array {
	$found = '' !== $path && ! empty( $args['filename'] ) && copy( $path, (string) $args['filename'] );

	return array(
		'headers'  => $found ? array(
			'content-type'   => (string) wp_check_filetype( $path )['type'],
			'content-length' => (string) filesize( $path ),
		) : array(),
		'body'     => '',
		'response' => array(
			'code'    => $found ? 200 : 404,
			'message' => $found ? 'OK' : 'Not Found',
		),
		'cookies'  => array(),
		'filename' => $found ? (string) $args['filename'] : null,
	);
}

/**
 * Answers the importer's download of a demo illustration from the plugin's
 * copy. Anything else, and a file the copy lacks, goes on to the network as
 * before, so a site that can reach GitHub still gets it, and one that cannot
 * ends the import without that picture: the importer records the failed
 * download and moves on, and the Garden feed shows the post as a text card.
 *
 * @param false|array<string, mixed>|WP_Error $preempt Response so far.
 * @param array<string, mixed>                $args    Request arguments.
 * @param string                              $url     Address requested.
 * @return false|array<string, mixed>|WP_Error
 */
function undergrowth_demo_image_download( $preempt, $args, $url ) {
	if ( false !== $preempt || empty( $args['stream'] ) || empty( $args['filename'] ) ) {
		return $preempt;
	}

	$path = undergrowth_demo_image_path( undergrowth_demo_images_dir(), (string) $url );

	return '' === $path ? $preempt : undergrowth_demo_image_response( $path, $args );
}

/**
 * Answers demo image downloads from the start of an import to its end. No
 * request on any other page load passes through the filter.
 */
function undergrowth_demo_images_import_start(): void {
	add_filter( 'pre_http_request', 'undergrowth_demo_image_download', 10, 3 );
}

/**
 * Stops answering demo image downloads once the import has ended.
 */
function undergrowth_demo_images_import_end(): void {
	remove_filter( 'pre_http_request', 'undergrowth_demo_image_download' );
}

add_action( 'import_start', 'undergrowth_demo_images_import_start' );
add_action( 'import_end', 'undergrowth_demo_images_import_end' );
