<?php
/**
 * Frequently asked questions.
 *
 * The body of the `licensio-kb/faq` block and the `[licensio_kb_faq]`
 * shortcode. A disclosure list built from `<details>`, so it opens and closes
 * with no JavaScript and stays keyboard accessible. Override from a theme by
 * copying this file to `licensio-kb/faq.php`.
 *
 * @package Licensio_KB
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

$licensio_kb_faq_items  = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();
$licensio_kb_open_first = isset( $args['open_first'] ) && (bool) $args['open_first'];

if ( empty( $licensio_kb_faq_items ) ) {
    licensio_kb_get_template(
        'no-results',
        array(
            'title'       => __( 'No questions found', 'licensio-knowledge-base' ),
            'description' => __( 'Nothing has been published here yet.', 'licensio-knowledge-base' ),
        )
    );

    return;
}
?>
<div class="licensio-kb-faq">
    <?php foreach ( $licensio_kb_faq_items as $licensio_kb_faq_index => $licensio_kb_faq_item ) : ?>
        <?php
        $licensio_kb_question = isset( $licensio_kb_faq_item['question'] ) ? (string) $licensio_kb_faq_item['question'] : '';
        $licensio_kb_answer   = isset( $licensio_kb_faq_item['answer'] ) ? (string) $licensio_kb_faq_item['answer'] : '';
        $licensio_kb_url      = isset( $licensio_kb_faq_item['url'] ) ? (string) $licensio_kb_faq_item['url'] : '';

        if ( '' === $licensio_kb_question ) {
            continue;
        }

        $licensio_kb_open = $licensio_kb_open_first && 0 === $licensio_kb_faq_index;
        ?>
        <details class="licensio-kb-faq__item"<?php echo $licensio_kb_open ? ' open' : ''; ?>>
            <summary class="licensio-kb-faq__question"><?php echo esc_html( $licensio_kb_question ); ?></summary>
            <div class="licensio-kb-faq__answer">
                <?php if ( '' !== $licensio_kb_answer ) : ?>
                    <p class="licensio-kb-faq__text"><?php echo esc_html( $licensio_kb_answer ); ?></p>
                <?php endif; ?>
                <?php if ( '' !== $licensio_kb_url ) : ?>
                    <p class="licensio-kb-faq__more">
                        <a href="<?php echo esc_url( $licensio_kb_url ); ?>">
                            <?php esc_html_e( 'Read the full article', 'licensio-knowledge-base' ); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>
        </details>
    <?php endforeach; ?>
</div>
