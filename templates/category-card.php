<?php
/**
 * Category card.
 *
 * One category in a grid: its icon, its name, its description, the first few
 * articles filed beneath it and a preview of its sub-categories, with the
 * article count kept as secondary meta at the foot of the card. Override from a
 * theme by copying this file to `lisensio-kb/category-card.php`.
 *
 * Three meta keys can supply the icon and only one of them is drawn. The order
 * is inline SVG, then the uploaded attachment, then the dashicon name — most
 * specific first, and set out in full on Lisensio_KB_Term_Meta.
 *
 * @package Lisensio_KB
 * @since   1.0.0 Added the article list, so a card says what is in the category rather than only how much.
 *
 * @var array $args {
 *     Template arguments.
 *
 *     @type WP_Term $term          The category.
 *     @type bool    $show_children Whether to list the direct sub-categories. Default true.
 *     @type bool    $show_count    Whether to show the article count. Default true.
 *     @type bool    $show_icon     Whether to show the category icon. Default false.
 *     @type bool    $show_articles Whether to list the articles in the category. Default true.
 *     @type int     $article_limit How many articles the list is capped at. Default 3.
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
$lisensio_kb_show_count     = ! isset( $args['show_count'] ) || (bool) $args['show_count'];
$lisensio_kb_show_icon      = isset( $args['show_icon'] ) && (bool) $args['show_icon'];
$lisensio_kb_show_articles  = ! isset( $args['show_articles'] ) || (bool) $args['show_articles'];
$lisensio_kb_article_limit  = isset( $args['article_limit'] ) ? (int) $args['article_limit'] : 3;
$lisensio_kb_children       = $lisensio_kb_show_children ? Lisensio_KB_Templates::child_categories( $lisensio_kb_category->term_id ) : array();
$lisensio_kb_articles       = $lisensio_kb_show_articles
    ? Lisensio_KB_Templates::category_articles( $lisensio_kb_category, $lisensio_kb_article_limit )
    : array();
$lisensio_kb_icon_svg       = $lisensio_kb_show_icon ? Lisensio_KB_Term_Meta::get_icon_svg( $lisensio_kb_category->term_id ) : '';
$lisensio_kb_icon_id        = $lisensio_kb_show_icon && '' === $lisensio_kb_icon_svg
    ? Lisensio_KB_Term_Meta::get_icon_id( $lisensio_kb_category->term_id )
    : 0;
$lisensio_kb_icon           = $lisensio_kb_show_icon && '' === $lisensio_kb_icon_svg && 0 === $lisensio_kb_icon_id
    ? Lisensio_KB_Term_Meta::get_icon( $lisensio_kb_category->term_id )
    : '';
?>
<article class="lisensio-kb-card lisensio-kb-card--category">
    <?php if ( '' !== $lisensio_kb_icon_svg ) : ?>
        <span class="lisensio-kb-card__icon lisensio-kb-card__icon--svg" aria-hidden="true">
            <?php
            /*
             * Printed unescaped, and it has to be: escaping it would put the
             * source of the icon on the card instead of the icon.
             *
             * That is safe here because of what the value is, not because of
             * where it is printed. Lisensio_KB_Term_Meta::get_icon_svg() runs
             * every read through Lisensio_KB_SVG_Sanitizer::sanitize(), which
             * rebuilds the markup from an allowlist of element and attribute
             * names — so whatever is in the database, what arrives here has no
             * `script`, no `foreignObject`, no `on*` handler and no `href` that
             * is not a same-document fragment. Reading it back out is not a
             * shortcut past the write-side sanitisation; it is the reason a row
             * written by an importer or by hand is as safe as one written by
             * the term form.
             */
            echo $lisensio_kb_icon_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Allowlist-sanitized on read by Lisensio_KB_Term_Meta::get_icon_svg().
            ?>
        </span>
    <?php elseif ( $lisensio_kb_icon_id > 0 ) : ?>
        <?php
        echo wp_kses_post(
            (string) wp_get_attachment_image(
                $lisensio_kb_icon_id,
                'thumbnail',
                false,
                array(
                    'class' => 'lisensio-kb-card__icon-image',
                    'alt'   => '',
                )
            )
        );
        ?>
    <?php elseif ( '' !== $lisensio_kb_icon ) : ?>
        <span class="lisensio-kb-card__icon dashicons <?php echo esc_attr( $lisensio_kb_icon ); ?>" aria-hidden="true"></span>
    <?php endif; ?>

    <h3 class="lisensio-kb-card__title">
        <a class="lisensio-kb-card__link" href="<?php echo esc_url( $lisensio_kb_category_url ); ?>">
            <?php echo esc_html( $lisensio_kb_category->name ); ?>
        </a>
    </h3>

    <?php if ( '' !== $lisensio_kb_category->description ) : ?>
        <p class="lisensio-kb-card__excerpt"><?php echo esc_html( $lisensio_kb_category->description ); ?></p>
    <?php endif; ?>

    <?php if ( ! empty( $lisensio_kb_articles ) ) : ?>
        <ul class="lisensio-kb-card__articles">
            <?php foreach ( $lisensio_kb_articles as $lisensio_kb_article ) : ?>
                <li class="lisensio-kb-card__article">
                    <a class="lisensio-kb-card__article-link" href="<?php echo esc_url( (string) get_permalink( $lisensio_kb_article ) ); ?>">
                        <?php echo esc_html( get_the_title( $lisensio_kb_article ) ); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
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

    <?php if ( $lisensio_kb_show_count ) : ?>
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
    <?php endif; ?>
</article>
