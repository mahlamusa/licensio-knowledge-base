<?php
/**
 * Example labels with characters HTML gives a meaning to.
 *
 * Declares a column, a column group, a filter and its options, a bulk action
 * and row actions whose labels hold a less-than sign, an ampersand, a percent
 * sequence and an encoded closing script tag, so the browser tests can check
 * that every app shows them exactly as declared, as text.
 *
 * Nothing is declared unless the option `licensio_kb_example_labels` is on,
 * so the extra items never change the apps under other specs. The labels spec
 * turns it on and off again.
 *
 * @package Licensio_KB
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'licensio_kb_register_extensions', 'licensio_kb_example_labels_declare' );
add_action( 'licensio_kb_enqueue_app_extensions', 'licensio_kb_example_labels_script', 10, 2 );

/**
 * Declare the items with the awkward labels.
 *
 * @since 1.1.0
 *
 * @param Licensio_KB_Extensions $extensions The registry.
 *
 * @return void
 */
function licensio_kb_example_labels_declare( $extensions ) {
    if ( ! get_option( 'licensio_kb_example_labels' ) ) {
        return;
    }

    $extensions->add_column(
        'articles',
        'example/label-words',
        array(
            'label'           => 'Words < 300',
            'group'           => 'Score < 50 &amp; up',
            'default_visible' => true,
        )
    );
    $extensions->add_filter(
        'articles',
        'example/label-ctr',
        array(
            'label'   => 'CTR %ab',
            'options' => array(
                'low'    => 'Words <300 & less',
                'script' => '&lt;/script&gt; end',
            ),
        )
    );
    $extensions->add_bulk_action( 'articles', 'example/label-bulk', array( 'label' => 'Score < 50 & up' ) );

    foreach ( array( 'articles', 'categories', 'tags' ) as $screen ) {
        $extensions->add_row_action( $screen, 'example/label-row', array( 'label' => 'CTR %ab < 5 & more' ) );
    }

    foreach ( array( 'categories', 'tags' ) as $screen ) {
        $extensions->add_column(
            $screen,
            'example/label-term',
            array(
                'label'           => 'Words < 300 & up',
                'default_visible' => true,
            )
        );
    }
}

/**
 * Give the bulk and row actions a handler, so the apps offer them.
 *
 * @since 1.1.0
 *
 * @param string $screen      `articles`, `categories` or `tags`.
 * @param string $hook_suffix The admin page's hook suffix.
 *
 * @return void
 */
function licensio_kb_example_labels_script( $screen, $hook_suffix ) {
    unset( $hook_suffix );

    if ( ! get_option( 'licensio_kb_example_labels' ) ) {
        return;
    }

    $definition = array(
        'rowActions' => array( 'example/label-row' => true ),
    );

    if ( 'articles' === $screen ) {
        $definition['bulkActions'] = array( 'example/label-bulk' => true );
    }

    wp_register_script( 'licensio-kb-example-labels', false, array( 'licensio-kb-extensions' ), '1.1.0', false );
    wp_enqueue_script( 'licensio-kb-example-labels' );
    wp_add_inline_script(
        'licensio-kb-example-labels',
        sprintf(
            '( function ( screen, keys ) {
                var noop = { run: function () { return Promise.resolve(); } };
                var definition = {};
                Object.keys( keys ).forEach( function ( section ) {
                    definition[ section ] = {};
                    Object.keys( keys[ section ] ).forEach( function ( key ) {
                        definition[ section ][ key ] = noop;
                    } );
                } );
                window.licensioKb.extensions.register( screen, definition );
            } )( %s, %s );',
            wp_json_encode( $screen ),
            wp_json_encode( $definition )
        )
    );
}
