import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	ExternalLink,
	Notice,
	PanelBody,
	PanelRow,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
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
function plainText( html ) {
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
function linksTo( html, url ) {
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
function Sentence( { sentence, span } ) {
	// Offsets count characters, as PHP's mb_ functions do, not UTF-16 units.
	const characters = [ ...sentence ];

	if ( ! span ) {
		return <blockquote>{ sentence }</blockquote>;
	}

	return (
		<blockquote>
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
 * Lists this post's link suggestions, and the posts that could link here.
 *
 * Accept asks the server to link the words in the paragraph as the editor
 * holds it, then hands the new paragraph to onLink, which puts it in the
 * editor where Undo can take it out. The suggestion is accepted when the
 * post is saved with the link.
 *
 * @param {Object}   props            Component props.
 * @param {number}                                 props.postId     Post being edited.
 * @param {string}                                 props.saved      Changes on every save, so the lists are read again.
 * @param {Object[]}                               props.paragraphs Result of editorParagraphs() for the post.
 * @param {(id: string, content: string) => void} props.onLink     Puts a paragraph's new HTML in the editor, given its client ID.
 * @return {Element} Sidebar contents.
 */
export default function LinksSidebar( { postId, saved, paragraphs, onLink } ) {
	const [ lists, setLists ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ refusals, setRefusals ] = useState( {} );
	const [ busy, setBusy ] = useState( 0 );
	const outgoing = useRef();
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

	if ( ! lists ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		) : null;
	}

	return (
		<div className="undergrowth-links-loaded">
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
								{ ! linked && ! changed && (
									<PanelRow>
										<Button
											__next40pxDefaultSize
											variant="secondary"
											className="undergrowth-accept"
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
											{ __( 'Accept', 'undergrowth' ) }
										</Button>
										<Button
											__next40pxDefaultSize
											variant="tertiary"
											className="undergrowth-veto"
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
