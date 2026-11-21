/**
 * Extension filters of the Articles app.
 *
 * Each filter another plugin declares in PHP (`ExtensionFilter`) gets a
 * dropdown in the filters popover. The chosen options are a map of filter key
 * to option value, written as `ext_filters[{key}]=value` both in the address
 * bar and in the list requests, the form `GET articles` reads and the core
 * screen redirect produces.
 *
 * Only a declared filter set to one of its declared options is read, the same
 * rule the server applies, so a stale link or a deactivated extension never
 * narrows the list by something the user cannot see.
 *
 * @since 1.1.0
 */

import type { ExtensionFilter } from '../components/extensions';

/** The chosen option of each extension filter, keyed by filter key. */
export type ExtFilterValues = Record< string, string >;

/** The argument name the chosen options are written under. */
export const EXT_FILTERS = 'ext_filters';

/**
 * The argument name of one filter, e.g. `ext_filters[seo/status]`.
 *
 * @since 1.1.0
 *
 * @param {string} key The filter key.
 *
 * @return {string} The argument name.
 */
export function extFilterParam( key: string ): string {
	return `${ EXT_FILTERS }[${ key }]`;
}

/**
 * Read the chosen options of the declared filters from query arguments.
 *
 * @since 1.1.0
 *
 * @param {URLSearchParams}   params   The query arguments.
 * @param {ExtensionFilter[]} declared The filters the page declared.
 *
 * @return {ExtFilterValues} The chosen options.
 */
export function readExtFilters(
	params: URLSearchParams,
	declared: ExtensionFilter[]
): ExtFilterValues {
	const values: ExtFilterValues = {};

	declared.forEach( ( filter ) => {
		const value = params.get( extFilterParam( filter.key ) );

		if (
			null !== value &&
			filter.options.some( ( option ) => option.value === value )
		) {
			values[ filter.key ] = value;
		}
	} );

	return values;
}

/**
 * Write the chosen options into query arguments, replacing every earlier one.
 *
 * @since 1.1.0
 *
 * @param {URLSearchParams} params The query arguments, changed in place.
 * @param {ExtFilterValues} values The chosen options.
 *
 * @return {void}
 */
export function writeExtFilters(
	params: URLSearchParams,
	values: ExtFilterValues
): void {
	[ ...params.keys() ]
		.filter( ( key ) => key.startsWith( `${ EXT_FILTERS }[` ) )
		.forEach( ( key ) => params.delete( key ) );

	Object.entries( extFilterArgs( values ) ).forEach( ( [ key, value ] ) =>
		params.set( key, value )
	);
}

/**
 * The chosen options as flat request arguments; empty values are left out.
 *
 * @since 1.1.0
 *
 * @param {ExtFilterValues} values The chosen options.
 *
 * @return {Record<string, string>} The arguments, by argument name.
 */
export function extFilterArgs(
	values: ExtFilterValues
): Record< string, string > {
	const args: Record< string, string > = {};

	Object.entries( values ).forEach( ( [ key, value ] ) => {
		if ( '' !== value ) {
			args[ extFilterParam( key ) ] = value;
		}
	} );

	return args;
}

/**
 * The chosen options with one filter changed; an empty value clears it.
 *
 * @since 1.1.0
 *
 * @param {ExtFilterValues} values The chosen options.
 * @param {string}          key    The filter key.
 * @param {string}          value  The option, or an empty string for any.
 *
 * @return {ExtFilterValues} A new map.
 */
export function withExtFilter(
	values: ExtFilterValues,
	key: string,
	value: string
): ExtFilterValues {
	const next = { ...values };

	if ( '' === value ) {
		delete next[ key ];
	} else {
		next[ key ] = value;
	}

	return next;
}
