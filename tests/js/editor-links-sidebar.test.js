import { afterEach, beforeAll, describe, expect, test } from '@jest/globals';
import apiFetch from '@wordpress/api-fetch';
import { createRoot, flushSync } from '@wordpress/element';

import LinksSidebar, { editorParagraphs } from '../../src/editor/links-sidebar';

const SENTENCE = 'I keep yacon syrup beside the seed ledger.';
const URL = 'https://example.org/seed-ledger/';

const requests = [];
let replies = [];

/**
 * Builds the route's answer with one suggestion from this post.
 *
 * @param {Object} extra Fields to add to the answer.
 * @return {Object} Answer.
 */
function suggestions( extra = {} ) {
	return {
		outgoing: [
			{
				target: 7,
				title: 'Seed ledger',
				url: URL,
				sentence: SENTENCE,
				span: { offset: 30, length: 11 },
				words: [
					'I',
					'keep',
					'yacon',
					'syrup',
					'beside',
					'the',
					'seed',
					'ledger',
				],
				first: 6,
				last: 7,
				version: 'v1',
			},
		],
		incoming: [],
		...extra,
	};
}

/**
 * Waits until a condition holds, for the requests the sidebar makes.
 *
 * @param {() => unknown} condition Returns something truthy once done.
 */
async function waitFor( condition ) {
	for ( let tries = 0; tries < 100 && ! condition(); tries++ ) {
		await new Promise( ( resolve ) => setTimeout( resolve, 10 ) );
	}
}

/**
 * Renders the sidebar into the document and waits for its first answer.
 *
 * @param {Object} props Sidebar props.
 * @return {Promise<{container: HTMLElement, linked: Array}>} Container, and the paragraphs it changed.
 */
