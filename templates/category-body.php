<?php
/**
 * Category archive body.
 *
 * The content half of a category archive: the page header, the divided
 * sub-category grid and the article list. The classic template wraps it in the
 * theme's header and footer; the `[lisensio_kb_view view="category"]`
 * shortcode renders it inside a block template, so both template paradigms
 * serve the same markup and the same stylesheet. Override from a theme by
 * copying this file to `lisensio-kb/category-body.php`.
 *
 * The grid follows the sidebar, the way the knowledge base archive does: two
 * columns of sub-category cards beside one, three without — and with no
 * sidebar the article list widens into the same three columns. Setting the
 * category sidebar to "No sidebar" is what makes that happen.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

$lisensio_kb_category = get_queried_object();
$lisensio_kb_children = $lisensio_kb_category instanceof WP_Term ? Lisensio_KB_Templates::child_categories( $lisensio_kb_category->term_id ) : array();

$lisensio_kb_sidebar = Lisensio_KB_Sidebar::render( Lisensio_KB_Sidebar::CONTEXT_CATEGORY );

$lisensio_kb_has_sidebar = '' !== trim( $lisensio_kb_sidebar );
$lisensio_kb_modifier    = $lisensio_kb_has_sidebar ? Lisensio_KB_Sidebar::modifier_class( Lisensio_KB_Sidebar::position( Lisensio_KB_Sidebar::CONTEXT_CATEGORY ) ) : '';

$lisensio_kb_columns = $lisensio_kb_has_sidebar ? 2 : 3;
?>
<div class="lisensio-kb lisensio-kb--category<?php echo '' === $lisensio_kb_modifier ? '' : ' ' . esc_attr( $lisensio_kb_modifier ); ?>">
    <?php
    lisensio_kb_get_template(
        'page-header',
        array(
            'title'    => single_term_title( '', false ),
            'subtitle' => $lisensio_kb_category instanceof WP_Term ? $lisensio_kb_category->description : '',
        )
    );
    ?>

    <?php if ( $lisensio_kb_has_sidebar ) : ?>
        <div class="lisensio-kb__layout">
        <div class="lisensio-kb__main">
    <?php endif; ?>

    <?php if ( ! empty( $lisensio_kb_children ) ) : ?>
        <section class="lisensio-kb__section">
            <h2 class="lisensio-kb__section-title"><?php esc_html_e( 'Sub-categories', 'lisensio-knowledge-base' ); ?></h2>

            <div class="lisensio-kb__grid lisensio-kb__grid--divided lisensio-kb__grid--cols-<?php echo esc_attr( (string) $lisensio_kb_columns ); ?>">
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

            <div class="lisensio-kb__list<?php echo $lisensio_kb_has_sidebar ? '' : ' lisensio-kb__list--cols-' . esc_attr( (string) $lisensio_kb_columns ); ?>">
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

    <?php if ( $lisensio_kb_has_sidebar ) : ?>
        </div>

        <?php echo $lisensio_kb_sidebar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside templates/sidebar.php. ?>
        </div>
    <?php endif; ?>
</div>
