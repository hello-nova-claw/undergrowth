import { afterEach, beforeAll, describe, expect, test } from '@jest/globals';
import apiFetch from '@wordpress/api-fetch';
import { getBlockType } from '@wordpress/blocks';
import { createRoot } from '@wordpress/element';

import metadata from '../../src/blocks/linked-from/block.json';
import Edit from '../../src/blocks/linked-from/edit';
import '../../src/blocks/linked-from/index';

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
 * @param {Object} context    Block context.
 * @param {Object} attributes Block attributes.
 * @return {HTMLElement} Container.
 */
function renderEdit( context, attributes = { heading: '', maxItems: 5 } ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	createRoot( container ).render(
		<Edit
			context={ context }
			attributes={ attributes }
			setAttributes={ () => {} }
		/>
	);

	return container;
}

describe( 'Linked from block', () => {
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
		const type = getBlockType( 'undergrowth/linked-from' );

		expect( type.title ).toBe( metadata.title );
		expect( type.usesContext ).toEqual( [ 'postId', 'postType' ] );
		expect( type.attributes.maxItems.default ).toBe( 5 );
		expect( type.attributes.heading.default ).toBe( '' );
	} );

	test( 'previews the server render of its own post with its settings', async () => {
		reply = {
			rendered:
				'<div class="wp-block-undergrowth-linked-from"><h2 class="wp-block-heading">Paths here</h2><ul><li><a href="/?p=3">Yacon</a></li></ul></div>',
		};

		const container = renderEdit(
			{ postId: 42, postType: 'post' },
			{
				heading: 'Paths here',
				maxItems: 3,
				backgroundColor: 'accent',
				style: { spacing: { padding: { top: '2em' } } },
			}
		);

		await waitFor( () => container.querySelector( 'li a' ) );

		expect( requests ).toHaveLength( 1 );
		expect( requests[ 0 ].path ).toMatch(
			/^\/wp\/v2\/block-renderer\/undergrowth\/linked-from\?/
		);
		expect( requests[ 0 ].path ).toContain( 'post_id=42' );
		expect( decodeURIComponent( requests[ 0 ].path ) ).toContain(
			'attributes[maxItems]=3'
		);
		// The wrapper carries the supports, so the preview never doubles them.
		expect( decodeURIComponent( requests[ 0 ].path ) ).not.toMatch(
			/backgroundColor|padding/
		);
		expect( container.querySelector( 'h2' ).textContent ).toBe(
			'Paths here'
		);
	} );

	test( 'explains an empty render instead of showing nothing', async () => {
		reply = { rendered: '' };

		const container = renderEdit( { postId: 7, postType: 'post' } );

		await waitFor( () => requests.length && container.textContent );

		// A note from the block, in core's Placeholder, not text in the post.
		expect(
			container.querySelector( '.components-placeholder' ).textContent
		).toContain( 'Readers see nothing here yet.' );
		expect(
			container.querySelector( '.components-placeholder__label' )
				.textContent
		).toContain( 'Linked from' );
		expect( container.querySelector( 'ul' ) ).toBeNull();
	} );

	test( 'asks the server for nothing outside a post', async () => {
		const container = renderEdit( {} );

		await waitFor( () => container.textContent );

		expect( requests ).toHaveLength( 0 );
		expect(
			container.querySelector( '.components-placeholder' ).textContent
		).toContain( 'Lists the posts that link to the post it is placed in.' );
	} );
} );
