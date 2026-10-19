<?php
/**
 * Minimal WP_Block_Template double.
 *
 * The block template layer builds and decorates these objects, so the unit
 * suite needs the same public property list WordPress exposes. Properties are
 * declared rather than assigned dynamically, so PHPStan sees the same shape as
 * the real class.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

if ( ! class_exists( 'WP_Block_Template' ) ) {

    /**
     * A block template or template part.
     *
     * @since 1.0.0
     */
    class WP_Block_Template {

        /**
         * Template ID, as `{theme}//{slug}`.
         *
         * @since 1.0.0
         * @var string
         */
        public $id = '';

        /**
         * The theme the template belongs to.
         *
         * @since 1.0.0
         * @var string
         */
        public $theme = '';

        /**
         * Block markup.
         *
         * @since 1.0.0
         * @var string
         */
        public $content = '';

        /**
         * Template slug.
         *
         * @since 1.0.0
         * @var string
         */
        public $slug = '';

        /**
         * Where the template came from: `theme`, `plugin` or `custom`.
         *
         * @since 1.0.0
         * @var string
         */
        public $source = 'theme';

        /**
         * Where a customised template originally came from.
         *
         * @since 1.0.0
         * @var string|null
         */
        public $origin = null;

        /**
         * Either `wp_template` or `wp_template_part`.
         *
         * @since 1.0.0
         * @var string
         */
        public $type = 'wp_template';

        /**
         * Human readable title.
         *
         * @since 1.0.0
         * @var string
         */
        public $title = '';

        /**
         * Human readable description.
         *
         * @since 1.0.0
         * @var string
         */
        public $description = '';

        /**
         * Post status.
         *
         * @since 1.0.0
         * @var string
         */
        public $status = 'publish';

        /**
         * Whether a file on disk backs the template.
         *
         * @since 1.0.0
         * @var bool
         */
        public $has_theme_file = false;

        /**
         * The saved post ID, when the template is a customisation.
         *
         * @since 1.0.0
         * @var int|null
         */
        public $wp_id = null;

        /**
         * Template part area.
         *
         * @since 1.0.0
         * @var string
         */
        public $area = 'uncategorized';

        /**
         * Author of a saved customisation.
         *
         * @since 1.0.0
         * @var int|null
         */
        public $author = null;

        /**
         * Post types the template may be assigned to.
         *
         * @since 1.0.0
         * @var string[]
         */
        public $post_types = array();

        /**
         * Whether the template is a user-created custom template.
         *
         * @since 1.0.0
         * @var bool
         */
        public $is_custom = true;

        /**
         * Last modified date.
         *
         * @since 1.0.0
         * @var string|null
         */
        public $modified = null;
    }
}
