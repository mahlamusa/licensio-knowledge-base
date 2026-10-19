<?php
/**
 * A build asset loader that records instead of enqueueing.
 *
 * Licensio_KB_Assets::enqueue_app() reads `dist/.vite/manifest.json` and calls
 * wp_enqueue_script(). A screen test wants neither: what it is proving is which
 * screens ask for the bundle and which do not, and a loader that answered false
 * because the plugin happened not to be built would make every one of those
 * assertions pass for the wrong reason.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/**
 * Records the apps a screen asked to load.
 *
 * @since 1.0.0
 */
class Licensio_KB_Recording_Assets extends Licensio_KB_Assets {

    /**
     * The Vite entries requested, keyed by script handle.
     *
     * @since 1.0.0
     * @var array<string, string>
     */
    public $recorded = array();

    /**
     * Record an enqueue instead of performing one.
     *
     * @since 1.0.0
     *
     * @param string   $handle The script handle.
     * @param string   $entry  The Vite entry, relative to the plugin root.
     * @param string[] $deps   The script dependencies.
     *
     * @return bool Always true, so the calling screen carries on to localise.
     */
    public function enqueue_app( $handle, $entry, $deps = array() ) {
        unset( $deps );

        $this->recorded[ (string) $handle ] = (string) $entry;

        return true;
    }
}
