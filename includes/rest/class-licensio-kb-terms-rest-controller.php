<?php
/**
 * Read-only term search for the content manager pickers.
 *
 * `GET /licensio-kb/v1/terms` is the data source of the shared TermSelect
 * component, which the articles, categories and tags apps all use to pick a
 * category or a tag. It exists apart from the categories and tags endpoints so
 * none of the three apps depends on another being built first, and so the apps
 * keep to the plugin's own namespace rather than core's `wp/v2` routes.
 *
 * Query: `taxonomy` (required, `licensio_category` or `licensio_tag`),
 * `search`, `include[]` and `exclude[]` (up to 100 IDs each) and `per_page`
 * (1 to 100, default 20).
 * Answers an array of `{ id, name, slug, parent, count, path }`, where `path`
 * lists the names of the term's ancestors, top first, so a picker can label a
 * subcategory "Parent › Child". It is always empty for tags. Names are plain
 * text: core stores `&` as `&amp;`, so they are decoded here for the apps,
 * which render them as text.
 *
 * Consumed by the articles, categories and tags apps. Anyone who may write
 * articles may search, since authors and contributors pick the categories and
 * tags of their own articles; core's `wp/v2` term routes are public anyway.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves the term search route.
 *
 * @since 1.0.0
 */
class Licensio_KB_Terms_REST_Controller extends Licensio_KB_REST_Controller {

    /**
     * The route base.
     *
     * @since 1.0.0
     * @var string
     */
    protected $rest_base = 'terms';

    /**
     * The most terms one request returns.
     *
     * @since 1.0.0
     * @var int
     */
    const MAX_PER_PAGE = 100;

    /**
     * Register the term search route.
     *
     * @since 1.0.0 Open to every user who may write articles or manage the knowledge base.
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'get_items' ),
                    'permission_callback' => array( $this, 'search_permissions_check' ),
                    'args'                => $this->get_items_args(),
                ),
            )
        );
    }

    /**
     * Permission for the term search: write articles or manage the knowledge base.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return true|WP_Error True when allowed.
     */
    public function search_permissions_check( $request ) {
        if ( true === $this->edit_articles_permissions_check( $request ) ) {
            return true;
        }

        return $this->manage_permissions_check( $request );
    }

