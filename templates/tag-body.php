<?php
/**
 * Tag archive body.
 *
 * The content half of a tag archive: the hero with its breadcrumb trail,
 * standfirst and search field, then every article carrying the tag. The classic
 * template wraps it in the theme's header and footer; the `licensio-kb/view`
 * block renders it inside a block template, so both template paradigms serve
 * the same markup and the same stylesheet. Override from a theme by copying
 * this file to `licensio-kb/tag-body.php`.
 *
 * The heading and breadcrumb trail come from the shared hero, which carries
 * the term description as its standfirst when the term has one.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

$licensio_kb_tag = get_queried_object();
?>
<div class="licensio-kb licensio-kb--bleed">
    <?php
    licensio_kb_get_template(
        'hero',
        array(
            'title'    => single_term_title( '', false ),
            'subtitle' => $licensio_kb_tag instanceof WP_Term ? $licensio_kb_tag->description : '',
            'search'   => true,
        )
    );
    ?>
</div>

<div class="licensio-kb licensio-kb--tag">

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
                'title'       => __( 'No articles carry this tag yet', 'licensio-knowledge-base' ),
                'description' => __( 'Try another tag, or search the knowledge base.', 'licensio-knowledge-base' ),
            )
        );
        ?>
    <?php endif; ?>
</div>
