import { afterEach, beforeAll, describe, expect, test } from '@jest/globals';
import apiFetch from '@wordpress/api-fetch';
import { getBlockType, parse } from '@wordpress/blocks';
import { createRoot } from '@wordpress/element';

import metadata from '../../src/blocks/garden-feed/block.json';
import Edit from '../../src/blocks/garden-feed/edit';
import '../../src/blocks/garden-feed/index';

const requests = [];
let reply = { rendered: '' };

const DEFAULTS = Object.fromEntries(
	Object.entries( metadata.attributes ).map( ( [ name, attribute ] ) => [
		name,
		attribute.default,
	] )
);

/**
 * Waits until a condition holds, for the debounced render request.
 *
 * @param {() => unknown} condition Returns something truthy once done.
 */
async function waitFor( condition ) {
	for ( let tries = 0; tries < 100 && ! condition(); tries++ ) {
		await new Promise( ( resolve ) => setTimeout( resolve, 20 ) );
	}
}

/**
 * Renders the editor preview into the document.
 *
 * @param {Object} attributes Block attributes.
 * @return {HTMLElement} Container.
 */
function renderEdit( attributes = DEFAULTS ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	createRoot( container ).render(
		<Edit attributes={ attributes } setAttributes={ () => {} } />
	);

	return container;
}

describe( 'Garden feed block', () => {
	beforeAll( () => {
		// Core's Placeholder sizes itself with ResizeObserver, which jsdom lacks.
		global.ResizeObserver ??= class {
			observe() {}
			unobserve() {}
			disconnect() {}
		};

		// Answers the block renderer as WordPress would, so no server is needed.
		apiFetch.use( ( options ) => {
			requests.push( options );

			return Promise.resolve( reply );
		} );
	} );

	afterEach( () => {
		requests.length = 0;
		document.body.innerHTML = '';
	} );

	test( 'is registered from block.json', () => {
		const type = getBlockType( 'undergrowth/garden-feed' );

		expect( type.title ).toBe( metadata.title );
		expect( type.attributes.orderBy.default ).toBe( 'tended' );
		expect( type.attributes.typeSource.default ).toBe( 'post_format' );
		expect( type.attributes.showImages.default ).toBe( true );
		expect( type.attributes.showFeatured ).toBeUndefined();
	} );

	test( 'reads a block saved with the old showFeatured setting', () => {
		// Saved before the setting was renamed, so the editor keeps the choice.
		const [ block ] = parse(
			'<!-- wp:undergrowth/garden-feed {"showFeatured":false,"postsPerPage":6} /-->'
		);

		expect( block.name ).toBe( 'undergrowth/garden-feed' );
		expect( block.isValid ).toBe( true );
		expect( block.attributes.showImages ).toBe( false );
		expect( block.attributes.postsPerPage ).toBe( 6 );
		expect( block.attributes.showFeatured ).toBeUndefined();

		const [ current ] = parse(
			'<!-- wp:undergrowth/garden-feed {"showImages":false} /-->'
		);

		expect( current.attributes.showImages ).toBe( false );
	} );

	test( 'previews the server render with the chosen settings', async () => {
		reply = {
			rendered:
				'<div class="wp-block-undergrowth-garden-feed"><ul class="wp-block-undergrowth-garden-feed-list"><li class="wp-block-undergrowth-garden-feed-item"><a class="wp-block-undergrowth-garden-feed-title" href="/?p=3"><span>Oca</span></a></li></ul></div>',
		};

		const container = renderEdit( {
			...DEFAULTS,
			orderBy: 'stage',
			postsPerPage: 6,
			backgroundColor: 'accent',
			style: { spacing: { padding: { top: '2em' } } },
		} );

		await waitFor( () => container.querySelector( 'li a' ) );

		expect( requests ).toHaveLength( 1 );
		const path = decodeURIComponent( requests[ 0 ].path );
		expect( path ).toMatch(
			/^\/wp\/v2\/block-renderer\/undergrowth\/garden-feed\?/
		);
		expect( path ).toContain( 'attributes[orderBy]=stage' );
		expect( path ).toContain( 'attributes[postsPerPage]=6' );
		// The wrapper carries the supports, so the preview never doubles them.
		expect( path ).not.toMatch( /backgroundColor|padding/ );
		expect( container.querySelector( 'li a' ).textContent ).toBe( 'Oca' );
		// The links are for readers, so the preview holds no tab stops.
		expect( container.querySelector( '[inert] li a' ) ).not.toBeNull();
	} );

	test( 'explains an empty render instead of showing nothing', async () => {
		reply = { rendered: '' };

		const container = renderEdit();

		await waitFor( () => requests.length && container.textContent );

		// A note from the block, in core's Placeholder, not text in the post.
		expect(
			container.querySelector( '.components-placeholder' ).textContent
		).toContain( 'Readers see nothing here yet.' );
		expect( container.querySelector( 'ul' ) ).toBeNull();
	} );
} );
