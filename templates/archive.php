<?php
/**
 * Knowledge base archive.
 *
 * The redesigned knowledge base home: a page heading and a hairline rule, a
 * divided grid of category cards, and a sidebar carrying the Popular Articles
 * and Need Support widgets. Override from a theme by copying this file to
 * `licensio-kb/archive.php`.
 *
 * Everything between the theme's header and footer lives in `archive-body.php`,
 * which the `licensio-kb/view` block renders too — that is how a block theme
 * serves the same markup a classic theme does, without a second copy of the
 * design to keep in step.
 *
 * @package Licensio_KB
 * @since   1.0.0 Redesigned to the Help Topics layout: page header, divided grid, popular articles and support widgets.
 * @since   1.0.0 Added the sidebar.
 */

defined( 'ABSPATH' ) || exit;

get_header();

licensio_kb_get_template( 'archive-body' );

/**
 * Fires at the end of the knowledge base archive.
 *
 * The seam a theme attaches its own closing section to — a call to action
 * band, a support link, a contact form. The plugin ships none of that itself:
 * the copy would belong to one site rather than to every install.
 *
 * Fired outside every `.licensio-kb` wrapper, deliberately. What a theme hangs
 * here is the theme's own markup, and inside that wrapper it would inherit the
 * plugin's link colour, focus ring and type — which is not a hypothetical:
 * a theme button rendered inside it came out with `color: var(--licensio-kb-brand)`
 * over a brand-coloured background, unreadable.
 *
 * @since 1.0.0
 */
do_action( 'licensio_kb_after_archive' );
?>
<?php
get_footer();
