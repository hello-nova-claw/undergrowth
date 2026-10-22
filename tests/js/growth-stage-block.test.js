import { afterEach, beforeAll, describe, expect, test } from '@jest/globals';
import apiFetch from '@wordpress/api-fetch';
import { getBlockType } from '@wordpress/blocks';
import { createRoot } from '@wordpress/element';

import metadata from '../../src/blocks/growth-stage/block.json';
import Edit from '../../src/blocks/growth-stage/edit';
import '../../src/blocks/growth-stage/index';

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
 * @param {Object} context Block context.
 * @return {HTMLElement} Container.
 */
function renderEdit( context ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	createRoot( container ).render(
		<Edit context={ context } attributes={ {} } />
	);

	return container;
}

describe( 'Growth stage block', () => {
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
		const type = getBlockType( 'undergrowth/growth-stage' );

		expect( type.title ).toBe( metadata.title );
		expect( type.usesContext ).toEqual( [ 'postId', 'postType' ] );
	} );

	test( 'previews the server render of its own post', async () => {
		reply = {
			rendered:
				'<p class="wp-block-undergrowth-growth-stage is-stage-sprout"><span class="screen-reader-text">Growth stage: Sprout</span><span aria-hidden="true">Sprout</span></p>',
		};

		const container = renderEdit( { postId: 42, postType: 'post' } );

		await waitFor( () => container.querySelector( '.is-stage-sprout' ) );

		expect( requests ).toHaveLength( 1 );
		expect( requests[ 0 ].path ).toMatch(
			/^\/wp\/v2\/block-renderer\/undergrowth\/growth-stage\?/
		);
		expect( requests[ 0 ].path ).toContain( 'post_id=42' );
		expect( container.textContent ).toContain( 'Growth stage: Sprout' );
	} );

	test( 'explains an empty render instead of showing nothing', async () => {
		reply = { rendered: '' };

		const container = renderEdit( { postId: 7, postType: 'post' } );

		await waitFor( () => requests.length && container.textContent );

		expect( container.textContent ).toContain(
			'Readers see no growth stage here.'
		);
		// A note from the block, in core's Placeholder, not text in the post.
		expect(
			container.querySelector( '.components-placeholder' ).textContent
		).toContain( 'Readers see no growth stage here.' );
		expect(
			container.querySelector( '.components-placeholder__label' )
				.textContent
		).toContain( 'Growth stage' );
		expect( container.querySelector( 'p:not([class])' ) ).toBeNull();
	} );

	test( 'asks the server for nothing outside a post', async () => {
		const container = renderEdit( {} );

		await waitFor( () => container.textContent );

		expect( requests ).toHaveLength( 0 );
		expect( container.textContent ).toContain(
			'Shows the growth stage of the post it is placed in.'
		);
		expect(
			container.querySelector( '.components-placeholder' ).textContent
		).toContain( 'Shows the growth stage of the post it is placed in.' );
	} );
} );
