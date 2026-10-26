<?php
/**
 * Category card.
 *
 * One category in a grid: its name with its article count beside it, the
 * first few articles filed beneath it each behind a document icon, and a
 * View all link into the category archive. Override from a theme by copying
 * this file to `licensio-kb/category-card.php`.
 *
 * Four meta keys can supply the icon and only one of them is drawn. The order
 * is inline SVG, then the inline Base64 image, then the uploaded attachment,
 * then the dashicon name — set out in full on Licensio_KB_Term_Meta.
 *
 * @package Licensio_KB
 * @since   1.0.0 Redesigned to the Help Topics card: inline article count, icon-fronted article links and a View all link.
 * @since   1.0.0 Added the article list, so a card says what is in the category rather than only how much.
 *
 * @var array $args {
 *     Template arguments.
 *
 *     @type WP_Term $term          The category.
 *     @type bool    $show_children Whether to list the direct sub-categories. Default true.
 *     @type bool    $show_count    Whether to show the article count beside the name. Default true.
 *     @type bool    $show_icon     Whether to show the category icon. Default false.
 *     @type bool    $show_articles Whether to list the articles in the category. Default true.
 *     @type int     $article_limit How many articles the list is capped at. Default 3.
 * }
 */

defined( 'ABSPATH' ) || exit;

$licensio_kb_category = isset( $args['term'] ) ? $args['term'] : null;

if ( ! $licensio_kb_category instanceof WP_Term ) {
    return;
}

$licensio_kb_category_url   = Licensio_KB_Templates::term_link( $licensio_kb_category );
$licensio_kb_category_count = Licensio_KB_Templates::category_article_count( $licensio_kb_category );
$licensio_kb_show_children  = ! isset( $args['show_children'] ) || (bool) $args['show_children'];
$licensio_kb_show_count     = ! isset( $args['show_count'] ) || (bool) $args['show_count'];
$licensio_kb_show_icon      = isset( $args['show_icon'] ) && (bool) $args['show_icon'];
$licensio_kb_show_articles  = ! isset( $args['show_articles'] ) || (bool) $args['show_articles'];
$licensio_kb_article_limit  = isset( $args['article_limit'] ) ? (int) $args['article_limit'] : 3;
$licensio_kb_children       = $licensio_kb_show_children ? Licensio_KB_Templates::child_categories( $licensio_kb_category->term_id ) : array();
$licensio_kb_articles       = $licensio_kb_show_articles
    ? Licensio_KB_Templates::category_articles( $licensio_kb_category, $licensio_kb_article_limit )
    : array();
$licensio_kb_icon_svg       = $licensio_kb_show_icon ? Licensio_KB_Term_Meta::get_icon_svg( $licensio_kb_category->term_id ) : '';
$licensio_kb_icon_data      = $licensio_kb_show_icon && '' === $licensio_kb_icon_svg
    ? Licensio_KB_Term_Meta::get_icon_data( $licensio_kb_category->term_id )
    : '';
$licensio_kb_icon_id        = $licensio_kb_show_icon && '' === $licensio_kb_icon_svg && '' === $licensio_kb_icon_data
    ? Licensio_KB_Term_Meta::get_icon_id( $licensio_kb_category->term_id )
    : 0;
$licensio_kb_icon           = $licensio_kb_show_icon && '' === $licensio_kb_icon_svg && '' === $licensio_kb_icon_data && 0 === $licensio_kb_icon_id
    ? Licensio_KB_Term_Meta::get_icon( $licensio_kb_category->term_id )
    : '';
