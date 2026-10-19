<?php
/**
 * Article card.
 *
 * One article in a list or grid. Override from a theme by copying this file to
 * `licensio-kb/article-card.php`.
 *
 * @package Licensio_KB
 * @since   1.0.0 Added the `show_excerpt` argument for the article list block.
 * @since   1.0.0 Replaced the theme's excerpt "more" link with a plain ellipsis so it is not printed as raw HTML.
 *
 * @var array $args {
 *     Template arguments.
 *
 *     @type WP_Post|int $post         The article. Defaults to the current post.
 *     @type bool        $show_excerpt Whether the excerpt is shown. Default true.
 * }
 */

defined( 'ABSPATH' ) || exit;

$licensio_kb_article = get_post( isset( $args['post'] ) ? $args['post'] : null );

if ( ! $licensio_kb_article instanceof WP_Post ) {
    return;
}

$licensio_kb_show_excerpt    = ! isset( $args['show_excerpt'] ) || (bool) $args['show_excerpt'];
$licensio_kb_article_url     = (string) get_permalink( $licensio_kb_article );
$licensio_kb_article_excerpt = '';

if ( $licensio_kb_show_excerpt ) {
    /*
     * The excerpt is printed escaped, so a theme's "Continue reading" link
     * from `excerpt_more` would show up as raw HTML. The card title is the
     * link already, so a plain ellipsis takes its place for this one call.
     */
    $licensio_kb_excerpt_more = static function () {
        return "\u{2026}";
    };

    add_filter( 'excerpt_more', $licensio_kb_excerpt_more, PHP_INT_MAX );
    $licensio_kb_article_excerpt = (string) get_the_excerpt( $licensio_kb_article );
    remove_filter( 'excerpt_more', $licensio_kb_excerpt_more, PHP_INT_MAX );
}
?>
<article class="licensio-kb-card licensio-kb-card--article">
    <h3 class="licensio-kb-card__title">
        <a class="licensio-kb-card__link" href="<?php echo esc_url( $licensio_kb_article_url ); ?>">
            <?php echo esc_html( get_the_title( $licensio_kb_article ) ); ?>
        </a>
    </h3>
    <?php if ( '' !== $licensio_kb_article_excerpt ) : ?>
        <p class="licensio-kb-card__excerpt"><?php echo esc_html( $licensio_kb_article_excerpt ); ?></p>
    <?php endif; ?>
</article>
