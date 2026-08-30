<?php
/**
 * Article feedback form.
 *
 * Rendered in the article footer by Lisensio_KB_Feedback_Widget. Override from
 * a theme by copying it to `lisensio-kb/feedback-form.php`.
 *
 * The comment field is present in the markup and hidden with the `hidden`
 * attribute; `assets/js/feedback.js` reveals it after a negative vote. The
 * status paragraph is a polite live region, so the outcome of a vote is
 * announced to a screen reader without moving focus.
 *
 * Available in `$args`:
 * - `article_id` int   The article being voted on.
 * - `score`      array The cached usefulness figures: `score`, `up`, `down`.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

$lisensio_kb_article_id = isset( $args['article_id'] ) ? (int) $args['article_id'] : 0;
$lisensio_kb_score      = isset( $args['score'] ) && is_array( $args['score'] ) ? $args['score'] : array();
$lisensio_kb_votes      = ( isset( $lisensio_kb_score['up'] ) ? (int) $lisensio_kb_score['up'] : 0 )
    + ( isset( $lisensio_kb_score['down'] ) ? (int) $lisensio_kb_score['down'] : 0 );
$lisensio_kb_percentage = isset( $lisensio_kb_score['score'] ) ? (int) $lisensio_kb_score['score'] : null;

if ( $lisensio_kb_article_id <= 0 ) {
    return;
}
?>
<section
    class="lisensio-kb-feedback"
    data-lisensio-kb-feedback
    data-article-id="<?php echo esc_attr( (string) $lisensio_kb_article_id ); ?>"
    aria-labelledby="lisensio-kb-feedback-heading-<?php echo esc_attr( (string) $lisensio_kb_article_id ); ?>"
>
    <h2 class="lisensio-kb-feedback__heading" id="lisensio-kb-feedback-heading-<?php echo esc_attr( (string) $lisensio_kb_article_id ); ?>">
        <?php esc_html_e( 'Was this article helpful?', 'lisensio-knowledge-base' ); ?>
    </h2>

    <div class="lisensio-kb-feedback__actions" data-lisensio-kb-feedback-actions>
        <button
            type="button"
            class="lisensio-kb-feedback__button lisensio-kb-feedback__button--up"
            data-lisensio-kb-vote="1"
        >
            <?php esc_html_e( 'Yes', 'lisensio-knowledge-base' ); ?>
        </button>
        <button
            type="button"
            class="lisensio-kb-feedback__button lisensio-kb-feedback__button--down"
            data-lisensio-kb-vote="-1"
        >
            <?php esc_html_e( 'No', 'lisensio-knowledge-base' ); ?>
        </button>
    </div>

    <div class="lisensio-kb-feedback__comment" data-lisensio-kb-feedback-comment hidden>
        <label
            class="lisensio-kb-feedback__label"
            for="lisensio-kb-feedback-comment-<?php echo esc_attr( (string) $lisensio_kb_article_id ); ?>"
        >
            <?php esc_html_e( 'What could we improve? (optional)', 'lisensio-knowledge-base' ); ?>
        </label>
        <textarea
            class="lisensio-kb-feedback__textarea"
            id="lisensio-kb-feedback-comment-<?php echo esc_attr( (string) $lisensio_kb_article_id ); ?>"
            data-lisensio-kb-feedback-textarea
            rows="4"
            maxlength="<?php echo esc_attr( (string) Lisensio_KB_Feedback::COMMENT_MAX_LENGTH ); ?>"
        ></textarea>
        <button type="button" class="lisensio-kb-feedback__button lisensio-kb-feedback__button--send" data-lisensio-kb-feedback-send>
            <?php esc_html_e( 'Send feedback', 'lisensio-knowledge-base' ); ?>
        </button>
    </div>

    <p class="lisensio-kb-feedback__status" data-lisensio-kb-feedback-status role="status" aria-live="polite"></p>

    <?php if ( null !== $lisensio_kb_percentage && $lisensio_kb_votes > 0 ) : ?>
        <p class="lisensio-kb-feedback__summary" data-lisensio-kb-feedback-summary>
            <?php
            printf(
                /* translators: 1: usefulness percentage, 2: total number of votes. */
                esc_html( _n( '%1$d%% of %2$d reader found this helpful.', '%1$d%% of %2$d readers found this helpful.', $lisensio_kb_votes, 'lisensio-knowledge-base' ) ),
                (int) $lisensio_kb_percentage,
                (int) $lisensio_kb_votes
            );
            ?>
        </p>
    <?php endif; ?>
</section>
