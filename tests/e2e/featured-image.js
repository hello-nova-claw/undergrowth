const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { PNG } = require( 'pngjs' );

/**
 * Uploads a small picture to the media library, for a post's featured
 * image. It is drawn here, a soft wash of green, so the repository holds
 * no binary fixture.
 *
 * @param {Object} requestUtils Request utilities.
 * @return {Promise<number>} The attachment's ID.
 */
async function uploadFeaturedImage( requestUtils ) {
	const png = new PNG( { width: 480, height: 320 } );

	for ( let y = 0; y < png.height; y++ ) {
		for ( let x = 0; x < png.width; x++ ) {
			const at = ( png.width * y + x ) * 4;
			const across = x / png.width;
			const down = y / png.height;

			png.data[ at ] = Math.round( 120 - 60 * down );
			png.data[ at + 1 ] = Math.round( 160 - 40 * across );
			png.data[ at + 2 ] = Math.round( 90 + 30 * down );
			png.data[ at + 3 ] = 255;
		}
	}

	const file = path.join(
		fs.mkdtempSync( path.join( os.tmpdir(), 'undergrowth-' ) ),
		'oca.png'
	);
	fs.writeFileSync( file, PNG.sync.write( png ) );

	try {
		return ( await requestUtils.uploadMedia( file ) ).id;
	} finally {
		fs.rmSync( path.dirname( file ), { recursive: true, force: true } );
	}
}

module.exports = { uploadFeaturedImage };
