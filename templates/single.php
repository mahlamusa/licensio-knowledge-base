<?php
/**
 * Single article.
 *
 * Fires the three frontend hooks so feedback, analytics and gating attach
 * without touching this file. Override from a theme by copying it to
 * `licensio-kb/single.php`.
 *
 * Everything between the theme's header and footer lives in
 * `single-body.php`, which the `[licensio_kb_view view="single"]` shortcode
 * renders too — that is how a block theme serves the same breadcrumbs, search
 * field, table of contents and sidebar a classic theme does, without a second
 * copy of the design to keep in step.
 *
 * @package Licensio_KB
 * @since   1.0.0 Rendered the heading and breadcrumbs through `templates/hero.php`.
 * @since   1.0.0 Added the sidebar and the table of contents.
 * @since   1.0.0 Moved the view into `single-body.php` so block themes share it.
 */

defined( 'ABSPATH' ) || exit;

get_header();

licensio_kb_get_template( 'single-body' );

get_footer();
