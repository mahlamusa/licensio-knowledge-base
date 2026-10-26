<?php
/**
 * The FAQ block and its shortcode.
 *
 * `licensio-kb/faq` and `[licensio_kb_faq]` share one render callback. The
 * block turns a category of articles into a disclosure list: the article title
 * is the question and its excerpt is the answer, with a link through to the
 * full article.
 *
 * The markup is `<details>`/`<summary>`, which is keyboard accessible and
 * expandable without any JavaScript, so the block behaves the same in the
 * editor preview, on the front end and inside a page builder.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders knowledge base articles as a frequently-asked-questions list.
 *
 * @since 1.0.0
 */
class Licensio_KB_FAQ_Block extends Licensio_KB_Block {

    /**
     * The orderings the block offers.
     *
     * @since 1.0.0
     * @var string[]
     */
    const ORDER_BY = array( 'menu_order', 'date', 'title' );

    /**
     * The block name.
     *
     * @since 1.0.0
     *
     * @return string The block name.
     */
    public static function block_name() {
        return 'licensio-kb/faq';
    }

    /**
     * The shortcode tag.
     *
     * @since 1.0.0
     *
     * @return string The shortcode tag.
     */
    public static function shortcode_tag() {
        return 'licensio_kb_faq';
    }

    /**
     * The `blocks/` sub-directory holding the block metadata.
     *
     * @since 1.0.0
     *
     * @return string The directory name.
     */
    public static function directory() {
        return 'faq';
    }

    /**
     * The BEM modifier used on the block wrapper.
     *
     * @since 1.0.0
     *
     * @return string The modifier.
     */
    public static function modifier() {
        return 'faq';
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
            'orderBy'     => 'menu_order',
            'order'       => 'asc',
            'count'       => 10,
            'showExcerpt' => true,
            'openFirst'   => false,
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
        $values['orderBy']  = self::enum( $values['orderBy'], self::ORDER_BY, 'menu_order' );
        $values['order']    = self::enum( $values['order'], array( 'asc', 'desc' ), 'asc' );
        $values['count']    = self::clamp( $values['count'], 1, 100 );

        return $values;
    }

    /**
     * The block's inner markup.
     *
     * Limited to one category with the default order, the questions follow
     * that category's article order, as the Categories app saves it; articles
     * without a position follow, in their `menu_order`. A category nobody has
     * ordered keeps the plain `menu_order` the block always used.
     *
     * @since 1.0.0 Follows the per-category article order when limited to one ordered category.
     *
     * @param array<string, bool|int|string> $attributes The normalised attributes.
     *
     * @return string The markup.
     */
    public function content( array $attributes ) {
        $orderby = (string) $attributes['orderBy'];

        if ( '' !== (string) $attributes['category'] && 'menu_order' === $orderby ) {
            $term = get_term_by( 'slug', (string) $attributes['category'], Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

            if ( $term instanceof WP_Term && Licensio_KB_Article_Order::has_positions( (int) $term->term_id ) ) {
                $orderby = Licensio_KB_Article_Order::ORDERBY;
            }
        }

        $posts = $this->query_articles(
            (string) $attributes['category'],
            $orderby,
            (string) $attributes['order'],
            (int) $attributes['count']
        );

        return licensio_kb_get_template(
            'faq',
            array(
                'items'        => $this->items( $posts, (bool) $attributes['showExcerpt'] ),
                'open_first'   => (bool) $attributes['openFirst'],
                'show_excerpt' => (bool) $attributes['showExcerpt'],
            ),
            false
        );
    }

    /**
     * Turn articles into question-and-answer pairs.
     *
     * @since 1.0.0
     *
     * @param array<int, mixed> $posts        The articles.
     * @param bool              $show_excerpt Whether the answer carries the article excerpt.
     *
     * @return array<int, array<string, string>> The question, answer and URL of each article.
     */
    public function items( array $posts, $show_excerpt ) {
        $items = array();

        foreach ( $posts as $post ) {
            if ( ! $post instanceof WP_Post ) {
                continue;
            }

            $items[] = array(
                'question' => (string) get_the_title( $post ),
                'answer'   => $show_excerpt ? (string) get_the_excerpt( $post ) : '',
                'url'      => (string) get_permalink( $post ),
            );
        }

        return $items;
    }
}
