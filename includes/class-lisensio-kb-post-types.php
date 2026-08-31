<?php
/**
 * Content model registration.
 *
 * Bare registration of the `lisensio_article` post type and the
 * `lisensio_category` and `lisensio_tag` taxonomies. This is the data
 * contract every other sub-issue builds against.
 *
 * Scope note: registration ONLY. Term meta UI, admin columns and capability
 * mapping belong to #3 and must not be added here.
 *
 * Consumed by: #3, #4, #5, #6, #7, #8, #9, #12.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the knowledge base post type and taxonomies.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Post_Types {

    /**
     * The article post type name.
     *
     * @since 1.0.0
     * @var string
     */
    const POST_TYPE = 'lisensio_article';

    /**
     * The hierarchical category taxonomy name.
     *
     * @since 1.0.0
     * @var string
     */
    const TAXONOMY_CATEGORY = 'lisensio_category';

    /**
     * The flat tag taxonomy name.
     *
     * @since 1.0.0
     * @var string
     */
    const TAXONOMY_TAG = 'lisensio_tag';

    /**
     * Settings store used to resolve the rewrite slugs.
     *
     * @since 1.0.0
     * @var Lisensio_KB_Settings
     */
    private $settings;

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Settings $settings The settings store.
     */
    public function __construct( Lisensio_KB_Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * Register the WordPress hooks.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        /*
         * Taxonomies register first. register_post_type()'s `taxonomies`
         * argument calls register_taxonomy_for_object_type(), which returns
         * false for a taxonomy that is not in $wp_taxonomies yet — so with the
         * post type first that argument was a silent no-op that only looked
         * load-bearing.
         */
        add_action( 'init', array( $this, 'register_taxonomies' ), 5 );
        add_action( 'init', array( $this, 'register_post_type' ), 6 );
        add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 99 );
    }

    /**
     * Rebuild the rewrite rules when a slug setting has changed.
     *
     * Runs late on `init`, after this class has registered the permastructs, so
     * the rules are rebuilt from the new slugs rather than the old ones. Without
     * this, saving a new article slug through the settings screen (#10) would
     * 404 every article, category and tag URL until someone re-saved permalinks
     * by hand — and a consumer cannot supply it without editing scaffold files.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function maybe_flush_rewrite_rules() {
        if ( ! Lisensio_KB_Settings::needs_rewrite_flush() ) {
            return;
        }

        Lisensio_KB_Settings::clear_rewrite_flush();

        flush_rewrite_rules( false );
    }

    /**
     * Register the `lisensio_article` post type.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_post_type() {
        $labels = array(
            'name'                  => _x( 'Articles', 'post type general name', 'lisensio-knowledge-base' ),
            'singular_name'         => _x( 'Article', 'post type singular name', 'lisensio-knowledge-base' ),
            'menu_name'             => _x( 'Knowledge Base', 'admin menu', 'lisensio-knowledge-base' ),
            'name_admin_bar'        => _x( 'Article', 'add new on admin bar', 'lisensio-knowledge-base' ),
            'add_new'               => __( 'Add New', 'lisensio-knowledge-base' ),
            'add_new_item'          => __( 'Add New Article', 'lisensio-knowledge-base' ),
            'new_item'              => __( 'New Article', 'lisensio-knowledge-base' ),
            'edit_item'             => __( 'Edit Article', 'lisensio-knowledge-base' ),
            'view_item'             => __( 'View Article', 'lisensio-knowledge-base' ),
            'view_items'            => __( 'View Articles', 'lisensio-knowledge-base' ),
            'all_items'             => __( 'All Articles', 'lisensio-knowledge-base' ),
            'search_items'          => __( 'Search Articles', 'lisensio-knowledge-base' ),
            'not_found'             => __( 'No articles found.', 'lisensio-knowledge-base' ),
            'not_found_in_trash'    => __( 'No articles found in Trash.', 'lisensio-knowledge-base' ),
            'archives'              => __( 'Article Archives', 'lisensio-knowledge-base' ),
            'featured_image'        => __( 'Article Image', 'lisensio-knowledge-base' ),
            'set_featured_image'    => __( 'Set article image', 'lisensio-knowledge-base' ),
            'remove_featured_image' => __( 'Remove article image', 'lisensio-knowledge-base' ),
            'use_featured_image'    => __( 'Use as article image', 'lisensio-knowledge-base' ),
            'item_published'        => __( 'Article published.', 'lisensio-knowledge-base' ),
            'item_updated'          => __( 'Article updated.', 'lisensio-knowledge-base' ),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_nav_menus'  => true,
            'show_in_rest'       => true,
            'rest_base'          => 'lisensio_articles',
            'has_archive'        => $this->article_slug(),
            'hierarchical'       => false,
            'menu_position'      => 26,
            'menu_icon'          => 'dashicons-book-alt',
            'supports'           => array(
                'title',
                'editor',
                'excerpt',
                'author',
                'thumbnail',
                'revisions',

                /*
                 * Comments are supported so readers can ask a follow-up on an
                 * article, and so core contributes its own comment column to
                 * the list table rather than this plugin reimplementing one.
                 */
                'comments',
                'custom-fields',
                'page-attributes',
            ),
            'taxonomies'         => array( self::TAXONOMY_CATEGORY, self::TAXONOMY_TAG ),
            'rewrite'            => array(
                'slug'       => $this->article_slug(),
                'with_front' => false,
                'feeds'      => true,
                'pages'      => true,
            ),
            'capability_type'    => 'post',
            'map_meta_cap'       => true,
        );

        /**
         * Filter the arguments used to register the article post type.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $args The register_post_type() arguments.
         */
        $args = apply_filters( 'lisensio_article_post_type_args', $args );

        register_post_type( self::POST_TYPE, $args );
    }

    /**
     * Register the `lisensio_category` and `lisensio_tag` taxonomies.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_taxonomies() {
        $category_labels = array(
            'name'              => _x( 'Categories', 'taxonomy general name', 'lisensio-knowledge-base' ),
            'singular_name'     => _x( 'Category', 'taxonomy singular name', 'lisensio-knowledge-base' ),
            'menu_name'         => __( 'Categories', 'lisensio-knowledge-base' ),
            'all_items'         => __( 'All Categories', 'lisensio-knowledge-base' ),
            'parent_item'       => __( 'Parent Category', 'lisensio-knowledge-base' ),
            'parent_item_colon' => __( 'Parent Category:', 'lisensio-knowledge-base' ),
            'edit_item'         => __( 'Edit Category', 'lisensio-knowledge-base' ),
            'update_item'       => __( 'Update Category', 'lisensio-knowledge-base' ),
            'add_new_item'      => __( 'Add New Category', 'lisensio-knowledge-base' ),
            'new_item_name'     => __( 'New Category Name', 'lisensio-knowledge-base' ),
            'search_items'      => __( 'Search Categories', 'lisensio-knowledge-base' ),
            'not_found'         => __( 'No categories found.', 'lisensio-knowledge-base' ),
        );

        /*
         * No `show_admin_column`: the ledger for this scaffold is explicit that
         * admin columns belong to #3, and that argument's whole effect is a
         * column on the article list table.
         */
        $category_args = array(
            'labels'            => $category_labels,
            'public'            => true,
            'hierarchical'      => true,
            'show_ui'           => true,
            'show_in_nav_menus' => true,
            'show_in_rest'      => true,
            'rest_base'         => 'lisensio_categories',
            'query_var'         => true,
            'rewrite'           => array(
                'slug'         => $this->category_slug(),
                'with_front'   => false,
                'hierarchical' => true,
            ),
        );

        /**
         * Filter the arguments used to register the category taxonomy.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $category_args The register_taxonomy() arguments.
         */
        $category_args = apply_filters( 'lisensio_category_taxonomy_args', $category_args );

        register_taxonomy( self::TAXONOMY_CATEGORY, array( self::POST_TYPE ), $category_args );

        $tag_labels = array(
            'name'          => _x( 'Tags', 'taxonomy general name', 'lisensio-knowledge-base' ),
            'singular_name' => _x( 'Tag', 'taxonomy singular name', 'lisensio-knowledge-base' ),
            'menu_name'     => __( 'Tags', 'lisensio-knowledge-base' ),
            'all_items'     => __( 'All Tags', 'lisensio-knowledge-base' ),
            'edit_item'     => __( 'Edit Tag', 'lisensio-knowledge-base' ),
            'update_item'   => __( 'Update Tag', 'lisensio-knowledge-base' ),
            'add_new_item'  => __( 'Add New Tag', 'lisensio-knowledge-base' ),
            'new_item_name' => __( 'New Tag Name', 'lisensio-knowledge-base' ),
            'search_items'  => __( 'Search Tags', 'lisensio-knowledge-base' ),
            'not_found'     => __( 'No tags found.', 'lisensio-knowledge-base' ),
        );

        $tag_args = array(
            'labels'            => $tag_labels,
            'public'            => true,
            'hierarchical'      => false,
            'show_ui'           => true,
            'show_in_nav_menus' => true,
            'show_in_rest'      => true,
            'rest_base'         => 'lisensio_tags',
            'query_var'         => true,
            'rewrite'           => array(
                'slug'       => $this->tag_slug(),
                'with_front' => false,
            ),
        );

        /**
         * Filter the arguments used to register the tag taxonomy.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $tag_args The register_taxonomy() arguments.
         */
        $tag_args = apply_filters( 'lisensio_tag_taxonomy_args', $tag_args );

        register_taxonomy( self::TAXONOMY_TAG, array( self::POST_TYPE ), $tag_args );
    }

    /**
     * The rewrite slug for articles, from settings.
     *
     * @since 1.0.0
     *
     * @return string The article slug; `docs` by default.
     */
    public function article_slug() {
        return $this->slug( 'article_slug', 'docs' );
    }

    /**
     * The rewrite slug for the category taxonomy, from settings.
     *
     * @since 1.0.0
     *
     * @return string The category slug; `docs-category` by default.
     */
    public function category_slug() {
        return $this->slug( 'category_slug', 'docs-category' );
    }

    /**
     * The rewrite slug for the tag taxonomy, from settings.
     *
     * @since 1.0.0
     *
     * @return string The tag slug; `docs-tag` by default.
     */
    public function tag_slug() {
        return $this->slug( 'tag_slug', 'docs-tag' );
    }

    /**
     * Read a rewrite slug setting, never returning an empty string.
     *
     * The settings store sanitizes these on both read and write, so an empty
     * value should not reach here — but registering a taxonomy with an empty
     * rewrite slug produces broken permastructs rather than an error, so the
     * guard is applied to all three slugs identically rather than to the
     * article slug alone.
     *
     * @since 1.0.0
     *
     * @param string $key      The setting name.
     * @param string $fallback The slug to use when the setting is empty.
     *
     * @return string The rewrite slug.
     */
    private function slug( $key, $fallback ) {
        $slug = (string) $this->settings->get( $key, $fallback );

        return '' === $slug ? $fallback : $slug;
    }
}
