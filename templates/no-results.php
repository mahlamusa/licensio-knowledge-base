<?php
/**
 * Empty state.
 *
 * Shown wherever a listing has nothing to list. Override from a theme by
 * copying this file to `lisensio-kb/no-results.php`.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 *
 * @var array $args {
 *     Template arguments.
 *
 *     @type string $title       Headline. Defaults to a generic "nothing here" message.
 *     @type string $description Optional supporting line.
 * }
 */

defined( 'ABSPATH' ) || exit;

$lisensio_kb_empty_title = isset( $args['title'] ) ? (string) $args['title'] : __( 'Nothing here yet', 'lisensio-knowledge-base' );
$lisensio_kb_empty_body  = isset( $args['description'] ) ? (string) $args['description'] : '';
?>
<div class="lisensio-kb__empty">
    <p class="lisensio-kb__empty-title"><?php echo esc_html( $lisensio_kb_empty_title ); ?></p>
    <?php if ( '' !== $lisensio_kb_empty_body ) : ?>
        <p class="lisensio-kb__empty-body"><?php echo esc_html( $lisensio_kb_empty_body ); ?></p>
    <?php endif; ?>
</div>
