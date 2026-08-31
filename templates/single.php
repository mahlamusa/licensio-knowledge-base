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
 * The article is rendered into a buffer before anything is printed. The table
 * of contents is collected while `the_content` runs, so the sidebar cannot be
 * composed until the article has been rendered — and whether there is a
 * sidebar at all decides whether the two-column wrapper is opened. Buffering
 * is what lets both questions be answered before the first tag is printed,
 * rather than opening a column and discovering afterwards that nothing goes in
 * it.
 *
 * @package Lisensio_KB
 * @since   1.0.0 Rendered the heading and breadcrumbs through `templates/hero.php`.
 * @since   1.0.0 Added the sidebar and the table of contents.
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

<?php
ob_start();

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

$lisensio_kb_body = (string) ob_get_clean();

$lisensio_kb_position = Lisensio_KB_Sidebar::position( Lisensio_KB_Sidebar::CONTEXT_ARTICLE );
$lisensio_kb_sidebar  = '';

if ( Lisensio_KB_Sidebar::POSITION_NONE !== $lisensio_kb_position ) {
    $lisensio_kb_sidebar = lisensio_kb_get_template(
        'sidebar',
        array(
            'sticky'     => Lisensio_KB_Sidebar::is_sticky(),
            'toc'        => Lisensio_KB_TOC::headings( $lisensio_kb_hero_post instanceof WP_Post ? $lisensio_kb_hero_post->ID : 0 ),
            'categories' => Lisensio_KB_Sidebar::categories(),
            'tags'       => Lisensio_KB_Sidebar::tags( $lisensio_kb_hero_post instanceof WP_Post ? $lisensio_kb_hero_post : null ),
            'current'    => Lisensio_KB_Sidebar::current_category( $lisensio_kb_hero_post instanceof WP_Post ? $lisensio_kb_hero_post : null ),
        ),
        false
    );
}

/*
 * An empty sidebar is no sidebar. The template returns nothing when all three
 * of its sections are empty, and in that case the article runs full width
 * exactly as it does when the position setting is `none`.
 */
$lisensio_kb_has_sidebar = '' !== trim( $lisensio_kb_sidebar );
$lisensio_kb_modifier    = $lisensio_kb_has_sidebar ? Lisensio_KB_Sidebar::modifier_class( $lisensio_kb_position ) : '';
?>

<div class="lisensio-kb lisensio-kb--single<?php echo '' === $lisensio_kb_modifier ? '' : ' ' . esc_attr( $lisensio_kb_modifier ); ?>">
    <?php if ( $lisensio_kb_has_sidebar ) : ?>
        <div class="lisensio-kb__layout">
            <div class="lisensio-kb__main">
                <?php echo $lisensio_kb_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The buffer is this template's own markup, escaped where it was built. ?>
            </div>

            <?php echo $lisensio_kb_sidebar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside templates/sidebar.php. ?>
        </div>
    <?php else : ?>
        <?php echo $lisensio_kb_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The buffer is this template's own markup, escaped where it was built. ?>
    <?php endif; ?>
</div>
<?php
get_footer();
