<?php
/**
 * Article feedback form.
 *
 * Rendered in the article footer by Licensio_KB_Feedback_Widget. Override from
 * a theme by copying it to `licensio-kb/feedback-form.php`.
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
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

$licensio_kb_article_id = isset( $args['article_id'] ) ? (int) $args['article_id'] : 0;
$licensio_kb_score      = isset( $args['score'] ) && is_array( $args['score'] ) ? $args['score'] : array();
$licensio_kb_votes      = ( isset( $licensio_kb_score['up'] ) ? (int) $licensio_kb_score['up'] : 0 )
    + ( isset( $licensio_kb_score['down'] ) ? (int) $licensio_kb_score['down'] : 0 );
$licensio_kb_percentage = isset( $licensio_kb_score['score'] ) ? (int) $licensio_kb_score['score'] : null;

if ( $licensio_kb_article_id <= 0 ) {
    return;
}
?>
<section
    class="licensio-kb-feedback"
    data-licensio-kb-feedback
    data-article-id="<?php echo esc_attr( (string) $licensio_kb_article_id ); ?>"
    aria-labelledby="licensio-kb-feedback-heading-<?php echo esc_attr( (string) $licensio_kb_article_id ); ?>"
>
    <h2 class="licensio-kb-feedback__heading" id="licensio-kb-feedback-heading-<?php echo esc_attr( (string) $licensio_kb_article_id ); ?>">
        <?php esc_html_e( 'Was this article helpful?', 'licensio-knowledge-base' ); ?>
    </h2>

    <div class="licensio-kb-feedback__actions" data-licensio-kb-feedback-actions>
        <button
            type="button"
            class="licensio-kb-feedback__button licensio-kb-feedback__button--up"
            data-licensio-kb-vote="1"
        >
            <?php esc_html_e( 'Yes', 'licensio-knowledge-base' ); ?>
        </button>
        <button
            type="button"
            class="licensio-kb-feedback__button licensio-kb-feedback__button--down"
            data-licensio-kb-vote="-1"
        >
            <?php esc_html_e( 'No', 'licensio-knowledge-base' ); ?>
        </button>
    </div>

    <div class="licensio-kb-feedback__comment" data-licensio-kb-feedback-comment hidden>
        <label
            class="licensio-kb-feedback__label"
            for="licensio-kb-feedback-comment-<?php echo esc_attr( (string) $licensio_kb_article_id ); ?>"
        >
            <?php esc_html_e( 'What could we improve? (optional)', 'licensio-knowledge-base' ); ?>
        </label>
        <textarea
            class="licensio-kb-feedback__textarea"
            id="licensio-kb-feedback-comment-<?php echo esc_attr( (string) $licensio_kb_article_id ); ?>"
            data-licensio-kb-feedback-textarea
            rows="4"
            maxlength="<?php echo esc_attr( (string) Licensio_KB_Feedback::COMMENT_MAX_LENGTH ); ?>"
        ></textarea>
        <button type="button" class="licensio-kb-feedback__button licensio-kb-feedback__button--send" data-licensio-kb-feedback-send>
            <?php esc_html_e( 'Send feedback', 'licensio-knowledge-base' ); ?>
        </button>
    </div>

    <p class="licensio-kb-feedback__status" data-licensio-kb-feedback-status role="status" aria-live="polite"></p>

    <?php if ( null !== $licensio_kb_percentage && $licensio_kb_votes > 0 ) : ?>
        <p class="licensio-kb-feedback__summary" data-licensio-kb-feedback-summary>
            <?php
            printf(
                /* translators: 1: usefulness percentage, 2: total number of votes. */
                esc_html( _n( '%1$d%% of %2$d reader found this helpful.', '%1$d%% of %2$d readers found this helpful.', $licensio_kb_votes, 'licensio-knowledge-base' ) ),
                (int) $licensio_kb_percentage,
                (int) $licensio_kb_votes
            );
            ?>
        </p>
    <?php endif; ?>
</section>