async function renderSidebar( props = {} ) {
	const container = document.createElement( 'div' );
	const linked = [];
	document.body.appendChild( container );
	flushSync( () =>
		createRoot( container ).render(
			<LinksSidebar
				postId={ 42 }
				saved="1"
				paragraphs={ [ { id: 'p1', content: SENTENCE } ] }
				onLink={ ( id, content ) => linked.push( [ id, content ] ) }
				{ ...props }
			/>
		)
	);
	await waitFor( () =>
		container.querySelector( '.undergrowth-links-loaded' )
	);

	return { container, linked };
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

describe( 'Editor links sidebar', () => {
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

	test( 'marks the stored words in the sentence and names the target', async () => {
		replies = [ suggestions() ];

		const { container } = await renderSidebar();

		expect( requests[ 0 ].path ).toBe( '/undergrowth/v1/posts/42/links' );
		expect( container.querySelector( 'mark' ).textContent ).toBe(
			'seed ledger'
		);
		expect(
			container.querySelector( '.undergrowth-suggestion blockquote' )
				.textContent
		).toBe( SENTENCE );
		expect(
			container.querySelector( `a[href="${ URL }"]` ).textContent
		).toContain( 'Seed ledger' );
		expect( button( container, 'Accept' ) ).toBeDefined();
		expect( button( container, 'Veto' ) ).toBeDefined();
	} );

	test( 'accepts by putting the linked paragraph back in the editor', async () => {
		const linkedHtml = `I keep yacon syrup beside the <a href="${ URL }">seed ledger</a>.`;
		replies = [
			suggestions(),
			{ id: 'p1', content: linkedHtml, message: 'Linked.' },
		];

		const { container, linked } = await renderSidebar();

		button( container, 'Accept' ).click();
		await waitFor( () => linked.length );

		expect( requests[ 1 ] ).toMatchObject( {
			path: '/undergrowth/v1/posts/42/links/7/accept',
			method: 'POST',
			data: { paragraphs: [ { id: 'p1', content: SENTENCE } ] },
		} );
		expect( linked ).toEqual( [ [ 'p1', linkedHtml ] ] );
	} );

	test( 'says a linked suggestion is kept on save, and offers nothing', async () => {
		replies = [ suggestions() ];

		const { container } = await renderSidebar( {
			paragraphs: [
				{
					id: 'p1',
					content: `I keep yacon syrup beside the <a href="${ URL }">seed ledger</a>.`,
				},
			],
		} );

		expect( container.textContent ).toContain( 'when you save the post' );
		expect( button( container, 'Accept' ) ).toBeUndefined();
		expect( button( container, 'Veto' ) ).toBeUndefined();
	} );

	test( 'says when the sentence has changed, and offers nothing', async () => {
		replies = [ suggestions() ];

		const { container } = await renderSidebar( {
			paragraphs: [ { id: 'p1', content: 'I rewrote all of it.' } ],
		} );

		expect( container.textContent ).toContain(
			'That sentence has changed.'
		);
		expect( button( container, 'Accept' ) ).toBeUndefined();
		expect( button( container, 'Veto' ) ).toBeUndefined();
	} );

	test( 'a suggestion with no words to link offers nothing either', async () => {
		replies = [
			suggestions( {
				outgoing: [ { ...suggestions().outgoing[ 0 ], span: null } ],
			} ),
		];

		const { container } = await renderSidebar();

		expect( container.textContent ).toContain(
			'That sentence has changed.'
		);
		expect( button( container, 'Accept' ) ).toBeUndefined();
	} );

	test( 'shows a refusal in the suggestion and links nothing', async () => {
		replies = [
			suggestions(),
			Object.assign( new Error( 'No words could be linked.' ), {
				code: 'undergrowth_no_word',
			} ),
		];

		const { container, linked } = await renderSidebar();

		button( container, 'Accept' ).click();
		await waitFor( () => container.querySelector( '.components-notice' ) );

		expect(
			container.querySelector( '.components-notice' ).textContent
		).toContain( 'No words could be linked.' );
		expect( linked ).toEqual( [] );
	} );

	test( 'vetoes and drops the suggestion, moving focus to its list', async () => {
		replies = [ suggestions(), { message: 'Done.' } ];

		const { container } = await renderSidebar();

		button( container, 'Veto' ).click();
		await waitFor(
			() => ! container.querySelector( '.undergrowth-suggestion' )
		);

		expect( requests[ 1 ] ).toMatchObject( {
			path: '/undergrowth/v1/posts/42/links/7/veto',
			method: 'POST',
		} );
		expect( document.activeElement ).toBe(
			container.querySelector( '.undergrowth-outgoing' )
		);
	} );

	test( 'chooses other words of the sentence, as the Suggestions tab does', async () => {
		replies = [
			suggestions(),
			{
				...suggestions().outgoing[ 0 ],
				span: { offset: 7, length: 11 },
				first: 2,
				last: 3,
				message: 'Saved.',
			},
		];

		const { container } = await renderSidebar();

		button( container, 'Choose words' ).click();
		await waitFor( () => container.querySelectorAll( 'select' ).length );

		const [ first, last ] = container.querySelectorAll( 'select' );

		// The form opens on the words now marked, and takes focus.
		expect( first.value ).toBe( '6' );
		expect( last.value ).toBe( '7' );
		expect( document.activeElement ).toBe( first );
		expect( first.options[ 2 ].textContent ).toBe( 'yacon (3 of 8)' );

		const choose = ( select, value ) =>
			flushSync( () => {
				Object.getOwnPropertyDescriptor(
					window.HTMLSelectElement.prototype,
					'value'
				).set.call( select, value );
				select.dispatchEvent(
					new Event( 'change', { bubbles: true } )
				);
			} );

		choose( first, '2' );
		choose( last, '3' );
		button( container, 'Save words' ).click();
		await waitFor( () => ! container.querySelector( 'select' ) );

		expect( requests[ 1 ] ).toMatchObject( {
			path: '/undergrowth/v1/posts/42/links/7/words',
			method: 'POST',
			data: { first: 2, last: 3, shown: 'v1' },
		} );
		expect( container.querySelector( 'mark' ).textContent ).toBe(
			'yacon syrup'
		);
		expect( document.activeElement ).toBe(
			button( container, 'Choose words' )
		);
	} );

	test( 'cancels choosing words without asking the server', async () => {
		replies = [ suggestions() ];

		const { container } = await renderSidebar();

		button( container, 'Choose words' ).click();
		await waitFor( () => button( container, 'Cancel' ) );
		button( container, 'Cancel' ).click();
		await waitFor( () => ! container.querySelector( 'select' ) );

		expect( requests ).toHaveLength( 1 );
		expect( document.activeElement ).toBe(
			button( container, 'Choose words' )
		);
	} );

	test( 'offers no word choice where the words cannot be told apart', async () => {
		replies = [
			suggestions( {
				outgoing: [ { ...suggestions().outgoing[ 0 ], words: [] } ],
			} ),
		];

		const { container } = await renderSidebar();

		expect( button( container, 'Accept' ) ).toBeDefined();
		expect( button( container, 'Choose words' ) ).toBeUndefined();
	} );

	test( 'links to the posts that could link here, with no Accept', async () => {
		replies = [
			suggestions( {
				outgoing: [],
				incoming: [
					{
						source: 3,
						title: 'Oca & mashua',
						url: 'https://example.org/oca/',
						edit_url:
							'https://example.org/wp-admin/post.php?post=3',
					},
					{
						source: 4,
						title: 'Not yours',
						url: 'https://example.org/not-yours/',
						edit_url: null,
					},
				],
			} ),
		];

		const { container } = await renderSidebar();
		const links = container.querySelectorAll( '.undergrowth-incoming a' );

		expect( links[ 0 ].getAttribute( 'href' ) ).toBe(
			'https://example.org/wp-admin/post.php?post=3'
		);
		expect( links[ 0 ].textContent ).toBe( 'Oca & mashua' );
		expect( links[ 1 ].getAttribute( 'href' ) ).toBe(
			'https://example.org/not-yours/'
		);
		expect( button( container, 'Accept' ) ).toBeUndefined();
		expect( container.textContent ).toContain(
			'No links are suggested from this post.'
		);
	} );

	test( 'asks again after each save', async () => {
		replies = [ suggestions(), suggestions( { outgoing: [] } ) ];

		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );
		const render = ( saved ) =>
			flushSync( () =>
				root.render(
					<LinksSidebar
						postId={ 42 }
						saved={ saved }
						paragraphs={ [] }
						onLink={ () => {} }
					/>
				)
			);

		render( '1' );
		await waitFor( () => requests.length === 1 );
		render( '2' );
		await waitFor( () =>
			container.textContent.includes(
				'No links are suggested from this post.'
			)
		);

		expect( requests ).toHaveLength( 2 );
	} );
} );

