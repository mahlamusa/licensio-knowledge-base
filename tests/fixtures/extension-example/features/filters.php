<?php
/**
 * Example extension: a filter in the Articles app.
 *
 * Declares the "Example flag" filter, whose options narrow the list to the
 * articles with or without the `_licensio_kb_example_flag` meta, and maps the
 * chosen option onto the list query on `licensio_kb_articles_query_args`. Its
 * `query_arg` is `example_flag`, so a core screen link such as
 * `edit.php?post_type=licensio_article&example_flag=flagged` opens the app
 * with the filter set.
 *
 * @package Licensio_KB
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'licensio_kb_register_extensions', 'licensio_kb_example_register_filters' );
add_filter( 'licensio_kb_articles_query_args', 'licensio_kb_example_filter_query', 10, 2 );

/**
 * Declare the example filter.
 *
 * @since 1.1.0
 *
 * @param Licensio_KB_Extensions $extensions The registry.
 *
 * @return void
 */
function licensio_kb_example_register_filters( $extensions ) {
    $extensions->add_filter(
        'articles',
        'example/flag',
        array(
            'label'     => 'Example flag',
            'query_arg' => 'example_flag',
            'options'   => array(
                'flagged'   => 'Flagged',
                'unflagged' => 'Not flagged',
            ),
        )
    );
}

/**
 * Narrow the article list by the example flag.
 *
 * Adds a meta clause next to any the query already has, so the core
 * filters and other extensions keep theirs.
 *
 * @since 1.1.0
 *
 * @param array<string, mixed>  $args        The WP_Query arguments.
 * @param array<string, string> $ext_filters The chosen extension filter options.
 *
 * @return array<string, mixed> The narrowed arguments.
 */
function licensio_kb_example_filter_query( $args, $ext_filters ) {
    if ( empty( $ext_filters['example/flag'] ) ) {
        return $args;
    }

    $clause = 'flagged' === $ext_filters['example/flag']
        ? array(
            'key'   => '_licensio_kb_example_flag',
            'value' => '1',
        )
        : array(
            'key'     => '_licensio_kb_example_flag',
            'compare' => 'NOT EXISTS',
        );

    $meta_query   = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
    $meta_query[] = $clause;

    $args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The example filter is a meta filter by definition.

    return $args;
}
