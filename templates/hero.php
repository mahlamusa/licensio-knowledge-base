<?php
/**
 * View hero.
 *
 * The one heading block every knowledge base view opens with: the breadcrumb
 * trail, the page heading with its final word painted in the brand gradient,
 * and an optional standfirst. The archive, a single article, a category, a tag
 * and a search results page all render this file, so the five views cannot
 * drift apart.
 *
 * A theme that copies this file to `licensio-kb/hero.php` replaces the hero on
 * all five at once, which is how a theme hands the knowledge base the hero it
 * already uses everywhere else. That indirection — rather than the plugin
 * imitating a particular theme's markup — is the reason this is a template of
 * its own and not a block of markup repeated in five files.
 *
 * The heading is deliberately the only text this file takes. Nothing here is
 * site-specific, so a standalone install renders a sensible hero with no
 * configuration and no copy the plugin had to invent.
 *
 * @package Licensio_KB
 * @since   1.0.0
 *
 * @var array $args {
 *     Template arguments.
 *
 *     @type string $title       The heading. Printed as plain text, split so its last word takes the gradient.
 *     @type string $subtitle    Optional. A standfirst below the heading. Omitted entirely when empty. Default ''.
 *     @type bool   $breadcrumbs Optional. Whether to render the breadcrumb trail. Default true.
 *     @type bool   $search      Optional. Whether to render the search form below the heading. Default false.
 * }
 */

defined( 'ABSPATH' ) || exit;

$licensio_kb_hero_title       = isset( $args['title'] ) ? (string) $args['title'] : '';
$licensio_kb_hero_subtitle    = isset( $args['subtitle'] ) ? (string) $args['subtitle'] : '';
$licensio_kb_hero_breadcrumbs = ! isset( $args['breadcrumbs'] ) || (bool) $args['breadcrumbs'];
$licensio_kb_hero_search      = isset( $args['search'] ) && (bool) $args['search'];

list( $licensio_kb_hero_lead, $licensio_kb_hero_accent ) = Licensio_KB_Templates::split_title( $licensio_kb_hero_title );
?>
<header class="licensio-kb__hero">
    <?php if ( $licensio_kb_hero_breadcrumbs ) : ?>
        <?php licensio_kb_get_template( 'breadcrumbs', array( 'items' => Licensio_KB_Templates::breadcrumb_items() ) ); ?>
    <?php endif; ?>

    <h1 class="licensio-kb__title">
        <?php if ( '' !== $licensio_kb_hero_lead ) : ?>
            <?php echo esc_html( $licensio_kb_hero_lead ); ?>
        <?php endif; ?>
        <span class="licensio-kb__title-accent"><?php echo esc_html( $licensio_kb_hero_accent ); ?></span>
    </h1>

    <?php if ( '' !== $licensio_kb_hero_subtitle ) : ?>
        <p class="licensio-kb__description"><?php echo esc_html( $licensio_kb_hero_subtitle ); ?></p>
    <?php endif; ?>

    <?php if ( $licensio_kb_hero_search ) : ?>
        <?php licensio_kb_get_template( 'search-field' ); ?>
    <?php endif; ?>
</header>
