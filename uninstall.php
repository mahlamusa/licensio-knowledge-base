<?php
/**
 * Plugin uninstall.
 *
 * Runs when the plugin is deleted from the Plugins screen. The work itself
 * lives in Lisensio_KB_Uninstaller so the multisite loop and the data-retention
 * decision are unit-testable; this file is the guard and the entry point.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! class_exists( 'Lisensio_KB_Uninstaller' ) ) {
    require_once __DIR__ . '/lisensio-knowledge-base.php';
}

Lisensio_KB_Uninstaller::run();
