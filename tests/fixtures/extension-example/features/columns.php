<?php
/**
 * Example extension: columns in the Articles app.
 *
 * Declares two Articles columns in an "Example" group, both hidden until the
 * user turns them on, so the other browser tests never see them:
 *
 * - `example/id`, after the title and sortable, rendered by a script as
 *   `#<ID>`; sorting by it sorts by article ID.
 * - `example/batch`, with no script, so the app shows its `text`: how many
 *   times the batch filter ran on the request, which stays 1 whatever the
 *   page size, as the data of a whole page comes from one batch.
 *
 * @package Licensio_KB
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'licensio_kb_register_extensions', 'licensio_kb_example_columns_register' );
add_filter( 'licensio_kb_article_extension_data_batch', 'licensio_kb_example_columns_batch', 10, 3 );
add_filter( 'licensio_kb_articles_query_args', 'licensio_kb_example_columns_sort', 10, 3 );
add_action( 'licensio_kb_enqueue_app_extensions', 'licensio_kb_example_columns_script' );

/**
 * Declare the example columns.
 *
 * @since 1.1.0
 *
 * @param Licensio_KB_Extensions $extensions The registry.
 *
 * @return void
 */
function licensio_kb_example_columns_register( $extensions ) {
    $extensions->add_column(
        'articles',
        'example/id',
        array(
            'label'    => 'Example ID',
            'group'    => 'Example',
            'sortable' => true,
            'position' => 'title',
        )
    );
    $extensions->add_column(
        'articles',
        'example/batch',
        array(
            'label' => 'Example batch',
            'group' => 'Example',
        )
    );
}

/**
 * Fill the example columns of a whole page at once.
 *
 * @since 1.1.0
 *
 * @param array<int, array<string, array<string, mixed>>> $data  Article ID to column key to data.
 * @param WP_Post[]                                       $posts The page of articles.
 * @param string[]                                        $keys  The extension column keys shown.
 *
 * @return array<int, array<string, array<string, mixed>>> The data.
 */
function licensio_kb_example_columns_batch( $data, $posts, $keys ) {
    static $batches = 0;

    ++$batches;

    foreach ( $posts as $post ) {
        if ( in_array( 'example/id', $keys, true ) ) {
            $data[ $post->ID ]['example/id'] = array(
                'text' => (string) $post->ID,
                'id'   => (int) $post->ID,
            );
        }

        if ( in_array( 'example/batch', $keys, true ) ) {
            $data[ $post->ID ]['example/batch'] = array( 'text' => 'batch ' . $batches );
        }
    }

    return $data;
}

/**
 * Sort the list by article ID when it is sorted by the example ID column.
 *
 * @since 1.1.0
 *
 * @param array<string, mixed>  $args        The query arguments.
 * @param array<string, string> $ext_filters The extension filters chosen.
 * @param WP_REST_Request       $request     The request.
 *
 * @return array<string, mixed> The query arguments.
 */
function licensio_kb_example_columns_sort( $args, $ext_filters, $request ) {
    unset( $ext_filters );

    if ( 'example/id' === $request->get_param( 'orderby' ) ) {
        $args['orderby'] = array( 'ID' => 'asc' === $request->get_param( 'order' ) ? 'ASC' : 'DESC' );
    }

    return $args;
}

/**
 * Enqueue the script that renders the example ID column.
 *
 * @since 1.1.0
 *
 * @param string $screen `articles`, `categories` or `tags`.
 *
 * @return void
 */
function licensio_kb_example_columns_script( $screen ) {
    if ( 'articles' !== $screen ) {
        return;
    }

    wp_register_script( 'licensio-kb-example-columns', false, array( 'licensio-kb-extensions' ), '1.1.0', false );
    wp_enqueue_script( 'licensio-kb-example-columns' );
    wp_add_inline_script(
        'licensio-kb-example-columns',
        'window.licensioKb.extensions.register( "articles", { columns: { "example/id": { render: function ( el, row ) {'
        . ' var data = row.extensions && row.extensions[ "example/id" ];'
        . ' if ( ! data ) { return; }'
        . ' var badge = document.createElement( "span" );'
        . ' badge.className = "example-id";'
        . ' badge.textContent = "#" + data.id;'
        . ' el.appendChild( badge );'
        . ' } } } } );'
    );
}
