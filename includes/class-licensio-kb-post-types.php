<?php
/**
 * Content model registration.
 *
 * Bare registration of the `licensio_article` post type and the
 * `licensio_category` and `licensio_tag` taxonomies. This is the data
 * contract every other sub-issue builds against.
 *
 * Scope note: registration ONLY. Term meta UI, admin columns and capability
 * mapping belong to #3 and must not be added here.
 *
 * Consumed by: #3, #4, #5, #6, #7, #8, #9, #12.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the knowledge base post type and taxonomies.
 *
 * @since 1.0.0
 */
class Licensio_KB_Post_Types {

    /**
     * The article post type name.
     *
     * @since 1.0.0
     * @var string
     */
    const POST_TYPE = 'licensio_article';

    /**
     * The hierarchical category taxonomy name.
     *
     * @since 1.0.0
     * @var string
     */
    const TAXONOMY_CATEGORY = 'licensio_category';

    /**
     * The flat tag taxonomy name.
     *
     * @since 1.0.0
     * @var string
     */
    const TAXONOMY_TAG = 'licensio_tag';

    /**
     * Where the knowledge base sits in the admin menu.
     *
     * Directly below License Server, which registers itself at 50. Adjacency
     * is the point: the two are one product, and a reader scanning the rail
     * should find them together rather than with 24 positions of unrelated
     * menus between them.
     *
     * The icon is deliberately *not* License Server's. Two adjacent entries
     * sharing a silhouette are unnavigable once the rail is collapsed, where
     * WordPress hides the label and the icon is the only thing left to read.
     * Same weight, same brand green, different shape.
     *
     * @since 1.0.0
     * @var int
     */
    const MENU_POSITION = 51;

    /**
     * Settings store used to resolve the rewrite slugs.
     *
     * @since 1.0.0
     * @var Licensio_KB_Settings
     */
    private $settings;

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_Settings $settings The settings store.
     */
    public function __construct( Licensio_KB_Settings $settings ) {
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

        add_filter( 'post_type_archive_title', array( $this, 'filter_archive_title' ), 10, 2 );
        add_filter( 'statute_seo_breadcrumbs_items', array( $this, 'filter_breadcrumb_items' ) );
    }

    /**
     * The heading the public archive is announced with.
     *
     * WordPress titles a post type archive with the type's plural label, which
     * here is "Articles" - a word that appears nowhere else the reader has
     * been. The menu says Docs, the address says /docs/, and the design calls
     * the section Documentation. "Articles" matched none of them.
     *
     * One word per thing, rather than one word everywhere: an *article* is the
     * item, the *knowledge base* is the thing being administered, and
     * *Documentation* is the published section a reader arrives at. Three
     * nouns for three different objects is register, not inconsistency - the
     * archive borrowing the item noun for the whole section was the error.
     *
     * @since 1.0.0
     *
     * @param string $title     The archive title WordPress derived.
     * @param string $post_type The post type being archived.
     *
     * @return string The title, replaced only for this plugin's archive.
     */
    public function filter_archive_title( $title, $post_type = '' ) {
        if ( self::POST_TYPE !== $post_type ) {
            return $title;
        }

        return __( 'Documentation', 'licensio-knowledge-base' );
    }

    /**
     * Rename the archive crumb in the site's breadcrumb trail.
     *
     * `filter_archive_title()` only reaches what WordPress derives from the
     * archive title. Breadcrumbs read the post type's plural label straight
     * off the object, so the trail still said "Articles" under a heading that
     * said "Documentation" - on the archive and on every single article, which
     * carries the archive as an ancestor.
     *
     * The crumb is matched on its URL rather than its position or its text.
     * Position moves with the trail's shape (an archive trail is two items,
     * a single article's is four), and matching the text would rename any
     * crumb that happened to read "Articles", including another plugin's.
     * The archive link is the one thing that identifies this crumb and only
     * this crumb.
     *
     * @since 1.0.0
     *
     * @param array $items Breadcrumb items, each with `name` and `url` keys.
     *
     * @return array The items, with this plugin's archive crumb renamed.
     */
    public function filter_breadcrumb_items( $items ) {
        if ( ! is_array( $items ) ) {
            return $items;
        }

        $archive = get_post_type_archive_link( self::POST_TYPE );

        if ( ! is_string( $archive ) || '' === $archive ) {
            return $items;
        }

        foreach ( $items as $index => $item ) {
            if ( isset( $item['url'] ) && $archive === $item['url'] ) {
                $items[ $index ]['name'] = __( 'Documentation', 'licensio-knowledge-base' );
            }
        }

        return $items;
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
        if ( ! Licensio_KB_Settings::needs_rewrite_flush() ) {
            return;
        }

        Licensio_KB_Settings::clear_rewrite_flush();

        flush_rewrite_rules( false );
    }

