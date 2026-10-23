<?php
/**
 * Category tree REST routes for the Categories app.
 *
 * - `GET /licensio-kb/v1/categories` answers every category as a flat list:
 *   `{ id, name, slug, parent, description, count, order, icon, icon_id,
 *   icon_svg, image_id, icon_url, image_url }`. Siblings come in their manual
 *   order (`licensio_order`), then by name. Names and descriptions are plain
 *   text: core stores `&` as `&amp;`, so they are decoded here.
 * - `POST /categories` creates a category and `PATCH /categories/{id}`
 *   updates one. Only the fields sent are written. The icon and image fields
 *   go through the same sanitisers as the term meta registration.
 * - `DELETE /categories/{id}` takes `reassign_articles` (`parent`, `none` or a
 *   category ID) and `reassign_children` (`parent` or a category ID), moves
 *   the articles and the child categories, then deletes the category.
 * - `POST /categories/order` takes `{ parent, ids }`: the complete, ordered
 *   children of `parent`. Each one is moved under `parent` and given its
 *   `licensio_order`. Answers the whole list, as the GET route does.
 * - `POST /categories/merge` takes `{ sources, target }`. Child categories of
 *   the sources are moved under the target first, then
 *   Licensio_KB_Term_Operations::merge_terms() moves the articles and deletes
 *   the sources.
 *
 * Every write that would put a category inside itself or inside one of its
 * own descendants is refused with `licensio_kb_category_cycle`.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves the category tree routes.
 *
 * @since 1.0.0
 */
class Licensio_KB_Categories_REST_Controller extends Licensio_KB_REST_Controller {

    /**
     * The route base.
     *
     * @since 1.0.0
     * @var string
     */
    protected $rest_base = 'categories';

    /**
     * The most categories one merge may delete.
     *
     * @since 1.0.0
     * @var int
     */
    const MAX_MERGE_SOURCES = 50;

    /**
     * The most categories one order request may place.
     *
     * @since 1.0.0
     * @var int
     */
    const MAX_ORDER_IDS = 1000;

    /**
     * The gap between two siblings' `licensio_order` values.
     *
     * @since 1.0.0
     * @var int
     */
    const ORDER_STEP = 10;

    /**
     * The request fields that are stored as term meta, and their meta keys.
     *
     * @since 1.0.0
     * @var array<string, string>
     */
    const META_FIELDS = array(
        'icon'     => Licensio_KB_Term_Meta::META_ICON,
        'icon_id'  => Licensio_KB_Term_Meta::META_ICON_ID,
        'icon_svg' => Licensio_KB_Term_Meta::META_ICON_SVG,
        'image_id' => Licensio_KB_Term_Meta::META_IMAGE_ID,
    );

    /**
     * The shared term operations.
     *
     * @since 1.0.0
     * @var Licensio_KB_Term_Operations
     */
    private $operations;

    /**
     * Build the controller.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_Term_Operations|null $operations The term operations. Created when omitted, so the
     *                                                     REST registrar can build the controller with no arguments.
     */
    public function __construct( $operations = null ) {
        $this->operations = $operations instanceof Licensio_KB_Term_Operations ? $operations : new Licensio_KB_Term_Operations();
    }

    /**
     * Register the category routes.
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
                    'permission_callback' => array( $this, 'get_items_permissions_check' ),
                    'args'                => array(),
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'create_item' ),
                    'permission_callback' => array( $this, 'create_item_permissions_check' ),
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
                    'permission_callback' => array( $this, 'update_item_permissions_check' ),
                    'args'                => array_merge( array( 'id' => $this->id_arg() ), $this->write_args( false ) ),
                ),
                array(
                    'methods'             => 'DELETE',
                    'callback'            => array( $this, 'delete_item' ),
                    'permission_callback' => array( $this, 'delete_item_permissions_check' ),
                    'args'                => $this->delete_args(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/order',
            array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'update_order' ),
                    'permission_callback' => array( $this, 'update_order_permissions_check' ),
                    'args'                => $this->order_args(),
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
                    'args'                => $this->merge_args(),
                ),
            )
        );
    }

    /**
     * The category ID path argument.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The argument schema.
     */
    private function id_arg() {
        return array(
            'required'          => true,
            'type'              => 'integer',
            'minimum'           => 1,
            'sanitize_callback' => 'absint',
            'description'       => __( 'The category ID.', 'licensio-knowledge-base' ),
        );
    }

