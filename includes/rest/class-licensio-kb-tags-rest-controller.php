<?php
/**
 * The tag routes behind the Tags app.
 *
 * Lists, creates, edits, bulk deletes, merges and converts `licensio_tag`
 * terms:
 *
 * - `GET /tags`: `search`, `unused`, `include[]`, `orderby` (`name`, `count`),
 *   `order`, `page`, `per_page`. Answers `{ items, total, pages, page, per_page }`.
 *   Each item's `count` is the number of published articles with the tag,
 *   like category counts and core's own term count, so converting a tag to a
 *   category keeps the number shown. The count sort uses the same number.
 *   The Unused filter instead counts articles in any status but trash and
 *   auto-draft, so a tag only drafts, scheduled, pending or private articles
 *   use is not unused and deleting the Unused list never strips it from them;
 *   such a tag shows a count of 0. The single-tag answers of the write routes
 *   carry core's count; the app reads counts only from this list, which it
 *   reloads after every write.
 * - `POST /tags` and `PATCH /tags/{id}`: `name`, `slug`, `description`. As in
 *   core, a new tag with a taken slug gets a unique one; editing a tag to a
 *   slug another tag uses, or adding one with a name a tag has, is refused
 *   with a 409.
 * - `POST /tags/bulk-delete`: `{ ids[] }`.
 * - `POST /tags/merge`: `{ sources[], target }`, through
 *   `Licensio_KB_Term_Operations::merge_terms()`.
 * - `POST /tags/convert`: `{ ids[], parent }`. Each tag becomes a category
 *   with the same slug under `parent`, or reuses the category that already
 *   has that slug, or the child of `parent` that already has that name, keeps
 *   its articles, and is then deleted. A tag that fails is left as it was.
 *
 * Every route needs `manage_licensio_kb` and the matching capability of the
 * taxonomy it changes, and the writes also need `edit_term` or `delete_term`
 * on each existing term they touch, as the Categories routes do. Term names
 * are answered as plain text: core stores `&` as `&amp;`, and the app renders
 * them as text. Descriptions are answered as stored: decoding one and sending
 * it back on save would let kses turn escaped text such as `&lt;b&gt;` into
 * markup, or strip it.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves the tag management routes.
 *
 * @since 1.0.0
 */
class Licensio_KB_Tags_REST_Controller extends Licensio_KB_REST_Controller {

    /**
     * The route base.
     *
     * @since 1.0.0
     * @var string
     */
    protected $rest_base = 'tags';

    /**
     * The most tags one page, or one write request, may name.
     *
     * @since 1.0.0
     * @var int
     */
    const MAX_ITEMS = 100;

    /**
     * The shared term operations.
     *
     * @since 1.0.0
     * @var Licensio_KB_Term_Operations
     */
    private $term_operations;

    /**
     * Build the controller.
     *
     * The REST registrar builds controllers with no arguments, so the term
     * operations default to a new instance. They hold no state.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_Term_Operations|null $term_operations Optional. The term operations to use.
     */
    public function __construct( $term_operations = null ) {
        $this->term_operations = $term_operations instanceof Licensio_KB_Term_Operations ? $term_operations : new Licensio_KB_Term_Operations();
    }