describe( 'Paragraphs a link may go in', () => {
	test( 'are paragraph blocks, but none inside a quote', () => {
		const blocks = [
			{
				clientId: 'a',
				name: 'core/paragraph',
				attributes: { content: 'Mine.' },
				innerBlocks: [],
			},
			{
				clientId: 'b',
				name: 'core/heading',
				attributes: { content: 'Heading' },
				innerBlocks: [],
			},
			{
				clientId: 'c',
				name: 'core/quote',
				attributes: {},
				innerBlocks: [
					{
						clientId: 'd',
						name: 'core/paragraph',
						attributes: { content: 'Theirs.' },
						innerBlocks: [],
					},
				],
			},
			{
				clientId: 'e',
				name: 'core/group',
				attributes: {},
				innerBlocks: [
					{
						clientId: 'f',
						name: 'core/paragraph',
						attributes: {
							content: {
								toHTMLString: () => 'Also <em>mine</em>.',
							},
						},
						innerBlocks: [],
					},
				],
			},
			{
				clientId: 'g',
				name: 'core/freeform',
				attributes: { content: '<p>Classic.</p>' },
				innerBlocks: [],
			},
		];

		expect( editorParagraphs( blocks ) ).toEqual( [
			{ id: 'a', content: 'Mine.' },
			{ id: 'f', content: 'Also <em>mine</em>.' },
		] );
	} );
} );
