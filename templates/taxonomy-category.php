<?php
/**
 * Category archive.
 *
 * A category's own sub-categories followed by the articles filed directly
 * under it. Override from a theme by copying this file to
 * `lisensio-kb/taxonomy-category.php`.
 *
 * Everything between the theme's header and footer lives in
 * `category-body.php`, which the `[lisensio_kb_view view="category"]`
 * shortcode renders too — that is how a block theme serves the same markup a
 * classic theme does, without a second copy of the design to keep in step.
 *
 * @package Lisensio_KB
 * @since   1.0.0 Redesigned to the page header layout and the sidebar-following column counts.
 * @since   1.0.0 Added the sidebar.
 */

defined( 'ABSPATH' ) || exit;

get_header();

lisensio_kb_get_template( 'category-body' );

get_footer();
