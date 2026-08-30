<?php
/**
 * Article card.
 *
 * One article in a list or grid. Override from a theme by copying this file to
 * `lisensio-kb/article-card.php`.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 *
 * @var array $args {
 *     Template arguments.
 *
 *     @type WP_Post|int $post The article. Defaults to the current post.
 * }
 */

defined( 'ABSPATH' ) || exit;

$lisensio_kb_article = get_post( isset( $args['post'] ) ? $args['post'] : null );

if ( ! $lisensio_kb_article instanceof WP_Post ) {
    return;
}

$lisensio_kb_article_url     = (string) get_permalink( $lisensio_kb_article );
$lisensio_kb_article_excerpt = (string) get_the_excerpt( $lisensio_kb_article );
?>
<article class="lisensio-kb-card lisensio-kb-card--article">
    <h3 class="lisensio-kb-card__title">
        <a class="lisensio-kb-card__link" href="<?php echo esc_url( $lisensio_kb_article_url ); ?>">
            <?php echo esc_html( get_the_title( $lisensio_kb_article ) ); ?>
        </a>
    </h3>
    <?php if ( '' !== $lisensio_kb_article_excerpt ) : ?>
        <p class="lisensio-kb-card__excerpt"><?php echo esc_html( $lisensio_kb_article_excerpt ); ?></p>
    <?php endif; ?>
</article>
