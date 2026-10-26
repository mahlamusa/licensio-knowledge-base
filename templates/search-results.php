<?php
/**
 * Search results.
 *
 * Answers a search scoped to the article post type. Override from a theme by
 * copying this file to `licensio-kb/search-results.php`.
 *
 * Everything between the theme's header and footer lives in
 * `search-body.php`, which the `[licensio_kb_view view="search"]` shortcode
 * renders too — that is how a block theme serves the same markup a classic
 * theme does, without a second copy of the design to keep in step.
 *
 * @package Licensio_KB
 * @since   1.0.0 Rendered the heading and breadcrumbs through `templates/hero.php`.
 * @since   1.0.0 Moved the view into `search-body.php` so block themes share it.
 */

defined( 'ABSPATH' ) || exit;

get_header();

licensio_kb_get_template( 'search-body' );

get_footer();
