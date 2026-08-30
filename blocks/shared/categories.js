/**
 * The knowledge base categories, as select options.
 *
 * Three of the four blocks filter by category, and all three offer the same
 * list, so the lookup lives here rather than being written out three times.
 *
 * @since 1.0.0
 */

import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

/**
 * The taxonomy the options are read from.
 *
 * @since 1.0.0
 * @type {string}
 */
export const CATEGORY_TAXONOMY = 'lisensio_category';

/**
 * Read every knowledge base category as a `SelectControl` option list.
 *
 * The first option is always the "no filter" one, so the control has a value to
 * fall back to while the terms are still loading and when the knowledge base
 * has no categories at all.
 *
 * @since 1.0.0
 *
 * @param {string} emptyLabel Label for the option that applies no filter.
 *
 * @return {Array<{label: string, value: string}>} The options, by category slug.
 */
export function useCategoryOptions( emptyLabel ) {
	const terms = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords(
				'taxonomy',
				CATEGORY_TAXONOMY,
				{
					per_page: -1,
					orderby: 'name',
					order: 'asc',
					_fields: 'id,name,slug',
				}
			),
		[]
	);

	const options = [ { label: emptyLabel, value: '' } ];

	( terms || [] ).forEach( ( term ) => {
		options.push( { label: term.name, value: term.slug } );
	} );

	return options;
}
