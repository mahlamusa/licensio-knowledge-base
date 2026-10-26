<?php
/**
 * Category archive body.
 *
 * The content half of a category archive: the page header, the divided
 * sub-category grid and the article list. The classic template wraps it in the
 * theme's header and footer; the `licensio-kb/view` block renders it inside a
 * block template, so both template paradigms serve the same markup and the same
 * stylesheet. Override from a theme by copying this file to
 * `licensio-kb/category-body.php`.
 *
 * The grid follows the sidebar, the way the knowledge base archive does: two
 * columns of sub-category cards beside one, three without — and with no
 * sidebar the article list widens into the same three columns. Setting the
 * category sidebar to "No sidebar" is what makes that happen.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

$licensio_kb_category = get_queried_object();
$licensio_kb_children = $licensio_kb_category instanceof WP_Term ? Licensio_KB_Templates::child_categories( $licensio_kb_category->term_id ) : array();

$licensio_kb_sidebar = Licensio_KB_Sidebar::render( Licensio_KB_Sidebar::CONTEXT_CATEGORY );

$licensio_kb_has_sidebar = '' !== trim( $licensio_kb_sidebar );
$licensio_kb_modifier    = $licensio_kb_has_sidebar ? Licensio_KB_Sidebar::modifier_class( Licensio_KB_Sidebar::position( Licensio_KB_Sidebar::CONTEXT_CATEGORY ) ) : '';

$licensio_kb_columns = $licensio_kb_has_sidebar ? 2 : 3;
?>
<div class="licensio-kb licensio-kb--category<?php echo '' === $licensio_kb_modifier ? '' : ' ' . esc_attr( $licensio_kb_modifier ); ?>">
    <?php
    licensio_kb_get_template(
        'page-header',
        array(
            'title'    => single_term_title( '', false ),
            'subtitle' => $licensio_kb_category instanceof WP_Term ? $licensio_kb_category->description : '',
        )
    );
    ?>

    <?php if ( $licensio_kb_has_sidebar ) : ?>
        <div class="licensio-kb__layout">
        <div class="licensio-kb__main">
    <?php endif; ?>

    <?php if ( ! empty( $licensio_kb_children ) ) : ?>
        <section class="licensio-kb__section">
            <h2 class="licensio-kb__section-title"><?php esc_html_e( 'Sub-categories', 'licensio-knowledge-base' ); ?></h2>

            <div class="licensio-kb__grid licensio-kb__grid--divided licensio-kb__grid--cols-<?php echo esc_attr( (string) $licensio_kb_columns ); ?>">
                <?php foreach ( $licensio_kb_children as $licensio_kb_child ) : ?>
                    <?php
                    licensio_kb_get_template(
                        'category-card',
                        array(
                            'term'          => $licensio_kb_child,
                            'show_children' => false,
                        )
                    );
                    ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ( have_posts() ) : ?>
        <section class="licensio-kb__section">
            <h2 class="licensio-kb__section-title"><?php esc_html_e( 'Articles', 'licensio-knowledge-base' ); ?></h2>

            <div class="licensio-kb__list<?php echo $licensio_kb_has_sidebar ? '' : ' licensio-kb__list--cols-' . esc_attr( (string) $licensio_kb_columns ); ?>">
                <?php
                while ( have_posts() ) :
                    the_post();

                    licensio_kb_get_template( 'article-card', array( 'post' => get_post() ) );
                endwhile;
                ?>
            </div>

            <?php the_posts_pagination( array( 'class' => 'licensio-kb__pagination' ) ); ?>
        </section>
    <?php elseif ( empty( $licensio_kb_children ) ) : ?>
        <?php
        licensio_kb_get_template(
            'no-results',
            array(
                'title'       => __( 'No articles in this category yet', 'licensio-knowledge-base' ),
                'description' => __( 'Try another category, or search the knowledge base.', 'licensio-knowledge-base' ),
            )
        );
        ?>
    <?php endif; ?>

    <?php if ( $licensio_kb_has_sidebar ) : ?>
        </div>

        <?php echo $licensio_kb_sidebar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside templates/sidebar.php. ?>
        </div>
    <?php endif; ?>
</div>
