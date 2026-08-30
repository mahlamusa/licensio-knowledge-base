<?php
/**
 * Block and shortcode registration.
 *
 * Owns the knowledge base block category, the stylesheets the blocks declare in
 * their `block.json`, and the registration of every block together with its
 * shortcode twin.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the knowledge base blocks and their shortcodes.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Blocks {

    /**
     * The block category the knowledge base blocks are filed under.
     *
     * @since 1.0.0
     * @var string
     */
    const CATEGORY = 'lisensio-kb';

    /**
     * The stylesheet handle the blocks declare as their frontend style.
     *
     * @since 1.0.0
     * @var string
     */
    const STYLE_HANDLE = 'lisensio-kb-blocks';

    /**
     * The stylesheet handle the blocks declare as their editor style.
     *
     * @since 1.0.0
     * @var string
     */
    const EDITOR_STYLE_HANDLE = 'lisensio-kb-blocks-editor';

    /**
     * The instantiated blocks, keyed by block name.
     *
     * @since 1.0.0
     * @var array<string, Lisensio_KB_Block>
     */
    private $blocks = array();

    /**
     * Register the WordPress hooks.
     *
     * Assets are registered before the blocks so the handles named in each
     * `block.json` already exist when `register_block_type()` resolves them.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'init', array( $this, 'register_assets' ), 5 );
        add_action( 'init', array( $this, 'register' ), 20 );
        add_filter( 'block_categories_all', array( $this, 'register_category' ) );
    }

    /**
     * Register the stylesheets the blocks declare.
     *
     * The base knowledge base stylesheet is registered here as well as by
     * `Lisensio_KB_Templates`, under the same handle, the same source and the
     * same version. The template layer registers it on `wp_enqueue_scripts`,
     * which never runs in the block editor, so a block preview would otherwise
     * be missing every custom property the design is built on. Registering an
     * already-registered handle is a no-op, so the two calls cannot disagree.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_assets() {
        wp_register_style(
            Lisensio_KB_Templates::STYLE_HANDLE,
            LISENSIO_KB_ASSETS_URL . 'css/lisensio-kb.css',
            array(),
            LISENSIO_KB_VERSION
        );

        wp_register_style(
            self::STYLE_HANDLE,
            LISENSIO_KB_ASSETS_URL . 'css/blocks.css',
            array( Lisensio_KB_Templates::STYLE_HANDLE ),
            LISENSIO_KB_VERSION
        );

        wp_register_style(
            self::EDITOR_STYLE_HANDLE,
            LISENSIO_KB_ASSETS_URL . 'css/blocks-editor.css',
            array( self::STYLE_HANDLE ),
            LISENSIO_KB_VERSION
        );
    }

    /**
     * Register every block and its shortcode.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register() {
        $blocks = array(
            new Lisensio_KB_Article_List_Block(),
            new Lisensio_KB_Category_Grid_Block(),
            new Lisensio_KB_Breadcrumbs_Block(),
            new Lisensio_KB_FAQ_Block(),
        );

        foreach ( $blocks as $block ) {
            $block->register();

            $this->blocks[ $block::block_name() ] = $block;
        }
    }

    /**
     * The registered blocks, keyed by block name.
     *
     * @since 1.0.0
     *
     * @return array<string, Lisensio_KB_Block> The blocks.
     */
    public function blocks() {
        return $this->blocks;
    }

    /**
     * Add the knowledge base block category to the inserter.
     *
     * @since 1.0.0
     *
     * @param array<int, array<string, mixed>> $categories The registered block categories.
     *
     * @return array<int, array<string, mixed>> The categories, with the knowledge base one added.
     */
    public function register_category( $categories ) {
        if ( ! is_array( $categories ) ) {
            return $categories;
        }

        foreach ( $categories as $category ) {
            if ( is_array( $category ) && isset( $category['slug'] ) && self::CATEGORY === $category['slug'] ) {
                return $categories;
            }
        }

        array_unshift(
            $categories,
            array(
                'slug'  => self::CATEGORY,
                'title' => __( 'Knowledge Base', 'lisensio-knowledge-base' ),
                'icon'  => null,
            )
        );

        return $categories;
    }
}
