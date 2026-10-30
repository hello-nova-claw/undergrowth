import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Disabled,
	PanelBody,
	Placeholder,
	RangeControl,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

import { useTypeOptions } from './type-options';

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
						label={ __( 'Show featured images', 'undergrowth' ) }
						checked={ attributes.showImages }
						onChange={ ( showImages ) =>
							setAttributes( { showImages } )
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
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Layout', 'undergrowth' ) }
						help={ __(
							'Masonry packs cards of different heights into columns, read top to bottom.',
							'undergrowth'
						) }
						value={ attributes.layout }
						options={ [
							{
								value: 'grid',
								label: __( 'Grid', 'undergrowth' ),
							},
							{
								value: 'masonry',
								label: __( 'Masonry', 'undergrowth' ),
							},
						] }
						onChange={ ( layout ) => setAttributes( { layout } ) }
					/>
				</PanelBody>
			</InspectorControls>
			{ /* The links are for readers, so the preview holds no tab stops. */ }
			<Disabled>
				<ServerSideRender
					block="undergrowth/garden-feed"
					attributes={ attributes }
					// The wrapper above already carries the colour, type and
					// spacing, so the preview must not apply them twice.
					skipBlockSupportAttributes
					EmptyResponsePlaceholder={ NoPosts }
				/>
			</Disabled>
		</div>
	);
}
