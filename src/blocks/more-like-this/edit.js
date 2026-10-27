import apiFetch from '@wordpress/api-fetch';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Button,
	Disabled,
	Notice,
	PanelBody,
	Placeholder,
	RangeControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * Shows a note from the block in core's Placeholder, so it never reads as
 * part of the post's own text.
 *
 * @param {Object} props      Component props.
 * @param {string} props.note What the author should know.
 * @return {Element} Placeholder.
 */
function Note( { note } ) {
	return (
		<Placeholder
			icon="networking"
			label={ __( 'More like this', 'undergrowth' ) }
			instructions={ note }
		/>
	);
}

/**
 * Says why readers will see no list, in place of the empty render.
 *
 * @return {Element} Explanation for the author.
 */
function NoList() {
	return (
		<Note
			note={ __(
				'Readers see nothing here yet. Only published posts in the garden get a list, and only once Undergrowth has read posts close enough in meaning.',
				'undergrowth'
			) }
		/>
	);
}

/**
 * Returns why a request failed, or a sentence the author can act on when no
 * reason came back.
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
 * Lists the posts readers see, each with a way to hide it from this post's
 * list for good, and the hidden posts with a way to bring them back.
 *
 * @param {Object}     props          Component props.
 * @param {number}     props.postId   Post the list is for.
 * @param {number}     props.count    How many posts the block shows.
 * @param {() => void} props.onChange Called after a post is hidden or shown again.
 * @return {Element} Panel contents.
 */
export function ListPanel( { postId, count, onChange } ) {
	const [ list, setList ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ again, setAgain ] = useState( 0 );
	const path = `/undergrowth/v1/posts/${ postId }/more-like-this`;

	useEffect( () => {
		let current = true;

		setError( '' );
		apiFetch( { path: `${ path }?count=${ count }` } ).then(
			( answer ) => current && setList( answer ),
			( refusal ) => current && setError( reason( refusal ) )
		);

		return () => {
			current = false;
		};
	}, [ path, count, again ] );

	/**
	 * Hides a post from the list, or shows it again, then reads the list anew.
	 *
	 * @param {number}  target Post to hide or show.
	 * @param {boolean} hide   True to hide.
	 */
	function act( target, hide ) {
		setBusy( true );
		setError( '' );
		apiFetch( {
			path: `${ path }/hidden/${ target }`,
			method: hide ? 'POST' : 'DELETE',
		} )
			.then(
				() => {
					setAgain( ( times ) => times + 1 );
					onChange();
				},
				( refusal ) => setError( reason( refusal ) )
			)
			.finally( () => setBusy( false ) );
	}

	const notice = error && (
		<Notice status="error" isDismissible={ false }>
			{ error }
		</Notice>
	);

	// Until the list first loads, a failure is all there is to show. After
	// that, the list stays below it, so a failed Hide can be tried again.
	if ( ! list ) {
		return notice || <Spinner />;
	}

	return (
		<>
			{ notice }
			{ list.shown.length === 0 ? (
				<p>{ __( 'No posts are on this list yet.', 'undergrowth' ) }</p>
			) : (
				<ul className="undergrowth-more-like-this-panel__list">
					{ list.shown.map( ( post ) => (
						<li key={ post.id }>
							<span>{ post.title }</span>{ ' ' }
							<Button
								variant="link"
								disabled={ busy }
								accessibleWhenDisabled
								className="undergrowth-more-like-this-hide"
								onClick={ () => act( post.id, true ) }
								label={ sprintf(
									/* translators: %s: post title. */
									__(
										'Hide “%s” from this list',
										'undergrowth'
									),
									post.title
								) }
							>
								{ __( 'Hide', 'undergrowth' ) }
							</Button>
						</li>
					) ) }
				</ul>
			) }
			{ list.hidden.length > 0 && (
				<>
					<p>{ __( 'Hidden from this list:', 'undergrowth' ) }</p>
					<ul className="undergrowth-more-like-this-panel__list">
						{ list.hidden.map( ( post ) => (
							<li key={ post.id }>
								<span>{ post.title }</span>{ ' ' }
								<Button
									variant="link"
									disabled={ busy }
									accessibleWhenDisabled
									className="undergrowth-more-like-this-show"
									onClick={ () => act( post.id, false ) }
									label={ sprintf(
										/* translators: %s: post title. */
										__(
											'Show “%s” on this list again',
											'undergrowth'
										),
										post.title
									) }
								>
									{ __( 'Show again', 'undergrowth' ) }
								</Button>
							</li>
						) ) }
					</ul>
				</>
			) }
		</>
	);
}

/**
 * Previews the list with the same markup readers get, with its settings and
 * the author's hidden posts in the sidebar.
 *
 * @param {Object}                       props                Block edit props.
 * @param {Object}                       props.attributes     Block attributes.
 * @param {(attributes: Object) => void} props.setAttributes  Updates attributes.
 * @param {Object}                       props.context        Block context.
 * @param {number}                       props.context.postId Post the list belongs to.
 * @return {Element} Editor preview.
 */
export default function Edit( {
	attributes,
	setAttributes,
	context: { postId },
} ) {
	const blockProps = useBlockProps();
	const [ changed, setChanged ] = useState( 0 );
	const { heading, postsToShow, showImage, showExcerpt } = attributes;

	const settings = (
		<InspectorControls>
			<PanelBody title={ __( 'Settings', 'undergrowth' ) }>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Heading', 'undergrowth' ) }
					help={ __(
						'Leave empty to use “More like this”.',
						'undergrowth'
					) }
					value={ heading }
					onChange={ ( value ) =>
						setAttributes( { heading: value } )
					}
				/>
				<RangeControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Number of posts', 'undergrowth' ) }
					value={ postsToShow }
					onChange={ ( value ) =>
						setAttributes( { postsToShow: value } )
					}
					min={ 1 }
					max={ 6 }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Show featured image', 'undergrowth' ) }
					checked={ showImage }
					onChange={ ( value ) =>
						setAttributes( { showImage: value } )
					}
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Show excerpt', 'undergrowth' ) }
					help={ __(
						'Only excerpts you wrote yourself are shown.',
						'undergrowth'
					) }
					checked={ showExcerpt }
					onChange={ ( value ) =>
						setAttributes( { showExcerpt: value } )
					}
				/>
			</PanelBody>
			{ postId && (
				<PanelBody
					className="undergrowth-more-like-this-panel"
					title={ __( 'Posts on this list', 'undergrowth' ) }
				>
					<ListPanel
						postId={ postId }
						count={ postsToShow }
						onChange={ () => setChanged( ( times ) => times + 1 ) }
					/>
				</PanelBody>
			) }
		</InspectorControls>
	);

	// Outside a post, such as in a template, there is no list to show yet.
	if ( ! postId ) {
		return (
			<div { ...blockProps }>
				{ settings }
				<Note
					note={ __(
						'Lists the posts closest in meaning to the post it is placed in.',
						'undergrowth'
					) }
				/>
			</div>
		);
	}

	// The links would leave the editor, so the preview is not interactive.
	// The post ID is passed on so a list inside a Query Loop renders for its
	// own post, and the key renders it again once the author hides a post.
	return (
		<div { ...blockProps }>
			{ settings }
			<Disabled>
				<ServerSideRender
					key={ changed }
					block="undergrowth/more-like-this"
					attributes={ attributes }
					// The wrapper above already carries the colour, type and
					// spacing, so the preview must not apply them twice.
					skipBlockSupportAttributes
					urlQueryArgs={ { post_id: postId } }
					EmptyResponsePlaceholder={ NoList }
				/>
			</Disabled>
		</div>
	);
}
