<?php
/**
 * Single article.
 *
 * Fires the three frontend hooks so feedback, analytics and gating attach
 * without touching this file. Override from a theme by copying it to
 * `lisensio-kb/single.php`.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div class="lisensio-kb lisensio-kb--single">
    <?php
    while ( have_posts() ) :
        the_post();

        $lisensio_kb_article = get_post();

        lisensio_kb_get_template( 'breadcrumbs', array( 'items' => Lisensio_KB_Templates::breadcrumb_items() ) );

        $lisensio_kb_article_tags = get_the_term_list(
            $lisensio_kb_article->ID,
            Lisensio_KB_Post_Types::TAXONOMY_TAG,
            '',
            ', ',
            ''
        );
        ?>
        <article id="lisensio-kb-article-<?php echo esc_attr( (string) $lisensio_kb_article->ID ); ?>" class="lisensio-kb-article">
            <header class="lisensio-kb-article__header">
                <h1 class="lisensio-kb-article__title"><?php echo esc_html( get_the_title( $lisensio_kb_article ) ); ?></h1>
                <p class="lisensio-kb-article__meta">
                    <?php
                    printf(
                        /* translators: %s: the date the article was last updated. */
                        esc_html__( 'Last updated on %s', 'lisensio-knowledge-base' ),
                        esc_html( get_the_modified_date( '', $lisensio_kb_article ) )
                    );
                    ?>
                </p>
            </header>

            <?php lisensio_kb_before_article( $lisensio_kb_article ); ?>

            <div class="lisensio-kb-article__body">
                <?php the_content(); ?>
            </div>

            <?php lisensio_kb_after_article( $lisensio_kb_article ); ?>

            <footer class="lisensio-kb-article__footer">
                <?php if ( is_string( $lisensio_kb_article_tags ) && '' !== $lisensio_kb_article_tags ) : ?>
                    <p class="lisensio-kb-article__tags">
                        <span class="lisensio-kb-article__tags-label"><?php esc_html_e( 'Tagged:', 'lisensio-knowledge-base' ); ?></span>
                        <?php echo wp_kses_post( $lisensio_kb_article_tags ); ?>
                    </p>
                <?php endif; ?>

                <?php lisensio_kb_article_footer( $lisensio_kb_article ); ?>
            </footer>
        </article>
        <?php
    endwhile;
    ?>
</div>
<?php
get_footer();
