/**
 * The context the Articles app gives extension handlers.
 *
 * Lets a column, bulk action or row action registered on
 * `window.licensioKb.extensions` talk back to the app: reload changed rows,
 * clear the selection, and call the REST API with the app's root and nonce.
 *
 * Consumed by the Articles app's extension bulk actions.
 *
 * @since 1.1.0
 */

import { config } from './api';
import { useArticlesStore as articlesStore } from './store';
import type { ExtensionContext } from '../components/extensions';

/**
 * The Articles app's extension context.
 *
 * @since 1.1.0
 *
 * @return {ExtensionContext} The context.
 */
export function articlesExtensionContext(): ExtensionContext {
	const store = articlesStore();
	const { root, nonce } = config();

	return {
		refresh: ( ids ) => store.refreshRows( ids ),
		clearSelection: () => store.clearSelection(),
		screen: 'articles',
		rest: { root, nonce },
	};
}
