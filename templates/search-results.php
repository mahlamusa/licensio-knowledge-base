<?php
/**
 * Search results.
 *
 * Answers a search scoped to the article post type. Override from a theme by
 * copying this file to `lisensio-kb/search-results.php`.
 *
 * The heading and breadcrumb trail come from the shared hero. The search form
 * follows it rather than sitting inside it, because a theme override of the
 * hero renders its own markup and nothing the plugin owns can be nested there.
 *
 * @package Lisensio_KB
 * @since   1.0.0 Rendered the heading and breadcrumbs through `templates/hero.php`.
 */

defined( 'ABSPATH' ) || exit;

get_header();

$lisensio_kb_query   = get_search_query();
$lisensio_kb_heading = sprintf(
    /* translators: %s: the search term. */
    __( 'Search results for “%s”', 'lisensio-knowledge-base' ),
    $lisensio_kb_query
);
?>
<div class="lisensio-kb lisensio-kb--bleed">
    <?php
    lisensio_kb_get_template(
        'hero',
        array(
            'title'  => $lisensio_kb_heading,
            'search' => true,
        )
    );
    ?>
</div>

<div class="lisensio-kb lisensio-kb--search">


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
