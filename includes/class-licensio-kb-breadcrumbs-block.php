<?php
/**
 * The breadcrumbs block and its shortcode.
 *
 * `licensio-kb/breadcrumbs` and `[licensio_kb_breadcrumbs]` share one render
 * callback and draw the trail through `templates/breadcrumbs.php`, the same
 * template the classic archive, category and single views use.
 *
 * The trail itself comes from `Licensio_KB_Templates::breadcrumb_items()`. The
 * hierarchy walk is not repeated here; the block only decides whether the site
 * home link is part of the trail.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the knowledge base breadcrumb trail.
 *
 * @since 1.0.0
 */
class Licensio_KB_Breadcrumbs_Block extends Licensio_KB_Block {

    /**
     * The block name.
     *
     * @since 1.0.0
     *
     * @return string The block name.
     */
    public static function block_name() {
        return 'licensio-kb/breadcrumbs';
    }

    /**
     * The shortcode tag.
     *
     * @since 1.0.0
     *
     * @return string The shortcode tag.
     */
    public static function shortcode_tag() {
        return 'licensio_kb_breadcrumbs';
    }

    /**
     * The `blocks/` sub-directory holding the block metadata.
     *
     * @since 1.0.0
     *
     * @return string The directory name.
     */
    public static function directory() {
        return 'breadcrumbs';
    }

    /**
     * The BEM modifier used on the block wrapper.
     *
     * @since 1.0.0
     *
     * @return string The modifier.
     */
    public static function modifier() {
        return 'breadcrumbs';
    }

    /**
     * The attributes the block accepts, and their defaults.
     *
     * @since 1.0.0
     *
     * @return array<string, bool|int|string> Attribute name to default value.
     */
    public static function defaults() {
        return array(
            'showHome' => true,
        );
    }

    /**
     * The block's inner markup.
     *
     * @since 1.0.0
     *
     * @param array<string, bool|int|string> $attributes The normalised attributes.
     *
     * @return string The markup.
     */
    public function content( array $attributes ) {
        $items = $this->items( (bool) $attributes['showHome'] );

        if ( empty( $items ) ) {
            return '';
        }

        return licensio_kb_get_template( 'breadcrumbs', array( 'items' => $items ), false );
    }

    /**
     * The trail the block renders.
     *
     * The home link is the first item of the shared trail, so switching it off
     * is a matter of dropping that item rather than of building a second trail.
     *
     * @since 1.0.0
     *
     * @param bool $show_home Whether the site home link stays in the trail.
     *
     * @return array<int, array<string, string>> The trail, outermost first.
     */
    public function items( $show_home ) {
        $items = Licensio_KB_Templates::breadcrumb_items();

        if ( ! $show_home ) {
            array_shift( $items );
        }

        return array_values( $items );
    }
}
