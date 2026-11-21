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
import { useArticlesStore } from './store';
import type { ExtensionContext } from '../components/extensions';

/**
 * The extension context of the Articles app.
 *
 * Call it where Pinia is active, such as a component's setup.
 *
 * @since 1.1.0
 *
 * @return {ExtensionContext} The context.
 */
export function articlesExtensionContext(): ExtensionContext {
	// A Pinia store, not a React hook.
	// eslint-disable-next-line react-hooks/rules-of-hooks
	const store = useArticlesStore();
	const { root, nonce } = config();

	return {
		refresh: ( ids ) => store.refreshRows( ids ),
		clearSelection: () => store.clearSelection(),
		screen: 'articles',
		rest: { root, nonce },
	};
}
