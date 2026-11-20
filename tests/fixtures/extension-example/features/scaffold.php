<?php
/**
 * Example extension: the shared pieces every feature relies on.
 *
 * On `licensio_kb_enqueue_app_extensions` it adds a classic script, depending
 * on the extension registry, that registers a handler for each app and
 * records the screens it ran on in `window.licensioKbExampleScreens`. The
 * handler's key is never declared in PHP, so no app shows it; the browser
 * test only checks that the action fired with the right screen and that a
 * script enqueued on it can reach the registry.
 *
 * @package Licensio_KB
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'licensio_kb_enqueue_app_extensions', 'licensio_kb_example_scaffold_script', 10, 2 );

/**
 * Enqueue the probe script on an app page.
 *
 * @since 1.1.0
 *
 * @param string $screen      `articles`, `categories` or `tags`.
 * @param string $hook_suffix The app page's hook suffix.
 *
 * @return void
 */
function licensio_kb_example_scaffold_script( $screen, $hook_suffix ) {
    unset( $hook_suffix );

    wp_register_script( 'licensio-kb-example-scaffold', false, array( 'licensio-kb-extensions' ), '1.1.0', false );
    wp_enqueue_script( 'licensio-kb-example-scaffold' );
    wp_add_inline_script(
        'licensio-kb-example-scaffold',
        sprintf(
            'window.licensioKbExampleScreens = ( window.licensioKbExampleScreens || [] ).concat( window.licensioKb.extensions.register( %1$s, { columns: { "example/probe": { render: function () {} } } } ) ? [ %1$s ] : [] );',
            wp_json_encode( $screen )
        )
    );
}
