<?php
/**
 * Frequently asked questions.
 *
 * The body of the `lisensio-kb/faq` block and the `[lisensio_kb_faq]`
 * shortcode. A disclosure list built from `<details>`, so it opens and closes
 * with no JavaScript and stays keyboard accessible. Override from a theme by
 * copying this file to `lisensio-kb/faq.php`.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 *
 * @var array $args {
 *     Template arguments.
 *
 *     @type array<int, array{question: string, answer: string, url: string}> $items      The questions.
 *     @type bool                                                             $open_first Whether the first question starts open. Default false.
 * }
 */

defined( 'ABSPATH' ) || exit;

$lisensio_kb_faq_items  = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();
$lisensio_kb_open_first = isset( $args['open_first'] ) && (bool) $args['open_first'];

if ( empty( $lisensio_kb_faq_items ) ) {
    lisensio_kb_get_template(
        'no-results',
        array(
            'title'       => __( 'No questions found', 'lisensio-knowledge-base' ),
            'description' => __( 'Nothing has been published here yet.', 'lisensio-knowledge-base' ),
        )
    );

    return;
}
?>
<div class="lisensio-kb-faq">
    <?php foreach ( $lisensio_kb_faq_items as $lisensio_kb_faq_index => $lisensio_kb_faq_item ) : ?>
        <?php
        $lisensio_kb_question = isset( $lisensio_kb_faq_item['question'] ) ? (string) $lisensio_kb_faq_item['question'] : '';
        $lisensio_kb_answer   = isset( $lisensio_kb_faq_item['answer'] ) ? (string) $lisensio_kb_faq_item['answer'] : '';
        $lisensio_kb_url      = isset( $lisensio_kb_faq_item['url'] ) ? (string) $lisensio_kb_faq_item['url'] : '';

        if ( '' === $lisensio_kb_question ) {
            continue;
        }

        $lisensio_kb_open = $lisensio_kb_open_first && 0 === $lisensio_kb_faq_index;
        ?>
        <details class="lisensio-kb-faq__item"<?php echo $lisensio_kb_open ? ' open' : ''; ?>>
            <summary class="lisensio-kb-faq__question"><?php echo esc_html( $lisensio_kb_question ); ?></summary>
            <div class="lisensio-kb-faq__answer">
                <?php if ( '' !== $lisensio_kb_answer ) : ?>
                    <p class="lisensio-kb-faq__text"><?php echo esc_html( $lisensio_kb_answer ); ?></p>
                <?php endif; ?>
                <?php if ( '' !== $lisensio_kb_url ) : ?>
                    <p class="lisensio-kb-faq__more">
                        <a href="<?php echo esc_url( $lisensio_kb_url ); ?>">
                            <?php esc_html_e( 'Read the full article', 'lisensio-knowledge-base' ); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>
        </details>
    <?php endforeach; ?>
</div>
