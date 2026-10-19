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
 * Override from a theme by copying this file to `licensio-kb/sidebar.php`.
 *
 * @package Licensio_KB
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

$licensio_kb_sticky     = isset( $args['sticky'] ) && (bool) $args['sticky'];
$licensio_kb_headings   = isset( $args['toc'] ) && is_array( $args['toc'] ) ? $args['toc'] : array();
$licensio_kb_categories = isset( $args['categories'] ) && is_array( $args['categories'] ) ? $args['categories'] : array();
$licensio_kb_tags       = isset( $args['tags'] ) && is_array( $args['tags'] ) ? $args['tags'] : array();
$licensio_kb_current    = isset( $args['current'] ) ? (int) $args['current'] : 0;
$licensio_kb_popular    = isset( $args['popular'] ) && is_array( $args['popular'] )
    ? array_values( array_filter( $args['popular'], array( 'Licensio_KB_Sidebar', 'is_post' ) ) )
    : array();
$licensio_kb_support    = isset( $args['support'] ) ? (string) $args['support'] : '';

/*
 * A single heading is a link to a page the reader is already looking at, so
 * the list needs two before it earns its own heading. The check is on the flat
 * list rather than on the tree, because two sub-sections of one section are
 * still two places to go.
 */
$licensio_kb_toc = count( $licensio_kb_headings ) >= Licensio_KB_TOC::MIN_HEADINGS
    ? Licensio_KB_TOC::tree( $licensio_kb_headings )
    : array();

if ( empty( $licensio_kb_toc ) && empty( $licensio_kb_categories ) && empty( $licensio_kb_tags ) && empty( $licensio_kb_popular ) && '' === $licensio_kb_support ) {
    return;
}

