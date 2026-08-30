<?php
/**
 * Breadcrumb trail.
 *
 * Override from a theme by copying this file to `lisensio-kb/breadcrumbs.php`.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 *
 * @var array $args {
 *     Template arguments.
 *
 *     @type array<int, array{label: string, url: string}> $items The trail, outermost first.
 * }
 */

defined( 'ABSPATH' ) || exit;

$lisensio_kb_items = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();

if ( empty( $lisensio_kb_items ) ) {
    return;
}
?>
<nav class="lisensio-kb__breadcrumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'lisensio-knowledge-base' ); ?>">
    <ol class="lisensio-kb__crumbs">
        <?php foreach ( $lisensio_kb_items as $lisensio_kb_index => $lisensio_kb_item ) : ?>
            <?php
            $lisensio_kb_label = isset( $lisensio_kb_item['label'] ) ? (string) $lisensio_kb_item['label'] : '';
            $lisensio_kb_url   = isset( $lisensio_kb_item['url'] ) ? (string) $lisensio_kb_item['url'] : '';

            if ( '' === $lisensio_kb_label ) {
                continue;
            }

            $lisensio_kb_is_last = ( count( $lisensio_kb_items ) - 1 ) === $lisensio_kb_index;
            ?>
            <li class="lisensio-kb__crumb">
                <?php if ( '' !== $lisensio_kb_url && ! $lisensio_kb_is_last ) : ?>
                    <a href="<?php echo esc_url( $lisensio_kb_url ); ?>"><?php echo esc_html( $lisensio_kb_label ); ?></a>
                <?php else : ?>
                    <span aria-current="page"><?php echo esc_html( $lisensio_kb_label ); ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>
