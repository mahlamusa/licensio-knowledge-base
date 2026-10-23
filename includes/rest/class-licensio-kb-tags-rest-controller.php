<?php
/**
 * The tag routes behind the Tags app.
 *
 * Lists, creates, edits, bulk deletes, merges and converts `licensio_tag`
 * terms:
 *
 * - `GET /tags`: `search`, `unused`, `include[]`, `orderby` (`name`, `count`),
 *   `order`, `page`, `per_page`. Answers `{ items, total, pages, page, per_page }`.
 * - `POST /tags` and `PATCH /tags/{id}`: `name`, `slug`, `description`. A slug
 *   that is taken is made unique the way core does it.
 * - `POST /tags/bulk-delete`: `{ ids[] }`.
 * - `POST /tags/merge`: `{ sources[], target }`, through
 *   `Licensio_KB_Term_Operations::merge_terms()`.
 * - `POST /tags/convert`: `{ ids[], parent }`. Each tag becomes a category
 *   with the same slug under `parent`, or reuses the category that already
 *   has that slug, keeps its articles, and is then deleted.
 *
 * Every route needs `manage_licensio_kb` and the matching capability of the
 * taxonomy it changes. Term names and descriptions are answered as plain text:
 * core stores `&` as `&amp;`, and the app renders them as text.
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
                    'permission_callback' => array( $this, 'edit_permissions_check' ),
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
                'description' => __( 'Only tags no published article uses.', 'licensio-knowledge-base' ),
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
                'description' => __( 'Sort by name or by article count.', 'licensio-knowledge-base' ),
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
                'sanitize_callback' => 'sanitize_title',
                'description'       => __( 'The tag slug. A slug that is taken gets a number added.', 'licensio-knowledge-base' ),
            ),
            'description' => array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
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
     * Permission callback for deleting tags.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return true|WP_Error True when the current user may delete tags.
     */
    public function delete_permissions_check( $request ) {
        return $this->taxonomy_permissions_check( $request, array( array( Licensio_KB_Post_Types::TAXONOMY_TAG, 'delete_terms' ) ) );
    }

    /**
     * Permission callback for merging tags, which edits one and deletes others.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return true|WP_Error True when the current user may merge tags.
     */
    public function merge_permissions_check( $request ) {
        return $this->taxonomy_permissions_check(
            $request,
            array(
                array( Licensio_KB_Post_Types::TAXONOMY_TAG, 'edit_terms' ),
                array( Licensio_KB_Post_Types::TAXONOMY_TAG, 'delete_terms' ),
            )
        );
    }

    /**
     * Permission callback for converting tags to categories.
     *
     * Converting deletes tags, creates categories and assigns them to
     * articles, so it needs all three capabilities.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return true|WP_Error True when the current user may convert tags.
     */
    public function convert_permissions_check( $request ) {
        return $this->taxonomy_permissions_check(
            $request,
            array(
                array( Licensio_KB_Post_Types::TAXONOMY_TAG, 'delete_terms' ),
                array( Licensio_KB_Post_Types::TAXONOMY_CATEGORY, 'edit_terms' ),
                array( Licensio_KB_Post_Types::TAXONOMY_CATEGORY, 'assign_terms' ),
            )
        );
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
                return new WP_Error(
                    'licensio_forbidden',
                    __( 'You are not allowed to change these terms.', 'licensio-knowledge-base' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }
        }

        return true;
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
                WHERE tt.taxonomy = %s
                    AND ( %s = \'\' OR t.name LIKE %s OR t.slug LIKE %s )
                    AND ( %d = 0 OR tt.count = 0 )
                    AND ( %s = \'\' OR FIND_IN_SET( t.term_id, %s ) > 0 )',
                $wpdb->terms,
                $wpdb->term_taxonomy,
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
                'SELECT t.term_id, t.name, t.slug, tt.description, tt.count FROM %i AS t INNER JOIN %i AS tt ON tt.term_id = t.term_id
                WHERE tt.taxonomy = %s
                    AND ( %s = \'\' OR t.name LIKE %s OR t.slug LIKE %s )
                    AND ( %d = 0 OR tt.count = 0 )
                    AND ( %s = \'\' OR FIND_IN_SET( t.term_id, %s ) > 0 )
                ORDER BY
                    CASE WHEN %s = \'count\' AND %s = \'asc\' THEN tt.count END ASC,
                    CASE WHEN %s = \'count\' AND %s = \'desc\' THEN tt.count END DESC,
                    CASE WHEN %s = \'name\' AND %s = \'desc\' THEN t.name END DESC,
                    t.name ASC,
                    t.term_id ASC
                LIMIT %d OFFSET %d',
                $wpdb->terms,
                $wpdb->term_taxonomy,
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

        return new WP_REST_Response( self::prepare_term( $term ), 201 );
    }

    /**
     * Edit a tag's name, slug or description.
     *
     * Only the fields sent are changed. Core's `wp_update_term()` refuses a
     * slug another term already has, so a new slug is first made unique with
     * `wp_unique_term_slug()`, which adds a number the way core does for a new
     * term.
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

            if ( '' !== $slug && $slug !== $term->slug ) {
                $args['slug'] = wp_unique_term_slug( $slug, $term );
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

        return rest_ensure_response( self::prepare_term( $term ) );
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
     * are deleted.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response|WP_Error The result: `moved`, the `deleted` IDs and the updated `target`.
     */
    public function merge( $request ) {
        $target = absint( $request->get_param( 'target' ) );
        $result = $this->term_operations->merge_terms(
            self::ids( $request->get_param( 'sources' ) ),
            $target,
            Licensio_KB_Post_Types::TAXONOMY_TAG
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $term = get_term( $target, Licensio_KB_Post_Types::TAXONOMY_TAG );

        return rest_ensure_response(
            array(
                'moved'   => (int) $result['moved'],
                'deleted' => array_map( 'intval', $result['deleted'] ),
                'target'  => $term instanceof WP_Term ? self::prepare_term( $term ) : null,
            )
        );
    }

    /**
     * Turn tags into categories.
     *
     * For each tag: the category with the tag's slug is reused if there is
     * one, wherever it sits in the tree, and is left where it is. Otherwise a
     * category is created under `parent` with the tag's name, slug and
     * description. Every article with the tag gains the category, then the
     * tag is deleted. A tag whose category or assignments fail is kept, and
     * reported as failed.
     *
     * `Licensio_KB_Term_Operations` only moves assignments within one
     * taxonomy, so the category is assigned here with `wp_set_object_terms()`.
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

        foreach ( self::ids( $request->get_param( 'ids' ) ) as $id ) {
            $result = $this->convert_tag( $id, $parent );

            if ( is_wp_error( $result ) ) {
                $failed[] = self::failure( $id, $result->get_error_message() );
                continue;
            }

            $converted[] = $result;
        }

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
     * @since 1.0.0
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

        $category = get_term_by( 'slug', $tag->slug, Licensio_KB_Post_Types::TAXONOMY_CATEGORY );
        $reused   = $category instanceof WP_Term;

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

        $articles = get_objects_in_term( (int) $tag->term_id, Licensio_KB_Post_Types::TAXONOMY_TAG );

        if ( is_wp_error( $articles ) ) {
            return $articles;
        }

        foreach ( (array) $articles as $article_id ) {
            $assigned = wp_set_object_terms( (int) $article_id, array( (int) $category->term_id ), Licensio_KB_Post_Types::TAXONOMY_CATEGORY, true );

            if ( is_wp_error( $assigned ) ) {
                return $assigned;
            }
        }

        $deleted = wp_delete_term( (int) $tag->term_id, Licensio_KB_Post_Types::TAXONOMY_TAG );

        if ( true !== $deleted ) {
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
            'articles' => count( (array) $articles ),
        );
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
     * A tag row from the list query, as the app reads it.
     *
     * @since 1.0.0
     *
     * @param object $row A row with `term_id`, `name`, `slug`, `description` and `count`.
     *
     * @return array<string, mixed> The tag.
     */
    private static function prepare_row( $row ) {
        return array(
            'id'          => (int) $row->term_id,
            'name'        => self::plain_text( $row->name ),
            'slug'        => (string) $row->slug,
            'description' => self::plain_text( $row->description ),
            'count'       => (int) $row->count,
        );
    }

    /**
     * A tag, as the app reads it.
     *
     * @since 1.0.0
     *
     * @param WP_Term $term The tag.
     *
     * @return array<string, mixed> The tag.
     */
    private static function prepare_term( WP_Term $term ) {
        return self::prepare_row( $term );
    }

    /**
     * Stored term text as plain text.
     *
     * Core saves term names and descriptions with HTML special characters
     * encoded, so "Billing & invoices" is stored as "Billing &amp; invoices".
     * The app shows them as text, never as HTML, so it needs the decoded form.
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
     * A core term error with the HTTP status the REST layer should answer.
     *
     * Core's term functions return errors without a status, which the REST
     * layer would answer as a 500. A clash with an existing term is a 409;
     * anything else is a bad request.
     *
     * @since 1.0.0
     *
     * @param WP_Error $error The core error.
     *
     * @return WP_Error The error, with a status.
     */
    private static function rest_error( WP_Error $error ) {
        $code = (string) $error->get_error_code();

        return new WP_Error(
            $code,
            $error->get_error_message(),
            array( 'status' => in_array( $code, array( 'term_exists', 'duplicate_term_slug' ), true ) ? 409 : 400 )
        );
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
