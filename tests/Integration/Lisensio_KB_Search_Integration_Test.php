<?php
/**
 * Search relevance, against a real database server.
 *
 * Relevance is the one thing a `$wpdb` double cannot answer. A double can be
 * told that `MATCH ... AGAINST` returned three rows; it cannot tell you whether
 * MySQL really ranked the article whose TITLE matched above the one whose BODY
 * happened to mention the word, because that answer lives in the server's
 * collation, its FULLTEXT index and its ORDER BY — none of which a stub has.
 *
 * Every test here therefore drives the shipped drivers, through the shipped
 * query facade, against a real table built by the shipped installer. The only
 * substitution is `$wpdb` itself, replaced by a mysqli adapter so the plugin's
 * own SQL reaches a real server.
 *
 * The suite runs against MySQL 8.0, MySQL 5.7 and MariaDB 10.6 in CI.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

/*
 * This suite connects to a real database on purpose: it exists precisely because
 * the unit suite's doubles cannot answer how a real server responds to real DDL.
 * The WordPress Database API is therefore not available or appropriate here.
 */
// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery


require_once __DIR__ . '/Lisensio_KB_Integration_Test_Case.php';
require_once __DIR__ . '/class-lisensio-kb-live-wpdb.php';

/**
 * Drives both search drivers at a live server.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Search_Integration_Test extends Lisensio_KB_Integration_Test_Case {

    /**
     * How many timed samples the performance budget is judged on.
     *
     * @since 1.0.0
     * @var int
     */
    const PERF_SAMPLES = 5;

    /**
     * The median search budget, in milliseconds.
     *
     * @since 1.0.0
     * @var int
     */
    const PERF_BUDGET_MS = 300;

    /**
     * The table prefix used for the scratch schema.
     *
     * @since 1.0.0
     * @var string
     */
    const PREFIX = 'lkbsearch_';

    /*
     * The three article IDs below are chosen so that the expected relevance
     * order — TITLE, TERMS, CONTENT, or 102, 105, 101 — is neither ascending nor
     * descending in the ID. That is not decoration.
     *
     * An earlier revision numbered them 101, 102, 103, so the expected ranking
     * WAS ascending ID order. Replacing the driver's `ORDER BY field_score DESC`
     * with `ORDER BY article_id ASC` — relevance ranking entirely gone — then
     * produced exactly the expected answer, and the whole suite stayed green.
     * The same coincidence carried the paging test and the both-drivers-agree
     * test, which compares the drivers to each other and is equally happy when
     * they agree on a wrong shared order.
     *
     * Three orderings a broken driver might fall back to must all be ruled out,
     * and with these IDs and the seeded dates they are:
     *
     *   article_id ASC   -> 101, 102, 105
     *   article_id DESC  -> 105, 102, 101
     *   updated_at DESC  -> 101, 105, 102
     *   expected         -> 102, 105, 101
     *
     * test_the_expected_ranking_cannot_be_produced_by_row_order() asserts this
     * property directly, so renumbering these constants back into a sequence
     * fails loudly instead of quietly disarming every ranking test in the file.
     */

    /**
     * The article whose TITLE carries the query word.
     *
     * @since 1.0.0
     * @var int
     */
    const TITLE_MATCH = 102;

    /**
     * The article whose TERMS carry the query word.
     *
     * @since 1.0.0
     * @var int
     */
    const TERMS_MATCH = 105;

    /**
     * The article whose BODY carries the query word.
     *
     * @since 1.0.0
     * @var int
     */
    const CONTENT_MATCH = 101;

    /**
     * The in-memory options table.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private $options = array();

    /**
     * Build the schema, add the FULLTEXT key and seed the index.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['wpdb'] = new Lisensio_KB_Live_WPDB( $this->db, self::PREFIX );

        $this->options = array(
            Lisensio_KB_DB_Installer::FULLTEXT_OPTION => 'yes',
        );

        Brain\Monkey\Functions\when( 'get_option' )->alias(
            function ( $name, $fallback = false ) {
                return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
            }
        );

        Brain\Monkey\Functions\when( 'update_option' )->alias(
            function ( $name, $value ) {
                $this->options[ $name ] = $value;

                return true;
            }
        );

        Brain\Monkey\Functions\when( 'delete_option' )->alias(
            function ( $name ) {
                unset( $this->options[ $name ] );

                return true;
            }
        );

        Brain\Monkey\Functions\when( 'current_time' )->alias(
            function ( $format, $gmt = 0 ) {
                unset( $format, $gmt );

                return gmdate( 'Y-m-d H:i:s' );
            }
        );

        Brain\Monkey\Functions\when( 'wp_strip_all_tags' )->alias(
            function ( $value ) {
                return strip_tags( (string) $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
            }
        );

        Brain\Monkey\Functions\when( 'sanitize_text_field' )->alias(
            function ( $value ) {
                return is_string( $value ) ? trim( strip_tags( $value ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
            }
        );

        Brain\Monkey\Functions\when( 'wp_unslash' )->returnArg();
        Brain\Monkey\Functions\when( 'wp_generate_password' )->justReturn( 'test-salt' );

        Brain\Monkey\Functions\when( 'sanitize_title' )->alias(
            function ( $value ) {
                $value = strtolower( strip_tags( (string) $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags

                return trim( (string) preg_replace( '/[^a-z0-9_-]+/', '-', $value ), '-' );
            }
        );

        Brain\Monkey\Functions\when( 'sanitize_hex_color' )->returnArg();
        Brain\Monkey\Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

        $this->install();
        $this->seed();
    }

    /**
     * Create the plugin's tables and add the FULLTEXT key.
     *
     * Uses the installer's own schema strings and its own private ALTER, so a
     * schema change that broke search would break these tests too.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function install() {
        $schemas = Lisensio_KB_DB_Installer::get_schemas(
            self::PREFIX,
            'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );

        foreach ( $schemas as $name => $sql ) {
            $this->assertTrue(
                (bool) $this->db->query( $sql ),
                sprintf( 'Schema %1$s failed on %2$s: %3$s', $name, $this->server_version(), $this->db->error )
            );
        }

        $add = new ReflectionMethod( 'Lisensio_KB_DB_Installer', 'add_fulltext_index' );
        $add->setAccessible( true );
        $add->invoke( null );

        $this->assertTrue(
            Lisensio_KB_DB_Installer::detect_fulltext_index(),
            sprintf( 'FULLTEXT is unavailable on %s, so the ranking tests would prove nothing.', $this->server_version() )
        );
    }

    /**
     * Seed three articles, each carrying the query word in a different field.
     *
     * The word is `licence`: seven characters, so it clears every server's
     * minimum indexed word length, and absent from the InnoDB and MyISAM
     * stopword lists, so no server silently drops it. It appears exactly once
     * across the three rows, in the title of one, the terms of another and the
     * body of the third — which is what makes the expected order a statement
     * about the weighting and nothing else.
     *
     * `updated_at` runs in the OPPOSITE order to the expected ranking, and that
     * is deliberate. Both drivers fall back to recency once relevance ties, and
     * with the word in every row InnoDB's own relevance score is identical for
     * all three — its IDF term is log10(3/3), which is zero. Seeding the rows in
     * date order therefore let a driver that had lost its weighting entirely
     * still return the right answer by accident. Reversing the dates means the
     * expected order can only come from the weighting.
     *
     * The article IDs carry the same requirement, for the same reason, and are
     * chosen in the constant block above so that the expected order is neither
     * ascending nor descending in the ID. Fixing only the date half of this
     * left `ORDER BY article_id ASC` passing the entire suite.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function seed() {
        $this->insert_row(
            self::TITLE_MATCH,
            'Licence activation guide',
            'Getting started',
            'This article explains how activation works once you have finished the installer.',
            '2026-01-01 00:00:00'
        );

        $this->insert_row(
            self::TERMS_MATCH,
            'Activation troubleshooting',
            'Licence, Support',
            'Steps to resolve activation problems reported by customers during onboarding.',
            '2026-02-01 00:00:00'
        );

        $this->insert_row(
            self::CONTENT_MATCH,
            'Server deployment',
            'Hosting',
            'Read the licence terms carefully before deploying the server to production.',
            '2026-03-01 00:00:00'
        );
    }

    /**
     * Write one row into the search index.
     *
     * @since 1.0.0
     *
     * @param int    $id         The article ID.
     * @param string $title      The title.
     * @param string $terms      The term names.
     * @param string $content    The body text.
     * @param string $updated_at When the row was last written.
     *
     * @return void
     */
    private function insert_row( $id, $title, $terms, $content, $updated_at = '2026-01-01 00:00:00' ) {
        $sql = sprintf(
            "INSERT INTO %slisensio_search_index (article_id, title, excerpt, content, terms, updated_at)
             VALUES (%d, '%s', '%s', '%s', '%s', '%s')",
            self::PREFIX,
            (int) $id,
            $this->db->real_escape_string( $title ),
            $this->db->real_escape_string( substr( $content, 0, 120 ) ),
            $this->db->real_escape_string( $content ),
            $this->db->real_escape_string( $terms ),
            $this->db->real_escape_string( $updated_at )
        );

        $this->assertTrue( (bool) $this->db->query( $sql ), $this->db->error );
    }

    /**
     * A query facade pinned to one driver.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Search_Driver|null $driver The driver to pin, or null to let the facade choose.
     *
     * @return Lisensio_KB_Search_Query The facade.
     */
    private function facade( $driver = null ) {
        $query = new Lisensio_KB_Search_Query();
        $query->set_driver( $driver );

        return $query;
    }

    /**
     * Every driver the ranking contract applies to.
     *
     * @since 1.0.0
     *
     * @return array<string, array{0: Lisensio_KB_Search_Driver}> The drivers, keyed by name.
     */
    public function driver_provider() {
        return array(
            'fulltext' => array( new Lisensio_KB_Search_Fulltext_Driver() ),
            'like'     => array( new Lisensio_KB_Search_Like_Driver() ),
        );
    }

    /**
     * The expected ranking cannot be produced by any ordering of the row IDs.
     *
     * A guard on the fixture rather than on the code. Every ranking assertion in
     * this file compares against one fixed sequence, and that sequence is only
     * evidence of ranking if no ordering a broken driver might fall back to
     * produces it by accident.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_expected_ranking_cannot_be_produced_by_row_order() {
        $expected = array( self::TITLE_MATCH, self::TERMS_MATCH, self::CONTENT_MATCH );

        $ascending = $expected;
        sort( $ascending );

        $descending = $expected;
        rsort( $descending );

        $this->assertNotSame(
            $ascending,
            $expected,
            'The expected ranking is ascending article_id order, so ORDER BY article_id ASC would satisfy every ranking test in this file.'
        );

        $this->assertNotSame(
            $descending,
            $expected,
            'The expected ranking is descending article_id order, so ORDER BY article_id DESC would satisfy every ranking test in this file.'
        );

        /*
         * The seeded dates run oldest-first in expected-ranking order, so the
         * recency fallback both drivers use once relevance ties produces the
         * reverse. Asserted here rather than trusted from the seed method.
         */
        $recent_first = array( self::CONTENT_MATCH, self::TERMS_MATCH, self::TITLE_MATCH );

        $this->assertNotSame(
            $recent_first,
            $expected,
            'The expected ranking is updated_at DESC order, so a driver that had lost its ranking would still pass.'
        );
    }

    /**
     * The recency fallback really does disagree with the expected ranking.
     *
     * Reads the seeded dates back from the server rather than restating the
     * seed method, so an edit to the fixture that quietly aligned recency with
     * relevance is caught here instead of silently weakening every ranking
     * assertion below.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_seeded_recency_order_is_not_the_expected_ranking() {
        $rows = $this->db->query(
            'SELECT article_id FROM ' . self::PREFIX . 'lisensio_search_index
             WHERE article_id IN (' . self::TITLE_MATCH . ', ' . self::TERMS_MATCH . ', ' . self::CONTENT_MATCH . ')
             ORDER BY updated_at DESC'
        );

        $order = array();

        while ( $row = $rows->fetch_object() ) {
            $order[] = (int) $row->article_id;
        }

        $this->assertSame(
            array( self::CONTENT_MATCH, self::TERMS_MATCH, self::TITLE_MATCH ),
            $order,
            'The seeded dates must run opposite to the expected ranking.'
        );
    }

    /**
     * A title match outranks a tag match, which outranks a body match.
     *
     * The acceptance criterion for this issue, asserted against a real server
     * for both drivers. It is a single query over three rows that differ only in
     * WHICH field carries the word, so the resulting order is a direct readout
     * of the title x3, tags x2, content x1 weighting.
     *
     * @since 1.0.0
     *
     * @dataProvider driver_provider
     *
     * @param Lisensio_KB_Search_Driver $driver The driver under test.
     *
     * @return void
     */
    public function test_a_title_match_outranks_a_tag_match_which_outranks_a_body_match( $driver ) {
        $found = $this->facade( $driver )->search( 'licence', array( 'limit' => 10 ) );

        $this->assertSame(
            array( self::TITLE_MATCH, self::TERMS_MATCH, self::CONTENT_MATCH ),
            $found['ids'],
            sprintf(
                'The %1$s driver ranked %2$s on %3$s.',
                $driver->name(),
                implode( ', ', $found['ids'] ),
                $this->server_version()
            )
        );

        $this->assertSame( 3, $found['total'] );
        $this->assertSame( $driver->name(), $found['driver'] );
    }

    /**
     * The two drivers rank the same corpus identically.
     *
     * A fallback that returns some other order is a different product for the
     * sites that land on it. This is what makes "no visible feature loss" a
     * measured claim rather than a hope.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_both_drivers_agree_on_the_ranking() {
        $fulltext = $this->facade( new Lisensio_KB_Search_Fulltext_Driver() )->search( 'licence' );
        $like     = $this->facade( new Lisensio_KB_Search_Like_Driver() )->search( 'licence' );

        $this->assertSame( $fulltext['ids'], $like['ids'], 'The drivers disagree on the ranking.' );
        $this->assertSame( $fulltext['total'], $like['total'], 'The drivers disagree on the total.' );
    }

    /**
     * The last token is prefix-expanded, so a half-typed word still matches.
     *
     * `licen` is not a word in any of the three rows. Only the trailing `*` the
     * FULLTEXT driver appends makes it match, which is what a type-ahead needs
     * between keystrokes.
     *
     * @since 1.0.0
     *
     * @dataProvider driver_provider
     *
     * @param Lisensio_KB_Search_Driver $driver The driver under test.
     *
     * @return void
     */
    public function test_a_partial_final_word_still_matches( $driver ) {
        $found = $this->facade( $driver )->search( 'licen', array( 'limit' => 10 ) );

        $this->assertSame(
            array( self::TITLE_MATCH, self::TERMS_MATCH, self::CONTENT_MATCH ),
            $found['ids'],
            sprintf( 'The %s driver did not prefix-match a partial word.', $driver->name() )
        );
    }

    /**
     * The generated boolean expression really does carry the prefix operator.
     *
     * Asserted separately from the result, because a driver that ignored the
     * expansion entirely and fell back to a substring match would still pass the
     * test above.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_boolean_expression_prefix_expands_only_the_last_token() {
        $this->assertSame(
            '+licence +activ*',
            Lisensio_KB_Search_Query::boolean_query( array( 'licence', 'activ' ) )
        );
    }

    /**
     * Boolean operators in visitor input never reach the server.
     *
     * `-activation` in a boolean-mode query means "and must not contain
     * activation", which would let any visitor delete results from everyone
     * else's search by pasting a link. The operators are stripped before the
     * term is tokenised, so the query behaves exactly as the same words without
     * them.
     *
     * @since 1.0.0
     *
     * @dataProvider driver_provider
     *
     * @param Lisensio_KB_Search_Driver $driver The driver under test.
     *
     * @return void
     */
    public function test_boolean_operators_are_stripped_from_visitor_input( $driver ) {
        $hostile = $this->facade( $driver )->search( '+licence -activation', array( 'limit' => 10 ) );
        $plain   = $this->facade( $driver )->search( 'licence activation', array( 'limit' => 10 ) );

        $this->assertSame( $plain['ids'], $hostile['ids'] );
        $this->assertNotEmpty( $hostile['ids'], 'The exclusion operator emptied the result set.' );
        $this->assertContains( self::TITLE_MATCH, $hostile['ids'] );

        /*
         * The term is also what gets written to the search log and echoed back
         * into the search field, so the operators must be gone from it too and
         * not merely dropped on the way to the tokeniser.
         */
        $this->assertSame( 'licence activation', $hostile['term'] );
    }

    /**
     * A term made only of operators returns nothing, and raises nothing.
     *
     * Unstripped, `*` alone is a syntax error in boolean mode and MySQL raises
     * it on a public, unauthenticated endpoint.
     *
     * @since 1.0.0
     *
     * @dataProvider driver_provider
     *
     * @param Lisensio_KB_Search_Driver $driver The driver under test.
     *
     * @return void
     */
    public function test_a_term_of_only_operators_is_harmless( $driver ) {
        $found = $this->facade( $driver )->search( '+*~<>()"@-', array( 'limit' => 10 ) );

        $this->assertSame( array(), $found['ids'] );
        $this->assertSame( 0, $found['total'] );
        $this->assertSame( 'none', $found['driver'] );
    }

    /**
     * Recording FULLTEXT as unavailable really does select the LIKE driver.
     *
     * Drives the shipped selection path rather than pinning a driver by hand, so
     * it covers the wiring a host without FULLTEXT actually goes through.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_host_without_fulltext_falls_back_to_like_and_still_ranks() {
        $this->options[ Lisensio_KB_DB_Installer::FULLTEXT_OPTION ] = 'no';

        $found = ( new Lisensio_KB_Search_Query() )->search( 'licence', array( 'limit' => 10 ) );

        $this->assertSame( 'like', $found['driver'] );
        $this->assertSame(
            array( self::TITLE_MATCH, self::TERMS_MATCH, self::CONTENT_MATCH ),
            $found['ids'],
            'The LIKE fallback returned an unranked or empty result set.'
        );
    }

    /**
     * Every token must appear, on both drivers.
     *
     * @since 1.0.0
     *
     * @dataProvider driver_provider
     *
     * @param Lisensio_KB_Search_Driver $driver The driver under test.
     *
     * @return void
     */
    public function test_every_token_is_required( $driver ) {
        $found = $this->facade( $driver )->search( 'licence hosting', array( 'limit' => 10 ) );

        $this->assertSame(
            array( self::CONTENT_MATCH ),
            $found['ids'],
            sprintf( 'The %s driver did not require every token.', $driver->name() )
        );
    }

    /**
     * Paging cuts the ranked list rather than re-ordering it.
     *
     * @since 1.0.0
     *
     * @dataProvider driver_provider
     *
     * @param Lisensio_KB_Search_Driver $driver The driver under test.
     *
     * @return void
     */
    public function test_paging_walks_the_ranked_list( $driver ) {
        $first  = $this->facade( $driver )->search(
            'licence',
            array(
                'limit'  => 1,
                'offset' => 0,
            )
        );
        $second = $this->facade( $driver )->search(
            'licence',
            array(
                'limit'  => 1,
                'offset' => 1,
            )
        );

        $this->assertSame( array( self::TITLE_MATCH ), $first['ids'] );
        $this->assertSame( array( self::TERMS_MATCH ), $second['ids'] );
        $this->assertSame( 3, $first['total'], 'The total must count every match, not the page.' );
    }

    /**
     * The index writer's own row lands and is findable.
     *
     * Drives Lisensio_KB_Search_Index::delete() and the upsert path through the
     * query builder, which is where the table-prefix contract between the plugin
     * and the vendored builder is actually exercised.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_removing_an_article_removes_it_from_the_results() {
        $index = new Lisensio_KB_Search_Index();

        $this->assertTrue( $index->delete( self::TITLE_MATCH ) );

        $found = $this->facade( new Lisensio_KB_Search_Fulltext_Driver() )->search( 'licence' );

        $this->assertSame( array( self::TERMS_MATCH, self::CONTENT_MATCH ), $found['ids'] );
        $this->assertSame( 2, $found['total'] );
        $this->assertSame( 2, $index->count() );
    }

    /**
     * Render timing samples for a human reading a failure.
     *
     * The individual samples matter when the budget is missed: a single slow
     * run among fast ones is a busy runner, whereas every sample being slow is
     * a regression, and the message should let a reader tell them apart.
     *
     * @since 1.0.0
     *
     * @param float[] $samples Elapsed times in milliseconds.
     *
     * @return string Comma-separated, one decimal place.
     */
    private static function format_samples( array $samples ) {
        $formatted = array();

        foreach ( $samples as $ms ) {
            $formatted[] = sprintf( '%.1f', $ms );
        }

        return implode( ', ', $formatted );
    }

    /**
     * A search of a 5,000-article index answers inside the performance budget.
     *
     * The specification's number is 300 ms server-side on a 5,000-article
     * knowledge base. Measured here against a real index rather than asserted in
     * a comment.
     *
     * @since 1.0.0
     *
     * @group perf
     *
     * @return void
     */
    public function test_a_five_thousand_article_index_answers_within_the_budget() {
        $this->seed_bulk( 5000 );

        $query = $this->facade( new Lisensio_KB_Search_Fulltext_Driver() );

        // One untimed run so the measurement is not dominated by cache warming.
        $query->search( 'licence', array( 'limit' => 10 ) );

        /*
         * Timed several times and judged on the median.
         *
         * A single sample on a shared CI runner measures the runner as much as
         * the query: this assertion failed at 311.6 ms against a 300 ms budget
         * and passed on an unchanged re-run. Widening the budget would have
         * hidden the next real regression, so the measurement is made steadier
         * instead of the bar being lowered. The median ignores one unlucky
         * sample while a genuine slowdown moves every sample, so it still
         * fails - which is the property that matters.
         */
        $samples = array();

        for ( $run = 0; $run < self::PERF_SAMPLES; $run++ ) {
            $started   = microtime( true );
            $found     = $query->search( 'licence', array( 'limit' => 10 ) );
            $samples[] = ( microtime( true ) - $started ) * 1000;
        }

        sort( $samples );

        $median = $samples[ (int) floor( count( $samples ) / 2 ) ];

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Reporting a measurement to the test runner's console.
        fwrite(
            STDOUT,
            sprintf(
                "\n[perf] FULLTEXT search over %d indexed articles on %s: median %.1f ms of [%s]\n",
                5003,
                $this->server_version(),
                $median,
                self::format_samples( $samples )
            )
        );

        $this->assertNotEmpty( $found['ids'] );
        $this->assertLessThan(
            self::PERF_BUDGET_MS,
            $median,
            sprintf(
                'Median search took %.1f ms on %s over %d samples: [%s].',
                $median,
                $this->server_version(),
                self::PERF_SAMPLES,
                self::format_samples( $samples )
            )
        );
    }

    /**
     * A search really lands in the search log, with its source.
     *
     * The log is the only record the analytics dashboard has of what visitors
     * asked for, and the `source` column is what separates a type-ahead
     * keystroke from a deliberate visit to the results page. Asserted against
     * the shipped schema, because a column the writer names but the table does
     * not have fails only at the server.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_search_is_written_to_the_search_log() {
        $_SERVER['REMOTE_ADDR']     = '198.51.100.4';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Lisensio Integration Runner)';

        $log = new Lisensio_KB_Search_Log();

        $this->assertTrue( $log->record( 'Install  Licence', 3, 'live' ) );

        $rows = $this->db->query( 'SELECT * FROM ' . self::PREFIX . 'lisensio_search_log' );

        $this->assertSame( 1, $rows->num_rows );

        $row = $rows->fetch_object();

        $this->assertSame( 'Install  Licence', $row->term );
        $this->assertSame( 'install licence', $row->normalized_term );
        $this->assertSame( 3, (int) $row->results_count );
        $this->assertSame( 'live', $row->source );
        $this->assertSame( 64, strlen( $row->visitor_hash ) );

        /*
         * The privacy posture in one assertion: whatever else the row holds, it
         * does not hold the address the request came from.
         */
        $this->assertStringNotContainsString( '198.51.100.4', wp_json_encode( (array) $row ) );
    }

    /**
     * An unknown source is coerced before it reaches the column.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unknown_source_never_reaches_the_column() {
        $_SERVER['REMOTE_ADDR']     = '198.51.100.4';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Lisensio Integration Runner)';

        ( new Lisensio_KB_Search_Log() )->record( 'licence', 1, 'made-up' );

        $row = $this->db->query( 'SELECT source FROM ' . self::PREFIX . 'lisensio_search_log' )->fetch_object();

        $this->assertSame( 'site', $row->source );
    }

    /**
     * Fill the index with filler articles.
     *
     * One in ten mentions the query word in its body, so the search has a
     * realistic candidate set to rank rather than three rows in an empty table.
     *
     * @since 1.0.0
     *
     * @param int $count How many rows to write.
     *
     * @return void
     */
    private function seed_bulk( $count ) {
        $batch = array();

        for ( $i = 0; $i < $count; $i++ ) {
            $id      = 1000 + $i;
            $mention = 0 === $i % 10 ? 'The licence terms are described in the appendix. ' : '';

            $batch[] = sprintf(
                "(%d, 'Article %d about deployment', 'Filler excerpt', '%sThis article covers deployment topic number %d in some detail, with configuration notes.', 'Filler, Deployment', '2026-01-01 00:00:00')",
                $id,
                $id,
                $mention,
                $id
            );

            if ( count( $batch ) >= 500 ) {
                $this->flush_batch( $batch );
                $batch = array();
            }
        }

        if ( ! empty( $batch ) ) {
            $this->flush_batch( $batch );
        }
    }

    /**
     * Write a batch of seeded rows.
     *
     * @since 1.0.0
     *
     * @param string[] $batch The VALUES tuples.
     *
     * @return void
     */
    private function flush_batch( array $batch ) {
        $sql = 'INSERT INTO ' . self::PREFIX . 'lisensio_search_index
            (article_id, title, excerpt, content, terms, updated_at) VALUES ' . implode( ', ', $batch );

        $this->assertTrue( (bool) $this->db->query( $sql ), $this->db->error );
    }
}
