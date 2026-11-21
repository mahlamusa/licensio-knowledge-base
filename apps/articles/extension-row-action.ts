/**
 * Running an extension row action in the Articles app.
 *
 * Calls the entry's handler with the row and waits for it. While a run is
 * pending, the same action on the same row does not run again, so a double
 * click runs it once. A handler that throws, or returns a promise that
 * rejects, gets the app's error toast with a generic message; the original
 * error goes to the console with the action's key, for the extension's
 * author. The extension can still show its own message. The returned promise
 * never rejects.
 *
 * @since 1.1.0
 */

import { reactive } from 'vue';
import { message } from 'ant-design-vue';
import { __, sprintf } from '../components/i18n';
import type { RowActionEntry } from '../components/row-actions';
import type { ArticleItem } from './types';

/** The pending runs, as `{row ID}:{action key}`. */
const running = reactive( new Set< string >() );

/**
 * The key of one action's run on one row.
 *
 * @since 1.1.0
 *
 * @param {RowActionEntry} entry The extension's row action.
 * @param {ArticleItem}    row   The row it runs on.
 *
 * @return {string} The key.
 */
function runKey(
	entry: RowActionEntry< ArticleItem >,
	row: ArticleItem
): string {
	return `${ row.id }:${ entry.key }`;
}

/**
 * Whether an extension row action is running on a row.
 *
 * @since 1.1.0
 *
 * @param {RowActionEntry} entry The extension's row action.
 * @param {ArticleItem}    row   The row.
 *
 * @return {boolean} True while its run is pending.
 */
export function isExtensionRowActionRunning(
	entry: RowActionEntry< ArticleItem >,
	row: ArticleItem
): boolean {
	return running.has( runKey( entry, row ) );
}

/**
 * Run an extension row action on a row, showing an error when it fails.
 *
 * Does nothing while the same action is already running on the row.
 *
 * @since 1.1.0
 *
 * @param {RowActionEntry} entry The extension's row action.
 * @param {ArticleItem}    row   The row it runs on.
 *
 * @return {Promise<void>} Resolves once the handler settles.
 */
export function runExtensionRowAction(
	entry: RowActionEntry< ArticleItem >,
	row: ArticleItem
): Promise< void > {
	const key = runKey( entry, row );

	if ( running.has( key ) ) {
		return Promise.resolve();
	}

	running.add( key );

	return Promise.resolve()
		.then( () => entry.run( row ) )
		.then(
			() => undefined,
			( error: unknown ) => {
				// eslint-disable-next-line no-console
				console.error( `Row action ${ entry.key } failed:`, error );
				message.error(
					sprintf(
						/* translators: %s: the row action's label. */
						__(
							'%s could not be completed.',
							'licensio-knowledge-base'
						),
						entry.label
					)
				);
			}
		)
		.finally( () => {
			running.delete( key );
		} );
}
