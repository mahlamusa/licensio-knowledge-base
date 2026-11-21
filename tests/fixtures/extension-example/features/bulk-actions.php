<?php
/**
 * Example extension: bulk actions in the Articles app.
 *
 * Declares three bulk actions on the Articles app:
 *
 * - `example/retitle` has a handler. It records the IDs and screen it was
 *   given in `window.licensioKbExampleBulk`, renames each article to
 *   `Retitled <ID>` through the app's REST root and nonce, then calls
 *   `ctx.refresh( ids )` and `ctx.clearSelection()`.
 * - `example/no-handler` has no handler, so the app hides it.
 * - `example/forbidden` has a handler but needs a capability nobody has, so
 *   it is never sent to the app.
 *
 * @package Licensio_KB
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'licensio_kb_register_extensions', 'licensio_kb_example_bulk_actions' );
add_action( 'licensio_kb_enqueue_app_extensions', 'licensio_kb_example_bulk_actions_script', 10, 2 );

/**
 * Declare the example bulk actions.
 *
 * @since 1.1.0
 *
 * @param Licensio_KB_Extensions $extensions The extension registry.
 *
 * @return void
 */
function licensio_kb_example_bulk_actions( $extensions ) {
    $extensions->add_bulk_action( 'articles', 'example/retitle', array( 'label' => 'Example: retitle' ) );
    $extensions->add_bulk_action( 'articles', 'example/no-handler', array( 'label' => 'Example: no handler' ) );
    $extensions->add_bulk_action(
        'articles',
        'example/forbidden',
        array(
            'label'      => 'Example: forbidden',
            'capability' => 'licensio_kb_example_nobody',
        )
    );
}

/**
 * Enqueue the handlers of the example bulk actions on the Articles app.
 *
 * @since 1.1.0
 *
 * @param string $screen      `articles`, `categories` or `tags`.
 * @param string $hook_suffix The app page's hook suffix.
 *
 * @return void
 */
function licensio_kb_example_bulk_actions_script( $screen, $hook_suffix ) {
    unset( $hook_suffix );

    if ( 'articles' !== $screen ) {
        return;
    }

    $script = <<<'JS'
( function () {
	function retitle( ids, ctx ) {
		window.licensioKbExampleBulk = { ids: ids.slice(), screen: ctx.screen };

		return Promise.all(
			ids.map( function ( id ) {
				return fetch( ctx.rest.root + 'articles/' + id, {
					method: 'PATCH',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': ctx.rest.nonce,
					},
					body: JSON.stringify( { title: 'Retitled ' + id } ),
				} );
			} )
		)
			.then( function () {
				return ctx.refresh( ids );
			} )
			.then( function () {
				ctx.clearSelection();
			} );
	}

	window.licensioKb.extensions.register( 'articles', {
		bulkActions: {
			'example/retitle': { run: retitle },
			'example/forbidden': { run: retitle },
		},
	} );
} )();
JS;

    wp_register_script( 'licensio-kb-example-bulk-actions', false, array( 'licensio-kb-extensions' ), '1.1.0', false );
    wp_enqueue_script( 'licensio-kb-example-bulk-actions' );
    wp_add_inline_script( 'licensio-kb-example-bulk-actions', $script );
}
