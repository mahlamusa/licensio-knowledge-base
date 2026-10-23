<?php
/**
 * A content manager that reports itself switched on.
 *
 * The real class ships inert, so the decision table, the hooks and the
 * redirects it holds for the switch-on can only be exercised through a
 * subclass that answers true from is_active(). A named class rather than an
 * anonymous one, because Brain Monkey cannot store a callback whose object is
 * an anonymous class.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/**
 * Licensio_KB_Content_Manager with is_active() forced on.
 *
 * @since 1.0.0
 */
class Licensio_KB_Active_Content_Manager extends Licensio_KB_Content_Manager {

    /**
     * Report the content manager as switched on.
     *
     * @since 1.0.0
     *
     * @return bool Always true.
     */
    public static function is_active() {
        return true;
    }
}