    /**
     * The accepted arguments for creating or updating a category.
     *
     * @since 1.0.0
     *
     * @param bool $creating Whether the name is required.
     *
     * @return array<string, array<string, mixed>> The argument schema.
     */
    public function write_args( $creating ) {
        return array(
            'name'        => array(
                'required'          => (bool) $creating,
                'type'              => 'string',
                'minLength'         => 1,
                'maxLength'         => 200,
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'The category name.', 'licensio-knowledge-base' ),
            ),
            'slug'        => array(
                'type'              => 'string',
                'maxLength'         => 200,
                'sanitize_callback' => 'sanitize_title',
                'description'       => __( 'The category slug. Made unique when it is taken.', 'licensio-knowledge-base' ),
            ),
            'parent'      => array(
                'type'              => 'integer',
                'minimum'           => 0,
                'sanitize_callback' => 'absint',
                'description'       => __( 'The parent category ID, 0 for the top level.', 'licensio-knowledge-base' ),
            ),
            'description' => array(
                'type'              => 'string',
                'sanitize_callback' => 'wp_kses_post',
                'description'       => __( 'The category description.', 'licensio-knowledge-base' ),
            ),
            'icon'        => array(
                'type'              => 'string',
                'sanitize_callback' => array( 'Licensio_KB_Term_Meta', 'sanitize_icon' ),
                'description'       => __( 'A Dashicons name, e.g. dashicons-book-alt.', 'licensio-knowledge-base' ),
            ),
            'icon_id'     => array(
                'type'              => 'integer',
                'minimum'           => 0,
                'sanitize_callback' => array( 'Licensio_KB_Term_Meta', 'sanitize_attachment_id' ),
                'description'       => __( 'The attachment ID of an uploaded icon, 0 for none.', 'licensio-knowledge-base' ),
            ),
            'icon_svg'    => array(
                'type'              => 'string',
                'sanitize_callback' => array( 'Licensio_KB_SVG_Sanitizer', 'sanitize' ),
                'description'       => __( 'Inline SVG icon markup. Reduced to an allowlist on save.', 'licensio-knowledge-base' ),
            ),
            'image_id'    => array(
                'type'              => 'integer',
                'minimum'           => 0,
                'sanitize_callback' => array( 'Licensio_KB_Term_Meta', 'sanitize_attachment_id' ),
                'description'       => __( 'The attachment ID of the category image, 0 for none.', 'licensio-knowledge-base' ),
            ),
        );
    }

    /**
     * The accepted arguments for deleting a category.
     *
     * Both arrive as query arguments. The defaults are what core does on its
     * own: articles lose the category, child categories move up to its
     * parent.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> The argument schema.
     */
    public function delete_args() {
        return array(
            'id'                => $this->id_arg(),
            'reassign_articles' => array(
                'type'        => 'string',
                'default'     => 'none',
                'pattern'     => '^(parent|none|[1-9][0-9]*)$',
                'description' => __( 'Where the articles go: parent, none, or a category ID.', 'licensio-knowledge-base' ),
            ),
            'reassign_children' => array(
                'type'        => 'string',
                'default'     => 'parent',
                'pattern'     => '^(parent|[1-9][0-9]*)$',
                'description' => __( 'Where the child categories go: parent, or a category ID.', 'licensio-knowledge-base' ),
            ),
        );
    }

    /**
     * The accepted arguments for ordering categories.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> The argument schema.
     */
    public function order_args() {
        return array(
            'parent' => array(
                'required'          => true,
                'type'              => 'integer',
                'minimum'           => 0,
                'sanitize_callback' => 'absint',
                'description'       => __( 'The parent the categories are placed under, 0 for the top level.', 'licensio-knowledge-base' ),
            ),
            'ids'    => array(
                'required'    => true,
                'type'        => 'array',
                'minItems'    => 1,
                'maxItems'    => self::MAX_ORDER_IDS,
                'uniqueItems' => true,
                'items'       => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                ),
                'description' => __( 'Every child of the parent, in the new order, first first.', 'licensio-knowledge-base' ),
            ),
        );
    }

    /**
     * The accepted arguments for merging categories.
     *
     * The sources are capped because every one of them is deleted.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> The argument schema.
     */
    public function merge_args() {
        return array(
            'sources' => array(
                'required'    => true,
                'type'        => 'array',
                'minItems'    => 1,
                'maxItems'    => self::MAX_MERGE_SOURCES,
                'uniqueItems' => true,
                'items'       => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                ),
                'description' => __( 'The categories to merge away.', 'licensio-knowledge-base' ),
            ),
            'target'  => array(
                'required'          => true,
                'type'              => 'integer',
                'minimum'           => 1,
                'sanitize_callback' => 'absint',
                'description'       => __( 'The category that remains.', 'licensio-knowledge-base' ),
            ),
        );
    }

    /**
     * Whether the current user may read the category tree.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return true|WP_Error True when allowed.
     */
    public function get_items_permissions_check( $request ) {
        return $this->taxonomy_permission( $request, 'manage_terms' );
    }

    /**
     * Whether the current user may create a category.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return true|WP_Error True when allowed.
     */
    public function create_item_permissions_check( $request ) {
        return $this->taxonomy_permission( $request, 'edit_terms' );
    }

    /**
     * Whether the current user may update this category.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return true|WP_Error True when allowed.
     */
    public function update_item_permissions_check( $request ) {
        return $this->term_permission( $request, 'edit_terms', 'edit_term', array( absint( $request->get_param( 'id' ) ) ) );
    }

    /**
     * Whether the current user may delete this category.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return true|WP_Error True when allowed.
     */
    public function delete_item_permissions_check( $request ) {
        return $this->term_permission( $request, 'delete_terms', 'delete_term', array( absint( $request->get_param( 'id' ) ) ) );
    }

    /**
     * Whether the current user may move every category in an order request.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return true|WP_Error True when allowed.
     */
    public function update_order_permissions_check( $request ) {
        return $this->term_permission( $request, 'edit_terms', 'edit_term', self::id_list( $request->get_param( 'ids' ) ) );
    }

    /**
     * Whether the current user may delete the sources and edit the target.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return true|WP_Error True when allowed.
     */
    public function merge_permissions_check( $request ) {
        $allowed = $this->term_permission( $request, 'delete_terms', 'delete_term', self::id_list( $request->get_param( 'sources' ) ) );

        if ( true !== $allowed ) {
            return $allowed;
        }

        return $this->term_permission( $request, 'edit_terms', 'edit_term', array( absint( $request->get_param( 'target' ) ) ) );
    }

    /**
     * Check the management capability and one of the taxonomy's capabilities.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     * @param string          $cap     The taxonomy capability key: `manage_terms`, `edit_terms` or `delete_terms`.
     *
     * @return true|WP_Error True when allowed.
     */
    private function taxonomy_permission( $request, $cap ) {
        $manage = $this->manage_permissions_check( $request );

        if ( true !== $manage ) {
            return $manage;
        }

        $taxonomy = get_taxonomy( Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        if ( ! $taxonomy || ! isset( $taxonomy->cap->$cap ) || ! current_user_can( $taxonomy->cap->$cap ) ) {
            return self::forbidden();
        }

        return true;
    }

    /**
     * Check the taxonomy capability, then the per-term capability for each ID.
     *
     * A category that does not exist answers 404 before the per-term check,
     * which would otherwise refuse it as forbidden.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request  The request.
     * @param string          $cap      The taxonomy capability key.
     * @param string          $meta_cap The per-term capability, `edit_term` or `delete_term`.
     * @param int[]           $ids      The categories the request touches.
     *
     * @return true|WP_Error True when allowed.
     */
    private function term_permission( $request, $cap, $meta_cap, array $ids ) {
        $allowed = $this->taxonomy_permission( $request, $cap );

        if ( true !== $allowed ) {
            return $allowed;
        }

        foreach ( $ids as $id ) {
            if ( ! $this->get_category( $id ) instanceof WP_Term ) {
                return self::not_found();
            }

            if ( ! current_user_can( $meta_cap, $id ) ) {
                return self::forbidden();
            }
        }

        return true;
    }

    /**
     * Answer every category.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response|WP_Error The categories, or the query error.
     */
    public function get_items( $request ) {
        unset( $request );

        $items = $this->all_items();

        if ( is_wp_error( $items ) ) {
            return $items;
        }

        return rest_ensure_response( $items );
    }

    /**
     * Create a category.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response|WP_Error The new category with status 201, or an error.
     */
    public function create_item( $request ) {
        $params = $request->get_params();
        $args   = self::term_args( $params );

        if ( isset( $args['parent'] ) && $args['parent'] > 0 && ! $this->get_category( $args['parent'] ) instanceof WP_Term ) {
            return self::invalid_category();
        }

        $result = wp_insert_term( (string) $request->get_param( 'name' ), Licensio_KB_Post_Types::TAXONOMY_CATEGORY, $args );

        if ( is_wp_error( $result ) ) {
            return self::bad_request( $result );
        }

        $term_id = (int) $result['term_id'];

        $this->save_meta( $term_id, $params );

        return new WP_REST_Response( $this->prepare_category( $this->get_category( $term_id ) ), 201 );
    }

    /**
     * Update a category.
     *
     * Only the fields present in the request are changed.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response|WP_Error The updated category, or an error.
     */
    public function update_item( $request ) {
        $id     = absint( $request->get_param( 'id' ) );
        $params = $request->get_params();
        $args   = self::term_args( $params );

        if ( ! $this->get_category( $id ) instanceof WP_Term ) {
            return self::not_found();
        }

        if ( isset( $args['parent'] ) ) {
            $parents = $this->parent_map();

            if ( is_wp_error( $parents ) ) {
                return $parents;
            }

            if ( $args['parent'] > 0 && ! isset( $parents[ $args['parent'] ] ) ) {
                return self::invalid_category();
            }

            if ( self::would_cycle( $id, $args['parent'], $parents ) ) {
                return self::cycle();
            }
        }

        if ( ! empty( $args ) ) {
            $result = wp_update_term( $id, Licensio_KB_Post_Types::TAXONOMY_CATEGORY, $args );

            if ( is_wp_error( $result ) ) {
                return self::bad_request( $result );
            }
        }

        $this->save_meta( $id, $params );

        return rest_ensure_response( $this->prepare_category( $this->get_category( $id ) ) );
    }

    /**
     * Delete a category after moving its articles and child categories.
     *
     * Every target is checked before anything is written. `reassign_articles`
     * of `parent` is refused for a top-level category, which has no parent to
     * move them to; `none` leaves the articles without this category.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response|WP_Error `{ deleted, moved, reparented }`, or an error.
     */
    public function delete_item( $request ) {
        $id   = absint( $request->get_param( 'id' ) );
        $term = $this->get_category( $id );

        if ( ! $term instanceof WP_Term ) {
            return self::not_found();
        }

        $parents = $this->parent_map();

        if ( is_wp_error( $parents ) ) {
            return $parents;
        }

        $parent = (int) $term->parent;
        $plan   = self::plan_delete(
            $id,
            $parent,
            (string) $request->get_param( 'reassign_articles' ),
            (string) $request->get_param( 'reassign_children' ),
            $parents
        );

        if ( is_wp_error( $plan ) ) {
            return $plan;
        }

        $moved = 0;

        if ( $plan['articles'] > 0 ) {
            $moved = $this->operations->move_assignments( $id, $plan['articles'], Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

            if ( is_wp_error( $moved ) ) {
                return $moved;
            }
        }

        $reparented = $this->reparent( self::children_of( $id, $parents ), $plan['children'] );

        if ( is_wp_error( $reparented ) ) {
            return $reparented;
        }

        $deleted = wp_delete_term( $id, Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        if ( is_wp_error( $deleted ) ) {
            return $deleted;
        }

        if ( true !== $deleted ) {
            return new WP_Error(
                'licensio_kb_category_not_deleted',
                __( 'The category could not be deleted.', 'licensio-knowledge-base' ),
                array( 'status' => 500 )
            );
        }

        return rest_ensure_response(
            array(
                'deleted'    => $id,
                'moved'      => (int) $moved,
                'reparented' => $reparented,
            )
        );
    }

    /**
     * Work out where a deleted category's articles and children go.
     *
     * @since 1.0.0
     *
     * @param int             $id                The category being deleted.
     * @param int             $parent_id         Its parent, 0 at the top level.
     * @param string          $reassign_articles `parent`, `none` or a category ID.
     * @param string          $reassign_children `parent` or a category ID.
     * @param array<int, int> $parents           Every category ID to its parent ID.
     *
     * @return array{articles: int, children: int}|WP_Error The article target (0 for none) and the children's new parent, or an error.
     */
    public static function plan_delete( $id, $parent_id, $reassign_articles, $reassign_children, array $parents ) {
        if ( 'none' === $reassign_articles ) {
            $articles = 0;
        } elseif ( 'parent' === $reassign_articles ) {
            if ( 0 === $parent_id ) {
                return new WP_Error(
                    'licensio_kb_no_parent',
                    __( 'A top-level category has no parent to move its articles to. Choose a category, or leave them without one.', 'licensio-knowledge-base' ),
                    array( 'status' => 400 )
                );
            }

            $articles = $parent_id;
        } else {
            $articles = absint( $reassign_articles );

            if ( $articles === $id ) {
                return self::same_category();
            }

            if ( ! isset( $parents[ $articles ] ) ) {
                return self::invalid_category();
            }
        }

        if ( 'parent' === $reassign_children ) {
            $children = $parent_id;
        } else {
            $children = absint( $reassign_children );

            if ( ! isset( $parents[ $children ] ) ) {
                return self::invalid_category();
            }

            if ( $children === $id || in_array( $children, self::descendants_of( $id, $parents ), true ) ) {
                return self::cycle();
            }
        }

        return array(
            'articles' => $articles,
            'children' => $children,
        );
    }

    /**
     * Place categories under one parent, in order.
     *
     * Every category named is moved under `parent` if it is not there already
     * and given `licensio_order` 10, 20, 30… in the order sent. Categories of
     * the parent left out of the list keep their order.
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response|WP_Error The whole category list, or an error.
     */
    public function update_order( $request ) {
        $parent  = absint( $request->get_param( 'parent' ) );
        $ids     = self::id_list( $request->get_param( 'ids' ) );
        $parents = $this->parent_map();

        if ( is_wp_error( $parents ) ) {
            return $parents;
        }

        $checked = self::check_order( $parent, $ids, $parents );

        if ( is_wp_error( $checked ) ) {
            return $checked;
        }

        foreach ( $ids as $index => $id ) {
            if ( $parents[ $id ] !== $parent ) {
                $result = wp_update_term( $id, Licensio_KB_Post_Types::TAXONOMY_CATEGORY, array( 'parent' => $parent ) );

                if ( is_wp_error( $result ) ) {
                    return self::bad_request( $result );
                }
            }

            update_term_meta( $id, Licensio_KB_Term_Meta::META_ORDER, ( $index + 1 ) * self::ORDER_STEP );
        }

        return $this->get_items( $request );
    }

    /**
     * Check an order request before anything is written.
     *
     * @since 1.0.0
     *
     * @param int             $parent_id The parent the categories go under.
     * @param int[]           $ids       The categories, in order.
     * @param array<int, int> $parents   Every category ID to its parent ID.
     *
     * @return true|WP_Error True when the request is usable, an error naming the first problem otherwise.
     */
    public static function check_order( $parent_id, array $ids, array $parents ) {
        if ( empty( $ids ) ) {
            return self::invalid_category();
        }

        if ( $parent_id > 0 && ! isset( $parents[ $parent_id ] ) ) {
            return self::invalid_category();
        }

        foreach ( $ids as $id ) {
            if ( ! isset( $parents[ $id ] ) ) {
                return self::invalid_category();
            }

            if ( self::would_cycle( $id, $parent_id, $parents ) ) {
                return self::cycle();
            }
        }

        return true;
    }

    /**
     * Merge categories into one.
     *
     * Child categories of the sources that are not sources themselves move
     * under the target first, because core would otherwise move them to each
     * source's own parent as it deletes it. Then the articles move and the
     * sources are deleted through Licensio_KB_Term_Operations::merge_terms().
     *
     * @since 1.0.0
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response|WP_Error `{ moved, deleted, reparented }`, or an error.
     */
    public function merge( $request ) {
        $target  = absint( $request->get_param( 'target' ) );
        $sources = self::id_list( $request->get_param( 'sources' ) );
        $parents = $this->parent_map();

        if ( is_wp_error( $parents ) ) {
            return $parents;
        }

        $checked = self::check_merge( $sources, $target, $parents );

        if ( is_wp_error( $checked ) ) {
            return $checked;
        }

        $children = array();

        foreach ( $parents as $term_id => $parent_id ) {
            if ( in_array( $parent_id, $sources, true ) && ! in_array( $term_id, $sources, true ) ) {
                $children[] = $term_id;
            }
        }

        $reparented = $this->reparent( $children, $target );

        if ( is_wp_error( $reparented ) ) {
            return $reparented;
        }

        $result = $this->operations->merge_terms( $sources, $target, Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response(
            array(
                'moved'      => (int) $result['moved'],
                'deleted'    => array_map( 'intval', $result['deleted'] ),
                'reparented' => $reparented,
            )
        );
    }

    /**
     * Check a merge request before anything is written.
     *
     * @since 1.0.0
     *
     * @param int[]           $sources The categories to merge away.
     * @param int             $target  The category that remains.
     * @param array<int, int> $parents Every category ID to its parent ID.
     *
     * @return true|WP_Error True when the request is usable, an error naming the first problem otherwise.
     */
    public static function check_merge( array $sources, $target, array $parents ) {
        if ( empty( $sources ) ) {
            return self::invalid_category();
        }

        if ( in_array( $target, $sources, true ) ) {
            return self::same_category();
        }

        foreach ( array_merge( $sources, array( $target ) ) as $id ) {
            if ( ! isset( $parents[ $id ] ) ) {
                return self::invalid_category();
            }
        }

        foreach ( $sources as $source ) {
            if ( in_array( $target, self::descendants_of( $source, $parents ), true ) ) {
                return self::cycle();
            }
        }

        return true;
    }

    /**
     * Move categories under a new parent.
     *
     * @since 1.0.0
     *
     * @param int[] $ids       The categories to move.
     * @param int   $parent_id The new parent, 0 for the top level.
     *
     * @return int[]|WP_Error The categories moved, or the first error.
     */
    private function reparent( array $ids, $parent_id ) {
        $moved = array();

        foreach ( $ids as $id ) {
            $result = wp_update_term( $id, Licensio_KB_Post_Types::TAXONOMY_CATEGORY, array( 'parent' => $parent_id ) );

            if ( is_wp_error( $result ) ) {
                return self::bad_request( $result );
            }

            $moved[] = (int) $id;
        }

        return $moved;
    }

    /**
     * Every category, prepared and sorted.
     *
     * One term query; term meta is primed by it, so reading each category's
     * meta costs no further queries.
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>>|WP_Error The categories, siblings in order then by name.
     */
    private function all_items() {
        $terms = get_terms(
            array(
                'taxonomy'               => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                'hide_empty'             => false,
                'update_term_meta_cache' => true,
            )
        );

        if ( is_wp_error( $terms ) ) {
            return $terms;
        }

        $items = array();

        foreach ( (array) $terms as $term ) {
            if ( $term instanceof WP_Term ) {
                $items[] = $this->prepare_category( $term );
            }
        }

        return self::sort_items( $items );
    }

    /**
     * Sort categories by manual order, then name, then ID.
     *
     * One flat sort keeps every set of siblings in order, whatever their
     * parent, so the app builds the tree by walking the list once.
     *
     * @since 1.0.0
     *
     * @param array<int, array<string, mixed>> $items The prepared categories.
     *
     * @return array<int, array<string, mixed>> The sorted categories.
     */
    public static function sort_items( array $items ) {
        usort(
            $items,
            function ( $a, $b ) {
                if ( $a['order'] !== $b['order'] ) {
                    return $a['order'] < $b['order'] ? -1 : 1;
                }

                $by_name = strnatcasecmp( (string) $a['name'], (string) $b['name'] );

                if ( 0 !== $by_name ) {
                    return $by_name;
                }

                return (int) $a['id'] - (int) $b['id'];
            }
        );

        return $items;
    }

    /**
     * The REST shape of one category.
     *
     * @since 1.0.0
     *
     * @param WP_Term|null $term The category.
     *
     * @return array<string, mixed> The category's fields.
     */
    public function prepare_category( $term ) {
        if ( ! $term instanceof WP_Term ) {
            return array();
        }

        $id       = (int) $term->term_id;
        $icon_id  = Licensio_KB_Term_Meta::get_icon_id( $id );
        $image_id = Licensio_KB_Term_Meta::get_image_id( $id );

        return array(
            'id'          => $id,
            'name'        => self::plain_text( $term->name ),
            'slug'        => (string) $term->slug,
            'parent'      => (int) $term->parent,
            'description' => self::plain_text( $term->description ),
            'count'       => (int) $term->count,
            'order'       => Licensio_KB_Term_Meta::get_order( $id ),
            'icon'        => Licensio_KB_Term_Meta::get_icon( $id ),
            'icon_id'     => $icon_id,
            'icon_svg'    => Licensio_KB_Term_Meta::get_icon_svg( $id ),
            'image_id'    => $image_id,
            'icon_url'    => self::attachment_url( $icon_id, 'thumbnail' ),
            'image_url'   => self::attachment_url( $image_id, 'medium' ),
        );
    }

    /**
     * Write the icon and image fields present in a request.
     *
     * The values were already sanitised by the argument schema, and
     * `update_term_meta()` runs the registered sanitisers again.
     *
     * @since 1.0.0
     *
     * @param int                  $term_id The category.
     * @param array<string, mixed> $params  The request parameters.
     *
     * @return void
     */
    private function save_meta( $term_id, array $params ) {
        foreach ( self::META_FIELDS as $field => $meta_key ) {
            if ( array_key_exists( $field, $params ) && null !== $params[ $field ] ) {
                update_term_meta( (int) $term_id, $meta_key, $params[ $field ] );
            }
        }
    }

    /**
     * The core term arguments present in a request.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $params The request parameters.
     *
     * @return array<string, mixed> Any of `name`, `slug`, `description` and `parent`.
     */
    private static function term_args( array $params ) {
        $args = array();

        foreach ( array( 'name', 'slug', 'description' ) as $field ) {
            if ( isset( $params[ $field ] ) ) {
                $args[ $field ] = (string) $params[ $field ];
            }
        }

        if ( isset( $params['parent'] ) ) {
            $args['parent'] = absint( $params['parent'] );
        }

        return $args;
    }

    /**
     * Every category ID mapped to its parent ID.
     *
     * @since 1.0.0
     *
     * @return array<int, int>|WP_Error The map, or the query error.
     */
    private function parent_map() {
        $terms = get_terms(
            array(
                'taxonomy'               => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                'hide_empty'             => false,
                'fields'                 => 'id=>parent',
                'update_term_meta_cache' => false,
            )
        );

        if ( is_wp_error( $terms ) ) {
            return $terms;
        }

        $parents = array();

        foreach ( (array) $terms as $id => $parent_id ) {
            $parents[ (int) $id ] = (int) $parent_id;
        }

        return $parents;
    }

    /**
     * Every descendant of a category.
     *
     * @since 1.0.0
     *
     * @param int             $id      The category.
     * @param array<int, int> $parents Every category ID to its parent ID.
     *
     * @return int[] The descendant IDs, nearest first.
     */
    public static function descendants_of( $id, array $parents ) {
        $found = array();
        $queue = array( (int) $id );

        while ( ! empty( $queue ) ) {
            $current = array_shift( $queue );

            foreach ( $parents as $child => $parent_id ) {
                if ( $parent_id === $current && ! in_array( $child, $found, true ) && $child !== (int) $id ) {
                    $found[] = $child;
                    $queue[] = $child;
                }
            }
        }

        return $found;
    }

    /**
     * The direct children of a category.
     *
     * @since 1.0.0
     *
     * @param int             $id      The category.
     * @param array<int, int> $parents Every category ID to its parent ID.
     *
     * @return int[] The child IDs.
     */
    private static function children_of( $id, array $parents ) {
        return array_keys( $parents, (int) $id, true );
    }

    /**
     * Whether putting a category under a parent would make a loop.
     *
     * @since 1.0.0
     *
     * @param int             $id        The category being moved.
     * @param int             $parent_id The parent it would go under, 0 for the top level.
     * @param array<int, int> $parents   Every category ID to its parent ID.
     *
     * @return bool True when the parent is the category itself or one of its descendants.
     */
    public static function would_cycle( $id, $parent_id, array $parents ) {
        if ( 0 === (int) $parent_id ) {
            return false;
        }

        return (int) $parent_id === (int) $id || in_array( (int) $parent_id, self::descendants_of( $id, $parents ), true );
    }

    /**
     * A category by ID, or null when there is no such category.
     *
     * @since 1.0.0
     *
     * @param int $id The category ID.
     *
     * @return WP_Term|null The category.
     */
    private function get_category( $id ) {
        if ( (int) $id < 1 ) {
            return null;
        }

        $term = get_term( (int) $id, Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        return $term instanceof WP_Term ? $term : null;
    }

    /**
     * A list of IDs from a request parameter.
     *
     * @since 1.0.0
     *
     * @param mixed $value The parameter.
     *
     * @return int[] The positive IDs, unique, in the order given.
     */
    private static function id_list( $value ) {
        return array_values( array_unique( array_filter( array_map( 'absint', (array) $value ) ) ) );
    }

    /**
     * The URL of an attachment, or an empty string.
     *
     * Falls back to the file URL for an attachment with no image sizes, such
     * as an uploaded SVG icon.
     *
     * @since 1.0.0
     *
     * @param int    $attachment_id The attachment, or 0.
     * @param string $size          The image size wanted.
     *
     * @return string The URL, or an empty string.
     */
    private static function attachment_url( $attachment_id, $size ) {
        if ( $attachment_id < 1 ) {
            return '';
        }

        $url = wp_get_attachment_image_url( $attachment_id, $size );

        if ( ! $url ) {
            $url = wp_get_attachment_url( $attachment_id );
        }

        return $url ? (string) $url : '';
    }

    /**
     * Stored term text as plain text.
     *
     * Core saves term names and descriptions with HTML special characters
     * encoded, so "Billing & invoices" is stored as "Billing &amp; invoices".
     * The app shows them as text, never as HTML.
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
     * A core error turned into a 400 the app can show.
     *
     * Core's term errors carry a term ID or nothing as their data, which the
     * REST server would answer as a 500.
     *
     * @since 1.0.0
     *
     * @param WP_Error $error The core error.
     *
     * @return WP_Error The same code and message with status 400.
     */
    private static function bad_request( WP_Error $error ) {
        return new WP_Error( $error->get_error_code(), $error->get_error_message(), array( 'status' => 400 ) );
    }

    /**
     * The error for a category that does not exist.
     *
     * @since 1.0.0
     *
     * @return WP_Error A 404 error.
     */
    private static function not_found() {
        return new WP_Error(
            'licensio_kb_category_not_found',
            __( 'That category does not exist.', 'licensio-knowledge-base' ),
            array( 'status' => 404 )
        );
    }

    /**
     * The error for a chosen category that does not exist.
     *
     * @since 1.0.0
     *
     * @return WP_Error A 400 error.
     */
    private static function invalid_category() {
        return new WP_Error(
            'licensio_kb_invalid_category',
            __( 'One of the chosen categories does not exist.', 'licensio-knowledge-base' ),
            array( 'status' => 400 )
        );
    }

    /**
     * The error for a category chosen as its own target.
     *
     * @since 1.0.0
     *
     * @return WP_Error A 400 error.
     */
    private static function same_category() {
        return new WP_Error(
            'licensio_kb_same_category',
            __( 'A category cannot be moved onto itself.', 'licensio-knowledge-base' ),
            array( 'status' => 400 )
        );
    }

    /**
     * The error for a move that would put a category inside itself.
     *
     * @since 1.0.0
     *
     * @return WP_Error A 400 error.
     */
    private static function cycle() {
        return new WP_Error(
            'licensio_kb_category_cycle',
            __( 'A category cannot be placed inside itself or one of its own subcategories.', 'licensio-knowledge-base' ),
            array( 'status' => 400 )
        );
    }

    /**
     * The error for a user without the capability a route needs.
     *
     * @since 1.0.0
     *
     * @return WP_Error A 401 or 403 error.
     */
    private static function forbidden() {
        return new WP_Error(
            'licensio_kb_category_forbidden',
            __( 'You are not allowed to change knowledge base categories.', 'licensio-knowledge-base' ),
            array( 'status' => rest_authorization_required_code() )
        );
    }
}
