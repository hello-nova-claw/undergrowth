import { afterEach, beforeAll, describe, expect, test } from '@jest/globals';
import apiFetch from '@wordpress/api-fetch';
import { getBlockType } from '@wordpress/blocks';
import { createRoot } from '@wordpress/element';

import metadata from '../../src/blocks/garden-count/block.json';
import Edit from '../../src/blocks/garden-count/edit';
import '../../src/blocks/garden-count/index';

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

describe( 'Garden count block', () => {
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
		const type = getBlockType( 'undergrowth/garden-count' );

		expect( type.title ).toBe( metadata.title );
		expect( type.attributes.showLabel.default ).toBe( true );
	} );

	test( 'previews the server render with the chosen settings', async () => {
		reply = {
			rendered:
				'<p class="wp-block-undergrowth-garden-count"><span class="wp-block-undergrowth-garden-count-number">12</span></p>',
		};

		const container = renderEdit( {
			...DEFAULTS,
			showLabel: false,
			backgroundColor: 'accent',
			style: { spacing: { padding: { top: '2em' } } },
		} );

		await waitFor( () => container.querySelector( 'p span' ) );

		expect( requests ).toHaveLength( 1 );
		const path = decodeURIComponent( requests[ 0 ].path );
		expect( path ).toMatch(
			/^\/wp\/v2\/block-renderer\/undergrowth\/garden-count\?/
		);
		expect( path ).toContain( 'attributes[showLabel]=false' );
		// The wrapper carries the supports, so the preview never doubles them.
		expect( path ).not.toMatch( /backgroundColor|padding/ );
		expect( container.querySelector( 'p span' ).textContent ).toBe( '12' );
	} );

	test( 'explains an empty render instead of showing nothing', async () => {
		reply = { rendered: '' };

		const container = renderEdit();

		await waitFor( () => requests.length && container.textContent );

		// A note from the block, in core's Placeholder, not text in the post.
		expect(
			container.querySelector( '.components-placeholder' ).textContent
		).toContain( 'Readers see nothing here yet.' );
		expect(
			container.querySelector( '.wp-block-undergrowth-garden-count' )
		).toBeNull();
	} );
} );
