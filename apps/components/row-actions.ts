/**
 * The menu entries of the extension row actions.
 *
 * Turns the row actions PHP declared for a screen (`extensions.rowActions` in
 * the app's config) into menu entries. An action whose script registered no
 * `run` is left out, so the menu never offers something that does nothing.
 * Running an entry calls the registered `run( row, ctx )`.
 *
 * Usage:
 *
 *     const entries = rowActionEntries( 'articles', config().extensions.rowActions, ctx );
 *     // <a-menu-item v-for="entry in entries" v-on:click="entry.run( row )">{{ entry.label }}</a-menu-item>
 *
 * Consumed by the Articles, Categories and Tags apps' row menus.
 *
 * @since 1.1.0
 */

import { extensionHandlers } from './extensions';
import type {
	ExtensionAction,
	ExtensionContext,
	ExtensionRow,
	ExtensionScreen,
} from './extensions';

/** One extension entry of a row's menu. */
export interface RowActionEntry< Row extends ExtensionRow = ExtensionRow > {
	key: string;
	label: string;
	/** Run the action on a row; returns what the handler returned. */
	run: ( row: Row ) => unknown;
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

	return declared
		.filter(
			( action ) => 'function' === typeof handlers[ action.key ]?.run
		)
		.map( ( action ) => ( {
			key: action.key,
			label: action.label,
			run: ( row: Row ) => handlers[ action.key ].run( row, ctx ),
		} ) );
}
