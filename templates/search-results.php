<?php
/**
 * Search results.
 *
 * Answers a search scoped to the article post type. Override from a theme by
 * copying this file to `lisensio-kb/search-results.php`.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

get_header();

$lisensio_kb_query = get_search_query();
?>
<div class="lisensio-kb lisensio-kb--search">
    <?php lisensio_kb_get_template( 'breadcrumbs', array( 'items' => Lisensio_KB_Templates::breadcrumb_items() ) ); ?>

    <header class="lisensio-kb__header">
        <h1 class="lisensio-kb__title">
            <?php
            printf(
                /* translators: %s: the search term. */
                esc_html__( 'Search results for “%s”', 'lisensio-knowledge-base' ),
                esc_html( $lisensio_kb_query )
            );
            ?>
        </h1>

        <form role="search" method="get" class="lisensio-kb__search" action="<?php echo esc_url( home_url( '/' ) ); ?>">
            <label class="lisensio-kb__sr-only" for="lisensio-kb-search-field">
                <?php esc_html_e( 'Search the knowledge base', 'lisensio-knowledge-base' ); ?>
            </label>
            <input
                type="search"
                id="lisensio-kb-search-field"
                class="lisensio-kb__search-field"
                name="s"
                value="<?php echo esc_attr( $lisensio_kb_query ); ?>"
                placeholder="<?php esc_attr_e( 'Search the knowledge base', 'lisensio-knowledge-base' ); ?>"
            />
            <input type="hidden" name="post_type" value="<?php echo esc_attr( Lisensio_KB_Post_Types::POST_TYPE ); ?>" />
            <button type="submit" class="lisensio-kb__button"><?php esc_html_e( 'Search', 'lisensio-knowledge-base' ); ?></button>
        </form>
    </header>

    <?php if ( have_posts() ) : ?>
        <div class="lisensio-kb__list">
            <?php
            while ( have_posts() ) :
                the_post();

                lisensio_kb_get_template( 'article-card', array( 'post' => get_post() ) );
            endwhile;
            ?>
        </div>

        <?php the_posts_pagination( array( 'class' => 'lisensio-kb__pagination' ) ); ?>
    <?php else : ?>
        <?php
        lisensio_kb_get_template(
            'no-results',
            array(
                'title'       => __( 'No articles matched that search', 'lisensio-knowledge-base' ),
                'description' => __( 'Try a different wording, or browse the categories.', 'lisensio-knowledge-base' ),
            )
        );
        ?>
    <?php endif; ?>
</div>
<?php
get_footer();
