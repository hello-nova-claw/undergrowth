import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	Placeholder,
	RangeControl,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * Says why readers will see no feed, in place of the empty render.
 *
 * @return {Element} Explanation for the author, in core's Placeholder.
 */
function NoPosts() {
	return (
		<Placeholder
			icon="list-view"
			label={ __( 'Garden feed', 'undergrowth' ) }
			instructions={ __(
				'Readers see nothing here yet. This lists your published posts, newest tended first, once there are any.',
				'undergrowth'
			) }
		/>
	);
}

/**
 * Lists the taxonomies that can name each post's type, post format first.
 *
 * The core data store is only read when the editor has it, so the block
 * still edits where it is missing.
 *
 * @return {Array<{value: string, label: string}>} Options.
 */
function useTypeOptions() {
	const taxonomies = useSelect(
		( select ) => select( 'core' )?.getTaxonomies?.( { per_page: -1 } ),
		[]
	);

	return [
		{ value: 'post_format', label: __( 'Post format', 'undergrowth' ) },
		...( taxonomies ?? [] )
			.filter(
				( taxonomy ) =>
					taxonomy.slug !== 'post_format' &&
					taxonomy.types?.includes( 'post' ) &&
					taxonomy.visibility?.publicly_queryable !== false
			)
			.map( ( taxonomy ) => ( {
				value: taxonomy.slug,
				label: taxonomy.name,
			} ) ),
	];
}

/**
 * Previews the feed with the same markup readers get.
 *
 * @param {Object}                    props               Block edit props.
 * @param {Object}                    props.attributes    Block attributes.
 * @param {(changes: Object) => void} props.setAttributes Updates the attributes.
 * @return {Element} Editor preview.
 */
export default function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps();
	const typeOptions = useTypeOptions();

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'undergrowth' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Feature the first post, with its image',
							'undergrowth'
						) }
						checked={ attributes.showFeatured }
						onChange={ ( showFeatured ) =>
							setAttributes( { showFeatured } )
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Order', 'undergrowth' ) }
						value={ attributes.orderBy }
						options={ [
							{
								value: 'tended',
								label: __( 'Last tended first', 'undergrowth' ),
							},
							{
								value: 'newest',
								label: __( 'Newest first', 'undergrowth' ),
							},
							{
								value: 'stage',
								label: __( 'Most grown first', 'undergrowth' ),
							},
						] }
						onChange={ ( orderBy ) => setAttributes( { orderBy } ) }
					/>
					<RangeControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Posts per page', 'undergrowth' ) }
						value={ attributes.postsPerPage }
						min={ 1 }
						max={ 100 }
						onChange={ ( postsPerPage ) =>
							setAttributes( { postsPerPage } )
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Type', 'undergrowth' ) }
						help={ __(
							'Which terms name each post’s type. A post with no format shows none.',
							'undergrowth'
						) }
						value={ attributes.typeSource }
						options={ typeOptions }
						onChange={ ( typeSource ) =>
							setAttributes( { typeSource } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Show the excerpt', 'undergrowth' ) }
						help={ __(
							'Only an excerpt you wrote yourself.',
							'undergrowth'
						) }
						checked={ attributes.showExcerpt }
						onChange={ ( showExcerpt ) =>
							setAttributes( { showExcerpt } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Show the type', 'undergrowth' ) }
						checked={ attributes.showType }
						onChange={ ( showType ) =>
							setAttributes( { showType } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<ServerSideRender
				block="undergrowth/garden-feed"
				attributes={ attributes }
				// The wrapper above already carries the colour, type and
				// spacing, so the preview must not apply them twice.
				skipBlockSupportAttributes
				EmptyResponsePlaceholder={ NoPosts }
			/>
		</div>
	);
}
