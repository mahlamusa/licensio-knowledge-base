<?php
/**
 * Article and category sidebar.
 *
 * Three sections in the order the reference implementation uses them: the
 * table of contents for the article being read, the knowledge base categories,
 * and the tags. Each one is skipped entirely when it has nothing to show, and
 * when all three are empty the file prints nothing at all — which is what lets
 * the calling template drop the two-column layout rather than reserve a column
 * for an empty box.
 *
 * The side the sidebar sits on is not decided here. It is a modifier class on
 * the layout wrapper, the way the reference encodes it, so the same markup
 * serves both sides and a theme restyles either with one selector.
 *
 * Override from a theme by copying this file to `lisensio-kb/sidebar.php`.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 *
 * @var array $args {
 *     Template arguments.
 *
 *     @type bool                              $sticky     Optional. Whether the sidebar follows the reader down the page. Default false.
 *     @type array<int, array<string, mixed>>  $toc        Optional. The article headings, flat and in document order. Default empty.
 *     @type WP_Term[]                         $categories Optional. The categories to list. Default empty.
 *     @type WP_Term[]                         $tags       Optional. The tags to list. Default empty.
 *     @type int                               $current    Optional. Term ID to mark as the one being read. Default 0.
 * }
 */

defined( 'ABSPATH' ) || exit;

$lisensio_kb_sticky     = isset( $args['sticky'] ) && (bool) $args['sticky'];
$lisensio_kb_headings   = isset( $args['toc'] ) && is_array( $args['toc'] ) ? $args['toc'] : array();
$lisensio_kb_categories = isset( $args['categories'] ) && is_array( $args['categories'] ) ? $args['categories'] : array();
$lisensio_kb_tags       = isset( $args['tags'] ) && is_array( $args['tags'] ) ? $args['tags'] : array();
$lisensio_kb_current    = isset( $args['current'] ) ? (int) $args['current'] : 0;

/*
 * A single heading is a link to a page the reader is already looking at, so
 * the list needs two before it earns its own heading. The check is on the flat
 * list rather than on the tree, because two sub-sections of one section are
 * still two places to go.
 */
$lisensio_kb_toc = count( $lisensio_kb_headings ) >= Lisensio_KB_TOC::MIN_HEADINGS
    ? Lisensio_KB_TOC::tree( $lisensio_kb_headings )
    : array();

if ( empty( $lisensio_kb_toc ) && empty( $lisensio_kb_categories ) && empty( $lisensio_kb_tags ) ) {
    return;
}

$lisensio_kb_sidebar_class = 'lisensio-kb__sidebar' . ( $lisensio_kb_sticky ? ' lisensio-kb__sidebar--sticky' : '' );
?>
<aside class="<?php echo esc_attr( $lisensio_kb_sidebar_class ); ?>">

    <?php if ( ! empty( $lisensio_kb_toc ) ) : ?>
        <nav class="lisensio-kb-widget lisensio-kb-widget--toc" aria-label="<?php esc_attr_e( 'On this page', 'lisensio-knowledge-base' ); ?>">
            <h2 class="lisensio-kb-widget__title"><?php esc_html_e( 'On this page', 'lisensio-knowledge-base' ); ?></h2>

            <ol class="lisensio-kb-toc">
                <?php foreach ( $lisensio_kb_toc as $lisensio_kb_heading ) : ?>
                    <li class="lisensio-kb-toc__item">
                        <a class="lisensio-kb-toc__link" href="#<?php echo esc_attr( $lisensio_kb_heading['id'] ); ?>"><?php echo esc_html( $lisensio_kb_heading['text'] ); ?></a>

                        <?php if ( ! empty( $lisensio_kb_heading['children'] ) ) : ?>
                            <ol class="lisensio-kb-toc lisensio-kb-toc--nested">
                                <?php foreach ( $lisensio_kb_heading['children'] as $lisensio_kb_child ) : ?>
                                    <li class="lisensio-kb-toc__item">
                                        <a class="lisensio-kb-toc__link" href="#<?php echo esc_attr( $lisensio_kb_child['id'] ); ?>"><?php echo esc_html( $lisensio_kb_child['text'] ); ?></a>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </nav>
    <?php endif; ?>

    <?php if ( ! empty( $lisensio_kb_categories ) ) : ?>
        <nav class="lisensio-kb-widget lisensio-kb-widget--categories" aria-label="<?php esc_attr_e( 'Categories', 'lisensio-knowledge-base' ); ?>">
            <h2 class="lisensio-kb-widget__title"><?php esc_html_e( 'Categories', 'lisensio-knowledge-base' ); ?></h2>

            <ul class="lisensio-kb-widget__list">
                <?php foreach ( $lisensio_kb_categories as $lisensio_kb_category ) : ?>
                    <?php if ( $lisensio_kb_category instanceof WP_Term ) : ?>
                        <?php $lisensio_kb_is_current = $lisensio_kb_current > 0 && (int) $lisensio_kb_category->term_id === $lisensio_kb_current; ?>
                        <li class="lisensio-kb-widget__item<?php echo $lisensio_kb_is_current ? ' is-current' : ''; ?>">
                            <a
                                class="lisensio-kb-widget__link"
                                href="<?php echo esc_url( Lisensio_KB_Templates::term_link( $lisensio_kb_category ) ); ?>"
                                <?php
                                /*
                                 * `aria-current="page"` rather than a visual
                                 * marker alone: the highlight tells a sighted
                                 * reader which category they are in, and this
                                 * tells everyone else the same thing.
                                 */
                                echo $lisensio_kb_is_current ? ' aria-current="page"' : '';
                                ?>
                            ><?php echo esc_html( $lisensio_kb_category->name ); ?></a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </nav>
    <?php endif; ?>

    <?php if ( ! empty( $lisensio_kb_tags ) ) : ?>
        <nav class="lisensio-kb-widget lisensio-kb-widget--tags" aria-label="<?php esc_attr_e( 'Tags', 'lisensio-knowledge-base' ); ?>">
            <h2 class="lisensio-kb-widget__title"><?php esc_html_e( 'Tags', 'lisensio-knowledge-base' ); ?></h2>

            <ul class="lisensio-kb-tags">
                <?php foreach ( $lisensio_kb_tags as $lisensio_kb_tag ) : ?>
                    <?php if ( $lisensio_kb_tag instanceof WP_Term ) : ?>
                        <li class="lisensio-kb-tags__item">
                            <a class="lisensio-kb-tag" href="<?php echo esc_url( Lisensio_KB_Templates::term_link( $lisensio_kb_tag ) ); ?>"><?php echo esc_html( $lisensio_kb_tag->name ); ?></a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </nav>
    <?php endif; ?>

</aside>
