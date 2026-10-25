import { afterEach, beforeAll, describe, expect, test } from '@jest/globals';
import apiFetch from '@wordpress/api-fetch';
import { getBlockType } from '@wordpress/blocks';
import { createRoot } from '@wordpress/element';

import metadata from '../../src/blocks/garden-index/block.json';
import Edit from '../../src/blocks/garden-index/edit';
import '../../src/blocks/garden-index/index';

const requests = [];
let reply = { rendered: '' };

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
function renderEdit( attributes = { stage: '' } ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	createRoot( container ).render(
		<Edit attributes={ attributes } setAttributes={ () => {} } />
	);

	return container;
}

describe( 'Garden index block', () => {
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
		const type = getBlockType( 'undergrowth/garden-index' );

		expect( type.title ).toBe( metadata.title );
		expect( type.attributes.stage.default ).toBe( '' );
	} );

	test( 'previews the server render with the chosen stage', async () => {
		reply = {
			rendered:
				'<div class="wp-block-undergrowth-garden-index"><h2 class="wp-block-heading">Oca</h2><ul><li><a href="/?p=3">Oca</a></li></ul></div>',
		};

		const container = renderEdit( {
			stage: 'sprout',
			backgroundColor: 'accent',
			style: { spacing: { padding: { top: '2em' } } },
		} );

		await waitFor( () => container.querySelector( 'li a' ) );

		expect( requests ).toHaveLength( 1 );
		expect( requests[ 0 ].path ).toMatch(
			/^\/wp\/v2\/block-renderer\/undergrowth\/garden-index\?/
		);
		expect( decodeURIComponent( requests[ 0 ].path ) ).toContain(
			'attributes[stage]=sprout'
		);
		// The wrapper carries the supports, so the preview never doubles them.
		expect( decodeURIComponent( requests[ 0 ].path ) ).not.toMatch(
			/backgroundColor|padding/
		);
		expect( container.querySelector( 'h2' ).textContent ).toBe( 'Oca' );
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
