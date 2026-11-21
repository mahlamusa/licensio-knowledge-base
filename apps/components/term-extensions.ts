/**
 * Extension columns and row refreshes of the Categories and Tags apps.
 *
 * Neither term app has a column picker, so an extension column shows when
 * PHP declared it `default_visible`; the REST list fills row data for the
 * same columns (`Licensio_KB_Term_Extension_Data`). The Tags table places
 * them with `orderColumns()` (`column-order.ts`), as the Articles table does.
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
