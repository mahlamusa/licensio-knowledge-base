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
     * The address of the knowledge base settings screen.
     *
     * The three screens each carry the product header, and the header's cog
     * links here. Built in one place so a change to the menu parent does not
     * have to be found in three localised payloads.
     *
     * @since 1.0.0
     *
     * @return string The admin URL of the settings screen.
     */
    public static function settings_url() {
        return admin_url( 'edit.php?post_type=' . Lisensio_KB_Post_Types::POST_TYPE . '&page=lisensio-kb-settings' );
    }

    /**
     * Print the page shell and the mount point.
     *
     * The heading and the placeholder sit *inside* the mount element, so they
     * are what the screen shows until the bundle parses and then are replaced
     * by the application wholesale. Printing the heading outside the mount
     * would leave it on the page next to the one the application draws, and
     * the screen would carry its own name twice.
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
            '<div class="wrap lkb-wrap"><div id="%2$s" class="lkb-page %3$s"><div class="lkb-page__boot"><h1 class="lkb-page__title">%1$s</h1><p class="lkb-page__loading">%4$s</p></div></div></div>',
            esc_html( $title ),
            esc_attr( $mount_id ),
            esc_attr( $mount_class ),
            esc_html( $loading_text )
        );
    }
}
