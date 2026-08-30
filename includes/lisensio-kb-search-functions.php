<?php
/**
 * Search entry points.
 *
 * Attaches the search component to `lisensio_loaded`, the hook the plugin
 * container fires once every shared component exists, and exposes the two
 * global helpers a theme or a sibling feature needs.
 *
 * The component is attached from here rather than constructed inside
 * Lisensio_KB::boot() because `lisensio_loaded` is the documented seam for a
 * sub-issue to attach its own components, and using it keeps the container a
 * scaffold-owned file that no feature branch has to edit.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * The knowledge base search component.
 *
 * @since 1.0.0
 *
 * @return Lisensio_KB_Search The search component.
 */
function lisensio_kb_search() {
    return Lisensio_KB_Search::instance();
}

/**
 * Run a knowledge base search.
 *
 * The one call a theme, a template or a sibling feature needs. Returns the
 * ranked articles themselves rather than IDs, hydrated through WP_Query so
 * permalinks, thumbnails and term caches all behave normally.
 *
 * @since 1.0.0
 *
 * @param string $term  The search term. Boolean operators are stripped.
 * @param int    $limit How many articles to return.
 *
 * @return WP_Post[] The matching articles, best first.
 */
function lisensio_kb_search_articles( $term, $limit = 10 ) {
    $query = lisensio_kb_search()->query();
    $found = $query->search( $term, array( 'limit' => (int) $limit ) );

    return $query->hydrate( $found['ids'] );
}

/**
 * Boot the search component.
 *
 * @since 1.0.0
 *
 * @return void
 */
function lisensio_kb_boot_search() {
    lisensio_kb_search()->init_hooks();
}

/**
 * Attach the search component to the container's ready hook.
 *
 * Split out from the call below so a unit test can drive the registration and
 * assert on it. Brain Monkey never runs a callback registered at file scope, so
 * a bare `add_action()` here would be untestable and could name a hook that
 * does not exist without anything noticing.
 *
 * @since 1.0.0
 *
 * @return void
 */
function lisensio_kb_register_search_bootstrap() {
    add_action( 'lisensio_loaded', 'lisensio_kb_boot_search' );
}

/*
 * Guarded because this file is also loaded by the unit bootstrap, which runs
 * before Brain Monkey has supplied the WordPress function set.
 */
if ( function_exists( 'add_action' ) ) {
    lisensio_kb_register_search_bootstrap();
}