?>
<article class="licensio-kb-card licensio-kb-card--category">
    <div class="licensio-kb-card__heading">
    <?php if ( '' !== $licensio_kb_icon_svg ) : ?>
        <span class="licensio-kb-card__icon licensio-kb-card__icon--svg" aria-hidden="true">
            <?php
            /*
             * Printed unescaped, and it has to be: escaping it would put the
             * source of the icon on the card instead of the icon.
             *
             * That is safe here because of what the value is, not because of
             * where it is printed. Licensio_KB_Term_Meta::get_icon_svg() runs
             * every read through Licensio_KB_SVG_Sanitizer::sanitize(), which
             * rebuilds the markup from an allowlist of element and attribute
             * names — so whatever is in the database, what arrives here has no
             * `script`, no `foreignObject`, no `on*` handler and no `href` that
             * is not a same-document fragment. Reading it back out is not a
             * shortcut past the write-side sanitisation; it is the reason a row
             * written by an importer or by hand is as safe as one written by the
             * term form.
             */
            echo $licensio_kb_icon_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Allowlist-sanitized on read by Licensio_KB_Term_Meta::get_icon_svg().
            ?>
        </span>
    <?php elseif ( '' !== $licensio_kb_icon_data ) : ?>
        <?php
        /*
         * esc_attr() rather than esc_url(): a data: URL is not one of the
         * protocols esc_url() keeps, so it would print an empty source. The
         * value is safe as a source because Licensio_KB_Term_Meta::get_icon_data()
         * rebuilds it on every read from bytes that decoded as a PNG, JPEG,
         * GIF or WebP image, so it is only ever `data:image/…;base64,` and
         * Base64 characters.
         */
        ?>
        <img class="licensio-kb-card__icon-image" src="<?php echo esc_attr( $licensio_kb_icon_data ); ?>" alt="" />
    <?php elseif ( $licensio_kb_icon_id > 0 ) : ?>
        <?php
        echo wp_kses_post(
            (string) wp_get_attachment_image(
                $licensio_kb_icon_id,
                'thumbnail',
                false,
                array(
                    'class' => 'licensio-kb-card__icon-image',
                    'alt'   => '',
                )
            )
        );
        ?>
    <?php elseif ( '' !== $licensio_kb_icon ) : ?>
        <span class="licensio-kb-card__icon dashicons <?php echo esc_attr( $licensio_kb_icon ); ?>" aria-hidden="true"></span>
    <?php endif; ?>

    <h3 class="licensio-kb-card__title">
        <a class="licensio-kb-card__link" href="<?php echo esc_url( $licensio_kb_category_url ); ?>">
            <?php echo esc_html( $licensio_kb_category->name ); ?>
        </a>
    </h3>

    <?php if ( $licensio_kb_show_count ) : ?>
        <span class="licensio-kb-card__count">
            <?php
            printf(
                esc_html(
                    /* translators: %s: number of articles. */
                    _n( '(%s Article)', '(%s Articles)', $licensio_kb_category_count, 'licensio-knowledge-base' )
                ),
                esc_html( number_format_i18n( $licensio_kb_category_count ) )
            );
            ?>
        </span>
    <?php endif; ?>
    </div>

    <?php if ( ! empty( $licensio_kb_articles ) ) : ?>
        <ul class="licensio-kb-card__articles">
            <?php foreach ( $licensio_kb_articles as $licensio_kb_article ) : ?>
                <li class="licensio-kb-card__article">
                    <a class="licensio-kb-card__article-link" href="<?php echo esc_url( (string) get_permalink( $licensio_kb_article ) ); ?>">
                        <svg class="licensio-kb-card__article-icon" viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round" aria-hidden="true">
                            <path d="M4 1.5h5.2L12.5 4.8V14.5H4z" />
                            <path d="M9 1.5v3.5h3.5" stroke-linecap="round" />
                        </svg>
                        <span class="licensio-kb-card__article-title"><?php echo esc_html( get_the_title( $licensio_kb_article ) ); ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ( ! empty( $licensio_kb_children ) ) : ?>
        <ul class="licensio-kb-card__children">
            <?php foreach ( $licensio_kb_children as $licensio_kb_child ) : ?>
                <li class="licensio-kb-card__child">
                    <a href="<?php echo esc_url( Licensio_KB_Templates::term_link( $licensio_kb_child ) ); ?>">
                        <?php echo esc_html( $licensio_kb_child->name ); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <a class="licensio-kb-card__view-all" href="<?php echo esc_url( $licensio_kb_category_url ); ?>">
        <?php echo esc_html__( 'View all', 'licensio-knowledge-base' ); ?>
        <svg class="licensio-kb-card__view-all-icon" viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true">
            <circle cx="8" cy="8" r="6.9" />
            <path d="M6.8 5.2 9.6 8l-2.8 2.8" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </a>
</article>
