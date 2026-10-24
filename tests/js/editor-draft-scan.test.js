import { afterEach, beforeAll, describe, expect, test } from '@jest/globals';
import apiFetch from '@wordpress/api-fetch';
import { createRoot, flushSync } from '@wordpress/element';

import DraftScan from '../../src/editor/draft-scan';

const SENTENCE = 'I keep the seed ledger by the door.';
const URL = 'https://example.org/seed-ledger/';
const DESTINATION = 'Sends this draft’s title and text to OpenAI once.';
const DRAFT = {
	title: 'Planting notes',
	content: `<!-- wp:paragraph --><p>${ SENTENCE }</p><!-- /wp:paragraph -->`,
	password: '',
};

const requests = [];
let replies = [];

/**
 * Waits until a condition holds, for the requests the scan makes.
 *
 * @param {() => unknown} condition Returns something truthy once done.
 */
async function waitFor( condition ) {
	for ( let tries = 0; tries < 100 && ! condition(); tries++ ) {
		await new Promise( ( resolve ) => setTimeout( resolve, 10 ) );
	}
}

/**
 * Finds a button by its visible text.
 *
 * @param {HTMLElement} container Where to look.
 * @param {string}      text      Button text.
 * @return {HTMLButtonElement|undefined} Button.
 */
function button( container, text ) {
	return [ ...container.querySelectorAll( 'button' ) ].find(
		( element ) => element.textContent === text
	);
}

/**
 * Renders the scan into the document and waits for its description.
 *
 * @param {Object} props Component props.
 * @return {Promise<{container: HTMLElement, root: Object, linked: Array}>} Container, root and the paragraphs it changed.
 */
async function renderScan( props = {} ) {
	const container = document.createElement( 'div' );
	const root = createRoot( container );
	const linked = [];
	document.body.appendChild( container );
	flushSync( () =>
		root.render(
			<DraftScan
				postId={ 42 }
				getDraft={ () => DRAFT }
				paragraphs={ [ { id: 'p1', content: SENTENCE } ] }
				onLink={ ( id, content ) => linked.push( [ id, content ] ) }
				{ ...props }
			/>
		)
	);
	await waitFor( () => container.querySelector( '.undergrowth-scan' ) );

	return { container, root, linked };
}

/**
 * Builds the scan's answer with one suggestion.
 *
 * @return {Object} Answer.
 */
function scanned() {
	return {
		outgoing: [
			{
				target: 7,
				title: 'Seed ledger',
				url: URL,
				sentence: SENTENCE,
				span: { offset: 11, length: 11 },
			},
		],
	};
}

