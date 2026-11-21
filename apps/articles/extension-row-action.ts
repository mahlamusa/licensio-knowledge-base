/**
 * Running an extension row action in the Articles app.
 *
 * Calls the entry's handler with the row and waits for it. A handler that
 * throws, or returns a promise that rejects, gets the app's error toast with
 * a generic message; the extension can still show its own. The returned
 * promise never rejects.
 *
 * @since 1.1.0
 */

import { message } from 'ant-design-vue';
import { __, sprintf } from '../components/i18n';
import type { RowActionEntry } from '../components/row-actions';
import type { ArticleItem } from './types';

/**
 * Run an extension row action on a row, showing an error when it fails.
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
	return Promise.resolve()
		.then( () => entry.run( row ) )
		.then(
			() => undefined,
			() => {
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
		);
}