$licensio_kb_sidebar_class = 'licensio-kb__sidebar' . ( $licensio_kb_sticky ? ' licensio-kb__sidebar--sticky' : '' );
?>
<aside class="<?php echo esc_attr( $licensio_kb_sidebar_class ); ?>">

    <?php if ( ! empty( $licensio_kb_toc ) ) : ?>
        <nav class="licensio-kb-widget licensio-kb-widget--toc" aria-label="<?php esc_attr_e( 'On this page', 'licensio-knowledge-base' ); ?>">
            <h2 class="licensio-kb-widget__title"><?php esc_html_e( 'On this page', 'licensio-knowledge-base' ); ?></h2>

            <ol class="licensio-kb-toc">
                <?php foreach ( $licensio_kb_toc as $licensio_kb_heading ) : ?>
                    <li class="licensio-kb-toc__item">
                        <a class="licensio-kb-toc__link" href="#<?php echo esc_attr( $licensio_kb_heading['id'] ); ?>"><?php echo esc_html( $licensio_kb_heading['text'] ); ?></a>

                        <?php if ( ! empty( $licensio_kb_heading['children'] ) ) : ?>
                            <ol class="licensio-kb-toc licensio-kb-toc--nested">
                                <?php foreach ( $licensio_kb_heading['children'] as $licensio_kb_child ) : ?>
                                    <li class="licensio-kb-toc__item">
                                        <a class="licensio-kb-toc__link" href="#<?php echo esc_attr( $licensio_kb_child['id'] ); ?>"><?php echo esc_html( $licensio_kb_child['text'] ); ?></a>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </nav>
    <?php endif; ?>

    <?php if ( ! empty( $licensio_kb_categories ) ) : ?>
        <nav class="licensio-kb-widget licensio-kb-widget--categories" aria-label="<?php esc_attr_e( 'Categories', 'licensio-knowledge-base' ); ?>">
            <h2 class="licensio-kb-widget__title"><?php esc_html_e( 'Categories', 'licensio-knowledge-base' ); ?></h2>

            <ul class="licensio-kb-widget__list">
                <?php foreach ( $licensio_kb_categories as $licensio_kb_category ) : ?>
                    <?php if ( $licensio_kb_category instanceof WP_Term ) : ?>
                        <?php $licensio_kb_is_current = $licensio_kb_current > 0 && (int) $licensio_kb_category->term_id === $licensio_kb_current; ?>
                        <li class="licensio-kb-widget__item<?php echo $licensio_kb_is_current ? ' is-current' : ''; ?>">
                            <a
                                class="licensio-kb-widget__link"
                                href="<?php echo esc_url( Licensio_KB_Templates::term_link( $licensio_kb_category ) ); ?>"
                                <?php
                                /*
                                 * `aria-current="page"` rather than a visual
                                 * marker alone: the highlight tells a sighted
                                 * reader which category they are in, and this
                                 * tells everyone else the same thing.
                                 */
                                echo $licensio_kb_is_current ? ' aria-current="page"' : '';
                                ?>
                            ><?php echo esc_html( $licensio_kb_category->name ); ?></a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </nav>
    <?php endif; ?>

    <?php if ( ! empty( $licensio_kb_tags ) ) : ?>
        <nav class="licensio-kb-widget licensio-kb-widget--tags" aria-label="<?php esc_attr_e( 'Tags', 'licensio-knowledge-base' ); ?>">
            <h2 class="licensio-kb-widget__title"><?php esc_html_e( 'Tags', 'licensio-knowledge-base' ); ?></h2>

            <ul class="licensio-kb-tags">
                <?php foreach ( $licensio_kb_tags as $licensio_kb_tag ) : ?>
                    <?php if ( $licensio_kb_tag instanceof WP_Term ) : ?>
                        <li class="licensio-kb-tags__item">
                            <a class="licensio-kb-tag" href="<?php echo esc_url( Licensio_KB_Templates::term_link( $licensio_kb_tag ) ); ?>"><?php echo esc_html( $licensio_kb_tag->name ); ?></a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </nav>
    <?php endif; ?>

    <?php if ( ! empty( $licensio_kb_popular ) ) : ?>
        <nav class="licensio-kb-widget licensio-kb-widget--popular" aria-label="<?php esc_attr_e( 'Popular articles', 'licensio-knowledge-base' ); ?>">
            <h2 class="licensio-kb-widget__title"><?php esc_html_e( 'Popular Articles', 'licensio-knowledge-base' ); ?></h2>

            <ul class="licensio-kb-widget__list licensio-kb-widget__list--articles">
                <?php foreach ( $licensio_kb_popular as $licensio_kb_article ) : ?>
                    <li class="licensio-kb-widget__item">
                        <a class="licensio-kb-widget__link licensio-kb-widget__link--article" href="<?php echo esc_url( (string) get_permalink( $licensio_kb_article ) ); ?>">
                            <svg class="licensio-kb-widget__article-icon" viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round" aria-hidden="true">
                                <path d="M4 1.5h5.2L12.5 4.8V14.5H4z" />
                                <path d="M9 1.5v3.5h3.5" stroke-linecap="round" />
                            </svg>
                            <span class="licensio-kb-widget__article-title"><?php echo esc_html( get_the_title( $licensio_kb_article ) ); ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
    <?php endif; ?>

    <?php if ( '' !== $licensio_kb_support ) : ?>
        <aside class="licensio-kb-widget licensio-kb-widget--support">
            <h2 class="licensio-kb-widget__title"><?php esc_html_e( 'Need Support?', 'licensio-knowledge-base' ); ?></h2>

            <p class="licensio-kb-widget__support-text">
                <?php esc_html_e( 'Can’t find what you’re looking for? Get in touch and we’ll help you out.', 'licensio-knowledge-base' ); ?>
            </p>

            <a class="licensio-kb-widget__button" href="<?php echo esc_url( $licensio_kb_support ); ?>">
                <?php esc_html_e( 'Contact Support', 'licensio-knowledge-base' ); ?>
            </a>
        </aside>
    <?php endif; ?>

</aside>
