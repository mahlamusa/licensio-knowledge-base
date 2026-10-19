<?php
/**
 * The category grid block and its shortcode.
 *
 * `licensio-kb/category-grid` and `[licensio_kb_categories]` share one render
 * callback, so the grid an editor previews is the grid a page builder emits.
 *
 * The categories, their article counts and their children all come from
 * `Licensio_KB_Templates`, which already knows that a parent category's count
 * has to include everything filed beneath it. Nothing about counting or walking
 * the hierarchy is repeated here.
 *
 * The one thing this block does own is the ordering. `child_categories()`
 * returns categories by name, but ordering by article count cannot be asked of
 * the term query: the count that matters includes descendants and is computed
 * in PHP, so the sort has to happen here too.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lists knowledge base categories as a grid of cards.
 *
 * @since 1.0.0
 */
class Licensio_KB_Category_Grid_Block extends Licensio_KB_Block {

    /**
     * The orderings the block offers.
     *
     * @since 1.0.0
     * @var string[]
     */
    const ORDER_BY = array( 'name', 'count' );

    /**
     * The block name.
     *
     * @since 1.0.0
     *
     * @return string The block name.
     */
    public static function block_name() {
        return 'licensio-kb/category-grid';
    }

    /**
     * The shortcode tag.
     *
     * @since 1.0.0
     *
     * @return string The shortcode tag.
     */
    public static function shortcode_tag() {
        return 'licensio_kb_categories';
    }

    /**
     * The `blocks/` sub-directory holding the block metadata.
     *
     * @since 1.0.0
     *
     * @return string The directory name.
     */
    public static function directory() {
        return 'category-grid';
    }

    /**
     * The BEM modifier used on the block wrapper.
     *
     * @since 1.0.0
     *
     * @return string The modifier.
     */
    public static function modifier() {
        return 'category-grid';
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
            'parent'       => '',
            'orderBy'      => 'name',
            'order'        => 'asc',
            'count'        => 0,
            'columns'      => 3,
            'showCount'    => true,
            'showIcon'     => true,
            'showChildren' => true,
        );
    }

    /**
     * Clamp and whitelist the typed attributes.
     *
     * `count` keeps 0 as "no limit", so it is clamped from zero rather than one.
     *
     * @since 1.0.0
     *
     * @param array<string, bool|int|string> $values The typed attributes.
     *
     * @return array<string, bool|int|string> The sanitised attributes.
     */
    protected static function sanitize( array $values ) {
        $values['parent']  = sanitize_title( (string) $values['parent'] );
        $values['orderBy'] = self::enum( $values['orderBy'], self::ORDER_BY, 'name' );
        $values['order']   = self::enum( $values['order'], array( 'asc', 'desc' ), 'asc' );
        $values['count']   = self::clamp( $values['count'], 0, 100 );
        $values['columns'] = self::clamp( $values['columns'], 1, 4 );

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
        $terms = $this->categories( $attributes );

        return licensio_kb_get_template(
            'category-grid',
            array(
                'terms'         => $terms,
                'columns'       => (int) $attributes['columns'],
                'show_count'    => (bool) $attributes['showCount'],
                'show_icon'     => (bool) $attributes['showIcon'],
                'show_children' => (bool) $attributes['showChildren'],
            ),
            false
        );
    }

    /**
     * The categories the grid lists, in the requested order.
     *
     * @since 1.0.0
     *
     * @param array<string, bool|int|string> $attributes The normalised attributes.
     *
     * @return WP_Term[] The categories.
     */
    public function categories( array $attributes ) {
        $parent = $this->parent_id( (string) $attributes['parent'] );

        if ( null === $parent ) {
            return array();
        }

        $terms = Licensio_KB_Templates::child_categories( $parent );

        /**
         * Filter the categories a knowledge base category grid lists.
         *
         * @since 1.0.0
         *
         * @param WP_Term[]                      $terms      The categories, before ordering and trimming.
         * @param array<string, bool|int|string> $attributes The normalised block attributes.
         */
        $terms = (array) apply_filters( 'licensio_block_category_grid_terms', $terms, $attributes );

        $terms = self::order( $terms, (string) $attributes['orderBy'], (string) $attributes['order'] );

        $count = (int) $attributes['count'];

        return $count > 0 ? array_slice( $terms, 0, $count ) : $terms;
    }

    /**
     * The term ID the grid lists the children of.
     *
     * @since 1.0.0
     *
     * @param string $slug The parent category slug, or an empty string for the top level.
     *
     * @return int|null The parent term ID, or null when the named category does not exist.
     */
    private function parent_id( $slug ) {
        if ( '' === $slug ) {
            return 0;
        }

        $term = get_term_by( 'slug', $slug, Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        return $term instanceof WP_Term ? (int) $term->term_id : null;
    }

    /**
     * Sort categories by name or by article count.
     *
     * The sort is done here rather than left to the term query because the
     * count that matters is the one that includes sub-categories, and because
     * a caller of this block should get the same order whatever the term query
     * happened to return. The term ID is the tie-break, so two categories with
     * the same name or the same count keep a stable order under PHP 7.4, where
     * `usort()` is not itself stable.
     *
     * @since 1.0.0
     *
     * @param array<int, mixed> $terms   The categories.
     * @param string            $orderby `name` or `count`.
     * @param string            $order   `asc` or `desc`.
     *
     * @return WP_Term[] The ordered categories.
     */
    public static function order( array $terms, $orderby, $order ) {
        $terms = array_values(
            array_filter(
                $terms,
                function ( $term ) {
                    return $term instanceof WP_Term;
                }
            )
        );

        $direction = 'desc' === $order ? -1 : 1;

        usort(
            $terms,
            function ( WP_Term $first, WP_Term $second ) use ( $orderby, $direction ) {
                if ( 'count' === $orderby ) {
                    $comparison = Licensio_KB_Templates::category_article_count( $first )
                        - Licensio_KB_Templates::category_article_count( $second );
                } else {
                    $comparison = strnatcasecmp( $first->name, $second->name );
                }

                if ( 0 !== $comparison ) {
                    return $direction * $comparison;
                }

                return $first->term_id - $second->term_id;
            }
        );

        return $terms;
    }
}