    /**
     * The accepted arguments for a term search.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> The argument schema.
     */
    public function get_items_args() {
        $ids = array(
            'type'    => 'array',
            'items'   => array(
                'type'    => 'integer',
                'minimum' => 1,
            ),
            'default' => array(),
        );

        return array(
            'taxonomy' => array(
                'required'    => true,
                'type'        => 'string',
                'enum'        => array( Licensio_KB_Post_Types::TAXONOMY_CATEGORY, Licensio_KB_Post_Types::TAXONOMY_TAG ),
                'description' => __( 'The taxonomy to search.', 'licensio-knowledge-base' ),
            ),
            'search'   => array(
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'Limit the terms to names or slugs containing this text.', 'licensio-knowledge-base' ),
            ),
            'include'  => array_merge(
                $ids,
                array(
                    'maxItems'    => self::MAX_PER_PAGE,
                    'description' => __( 'Return only these term IDs.', 'licensio-knowledge-base' ),
                )
            ),
            // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Term IDs for get_terms(), not a post query, capped at 100.
            'exclude'  => array_merge(
                $ids,
                array(
                    'maxItems'    => self::MAX_PER_PAGE,
                    'description' => __( 'Leave these term IDs out.', 'licensio-knowledge-base' ),
                )
            ),
            'per_page' => array(
                'type'              => 'integer',
                'minimum'           => 1,
                'maximum'           => self::MAX_PER_PAGE,
                'default'           => 20,
                'sanitize_callback' => 'absint',
                'description'       => __( 'How many terms to return.', 'licensio-knowledge-base' ),
            ),
        );
    }

    /**
     * Search one plugin taxonomy.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response|WP_Error The matching terms, ordered by name.
     */
    public function get_items( $request ) {
        $taxonomy = (string) $request->get_param( 'taxonomy' );
        $include  = array_map( 'absint', (array) $request->get_param( 'include' ) );
        $per_page = min( self::MAX_PER_PAGE, max( 1, absint( $request->get_param( 'per_page' ) ) ) );

        $args = array(
            'taxonomy'               => $taxonomy,
            'hide_empty'             => false,
            'orderby'                => 'name',
            'order'                  => 'ASC',
            'number'                 => empty( $include ) ? $per_page : count( $include ),
            'search'                 => (string) $request->get_param( 'search' ),
            'include'                => $include,
            // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- A short, capped list of term IDs; get_terms() does not run the slow post__not_in query this warns about.
            'exclude'                => array_map( 'absint', (array) $request->get_param( 'exclude' ) ),

            // Nothing here reads term meta.
            'update_term_meta_cache' => false,
        );

        $terms = get_terms( $args );

        if ( is_wp_error( $terms ) ) {
            return $terms;
        }

        $terms = (array) $terms;
        $paths = $this->ancestor_paths( $terms, $taxonomy );
        $items = array();

        foreach ( $terms as $term ) {
            if ( ! $term instanceof WP_Term ) {
                continue;
            }

            $items[] = array(
                'id'     => (int) $term->term_id,
                'name'   => self::plain_name( $term->name ),
                'slug'   => $term->slug,
                'parent' => (int) $term->parent,
                'count'  => (int) $term->count,
                'path'   => isset( $paths[ (int) $term->term_id ] ) ? $paths[ (int) $term->term_id ] : array(),
            );
        }

        return rest_ensure_response( $items );
    }

    /**
     * The ancestor names of the given terms in a hierarchical taxonomy.
     *
     * Reads the whole taxonomy's IDs, parents and names in one query, so the
     * labels cost the same however deep the tree is. Category trees are small;
     * tags are not hierarchical, and a list of top-level terms has no
     * ancestors, so both skip the query.
     *
     * @since 1.0.0
     *
     * @param array<mixed> $terms    The terms to label.
     * @param string       $taxonomy The taxonomy.
     *
     * @return array<int, string[]> Term ID to ancestor names, top first, for each child term given.
     */
    private function ancestor_paths( array $terms, $taxonomy ) {
        if ( Licensio_KB_Post_Types::TAXONOMY_CATEGORY !== $taxonomy ) {
            return array();
        }

        $children = array();

        foreach ( $terms as $term ) {
            if ( $term instanceof WP_Term && (int) $term->parent > 0 ) {
                $children[] = (int) $term->term_id;
            }
        }

        if ( empty( $children ) ) {
            return array();
        }

        $all = get_terms(
            array(
                'taxonomy'               => $taxonomy,
                'hide_empty'             => false,
                'update_term_meta_cache' => false,
            )
        );

        if ( ! is_array( $all ) ) {
            return array();
        }

        $parents = array();
        $names   = array();

        foreach ( $all as $term ) {
            if ( $term instanceof WP_Term ) {
                $parents[ (int) $term->term_id ] = (int) $term->parent;
                $names[ (int) $term->term_id ]   = self::plain_name( $term->name );
            }
        }

        $paths = array();

        foreach ( $children as $term_id ) {
            $path    = array();
            $current = isset( $parents[ $term_id ] ) ? $parents[ $term_id ] : 0;
            $seen    = array( $term_id => true );

            // The seen list stops a corrupted parent loop from spinning forever.
            while ( isset( $names[ $current ] ) && ! isset( $seen[ $current ] ) ) {
                $seen[ $current ] = true;
                array_unshift( $path, $names[ $current ] );
                $current = $parents[ $current ];
            }

            $paths[ $term_id ] = $path;
        }

        return $paths;
    }

    /**
     * A term name as plain text.
     *
     * Core saves term names with HTML special characters encoded, so
     * "Billing & invoices" is stored as "Billing &amp; invoices". The apps
     * show names as text, never as HTML, so they need the decoded form.
     *
     * @since 1.0.0
     *
     * @param string $name The stored term name.
     *
     * @return string The name with HTML entities decoded.
     */
    private static function plain_name( $name ) {
        return html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' );
    }
}
