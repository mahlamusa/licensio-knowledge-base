<?php
/**
 * View page header.
 *
 * The one heading block the redesigned archive views open with: the page
 * heading and the hairline rule beneath it. The knowledge base archive and a
 * category archive both render this file, so the two views cannot drift apart.
 *
 * Override from a theme by copying this file to
 * `licensio-kb/page-header.php`.
 *
 * @package Licensio_KB
 * @since   1.0.0
 *
 * @var array $args {
 *     Template arguments.
 *
 *     @type string $title       The heading. Printed as plain text.
 *     @type string $subtitle    Optional. A muted line under the heading, above the rule. Omitted entirely when empty. Default ''.
 * }
 */

defined( 'ABSPATH' ) || exit;

$licensio_kb_header_title    = isset( $args['title'] ) ? (string) $args['title'] : '';
$licensio_kb_header_subtitle = isset( $args['subtitle'] ) ? (string) $args['subtitle'] : '';
?>
<header class="licensio-kb__pagehead">
    <h1 class="licensio-kb__pagehead-title"><?php echo esc_html( $licensio_kb_header_title ); ?></h1>

    <?php if ( '' !== $licensio_kb_header_subtitle ) : ?>
        <p class="licensio-kb__pagehead-subtitle"><?php echo esc_html( $licensio_kb_header_subtitle ); ?></p>
    <?php endif; ?>
</header>
