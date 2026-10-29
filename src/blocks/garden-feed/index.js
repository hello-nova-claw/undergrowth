import { registerBlockType } from '@wordpress/blocks';

import metadata from './block.json';
import Edit from './edit';

// Before the first release the feed featured one post with its image,
// under a showFeatured setting. A block saved then keeps the choice.
const { showImages, ...olderAttributes } = metadata.attributes;

registerBlockType( metadata, {
	edit: Edit,
	deprecated: [
		{
			attributes: {
				...olderAttributes,
				showFeatured: showImages,
			},
			isEligible: ( attributes ) => 'showFeatured' in attributes,
			migrate: ( { showFeatured, ...attributes } ) => ( {
				...attributes,
				showImages: showFeatured,
			} ),
			save: () => null,
		},
	],
} );
