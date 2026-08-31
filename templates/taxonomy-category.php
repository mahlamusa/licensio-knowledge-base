<?php
/**
 * Category archive.
 *
 * A category's own sub-categories followed by the articles filed directly
 * under it. Override from a theme by copying this file to
 * `lisensio-kb/taxonomy-category.php`.
 *
 * The heading and breadcrumb trail come from the shared hero, which carries
 * the term description as its standfirst when the term has one.
 *
 * @package Lisensio_KB
 * @since   1.0.0 Rendered the heading and breadcrumbs through `templates/hero.php`.
 */

defined( 'ABSPATH' ) || exit;

get_header();

$lisensio_kb_category = get_queried_object();
$lisensio_kb_children = $lisensio_kb_category instanceof WP_Term ? Lisensio_KB_Templates::child_categories( $lisensio_kb_category->term_id ) : array();
?>
<div class="lisensio-kb lisensio-kb--category">
    <?php
    lisensio_kb_get_template(
        'hero',
        array(
            'title'    => single_term_title( '', false ),
            'subtitle' => $lisensio_kb_category instanceof WP_Term ? $lisensio_kb_category->description : '',
        )
    );
    ?>

    <?php if ( ! empty( $lisensio_kb_children ) ) : ?>
        <section class="lisensio-kb__section">
            <h2 class="lisensio-kb__section-title"><?php esc_html_e( 'Sub-categories', 'lisensio-knowledge-base' ); ?></h2>

            <div class="lisensio-kb__grid">
                <?php foreach ( $lisensio_kb_children as $lisensio_kb_child ) : ?>
                    <?php
                    lisensio_kb_get_template(
                        'category-card',
                        array(
                            'term'          => $lisensio_kb_child,
                            'show_children' => false,
                        )
                    );
                    ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ( have_posts() ) : ?>
        <section class="lisensio-kb__section">
            <h2 class="lisensio-kb__section-title"><?php esc_html_e( 'Articles', 'lisensio-knowledge-base' ); ?></h2>

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
    <?php elseif ( empty( $lisensio_kb_children ) ) : ?>
        <?php
        lisensio_kb_get_template(
            'no-results',
            array(
                'title'       => __( 'No articles in this category yet', 'lisensio-knowledge-base' ),
                'description' => __( 'Try another category, or search the knowledge base.', 'lisensio-knowledge-base' ),
            )
        );
        ?>
    <?php endif; ?>
</div>
<?php
get_footer();
