<?php
/**
 * Test double for wp-admin/includes/upgrade.php.
 *
 * The database installer and the vendored query builder both require this
 * file before calling dbDelta(). The stub records the statements it is given
 * so a test can assert on the schema without a database.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

if ( ! function_exists( 'dbDelta' ) ) {
    /**
     * Record a schema statement instead of executing it.
     *
     * @since 1.0.0
     *
     * @param string|string[] $queries One or more CREATE TABLE statements.
     * @param bool            $execute Ignored by the stub.
     *
     * @return array<string, string> Always an empty report.
     */
    function dbDelta( $queries = '', $execute = true ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid, Generic.CodeAnalysis.UnusedFunctionParameter
        if ( ! isset( $GLOBALS['lisensio_kb_delta_calls'] ) || ! is_array( $GLOBALS['lisensio_kb_delta_calls'] ) ) {
            $GLOBALS['lisensio_kb_delta_calls'] = array();
        }

        $GLOBALS['lisensio_kb_delta_calls'][] = $queries;

        return array();
    }
}
