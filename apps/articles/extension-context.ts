/**
 * What the Articles app gives extension handlers to talk back to it.
 *
 * `refresh( ids )` fetches those rows again with the current filters, or the
 * whole page when no IDs are given; `clearSelection()` drops the list's
 * selection; `rest` is the `licensio-kb/v1` root and nonce the page localised.
 *
 * @since 1.1.0
 */

import { config } from './api';
import type { useArticlesStore } from './store';
import type { ExtensionContext } from '../components/extensions';

/**
 * The extension context of the Articles app.
 *
 * @since 1.1.0
 *
 * @param {Object} store The app's store.
 *
 * @return {ExtensionContext} The context.
 */
export function articlesExtensionContext(
	store: ReturnType< typeof useArticlesStore >
): ExtensionContext {
	const { root, nonce } = config();

	return {
		refresh: ( ids ) => store.refreshRows( ids ),
		clearSelection: () => store.clearSelection(),
		screen: 'articles',
		rest: { root, nonce },
	};
}
