<?php
/**
 * Knowledge base archive.
 *
 * The shared hero, the search form and a three column grid of category cards,
 * each listing the articles filed beneath it. Override from a theme by copying
 * this file to `lisensio-kb/archive.php`.
 *
 * The archive does not render the main loop. Its job is to point at the
 * categories; the articles themselves are reached through the cards, and each
 * category archive lists its own. Nothing in the plugin listens for a per
 * article hook here, so no contract is lost by leaving the loop unrendered.
 *
 * The one thing the category grid cannot reach is an article filed under no
 * category, which would otherwise be published and linked from nowhere. Those
 * get a list of their own below the grid, which renders nothing at all when
 * every article is filed — so a well-organised site never sees it.
 *
 * The sidebar here carries tags only. It has no table of contents, because
 * there is no article to collect headings from, and no category list, because
 * the main column IS the category list: repeating those same eight links in a
 * narrow column beside the cards that already name them is a navigation aid
 * that navigates nowhere. That is the same reasoning that keeps an article's
 * own categories out of its sidebar. Tags are the one thing this view surfaces
 * nowhere else.
 *
 * @package Lisensio_KB
 * @since   1.0.0 Rendered the hero through `templates/hero.php`, replaced the article loop with the uncategorised list and added `lisensio_kb_after_archive`.
 * @since   1.0.0 Added the sidebar.
 */

defined( 'ABSPATH' ) || exit;

get_header();

$lisensio_kb_categories    = Lisensio_KB_Templates::top_level_categories();
$lisensio_kb_uncategorised = Lisensio_KB_Templates::uncategorised_articles();

$lisensio_kb_position = Lisensio_KB_Sidebar::position( Lisensio_KB_Sidebar::CONTEXT_ARCHIVE );
$lisensio_kb_sidebar  = '';

if ( Lisensio_KB_Sidebar::POSITION_NONE !== $lisensio_kb_position ) {
    $lisensio_kb_sidebar = lisensio_kb_get_template(
        'sidebar',
        array(
            'sticky' => Lisensio_KB_Sidebar::is_sticky(),
            'tags'   => Lisensio_KB_Sidebar::tags(),
        ),
        false
    );
}

$lisensio_kb_has_sidebar = '' !== trim( $lisensio_kb_sidebar );
$lisensio_kb_modifier    = $lisensio_kb_has_sidebar ? Lisensio_KB_Sidebar::modifier_class( $lisensio_kb_position ) : '';
?>
<div class="lisensio-kb lisensio-kb--bleed">
    <?php
    lisensio_kb_get_template(
        'hero',
        array(
            'title'  => post_type_archive_title( '', false ),
            'search' => true,
        )
    );
    ?>
</div>

<div class="lisensio-kb lisensio-kb--archive<?php echo '' === $lisensio_kb_modifier ? '' : ' ' . esc_attr( $lisensio_kb_modifier ); ?>">
    <?php if ( $lisensio_kb_has_sidebar ) : ?>
        <div class="lisensio-kb__layout">
        <div class="lisensio-kb__main">
    <?php endif; ?>

    <?php if ( ! empty( $lisensio_kb_categories ) ) : ?>
        <div class="lisensio-kb__grid lisensio-kb__grid--cols-<?php echo esc_attr( (string) Lisensio_KB_Templates::archive_columns() ); ?>">
            <?php foreach ( $lisensio_kb_categories as $lisensio_kb_category ) : ?>
                <?php
                lisensio_kb_get_template(
                    'category-card',
                    array(
                        'term'       => $lisensio_kb_category,
                        'show_icon'  => true,
                        'show_count' => false,
                    )
                );
                ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ( ! empty( $lisensio_kb_uncategorised ) ) : ?>
        <section class="lisensio-kb__section">
            <h2 class="lisensio-kb__section-title"><?php esc_html_e( 'Uncategorised', 'lisensio-knowledge-base' ); ?></h2>

            <div class="lisensio-kb__list">
                <?php foreach ( $lisensio_kb_uncategorised as $lisensio_kb_article ) : ?>
                    <?php lisensio_kb_get_template( 'article-card', array( 'post' => $lisensio_kb_article ) ); ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ( empty( $lisensio_kb_categories ) && empty( $lisensio_kb_uncategorised ) ) : ?>
        <?php
        lisensio_kb_get_template(
            'no-results',
            array(
                'title'       => __( 'The knowledge base is empty', 'lisensio-knowledge-base' ),
                'description' => __( 'No articles have been published yet.', 'lisensio-knowledge-base' ),
            )
        );
        ?>
    <?php endif; ?>

    <?php if ( $lisensio_kb_has_sidebar ) : ?>
        </div>

        <?php echo $lisensio_kb_sidebar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside templates/sidebar.php. ?>
        </div>
    <?php endif; ?>

</div>

<?php
/**
 * Fires at the end of the knowledge base archive.
 *
 * The seam a theme attaches its own closing section to — a call to action
 * band, a support link, a contact form. The plugin ships none of that itself:
 * the copy would belong to one site rather than to every install.
 *
 * Fired outside every `.lisensio-kb` wrapper, deliberately. What a theme hangs
 * here is the theme's own markup, and inside that wrapper it would inherit the
 * plugin's link colour, focus ring and type — which is not a hypothetical:
 * a theme button rendered inside it came out with `color: var(--lisensio-kb-brand)`
 * over a brand-coloured background, green on green and unreadable.
 *
 * @since 1.0.0
 */
do_action( 'lisensio_kb_after_archive' );
?>
<?php
get_footer();
