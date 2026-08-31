<?php
/**
 * Shared shell for the Vue-backed admin screens.
 *
 * Analytics, Feedback and Settings each hand their whole screen to a Vue
 * application, so the only markup PHP owns is the frame around the mount
 * point. Printing that frame from one place keeps the three screens
 * measurably identical: same ground, same measure, same heading position,
 * and a heading that is present before the bundle has parsed.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prints the frame the admin applications mount into.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Admin_Page {

    /**
     * Print the page shell and the mount point.
     *
     * The heading sits inside the measured column rather than in `.wrap` so it
     * lines up with the content the application draws underneath it, and it is
     * server-rendered so the screen is never nameless while the bundle loads.
     *
     * @since 1.0.0
     *
     * @param string $title        Screen heading, already translated.
     * @param string $mount_id     DOM id the application mounts on.
     * @param string $loading_text Placeholder shown until the bundle runs, already translated.
     * @param string $mount_class  Optional class for the mount element.
     *
     * @return void
     */
    public static function shell( $title, $mount_id, $loading_text, $mount_class = '' ) {
        printf(
            '<div class="wrap lkb-wrap"><div class="lkb-page"><div class="lkb-page__inner"><h1 class="lkb-page__title">%1$s</h1><div id="%2$s" class="%3$s"><p class="lkb-page__loading">%4$s</p></div></div></div></div>',
            esc_html( $title ),
            esc_attr( $mount_id ),
            esc_attr( $mount_class ),
            esc_html( $loading_text )
        );
    }
}
