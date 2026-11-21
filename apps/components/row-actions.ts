/**
 * The menu entries of the extension row actions.
 *
 * Turns the row actions PHP declared for a screen (`extensions.rowActions` in
 * the app's config) into menu entries. An action whose script registered no
 * `run` is left out, so the menu never offers something that does nothing.
 * Running an entry calls the registered `run( row, ctx )`. `rowActionsFor()`
 * keeps the entries whose registered `visible( row )` lets them show on a
 * row; call it as the row renders, so a refreshed row is checked again.
 *
 * Usage:
 *
 *     const entries = rowActionEntries( 'articles', config().extensions.rowActions, ctx );
 *     // <a-menu-item v-for="entry in rowActionsFor( entries, row )" v-on:click="entry.run( row )">{{ entry.label }}</a-menu-item>
 *
 * Consumed by the Articles, Categories and Tags apps' row menus.
 *
 * @since 1.1.0
 */

import { actionEntries, extensionHandlers } from './extensions';
import type {
	ExtensionAction,
	ExtensionActionEntry,
	ExtensionContext,
	ExtensionRow,
	ExtensionScreen,
	RowActionHandler,
} from './extensions';

/** One extension entry of a row's menu. */
export interface RowActionEntry<
	Row extends ExtensionRow = ExtensionRow,
> extends ExtensionActionEntry< Row > {
	/** Whether the action shows on a row. */
	visible: ( row: Row ) => boolean;
}

/**
 * A row action's check of whether it shows on a row.
 *
 * Shows it on every row when the handler has no `visible`. A `visible` that
 * throws hides the action, and its first error goes to the console with the
 * action's key; later ones are not logged again, as the check runs on every
 * render of every row.
 *
 * @since 1.1.0
 *
 * @param {string}           key     The action's key.
 * @param {RowActionHandler} handler The registered handler.
 *
 * @return {Function} The check, given a row.
 */
function visibility< Row extends ExtensionRow >(
	key: string,
	handler: RowActionHandler< Row >
): ( row: Row ) => boolean {
	const check = handler.visible;
	let logged = false;

	if ( 'function' !== typeof check ) {
		return () => true;
	}

	return ( row: Row ) => {
		try {
			return Boolean( check( row ) );
		} catch ( error ) {
			if ( ! logged ) {
				logged = true;
				// eslint-disable-next-line no-console
				console.error( `Row action ${ key } visible failed:`, error );
			}

			return false;
		}
	};
}

/**
 * The row actions with a registered handler, as menu entries.
 *
 * @since 1.1.0
 *
 * @param {ExtensionScreen}   screen   The app.
 * @param {ExtensionAction[]} declared The row actions PHP declared for the user.
 * @param {ExtensionContext}  ctx      What the handlers are given to talk back to the app.
 *
 * @return {RowActionEntry[]} The entries, in the declared order.
 */
export function rowActionEntries< Row extends ExtensionRow = ExtensionRow >(
	screen: ExtensionScreen,
	declared: ExtensionAction[],
	ctx: ExtensionContext
): RowActionEntry< Row >[] {
	const handlers = extensionHandlers< Row >( screen ).rowActions;

	return actionEntries< Row >( declared, handlers, ctx ).map( ( entry ) => ( {
		...entry,
		visible: visibility( entry.key, handlers[ entry.key ] ),
	} ) );
}

/**
 * The entries that show on a row.
 *
 * @since 1.1.0
 *
 * @param {RowActionEntry[]} entries The screen's row action entries.
 * @param {ExtensionRow}     row     The row.
 *
 * @return {RowActionEntry[]} The entries whose `visible( row )` lets them show, in order.
 */
export function rowActionsFor< Row extends ExtensionRow = ExtensionRow >(
	entries: RowActionEntry< Row >[],
	row: Row
): RowActionEntry< Row >[] {
	return entries.filter( ( entry ) => entry.visible( row ) );
}
