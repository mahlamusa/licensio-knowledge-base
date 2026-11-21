<?php
/**
 * An asset loader double whose manifest has a file for every entry.
 *
 * @package Licensio_KB
 * @since   1.1.0
 */

/**
 * An asset loader that reports every entry as built.
 *
 * Tests that need the content manager apps to be loadable, without a real
 * build manifest on disk, pass this to the content manager.
 *
 * @since 1.1.0
 */
class Licensio_KB_Built_Assets extends Licensio_KB_Assets {

    /**
     * The manifest chunk of an entry.
     *
     * @since 1.1.0
     *
     * @param string $entry The entry.
     *
     * @return array<string, mixed> A chunk with a file, for every entry.
     */
    public function manifest_entry( $entry ) {
        unset( $entry );

        return array( 'file' => 'apps/x.js' );
    }
}
