<?php
/**
 * Category grid.
 *
 * The body of the `lisensio-kb/category-grid` block and the
 * `[lisensio_kb_categories]` shortcode. Each category is drawn by
 * `category-card.php`, so overriding the card changes this grid too. Override
 * from a theme by copying this file to `lisensio-kb/category-grid.php`.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 *
 * @var array $args {
 *     Template arguments.
 *
 *     @type WP_Term[] $terms         The categories to list.
 *     @type int       $columns       How many columns the grid uses. Default 3.
 *     @type bool      $show_count    Whether each card carries its article count. Default true.
 *     @type bool      $show_icon     Whether each card carries its icon. Default false.
 *     @type bool      $show_children Whether each card lists its sub-categories. Default true.
 * }
 */

defined( 'ABSPATH' ) || exit;

$lisensio_kb_terms         = isset( $args['terms'] ) && is_array( $args['terms'] ) ? $args['terms'] : array();
$lisensio_kb_columns       = isset( $args['columns'] ) ? max( 1, min( 4, (int) $args['columns'] ) ) : 3;
$lisensio_kb_show_count    = ! isset( $args['show_count'] ) || (bool) $args['show_count'];
$lisensio_kb_show_icon     = isset( $args['show_icon'] ) && (bool) $args['show_icon'];
$lisensio_kb_show_children = ! isset( $args['show_children'] ) || (bool) $args['show_children'];

if ( empty( $lisensio_kb_terms ) ) {
    lisensio_kb_get_template(
        'no-results',
        array(
            'title'       => __( 'No categories found', 'lisensio-knowledge-base' ),
            'description' => __( 'Nothing has been filed here yet.', 'lisensio-knowledge-base' ),
        )
    );

    return;
}
?>
<div class="lisensio-kb__grid lisensio-kb__grid--cols-<?php echo esc_attr( (string) $lisensio_kb_columns ); ?>">
    <?php foreach ( $lisensio_kb_terms as $lisensio_kb_term ) : ?>
        <?php
        lisensio_kb_get_template(
            'category-card',
            array(
                'term'          => $lisensio_kb_term,
                'show_count'    => $lisensio_kb_show_count,
                'show_icon'     => $lisensio_kb_show_icon,
                'show_children' => $lisensio_kb_show_children,
            )
        );
        ?>
    <?php endforeach; ?>
</div>
