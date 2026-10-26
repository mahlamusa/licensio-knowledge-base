<?php
/**
 * Tag archive.
 *
 * Every article carrying one tag. Override from a theme by copying this file
 * to `licensio-kb/taxonomy-tag.php`.
 *
 * Everything between the theme's header and footer lives in `tag-body.php`,
 * which the `[licensio_kb_view view="tag"]` shortcode renders too — that is
 * how a block theme serves the same markup a classic theme does, without a
 * second copy of the design to keep in step.
 *
 * @package Licensio_KB
 * @since   1.0.0 Rendered the heading and breadcrumbs through `templates/hero.php`.
 * @since   1.0.0 Moved the view into `tag-body.php` so block themes share it.
 */

defined( 'ABSPATH' ) || exit;

get_header();

licensio_kb_get_template( 'tag-body' );

get_footer();
