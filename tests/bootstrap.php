<?php
/**
 * PHPUnit bootstrap.
 *
 * Boots the plugin classes without a WordPress installation. WordPress
 * functions are supplied per test by Brain Monkey; the constants the plugin
 * would normally receive from its bootstrap file are defined here.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

$lisensio_kb_root = dirname( __DIR__ );

require_once $lisensio_kb_root . '/vendor/autoload.php';

define( 'ABSPATH', $lisensio_kb_root . '/tests/fixtures/wordpress/' );
define( 'LISENSIO_KB_VERSION', '1.0.0' );
define( 'LISENSIO_KB_DB_VERSION', '1.0.0' );
define( 'LISENSIO_KB_MIN_PHP', '7.4' );
define( 'LISENSIO_KB_MIN_WP', '6.4' );
define( 'LISENSIO_KB_PLUGIN_BASENAME', 'lisensio-knowledge-base/lisensio-knowledge-base.php' );
define( 'LISENSIO_KB_ABSPATH', $lisensio_kb_root . '/' );
define( 'LISENSIO_KB_PLUGIN_URL', 'https://example.test/wp-content/plugins/lisensio-knowledge-base/' );
define( 'LISENSIO_KB_ASSETS_URL', LISENSIO_KB_PLUGIN_URL . 'assets/' );
define( 'LISENSIO_KB_DIST_URL', LISENSIO_KB_PLUGIN_URL . 'dist/' );
define( 'LISENSIO_KB_DIST_PATH', LISENSIO_KB_ABSPATH . 'dist/' );
define( 'LISENSIO_KB_REST_NAMESPACE', 'lisensio-kb/v1' );

/*
 * The output-format constants WordPress defines alongside wpdb. The vendored
 * query builder names them in its default parameters, so any code path that
 * reads a row fatals without them.
 */
defined( 'OBJECT' ) || define( 'OBJECT', 'OBJECT' );
defined( 'OBJECT_K' ) || define( 'OBJECT_K', 'OBJECT_K' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'ARRAY_N' ) || define( 'ARRAY_N', 'ARRAY_N' );

/*
 * WP-CLI's own gate. `wp lisensio-kb reindex` is a shipped entry point whose
 * registration is guarded by this constant, so without it the command is the
 * one piece of the plugin nothing can execute.
 */
defined( 'WP_CLI' ) || define( 'WP_CLI', true );

defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

require_once LISENSIO_KB_ABSPATH . 'tests/fixtures/class-wp-error.php';
require_once LISENSIO_KB_ABSPATH . 'tests/fixtures/class-wp-post.php';
require_once LISENSIO_KB_ABSPATH . 'tests/fixtures/class-wp-term.php';
require_once LISENSIO_KB_ABSPATH . 'tests/fixtures/class-wp-block-template.php';
require_once LISENSIO_KB_ABSPATH . 'tests/fixtures/class-wp-role.php';
require_once LISENSIO_KB_ABSPATH . 'tests/fixtures/class-wp-screen.php';
require_once LISENSIO_KB_ABSPATH . 'tests/fixtures/class-wp-rest-controller.php';
require_once LISENSIO_KB_ABSPATH . 'tests/fixtures/class-wp-rest-request.php';
require_once LISENSIO_KB_ABSPATH . 'tests/fixtures/class-lisensio-kb-test-wpdb.php';
require_once LISENSIO_KB_ABSPATH . 'includes/lisensio-kb-template-functions.php';
require_once LISENSIO_KB_ABSPATH . 'includes/lisensio-kb-hooks.php';
