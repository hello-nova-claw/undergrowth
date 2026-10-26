import { afterEach, beforeAll, describe, expect, test } from '@jest/globals';
import apiFetch from '@wordpress/api-fetch';
import { getBlockType } from '@wordpress/blocks';
import { createRoot } from '@wordpress/element';

import metadata from '../../src/blocks/garden-filters/block.json';
import Edit from '../../src/blocks/garden-filters/edit';
import '../../src/blocks/garden-filters/index';
import { formAddress } from '../../src/blocks/garden-filters/form-address';

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

describe( 'Garden filters block', () => {
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
		const type = getBlockType( 'undergrowth/garden-filters' );

		expect( type.title ).toBe( metadata.title );
		expect( type.attributes.topicSource.default ).toBe( 'post_tag' );
		// The types follow the garden feed on the same page.
		expect( type.attributes.typeSource ).toBeUndefined();
		expect( type.supports.interactivity ).toBe( true );
	} );

	test( 'previews the server render with the chosen settings', async () => {
		reply = {
			rendered:
				'<div class="wp-block-undergrowth-garden-filters"><nav><ul><li><a href="/?topic=roots">Roots</a></li></ul></nav></div>',
		};

		const container = renderEdit( {
			...DEFAULTS,
			topicSource: 'category',
			showType: false,
			backgroundColor: 'accent',
			style: { spacing: { padding: { top: '2em' } } },
		} );

		await waitFor( () => container.querySelector( 'li a' ) );

		expect( requests ).toHaveLength( 1 );
		const path = decodeURIComponent( requests[ 0 ].path );
		expect( path ).toMatch(
			/^\/wp\/v2\/block-renderer\/undergrowth\/garden-filters\?/
		);
		expect( path ).toContain( 'attributes[topicSource]=category' );
		expect( path ).toContain( 'attributes[showType]=false' );
		// The wrapper carries the supports, so the preview never doubles them.
		expect( path ).not.toMatch( /backgroundColor|padding/ );
		expect( container.querySelector( 'li a' ).textContent ).toBe( 'Roots' );
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

	test( 'sends the same address as the form would, without empty filters', () => {
		document.body.innerHTML = `<form action="/garden/" method="get">
			<input type="hidden" name="page_id" value="7">
			<input type="hidden" name="topic" value="roots">
			<select name="stage"><option value="">All</option><option value="sprout" selected>Sprout</option></select>
			<select name="type"><option value="" selected>All</option></select>
		</form>`;

		expect( formAddress( document.querySelector( 'form' ) ) ).toBe(
			'http://localhost/garden/?page_id=7&topic=roots&stage=sprout'
		);
	} );

	test( "keeps the form's address when a query arg is named action", () => {
		// The page's own query args come back as hidden fields, and a field
		// named action shadows the form's action property.
		document.body.innerHTML = `<form action="/garden/" method="get">
			<input type="hidden" name="action" value="x">
			<select name="stage"><option value="sprout" selected>Sprout</option></select>
		</form>`;

		expect( formAddress( document.querySelector( 'form' ) ) ).toBe(
			'http://localhost/garden/?action=x&stage=sprout'
		);
	} );
} );
