/**
 * Extension registry of the content manager apps.
 *
 * Defines `window.licensioKb.extensions`, where other plugins register the
 * behaviour of the columns, bulk actions and row actions they declared in PHP
 * through `Licensio_KB_Extensions`:
 *
 *     window.licensioKb.extensions.register( 'articles', {
 *         columns: { 'acme/score': { render( el, row, ctx ) { … return cleanup; } } },
 *         bulkActions: { 'acme/check': { run( ids, ctx ) { … } } },
 *         rowActions: { 'acme/edit': { run( row, ctx ) { … } } },
 *     } );
 *
 * `ctx` gives `refresh( ids? )`, `clearSelection()`, `screen` and `rest`
 * (`root` and `nonce`). The apps read the registered handlers when they render,
 * so a script may register before or after the app's own script runs. The
 * TypeScript types are in `apps/components/extensions.ts`.
 *
 * Deliberately a CLASSIC script, not a Vite entry: the apps are ES modules,
 * which run after every classic script on the page, and the extension scripts
 * that call `register()` are classic scripts. Served straight from
 * `assets/js/` and never built, like `licensio-kb.js`.
 *
 * Enqueued as a dependency of the Articles, Categories and Tags apps.
 *
 * @since 1.1.0
 */

( function () {
	'use strict';

	const root = ( window.licensioKb = window.licensioKb || {} );

	if ( root.extensions ) {
		return;
	}

	const SCREENS = [ 'articles', 'categories', 'tags' ];
	const SECTIONS = [ 'columns', 'bulkActions', 'rowActions' ];
	const handlers = {};

	SCREENS.forEach( ( screen ) => {
		handlers[ screen ] = { columns: {}, bulkActions: {}, rowActions: {} };
	} );

	root.extensions = {
		/**
		 * Register the handlers of one screen's extensions.
		 *
		 * A key registered again replaces the earlier handler.
		 *
		 * @since 1.1.0
		 *
		 * @param {string} screen     `articles`, `categories` or `tags`.
		 * @param {Object} definition The `columns`, `bulkActions` and
		 *                            `rowActions` handlers, keyed by the
		 *                            keys declared in PHP.
		 *
		 * @return {boolean} False when the screen or definition is invalid.
		 */
		register( screen, definition ) {
			if (
				! handlers[ screen ] ||
				! definition ||
				'object' !== typeof definition
			) {
				return false;
			}

			SECTIONS.forEach( ( section ) => {
				const items = definition[ section ];

				if ( ! items || 'object' !== typeof items ) {
					return;
				}

				Object.keys( items ).forEach( ( key ) => {
					handlers[ screen ][ section ][ key ] = items[ key ];
				} );
			} );

			return true;
		},

		/**
		 * The handlers registered for one screen.
		 *
		 * @since 1.1.0
		 *
		 * @param {string} screen `articles`, `categories` or `tags`.
		 *
		 * @return {Object} The `columns`, `bulkActions` and `rowActions`
		 *                  handlers, empty for an unknown screen.
		 */
		get( screen ) {
			return (
				handlers[ screen ] || {
					columns: {},
					bulkActions: {},
					rowActions: {},
				}
			);
		},
	};
} )();
