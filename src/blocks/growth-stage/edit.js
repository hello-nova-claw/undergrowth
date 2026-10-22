import { useBlockProps } from '@wordpress/block-editor';
import { Placeholder } from '@wordpress/components';
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
			icon="awards"
			label={ __( 'Growth stage', 'undergrowth' ) }
			instructions={ note }
		/>
	);
}

/**
 * Says why readers will see no badge, in place of the empty render.
 *
 * @return {Element} Explanation for the author.
 */
function NoBadge() {
	return (
		<Note
			note={ __(
				'Readers see no growth stage here. Only published posts in the garden show one, and composting posts show none.',
				'undergrowth'
			) }
		/>
	);
}

/**
 * Previews the badge with the same markup readers get.
 *
 * @param {Object} props                Block edit props.
 * @param {Object} props.context        Block context.
 * @param {number} props.context.postId Post the badge belongs to.
 * @return {Element} Editor preview.
 */
export default function Edit( { context: { postId } } ) {
	const blockProps = useBlockProps();

	// Outside a post, such as in a template, there is no stage to show yet.
	if ( ! postId ) {
		return (
			<div { ...blockProps }>
				<Note
					note={ __(
						'Shows the growth stage of the post it is placed in.',
						'undergrowth'
					) }
				/>
			</div>
		);
	}

	// The post ID is passed on so a badge inside a Query Loop renders for
	// its own post, not for the post being edited.
	return (
		<div { ...blockProps }>
			<ServerSideRender
				block="undergrowth/growth-stage"
				urlQueryArgs={ { post_id: postId } }
				EmptyResponsePlaceholder={ NoBadge }
			/>
		</div>
	);
}
