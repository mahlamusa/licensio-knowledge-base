/**
 * Extension columns and row refreshes of the Categories and Tags apps.
 *
 * Neither term app has a column picker, so an extension column shows when
 * PHP declared it `default_visible`; the REST list fills row data for the
 * same columns (`Licensio_KB_Term_Extension_Data`). A table places each
 * column after the column its `position` names, or at the end.
 *
 * `ctx.refresh( ids )` re-reads only the given rows (`include[]` on the
 * list route) and swaps them into the list in place; when one is no longer
 * answered (it was deleted), the whole list loads again instead, as the
 * Articles app does.
 *
 * Consumed by the Categories app's tree and store and the Tags app's table
 * and store.
 *
 * @since 1.1.0
 */

import type { ExtensionColumn } from './extensions';

/** The most rows one refresh asks for, as the term list routes allow. */
export const REFRESH_MAX_IDS = 100;

/**
 * The extension columns a term app shows.
 *
 * @since 1.1.0
 *
 * @param {ExtensionColumn[]} columns The columns PHP declared for the user.
 *
 * @return {ExtensionColumn[]} The default visible ones, in the declared order.
 */
export function shownColumns( columns: ExtensionColumn[] ): ExtensionColumn[] {
	return columns.filter( ( column ) => column.defaultVisible );
}

/**
 * Place extension columns among a table's own columns.
 *
 * Each goes after the column its `position` names, which may be an earlier
 * extension column, and after any extension columns already right after it,
 * so columns that name the same position keep their declared order. One with
 * no position, or a position that is not a column, goes at the end.
 *
 * @since 1.1.0
 *
 * @template Column
 *
 * @param {Column[]}          core       The table's own columns, in order.
 * @param {ExtensionColumn[]} extensions The extension columns to show.
 * @param {Function}          make       Builds a table column from an extension column.
 *
 * @return {Column[]} Every column, in order.
 */
export function placeColumns< Column extends { key: string } >(
	core: Column[],
	extensions: ExtensionColumn[],
	make: ( column: ExtensionColumn ) => Column
): Column[] {
	const placed = [ ...core ];
	const added = new Set< string >();

	extensions.forEach( ( extension ) => {
		let at =
			'' === extension.position
				? -1
				: placed.findIndex(
						( column ) => column.key === extension.position
					);

		if ( -1 === at ) {
			placed.push( make( extension ) );
		} else {
			while (
				at + 1 < placed.length &&
				added.has( placed[ at + 1 ].key )
			) {
				at++;
			}

			placed.splice( at + 1, 0, make( extension ) );
		}

		added.add( extension.key );
	} );

	return placed;
}

/**
 * The IDs worth refreshing: distinct, and rows the list holds.
 *
 * @since 1.1.0
 *
 * @param {Array<{id: number}>} rows The list.
 * @param {number[]}            ids  The IDs an extension asked for.
 *
 * @return {number[]} The IDs, in the order asked.
 */
export function refreshIds(
	rows: Array< { id: number } >,
	ids: number[]
): number[] {
	const listed = new Set( rows.map( ( row ) => row.id ) );

	return [ ...new Set( ids ) ].filter( ( id ) => listed.has( id ) );
}

/**
 * Swap refreshed rows into a list.
 *
 * A row the refresh answered replaces the row with its ID, where it was.
 * Every other row is kept as it is.
 *
 * @since 1.1.0
 *
 * @template Row
 *
 * @param {Row[]} rows  The list.
 * @param {Row[]} fresh The rows the server answered.
 *
 * @return {Row[]} A new list.
 */
export function replaceRows< Row extends { id: number } >(
	rows: Row[],
	fresh: Row[]
): Row[] {
	const answered = new Map( fresh.map( ( row ) => [ row.id, row ] ) );

	return rows.map( ( row ) => answered.get( row.id ) ?? row );
}
