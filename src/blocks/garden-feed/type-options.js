import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

/**
 * Lists the taxonomies that can name each post's type, post format first:
 * those that hold a post type Undergrowth indexes, as the server accepts.
 *
 * The core data store is only read when the editor has it, so the block
 * still edits where it is missing.
 *
 * @return {Array<{value: string, label: string}>} Options.
 */
export function useTypeOptions() {
	const { taxonomies, postTypes } = useSelect(
		( select ) => ( {
			taxonomies: select( 'core' )?.getTaxonomies?.( { per_page: -1 } ),
			postTypes: select( 'core/block-editor' )?.getSettings?.()
				.undergrowthIndexedPostTypes ?? [ 'post' ],
		} ),
		[]
	);

	return [
		{ value: 'post_format', label: __( 'Post format', 'undergrowth' ) },
		...( taxonomies ?? [] )
			.filter(
				( taxonomy ) =>
					taxonomy.slug !== 'post_format' &&
					taxonomy.types?.some( ( type ) =>
						postTypes.includes( type )
					) &&
					taxonomy.visibility?.publicly_queryable !== false
			)
			.map( ( taxonomy ) => ( {
				value: taxonomy.slug,
				label: taxonomy.name,
			} ) ),
	];
}
