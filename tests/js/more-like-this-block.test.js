import { afterEach, beforeAll, describe, expect, test } from '@jest/globals';
import apiFetch from '@wordpress/api-fetch';
import { getBlockType } from '@wordpress/blocks';
import { createRoot, flushSync } from '@wordpress/element';

import metadata from '../../src/blocks/more-like-this/block.json';
import Edit, { ListPanel } from '../../src/blocks/more-like-this/edit';
import '../../src/blocks/more-like-this/index';

const requests = [];
let replies = {};

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
 * Renders an element into the document.
 *
 * @param {Element} element What to render.
 * @return {HTMLElement} Container.
 */
function mount( element ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	flushSync( () => root.render( element ) );

	return container;
}

const ATTRIBUTES = { postsToShow: 3, showImage: false, showExcerpt: false };

describe( 'More like this block', () => {
	beforeAll( () => {
		// Core's Placeholder sizes itself with ResizeObserver, which jsdom lacks.
		global.ResizeObserver ??= class {
			observe() {}
			unobserve() {}
			disconnect() {}
		};

		// Answers as WordPress would, by the start of the path, so no server
		// is needed.
		apiFetch.use( ( options ) => {
			requests.push( options );

			// The longest matching start wins, so a route can answer apart
			// from the one it extends.
			const key = Object.keys( replies )
				.filter( ( start ) => options.path.startsWith( start ) )
				.sort( ( a, b ) => b.length - a.length )[ 0 ];

			return replies[ key ] instanceof Error
				? Promise.reject( replies[ key ] )
				: Promise.resolve( replies[ key ] ?? {} );
		} );
	} );

	afterEach( () => {
		requests.length = 0;
		replies = {};
		document.body.innerHTML = '';
	} );

	test( 'is registered from block.json, never as "Related by readers"', () => {
		const type = getBlockType( 'undergrowth/more-like-this' );

		expect( type.title ).toBe( metadata.title );
		expect( type.title ).toBe( 'More like this' );
		expect( JSON.stringify( metadata ) ).not.toMatch( /by readers/i );
		expect( type.usesContext ).toEqual( [ 'postId', 'postType' ] );
		expect( type.attributes.postsToShow.default ).toBe( 3 );
	} );

	test( 'previews the server render of its own post', async () => {
		replies[ '/wp/v2/block-renderer/' ] = {
			rendered:
				'<nav class="wp-block-undergrowth-more-like-this" aria-label="More like this"><ul><li><a href="#">Yacon</a></li></ul></nav>',
		};

		const container = mount(
			<Edit
				attributes={ {
					...ATTRIBUTES,
					backgroundColor: 'accent',
					style: { spacing: { padding: { top: '2em' } } },
				} }
				setAttributes={ () => {} }
				context={ { postId: 42, postType: 'post' } }
			/>
		);

		await waitFor( () => container.querySelector( 'nav' ) );

		const render = requests.find( ( request ) =>
			request.path.startsWith( '/wp/v2/block-renderer/' )
		);
		expect( render.path ).toMatch(
			/^\/wp\/v2\/block-renderer\/undergrowth\/more-like-this\?/
		);
		expect( render.path ).toContain( 'post_id=42' );
		// The wrapper carries the supports, so the preview never doubles them.
		expect( decodeURIComponent( render.path ) ).toContain(
			'attributes[postsToShow]=3'
		);
		expect( decodeURIComponent( render.path ) ).not.toMatch(
			/backgroundColor|padding/
		);
		expect( container.textContent ).toContain( 'Yacon' );
	} );

	test( 'explains an empty render instead of showing nothing', async () => {
		replies[ '/wp/v2/block-renderer/' ] = { rendered: '' };

		const container = mount(
			<Edit
				attributes={ ATTRIBUTES }
				setAttributes={ () => {} }
				context={ { postId: 7, postType: 'post' } }
			/>
		);

		await waitFor( () =>
			container.querySelector( '.components-placeholder' )
		);

		expect(
			container.querySelector( '.components-placeholder' ).textContent
		).toContain( 'Readers see nothing here yet.' );
	} );

	test( 'asks the server for nothing outside a post', async () => {
		const container = mount(
			<Edit
				attributes={ ATTRIBUTES }
				setAttributes={ () => {} }
				context={ {} }
			/>
		);

		await waitFor( () => container.textContent );

		expect( requests ).toHaveLength( 0 );
		expect( container.textContent ).toContain(
			'Lists the posts closest in meaning to the post it is placed in.'
		);
	} );

	test( 'lists the shown posts with Hide, and the hidden ones with Show again', async () => {
		const path = '/undergrowth/v1/posts/42/more-like-this';
		replies[ path ] = {
			shown: [ { id: 5, title: 'Yacon', link: '#' } ],
			hidden: [ { id: 6, title: 'Mashua', link: '#' } ],
		};
		let changes = 0;

		const container = mount(
			<ListPanel postId={ 42 } count={ 2 } onChange={ () => changes++ } />
		);

		await waitFor( () => container.querySelector( 'button' ) );

		expect( requests[ 0 ].path ).toBe( `${ path }?count=2` );

		const [ hide, show ] = container.querySelectorAll( 'button' );
		expect( hide.getAttribute( 'aria-label' ) ).toBe(
			'Hide “Yacon” from this list'
		);
		expect( show.getAttribute( 'aria-label' ) ).toBe(
			'Show “Mashua” on this list again'
		);

		hide.click();
		await waitFor( () => changes === 1 );

		expect( requests[ 1 ] ).toMatchObject( {
			path: `${ path }/hidden/5`,
			method: 'POST',
		} );
		// The list is read again once the post is hidden.
		await waitFor( () => requests.length === 3 );
		expect( requests[ 2 ].path ).toBe( `${ path }?count=2` );

		container.querySelectorAll( 'button' )[ 1 ].click();
		await waitFor( () => changes === 2 );

		expect( requests[ 3 ] ).toMatchObject( {
			path: `${ path }/hidden/6`,
			method: 'DELETE',
		} );
	} );

	test( 'keeps the list on screen when a Hide fails, so it can be tried again', async () => {
		const path = '/undergrowth/v1/posts/42/more-like-this';
		replies[ path ] = {
			shown: [ { id: 5, title: 'Yacon', link: '#' } ],
			hidden: [],
		};
		replies[ `${ path }/hidden/5` ] = new Error( 'Not now.' );

		const container = mount(
			<ListPanel postId={ 42 } count={ 3 } onChange={ () => {} } />
		);

		await waitFor( () => container.querySelector( 'button' ) );
		container.querySelector( 'button' ).click();
		await waitFor( () => container.querySelector( '.components-notice' ) );

		expect( container.textContent ).toContain( 'Not now.' );
		expect(
			container.querySelector( '.undergrowth-more-like-this-hide' )
		).not.toBeNull();
	} );

	test( 'says when the list is empty, and why a request failed', async () => {
		replies[ '/undergrowth/v1/posts/1/more-like-this' ] = {
			shown: [],
			hidden: [],
		};
		replies[ '/undergrowth/v1/posts/2/more-like-this' ] = new Error();

		const empty = mount(
			<ListPanel postId={ 1 } count={ 3 } onChange={ () => {} } />
		);
		await waitFor( () => empty.textContent );
		expect( empty.textContent ).toContain(
			'No posts are on this list yet.'
		);

		const failed = mount(
			<ListPanel postId={ 2 } count={ 3 } onChange={ () => {} } />
		);
		await waitFor( () => failed.querySelector( '.components-notice' ) );
		expect( failed.textContent ).toContain(
			'Undergrowth could not reach your site.'
		);
	} );
} );
