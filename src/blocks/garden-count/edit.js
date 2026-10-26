import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, Placeholder, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * Says why readers will see no count, in place of the empty render.
 *
 * @return {Element} Explanation for the author, in core's Placeholder.
 */
function NoPosts() {
	return (
		<Placeholder
			icon="chart-bar"
			label={ __( 'Garden count', 'undergrowth' ) }
			instructions={ __(
				'Readers see nothing here yet. This counts your published posts in the garden, once there are any.',
				'undergrowth'
			) }
		/>
	);
}

/**
 * Previews the count with the same markup readers get.
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
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Show the label', 'undergrowth' ) }
						help={ __(
							'Off shows the number alone.',
							'undergrowth'
						) }
						checked={ attributes.showLabel }
						onChange={ ( showLabel ) =>
							setAttributes( { showLabel } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<ServerSideRender
				block="undergrowth/garden-count"
				attributes={ attributes }
				// The wrapper above already carries the colour, type and
				// spacing, so the preview must not apply them twice.
				skipBlockSupportAttributes
				EmptyResponsePlaceholder={ NoPosts }
			/>
		</div>
	);
}
