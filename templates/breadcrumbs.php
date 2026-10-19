<?php
/**
 * Breadcrumb trail.
 *
 * Override from a theme by copying this file to `licensio-kb/breadcrumbs.php`.
 *
 * @package Licensio_KB
 * @since   1.0.0
 *
 * @var array $args {
 *     Template arguments.
 *
 *     @type array<int, array{label: string, url: string}> $items The trail, outermost first.
 * }
 */

defined( 'ABSPATH' ) || exit;

$licensio_kb_items = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();

if ( empty( $licensio_kb_items ) ) {
    return;
}
?>
<nav class="licensio-kb__breadcrumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'licensio-knowledge-base' ); ?>">
    <ol class="licensio-kb__crumbs">
        <?php foreach ( $licensio_kb_items as $licensio_kb_index => $licensio_kb_item ) : ?>
            <?php
            $licensio_kb_label = isset( $licensio_kb_item['label'] ) ? (string) $licensio_kb_item['label'] : '';
            $licensio_kb_url   = isset( $licensio_kb_item['url'] ) ? (string) $licensio_kb_item['url'] : '';

            if ( '' === $licensio_kb_label ) {
                continue;
            }

            $licensio_kb_is_last = ( count( $licensio_kb_items ) - 1 ) === $licensio_kb_index;
            ?>
            <li class="licensio-kb__crumb">
                <?php if ( '' !== $licensio_kb_url && ! $licensio_kb_is_last ) : ?>
                    <a href="<?php echo esc_url( $licensio_kb_url ); ?>"><?php echo esc_html( $licensio_kb_label ); ?></a>
                <?php else : ?>
                    <span aria-current="page"><?php echo esc_html( $licensio_kb_label ); ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>
