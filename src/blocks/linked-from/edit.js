import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	Placeholder,
	RangeControl,
	TextControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
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
			icon="admin-links"
			label={ __( 'Linked from', 'undergrowth' ) }
			instructions={ note }
		/>
	);
}

/**
 * Says why readers will see no list, in place of the empty render.
 *
 * @return {Element} Explanation for the author.
 */
function NoLinks() {
	return (
		<Note
			note={ __(
				'Readers see nothing here yet. This lists your published posts that link to this one through a suggestion you accepted.',
				'undergrowth'
			) }
		/>
	);
}

/**
 * The block's settings: its heading and how many posts it lists.
 *
 * @param {Object}                    props                     Component props.
 * @param {Object}                    props.attributes          Block attributes.
 * @param {string}                    props.attributes.heading  Heading text.
 * @param {number}                    props.attributes.maxItems Most posts listed.
 * @param {(changes: Object) => void} props.setAttributes       Updates the attributes.
 * @return {Element} Sidebar controls.
 */
function Settings( { attributes: { heading, maxItems }, setAttributes } ) {
	return (
		<InspectorControls>
			<PanelBody title={ __( 'Settings', 'undergrowth' ) }>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Heading', 'undergrowth' ) }
					help={ __(
						'Leave empty to use “Linked from”.',
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
					label={ __( 'Maximum posts', 'undergrowth' ) }
					value={ maxItems }
					min={ 1 }
					max={ 20 }
					onChange={ ( value ) =>
						setAttributes( { maxItems: value ?? 5 } )
					}
				/>
			</PanelBody>
		</InspectorControls>
	);
}

/**
 * Previews the list with the same markup readers get.
 *
 * @param {Object}                    props                Block edit props.
 * @param {Object}                    props.attributes     Block attributes.
 * @param {(changes: Object) => void} props.setAttributes  Updates the attributes.
 * @param {Object}                    props.context        Block context.
 * @param {number}                    props.context.postId Post the links point to.
 * @return {Element} Editor preview.
 */
export default function Edit( {
	attributes,
	setAttributes,
	context: { postId },
} ) {
	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<Settings
				attributes={ attributes }
				setAttributes={ setAttributes }
			/>
			{ postId ? (
				// The post ID is passed on so a list inside a Query Loop
				// renders for its own post, not for the post being edited.
				// The wrapper already carries colour and spacing, so the
				// preview leaves them out rather than show them twice.
				<ServerSideRender
					block="undergrowth/linked-from"
					attributes={ attributes }
					urlQueryArgs={ { post_id: postId } }
					skipBlockSupportAttributes
					EmptyResponsePlaceholder={ NoLinks }
				/>
			) : (
				// Outside a post, such as in a template, there is nothing
				// to list yet.
				<Note
					note={ __(
						'Lists the posts that link to the post it is placed in.',
						'undergrowth'
					) }
				/>
			) }
		</div>
	);
}
