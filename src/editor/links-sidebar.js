import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	ExternalLink,
	Notice,
	PanelBody,
	PanelRow,
	SelectControl,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import {
	useEffect,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

// Blocks that hold someone else's words, as undergrowth_others_words_spans()
// lists them. Nothing inside them is ever linked.
const OTHERS_WORDS = [ 'core/quote', 'core/pullquote', 'core/html' ];

/**
 * Lists the paragraph blocks a link may go in, in document order.
 *
 * Only paragraphs count, as in src/accept.php, so a heading, a Classic
 * block or anything inside a quote is never offered.
 *
 * @param {Object[]} blocks Blocks from the block editor.
 * @return {{id: string, content: string}[]} Each paragraph's client ID and HTML.
 */
export function editorParagraphs( blocks ) {
	const found = [];

	for ( const block of blocks ) {
		if ( OTHERS_WORDS.includes( block.name ) ) {
			continue;
		}

		if ( 'core/paragraph' === block.name ) {
			const content = block.attributes.content ?? '';

			found.push( {
				id: block.clientId,
				content:
					typeof content === 'string'
						? content
						: content.toHTMLString(),
			} );
		}

		found.push( ...editorParagraphs( block.innerBlocks ?? [] ) );
	}

	return found;
}

/**
 * Reads HTML as the text a reader sees, with runs of space as one.
 *
 * @param {string} html HTML.
 * @return {string} Text.
 */
export function plainText( html ) {
	const template = document.createElement( 'template' );
	template.innerHTML = html;

	return template.content.textContent.replace( /\s+/g, ' ' ).trim();
}

/**
 * Tells whether HTML holds a link to an address.
 *
 * @param {string} html HTML.
 * @param {string} url  Address.
 * @return {boolean} True when a link points there.
 */
export function linksTo( html, url ) {
	const template = document.createElement( 'template' );
	template.innerHTML = html;

	return [ ...template.content.querySelectorAll( 'a[href]' ) ].some(
		( link ) => link.getAttribute( 'href' ) === url
	);
}

/**
 * Shows a suggestion's sentence with the words a link would sit on marked.
 *
 * @param {Object}      props          Component props.
 * @param {string}      props.sentence Sentence from the post.
 * @param {Object|null} props.span     Offset and length of the words, if any.
 * @return {Element} Sentence.
 */
export function Sentence( { sentence, span } ) {
	// Offsets count characters, as PHP's mb_ functions do, not UTF-16 units.
	const characters = [ ...sentence ];

	if ( ! span ) {
		return <blockquote dir="auto">{ sentence }</blockquote>;
	}

	return (
		<blockquote dir="auto">
			{ characters.slice( 0, span.offset ).join( '' ) }
			<mark>
				{ characters
					.slice( span.offset, span.offset + span.length )
					.join( '' ) }
			</mark>
			{ characters.slice( span.offset + span.length ).join( '' ) }
		</blockquote>
	);
}

/**
 * Lets the author choose the first and last of their own words to link, as
 * the Choose words form on the Suggestions tab does.
 *
 * Nothing can be typed, so the link only ever goes around words already in
 * the sentence.
 *
 * @param {Object}                                props          Component props.
 * @param {Object}                                props.item     Suggestion, with its words.
 * @param {boolean}                               props.busy     Whether a request is under way.
 * @param {(first: number, last: number) => void} props.onSave   Saves the choice.
 * @param {() => void}                            props.onCancel Closes the form.
 * @return {Element} Form.
 */
function ChooseWords( { item, busy, onSave, onCancel } ) {
	const [ first, setFirst ] = useState( item.first );
	const [ last, setLast ] = useState( item.last );
	const firstField = useRef();
	const options = item.words.map( ( word, index ) => ( {
		value: String( index ),
		label: sprintf(
			/* translators: 1: a word from the author's sentence, 2: its position in the sentence, 3: how many words the sentence has. */
			__( '%1$s (%2$s of %3$s)', 'undergrowth' ),
			word,
			( index + 1 ).toLocaleString(),
			item.words.length.toLocaleString()
		),
	} ) );

	useEffect( () => {
		firstField.current?.focus();
	}, [] );

	return (
		<fieldset className="undergrowth-choose-words">
			<legend>{ __( 'Choose the words to link', 'undergrowth' ) }</legend>
			<p className="description">
				{ __(
					'The link goes around your own words, from the first word to the last. Only words already in this sentence can be chosen.',
					'undergrowth'
				) }
			</p>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				ref={ firstField }
				dir="auto"
				label={ __( 'First word', 'undergrowth' ) }
				value={ String( first ) }
				options={ options }
				onChange={ ( value ) => setFirst( Number( value ) ) }
			/>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				dir="auto"
				label={ __( 'Last word', 'undergrowth' ) }
				value={ String( last ) }
				options={ options }
				onChange={ ( value ) => setLast( Number( value ) ) }
			/>
			<PanelRow>
				<Button
					__next40pxDefaultSize
					variant="primary"
					isBusy={ busy }
					disabled={ busy }
					accessibleWhenDisabled
					onClick={ () => onSave( first, last ) }
				>
					{ __( 'Save words', 'undergrowth' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ onCancel }
				>
					{ __( 'Cancel', 'undergrowth' ) }
				</Button>
			</PanelRow>
		</fieldset>
	);
}

/**
 * Lists this post's link suggestions, and the posts that could link here.
 *
 * Accept asks the server to link the words in the paragraph as the editor
 * holds it, then hands the new paragraph to onLink, which puts it in the
 * editor where Undo can take it out. The suggestion is accepted when the
 * post is saved with the link.
 *
 * @param {Object}                                props            Component props.
 * @param {number}                                props.postId     Post being edited.
 * @param {string}                                props.saved      Changes on every save, so the lists are read again.
 * @param {Object[]}                              props.paragraphs Result of editorParagraphs() for the post.
 * @param {(id: string, content: string) => void} props.onLink     Puts a paragraph's new HTML in the editor, given its client ID.
 * @return {Element} Sidebar contents.
 */
export default function LinksSidebar( { postId, saved, paragraphs, onLink } ) {
	const [ lists, setLists ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ refusals, setRefusals ] = useState( {} );
	const [ busy, setBusy ] = useState( 0 );
	const [ choosing, setChoosing ] = useState( 0 );
	const outgoing = useRef();
	const chooseButtons = useRef( {} );
	const refocus = useRef( 0 );
	const { createSuccessNotice } = useDispatch( noticesStore );
	const path = `/undergrowth/v1/posts/${ postId }/links`;

	useEffect( () => {
		let current = true;

		setError( '' );
		setRefusals( {} );
		apiFetch( { path } ).then(
			( answer ) => current && setLists( answer ),
			( refusal ) => current && setError( refusal.message )
		);

		return () => {
			current = false;
		};
	}, [ path, saved ] );

	/**
	 * Asks the server to act on a suggestion.
	 *
	 * @param {number}                   target Post the link would point to.
	 * @param {string}                   action 'accept' or 'veto'.
	 * @param {(answer: Object) => void} done   Called with the answer.
	 * @param {Object}                   data   Body to send.
	 */
	function act( target, action, done, data = {} ) {
		setBusy( target );
		setRefusals( ( shown ) => ( { ...shown, [ target ]: '' } ) );

		apiFetch( {
			path: `${ path }/${ target }/${ action }`,
			method: 'POST',
			data,
		} )
			.then(
				( answer ) => {
					done( answer );
					createSuccessNotice( answer.message, {
						type: 'snackbar',
					} );
				},
				( refusal ) =>
					setRefusals( ( shown ) => ( {
						...shown,
						[ target ]: refusal.message,
					} ) )
			)
			.finally( () => setBusy( 0 ) );
	}

	// Once the form closes, focus goes back to the button that opened it.
	useLayoutEffect( () => {
		if ( refocus.current && ! choosing ) {
			chooseButtons.current[ refocus.current ]?.focus();
			refocus.current = 0;
		}
	} );

	/**
	 * Closes the Choose words form.
	 *
	 * @param {number} target Post the link would point to.
	 */
	function closeChoice( target ) {
		refocus.current = target;
		setChoosing( 0 );
	}

	// A failed refresh after a save leaves the old lists, which may be out of
	// date, so the failure shows above them.
	const failure = error && (
		<Notice status="error" isDismissible={ false }>
			{ error }
		</Notice>
	);

	if ( ! lists ) {
		return failure || null;
	}

	return (
		<div className="undergrowth-links-loaded">
			{ failure }
			<PanelBody title={ __( 'Suggested links', 'undergrowth' ) }>
				<div
					className="undergrowth-outgoing"
					ref={ outgoing }
					tabIndex={ -1 }
				>
					{ lists.outgoing.length === 0 && (
						<p>
							{ __(
								'No links are suggested from this post.',
								'undergrowth'
							) }
						</p>
					) }
					{ lists.outgoing.map( ( item ) => {
						const linked = paragraphs.some( ( paragraph ) =>
							linksTo( paragraph.content, item.url )
						);
						const changed =
							! item.span ||
							! paragraphs.some( ( paragraph ) =>
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
								{ refusals[ item.target ] && (
									<Notice
										status="warning"
										isDismissible={ false }
									>
										{ refusals[ item.target ] }
									</Notice>
								) }
								{ linked && (
									<p className="description">
										{ __(
											'Linked. The suggestion is accepted when you save the post.',
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
								{ ! linked &&
									! changed &&
									choosing === item.target && (
										<ChooseWords
											item={ item }
											busy={ busy === item.target }
											onCancel={ () =>
												closeChoice( item.target )
											}
											onSave={ ( first, last ) =>
												act(
													item.target,
													'words',
													( answer ) => {
														setLists(
															( shown ) => ( {
																...shown,
																outgoing:
																	shown.outgoing.map(
																		(
																			other
																		) =>
																			other.target ===
																			item.target
																				? {
																						...other,
																						...answer,
																					}
																				: other
																	),
															} )
														);
														closeChoice(
															item.target
														);
													},
													{
														first,
														last,
														shown: item.version,
													}
												)
											}
										/>
									) }
								{ ! linked &&
									! changed &&
									choosing !== item.target && (
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
												isBusy={ busy === item.target }
												disabled={ busy !== 0 }
												accessibleWhenDisabled
												onClick={ () =>
													act(
														item.target,
														'accept',
														( answer ) =>
															onLink(
																answer.id,
																answer.content
															),
														{ paragraphs }
													)
												}
											>
												{ __(
													'Accept',
													'undergrowth'
												) }
											</Button>
											<Button
												__next40pxDefaultSize
												variant="tertiary"
												className="undergrowth-veto"
												label={ sprintf(
													/* translators: 1: title of the post the link would point to. */
													__(
														'Veto the link to %1$s',
														'undergrowth'
													),
													item.title
												) }
												showTooltip={ false }
												disabled={ busy !== 0 }
												accessibleWhenDisabled
												onClick={ () =>
													act(
														item.target,
														'veto',
														() => {
															setLists(
																( shown ) => ( {
																	...shown,
																	outgoing:
																		shown.outgoing.filter(
																			(
																				other
																			) =>
																				other.target !==
																				item.target
																		),
																} )
															);
															outgoing.current?.focus();
														}
													)
												}
											>
												{ __( 'Veto', 'undergrowth' ) }
											</Button>
											{ item.words.length > 0 && (
												<Button
													__next40pxDefaultSize
													variant="tertiary"
													className="undergrowth-choose"
													label={ sprintf(
														/* translators: 1: title of the post the link would point to. */
														__(
															'Choose words for the link to %1$s',
															'undergrowth'
														),
														item.title
													) }
													showTooltip={ false }
													ref={ ( element ) => {
														chooseButtons.current[
															item.target
														] = element;
													} }
													disabled={ busy !== 0 }
													accessibleWhenDisabled
													onClick={ () =>
														setChoosing(
															item.target
														)
													}
												>
													{ __(
														'Choose words',
														'undergrowth'
													) }
												</Button>
											) }
										</PanelRow>
									) }
							</div>
						);
					} ) }
				</div>
			</PanelBody>
			<PanelBody
				title={ __( 'Posts that could link here', 'undergrowth' ) }
			>
				{ lists.incoming.length === 0 ? (
					<p>
						{ __(
							'No other post is suggested to link here.',
							'undergrowth'
						) }
					</p>
				) : (
					<>
						<p className="description">
							{ __(
								'Open one of these posts to accept its link to this one.',
								'undergrowth'
							) }
						</p>
						<ul className="undergrowth-incoming">
							{ lists.incoming.map( ( item ) => (
								<li key={ item.source }>
									<a href={ item.edit_url ?? item.url }>
										{ item.title }
									</a>
								</li>
							) ) }
						</ul>
					</>
				) }
			</PanelBody>
		</div>
	);
}
