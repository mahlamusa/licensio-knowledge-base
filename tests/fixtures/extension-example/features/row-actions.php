<?php
/**
 * Example row actions for the Articles app.
 *
 * Declares four row actions and registers handlers for three of them:
 *
 * - `example/retitle` adds " (retitled)" to the article's title through the
 *   knowledge base's REST route, then refreshes just that row.
 * - `example/fails` returns a promise that rejects, so the app shows its
 *   error toast.
 * - `example/no-script` has no handler, so the app hides it.
 * - `example/forbidden` needs a capability nobody has, so it never reaches the
 *   app config.
 *
 * Each run is recorded on `window.licensioKbExampleRowRuns`, so the browser
 * tests can check what the handler was given.
 *
 * @package Licensio_KB
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'licensio_kb_register_extensions', 'licensio_kb_example_row_actions' );
add_action( 'licensio_kb_enqueue_app_extensions', 'licensio_kb_example_row_actions_script', 10, 2 );

/**
 * Declare the example row actions of the Articles app.
 *
 * @since 1.1.0
 *
 * @param Licensio_KB_Extensions $extensions The registry.
 *
 * @return void
 */
function licensio_kb_example_row_actions( $extensions ) {
    $extensions->add_row_action( 'articles', 'example/retitle', array( 'label' => 'Example retitle' ) );
    $extensions->add_row_action( 'articles', 'example/fails', array( 'label' => 'Example fails' ) );
    $extensions->add_row_action( 'articles', 'example/no-script', array( 'label' => 'Example no script' ) );
    $extensions->add_row_action(
        'articles',
        'example/forbidden',
        array(
            'label'      => 'Example forbidden',
            'capability' => 'licensio_kb_example_nobody',
        )
    );
}

/**
 * Register the handlers of the example row actions on the Articles app.
 *
 * @since 1.1.0
 *
 * @param string $screen      The app being enqueued.
 * @param string $hook_suffix The admin page's hook suffix.
 *
 * @return void
 */
function licensio_kb_example_row_actions_script( $screen, $hook_suffix ) {
    unset( $hook_suffix );

    if ( 'articles' !== $screen ) {
        return;
    }

    wp_register_script( 'licensio-kb-example-row-actions', false, array( 'licensio-kb-extensions' ), '1.1.0', false );
    wp_enqueue_script( 'licensio-kb-example-row-actions' );
    wp_add_inline_script(
        'licensio-kb-example-row-actions',
        <<<'JS'
( function () {
	function record( row, ctx ) {
		window.licensioKbExampleRowRuns = ( window.licensioKbExampleRowRuns || [] ).concat( [ { id: row.id, screen: ctx.screen } ] );
	}

	window.licensioKb.extensions.register( 'articles', {
		rowActions: {
			'example/retitle': {
				run: function ( row, ctx ) {
					record( row, ctx );

					return fetch( ctx.rest.root.replace( /\/+$/, '' ) + '/articles/' + row.id, {
						method: 'PATCH',
						credentials: 'same-origin',
						headers: { 'X-WP-Nonce': ctx.rest.nonce, 'Content-Type': 'application/json' },
						body: JSON.stringify( { title: row.title_raw + ' (retitled)' } ),
					} ).then( function () {
						return ctx.refresh( [ row.id ] );
					} );
				},
			},
			'example/fails': {
				run: function ( row, ctx ) {
					record( row, ctx );

					return Promise.reject( new Error( 'Example failure' ) );
				},
			},
			'example/forbidden': {
				run: record,
			},
		},
	} );
} )();
JS
    );
}
