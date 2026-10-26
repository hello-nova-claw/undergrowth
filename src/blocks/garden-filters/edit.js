import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Disabled,
	PanelBody,
	Placeholder,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

import { useTypeOptions } from '../garden-feed/type-options';

/**
 * Says why readers will see no filters, in place of the empty render.
 *
 * @return {Element} Explanation for the author, in core's Placeholder.
 */
function NoPosts() {
	return (
		<Placeholder
			icon="filter"
			label={ __( 'Garden filters', 'undergrowth' ) }
			instructions={ __(
				'Readers see nothing here yet. The filters offer the topics, stages and types of your published posts, once there are any.',
				'undergrowth'
			) }
		/>
	);
}

/**
 * Previews the filters with the same markup readers get.
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
						label={ __( 'Show the topics', 'undergrowth' ) }
						checked={ attributes.showTopics }
						onChange={ ( showTopics ) =>
							setAttributes( { showTopics } )
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Topics', 'undergrowth' ) }
						value={ attributes.topicSource }
						options={ [
							{
								value: 'post_tag',
								label: __( 'Tags', 'undergrowth' ),
							},
							{
								value: 'category',
								label: __( 'Categories', 'undergrowth' ),
							},
						] }
						onChange={ ( topicSource ) =>
							setAttributes( { topicSource } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Show the growth stage', 'undergrowth' ) }
						checked={ attributes.showStage }
						onChange={ ( showStage ) =>
							setAttributes( { showStage } )
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
						label={ __( 'Type', 'undergrowth' ) }
						help={ __(
							'Use the same choice as the garden feed.',
							'undergrowth'
						) }
						value={ attributes.typeSource }
						options={ typeOptions }
						onChange={ ( typeSource ) =>
							setAttributes( { typeSource } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			{ /* The links and the form are for readers, so the preview holds no tab stops. */ }
			<Disabled>
				<ServerSideRender
					block="undergrowth/garden-filters"
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
