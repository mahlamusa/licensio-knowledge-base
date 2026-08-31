<?php
/**
 * Tag archive.
 *
 * Every article carrying one tag. Override from a theme by copying this file
 * to `lisensio-kb/taxonomy-tag.php`.
 *
 * The heading and breadcrumb trail come from the shared hero, which carries
 * the term description as its standfirst when the term has one.
 *
 * @package Lisensio_KB
 * @since   1.0.0 Rendered the heading and breadcrumbs through `templates/hero.php`.
 */

defined( 'ABSPATH' ) || exit;

get_header();

$lisensio_kb_tag = get_queried_object();
?>
<div class="lisensio-kb lisensio-kb--bleed">
    <?php
    lisensio_kb_get_template(
        'hero',
        array(
            'title'    => single_term_title( '', false ),
            'subtitle' => $lisensio_kb_tag instanceof WP_Term ? $lisensio_kb_tag->description : '',
            'search'   => true,
        )
    );
    ?>
</div>

<div class="lisensio-kb lisensio-kb--tag">

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
                'title'       => __( 'No articles carry this tag yet', 'lisensio-knowledge-base' ),
                'description' => __( 'Try another tag, or search the knowledge base.', 'lisensio-knowledge-base' ),
            )
        );
        ?>
    <?php endif; ?>
</div>
<?php
get_footer();
