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

	test( 'shows a Worth a look note and dismisses it', async () => {
		replies = [
			{
				...inGarden( 'evergreen' ),
				tend: 'Worth a look: you last edited this Evergreen post 2 years ago.',
			},
			{
				...inGarden( 'evergreen' ),
				tend: null,
				message: 'Dismissed.',
			},
		];

		const container = await renderPanel();
		const note = container.querySelector( '.undergrowth-tend' );

		expect( note.textContent ).toContain( 'Worth a look' );
		expect( note.className ).toContain( 'is-info' );

		button( container, 'Dismiss note' ).click();
		await waitFor( () => ! container.querySelector( '.undergrowth-tend' ) );

		expect( requests[ 1 ] ).toMatchObject( {
			path: '/undergrowth/v1/posts/42/stage',
			method: 'POST',
			data: { action: 'dismiss_tend' },
		} );
		expect( container.querySelector( '.undergrowth-tend' ) ).toBeNull();
		// The button is gone, so focus goes to the result, not the page.
		expect( document.activeElement ).toBe(
			container.querySelector( '.undergrowth-stage' )
		);
	} );

	test( 'shows no note when the post is not worth a look', async () => {
		replies = [ { ...inGarden( 'evergreen' ), tend: null } ];

		const container = await renderPanel();

		expect( container.querySelector( '.undergrowth-tend' ) ).toBeNull();
		expect( button( container, 'Dismiss note' ) ).toBeUndefined();
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
			// Through the prototype's setter, as a browser would, or React
			// would not see the change.
			Object.getOwnPropertyDescriptor(
				window.HTMLSelectElement.prototype,
				'value'
			).set.call( choice, 'sprout' );
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

	test( 'says what to do when the stage cannot be read and no reason comes back', async () => {
		// An aborted request, for one, is rejected without a REST message.
		replies = [ new Error() ];

		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		flushSync( () =>
			createRoot( container ).render(
				<StagePanel postId={ 42 } status="publish" />
			)
		);
		await waitFor( () => container.querySelector( '.components-notice' ) );

		expect(
			container.querySelector( '.components-notice' ).textContent
		).toContain(
			'Undergrowth could not reach your site. Check your connection, then reload the page.'
		);
	} );

	test( 'says what to do when an action fails and no reason comes back', async () => {
		replies = [ inGarden( 'sprout', true ), new Error() ];

		const container = await renderPanel();

		button( container, 'Unpin' ).click();
		await waitFor( () => container.querySelector( '.components-notice' ) );

		expect(
			container.querySelector( '.components-notice' ).textContent
		).toContain(
			'Undergrowth could not reach your site. Check your connection, then reload the page.'
		);
		expect(
			container.querySelector( '.undergrowth-stage' ).textContent
		).toBe( 'Growth stage: Sprout, pinned' );
	} );

	test( 'drops the old stage and refusal while it asks again', async () => {
		replies = [
			inGarden( 'sprout' ),
			Object.assign( new Error( 'Sorry, you cannot do that.' ), {
				code: 'rest_forbidden',
			} ),
		];

		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );

		flushSync( () =>
			root.render( <StagePanel postId={ 42 } status="publish" /> )
		);
		await waitFor( () => button( container, 'Compost' ) );
		flushSync( () =>
			root.render( <StagePanel postId={ 42 } status="private" /> )
		);
		await new Promise( ( resolve ) => setTimeout( resolve ) );

		// No control for a stage that may no longer hold is left to press.
		expect( container.querySelector( '.undergrowth-stage' ) ).toBeNull();
		expect( container.querySelectorAll( 'button' ) ).toHaveLength( 0 );
		expect(
			container.querySelector( '.components-notice' ).textContent
		).toContain( 'Sorry, you cannot do that.' );
	} );

	test( 'an action that settles after the panel asked again asks once more, quietly', async () => {
		let settle;
		replies = [
			inGarden( 'sprout' ),
			new Promise( ( resolve ) => {
				settle = resolve;
			} ),
			inGarden( 'sprout', false, 'Sprout before the action' ),
			inGarden( 'composting' ),
		];

		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );

		flushSync( () =>
			root.render( <StagePanel postId={ 42 } status="publish" /> )
		);
		await waitFor( () => button( container, 'Compost' ) );
		button( container, 'Compost' ).click();
		await waitFor( () => requests.length === 2 );

		// The panel asks again, and that answer comes before the action's.
		flushSync( () =>
			root.render( <StagePanel postId={ 42 } status="private" /> )
		);
		await waitFor( () =>
			container
				.querySelector( '.undergrowth-stage' )
				?.textContent.includes( 'before the action' )
		);

		settle( { ...inGarden( 'composting' ), message: 'Moved, late.' } );
		await waitFor( () => button( container, 'Return to garden' ) );

		// The action's own answer may be out of date, so the stage is read again.
		expect( requests ).toHaveLength( 4 );
		expect( requests[ 3 ].path ).toBe( '/undergrowth/v1/posts/42/stage' );
		expect(
			container.querySelector( '.undergrowth-stage' ).textContent
		).toBe( 'Growth stage: Composting' );
		expect(
			button( container, 'Return to garden' ).getAttribute(
				'aria-disabled'
			)
		).not.toBe( 'true' );
		expect(
			select( noticesStore )
				.getNotices()
				.map( ( notice ) => notice.content )
		).not.toContain( 'Moved, late.' );
	} );

	test( 'an action for another post is dropped without asking again', async () => {
		let settle;
		replies = [
			inGarden( 'sprout' ),
			new Promise( ( resolve ) => {
				settle = resolve;
			} ),
			inGarden( 'budding' ),
		];

		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );

		flushSync( () =>
			root.render( <StagePanel postId={ 42 } status="publish" /> )
		);
		await waitFor( () => button( container, 'Compost' ) );
		button( container, 'Compost' ).click();
		await waitFor( () => requests.length === 2 );

		flushSync( () =>
			root.render( <StagePanel postId={ 43 } status="publish" /> )
		);
		await waitFor( () => button( container, 'Compost' ) );

		settle( { ...inGarden( 'composting' ), message: 'Moved away.' } );
		await new Promise( ( resolve ) => setTimeout( resolve, 30 ) );

		expect( requests ).toHaveLength( 3 );
		expect(
			container.querySelector( '.undergrowth-stage' ).textContent
		).toBe( 'Growth stage: Budding' );
		expect(
			select( noticesStore )
				.getNotices()
				.map( ( notice ) => notice.content )
		).not.toContain( 'Moved away.' );
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
