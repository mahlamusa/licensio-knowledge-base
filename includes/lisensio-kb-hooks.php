<?php
/**
 * Frontend hook contract.
 *
 * Three action hooks fire around every rendered article, in both template
 * paradigms. Feedback (#8), analytics (#9) and future gating attach to them
 * once and work identically under a classic theme and a block theme.
 *
 * The contract:
 *
 * - `lisensio_before_article` — fires immediately before the article body,
 *   inside the article wrapper. Passed the WP_Post.
 * - `lisensio_after_article` — fires immediately after the article body,
 *   before the footer region. Passed the WP_Post. Feedback and voting attach
 *   here.
 * - `lisensio_article_footer` — fires in the article footer region, after
 *   everything else. Passed the WP_Post. Related articles, attachments and
 *   analytics beacons attach here.
 *
 * Every hook receives the same single argument, so a callback written for one
 * can be moved to another without changing its signature. Template authors
 * MUST call the three functions below rather than `do_action()` directly, so
 * the argument contract stays in one place.
 *
 * Consumed by: #5, #6, #8, #9.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fire the hook that runs before an article body.
 *
 * @since 1.0.0
 *
 * @param WP_Post|int|null $post The article, or its ID. Defaults to the current post.
 *
 * @return void
 */
function lisensio_kb_before_article( $post = null ) {
    $post = get_post( $post );

    /**
     * Fires immediately before a knowledge base article body.
     *
     * @since 1.0.0
     *
     * @param WP_Post|null $post The article being rendered.
     */
    do_action( 'lisensio_before_article', $post );
}

/**
 * Fire the hook that runs after an article body.
 *
 * @since 1.0.0
 *
 * @param WP_Post|int|null $post The article, or its ID. Defaults to the current post.
 *
 * @return void
 */
function lisensio_kb_after_article( $post = null ) {
    $post = get_post( $post );

    /**
     * Fires immediately after a knowledge base article body.
     *
     * @since 1.0.0
     *
     * @param WP_Post|null $post The article being rendered.
     */
    do_action( 'lisensio_after_article', $post );
}

/**
 * Fire the hook that runs in the article footer region.
 *
 * @since 1.0.0
 *
 * @param WP_Post|int|null $post The article, or its ID. Defaults to the current post.
 *
 * @return void
 */
function lisensio_kb_article_footer( $post = null ) {
    $post = get_post( $post );

    /**
     * Fires in the footer region of a knowledge base article.
     *
     * @since 1.0.0
     *
     * @param WP_Post|null $post The article being rendered.
     */
    do_action( 'lisensio_article_footer', $post );
}
