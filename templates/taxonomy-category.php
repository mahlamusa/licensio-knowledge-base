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
 * The sidebar carries no table of contents here: a category view has no
 * article body to collect headings from, and the template skips a section it
 * cannot fill rather than printing an empty one.
 *
 * @package Lisensio_KB
 * @since   1.0.0 Rendered the heading and breadcrumbs through `templates/hero.php`.
 * @since   1.0.0 Added the sidebar.
 */

defined( 'ABSPATH' ) || exit;

get_header();

$lisensio_kb_category = get_queried_object();
$lisensio_kb_children = $lisensio_kb_category instanceof WP_Term ? Lisensio_KB_Templates::child_categories( $lisensio_kb_category->term_id ) : array();

$lisensio_kb_position = Lisensio_KB_Sidebar::position( Lisensio_KB_Sidebar::CONTEXT_CATEGORY );
$lisensio_kb_sidebar  = '';

if ( Lisensio_KB_Sidebar::POSITION_NONE !== $lisensio_kb_position ) {
    $lisensio_kb_sidebar = lisensio_kb_get_template(
        'sidebar',
        array(
            'sticky'     => Lisensio_KB_Sidebar::is_sticky(),
            'categories' => Lisensio_KB_Sidebar::categories(),
            'tags'       => Lisensio_KB_Sidebar::tags(),
            'current'    => $lisensio_kb_category instanceof WP_Term ? (int) $lisensio_kb_category->term_id : 0,
        ),
        false
    );
}

$lisensio_kb_has_sidebar = '' !== trim( $lisensio_kb_sidebar );
$lisensio_kb_modifier    = $lisensio_kb_has_sidebar ? Lisensio_KB_Sidebar::modifier_class( $lisensio_kb_position ) : '';
?>
<div class="lisensio-kb lisensio-kb--bleed">
    <?php
    lisensio_kb_get_template(
        'hero',
        array(
            'title'    => single_term_title( '', false ),
            'subtitle' => $lisensio_kb_category instanceof WP_Term ? $lisensio_kb_category->description : '',
            'search'   => true,
        )
    );
    ?>
</div>

<div class="lisensio-kb lisensio-kb--category<?php echo '' === $lisensio_kb_modifier ? '' : ' ' . esc_attr( $lisensio_kb_modifier ); ?>">
    <?php if ( $lisensio_kb_has_sidebar ) : ?>
        <div class="lisensio-kb__layout">
        <div class="lisensio-kb__main">
    <?php endif; ?>

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

    <?php if ( $lisensio_kb_has_sidebar ) : ?>
        </div>

        <?php echo $lisensio_kb_sidebar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside templates/sidebar.php. ?>
        </div>
    <?php endif; ?>
</div>
<?php
get_footer();
