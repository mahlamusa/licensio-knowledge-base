<?php
/**
 * Article list.
 *
 * The body of the `lisensio-kb/article-list` block and the
 * `[lisensio_kb_articles]` shortcode. Each article is drawn by
 * `article-card.php`, so overriding the card changes this listing too.
 * Override from a theme by copying this file to `lisensio-kb/article-list.php`.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 *
 * @var array $args {
 *     Template arguments.
 *
 *     @type WP_Post[] $posts        The articles to list.
 *     @type int       $columns      How many columns the grid uses. Default 1.
 *     @type bool      $show_excerpt Whether each card carries its excerpt. Default true.
 * }
 */

defined( 'ABSPATH' ) || exit;

$lisensio_kb_articles     = isset( $args['posts'] ) && is_array( $args['posts'] ) ? $args['posts'] : array();
$lisensio_kb_columns      = isset( $args['columns'] ) ? max( 1, min( 4, (int) $args['columns'] ) ) : 1;
$lisensio_kb_show_excerpt = ! isset( $args['show_excerpt'] ) || (bool) $args['show_excerpt'];

if ( empty( $lisensio_kb_articles ) ) {
    lisensio_kb_get_template(
        'no-results',
        array(
            'title'       => __( 'No articles found', 'lisensio-knowledge-base' ),
            'description' => __( 'Nothing has been published here yet.', 'lisensio-knowledge-base' ),
        )
    );

    return;
}
?>
<div class="lisensio-kb__grid lisensio-kb__grid--cols-<?php echo esc_attr( (string) $lisensio_kb_columns ); ?>">
    <?php foreach ( $lisensio_kb_articles as $lisensio_kb_article ) : ?>
        <?php
        lisensio_kb_get_template(
            'article-card',
            array(
                'post'         => $lisensio_kb_article,
                'show_excerpt' => $lisensio_kb_show_excerpt,
            )
        );
        ?>
    <?php endforeach; ?>
</div>
