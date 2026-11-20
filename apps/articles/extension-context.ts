/**
 * The context the Articles app gives extension handlers.
 *
 * What a column's `render`, a bulk action's `run` or a row action's `run`
 * receives to talk back to the Articles app (`ExtensionContext`): reload some
 * rows or the page, clear the selection, and the REST root and nonce.
 *
 * Usage, inside a component's setup:
 *
 *     const ctx = useArticlesExtensionContext();
 *
 * Consumed by the Articles app's extension columns and actions.
 *
 * @since 1.1.0
 */

import { config } from './api';
import { useArticlesStore } from './store';
import type { ExtensionContext } from '../components/extensions';

/**
 * The extension context of the Articles app.
 *
 * @since 1.1.0
 *
 * @return {ExtensionContext} The context, bound to the Articles store.
 */
export function useArticlesExtensionContext(): ExtensionContext {
	const store = useArticlesStore();
	const { root, nonce } = config();

	return {
		refresh: ( ids?: number[] ) => store.refreshRows( ids ?? [] ),
		clearSelection: () => store.clearSelection(),
		screen: 'articles',
		rest: { root, nonce },
	};
}
