<?php
/**
 * Knowledge base home.
 *
 * The article post type archive: the top-level categories with their article
 * counts, then the articles from the main query. Override from a theme by
 * copying this file to `lisensio-kb/archive.php`.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

get_header();

$lisensio_kb_categories = Lisensio_KB_Templates::top_level_categories();
?>
<div class="lisensio-kb lisensio-kb--archive">
    <?php lisensio_kb_get_template( 'breadcrumbs', array( 'items' => Lisensio_KB_Templates::breadcrumb_items() ) ); ?>

    <header class="lisensio-kb__header">
        <h1 class="lisensio-kb__title"><?php echo esc_html( post_type_archive_title( '', false ) ); ?></h1>

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
    </header>

    <?php if ( ! empty( $lisensio_kb_categories ) ) : ?>
        <div class="lisensio-kb__grid">
            <?php foreach ( $lisensio_kb_categories as $lisensio_kb_category ) : ?>
                <?php lisensio_kb_get_template( 'category-card', array( 'term' => $lisensio_kb_category ) ); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ( have_posts() ) : ?>
        <section class="lisensio-kb__section">
            <h2 class="lisensio-kb__section-title"><?php esc_html_e( 'Latest articles', 'lisensio-knowledge-base' ); ?></h2>

            <div class="lisensio-kb__list">
                <?php
                while ( have_posts() ) :
                    the_post();

                    lisensio_kb_get_template( 'article-card', array( 'post' => get_post() ) );
                endwhile;
                ?>
            </div>

            <?php the_posts_pagination( array( 'class' => 'lisensio-kb__pagination' ) ); ?>
        </section>
    <?php elseif ( empty( $lisensio_kb_categories ) ) : ?>
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
<?php
get_footer();
