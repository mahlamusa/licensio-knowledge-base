<?php
/**
 * View page header.
 *
 * The one heading block the redesigned archive views open with: the page
 * heading and the hairline rule beneath it. The knowledge base archive and a
 * category archive both render this file, so the two views cannot drift apart.
 *
 * When an image is passed (the archive passes its Archive header image) the
 * header takes it as a background and sets the heading on blurred panels,
 * which keep it legible over most images without the plugin choosing a
 * colour. With no image the header is plain and takes the theme's colours.
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
 *     @type string $image       Optional. URL of a background image for the header. A plain header when empty. Default ''.
 * }
 */

defined( 'ABSPATH' ) || exit;

$licensio_kb_header_title    = isset( $args['title'] ) ? (string) $args['title'] : '';
$licensio_kb_header_subtitle = isset( $args['subtitle'] ) ? (string) $args['subtitle'] : '';

/*
 * esc_url() strips double quotes and backslashes, so the URL cannot close the
 * quoted CSS url() it is printed into and start a declaration of its own.
 */
$licensio_kb_header_image = isset( $args['image'] ) ? trim( (string) $args['image'] ) : '';
$licensio_kb_header_image = '' === $licensio_kb_header_image ? '' : esc_url( $licensio_kb_header_image );
?>
<?php if ( '' === $licensio_kb_header_image ) : ?>
<header class="licensio-kb__pagehead">
<?php else : ?>
<header class="licensio-kb__pagehead licensio-kb__pagehead--image" style="<?php echo esc_attr( '--licensio-kb-pagehead-image:url("' . $licensio_kb_header_image . '")' ); ?>">
<?php endif; ?>
    <h1 class="licensio-kb__pagehead-title"><?php echo esc_html( $licensio_kb_header_title ); ?></h1>

    <?php if ( '' !== $licensio_kb_header_subtitle ) : ?>
        <p class="licensio-kb__pagehead-subtitle"><?php echo esc_html( $licensio_kb_header_subtitle ); ?></p>
    <?php endif; ?>
</header>
