<?php
/**
 * Single article body.
 *
 * The content half of a single article: the hero with its breadcrumb trail and
 * search field, the article itself and the sidebar with the table of contents.
 * The classic template wraps it in the theme's header and footer; the
 * `[licensio_kb_view view="single"]` shortcode renders it inside a block
 * template, so both template paradigms serve the same markup and the same
 * stylesheet. Override from a theme by copying this file to
 * `licensio-kb/single-body.php`.
 *
 * Fires the three frontend hooks so feedback, analytics and gating attach
 * without touching this file.
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
 * it. It is also why a block template renders this whole body through one
 * shortcode rather than placing a sidebar beside a post content block: the
 * shortcode would run before the block, so the table of contents would be
 * empty.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/*
 * The hero is resolved before the loop, because it renders outside the
 * `.licensio-kb` wrapper and the loop opens inside it. WordPress populates the
 * global post for a singular query before the loop runs, so this is the same
 * article the loop below is about to hand to `the_post()`.
 */
$licensio_kb_hero_post = get_post();
?>
<div class="licensio-kb licensio-kb--bleed">
    <?php
    licensio_kb_get_template(
        'hero',
        array(
            'title'  => $licensio_kb_hero_post instanceof WP_Post ? get_the_title( $licensio_kb_hero_post ) : '',
            'search' => true,
        )
    );
    ?>
</div>

<?php
ob_start();

while ( have_posts() ) :
    the_post();

    $licensio_kb_article = get_post();

    $licensio_kb_article_tags = get_the_term_list(
        $licensio_kb_article->ID,
        Licensio_KB_Post_Types::TAXONOMY_TAG,
        '',
        ', ',
        ''
    );
    ?>
    <article id="licensio-kb-article-<?php echo esc_attr( (string) $licensio_kb_article->ID ); ?>" class="licensio-kb-article">
        <header class="licensio-kb-article__header">
            <p class="licensio-kb-article__meta">
                <?php
                printf(
                    /* translators: %s: the date the article was last updated. */
                    esc_html__( 'Last updated on %s', 'licensio-knowledge-base' ),
                    esc_html( get_the_modified_date( '', $licensio_kb_article ) )
                );
                ?>
            </p>
        </header>

        <?php licensio_kb_before_article( $licensio_kb_article ); ?>

        <div class="licensio-kb-article__body">
            <?php the_content(); ?>
        </div>

        <?php licensio_kb_after_article( $licensio_kb_article ); ?>

        <footer class="licensio-kb-article__footer">
            <?php if ( is_string( $licensio_kb_article_tags ) && '' !== $licensio_kb_article_tags ) : ?>
                <p class="licensio-kb-article__tags">
                    <span class="licensio-kb-article__tags-label"><?php esc_html_e( 'Tagged:', 'licensio-knowledge-base' ); ?></span>
                    <?php echo wp_kses_post( $licensio_kb_article_tags ); ?>
                </p>
            <?php endif; ?>

            <?php licensio_kb_article_footer( $licensio_kb_article ); ?>
        </footer>
    </article>
    <?php
endwhile;

$licensio_kb_body = (string) ob_get_clean();

$licensio_kb_position = Licensio_KB_Sidebar::position( Licensio_KB_Sidebar::CONTEXT_ARTICLE );
$licensio_kb_sidebar  = '';

if ( Licensio_KB_Sidebar::POSITION_NONE !== $licensio_kb_position ) {
    $licensio_kb_sidebar = licensio_kb_get_template(
        'sidebar',
        array(
            'sticky'     => Licensio_KB_Sidebar::is_sticky(),
            'toc'        => Licensio_KB_TOC::headings( $licensio_kb_hero_post instanceof WP_Post ? $licensio_kb_hero_post->ID : 0 ),
            'categories' => Licensio_KB_Sidebar::categories(),
            'tags'       => Licensio_KB_Sidebar::tags( $licensio_kb_hero_post instanceof WP_Post ? $licensio_kb_hero_post : null ),
            'current'    => Licensio_KB_Sidebar::current_category( $licensio_kb_hero_post instanceof WP_Post ? $licensio_kb_hero_post : null ),
        ),
        false
    );
}

/*
 * An empty sidebar is no sidebar. The template returns nothing when all three
 * of its sections are empty, and in that case the article runs full width
 * exactly as it does when the position setting is `none`.
 */
$licensio_kb_has_sidebar = '' !== trim( $licensio_kb_sidebar );
$licensio_kb_modifier    = $licensio_kb_has_sidebar ? Licensio_KB_Sidebar::modifier_class( $licensio_kb_position ) : '';
?>

<div class="licensio-kb licensio-kb--single<?php echo '' === $licensio_kb_modifier ? '' : ' ' . esc_attr( $licensio_kb_modifier ); ?>">
    <?php if ( $licensio_kb_has_sidebar ) : ?>
        <div class="licensio-kb__layout">
            <div class="licensio-kb__main">
                <?php echo $licensio_kb_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The buffer is this template's own markup, escaped where it was built. ?>
            </div>

            <?php echo $licensio_kb_sidebar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside templates/sidebar.php. ?>
        </div>
    <?php else : ?>
        <?php echo $licensio_kb_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The buffer is this template's own markup, escaped where it was built. ?>
    <?php endif; ?>
</div>
