<?php
/**
 * Knowledge base archive body.
 *
 * The content half of the knowledge base archive: the page header, the divided
 * category grid and the sidebar. The classic template wraps it in the theme's
 * header and footer; the `[lisensio_kb_view view="archive"]` shortcode renders
 * it inside a block template, so both template paradigms serve the same markup
 * and the same stylesheet. Override from a theme by copying this file to
 * `lisensio-kb/archive-body.php`.
 *
 * The archive does not render the main loop. Its job is to point at the
 * categories; the articles themselves are reached through the cards, and each
 * category archive lists its own.
 *
 * The one thing the category grid cannot reach is an article filed under no
 * category, which would otherwise be published and linked from nowhere. Those
 * get a list of their own below the grid, which renders nothing at all when
 * every article is filed — so a well-organised site never sees it.
 *
 * The grid follows the sidebar: two columns of cards beside one, three
 * without. The `archive_columns` setting overrides that pair when it holds an
 * explicit count.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

$lisensio_kb_categories    = Lisensio_KB_Templates::top_level_categories();
$lisensio_kb_uncategorised = Lisensio_KB_Templates::uncategorised_articles();

$lisensio_kb_sidebar = Lisensio_KB_Sidebar::render( Lisensio_KB_Sidebar::CONTEXT_ARCHIVE );

$lisensio_kb_has_sidebar = '' !== trim( $lisensio_kb_sidebar );
$lisensio_kb_modifier    = $lisensio_kb_has_sidebar ? Lisensio_KB_Sidebar::modifier_class( Lisensio_KB_Sidebar::position( Lisensio_KB_Sidebar::CONTEXT_ARCHIVE ) ) : '';

$lisensio_kb_columns = Lisensio_KB_Templates::archive_columns();

if ( $lisensio_kb_columns < 1 ) {
    $lisensio_kb_columns = $lisensio_kb_has_sidebar ? 2 : 3;
}
?>
<div class="lisensio-kb lisensio-kb--archive<?php echo '' === $lisensio_kb_modifier ? '' : ' ' . esc_attr( $lisensio_kb_modifier ); ?>">
    <?php
    lisensio_kb_get_template(
        'page-header',
        array(
            'title' => Lisensio_KB_Templates::archive_title(),
        )
    );
    ?>

    <?php if ( $lisensio_kb_has_sidebar ) : ?>
        <div class="lisensio-kb__layout">
        <div class="lisensio-kb__main">
    <?php endif; ?>

    <?php if ( ! empty( $lisensio_kb_categories ) ) : ?>
        <div class="lisensio-kb__grid lisensio-kb__grid--divided lisensio-kb__grid--cols-<?php echo esc_attr( (string) $lisensio_kb_columns ); ?>">
            <?php foreach ( $lisensio_kb_categories as $lisensio_kb_category ) : ?>
                <?php
                lisensio_kb_get_template(
                    'category-card',
                    array(
                        'term'       => $lisensio_kb_category,
                        'show_icon'  => false,
                        'show_count' => true,
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
