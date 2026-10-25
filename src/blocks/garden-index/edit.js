import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, Placeholder, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * Says why readers will see no index, in place of the empty render.
 *
 * @return {Element} Explanation for the author, in core's Placeholder.
 */
function NoPosts() {
	return (
		<Placeholder
			icon="networking"
			label={ __( 'Garden index', 'undergrowth' ) }
			instructions={ __(
				'Readers see nothing here yet. This lists your published posts by the groups on the Map, and only those at the chosen stage.',
				'undergrowth'
			) }
		/>
	);
}

/**
 * Previews the index with the same markup readers get.
 *
 * @param {Object}                    props               Block edit props.
 * @param {Object}                    props.attributes    Block attributes.
 * @param {(changes: Object) => void} props.setAttributes Updates the attributes.
 * @return {Element} Editor preview.
 */
export default function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'undergrowth' ) }>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Growth stage', 'undergrowth' ) }
						value={ attributes.stage }
						options={ [
							{
								value: '',
								label: __( 'Every stage', 'undergrowth' ),
							},
							{
								value: 'seedling',
								label: __( 'Seedling', 'undergrowth' ),
							},
							{
								value: 'sprout',
								label: __( 'Sprout', 'undergrowth' ),
							},
							{
								value: 'budding',
								label: __( 'Budding', 'undergrowth' ),
							},
							{
								value: 'evergreen',
								label: __( 'Evergreen', 'undergrowth' ),
							},
						] }
						onChange={ ( stage ) => setAttributes( { stage } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<ServerSideRender
				block="undergrowth/garden-index"
				attributes={ attributes }
				// The wrapper above already carries the colour, type and
				// spacing, so the preview must not apply them twice.
				skipBlockSupportAttributes
				EmptyResponsePlaceholder={ NoPosts }
			/>
		</div>
	);
}
