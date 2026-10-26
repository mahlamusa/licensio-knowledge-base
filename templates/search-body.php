<?php
/**
 * Search results body.
 *
 * The content half of a knowledge base search: the hero with its breadcrumb
 * trail and search field, then the matching articles. The classic template
 * wraps it in the theme's header and footer; the `licensio-kb/view` block
 * renders it inside a block template, so both template paradigms serve the same
 * markup and the same stylesheet. Override from a theme by copying this file to
 * `licensio-kb/search-body.php`.
 *
 * The heading, breadcrumb trail and search field come from the shared hero.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

$licensio_kb_query   = get_search_query();
$licensio_kb_heading = sprintf(
    /* translators: %s: the search term. */
    __( 'Search results for “%s”', 'licensio-knowledge-base' ),
    $licensio_kb_query
);
?>
<div class="licensio-kb licensio-kb--bleed">
    <?php
    licensio_kb_get_template(
        'hero',
        array(
            'title'  => $licensio_kb_heading,
            'search' => true,
        )
    );
    ?>
</div>

<div class="licensio-kb licensio-kb--search">

    <?php if ( have_posts() ) : ?>
        <div class="licensio-kb__list">
            <?php
            while ( have_posts() ) :
                the_post();

                licensio_kb_get_template( 'article-card', array( 'post' => get_post() ) );
            endwhile;
            ?>
        </div>

        <?php the_posts_pagination( array( 'class' => 'licensio-kb__pagination' ) ); ?>
    <?php else : ?>
        <?php
        licensio_kb_get_template(
            'no-results',
            array(
                'title'       => __( 'No articles matched that search', 'licensio-knowledge-base' ),
                'description' => __( 'Try a different wording, or browse the categories.', 'licensio-knowledge-base' ),
            )
        );
        ?>
    <?php endif; ?>
</div>
