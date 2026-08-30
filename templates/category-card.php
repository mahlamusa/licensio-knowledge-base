<?php
/**
 * Category card.
 *
 * One category in a grid, with its article count and a preview of its
 * sub-categories. Override from a theme by copying this file to
 * `lisensio-kb/category-card.php`.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 *
 * @var array $args {
 *     Template arguments.
 *
 *     @type WP_Term $term          The category.
 *     @type bool    $show_children Whether to list the direct sub-categories. Default true.
 * }
 */

defined( 'ABSPATH' ) || exit;

$lisensio_kb_category = isset( $args['term'] ) ? $args['term'] : null;

if ( ! $lisensio_kb_category instanceof WP_Term ) {
    return;
}

$lisensio_kb_category_url   = Lisensio_KB_Templates::term_link( $lisensio_kb_category );
$lisensio_kb_category_count = Lisensio_KB_Templates::category_article_count( $lisensio_kb_category );
$lisensio_kb_show_children  = ! isset( $args['show_children'] ) || (bool) $args['show_children'];
$lisensio_kb_children       = $lisensio_kb_show_children ? Lisensio_KB_Templates::child_categories( $lisensio_kb_category->term_id ) : array();
?>
<article class="lisensio-kb-card lisensio-kb-card--category">
    <h3 class="lisensio-kb-card__title">
        <a class="lisensio-kb-card__link" href="<?php echo esc_url( $lisensio_kb_category_url ); ?>">
            <?php echo esc_html( $lisensio_kb_category->name ); ?>
        </a>
    </h3>

    <p class="lisensio-kb-card__meta">
        <?php
        printf(
            esc_html(
                /* translators: %s: number of articles. */
                _n( '%s article', '%s articles', $lisensio_kb_category_count, 'lisensio-knowledge-base' )
            ),
            esc_html( number_format_i18n( $lisensio_kb_category_count ) )
        );
        ?>
    </p>

    <?php if ( '' !== $lisensio_kb_category->description ) : ?>
        <p class="lisensio-kb-card__excerpt"><?php echo esc_html( $lisensio_kb_category->description ); ?></p>
    <?php endif; ?>

    <?php if ( ! empty( $lisensio_kb_children ) ) : ?>
        <ul class="lisensio-kb-card__children">
            <?php foreach ( $lisensio_kb_children as $lisensio_kb_child ) : ?>
                <li class="lisensio-kb-card__child">
                    <a href="<?php echo esc_url( Lisensio_KB_Templates::term_link( $lisensio_kb_child ) ); ?>">
                        <?php echo esc_html( $lisensio_kb_child->name ); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</article>
