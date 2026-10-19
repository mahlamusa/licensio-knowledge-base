<?php
/**
 * The article list block and its shortcode.
 *
 * `licensio-kb/article-list` and `[licensio_kb_articles]` are one feature
 * reached two ways: both are served by `Licensio_KB_Block::render()`, so the
 * markup cannot differ between them.
 *
 * The cards are drawn by `templates/article-card.php` through
 * `licensio_kb_get_template()`, which means a theme that overrides the card
 * changes the block's output as well as the archive's.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lists knowledge base articles as cards.
 *
 * @since 1.0.0
 */
class Licensio_KB_Article_List_Block extends Licensio_KB_Block {

    /**
     * The orderings the block offers.
     *
     * @since 1.0.0
     * @var string[]
     */
    const ORDER_BY = array( 'date', 'title', 'menu_order', 'modified' );

    /**
     * The block name.
     *
     * @since 1.0.0
     *
     * @return string The block name.
     */
    public static function block_name() {
        return 'licensio-kb/article-list';
    }

    /**
     * The shortcode tag.
     *
     * @since 1.0.0
     *
     * @return string The shortcode tag.
     */
    public static function shortcode_tag() {
        return 'licensio_kb_articles';
    }

    /**
     * The `blocks/` sub-directory holding the block metadata.
     *
     * @since 1.0.0
     *
     * @return string The directory name.
     */
    public static function directory() {
        return 'article-list';
    }

    /**
     * The BEM modifier used on the block wrapper.
     *
     * @since 1.0.0
     *
     * @return string The modifier.
     */
    public static function modifier() {
        return 'article-list';
    }

    /**
     * The attributes the block accepts, and their defaults.
     *
     * @since 1.0.0
     *
     * @return array<string, bool|int|string> Attribute name to default value.
     */
    public static function defaults() {
        return array(
            'category'    => '',
            'orderBy'     => 'date',
            'order'       => 'desc',
            'count'       => 5,
            'columns'     => 1,
            'showExcerpt' => true,
        );
    }

    /**
     * Clamp and whitelist the typed attributes.
     *
     * @since 1.0.0
     *
     * @param array<string, bool|int|string> $values The typed attributes.
     *
     * @return array<string, bool|int|string> The sanitised attributes.
     */
    protected static function sanitize( array $values ) {
        $values['category'] = sanitize_title( (string) $values['category'] );
        $values['orderBy']  = self::enum( $values['orderBy'], self::ORDER_BY, 'date' );
        $values['order']    = self::enum( $values['order'], array( 'asc', 'desc' ), 'desc' );
        $values['count']    = self::clamp( $values['count'], 1, 100 );
        $values['columns']  = self::clamp( $values['columns'], 1, 4 );

        return $values;
    }

    /**
     * The block's inner markup.
     *
     * @since 1.0.0
     *
     * @param array<string, bool|int|string> $attributes The normalised attributes.
     *
     * @return string The markup.
     */
    public function content( array $attributes ) {
        $posts = $this->query_articles(
            (string) $attributes['category'],
            (string) $attributes['orderBy'],
            (string) $attributes['order'],
            (int) $attributes['count']
        );

        return licensio_kb_get_template(
            'article-list',
            array(
                'posts'        => $posts,
                'columns'      => (int) $attributes['columns'],
                'show_excerpt' => (bool) $attributes['showExcerpt'],
            ),
            false
        );
    }
}
