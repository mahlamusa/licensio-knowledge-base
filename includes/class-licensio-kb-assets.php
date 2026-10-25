<?php
/**
 * Build asset loader.
 *
 * Resolves built assets for the two build pipelines the plugin uses:
 *
 * - Vite, for the three Vue admin apps in `apps/`. In development the assets
 *   come from the Vite dev server on port 6913, gated by the `HMR_DEV`
 *   constant being set to `licensio-kb`. In production they are resolved
 *   through `dist/.vite/manifest.json`.
 * - `wp-scripts`, for the editor blocks in `blocks/`, whose dependencies and
 *   cache-busting version come from the generated `index.asset.php` files.
 *
 * Both pipelines emit into `dist/`.
 *
 * Consumed by: #6, #7, #8, #9, #10.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues Vite and wp-scripts build output.
 *
 * @since 1.0.0
 */
class Licensio_KB_Assets {

    /**
     * The value `HMR_DEV` must hold for this plugin to use the dev server.
     *
     * @since 1.0.0
     * @var string
     */
    const HMR_KEY = 'licensio-kb';

    /**
     * Default Vite dev server port.
     *
     * @since 1.0.0
     * @var string
     */
    const DEV_SERVER_PORT = '6913';

    /**
     * Default Vite dev server host.
     *
     * @since 1.0.0
     * @var string
     */
    const DEV_SERVER_HOST = 'localhost';

    /**
     * Parsed Vite manifest, or null before the first read.
     *
     * @since 1.0.0
     * @var array<string, mixed>|null
     */
    private $manifest = null;

    /**
     * Handles registered as ES modules.
     *
     * @since 1.0.0
     * @var string[]
     */
    private $module_handles = array();

    /**
     * Whether the module script filter has been attached.
     *
     * @since 1.0.0
     * @var bool
     */
    private $filtering_tags = false;

    /**
     * Memoized result of the HMR gate for this request.
     *
     * @since 1.0.0
     * @var bool|null
     */
    private $hmr_enabled = null;

    /**
     * The handle the shared frontend namespace is registered under.
     *
     * @since 1.0.0
     * @var string
     */
    const FRONTEND_NAMESPACE_HANDLE = 'licensio-kb';

    /**
     * Register the WordPress hooks.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'wp_enqueue_scripts', array( $this, 'register_frontend_namespace' ), 1 );
    }

    /**
     * Register the shared frontend namespace script.
     *
     * Registered, not enqueued: it costs nothing until a consumer declares it as
     * a dependency, at which point WordPress enqueues it ahead of them. Served
     * straight from `assets/js/` as a classic script rather than through the Vite
     * pipeline — see the file's own comment for why it must not be a module.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_frontend_namespace() {
        wp_register_script(
            self::FRONTEND_NAMESPACE_HANDLE,
            LICENSIO_KB_ASSETS_URL . 'js/licensio-kb.js',
            array(),
            LICENSIO_KB_VERSION,
            false
        );
    }

    /**
     * Whether the Vite dev server should be used for this request.
     *
     * Requires all three of: `HMR_DEV` set to `licensio-kb`, a non-production
     * environment, and a reachable dev server. Memoized, because enqueue_app()
     * asks once per app and the reachability check opens a socket.
     *
     * @since 1.0.0
     *
     * @return bool True when HMR assets should be enqueued.
     */
    public function is_hmr_enabled() {
        if ( null === $this->hmr_enabled ) {
            $this->hmr_enabled = $this->probe_hmr();
        }

        return $this->hmr_enabled;
    }

