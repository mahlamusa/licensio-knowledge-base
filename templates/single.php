<?php
/**
 * Single article.
 *
 * Fires the three frontend hooks so feedback, analytics and gating attach
 * without touching this file. Override from a theme by copying it to
 * `lisensio-kb/single.php`.
 *
 * The article's heading and breadcrumb trail come from the shared hero, the
 * same one the archive and every taxonomy view render. The article header that
 * remains carries only the modified date, which is the article's own metadata
 * rather than part of the page heading.
 *
 * @package Lisensio_KB
 * @since   1.0.0 Rendered the heading and breadcrumbs through `templates/hero.php`.
 */

defined( 'ABSPATH' ) || exit;

get_header();

/*
 * The hero is resolved before the loop, because it renders outside the
 * `.lisensio-kb` wrapper and the loop opens inside it. WordPress populates the
 * global post for a singular query before the loop runs, so this is the same
 * article the loop below is about to hand to `the_post()`.
 */
$lisensio_kb_hero_post = get_post();
?>
<div class="lisensio-kb lisensio-kb--bleed">
    <?php
    lisensio_kb_get_template(
        'hero',
        array(
            'title'  => $lisensio_kb_hero_post instanceof WP_Post ? get_the_title( $lisensio_kb_hero_post ) : '',
            'search' => true,
        )
    );
    ?>
</div>

<div class="lisensio-kb lisensio-kb--single">
    <?php
    while ( have_posts() ) :
        the_post();

        $lisensio_kb_article = get_post();

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
