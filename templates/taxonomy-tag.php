<?php
/**
 * Tag archive.
 *
 * Every article carrying one tag. Override from a theme by copying this file
 * to `lisensio-kb/taxonomy-tag.php`.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

get_header();

$lisensio_kb_tag = get_queried_object();
?>
<div class="lisensio-kb lisensio-kb--tag">
    <?php lisensio_kb_get_template( 'breadcrumbs', array( 'items' => Lisensio_KB_Templates::breadcrumb_items() ) ); ?>

    <header class="lisensio-kb__header">
        <h1 class="lisensio-kb__title">
            <?php
            printf(
                /* translators: %s: the tag name. */
                esc_html__( 'Articles tagged %s', 'lisensio-knowledge-base' ),
                esc_html( single_term_title( '', false ) )
            );
            ?>
        </h1>

        <?php if ( $lisensio_kb_tag instanceof WP_Term && '' !== $lisensio_kb_tag->description ) : ?>
            <p class="lisensio-kb__description"><?php echo esc_html( $lisensio_kb_tag->description ); ?></p>
        <?php endif; ?>
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
                'title'       => __( 'No articles carry this tag yet', 'lisensio-knowledge-base' ),
                'description' => __( 'Try another tag, or search the knowledge base.', 'lisensio-knowledge-base' ),
            )
        );
        ?>
    <?php endif; ?>
</div>
<?php
get_footer();
