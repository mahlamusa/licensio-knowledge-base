<?php
/**
 * Template resolution.
 *
 * The block-theme-aware template resolver. `lisensio_kb_get_template()` asks
 * `wp_is_block_theme()` which paradigm the active theme uses and routes to
 * either the classic PHP template layer or the block template layer.
 *
 * Classic resolution order:
 *   1. `{stylesheet}/lisensio-kb/{template}.php`
 *   2. `{template}/lisensio-kb/{template}.php`   (parent theme)
 *   3. plugin `templates/{template}.php`
 *
 * Block resolution order:
 *   1. `{stylesheet}/templates/{template}.html`
 *   2. `{template}/templates/{template}.html`    (parent theme)
 *   3. plugin `block-templates/{template}.html`
 *
 * This file ships no templates. The classic set is #5 and the block set is
 * #6; both layers resolve through the functions defined here.
 *
 * Consumed by: #4, #5, #6, #7, #8.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * The directory a theme places its knowledge base overrides in.
 *
 * @since 1.0.0
 *
 * @return string The directory name, relative to the theme root, with no trailing slash.
 */
function lisensio_kb_template_path() {
    /**
     * Filter the theme sub-directory searched for template overrides.
     *
     * @since 1.0.0
     *
     * @param string $path The directory name relative to the theme root.
     */
    return untrailingslashit( (string) apply_filters( 'lisensio_template_path', 'lisensio-kb' ) );
}

/**
 * Whether the block template layer should be used for this request.
 *
 * @since 1.0.0
 *
 * @return bool True when the active theme is a block theme.
 */
function lisensio_kb_use_block_templates() {
    $is_block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();

    /**
     * Filter whether the block template layer is used.
     *
     * Return false to force the classic PHP templates under a block theme.
     *
     * @since 1.0.0
     *
     * @param bool $is_block_theme Whether the active theme is a block theme.
     */
    return (bool) apply_filters( 'lisensio_use_block_templates', $is_block_theme );
}

/**
 * Whether a template name is a safe, plain slug path.
 *
 * Both resolvers turn this value into a filesystem path that is included or
 * read, so anything other than slug segments is rejected outright. This blocks
 * `..` traversal, absolute paths, null bytes and dot-segments at the single
 * point every consumer goes through, rather than relying on each of them to
 * remember.
 *
 * @since 1.0.0
 *
 * @param string $template The requested template name.
 *
 * @return bool True when the name is a safe slug path.
 */
function lisensio_kb_is_safe_template_name( $template ) {
    return (bool) preg_match( '#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$#', (string) $template );
}

/**
 * Find a classic PHP template, theme overrides first.
 *
 * @since 1.0.0
 *
 * @param string $template Template name without extension, e.g. `single-article`.
 *
 * @return string Absolute path to the template, or an empty string when none exists.
 */
function lisensio_kb_locate_template( $template ) {
    $template = ltrim( (string) $template, '/' );

    if ( ! lisensio_kb_is_safe_template_name( $template ) ) {
        return '';
    }
    $file = lisensio_kb_template_path() . '/' . $template . '.php';

    $located = locate_template( array( $file ) );

    if ( '' === $located ) {
        $fallback = LISENSIO_KB_ABSPATH . 'templates/' . $template . '.php';
        $located  = is_readable( $fallback ) ? $fallback : '';
    }

    /**
     * Filter the located classic template path.
     *
     * @since 1.0.0
     *
     * @param string $located  Absolute path to the template, or an empty string.
     * @param string $template The requested template name.
     */
    return (string) apply_filters( 'lisensio_locate_template', $located, $template );
}

/**
 * Find a block template, theme overrides first.
 *
 * WordPress resolves theme overrides itself for full-page templates, but not
 * for the partials this function is used for, where nothing else looks in the
 * theme. The docblock at the top of this file has always advertised a theme
 * override step; this implements it rather than leaving #6 building against a
 * contract the code did not honour.
 *
 * Order: child theme `templates/`, parent theme `templates/`, then the
 * plugin's own `block-templates/`.
 *
 * @since 1.0.0
 *
 * @param string $template Template slug without extension, e.g. `single-lisensio_article`.
 *
 * @return string Absolute path to the block template, or an empty string when none exists.
 */
