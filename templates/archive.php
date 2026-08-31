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
 * @package Lisensio_KB
 * @since   1.0.0 Rendered the hero through `templates/hero.php`, replaced the article loop with the uncategorised list and added `lisensio_kb_after_archive`.
 */

defined( 'ABSPATH' ) || exit;

get_header();

$lisensio_kb_categories    = Lisensio_KB_Templates::top_level_categories();
$lisensio_kb_uncategorised = Lisensio_KB_Templates::uncategorised_articles();
?>
<div class="lisensio-kb lisensio-kb--bleed">
    <?php
    lisensio_kb_get_template(
        'hero',
        array( 'title' => post_type_archive_title( '', false ) )
    );
    ?>
</div>

<div class="lisensio-kb lisensio-kb--archive">

    <form role="search" method="get" class="lisensio-kb__search" action="<?php echo esc_url( home_url( '/' ) ); ?>">
        <label class="lisensio-kb__sr-only" for="lisensio-kb-search-field">
            <?php esc_html_e( 'Search the knowledge base', 'lisensio-knowledge-base' ); ?>
        </label>
        <input
            type="search"
            id="lisensio-kb-search-field"
            class="lisensio-kb__search-field"
            name="s"
            value="<?php echo esc_attr( get_search_query() ); ?>"
            placeholder="<?php esc_attr_e( 'Search the knowledge base', 'lisensio-knowledge-base' ); ?>"
        />
        <input type="hidden" name="post_type" value="<?php echo esc_attr( Lisensio_KB_Post_Types::POST_TYPE ); ?>" />
        <button type="submit" class="lisensio-kb__button"><?php esc_html_e( 'Search', 'lisensio-knowledge-base' ); ?></button>
    </form>

    <?php if ( ! empty( $lisensio_kb_categories ) ) : ?>
        <div class="lisensio-kb__grid lisensio-kb__grid--cols-3">
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

</div>

<div class="lisensio-kb lisensio-kb--bleed">
    <?php
    /**
     * Fires at the end of the knowledge base archive, inside its wrapper.
     *
     * The seam a theme attaches its own closing section to — a call to action
     * band, a support link, a contact form. The plugin ships none of that
     * itself: the copy would belong to one site rather than to every install.
     *
     * @since 1.0.0
     */
    do_action( 'lisensio_kb_after_archive' );
    ?>
</div>
<?php
get_footer();
