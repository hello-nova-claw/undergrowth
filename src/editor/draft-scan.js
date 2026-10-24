import apiFetch from '@wordpress/api-fetch';
import { Button, ExternalLink, Notice, PanelRow } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import {
	useEffect,
	useId,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

import { Sentence, linksTo, plainText } from './links-sidebar';

/**
 * Offers to scan the draft for links, and lists what the scan finds.
 *
 * Nothing is sent until the author presses the button, and the sentence
 * beside it says where the draft goes. The results live only in this
 * component, so they are gone once the sidebar closes.
 *
 * @param {Object}                                props            Component props.
 * @param {number}                                props.postId     Post being edited.
 * @param {() => Object}                          props.getDraft   Returns the title, content and password the editor holds.
 * @param {Object[]}                              props.paragraphs Result of editorParagraphs() for the post.
 * @param {(id: string, content: string) => void} props.onLink     Puts a paragraph's new HTML in the editor, given its client ID.
 * @return {Element} The scan.
 */
export default function DraftScan( { postId, getDraft, paragraphs, onLink } ) {
	const [ offer, setOffer ] = useState( null );
	const [ results, setResults ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ accepting, setAccepting ] = useState( 0 );
	const found = useRef();
	const moved = useRef( false );
	const linkedNotes = useRef( {} );
	const justLinked = useRef( 0 );
	const describedBy = `undergrowth-scan-destination-${ useId() }`;
	const { createSuccessNotice } = useDispatch( noticesStore );
	const path = `/undergrowth/v1/posts/${ postId }/scan`;

	useEffect( () => {
		let current = true;

		apiFetch( { path } ).then(
			( answer ) => current && setOffer( answer ),
			( refusal ) => current && setError( refusal.message )
		);

		return () => {
			current = false;
		};
	}, [ path ] );

	// The results replace nothing the author pressed, so focus goes to them.
	useLayoutEffect( () => {
		if ( moved.current ) {
			moved.current = false;
			found.current?.focus();
		}
	}, [ results ] );

	// Accept's button goes once the paragraph holds the link, which can be a
	// render after the answer, so focus moves to the note that replaces it.
	useLayoutEffect( () => {
		const note = linkedNotes.current[ justLinked.current ];

		if ( note ) {
			note.focus();
			justLinked.current = 0;
		}
	} );

	/**
	 * Sends the draft once and shows what it could link to.
	 */
	function scan() {
		setBusy( true );
		setError( '' );

		apiFetch( { path, method: 'POST', data: getDraft() } )
			.then(
				( answer ) => {
					moved.current = true;
					setResults( answer.outgoing );
				},
				( refusal ) => setError( refusal.message )
			)
			.finally( () => setBusy( false ) );
	}

	/**
	 * Links a suggestion's words in the paragraph the editor holds.
	 *
	 * @param {Object} item Suggestion.
	 */
	function accept( item ) {
		setAccepting( item.target );
		setError( '' );

		apiFetch( {
			path: `${ path }/accept`,
			method: 'POST',
			data: {
				target: item.target,
				sentence: item.sentence,
				offset: item.span.offset,
				length: item.span.length,
				paragraphs,
			},
		} )
			.then(
				( answer ) => {
					justLinked.current = item.target;
					onLink( answer.id, answer.content );
					createSuccessNotice( answer.message, {
						type: 'snackbar',
					} );
				},
				( refusal ) => setError( refusal.message )
			)
			.finally( () => setAccepting( 0 ) );
	}

	const refusal = error && (
		<Notice status="warning" isDismissible={ false }>
			{ error }
		</Notice>
	);

	if ( ! offer ) {
		return refusal || null;
	}

	if ( ! offer.available ) {
		return (
			<div className="undergrowth-scan">
				<p>{ offer.unavailable }</p>
			</div>
		);
	}

	return (
		<div className="undergrowth-scan">
			<p className="description" id={ describedBy }>
				{ offer.destination }
			</p>
			<PanelRow>
				<Button
					__next40pxDefaultSize
					variant="secondary"
					className="undergrowth-scan-button"
					aria-describedby={ describedBy }
					isBusy={ busy }
					disabled={ busy }
					accessibleWhenDisabled
					onClick={ scan }
				>
					{ __( 'Scan this draft', 'undergrowth' ) }
				</Button>
			</PanelRow>
			{ refusal }
			{ results && (
				<div
					className="undergrowth-scan-results"
					ref={ found }
					tabIndex={ -1 }
				>
					<h3>
						{ __(
							'Posts this draft could link to',
							'undergrowth'
						) }
					</h3>
					{ results.length === 0 && (
						<p>
							{ __(
								'No published post is close enough to suggest a link.',
								'undergrowth'
							) }
						</p>
					) }
					{ results.map( ( item ) => {
						const linked = paragraphs.some( ( paragraph ) =>
							linksTo( paragraph.content, item.url )
						);
						const changed = ! paragraphs.some( ( paragraph ) =>
							plainText( paragraph.content ).includes(
								item.sentence
							)
						);

						return (
							<div
								key={ item.target }
								className="undergrowth-suggestion"
							>
								<p>
									<ExternalLink href={ item.url }>
										{ item.title }
									</ExternalLink>
								</p>
								<Sentence
									sentence={ item.sentence }
									span={ item.span }
								/>
								{ linked && (
									<p
										className="description"
										tabIndex={ -1 }
										ref={ ( element ) => {
											linkedNotes.current[ item.target ] =
												element;
										} }
									>
										{ __(
											'Linked. Save the post to keep the link.',
											'undergrowth'
										) }
									</p>
								) }
								{ ! linked && changed && (
									<p className="description">
										{ __(
											'That sentence has changed.',
											'undergrowth'
										) }
									</p>
								) }
								{ ! linked && ! changed && (
									<PanelRow>
										<Button
											__next40pxDefaultSize
											variant="secondary"
											className="undergrowth-accept"
											label={ sprintf(
												/* translators: 1: title of the post the link would point to. */
												__(
													'Accept the link to %1$s',
													'undergrowth'
												),
												item.title
											) }
											showTooltip={ false }
											isBusy={ accepting === item.target }
											disabled={ accepting !== 0 }
											accessibleWhenDisabled
											onClick={ () => accept( item ) }
										>
											{ __( 'Accept', 'undergrowth' ) }
										</Button>
									</PanelRow>
								) }
							</div>
						);
					} ) }
				</div>
			) }
		</div>
	);
}
