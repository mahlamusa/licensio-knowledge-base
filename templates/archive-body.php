<?php
/**
 * Knowledge base archive body.
 *
 * The content half of the knowledge base archive: the page header, the divided
 * category grid and the sidebar. The classic template wraps it in the theme's
 * header and footer; the `licensio-kb/view` block renders it inside a block
 * template, so both template paradigms serve the same markup and the same
 * stylesheet. Override from a theme by copying this file to
 * `licensio-kb/archive-body.php`.
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
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

$licensio_kb_categories    = Licensio_KB_Templates::top_level_categories();
$licensio_kb_uncategorised = Licensio_KB_Templates::uncategorised_articles();

$licensio_kb_sidebar = Licensio_KB_Sidebar::render( Licensio_KB_Sidebar::CONTEXT_ARCHIVE );

$licensio_kb_has_sidebar = '' !== trim( $licensio_kb_sidebar );
$licensio_kb_modifier    = $licensio_kb_has_sidebar ? Licensio_KB_Sidebar::modifier_class( Licensio_KB_Sidebar::position( Licensio_KB_Sidebar::CONTEXT_ARCHIVE ) ) : '';

$licensio_kb_columns = Licensio_KB_Templates::archive_columns();

if ( $licensio_kb_columns < 1 ) {
    $licensio_kb_columns = $licensio_kb_has_sidebar ? 2 : 3;
}
?>
<div class="licensio-kb licensio-kb--archive<?php echo '' === $licensio_kb_modifier ? '' : ' ' . esc_attr( $licensio_kb_modifier ); ?>">
    <?php
    licensio_kb_get_template(
        'page-header',
        array(
            'title' => Licensio_KB_Templates::archive_title(),
        )
    );
    ?>

    <?php if ( $licensio_kb_has_sidebar ) : ?>
        <div class="licensio-kb__layout">
        <div class="licensio-kb__main">
    <?php endif; ?>

    <?php if ( ! empty( $licensio_kb_categories ) ) : ?>
        <div class="licensio-kb__grid licensio-kb__grid--divided licensio-kb__grid--cols-<?php echo esc_attr( (string) $licensio_kb_columns ); ?>">
            <?php foreach ( $licensio_kb_categories as $licensio_kb_category ) : ?>
                <?php
                licensio_kb_get_template(
                    'category-card',
                    array(
                        'term'       => $licensio_kb_category,
                        'show_icon'  => false,
                        'show_count' => true,
                    )
                );
                ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ( ! empty( $licensio_kb_uncategorised ) ) : ?>
        <section class="licensio-kb__section">
            <h2 class="licensio-kb__section-title"><?php esc_html_e( 'Uncategorised', 'licensio-knowledge-base' ); ?></h2>

            <div class="licensio-kb__list">
                <?php foreach ( $licensio_kb_uncategorised as $licensio_kb_article ) : ?>
                    <?php licensio_kb_get_template( 'article-card', array( 'post' => $licensio_kb_article ) ); ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ( empty( $licensio_kb_categories ) && empty( $licensio_kb_uncategorised ) ) : ?>
        <?php
        licensio_kb_get_template(
            'no-results',
            array(
                'title'       => __( 'The knowledge base is empty', 'licensio-knowledge-base' ),
                'description' => __( 'No articles have been published yet.', 'licensio-knowledge-base' ),
            )
        );
        ?>
    <?php endif; ?>

    <?php if ( $licensio_kb_has_sidebar ) : ?>
        </div>

        <?php echo $licensio_kb_sidebar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside templates/sidebar.php. ?>
        </div>
    <?php endif; ?>

</div>
