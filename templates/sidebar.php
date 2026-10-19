<?php
/**
 * Article and category sidebar.
 *
 * The sections a view's sidebar is composed of: the table of contents for the
 * article being read, the knowledge base categories, the tags, the popular
 * articles and the Need Support card. Each one is skipped entirely when it has
 * nothing to show, and when all of them are empty the file prints nothing at
 * all — which is what lets the calling template drop the two-column layout
 * rather than reserve a column for an empty box.
 *
 * The side the sidebar sits on is not decided here. It is a modifier class on
 * the layout wrapper, the way the reference encodes it, so the same markup
 * serves both sides and a theme restyles either with one selector.
 *
 * Override from a theme by copying this file to `lisensio-kb/sidebar.php`.
 *
 * @package Lisensio_KB
 * @since   1.0.0 Added the Popular Articles and Need Support sections.
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
 *     @type WP_Post[]                         $popular    Optional. The articles the Popular Articles widget lists. Default empty.
 *     @type string                            $support    Optional. The URL behind the Contact Support button. Empty hides the card. Default ''.
 * }
 */

defined( 'ABSPATH' ) || exit;

$lisensio_kb_sticky     = isset( $args['sticky'] ) && (bool) $args['sticky'];
$lisensio_kb_headings   = isset( $args['toc'] ) && is_array( $args['toc'] ) ? $args['toc'] : array();
$lisensio_kb_categories = isset( $args['categories'] ) && is_array( $args['categories'] ) ? $args['categories'] : array();
$lisensio_kb_tags       = isset( $args['tags'] ) && is_array( $args['tags'] ) ? $args['tags'] : array();
$lisensio_kb_current    = isset( $args['current'] ) ? (int) $args['current'] : 0;
$lisensio_kb_popular    = isset( $args['popular'] ) && is_array( $args['popular'] )
    ? array_values( array_filter( $args['popular'], array( 'Lisensio_KB_Sidebar', 'is_post' ) ) )
    : array();
$lisensio_kb_support    = isset( $args['support'] ) ? (string) $args['support'] : '';

/*
 * A single heading is a link to a page the reader is already looking at, so
 * the list needs two before it earns its own heading. The check is on the flat
 * list rather than on the tree, because two sub-sections of one section are
 * still two places to go.
 */
$lisensio_kb_toc = count( $lisensio_kb_headings ) >= Lisensio_KB_TOC::MIN_HEADINGS
    ? Lisensio_KB_TOC::tree( $lisensio_kb_headings )
    : array();

if ( empty( $lisensio_kb_toc ) && empty( $lisensio_kb_categories ) && empty( $lisensio_kb_tags ) && empty( $lisensio_kb_popular ) && '' === $lisensio_kb_support ) {
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

    <?php if ( ! empty( $lisensio_kb_popular ) ) : ?>
        <nav class="lisensio-kb-widget lisensio-kb-widget--popular" aria-label="<?php esc_attr_e( 'Popular articles', 'lisensio-knowledge-base' ); ?>">
            <h2 class="lisensio-kb-widget__title"><?php esc_html_e( 'Popular Articles', 'lisensio-knowledge-base' ); ?></h2>

            <ul class="lisensio-kb-widget__list lisensio-kb-widget__list--articles">
                <?php foreach ( $lisensio_kb_popular as $lisensio_kb_article ) : ?>
                    <li class="lisensio-kb-widget__item">
                        <a class="lisensio-kb-widget__link lisensio-kb-widget__link--article" href="<?php echo esc_url( (string) get_permalink( $lisensio_kb_article ) ); ?>">
                            <svg class="lisensio-kb-widget__article-icon" viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round" aria-hidden="true">
                                <path d="M4 1.5h5.2L12.5 4.8V14.5H4z" />
                                <path d="M9 1.5v3.5h3.5" stroke-linecap="round" />
                            </svg>
                            <span class="lisensio-kb-widget__article-title"><?php echo esc_html( get_the_title( $lisensio_kb_article ) ); ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
    <?php endif; ?>

    <?php if ( '' !== $lisensio_kb_support ) : ?>
        <aside class="lisensio-kb-widget lisensio-kb-widget--support">
            <h2 class="lisensio-kb-widget__title"><?php esc_html_e( 'Need Support?', 'lisensio-knowledge-base' ); ?></h2>

            <p class="lisensio-kb-widget__support-text">
                <?php esc_html_e( 'Can’t find what you’re looking for? Get in touch and we’ll help you out.', 'lisensio-knowledge-base' ); ?>
            </p>

            <a class="lisensio-kb-widget__button" href="<?php echo esc_url( $lisensio_kb_support ); ?>">
                <?php esc_html_e( 'Contact Support', 'lisensio-knowledge-base' ); ?>
            </a>
        </aside>
    <?php endif; ?>

</aside>
