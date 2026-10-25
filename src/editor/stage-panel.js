import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, PanelRow, SelectControl } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import {
	useEffect,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Returns why a request failed, or a sentence the author can act on when no
 * reason came back, as for an aborted request.
 *
 * @param {Object|undefined} refusal What the request was rejected with.
 * @return {string} Message to show.
 */
function reason( refusal ) {
	return 'string' === typeof refusal?.message && '' !== refusal.message
		? refusal.message
		: __(
				'Undergrowth could not reach your site. Check your connection, then reload the page.',
				'undergrowth'
			);
}

/**
 * Shows a post's growth stage, with Pin, Unpin, Compost and Return to garden.
 *
 * Every rule lives on the server, in the same functions the Growth stages tab
 * and the Posts list use. The panel only asks and shows the answer.
 *
 * @param {Object} props        Component props.
 * @param {number} props.postId Post being edited.
 * @param {string} props.status The post's saved status, so the panel asks again once it is published.
 * @return {Element} Panel contents.
 */
export default function StagePanel( { postId, status } ) {
	const [ stage, setStage ] = useState( null );
	const [ choice, setChoice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ again, setAgain ] = useState( 0 );
	const result = useRef();
	const moved = useRef( false );
	// Counts the times the panel asked again, so an action that settles
	// after that never overwrites the newer answer.
	const asked = useRef( 0 );
	const latestPath = useRef( '' );
	const { createSuccessNotice } = useDispatch( noticesStore );
	const path = `/undergrowth/v1/posts/${ postId }/stage`;

	// During the commit, so no answer to an earlier action handled after
	// this point can pass for a current one.
	useLayoutEffect( () => {
		asked.current++;
		latestPath.current = path;
	}, [ path, status, again ] );

	useEffect( () => {
		let current = true;

		// Until the answer comes, the old stage and its controls may no longer
		// hold, so neither is left on screen.
		setBusy( false );
		setError( '' );
		setStage( null );
		apiFetch( { path } ).then(
			( answer ) => current && setStage( answer ),
			( refusal ) => current && setError( reason( refusal ) )
		);

		return () => {
			current = false;
		};
	}, [ path, status, again ] );

	// Before the browser paints, so focus never rests on the page's body.
	useLayoutEffect( () => {
		// The button that was pressed is gone after an action, so focus goes
		// to the result rather than falling back to the top of the page.
		if ( moved.current ) {
			moved.current = false;
			result.current?.focus();
		}
	}, [ stage ] );

	/**
	 * Asks the server to act on the stage, then shows the new one.
	 *
	 * @param {Object} data Action, and the stage to pin.
	 */
	function act( data ) {
		const when = asked.current;
		const current = () => when === asked.current;

		setBusy( true );
		setError( '' );

		apiFetch( { path, method: 'POST', data } )
			.then(
				( answer ) => {
					// The stage the panel asked for meanwhile may predate this
					// action, so it is read again. An action on another post
					// says nothing about this one.
					if ( ! current() ) {
						if ( latestPath.current === path ) {
							setAgain( ( count ) => count + 1 );
						}

						return;
					}

					moved.current = true;
					setChoice( null );
					setStage( answer );
					createSuccessNotice( answer.message, { type: 'snackbar' } );
				},
				( refusal ) => current() && setError( reason( refusal ) )
			)
			.finally( () => current() && setBusy( false ) );
	}

	const refusal = error && (
		<Notice status="error" isDismissible={ false }>
			{ error }
		</Notice>
	);

	if ( ! stage ) {
		return refusal || null;
	}

	if ( ! stage.in_garden ) {
		return (
			<p className="undergrowth-not-in-garden">
				{ __(
					'This post gets a growth stage once it is published.',
					'undergrowth'
				) }
			</p>
		);
	}

	const composting = 'composting' === stage.stage;
	const button = ( className, text, data, variant = 'secondary' ) => (
		<Button
			__next40pxDefaultSize
			className={ className }
			variant={ variant }
			isBusy={ busy }
			disabled={ busy }
			accessibleWhenDisabled
			onClick={ () => act( data ) }
		>
			{ text }
		</Button>
	);

	return (
		<>
			{ refusal }
			<p className="undergrowth-stage" ref={ result } tabIndex={ -1 }>
				{ stage.pinned
					? sprintf(
							/* translators: %s: growth stage name, such as Sprout. */
							__( 'Growth stage: %s, pinned', 'undergrowth' ),
							stage.label
						)
					: sprintf(
							/* translators: %s: growth stage name, such as Sprout. */
							__( 'Growth stage: %s', 'undergrowth' ),
							stage.label
						) }
			</p>
			<p className="description">
				{ composting
					? __(
							'Composting posts rest outside the garden. They stay published, and no links are suggested to or from them.',
							'undergrowth'
						)
					: __(
							'The monthly cycle measures the stage from the links you accept. Pin a stage to keep it as it is.',
							'undergrowth'
						) }
			</p>
			{ stage.tend && (
				// A note to the author only, never shown to readers.
				<Notice
					className="undergrowth-tend"
					status="info"
					isDismissible={ false }
				>
					<p>{ stage.tend }</p>
					{ button(
						'undergrowth-dismiss-tend',
						__( 'Dismiss note', 'undergrowth' ),
						{ action: 'dismiss_tend' },
						'link'
					) }
				</Notice>
			) }
			{ ! composting && ! stage.pinned && (
				<>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Stage to pin', 'undergrowth' ) }
						value={ choice ?? stage.stage }
						options={ stage.choices }
						onChange={ setChoice }
					/>
					<PanelRow>
						{ button(
							'undergrowth-pin',
							__( 'Pin stage', 'undergrowth' ),
							{
								action: 'pin',
								stage: choice ?? stage.stage,
							}
						) }
					</PanelRow>
				</>
			) }
			<PanelRow>
				{ stage.pinned &&
					button( 'undergrowth-unpin', __( 'Unpin', 'undergrowth' ), {
						action: 'unpin',
					} ) }
				{ composting
					? button(
							'undergrowth-return',
							__( 'Return to garden', 'undergrowth' ),
							{ action: 'return' }
						)
					: button(
							'undergrowth-compost',
							__( 'Compost', 'undergrowth' ),
							{ action: 'compost' },
							'tertiary'
						) }
			</PanelRow>
		</>
	);
}
