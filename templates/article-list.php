<?php
/**
 * Article list.
 *
 * The body of the `licensio-kb/article-list` block and the
 * `[licensio_kb_articles]` shortcode. Each article is drawn by
 * `article-card.php`, so overriding the card changes this listing too.
 * Override from a theme by copying this file to `licensio-kb/article-list.php`.
 *
 * @package Licensio_KB
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

$licensio_kb_articles     = isset( $args['posts'] ) && is_array( $args['posts'] ) ? $args['posts'] : array();
$licensio_kb_columns      = isset( $args['columns'] ) ? max( 1, min( 4, (int) $args['columns'] ) ) : 1;
$licensio_kb_show_excerpt = ! isset( $args['show_excerpt'] ) || (bool) $args['show_excerpt'];

if ( empty( $licensio_kb_articles ) ) {
    licensio_kb_get_template(
        'no-results',
        array(
            'title'       => __( 'No articles found', 'licensio-knowledge-base' ),
            'description' => __( 'Nothing has been published here yet.', 'licensio-knowledge-base' ),
        )
    );

    return;
}
?>
<div class="licensio-kb__grid licensio-kb__grid--cols-<?php echo esc_attr( (string) $licensio_kb_columns ); ?>">
    <?php foreach ( $licensio_kb_articles as $licensio_kb_article ) : ?>
        <?php
        licensio_kb_get_template(
            'article-card',
            array(
                'post'         => $licensio_kb_article,
                'show_excerpt' => $licensio_kb_show_excerpt,
            )
        );
        ?>
    <?php endforeach; ?>
</div>