function lisensio_kb_locate_block_template( $template ) {
    $template = ltrim( (string) $template, '/' );

    if ( ! lisensio_kb_is_safe_template_name( $template ) ) {
        return '';
    }

    $path       = '';
    $relative   = 'templates/' . $template . '.html';
    $theme_dirs = array();

    if ( function_exists( 'get_stylesheet_directory' ) ) {
        $theme_dirs[] = get_stylesheet_directory();
    }

    if ( function_exists( 'get_template_directory' ) ) {
        $theme_dirs[] = get_template_directory();
    }

    foreach ( array_unique( array_filter( $theme_dirs ) ) as $theme_dir ) {
        $candidate = trailingslashit( $theme_dir ) . $relative;

        if ( is_readable( $candidate ) ) {
            $path = $candidate;
            break;
        }
    }

    if ( '' === $path ) {
        $path = LISENSIO_KB_ABSPATH . 'block-templates/' . $template . '.html';
    }

    /**
     * Filter the located block template path.
     *
     * @since 1.0.0
     *
     * @param string $path     Absolute path to the block template, or an empty string.
     * @param string $template The requested template slug.
     */
    return (string) apply_filters( 'lisensio_locate_block_template', is_readable( $path ) ? $path : '', $template );
}

/**
 * Render a knowledge base template.
 *
 * Routes to the block template layer under a block theme and to the classic
 * PHP layer otherwise. When the block layer has no template for the slug the
 * classic layer is used, so a block theme still renders on a plugin build
 * where only the PHP templates exist.
 *
 * Classic templates receive the arguments as a single `$args` array; there is
 * deliberately no `extract()`.
 *
 * @since 1.0.0
 *
 * @param string               $template Template name without extension.
 * @param array<string, mixed> $args     Values made available to the template as `$args`.
 * @param bool                 $display  Whether to print the markup. Pass false to return it.
 *
 * @return string The rendered markup. An empty string when printed or when no template was found.
 */
function lisensio_kb_get_template( $template, $args = array(), $display = true ) {
    $template = (string) $template;
    $args     = is_array( $args ) ? $args : array();
    $markup   = '';

    if ( lisensio_kb_use_block_templates() ) {
        $block_template = lisensio_kb_locate_block_template( $template );

        if ( '' !== $block_template ) {
            $markup = do_blocks( (string) file_get_contents( $block_template ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        }
    }

    if ( '' === $markup ) {
        $located = lisensio_kb_locate_template( $template );

        if ( '' !== $located ) {
            ob_start();

            /**
             * Fires before a classic knowledge base template is included.
             *
             * @since 1.0.0
             *
             * @param string               $template The template name.
             * @param string               $located  The absolute path being included.
             * @param array<string, mixed> $args     The template arguments.
             */
            do_action( 'lisensio_before_template', $template, $located, $args );

            include $located;

            /**
             * Fires after a classic knowledge base template is included.
             *
             * @since 1.0.0
             *
             * @param string               $template The template name.
             * @param string               $located  The absolute path that was included.
             * @param array<string, mixed> $args     The template arguments.
             */
            do_action( 'lisensio_after_template', $template, $located, $args );

            $markup = (string) ob_get_clean();
        }
    }

    /**
     * Filter the rendered markup of a knowledge base template.
     *
     * @since 1.0.0
     *
     * @param string               $markup   The rendered markup.
     * @param string               $template The template name.
     * @param array<string, mixed> $args     The template arguments.
     */
    $markup = (string) apply_filters( 'lisensio_get_template', $markup, $template, $args );

    if ( ! $display ) {
        return $markup;
    }

    echo $markup; // phpcs:ignore WordPress.Security.EscapeOutputWithCast.OutputNotEscaped, WordPress.Security.EscapeOutput.OutputNotEscaped -- Template output is escaped inside the template.

    return '';
}
