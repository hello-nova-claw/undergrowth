import { afterEach, beforeAll, describe, expect, test } from '@jest/globals';
import apiFetch from '@wordpress/api-fetch';
import { select } from '@wordpress/data';
import { createRoot, flushSync } from '@wordpress/element';
import { store as noticesStore } from '@wordpress/notices';

import StagePanel from '../../src/editor/stage-panel';

const CHOICES = [
	{ value: 'seedling', label: 'Seedling' },
	{ value: 'sprout', label: 'Sprout' },
	{ value: 'budding', label: 'Budding' },
	{ value: 'evergreen', label: 'Evergreen' },
];

const requests = [];
let replies = [];

/**
 * Builds the route's answer for a post in the garden.
 *
 * @param {string}  stage  Stage slug.
 * @param {boolean} pinned Whether it is pinned.
 * @param {string}  label  Stage name.
 * @return {Object} Answer.
 */
function inGarden( stage, pinned = false, label = '' ) {
	return {
		in_garden: true,
		stage,
		label: label || stage[ 0 ].toUpperCase() + stage.slice( 1 ),
		pinned,
		choices: CHOICES,
	};
}

/**
 * Waits until a condition holds, for the requests the panel makes.
 *
 * @param {() => unknown} condition Returns something truthy once done.
 */
async function waitFor( condition ) {
	for ( let tries = 0; tries < 100 && ! condition(); tries++ ) {
		await new Promise( ( resolve ) => setTimeout( resolve, 10 ) );
	}
}

/**
 * Renders the panel into the document and waits for its first answer.
 *
 * @param {Object} props Panel props.
 * @return {Promise<HTMLElement>} Container.
 */
async function renderPanel( props = {} ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	flushSync( () =>
		createRoot( container ).render(
			<StagePanel postId={ 42 } status="publish" { ...props } />
		)
	);
	await waitFor( () => container.querySelector( '.undergrowth-stage' ) );

	return container;
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

describe( 'Editor stage panel', () => {
	beforeAll( () => {
		// Answers in order, as the route would, so no server is needed.
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

	test( 'shows the stage of the post it is editing', async () => {
		replies = [ inGarden( 'budding', true ) ];

		const container = await renderPanel();

		expect( requests[ 0 ].path ).toBe( '/undergrowth/v1/posts/42/stage' );
		expect(
			container.querySelector( '.undergrowth-stage' ).textContent
		).toBe( 'Growth stage: Budding, pinned' );
		expect( button( container, 'Unpin' ) ).toBeDefined();
		expect( button( container, 'Pin stage' ) ).toBeUndefined();
		expect( button( container, 'Compost' ) ).toBeDefined();
	} );

	test( 'says a draft gets a stage once published, and offers nothing', async () => {
		replies = [
			{ in_garden: false, stage: null, label: '', pinned: false },
		];

		const container = await renderPanel( { status: 'draft' } );

		expect( container.textContent ).toContain(
			'gets a growth stage once it is published'
		);
		expect( container.querySelectorAll( 'button' ) ).toHaveLength( 0 );
		expect( container.querySelector( 'select' ) ).toBeNull();
	} );

	test( 'pins the chosen stage and shows it', async () => {
		replies = [
			inGarden( 'seedling' ),
			{
				...inGarden( 'sprout', true ),
				message: 'Stage pinned.',
			},
		];

		const container = await renderPanel();
		const choice = container.querySelector( 'select' );

		expect( choice.value ).toBe( 'seedling' );
		expect(
			container.querySelector( `label[for="${ choice.id }"]` ).textContent
		).toBe( 'Stage to pin' );

		flushSync( () => {
			choice.value = 'sprout';
			choice.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
		button( container, 'Pin stage' ).click();

		await waitFor( () => button( container, 'Unpin' ) );

		expect( requests[ 1 ] ).toMatchObject( {
			path: '/undergrowth/v1/posts/42/stage',
			method: 'POST',
			data: { action: 'pin', stage: 'sprout' },
		} );
		expect(
			container.querySelector( '.undergrowth-stage' ).textContent
		).toBe( 'Growth stage: Sprout, pinned' );
		// The button that was pressed is gone, so focus goes to the result.
		expect( document.activeElement ).toBe(
			container.querySelector( '.undergrowth-stage' )
		);
		expect(
			select( noticesStore )
				.getNotices()
				.map( ( notice ) => notice.content )
		).toContain( 'Stage pinned.' );
	} );

	test( 'composts, then returns to the garden', async () => {
		replies = [
			inGarden( 'sprout' ),
			{ ...inGarden( 'composting' ), message: 'Moved.' },
			{ ...inGarden( 'seedling' ), message: 'Returned.' },
		];

		const container = await renderPanel();

		button( container, 'Compost' ).click();
		await waitFor( () => button( container, 'Return to garden' ) );

		expect( requests[ 1 ].data ).toEqual( { action: 'compost' } );
		// Composting is outside the pinning rules.
		expect( container.querySelector( 'select' ) ).toBeNull();
		expect( button( container, 'Unpin' ) ).toBeUndefined();

		button( container, 'Return to garden' ).click();
		await waitFor( () => button( container, 'Compost' ) );

		expect( requests[ 2 ].data ).toEqual( { action: 'return' } );
		expect(
			container.querySelector( '.undergrowth-stage' ).textContent
		).toBe( 'Growth stage: Seedling' );
	} );

	test( 'shows a refusal in the panel and keeps the stage', async () => {
		replies = [
			inGarden( 'sprout', true ),
			Object.assign( new Error( 'The stage could not be saved.' ), {
				code: 'undergrowth_not_saved',
			} ),
		];

		const container = await renderPanel();

		button( container, 'Unpin' ).click();
		await waitFor( () => container.querySelector( '.components-notice' ) );

		expect(
			container.querySelector( '.components-notice' ).textContent
		).toContain( 'The stage could not be saved.' );
		expect(
			container.querySelector( '.undergrowth-stage' ).textContent
		).toBe( 'Growth stage: Sprout, pinned' );
	} );

	test( 'asks again once the post is published', async () => {
		replies = [
			{ in_garden: false, stage: null, label: '', pinned: false },
			inGarden( 'seedling' ),
		];

		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );

		flushSync( () =>
			root.render( <StagePanel postId={ 42 } status="draft" /> )
		);
		await waitFor( () => requests.length === 1 );
		flushSync( () =>
			root.render( <StagePanel postId={ 42 } status="publish" /> )
		);
		await waitFor( () => button( container, 'Pin stage' ) );

		expect( requests ).toHaveLength( 2 );
	} );
} );