    /**
     * Register the tag routes.
     *
     * @since 1.0.0
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
                    'permission_callback' => array( $this, 'read_permissions_check' ),
                    'args'                => $this->get_items_args(),
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'create_item' ),
                    'permission_callback' => array( $this, 'edit_permissions_check' ),
                    'args'                => $this->write_args( true ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)',
            array(
                array(
                    'methods'             => 'PATCH',
                    'callback'            => array( $this, 'update_item' ),
                    'permission_callback' => array( $this, 'update_permissions_check' ),
                    'args'                => array_merge(
                        array(
                            'id' => array(
                                'type'        => 'integer',
                                'minimum'     => 1,
                                'description' => __( 'The tag ID.', 'licensio-knowledge-base' ),
                            ),
                        ),
                        $this->write_args( false )
                    ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/bulk-delete',
            array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'bulk_delete' ),
                    'permission_callback' => array( $this, 'delete_permissions_check' ),
                    'args'                => array(
                        'ids' => $this->ids_arg( __( 'The tags to delete.', 'licensio-knowledge-base' ) ),
                    ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/merge',
            array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'merge' ),
                    'permission_callback' => array( $this, 'merge_permissions_check' ),
                    'args'                => array(
                        'sources' => $this->ids_arg( __( 'The tags to merge away.', 'licensio-knowledge-base' ) ),
                        'target'  => array(
                            'required'    => true,
                            'type'        => 'integer',
                            'minimum'     => 1,
                            'description' => __( 'The tag that remains.', 'licensio-knowledge-base' ),
                        ),
                    ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/convert',
            array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'convert' ),
                    'permission_callback' => array( $this, 'convert_permissions_check' ),
                    'args'                => array(
                        'ids'    => $this->ids_arg( __( 'The tags to convert.', 'licensio-knowledge-base' ) ),
                        'parent' => array(
                            'type'        => 'integer',
                            'minimum'     => 0,
                            'default'     => 0,
                            'description' => __( 'The category the new categories go under, or 0 for the top level.', 'licensio-knowledge-base' ),
                        ),
                    ),
                ),
            )
        );
    }

    /**
     * The accepted arguments for the tag list.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> The argument schema.
     */
    public function get_items_args() {
        return array(
            'search'   => array(
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'Limit the tags to names or slugs containing this text.', 'licensio-knowledge-base' ),
            ),
            'unused'   => array(
                'type'        => 'boolean',
                'default'     => false,
                'description' => __( 'Only tags no article uses, counting every status but trash and auto-draft.', 'licensio-knowledge-base' ),
            ),
            'include'  => array(
                'type'        => 'array',
                'items'       => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                ),
                'maxItems'    => self::MAX_ITEMS,
                'default'     => array(),
                'description' => __( 'Return only these tag IDs.', 'licensio-knowledge-base' ),
            ),
            'orderby'  => array(
                'type'        => 'string',
                'enum'        => array( 'name', 'count' ),
                'default'     => 'name',
                'description' => __( 'Sort by name or by published article count.', 'licensio-knowledge-base' ),
            ),
            'order'    => array(
                'type'        => 'string',
                'enum'        => array( 'asc', 'desc' ),
                'default'     => 'asc',
                'description' => __( 'The sort direction.', 'licensio-knowledge-base' ),
            ),
            'page'     => array(
                'type'              => 'integer',
                'minimum'           => 1,
                'default'           => 1,
                'sanitize_callback' => 'absint',
                'description'       => __( 'The page to return.', 'licensio-knowledge-base' ),
            ),
            'per_page' => array(
                'type'              => 'integer',
                'minimum'           => 1,
                'maximum'           => self::MAX_ITEMS,
                'default'           => 20,
                'sanitize_callback' => 'absint',
                'description'       => __( 'How many tags a page holds.', 'licensio-knowledge-base' ),
            ),
        );
    }

    /**
     * The accepted arguments for creating or editing a tag.
     *
     * A description keeps the HTML a post may hold, as the core tag screen
     * and the Categories app allow, so editing one does not strip its links
     * or formatting.
     *
     * @since 1.0.0
     *
     * @param bool $creating True for a new tag, which must have a name.
     *
     * @return array<string, array<string, mixed>> The argument schema.
     */
    public function write_args( $creating ) {
        return array(
            'name'        => array(
                'required'          => (bool) $creating,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'The tag name.', 'licensio-knowledge-base' ),
            ),
            'slug'        => array(
                'type'              => 'string',
                'sanitize_callback' => array( $this, 'sanitize_slug' ),
                'description'       => __( 'The tag slug. A new tag with a taken slug gets a unique one; an edit to a slug another tag uses is refused. An empty slug is made from the name.', 'licensio-knowledge-base' ),
            ),
            'description' => array(
                'type'              => 'string',
                'sanitize_callback' => 'wp_kses_post',
                'description'       => __( 'The tag description.', 'licensio-knowledge-base' ),
            ),
        );
    }

    /**
     * The schema of a required list of tag IDs.
     *
     * The list is capped because every write route that takes one deletes
     * the tags it names.
     *
     * @since 1.0.0
     *
     * @param string $description What the IDs are for.
     *
     * @return array<string, mixed> The argument schema.
     */
    private function ids_arg( $description ) {
        return array(
            'required'    => true,
            'type'        => 'array',
            'items'       => array(
                'type'    => 'integer',
                'minimum' => 1,
            ),
            'minItems'    => 1,
            'maxItems'    => self::MAX_ITEMS,
            'description' => $description,
        );
    }

    /**
     * Permission callback for reading tags.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return true|WP_Error True when the current user may list tags.
     */
    public function read_permissions_check( $request ) {
        return $this->taxonomy_permissions_check( $request, array( array( Licensio_KB_Post_Types::TAXONOMY_TAG, 'manage_terms' ) ) );
    }

    /**
     * Permission callback for creating and editing tags.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return true|WP_Error True when the current user may edit tags.
     */
    public function edit_permissions_check( $request ) {
        return $this->taxonomy_permissions_check( $request, array( array( Licensio_KB_Post_Types::TAXONOMY_TAG, 'edit_terms' ) ) );
    }

    /**
     * Permission callback for editing one tag.
     *
     * Also needs `edit_term` on the tag, as the Categories routes do.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return true|WP_Error True when the current user may edit this tag.
     */
    public function update_permissions_check( $request ) {
        $allowed = $this->edit_permissions_check( $request );

        if ( true !== $allowed ) {
            return $allowed;
        }

        return self::term_permissions_check( 'edit_term', Licensio_KB_Post_Types::TAXONOMY_TAG, $request->get_param( 'id' ) );
    }

    /**
     * Permission callback for deleting tags.
     *
     * Also needs `delete_term` on each tag.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return true|WP_Error True when the current user may delete these tags.
     */
    public function delete_permissions_check( $request ) {
        $allowed = $this->taxonomy_permissions_check( $request, array( array( Licensio_KB_Post_Types::TAXONOMY_TAG, 'delete_terms' ) ) );

        if ( true !== $allowed ) {
            return $allowed;
        }

        return self::term_permissions_check( 'delete_term', Licensio_KB_Post_Types::TAXONOMY_TAG, $request->get_param( 'ids' ) );
    }

    /**
     * Permission callback for merging tags, which edits one and deletes others.
     *
     * Merging also moves the sources' articles onto the target, so it needs
     * the tag assign capability too, then `delete_term` on each source and
     * `edit_term` on the target.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return true|WP_Error True when the current user may merge these tags.
     */
    public function merge_permissions_check( $request ) {
        $allowed = $this->taxonomy_permissions_check(
            $request,
            array(
                array( Licensio_KB_Post_Types::TAXONOMY_TAG, 'edit_terms' ),
                array( Licensio_KB_Post_Types::TAXONOMY_TAG, 'delete_terms' ),
                array( Licensio_KB_Post_Types::TAXONOMY_TAG, 'assign_terms' ),
            )
        );

        if ( true !== $allowed ) {
            return $allowed;
        }

        $allowed = self::term_permissions_check( 'delete_term', Licensio_KB_Post_Types::TAXONOMY_TAG, $request->get_param( 'sources' ) );

        if ( true !== $allowed ) {
            return $allowed;
        }

        return self::term_permissions_check( 'edit_term', Licensio_KB_Post_Types::TAXONOMY_TAG, $request->get_param( 'target' ) );
    }

    /**
     * Permission callback for converting tags to categories.
     *
     * Converting deletes tags, creates categories and assigns them to
     * articles, so it needs all three capabilities, then `delete_term` on
     * each tag and `edit_term` on the parent category the new ones go under.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return true|WP_Error True when the current user may convert these tags.
     */
    public function convert_permissions_check( $request ) {
        $allowed = $this->taxonomy_permissions_check(
            $request,
            array(
                array( Licensio_KB_Post_Types::TAXONOMY_TAG, 'delete_terms' ),
                array( Licensio_KB_Post_Types::TAXONOMY_CATEGORY, 'edit_terms' ),
                array( Licensio_KB_Post_Types::TAXONOMY_CATEGORY, 'assign_terms' ),
            )
        );

        if ( true !== $allowed ) {
            return $allowed;
        }

        $allowed = self::term_permissions_check( 'delete_term', Licensio_KB_Post_Types::TAXONOMY_TAG, $request->get_param( 'ids' ) );

        if ( true !== $allowed ) {
            return $allowed;
        }

        return self::term_permissions_check( 'edit_term', Licensio_KB_Post_Types::TAXONOMY_CATEGORY, $request->get_param( 'parent' ) );
    }

    /**
     * Check the management capability and some taxonomy capabilities.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request                   $request The request.
     * @param array<int, array{string, string}> $checks  Taxonomy and capability key pairs, e.g. `licensio_tag`, `edit_terms`.
     *
     * @return true|WP_Error True when the current user holds every capability.
     */
    private function taxonomy_permissions_check( $request, array $checks ) {
        $allowed = $this->manage_permissions_check( $request );

        if ( is_wp_error( $allowed ) ) {
            return $allowed;
        }

        foreach ( $checks as $check ) {
            $taxonomy = get_taxonomy( $check[0] );
            $key      = $check[1];

            if ( ! is_object( $taxonomy ) || ! isset( $taxonomy->cap->$key ) || ! current_user_can( $taxonomy->cap->$key ) ) {
                return self::forbidden_error();
            }
        }

        return true;
    }

    /**
     * Check a per-term capability on each term that exists.
     *
     * A term that does not exist is skipped here, as the Categories routes
     * skip one they only write to: the handler answers it, as a 404 or as a
     * failed entry, and a 0 parent means the top level.
     *
     * @since 1.0.0
     *
     * @param string $meta_cap The per-term capability, `edit_term` or `delete_term`.
     * @param string $taxonomy The taxonomy the IDs belong to.
     * @param mixed  $ids      The term ID or IDs as sent.
     *
     * @return true|WP_Error True when the current user holds the capability on every term.
     */
    private static function term_permissions_check( $meta_cap, $taxonomy, $ids ) {
        foreach ( self::ids( $ids ) as $id ) {
            if ( get_term( $id, $taxonomy ) instanceof WP_Term && ! current_user_can( $meta_cap, $id ) ) {
                return self::forbidden_error();
            }
        }

        return true;
    }

    /**
     * The error for a user who may not change these terms.
     *
     * @since 1.0.0
     *
     * @return WP_Error The 403, or 401 when signed out.
     */
    private static function forbidden_error() {
        return new WP_Error(
            'licensio_forbidden',
            __( 'You are not allowed to change these terms.', 'licensio-knowledge-base' ),
            array( 'status' => rest_authorization_required_code() )
        );
    }

    /**
     * List tags.
     *
     * One query reads the page and one counts the matches. Both are written
     * out in full with every filter as a placeholder, so nothing is
     * interpolated: a filter that is off compares its placeholder with its
     * "off" value, and the sort is a set of CASE expressions of which only the
     * chosen one is not null.
     *
     * The counts come from a derived table over the tag relationships of
     * articles in any status but trash and auto-draft, not from core's
     * stored `count` column. It gives two numbers: the published articles,
     * which is the `count` answered and sorted by, as category counts and
     * core term counts are; and every article it covers, which the Unused
     * filter reads, so it never lists a tag a draft, scheduled, pending or
     * private article still uses.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response|WP_Error The page of tags.
     */
    public function get_items( $request ) {
        global $wpdb;

        $search   = trim( (string) $request->get_param( 'search' ) );
        $like     = '' === $search ? '' : '%' . $wpdb->esc_like( $search ) . '%';
        $unused   = rest_sanitize_boolean( $request->get_param( 'unused' ) ) ? 1 : 0;
        $include  = implode( ',', array_filter( array_map( 'absint', (array) $request->get_param( 'include' ) ) ) );
        $orderby  = 'count' === $request->get_param( 'orderby' ) ? 'count' : 'name';
        $order    = 'desc' === $request->get_param( 'order' ) ? 'desc' : 'asc';
        $per_page = min( self::MAX_ITEMS, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
        $page     = max( 1, absint( $request->get_param( 'page' ) ) );
        $taxonomy = Licensio_KB_Post_Types::TAXONOMY_TAG;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i AS t INNER JOIN %i AS tt ON tt.term_id = t.term_id
                LEFT JOIN (
                    SELECT r.term_taxonomy_id, COUNT(*) AS used, SUM( CASE WHEN p.post_status = %s THEN 1 ELSE 0 END ) AS n FROM %i AS r INNER JOIN %i AS p ON p.ID = r.object_id
                    INNER JOIN %i AS rt ON rt.term_taxonomy_id = r.term_taxonomy_id AND rt.taxonomy = %s
                    WHERE p.post_type = %s AND p.post_status NOT IN ( %s, %s )
                    GROUP BY r.term_taxonomy_id
                ) AS c ON c.term_taxonomy_id = tt.term_taxonomy_id
                WHERE tt.taxonomy = %s
                    AND ( %s = \'\' OR t.name LIKE %s OR t.slug LIKE %s )
                    AND ( %d = 0 OR COALESCE( c.used, 0 ) = 0 )
                    AND ( %s = \'\' OR FIND_IN_SET( t.term_id, %s ) > 0 )',
                $wpdb->terms,
                $wpdb->term_taxonomy,
                'publish',
                $wpdb->term_relationships,
                $wpdb->posts,
                $wpdb->term_taxonomy,
                $taxonomy,
                Licensio_KB_Post_Types::POST_TYPE,
                'trash',
                'auto-draft',
                $taxonomy,
                $like,
                $like,
                $like,
                $unused,
                $include,
                $include
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT t.term_id, t.name, t.slug, tt.description, COALESCE( c.n, 0 ) AS count FROM %i AS t INNER JOIN %i AS tt ON tt.term_id = t.term_id
                LEFT JOIN (
                    SELECT r.term_taxonomy_id, COUNT(*) AS used, SUM( CASE WHEN p.post_status = %s THEN 1 ELSE 0 END ) AS n FROM %i AS r INNER JOIN %i AS p ON p.ID = r.object_id
                    INNER JOIN %i AS rt ON rt.term_taxonomy_id = r.term_taxonomy_id AND rt.taxonomy = %s
                    WHERE p.post_type = %s AND p.post_status NOT IN ( %s, %s )
                    GROUP BY r.term_taxonomy_id
                ) AS c ON c.term_taxonomy_id = tt.term_taxonomy_id
                WHERE tt.taxonomy = %s
                    AND ( %s = \'\' OR t.name LIKE %s OR t.slug LIKE %s )
                    AND ( %d = 0 OR COALESCE( c.used, 0 ) = 0 )
                    AND ( %s = \'\' OR FIND_IN_SET( t.term_id, %s ) > 0 )
                ORDER BY
                    CASE WHEN %s = \'count\' AND %s = \'asc\' THEN COALESCE( c.n, 0 ) END ASC,
                    CASE WHEN %s = \'count\' AND %s = \'desc\' THEN COALESCE( c.n, 0 ) END DESC,
                    CASE WHEN %s = \'name\' AND %s = \'desc\' THEN t.name END DESC,
                    t.name ASC,
                    t.term_id ASC
                LIMIT %d OFFSET %d',
                $wpdb->terms,
                $wpdb->term_taxonomy,
                'publish',
                $wpdb->term_relationships,
                $wpdb->posts,
                $wpdb->term_taxonomy,
                $taxonomy,
                Licensio_KB_Post_Types::POST_TYPE,
                'trash',
                'auto-draft',
                $taxonomy,
                $like,
                $like,
                $like,
                $unused,
                $include,
                $include,
                $orderby,
                $order,
                $orderby,
                $order,
                $orderby,
                $order,
                $per_page,
                ( $page - 1 ) * $per_page
            )
        );

        $items = array();

        foreach ( (array) $rows as $row ) {
            $items[] = self::prepare_row( $row );
        }

        return rest_ensure_response(
            array(
                'items'    => $items,
                'total'    => $total,
                'pages'    => (int) ceil( $total / $per_page ),
                'page'     => $page,
                'per_page' => $per_page,
            )
        );
    }

    /**
     * Create a tag.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response|WP_Error The new tag, with status 201.
     */
    public function create_item( $request ) {
        $name = trim( (string) $request->get_param( 'name' ) );

        if ( '' === $name ) {
            return self::empty_name_error();
        }

        $args = array();

        foreach ( array( 'slug', 'description' ) as $field ) {
            if ( null !== $request->get_param( $field ) ) {
                $args[ $field ] = wp_slash( (string) $request->get_param( $field ) );
            }
        }

        $created = wp_insert_term( wp_slash( $name ), Licensio_KB_Post_Types::TAXONOMY_TAG, $args );

        if ( is_wp_error( $created ) ) {
            return self::rest_error( $created );
        }

        $term = get_term( (int) $created['term_id'], Licensio_KB_Post_Types::TAXONOMY_TAG );

        if ( ! $term instanceof WP_Term ) {
            return self::not_found_error();
        }

        return new WP_REST_Response( self::prepare_row( $term ), 201 );
    }

    /**
     * Edit a tag's name, slug or description.
     *
     * Only the fields sent are changed. A slug another tag already uses is
     * refused with a 409 before anything is written, as the Categories routes
     * do. A slug sent empty is regenerated from the name by
     * `wp_update_term()`, which makes it unique.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response|WP_Error The updated tag.
     */
    public function update_item( $request ) {
        $term = get_term( absint( $request->get_param( 'id' ) ), Licensio_KB_Post_Types::TAXONOMY_TAG );

        if ( ! $term instanceof WP_Term ) {
            return self::not_found_error();
        }

        $args = array();

        if ( null !== $request->get_param( 'name' ) ) {
            $name = trim( (string) $request->get_param( 'name' ) );

            if ( '' === $name ) {
                return self::empty_name_error();
            }

            $args['name'] = wp_slash( $name );
        }

        if ( null !== $request->get_param( 'slug' ) ) {
            $slug = sanitize_title( (string) $request->get_param( 'slug' ) );

            $free = $this->term_operations->check_slug( (int) $term->term_id, $slug, Licensio_KB_Post_Types::TAXONOMY_TAG );

            if ( is_wp_error( $free ) ) {
                return $free;
            }

            if ( $slug !== $term->slug ) {
                $args['slug'] = $slug;
            }
        }

        if ( null !== $request->get_param( 'description' ) ) {
            $args['description'] = wp_slash( (string) $request->get_param( 'description' ) );
        }

        if ( ! empty( $args ) ) {
            $updated = wp_update_term( (int) $term->term_id, Licensio_KB_Post_Types::TAXONOMY_TAG, $args );

            if ( is_wp_error( $updated ) ) {
                return self::rest_error( $updated );
            }

            $term = get_term( (int) $term->term_id, Licensio_KB_Post_Types::TAXONOMY_TAG );

            if ( ! $term instanceof WP_Term ) {
                return self::not_found_error();
            }
        }

        return rest_ensure_response( self::prepare_row( $term ) );
    }

    /**
     * Delete several tags.
     *
     * Each tag is deleted on its own, so one that fails does not stop the
     * rest. The answer says which were deleted and which failed.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response The result: `deleted` IDs and `failed` `{ id, message }` entries.
     */
    public function bulk_delete( $request ) {
        $deleted = array();
        $failed  = array();

        // Recount each changed term once at the end, not once per article.
        wp_defer_term_counting( true );

        foreach ( self::ids( $request->get_param( 'ids' ) ) as $id ) {
            if ( ! get_term( $id, Licensio_KB_Post_Types::TAXONOMY_TAG ) instanceof WP_Term ) {
                $failed[] = self::failure( $id, __( 'The tag does not exist.', 'licensio-knowledge-base' ) );
                continue;
            }

            $result = wp_delete_term( $id, Licensio_KB_Post_Types::TAXONOMY_TAG );

            if ( true === $result ) {
                $deleted[] = $id;
                continue;
            }

            $failed[] = self::failure(
                $id,
                is_wp_error( $result ) ? $result->get_error_message() : __( 'The tag could not be deleted.', 'licensio-knowledge-base' )
            );
        }

        wp_defer_term_counting( false );

        return rest_ensure_response(
            array(
                'deleted' => $deleted,
                'failed'  => $failed,
            )
        );
    }

    /**
     * Merge tags into one.
     *
     * The articles of every source tag gain the target tag, then the sources
     * are deleted. Nothing moves unless the user may edit every article of the
     * sources and nobody else has one open in the editor.
     *
     * @since 1.0.0 Checks each article first.
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response|WP_Error The result: `moved`, the `deleted` IDs and the updated `target`.
     */
    public function merge( $request ) {
        $target  = absint( $request->get_param( 'target' ) );
        $sources = self::ids( $request->get_param( 'sources' ) );
        $checked = $this->term_operations->check_articles( $sources, Licensio_KB_Post_Types::TAXONOMY_TAG );

        if ( is_wp_error( $checked ) ) {
            return $checked;
        }

        $result = $this->term_operations->merge_terms( $sources, $target, Licensio_KB_Post_Types::TAXONOMY_TAG );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $term = get_term( $target, Licensio_KB_Post_Types::TAXONOMY_TAG );

        return rest_ensure_response(
            array(
                'moved'   => (int) $result['moved'],
                'deleted' => array_map( 'intval', $result['deleted'] ),
                'target'  => $term instanceof WP_Term ? self::prepare_row( $term ) : null,
            )
        );
    }

    /**
     * Turn tags into categories.
     *
     * For each tag: the category with the tag's slug is reused if there is
     * one, wherever it sits in the tree, and is left where it is. Failing
     * that, the child of `parent` with the tag's name is reused. Both are
     * intended: a convert never makes a second category with the same slug,
     * or with the same name under the same parent, and joins the tag's
     * articles to the existing one instead. Otherwise a category is created
     * under `parent` with the tag's name, slug and description. Every article with the tag moves to the category, through
     * `Licensio_KB_Term_Operations::move_across_taxonomies()`, then the tag is
     * deleted. A tag that fails is kept as it was, and reported as failed.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response|WP_Error The result: `converted` `{ tag, category, reused, articles }` and `failed` entries.
     */
    public function convert( $request ) {
        $parent = absint( $request->get_param( 'parent' ) );

        if ( $parent > 0 && ! get_term( $parent, Licensio_KB_Post_Types::TAXONOMY_CATEGORY ) instanceof WP_Term ) {
            return new WP_Error(
                'licensio_kb_invalid_parent',
                __( 'The chosen parent category does not exist.', 'licensio-knowledge-base' ),
                array( 'status' => 400 )
            );
        }

        $converted = array();
        $failed    = array();

        // Recount each changed term once at the end, not once per article.
        wp_defer_term_counting( true );

        foreach ( self::ids( $request->get_param( 'ids' ) ) as $id ) {
            $result = $this->convert_tag( $id, $parent );

            if ( is_wp_error( $result ) ) {
                $failed[] = self::failure( $id, $result->get_error_message() );
                continue;
            }

            $converted[] = $result;
        }

        wp_defer_term_counting( false );

        return rest_ensure_response(
            array(
                'converted' => $converted,
                'failed'    => $failed,
            )
        );
    }

    /**
     * Turn one tag into a category.
     *
     * Reuses the category with the tag's slug wherever it is, or else the
     * child of the parent with the tag's name, so a convert never adds a
     * second category of the same name under the same parent. A new category
     * is created only when neither exists.
     *
     * If the articles cannot all be given the category, or the tag cannot be
     * deleted, the articles lose the category again and a category created
     * here is deleted, so the tag is left as it was. A tag with an article the
     * user may not edit, or that someone else has open in the editor, is kept
     * as it was and reported.
     *
     * @since 1.0.0 Checks each article first.
     *
     * @param int $id        The tag ID.
     * @param int $parent_id The parent for a new category, or 0 for the top level.
     *
     * @return array<string, mixed>|WP_Error The converted entry, or why the tag was kept.
     */
    private function convert_tag( $id, $parent_id ) {
        $tag = get_term( $id, Licensio_KB_Post_Types::TAXONOMY_TAG );

        if ( ! $tag instanceof WP_Term ) {
            return new WP_Error( 'licensio_kb_invalid_term', __( 'The tag does not exist.', 'licensio-knowledge-base' ) );
        }

        $checked = $this->term_operations->check_articles( array( $id ), Licensio_KB_Post_Types::TAXONOMY_TAG );

        if ( is_wp_error( $checked ) ) {
            return $checked;
        }

        $category = get_term_by( 'slug', $tag->slug, Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        if ( ! $category instanceof WP_Term ) {
            $category = self::child_named( $tag->name, $parent_id );
        }

        $reused = $category instanceof WP_Term;

        if ( ! $reused ) {
            $created = wp_insert_term(
                wp_slash( $tag->name ),
                Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                array(
                    'slug'        => $tag->slug,
                    'parent'      => $parent_id,
                    'description' => wp_slash( $tag->description ),
                )
            );

            if ( is_wp_error( $created ) ) {
                return $created;
            }

            $category = get_term( (int) $created['term_id'], Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

            if ( ! $category instanceof WP_Term ) {
                return new WP_Error( 'licensio_kb_invalid_term', __( 'The category could not be created.', 'licensio-knowledge-base' ) );
            }
        }

        $record   = array();
        $articles = $this->term_operations->move_across_taxonomies(
            (int) $tag->term_id,
            Licensio_KB_Post_Types::TAXONOMY_TAG,
            (int) $category->term_id,
            Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
            $record
        );

        $deleted = is_wp_error( $articles ) ? $articles : wp_delete_term( (int) $tag->term_id, Licensio_KB_Post_Types::TAXONOMY_TAG );

        if ( true !== $deleted ) {
            $this->term_operations->undo( $record );

            if ( ! $reused ) {
                wp_delete_term( (int) $category->term_id, Licensio_KB_Post_Types::TAXONOMY_CATEGORY );
            }

            return is_wp_error( $deleted ) ? $deleted : new WP_Error( 'licensio_kb_delete_failed', __( 'The tag could not be deleted.', 'licensio-knowledge-base' ) );
        }

        return array(
            'tag'      => (int) $tag->term_id,
            'category' => array(
                'id'     => (int) $category->term_id,
                'name'   => self::plain_text( $category->name ),
                'slug'   => $category->slug,
                'parent' => (int) $category->parent,
            ),
            'reused'   => $reused,
            'articles' => (int) $articles,
        );
    }

    /**
     * The category under a parent that has a name.
     *
     * The name is compared as core stores it, entity encoded (`Q&amp;A`), which
     * is how a tag's name is stored too.
     *
     * @since 1.0.0
     *
     * @param string $name      The stored name.
     * @param int    $parent_id The parent category, or 0 for the top level.
     *
     * @return WP_Term|null The category, or null when there is none.
     */
    private static function child_named( $name, $parent_id ) {
        $found = get_terms(
            array(
                'taxonomy'   => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                'parent'     => (int) $parent_id,
                'name'       => $name,
                'hide_empty' => false,
                'number'     => 1,
            )
        );

        if ( ! is_array( $found ) ) {
            return null;
        }

        $first = reset( $found );

        return $first instanceof WP_Term ? $first : null;
    }

    /**
     * A list of positive, distinct IDs.
     *
     * @since 1.0.0
     *
     * @param mixed $ids The IDs as sent.
     *
     * @return int[] The IDs.
     */
    private static function ids( $ids ) {
        return array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
    }

    /**
     * One failed entry of a bulk answer.
     *
     * @since 1.0.0
     *
     * @param int    $id      The tag ID.
     * @param string $message Why it failed.
     *
     * @return array{id: int, message: string} The entry.
     */
    private static function failure( $id, $message ) {
        return array(
            'id'      => (int) $id,
            'message' => (string) $message,
        );
    }

    /**
     * A tag, as the app reads it.
     *
     * The list query's `count` counts published articles, as the
     * published-only count a `WP_Term` carries does. The name
     * is decoded to plain text; the description is kept as stored, so it
     * round-trips unchanged.
     *
     * @since 1.0.0
     *
     * @param object $row A list query row or a `WP_Term`, with `term_id`, `name`, `slug`, `description` and `count`.
     *
     * @return array<string, mixed> The tag.
     */
    private static function prepare_row( $row ) {
        return array(
            'id'          => (int) $row->term_id,
            'name'        => self::plain_text( $row->name ),
            'slug'        => (string) $row->slug,
            'description' => (string) $row->description,
            'count'       => (int) $row->count,
        );
    }

    /**
     * A stored term name as plain text.
     *
     * Core saves term names with HTML special characters encoded, so
     * "Billing & invoices" is stored as "Billing &amp; invoices". The app
     * shows them as text, never as HTML. Only names go through this:
     * descriptions are answered exactly as stored, because decoding one
     * would turn text such as `&lt;b&gt;` into markup that the next save
     * strips, or keeps as real HTML.
     *
     * @since 1.0.0
     *
     * @param string $text The stored text.
     *
     * @return string The text with HTML entities decoded.
     */
    private static function plain_text( $text ) {
        return html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
    }

    /**
     * The error for a tag that does not exist.
     *
     * @since 1.0.0
     *
     * @return WP_Error A 404 error.
     */
    private static function not_found_error() {
        return new WP_Error(
            'licensio_kb_invalid_term',
            __( 'The tag does not exist.', 'licensio-knowledge-base' ),
            array( 'status' => 404 )
        );
    }

    /**
     * The error for an empty tag name.
     *
     * @since 1.0.0
     *
     * @return WP_Error A 400 error.
     */
    private static function empty_name_error() {
        return new WP_Error(
            'licensio_kb_empty_name',
            __( 'A tag needs a name.', 'licensio-knowledge-base' ),
            array( 'status' => 400 )
        );
    }
}
