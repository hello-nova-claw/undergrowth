import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

/**
 * Lists the taxonomies that can name each post's type, post format first.
 *
 * The core data store is only read when the editor has it, so the block
 * still edits where it is missing.
 *
 * @return {Array<{value: string, label: string}>} Options.
 */
export function useTypeOptions() {
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