    /**
     * The admin menu icon as a base64 SVG data URL.
     *
     * WordPress draws a data URL icon the way it draws a dashicon, centred in
     * the menu row, and recolours it to the admin colour scheme, so the brand
     * mark turns white on hover and on the current page like the core icons.
     * An icon given as a file URL is drawn as a fixed-colour `img` instead.
     *
     * @since 1.0.0
     *
     * @return string The data URL, or an empty string when the file is missing.
     */
    private function menu_icon() {
        $svg = file_get_contents( LICENSIO_KB_ABSPATH . 'assets/images/logo/knowledge_base_icon.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file bundled with the plugin.

        if ( false === $svg ) {
            return '';
        }

        return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- WordPress only recolours menu icons given as base64 SVG data URLs.
    }

    /**
     * Register the `licensio_article` post type.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_post_type() {
        $labels = array(
            'name'                  => _x( 'Articles', 'post type general name', 'licensio-knowledge-base' ),
            'singular_name'         => _x( 'Article', 'post type singular name', 'licensio-knowledge-base' ),
            'menu_name'             => _x( 'Knowledge Base', 'admin menu', 'licensio-knowledge-base' ),
            'name_admin_bar'        => _x( 'Article', 'add new on admin bar', 'licensio-knowledge-base' ),
            'add_new'               => __( 'Add New', 'licensio-knowledge-base' ),
            'add_new_item'          => __( 'Add New Article', 'licensio-knowledge-base' ),
            'new_item'              => __( 'New Article', 'licensio-knowledge-base' ),
            'edit_item'             => __( 'Edit Article', 'licensio-knowledge-base' ),
            'view_item'             => __( 'View Article', 'licensio-knowledge-base' ),
            'view_items'            => __( 'View Articles', 'licensio-knowledge-base' ),
            'all_items'             => __( 'All Articles', 'licensio-knowledge-base' ),
            'search_items'          => __( 'Search Articles', 'licensio-knowledge-base' ),
            'not_found'             => __( 'No articles found.', 'licensio-knowledge-base' ),
            'not_found_in_trash'    => __( 'No articles found in Trash.', 'licensio-knowledge-base' ),
            'archives'              => __( 'Article Archives', 'licensio-knowledge-base' ),
            'featured_image'        => __( 'Article Image', 'licensio-knowledge-base' ),
            'set_featured_image'    => __( 'Set article image', 'licensio-knowledge-base' ),
            'remove_featured_image' => __( 'Remove article image', 'licensio-knowledge-base' ),
            'use_featured_image'    => __( 'Use as article image', 'licensio-knowledge-base' ),
            'item_published'        => __( 'Article published.', 'licensio-knowledge-base' ),
            'item_updated'          => __( 'Article updated.', 'licensio-knowledge-base' ),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_nav_menus'  => true,
            'show_in_rest'       => true,
            'rest_base'          => 'licensio_articles',
            'has_archive'        => $this->article_slug(),
            'hierarchical'       => false,
            'menu_position'      => self::MENU_POSITION,
            'menu_icon'          => $this->menu_icon(),
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
        $args = apply_filters( 'licensio_article_post_type_args', $args );

        register_post_type( self::POST_TYPE, $args );
    }

    /**
     * Register the `licensio_category` and `licensio_tag` taxonomies.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_taxonomies() {
        $category_labels = array(
            'name'              => _x( 'Categories', 'taxonomy general name', 'licensio-knowledge-base' ),
            'singular_name'     => _x( 'Category', 'taxonomy singular name', 'licensio-knowledge-base' ),
            'menu_name'         => __( 'Categories', 'licensio-knowledge-base' ),
            'all_items'         => __( 'All Categories', 'licensio-knowledge-base' ),
            'parent_item'       => __( 'Parent Category', 'licensio-knowledge-base' ),
            'parent_item_colon' => __( 'Parent Category:', 'licensio-knowledge-base' ),
            'edit_item'         => __( 'Edit Category', 'licensio-knowledge-base' ),
            'update_item'       => __( 'Update Category', 'licensio-knowledge-base' ),
            'add_new_item'      => __( 'Add New Category', 'licensio-knowledge-base' ),
            'new_item_name'     => __( 'New Category Name', 'licensio-knowledge-base' ),
            'search_items'      => __( 'Search Categories', 'licensio-knowledge-base' ),
            'not_found'         => __( 'No categories found.', 'licensio-knowledge-base' ),
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
            'rest_base'         => 'licensio_categories',
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
        $category_args = apply_filters( 'licensio_category_taxonomy_args', $category_args );

        register_taxonomy( self::TAXONOMY_CATEGORY, array( self::POST_TYPE ), $category_args );

        $tag_labels = array(
            'name'          => _x( 'Tags', 'taxonomy general name', 'licensio-knowledge-base' ),
            'singular_name' => _x( 'Tag', 'taxonomy singular name', 'licensio-knowledge-base' ),
            'menu_name'     => __( 'Tags', 'licensio-knowledge-base' ),
            'all_items'     => __( 'All Tags', 'licensio-knowledge-base' ),
            'edit_item'     => __( 'Edit Tag', 'licensio-knowledge-base' ),
            'update_item'   => __( 'Update Tag', 'licensio-knowledge-base' ),
            'add_new_item'  => __( 'Add New Tag', 'licensio-knowledge-base' ),
            'new_item_name' => __( 'New Tag Name', 'licensio-knowledge-base' ),
            'search_items'  => __( 'Search Tags', 'licensio-knowledge-base' ),
            'not_found'     => __( 'No tags found.', 'licensio-knowledge-base' ),
        );

        $tag_args = array(
            'labels'            => $tag_labels,
            'public'            => true,
            'hierarchical'      => false,
            'show_ui'           => true,
            'show_in_nav_menus' => true,
            'show_in_rest'      => true,
            'rest_base'         => 'licensio_tags',
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
        $tag_args = apply_filters( 'licensio_tag_taxonomy_args', $tag_args );

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
