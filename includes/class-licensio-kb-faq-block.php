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
 * `entries()` is the public way to read the questions and answers a block
 * lists, for another plugin that marks them up, e.g. as `FAQPage` structured
 * data. The markup is built from the same call, so the two never disagree.
 *
 * @package Licensio_KB
 * @since   1.0.0
 * @since   1.1.0 Adds entries(), the public list of the questions the block renders.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders knowledge base articles as a frequently-asked-questions list.
 *
 * @since 1.0.0
 * @since 1.1.0 Adds entries(), the public list of the questions the block renders.
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
     * The questions come from `entries()`, so the markup and any other plugin
     * reading the same attributes list the same articles in the same order.
     *
     * @since 1.0.0 Follows the per-category article order when limited to one ordered category.
     * @since 1.1.0 Reads its questions from entries().
     *
     * @param array<string, bool|int|string> $attributes The normalised attributes.
     *
     * @return string The markup.
     */
    public function content( array $attributes ) {
        return licensio_kb_get_template(
            'faq',
            array(
                'items'        => static::entries( $attributes ),
                'open_first'   => (bool) $attributes['openFirst'],
                'show_excerpt' => (bool) $attributes['showExcerpt'],
            ),
            false
        );
    }

    /**
     * The questions and answers the block renders for a set of attributes.
     *
     * Takes the attributes as stored in the block comment or given to the
     * shortcode, e.g. `$block['attrs']` from `parse_blocks()`, and normalises
     * them the way the block does. The articles come from the same query, the
     * same `licensio_block_article_query_args` filter and the same
     * per-category order as the rendered block, in the same order.
     *
     * The answer is the article excerpt, or an empty string when `showExcerpt`
     * is off; callers decide whether to skip an entry without one. An untitled
     * article has an empty question, which the block does not print. Values
     * are raw text, not escaped: escape them for the context they are printed
     * in.
     *
     * Part of the extension API, detectable through `LICENSIO_KB_EXTENSIONS_API`.
     *
     * @since 1.1.0
     *
     * @param array<string, mixed> $attributes The block or shortcode attributes.
     *
     * @return array<int, array{question: string, answer: string, url: string, post_id: int}> One entry per article, in display order.
     */
    public static function entries( array $attributes ) {
        $attributes = static::normalize( $attributes );
        $orderby    = (string) $attributes['orderBy'];

        if ( '' !== (string) $attributes['category'] && 'menu_order' === $orderby ) {
            $term = get_term_by( 'slug', (string) $attributes['category'], Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

            if ( $term instanceof WP_Term && Licensio_KB_Article_Order::has_positions( (int) $term->term_id ) ) {
                $orderby = Licensio_KB_Article_Order::ORDERBY;
            }
        }

        $block = new self();
        $posts = $block->query_articles(
            (string) $attributes['category'],
            $orderby,
            (string) $attributes['order'],
            (int) $attributes['count']
        );

        return $block->items( $posts, (bool) $attributes['showExcerpt'] );
    }

    /**
     * Turn articles into question-and-answer pairs.
     *
     * @since 1.0.0
     * @since 1.1.0 Adds the article ID as post_id.
     *
     * @param array<int, mixed> $posts        The articles.
     * @param bool              $show_excerpt Whether the answer carries the article excerpt.
     *
     * @return array<int, array{question: string, answer: string, url: string, post_id: int}> The question, answer, URL and ID of each article.
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
                'post_id'  => (int) $post->ID,
            );
        }

        return $items;
    }
}
