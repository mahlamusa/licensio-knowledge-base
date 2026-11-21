/**
 * Where extension columns sit among a table's own columns.
 *
 * An extension column follows the column named by its `position`, a core
 * column key or another extension column's key, and any extension columns
 * already placed right after that column, so columns sharing a position keep
 * the order they were declared in. A column with no position, or one whose
 * position names no column placed before it, goes at the end; so a
 * `position` cannot name an extension column that has no position itself.
 *
 * Consumed by the Articles and Tags tables.
 *
 * @since 1.1.0
 */

import type { ExtensionColumn } from './extensions';

/**
 * Every column key in table order.
 *
 * @since 1.1.0
 *
 * @param {string[]}          core       The core column keys, in table order.
 * @param {ExtensionColumn[]} extensions The extension columns, as declared.
 *
 * @return {string[]} The keys, each extension column after its position.
 */
export function orderColumns(
	core: string[],
	extensions: ExtensionColumn[]
): string[] {
	const order = [ ...core ];
	const placed = new Set< string >();
	const trailing: string[] = [];

	extensions.forEach( ( column ) => {
		const after = column.position ? order.indexOf( column.position ) : -1;

		if ( after < 0 ) {
			trailing.push( column.key );
			return;
		}

		let at = after + 1;

		while ( at < order.length && placed.has( order[ at ] ) ) {
			at++;
		}

		order.splice( at, 0, column.key );
		placed.add( column.key );
	} );

	return [ ...order, ...trailing ];
}