describe( 'Scan this draft', () => {
	beforeAll( () => {
		apiFetch.use( ( options ) => {
			requests.push( options );
			const next = replies.shift();

			return next instanceof Error
				? Promise.reject( next )
				: Promise.resolve( next );
		} );
	} );

	afterEach( () => {
		requests.length = 0;
		replies = [];
		document.body.innerHTML = '';
	} );

	test( 'names where the draft goes before anything is sent', async () => {
		replies = [
			{ available: true, destination: DESTINATION, unavailable: '' },
		];

		const { container } = await renderScan();
		const scan = button( container, 'Scan this draft' );

		expect( requests ).toHaveLength( 1 );
		expect( requests[ 0 ].path ).toBe( '/undergrowth/v1/posts/42/scan' );
		expect( requests[ 0 ].method ).toBeUndefined();
		expect(
			document.getElementById( scan.getAttribute( 'aria-describedby' ) )
				.textContent
		).toBe( DESTINATION );
	} );

	test( 'says why there is nothing to scan, with no button', async () => {
		replies = [
			{
				available: false,
				destination: '',
				unavailable:
					'Scanning a draft needs something to read it with.',
			},
		];

		const { container } = await renderScan();

		expect( container.textContent ).toContain(
			'Scanning a draft needs something to read it with.'
		);
		expect( button( container, 'Scan this draft' ) ).toBeUndefined();
	} );

	test( 'scans the draft as the editor holds it, on a click', async () => {
		replies = [
			{ available: true, destination: DESTINATION, unavailable: '' },
			scanned(),
		];

		const { container } = await renderScan();

		button( container, 'Scan this draft' ).click();
		await waitFor( () => container.querySelector( 'mark' ) );

		expect( requests[ 1 ] ).toMatchObject( {
			path: '/undergrowth/v1/posts/42/scan',
			method: 'POST',
			data: DRAFT,
		} );
		expect( container.querySelector( 'mark' ).textContent ).toBe(
			'seed ledger'
		);
		expect(
			container.querySelector( `a[href="${ URL }"]` ).textContent
		).toContain( 'Seed ledger' );
		// Focus goes to the results, so a screen reader hears them.
		expect( document.activeElement ).toBe(
			container.querySelector( '.undergrowth-scan-results' )
		);
	} );

	test( 'accepts by putting the linked paragraph back in the editor', async () => {
		const linkedHtml = `I keep the <a href="${ URL }">seed ledger</a> by the door.`;
		replies = [
			{ available: true, destination: DESTINATION, unavailable: '' },
			scanned(),
			{ id: 'p1', content: linkedHtml, message: 'Linked.' },
		];

		const { container, linked } = await renderScan();

		button( container, 'Scan this draft' ).click();
		await waitFor( () => button( container, 'Accept' ) );
		button( container, 'Accept' ).click();
		await waitFor( () => linked.length );

		expect( requests[ 2 ] ).toMatchObject( {
			path: '/undergrowth/v1/posts/42/scan/accept',
			method: 'POST',
			data: {
				target: 7,
				sentence: SENTENCE,
				offset: 11,
				length: 11,
				paragraphs: [ { id: 'p1', content: SENTENCE } ],
			},
		} );
		expect( linked ).toEqual( [ [ 'p1', linkedHtml ] ] );
	} );

	test( 'names the target in Accept, for screen readers', async () => {
		replies = [
			{ available: true, destination: DESTINATION, unavailable: '' },
			scanned(),
		];

		const { container } = await renderScan();

		button( container, 'Scan this draft' ).click();
		await waitFor( () => button( container, 'Accept' ) );

		expect(
			button( container, 'Accept' ).getAttribute( 'aria-label' )
		).toContain( 'Seed ledger' );
	} );

	test( 'moves focus to the note that replaces the pressed Accept', async () => {
		const linkedHtml = `I keep the <a href="${ URL }">seed ledger</a> by the door.`;
		replies = [
			{ available: true, destination: DESTINATION, unavailable: '' },
			scanned(),
			{ id: 'p1', content: linkedHtml, message: 'Linked, scan focus.' },
		];

		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );
		const render = ( content ) =>
			root.render(
				<DraftScan
					postId={ 42 }
					getDraft={ () => DRAFT }
					paragraphs={ [ { id: 'p1', content } ] }
					onLink={ ( id, html ) => render( html ) }
				/>
			);

		flushSync( () => render( SENTENCE ) );
		await waitFor( () => button( container, 'Scan this draft' ) );
		button( container, 'Scan this draft' ).click();
		await waitFor( () => button( container, 'Accept' ) );
		button( container, 'Accept' ).click();
		await waitFor( () => ! button( container, 'Accept' ) );
		await new Promise( ( resolve ) => setTimeout( resolve, 10 ) );

		expect( document.activeElement ).toBe(
			container.querySelector( '.undergrowth-suggestion .description' )
		);
	} );

	test( 'says when nothing is close enough', async () => {
		replies = [
			{ available: true, destination: DESTINATION, unavailable: '' },
			{ outgoing: [] },
		];

		const { container } = await renderScan();

		button( container, 'Scan this draft' ).click();
		await waitFor( () =>
			container.querySelector( '.undergrowth-scan-results' )
		);

		expect( container.textContent ).toContain(
			'No published post is close enough to suggest a link.'
		);
	} );

	test( 'shows a refusal and lets the author try again', async () => {
		replies = [
			{ available: true, destination: DESTINATION, unavailable: '' },
			Object.assign( new Error( 'A scan has just run.' ), {
				code: 'undergrowth_scan_wait',
			} ),
		];

		const { container } = await renderScan();

		button( container, 'Scan this draft' ).click();
		await waitFor( () => container.querySelector( '.components-notice' ) );

		expect(
			container.querySelector( '.components-notice' ).textContent
		).toContain( 'A scan has just run.' );
		expect(
			button( container, 'Scan this draft' ).getAttribute(
				'aria-disabled'
			)
		).not.toBe( 'true' );
	} );

	test( 'keeps nothing once the sidebar closes', async () => {
		replies = [
			{ available: true, destination: DESTINATION, unavailable: '' },
			scanned(),
			{ available: true, destination: DESTINATION, unavailable: '' },
		];

		const first = await renderScan();

		button( first.container, 'Scan this draft' ).click();
		await waitFor( () => first.container.querySelector( 'mark' ) );
		first.root.unmount();

		const second = await renderScan();

		expect( second.container.querySelector( 'mark' ) ).toBeNull();
		expect( requests.map( ( request ) => request.method ) ).toEqual( [
			undefined,
			'POST',
			undefined,
		] );
	} );
} );
