<?php
/**
 * Seed data for the Playwright suite.
 *
 * Run once against a fresh site by `bin/e2e-env.sh`, through
 * `wp eval-file -`. Gives every spec the content it reads but does not make
 * itself: published articles the search type-ahead finds, a page carrying the
 * search form, and a small BetterDocs data set, imported once, so the Migration
 * tab is shown and its log has a run to read.
 *
 * Every item is looked up before it is made, so running it twice changes
 * nothing.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Find a post by type and title, or make it.
 *
 * @since 1.0.0
 *
 * @param string               $post_type The post type.
 * @param string               $title     The post title.
 * @param array<string, mixed> $postarr   The rest of the post, as wp_insert_post() takes it.
 *
 * @return int The post ID.
 */
function licensio_kb_e2e_post( $post_type, $title, array $postarr = array() ) {
    $found = get_posts(
        array(
            'post_type'   => $post_type,
            'post_status' => 'any',
            'title'       => $title,
            'fields'      => 'ids',
            'numberposts' => 1,
        )
    );

    if ( ! empty( $found ) ) {
        return (int) $found[0];
    }

    return (int) wp_insert_post(
        array_merge(
            array(
                'post_type'   => $post_type,
                'post_status' => 'publish',
                'post_title'  => $title,
            ),
            $postarr
        )
    );
}

/**
 * Find a term by name, or make it.
 *
 * @since 1.0.0
 *
 * @param string $taxonomy The taxonomy.
 * @param string $name     The term name.
 *
 * @return int The term ID.
 */
function licensio_kb_e2e_term( $taxonomy, $name ) {
    $found = get_term_by( 'name', $name, $taxonomy );

    if ( $found ) {
        return (int) $found->term_id;
    }

    $made = wp_insert_term( $name, $taxonomy );

    return is_wp_error( $made ) ? 0 : (int) $made['term_id'];
}

// Plain permalinks, so every spec proves the REST root as a query string.
// Set it on the rewrite object too, or the flush stores the old pretty rules.
global $wp_rewrite;
$wp_rewrite->set_permalink_structure( '' );
flush_rewrite_rules();

// Articles for the search type-ahead and every list the apps show.
$licensio_kb_e2e_category = licensio_kb_e2e_term( 'licensio_category', 'Getting started' );

foreach ( array( 'Activating your licence', 'Renewing a licence key' ) as $licensio_kb_e2e_title ) {
    $licensio_kb_e2e_article = licensio_kb_e2e_post(
        'licensio_article',
        $licensio_kb_e2e_title,
        array( 'post_content' => '<!-- wp:paragraph --><p>Every licence key unlocks updates and support.</p><!-- /wp:paragraph -->' )
    );

    wp_set_object_terms( $licensio_kb_e2e_article, array( $licensio_kb_e2e_category ), 'licensio_category' );
}

// The page the search type-ahead spec opens.
licensio_kb_e2e_post(
    'page',
    'Knowledge base search',
    array(
        'post_name'    => 'kb-search-e2e',
        'post_content' => '<!-- wp:shortcode -->[licensio_kb_search]<!-- /wp:shortcode -->',
    )
);

/*
 * A BetterDocs source. Detection reads the data, not whether BetterDocs is
 * active, so registering its post type and taxonomies for this run is enough
 * to store a few rows the Migration tab can find.
 */
register_post_type( 'docs' );
register_taxonomy( 'doc_category', 'docs', array( 'hierarchical' => true ) );
register_taxonomy( 'doc_tag', 'docs' );

$licensio_kb_e2e_doc = licensio_kb_e2e_post(
    'docs',
    'Installing the plugin',
    array(
        'post_content' => '<p>Upload the zip and activate it.</p>',
        'meta_input'   => array( '_betterdocs_meta_views' => 12 ),
    )
);

wp_set_object_terms( $licensio_kb_e2e_doc, array( licensio_kb_e2e_term( 'doc_category', 'Installation' ) ), 'doc_category' );
wp_set_object_terms( $licensio_kb_e2e_doc, array( licensio_kb_e2e_term( 'doc_tag', 'setup' ) ), 'doc_tag' );

/*
 * One real import of that source, so the migration log holds a created
 * article with its meta and filing. The wizard spec only ever dry-runs, and a
 * dry run records what it would do rather than what it did.
 */
$licensio_kb_e2e_driver = ( new Licensio_KB_Importer() )->get_driver( 'betterdocs' );

if ( $licensio_kb_e2e_driver && ! $licensio_kb_e2e_driver->is_complete() ) {
    do {
        $licensio_kb_e2e_progress = $licensio_kb_e2e_driver->run_batch( array( 'batch_size' => 50 ) );
    } while ( empty( $licensio_kb_e2e_progress['finished'] ) );
}
