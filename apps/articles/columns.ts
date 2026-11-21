/**
 * The Articles table's columns, core and extension.
 *
 * Pure helpers over the extension columns PHP declared for the Articles app
 * (`extensions.columns` in its config): how the Columns picker groups them,
 * and which ones the list can sort by. Where each one sits in the table is
 * `orderColumns()` in `apps/components/column-order.ts`, shared with the Tags
 * app.
 *
 * Consumed by the store, `ArticleTable.vue` and `ColumnPicker.vue`.
 *
 * @since 1.1.0
 */

import type { ExtensionColumn } from '../components/extensions';
import type { ExtensionColumnKey } from './types';

/** The extension columns of one Columns picker group. */
export interface ColumnGroup {
	/** The group's heading; empty for the columns declared without one. */
	label: string;
	columns: ExtensionColumn[];
}

/**
 * Whether a column key is an extension column's, `{namespace}/{name}`.
 *
 * @since 1.1.0
 *
 * @param {string} key The column key.
 *
 * @return {boolean} True for an extension column.
 */
export function isExtensionKey( key: string ): key is ExtensionColumnKey {
	return key.includes( '/' );
}

/**
 * The extension columns by Columns picker group.
 *
 * @since 1.1.0
 *
 * @param {ExtensionColumn[]} extensions The extension columns, as declared.
 *
 * @return {ColumnGroup[]} The groups, the ungrouped columns first, then each
 *                         group in the order it first appears.
 */
export function groupColumns( extensions: ExtensionColumn[] ): ColumnGroup[] {
	const groups = new Map< string, ExtensionColumn[] >( [ [ '', [] ] ] );

	extensions.forEach( ( column ) => {
		const label = column.group.trim();

		groups.set( label, [ ...( groups.get( label ) ?? [] ), column ] );
	} );

	return [ ...groups ]
		.map( ( [ label, columns ] ) => ( { label, columns } ) )
		.filter( ( group ) => group.columns.length > 0 );
}

/**
 * The keys of the extension columns the list can be sorted by.
 *
 * @since 1.1.0
 *
 * @param {ExtensionColumn[]} extensions The extension columns, as declared.
 *
 * @return {ExtensionColumnKey[]} The keys.
 */
export function sortableKeys(
	extensions: ExtensionColumn[]
): ExtensionColumnKey[] {
	return extensions
		.filter( ( column ) => column.sortable )
		.map( ( column ) => column.key )
		.filter( isExtensionKey );
}