    /**
     * Work out whether the dev server should be used, ignoring the memo.
     *
     * The environment check is not redundant with `HMR_DEV`. The socket probe
     * runs on the SERVER, but the URL it gates is fetched by the BROWSER, so it
     * says nothing about what the browser will reach. If `HMR_DEV` were left in
     * a production `wp-config.php` and anything at all were listening on the
     * dev port server-side, every administrator's browser would be told to load
     * and execute whatever was listening on that port on *their own* machine,
     * inside the wp-admin origin. `wp_get_environment_type()` is the signal that
     * does not survive a production deploy.
     *
     * @since 1.0.0
     *
     * @return bool True when HMR assets should be enqueued.
     */
    private function probe_hmr() {
        if ( ! defined( 'HMR_DEV' ) || self::HMR_KEY !== HMR_DEV ) {
            return false;
        }

        if ( function_exists( 'wp_get_environment_type' ) && 'production' === wp_get_environment_type() ) {
            return false;
        }

        $host = $this->dev_server_host();
        $port = (int) $this->dev_server_port();

        $errno   = 0;
        $errstr  = '';
        $socket  = @fsockopen( $host, $port, $errno, $errstr, 0.2 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen
        $running = false !== $socket;

        if ( $running ) {
            fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        }

        return $running;
    }

    /**
     * The Vite dev server host.
     *
     * @since 1.0.0
     *
     * @return string The host name.
     */
    public function dev_server_host() {
        /**
         * Filter the Vite dev server host.
         *
         * @since 1.0.0
         *
         * @param string $host The host name.
         */
        return (string) apply_filters( 'licensio_dev_server_host', self::DEV_SERVER_HOST );
    }

    /**
     * The Vite dev server port.
     *
     * @since 1.0.0
     *
     * @return string The port number as a string.
     */
    public function dev_server_port() {
        /**
         * Filter the Vite dev server port.
         *
         * @since 1.0.0
         *
         * @param string $port The port number.
         */
        return (string) apply_filters( 'licensio_dev_server_port', self::DEV_SERVER_PORT );
    }

    /**
     * The origin the Vite dev server is served from.
     *
     * @since 1.0.0
     *
     * @return string The dev server origin, with no trailing slash.
     */
    public function dev_server_url() {
        return 'http://' . $this->dev_server_host() . ':' . $this->dev_server_port();
    }

    /**
     * The parsed Vite manifest.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The manifest, or an empty array when it is missing or unreadable.
     */
    public function manifest() {
        if ( is_array( $this->manifest ) ) {
            return $this->manifest;
        }

        $this->manifest = array();
        $path           = LICENSIO_KB_DIST_PATH . '.vite/manifest.json';

        if ( is_readable( $path ) ) {
            $contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

            if ( is_string( $contents ) ) {
                $decoded = json_decode( $contents, true );

                if ( is_array( $decoded ) ) {
                    $this->manifest = $decoded;
                }
            }
        }

        return $this->manifest;
    }

    /**
     * The manifest entry for a Vite source path.
     *
     * @since 1.0.0
     *
     * @param string $entry Source path relative to the plugin root, e.g. `apps/settings/index.ts`.
     *
     * @return array<string, mixed> The manifest chunk, or an empty array when the entry is unknown.
     */
    public function manifest_entry( $entry ) {
        $manifest = $this->manifest();

        return isset( $manifest[ $entry ] ) && is_array( $manifest[ $entry ] ) ? $manifest[ $entry ] : array();
    }

    /**
     * Every stylesheet an entry needs, including its shared chunks'.
     *
     * Vite attaches the CSS of a *shared* chunk to that chunk's own manifest
     * entry, not to the entry that imports it. Reading only the entry's own
     * `css` array therefore silently drops the styles of everything the entry
     * imports — and the moment two of the three admin apps import the same Vue
     * or Ant Design component, that is most of the stylesheet. The JavaScript
     * still works, because the browser resolves the module graph itself, so the
     * only symptom is an unstyled screen with nothing in any log.
     *
     * `imports` is walked recursively and the result deduplicated;
     * `dynamicImports` is deliberately not walked, because Vite's runtime loads
     * the CSS for a lazily imported chunk itself.
     *
     * @since 1.0.0
     *
     * @param string $entry Source path relative to the plugin root.
     *
     * @return string[] Stylesheet paths relative to `dist/`, in dependency order, deduplicated.
     */
    public function entry_styles( $entry ) {
        $seen = array();

        return array_values( array_unique( $this->collect_styles( (string) $entry, $seen ) ) );
    }

    /**
     * Recursively gather the stylesheets of a chunk and everything it imports.
     *
     * @since 1.0.0
     *
     * @param string              $entry The manifest key to walk.
     * @param array<string, bool> $seen  Keys already visited, to stop a cyclic import graph.
     *
     * @return string[] Stylesheet paths relative to `dist/`.
     */
    private function collect_styles( $entry, array &$seen ) {
        if ( isset( $seen[ $entry ] ) ) {
            return array();
        }

        $seen[ $entry ] = true;

        $chunk  = $this->manifest_entry( $entry );
        $styles = array();

        if ( ! empty( $chunk['imports'] ) && is_array( $chunk['imports'] ) ) {
            foreach ( $chunk['imports'] as $import ) {
                $styles = array_merge( $styles, $this->collect_styles( (string) $import, $seen ) );
            }
        }

        if ( ! empty( $chunk['css'] ) && is_array( $chunk['css'] ) ) {
            foreach ( $chunk['css'] as $file ) {
                $styles[] = (string) $file;
            }
        }

        return $styles;
    }

    /**
     * Enqueue a Vite-built admin app.
     *
     * In HMR mode this enqueues the Vite client and the raw source entry from
     * the dev server. Otherwise it reads the manifest and enqueues the built
     * chunk plus any CSS Vite extracted for it. An app that depends on
     * `wp-i18n` also gets the translations of the shared chunks it imports.
     *
     * @since 1.0.0
     *
     * @param string   $handle Script handle to register the entry under.
     * @param string   $entry  Source path relative to the plugin root, e.g. `apps/settings/index.ts`.
     * @param string[] $deps   Script dependencies.
     *
     * @return bool True when something was enqueued.
     */
    public function enqueue_app( $handle, $entry, $deps = array() ) {
        $this->attach_module_filter();

        if ( $this->is_hmr_enabled() ) {
            $this->module_handles[] = 'licensio-kb-vite-client';
            $this->module_handles[] = $handle;

            wp_enqueue_script( 'licensio-kb-vite-client', $this->dev_server_url() . '/@vite/client', array(), LICENSIO_KB_VERSION, true );
            wp_enqueue_script( $handle, $this->dev_server_url() . '/' . ltrim( $entry, '/' ), $deps, LICENSIO_KB_VERSION, true );

            return true;
        }

        $chunk = $this->manifest_entry( $entry );

        if ( empty( $chunk['file'] ) ) {
            return false;
        }

        $this->module_handles[] = $handle;

        wp_enqueue_script( $handle, LICENSIO_KB_DIST_URL . $chunk['file'], $deps, LICENSIO_KB_VERSION, true );

        foreach ( $this->entry_styles( $entry ) as $index => $css ) {
            wp_enqueue_style( $handle . '-' . $index, LICENSIO_KB_DIST_URL . $css, array(), LICENSIO_KB_VERSION );
        }

        if ( in_array( 'wp-i18n', (array) $deps, true ) ) {
            $this->add_chunk_translations( $handle, $entry );
        }

        return true;
    }

    /**
     * Every JavaScript chunk an entry imports, at any depth.
     *
     * @since 1.0.0
     *
     * @param string $entry Source path relative to the plugin root.
     *
     * @return string[] Chunk paths relative to `dist/`, deduplicated.
     */
    public function entry_chunks( $entry ) {
        $chunks  = array();
        $pending = array( (string) $entry );
        $seen    = array();

        while ( $pending ) {
            $key = (string) array_shift( $pending );

            if ( isset( $seen[ $key ] ) ) {
                continue;
            }

            $seen[ $key ] = true;
            $chunk        = $this->manifest_entry( $key );

            if ( $key !== $entry && ! empty( $chunk['file'] ) ) {
                $chunks[] = (string) $chunk['file'];
            }

            if ( ! empty( $chunk['imports'] ) && is_array( $chunk['imports'] ) ) {
                foreach ( $chunk['imports'] as $import ) {
                    $pending[] = (string) $import;
                }
            }
        }

        return array_values( array_unique( $chunks ) );
    }

    /**
     * Load the translations of the chunks an app imports.
     *
     * `wp_set_script_translations()` only loads the JSON file of the handle's
     * own script. Vite moves code that several apps share, such as the shared
     * components, into chunks the entry imports itself, and WordPress.org
     * writes one JSON file per built file, so the strings in those chunks
     * would never be translated. Each chunk is registered just long enough for
     * WordPress to find its JSON file from its URL, and the translations are
     * printed before the app the same way WordPress prints the entry's own.
     *
     * @since 1.0.0
     *
     * @param string $handle The app's script handle.
     * @param string $entry  Source path relative to the plugin root.
     *
     * @return void
     */
    private function add_chunk_translations( $handle, $entry ) {
        $domain = 'licensio-knowledge-base';

        foreach ( $this->entry_chunks( $entry ) as $index => $file ) {
            $chunk_handle = $handle . '-chunk-' . $index;

            wp_register_script( $chunk_handle, LICENSIO_KB_DIST_URL . $file, array(), LICENSIO_KB_VERSION, true );
            $translations = load_script_textdomain( $chunk_handle, $domain );
            wp_deregister_script( $chunk_handle );

            if ( ! is_string( $translations ) || '' === $translations ) {
                continue;
            }

            wp_add_inline_script(
                $handle,
                sprintf(
                    '( function( domain, translations ) { var localeData = translations.locale_data[ domain ] || translations.locale_data.messages; localeData[""].domain = domain; wp.i18n.setLocaleData( localeData, domain ); } )( "%s", %s );',
                    $domain,
                    $translations
                ),
                'before'
            );
        }
    }

    /**
     * Read a wp-scripts generated asset file.
     *
     * @since 1.0.0
     *
     * @param string $name The block or bundle name, e.g. `search`.
     *
     * @return array{dependencies: string[], version: string} The dependency list and version.
     */
    public function block_asset( $name ) {
        $fallback = array(
            'dependencies' => array(),
            'version'      => LICENSIO_KB_VERSION,
        );

        /*
         * The name becomes a path that is include()d, so it is held to a plain
         * slug. Defence in depth rather than a live hole — the path has to end
         * in a file literally named index.asset.php, and the name is supplied by
         * a developer, not a request — but this is the only include() in the
         * class and the guard costs one line.
         */
        if ( ! preg_match( '/^[a-z0-9-]+$/', (string) $name ) ) {
            return $fallback;
        }

        $path = LICENSIO_KB_DIST_PATH . 'blocks/' . $name . '/index.asset.php';

        if ( is_readable( $path ) ) {
            $asset = include $path;

            if ( is_array( $asset ) ) {
                return array(
                    'dependencies' => isset( $asset['dependencies'] ) ? (array) $asset['dependencies'] : array(),
                    'version'      => isset( $asset['version'] ) ? (string) $asset['version'] : LICENSIO_KB_VERSION,
                );
            }
        }

        return $fallback;
    }

    /**
     * The URL of a file inside the build output directory.
     *
     * @since 1.0.0
     *
     * @param string $relative_path Path relative to `dist/`.
     *
     * @return string The absolute URL.
     */
    public function dist_url( $relative_path ) {
        return LICENSIO_KB_DIST_URL . ltrim( $relative_path, '/' );
    }

    /**
     * Attach the filter that marks registered handles as ES modules.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function attach_module_filter() {
        if ( $this->filtering_tags ) {
            return;
        }

        add_filter( 'script_loader_tag', array( $this, 'add_module_type' ), 10, 2 );

        $this->filtering_tags = true;
    }

    /**
     * Mark this plugin's Vite scripts as ES modules.
     *
     * @since 1.0.0
     *
     * @param string $tag    The script tag markup.
     * @param string $handle The script handle.
     *
     * @return string The filtered script tag.
     */
    public function add_module_type( $tag, $handle ) {
        if ( ! in_array( $handle, $this->module_handles, true ) ) {
            return $tag;
        }

        if ( false !== strpos( $tag, ' type=' ) ) {
            return str_replace( array( " type='text/javascript'", ' type="text/javascript"' ), ' type="module"', $tag );
        }

        return str_replace( ' src=', ' type="module" src=', $tag );
    }
}
