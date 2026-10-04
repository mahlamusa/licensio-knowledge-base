<?php
/**
 * The pure parts of the import log.
 *
 * Writing, listing, filtering and clearing entries need a real table and are
 * covered by the BetterDocs integration suite. What can be proven without a
 * database lives here: the meta description, the disabled log and the CSV
 * escaping.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

/**
 * What the import log does without touching the database.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Import_Log_Test extends Lisensio_KB_Test_Case {

    /**
     * Render one CSV line through the log's private writer.
     *
     * The writer is private because only `to_csv()` uses it, but `to_csv()`
     * reads the table, and the escaping is worth proving on its own.
     *
     * @since 1.0.0
     *
     * @param array<int, mixed> $values The fields.
     *
     * @return string The CSV line.
     */
    private function csv_line( array $values ) {
        $method = new ReflectionMethod( 'Lisensio_KB_Import_Log', 'csv_line' );

        $method->setAccessible( true );

        return $method->invoke( null, $values );
    }

    /**
     * A meta entry carries the key, value, action and target.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_meta_entry_describes_one_meta_row() {
        $this->assertSame(
            array(
                'key'    => '_thumbnail_id',
                'value'  => '42',
                'action' => 'migrated',
                'target' => 'the same attachment',
            ),
            Lisensio_KB_Import_Log::meta_entry( '_thumbnail_id', 42, 'migrated', 'the same attachment' )
        );
    }

    /**
     * A short value is kept whole.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_meta_entry_keeps_a_value_of_exactly_the_limit() {
        $value = str_repeat( 'a', Lisensio_KB_Import_Log::VALUE_MAX_LENGTH );
        $entry = Lisensio_KB_Import_Log::meta_entry( 'k', $value, 'skipped' );

        $this->assertSame( $value, $entry['value'] );
        $this->assertSame( '', $entry['target'], 'The target is optional.' );
    }

    /**
     * A long value is cut to 200 characters, the last of them an ellipsis.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_meta_entry_truncates_a_long_value_to_200_characters() {
        $entry = Lisensio_KB_Import_Log::meta_entry( 'k', str_repeat( 'a', 500 ), 'not_migrated' );

        $this->assertSame( 200, mb_strlen( $entry['value'] ) );
        $this->assertSame( str_repeat( 'a', 199 ) . '…', $entry['value'] );
    }

    /**
     * Truncation counts characters, so a multibyte value is not cut mid-character.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_meta_entry_truncation_is_multibyte_safe() {
        $entry = Lisensio_KB_Import_Log::meta_entry( 'k', str_repeat( 'é', 250 ), 'skipped' );

        $this->assertSame( 200, mb_strlen( $entry['value'] ) );
        $this->assertSame( str_repeat( 'é', 199 ) . '…', $entry['value'] );
        $this->assertTrue( mb_check_encoding( $entry['value'], 'UTF-8' ) );
    }

    /**
     * A non-scalar value is logged as JSON, then truncated like any other.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_meta_entry_encodes_a_non_scalar_value_as_json() {
        $short = Lisensio_KB_Import_Log::meta_entry( 'k', array( 'a' => 1 ), 'skipped' );
        $long  = Lisensio_KB_Import_Log::meta_entry( 'k', array_fill( 0, 200, 'item' ), 'skipped' );

        $this->assertSame( '{"a":1}', $short['value'] );
        $this->assertSame( 200, mb_strlen( $long['value'] ) );
    }

    /**
     * A disabled log writes nothing, and needs no database to say so.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_disabled_log_writes_nothing() {
        unset( $GLOBALS['wpdb'] );

        $log = Lisensio_KB_Import_Log::disabled();

        $this->assertFalse( $log->entry( 'articles', 'article', 1, 2, 'Title', Lisensio_KB_Import_Log::STATUS_CREATED, 'Created.', array( 'a' => 1 ) ) );
        $this->assertSame( '', $log->run_id() );
    }

    /**
     * A log built with writes switched off behaves the same.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_log_constructed_disabled_writes_nothing() {
        unset( $GLOBALS['wpdb'] );

        $log = new Lisensio_KB_Import_Log( 'run-1', 'betterdocs', true, false );

        $this->assertFalse( $log->entry( 'run', 'run', 0, 0, '', Lisensio_KB_Import_Log::STATUS_INFO ) );
        $this->assertSame( 'run-1', $log->run_id() );
    }

    /**
     * Every CSV field is quoted, and a quote inside a field is doubled.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_csv_fields_are_quoted_and_inner_quotes_doubled() {
        $this->assertSame( "\"1\",\"say \"\"hi\"\"\",\"\",\"a,b\"\n", $this->csv_line( array( 1, 'say "hi"', '', 'a,b' ) ) );
    }

    /**
     * A field that a spreadsheet would run as a formula is neutralised.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_csv_fields_starting_with_a_formula_character_are_prefixed() {
        $line = $this->csv_line( array( '=SUM(A1)', '+1', '-1', '@cmd', 'safe=', ' =padded' ) );

        $this->assertSame( "\"'=SUM(A1)\",\"'+1\",\"'-1\",\"'@cmd\",\"safe=\",\" =padded\"\n", $line );
    }
}
