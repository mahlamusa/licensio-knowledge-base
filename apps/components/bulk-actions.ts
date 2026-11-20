/**
 * The bulk bar entries of the extension bulk actions.
 *
 * Turns the bulk actions PHP declared for a screen (`extensions.bulkActions`
 * in the app's config) into bulk bar entries. An action whose script
 * registered no `run` is left out, so the bar never offers something that
 * does nothing. Running an entry calls the registered `run( ids, ctx )`; the
 * extension shows its own progress and results.
 *
 * Usage:
 *
 *     const entries = bulkActionEntries( 'articles', config().extensions.bulkActions, ctx );
 *     entries[ 0 ].run( [ 12, 15 ] );
 *
 * Consumed by the Articles app's bulk bar.
 *
 * @since 1.1.0
 */

import { extensionHandlers } from './extensions';
import type {
	ExtensionAction,
	ExtensionContext,
	ExtensionScreen,
} from './extensions';

/** One extension entry of the bulk bar. */
export interface BulkActionEntry {
	key: string;
	label: string;
	/** Run the action on IDs; returns what the handler returned. */
	run: ( ids: number[] ) => unknown;
}

/**
 * The bulk actions with a registered handler, as bulk bar entries.
 *
 * @since 1.1.0
 *
 * @param {ExtensionScreen}   screen   The app.
 * @param {ExtensionAction[]} declared The bulk actions PHP declared for the user.
 * @param {ExtensionContext}  ctx      What the handlers are given to talk back to the app.
 *
 * @return {BulkActionEntry[]} The entries, in the declared order.
 */
export function bulkActionEntries(
	screen: ExtensionScreen,
	declared: ExtensionAction[],
	ctx: ExtensionContext
): BulkActionEntry[] {
	const handlers = extensionHandlers( screen ).bulkActions;

	return declared
		.filter(
			( action ) => 'function' === typeof handlers[ action.key ]?.run
		)
		.map( ( action ) => ( {
			key: action.key,
			label: action.label,
			run: ( ids: number[] ) => handlers[ action.key ].run( ids, ctx ),
		} ) );
}
