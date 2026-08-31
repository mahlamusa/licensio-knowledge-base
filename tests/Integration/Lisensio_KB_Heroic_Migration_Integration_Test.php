<?php
/**
 * Migrating a real Heroic database into the real target schema.
 *
 * Everything here runs against a live server. The Heroic tables are created
 * with Heroic's own DDL, seeded with rows carrying real IP addresses, and
 * migrated by the migrator's real code through the plugin's real gateway.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery

require_once __DIR__ . '/Lisensio_KB_Heroic_Migration_DB_Test_Case.php';

/**
 * What actually lands in the target tables.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Heroic_Migration_Integration_Test extends Lisensio_KB_Heroic_Migration_DB_Test_Case {

    /**
     * Addresses seeded into the Heroic tables.
     *
     * Every one of these is present in the source database and none of them may
     * appear anywhere in the target.
     *
     * @since 1.0.0
     * @var string[]
     */
    const ADDRESSES = array( '198.51.100.7', '203.0.113.19', '192.0.2.240' );

    /**
     * Seed a small but realistic Heroic knowledge base.
     *
     * Two articles have been migrated; a third Heroic article has not, so the
     * orphan branches are exercised rather than assumed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function seed_heroic_site() {
        $this->seed_migrated_article( 900, 11, 'install-a-licence' );
        $this->seed_migrated_article( 901, 12, 'renew-a-licence' );

        $this->seed(
            Lisensio_KB_Heroic_Schema::TABLE_VOTING,
            array(
                array(
                    'vote_id'   => 1,
                    'vote_key'  => 'k1',
                    'post_id'   => 11,
                    'user_id'   => 5,
                    'user_ip'   => self::ADDRESSES[0],
                    'datetime'  => '2025-01-02 10:00:00',
                    'magnitude' => Lisensio_KB_Heroic_Schema::MAGNITUDE_UP,
                    'feedback'  => 'Exactly what I needed.',
                ),
                array(
                    'vote_id'   => 2,
                    'vote_key'  => 'k2',
                    'post_id'   => 11,
                    'user_id'   => 0,
                    'user_ip'   => self::ADDRESSES[1],
                    'datetime'  => '2025-01-03 11:00:00',
                    'magnitude' => Lisensio_KB_Heroic_Schema::MAGNITUDE_DOWN,
                    'feedback'  => 'Still stuck on step 3.',
                ),
                array(
                    'vote_id'   => 3,
                    'vote_key'  => 'k3',
                    'post_id'   => 12,
                    'user_id'   => 0,
                    'user_ip'   => self::ADDRESSES[0],
                    'datetime'  => '2025-01-04 12:00:00',
                    'magnitude' => 5,
                    'feedback'  => '',
                ),
                array(
                    'vote_id'   => 4,
                    'vote_key'  => 'k4',
                    'post_id'   => 77,
                    'user_id'   => 0,
                    'user_ip'   => self::ADDRESSES[2],
                    'datetime'  => '2025-01-05 13:00:00',
                    'magnitude' => Lisensio_KB_Heroic_Schema::MAGNITUDE_UP,
                    'feedback'  => 'A vote on an article that was never migrated.',
                ),
            )
        );

        $this->seed(
            Lisensio_KB_Heroic_Schema::TABLE_VISITS,
            array(
                array(
                    'visit_id'    => 1,
                    'object_type' => Lisensio_KB_Heroic_Schema::OBJECT_TYPE_ARTICLE,
                    'object_id'   => 11,
                    'user_id'     => 0,
                    'user_ip'     => self::ADDRESSES[0],
                    'datetime'    => '2025-01-02 09:00:00',
                    'duration'    => 0,
                ),
                array(
                    'visit_id'    => 2,
                    'object_type' => Lisensio_KB_Heroic_Schema::OBJECT_TYPE_ARTICLE,
                    'object_id'   => 11,
                    'user_id'     => 0,
                    'user_ip'     => self::ADDRESSES[1],
                    'datetime'    => '2025-01-02 09:05:00',
                    'duration'    => 0,
                ),
                array(
                    'visit_id'    => 3,
                    'object_type' => 'ht_kb_category',
                    'object_id'   => 4,
                    'user_id'     => 0,
                    'user_ip'     => self::ADDRESSES[2],
                    'datetime'    => '2025-01-02 09:10:00',
                    'duration'    => 0,
                ),
                array(
                    'visit_id'    => 4,
                    'object_type' => 'ht_kb_archive',
                    'object_id'   => 0,
                    'user_id'     => 0,
                    'user_ip'     => self::ADDRESSES[2],
                    'datetime'    => '2025-01-02 09:11:00',
                    'duration'    => 0,
                ),
                array(
                    'visit_id'    => 5,
                    'object_type' => Lisensio_KB_Heroic_Schema::OBJECT_TYPE_ARTICLE,
                    'object_id'   => 77,
                    'user_id'     => 0,
                    'user_ip'     => self::ADDRESSES[2],
                    'datetime'    => '2025-01-02 09:12:00',
                    'duration'    => 0,
                ),
            )
        );

        $this->seed(
            Lisensio_KB_Heroic_Schema::TABLE_SEARCHES,
            array(
                array(
                    'id'       => 1,
                    'terms'    => 'Reset  Password',
                    'datetime' => '2025-01-02 08:00:00',
                    'hits'     => 3,
                    'user_id'  => 0,
                    'user_ip'  => self::ADDRESSES[0],
                    'source'   => Lisensio_KB_Heroic_Schema::SOURCE_FULL_SEARCH,
                ),
                array(
                    'id'       => 2,
                    'terms'    => 'licence key',
                    'datetime' => '2025-01-02 08:05:00',
                    'hits'     => 0,
                    'user_id'  => 0,
                    'user_ip'  => self::ADDRESSES[1],
                    'source'   => Lisensio_KB_Heroic_Schema::SOURCE_LIVE_SEARCH,
                ),
                array(
                    'id'       => 3,
                    'terms'    => 'refund',
                    'datetime' => '2025-01-02 08:10:00',
                    'hits'     => 1,
                    'user_id'  => 0,
                    'user_ip'  => self::ADDRESSES[2],
                    'source'   => null,
                ),
            )
        );

        $this->seed(
            Lisensio_KB_Heroic_Schema::TABLE_EXITS,
            array(
                array(
                    'exit_id'     => 1,
                    'object_type' => Lisensio_KB_Heroic_Schema::OBJECT_TYPE_ARTICLE,
                    'object_id'   => 11,
                    'source'      => 'article',
                    'user_id'     => 0,
                    'user_ip'     => self::ADDRESSES[2],
                    'datetime'    => '2025-01-02 09:20:00',
                    'url'         => 'https://example.test/submit-a-ticket/',
                ),
            )
        );
    }

    /**
     * Migrate the seeded site and hand back the report.
     *
     * @since 1.0.0
     *
     * @param Lisensio_KB_Heroic_State|null $state The resume point to use.
     *
     * @return Lisensio_KB_Heroic_Report The run report.
     */
    private function migrate( $state = null ) {
        $report   = new Lisensio_KB_Heroic_Report();
        $state    = $state instanceof Lisensio_KB_Heroic_State ? $state : new Lisensio_KB_Heroic_State();
        $migrator = $this->data_migrator( $state, $report );

        $this->run_all_data_phases( $migrator );

        return $report;
    }

    /**
     * The seeding itself puts real addresses into the source database.
     *
     * Without this, every no-IP assertion below could pass because nothing was
     * ever there to leak.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_source_database_really_does_contain_addresses() {
        $this->seed_heroic_site();

        $tables = array(
            Lisensio_KB_Heroic_Schema::TABLE_VOTING,
            Lisensio_KB_Heroic_Schema::TABLE_VISITS,
            Lisensio_KB_Heroic_Schema::TABLE_SEARCHES,
            Lisensio_KB_Heroic_Schema::TABLE_EXITS,
        );

        foreach ( $tables as $table ) {
            $found = array();

            foreach ( $this->fetch_all( $table ) as $row ) {
                $found[] = (string) $row[ Lisensio_KB_Heroic_Schema::COLUMN_IP ];
            }

            $this->assertNotEmpty( $found, sprintf( 'Heroic table %s was seeded with nothing.', $table ) );

            foreach ( $found as $address ) {
                $this->assertContains( $address, self::ADDRESSES );
            }
        }
    }

    /**
     * Not one of the seeded addresses reaches any target table, in any column.
     *
     * This is the assertion the whole tool exists to satisfy. It reads every
     * column of every row of every table the plugin owns and looks for the
     * literal values that are demonstrably present on the source side.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_no_heroic_address_reaches_any_lisensio_table() {
        $this->seed_heroic_site();
        $this->migrate();

        $stored = $this->every_target_value();

        $this->assertNotEmpty( $stored, 'Nothing was migrated, so this test would pass vacuously.' );

        foreach ( $stored as $value ) {
            foreach ( self::ADDRESSES as $address ) {
                $this->assertStringNotContainsString(
                    $address,
                    $value,
                    sprintf( 'A migrated value contains the Heroic visitor address %s.', $address )
                );
            }
        }
    }

    /**
     * Every target row was actually written, so the scan above had rows to read.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_migration_writes_the_rows_the_scan_then_reads() {
        $this->seed_heroic_site();
        $this->migrate();

        $this->assertSame( 3, $this->count_rows( Lisensio_KB_Feedback::TABLE ), 'Three votes name a migrated article.' );
        $this->assertSame( 2, $this->count_rows( Lisensio_KB_Analytics::TABLE_ARTICLE_VIEWS ), 'Two visits are article views of a migrated article.' );
        $this->assertSame( 3, $this->count_rows( Lisensio_KB_Search_Log::TABLE ), 'Every search migrates; searches do not name an article.' );
    }

    /**
     * What is stored instead of the address is a hash of the expected shape.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_what_is_stored_instead_is_a_sha256_digest() {
        $this->seed_heroic_site();
        $this->migrate();

        $hashes = array();

        foreach ( Lisensio_KB_DB_Installer::get_table_names() as $table ) {
            foreach ( $this->fetch_all( $table ) as $row ) {
                if ( array_key_exists( 'visitor_hash', $row ) ) {
                    $hashes[] = (string) $row['visitor_hash'];
                }
            }
        }

        $this->assertNotEmpty( $hashes );

        foreach ( $hashes as $hash ) {
            $this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash );
        }
    }

    /**
     * Distinct visitors stay distinct and one visitor stays one.
     *
     * A migration that hashed every row to a constant would pass the no-IP scan
     * while destroying every unique-visitor figure in the migrated analytics, so
     * the guarantee is only worth anything alongside this.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_visitor_identity_survives_the_migration() {
        $this->seed_heroic_site();
        $this->migrate();

        $by_search = array();

        foreach ( $this->fetch_all( Lisensio_KB_Search_Log::TABLE, 'id ASC' ) as $row ) {
            $by_search[] = (string) $row['visitor_hash'];
        }

        $this->assertCount( 3, $by_search );
        $this->assertCount( 3, array_unique( $by_search ), 'Three addresses must produce three identities.' );

        $votes = array();

        foreach ( $this->fetch_all( Lisensio_KB_Feedback::TABLE, 'id ASC' ) as $row ) {
            $votes[ (string) $row['comment'] ] = (string) $row['visitor_hash'];
        }

        $this->assertSame(
            $by_search[0],
            $votes['Exactly what I needed.'],
            'One address must resolve to one identity across two different Heroic tables.'
        );
    }

    /**
     * Votes keep their direction, comment, author and date.
     *
     * Heroic stores an up vote as 10 and a down vote as 0, so a migrator written
     * against an assumption of 1/-1 turns every down vote into an up vote. The
     * fixture contains one of each plus a neutral 5.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_votes_keep_direction_comment_author_and_date() {
        $this->seed_heroic_site();
        $this->migrate();

        $rows = array();

        foreach ( $this->fetch_all( Lisensio_KB_Feedback::TABLE, 'id ASC' ) as $row ) {
            $rows[] = $row;
        }

        $this->assertSame( 900, (int) $rows[0]['article_id'] );
        $this->assertSame( Lisensio_KB_Feedback::VOTE_UP, (int) $rows[0]['vote'] );
        $this->assertSame( 'Exactly what I needed.', $rows[0]['comment'] );
        $this->assertSame( 5, (int) $rows[0]['user_id'] );
        $this->assertSame( '2025-01-02 10:00:00', $rows[0]['created_at'] );
        $this->assertSame( Lisensio_KB_Feedback::STATUS_NEW, $rows[0]['status'] );

        $this->assertSame( Lisensio_KB_Feedback::VOTE_DOWN, (int) $rows[1]['vote'], 'Heroic stores a down vote as 0, not -1.' );
        $this->assertSame( 'Still stuck on step 3.', $rows[1]['comment'] );

        $this->assertSame( 901, (int) $rows[2]['article_id'] );
        $this->assertSame( Lisensio_KB_Feedback::VOTE_UP, (int) $rows[2]['vote'], 'Heroic counts a neutral vote as helpful.' );
    }

    /**
     * A vote on an article that was never migrated is reported, not orphaned into row 0.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_vote_for_an_unmigrated_article_is_counted_and_dropped() {
        $this->seed_heroic_site();
        $report = $this->migrate();

        $this->assertSame( 1, $report->get( 'feedback_orphaned' ) );
        $this->assertSame( 3, $report->get( 'feedback_migrated' ) );

        foreach ( $this->fetch_all( Lisensio_KB_Feedback::TABLE ) as $row ) {
            $this->assertGreaterThan( 0, (int) $row['article_id'], 'A vote was filed against article 0.' );
        }
    }

    /**
     * Category and archive visits are not article views.
     *
     * Heroic logs all four object types into one table. Migrating them all would
     * file a term ID into `article_id` and inflate every article's view count.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_visits_that_are_not_article_views_are_skipped() {
        $this->seed_heroic_site();
        $report = $this->migrate();

        $this->assertSame( 2, $report->get( 'views_not_articles' ), 'One category visit and one archive visit.' );
        $this->assertSame( 1, $report->get( 'views_orphaned' ) );
        $this->assertSame( 2, $report->get( 'views_migrated' ) );

        foreach ( $this->fetch_all( Lisensio_KB_Analytics::TABLE_ARTICLE_VIEWS ) as $row ) {
            $this->assertContains( (int) $row['article_id'], array( 900, 901 ) );
        }
    }

    /**
     * Searches keep their term, their result count and their source.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_searches_keep_term_results_and_source() {
        $this->seed_heroic_site();
        $this->migrate();

        $rows = $this->fetch_all( Lisensio_KB_Search_Log::TABLE, 'id ASC' );

        $this->assertSame( 'Reset  Password', $rows[0]['term'] );
        $this->assertSame( 'reset password', $rows[0]['normalized_term'] );
        $this->assertSame( 3, (int) $rows[0]['results_count'] );
        $this->assertSame( 'site', $rows[0]['source'] );

        $this->assertSame( 'licence key', $rows[1]['term'] );
        $this->assertSame( 0, (int) $rows[1]['results_count'], 'A no-results search is the most valuable row in the table.' );
        $this->assertSame( 'live', $rows[1]['source'], 'Heroic stores the type-ahead as source 1.' );

        $this->assertSame( 'site', $rows[2]['source'], 'A NULL source predates the column and was a full search.' );
    }

    /**
     * The exits table is not migrated, and nothing pretends otherwise.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_exit_rows_are_left_where_they_are() {
        $this->seed_heroic_site();
        $this->migrate();

        $this->assertSame( 1, $this->count_rows( Lisensio_KB_Heroic_Schema::TABLE_EXITS ), 'The source row is untouched.' );

        foreach ( $this->every_target_value() as $value ) {
            $this->assertStringNotContainsString( 'submit-a-ticket', $value, 'An exit URL reached a target table.' );
        }
    }

    /**
     * A second run inserts nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_second_run_does_not_duplicate_anything() {
        $this->seed_heroic_site();

        $this->migrate();

        $before = array(
            Lisensio_KB_Feedback::TABLE                => $this->count_rows( Lisensio_KB_Feedback::TABLE ),
            Lisensio_KB_Analytics::TABLE_ARTICLE_VIEWS => $this->count_rows( Lisensio_KB_Analytics::TABLE_ARTICLE_VIEWS ),
            Lisensio_KB_Search_Log::TABLE              => $this->count_rows( Lisensio_KB_Search_Log::TABLE ),
        );

        $this->assertNotEmpty( array_filter( $before ), 'Nothing was migrated, so a duplicate check is vacuous.' );

        $second = $this->migrate();

        foreach ( $before as $table => $count ) {
            $this->assertSame( $count, $this->count_rows( $table ), sprintf( 'Re-running the migration duplicated %s.', $table ) );
        }

        $this->assertSame( 0, $second->get( 'feedback_migrated' ), 'A second run should have found nothing left to do.' );
        $this->assertSame( 0, $second->get( 'searches_migrated' ) );
    }

    /**
     * A run killed part-way resumes at the next row, not at the first.
     *
     * The first process reads exactly one batch of one row and then dies. The
     * second picks up the persisted cursor and finishes. Neither row is written
     * twice and none is skipped.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_interrupted_run_resumes_where_it_stopped() {
        $this->seed_heroic_site();

        $first = new Lisensio_KB_Heroic_State();

        $this->data_migrator( $first, new Lisensio_KB_Heroic_Report() )->migrate_searches_batch( 1 );

        $this->assertSame( 1, $this->count_rows( Lisensio_KB_Search_Log::TABLE ) );
        $this->assertSame( 1, $first->cursor( 'searches' ) );

        /*
         * A brand new state object, reading the option the killed process left
         * behind. This is what the second `wp` invocation actually does.
         */
        $second   = new Lisensio_KB_Heroic_State();
        $migrator = $this->data_migrator( $second, new Lisensio_KB_Heroic_Report() );

        do {
            $read = $migrator->migrate_searches_batch( 1 );
        } while ( $read > 0 );

        $rows  = $this->fetch_all( Lisensio_KB_Search_Log::TABLE, 'id ASC' );
        $terms = array_column( $rows, 'term' );

        $this->assertSame( array( 'Reset  Password', 'licence key', 'refund' ), $terms, 'The resumed run skipped or repeated a row.' );
    }

    /**
     * A resumed run gives the same visitor the same identity.
     *
     * The salt has to survive between processes, or the same person counts twice
     * either side of the interruption.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_resumed_run_keeps_one_identity_per_visitor() {
        $this->seed_heroic_site();

        $this->data_migrator( new Lisensio_KB_Heroic_State(), new Lisensio_KB_Heroic_Report() )->migrate_searches_batch( 1 );

        $migrator = $this->data_migrator( new Lisensio_KB_Heroic_State(), new Lisensio_KB_Heroic_Report() );

        do {
            $read = $migrator->migrate_feedback_batch( 10 );
        } while ( $read > 0 );

        $search = $this->fetch_all( Lisensio_KB_Search_Log::TABLE, 'id ASC' );
        $votes  = $this->fetch_all( Lisensio_KB_Feedback::TABLE, 'id ASC' );

        $this->assertSame(
            (string) $search[0]['visitor_hash'],
            (string) $votes[0]['visitor_hash'],
            'A salt that did not survive the interruption gave one visitor two identities.'
        );
    }

    /**
     * A dry run writes nothing: no rows, and no resume state.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_dry_run_writes_nothing() {
        $this->seed_heroic_site();

        $report   = new Lisensio_KB_Heroic_Report();
        $state    = new Lisensio_KB_Heroic_State( false );
        $migrator = $this->data_migrator( $state, $report, true );

        $this->run_all_data_phases( $migrator );

        $this->assertSame( 0, $this->count_rows( Lisensio_KB_Feedback::TABLE ) );
        $this->assertSame( 0, $this->count_rows( Lisensio_KB_Analytics::TABLE_ARTICLE_VIEWS ) );
        $this->assertSame( 0, $this->count_rows( Lisensio_KB_Search_Log::TABLE ) );
        $this->assertArrayNotHasKey( Lisensio_KB_Heroic_State::OPTION_NAME, $this->options );
    }

    /**
     * A dry run still reports the counts a real run would produce.
     *
     * A dry run that wrote nothing and also reported nothing would satisfy the
     * previous test while being useless.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_dry_run_reports_the_counts_a_real_run_would_produce() {
        $this->seed_heroic_site();

        $dry = new Lisensio_KB_Heroic_Report();

        $this->run_all_data_phases( $this->data_migrator( new Lisensio_KB_Heroic_State( false ), $dry, true ) );

        $wet = $this->migrate();

        foreach ( array( 'feedback_migrated', 'feedback_orphaned', 'views_migrated', 'views_not_articles', 'views_orphaned', 'searches_migrated' ) as $counter ) {
            $this->assertSame(
                $wet->get( $counter ),
                $dry->get( $counter ),
                sprintf( 'The dry run disagreed with the real run about %s.', $counter )
            );
        }
    }

    /**
     * A timezone offset shifts every migrated datetime and nothing else.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_source_timezone_offset_is_applied_to_migrated_rows() {
        $this->seed_heroic_site();

        $migrator = $this->data_migrator( new Lisensio_KB_Heroic_State(), new Lisensio_KB_Heroic_Report(), false, 3600 );

        do {
            $read = $migrator->migrate_searches_batch( 10 );
        } while ( $read > 0 );

        $rows = $this->fetch_all( Lisensio_KB_Search_Log::TABLE, 'id ASC' );

        $this->assertSame( '2025-01-02 07:00:00', $rows[0]['created_at'], 'The seeded row is 08:00:00 one hour ahead of UTC.' );
    }

    /**
     * Usefulness recomputed from the migrated rows matches a hand recomputation.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_usefulness_recomputed_after_import_matches_the_migrated_votes() {
        $this->seed_heroic_site();

        $report   = new Lisensio_KB_Heroic_Report();
        $migrator = $this->data_migrator( new Lisensio_KB_Heroic_State(), $report );

        do {
            $read = $migrator->migrate_feedback_batch( 10 );
        } while ( $read > 0 );

        $feedback = new Lisensio_KB_Feedback();

        $this->assertSame(
            array(
                'up'   => 1,
                'down' => 1,
            ),
            $feedback->count_votes( 900 )
        );
        $this->assertSame( 50, Lisensio_KB_Feedback::calculate_score( 1, 1 ) );

        $this->assertSame(
            array(
                'up'   => 1,
                'down' => 0,
            ),
            $feedback->count_votes( 901 )
        );
        $this->assertSame( 100, Lisensio_KB_Feedback::calculate_score( 1, 0 ) );

        $this->assertSame( array( 900, 901 ), $migrator->voted_articles() );
    }

    /**
     * A Heroic module that was never switched on reports nothing rather than failing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_missing_heroic_table_migrates_as_zero_rows() {
        $this->seed_heroic_site();

        $this->assertTrue( (bool) $this->db->query( 'DROP TABLE ' . $this->table( Lisensio_KB_Heroic_Schema::TABLE_VISITS ) ) );

        $source = new Lisensio_KB_Heroic_Source();

        $this->assertFalse( Lisensio_KB_Heroic_Schema::table_exists( Lisensio_KB_Heroic_Schema::TABLE_VISITS ) );
        $this->assertSame( 0, $source->count_table( Lisensio_KB_Heroic_Schema::TABLE_VISITS ) );

        $report = $this->migrate();

        $this->assertSame( 0, $report->get( 'views_migrated' ) );
        $this->assertSame( 3, $report->get( 'feedback_migrated' ), 'The other phases must still run.' );
    }

    /**
     * The article lookup ignores a post that is not an article.
     *
     * The provenance meta key is not unique to the article post type. A revision
     * of a migrated article inherits its parent's meta, and a Heroic post that
     * was itself imported from somewhere could carry the key too. Without the
     * post type condition the lookup would return whichever row the index found
     * first, and votes would be filed against a revision — invisible in the
     * inbox, and lost as soon as the revision is pruned.
     *
     * The fixture puts the decoy at a LOWER id than the real article, so a
     * lookup that dropped the condition returns the decoy rather than happening
     * to return the right row anyway.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_article_lookup_ignores_a_post_that_is_not_an_article() {
        $this->seed(
            'posts',
            array(
                array(
                    'ID'          => 10,
                    'post_title'  => 'A revision of the article',
                    'post_name'   => '900-revision-v1',
                    'post_type'   => 'revision',
                    'post_status' => 'inherit',
                    'post_parent' => 900,
                ),
            )
        );

        $this->seed(
            'postmeta',
            array(
                array(
                    'post_id'    => 10,
                    'meta_key'   => Lisensio_KB_Heroic_Content_Migrator::META_SOURCE_POST, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                    'meta_value' => '11', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                ),
            )
        );

        $this->seed_heroic_site();
        $this->migrate();

        foreach ( $this->fetch_all( Lisensio_KB_Feedback::TABLE ) as $row ) {
            $this->assertNotSame( 10, (int) $row['article_id'], 'A vote was filed against a revision.' );
        }

        $this->assertSame( 2, $this->count_rows_where( Lisensio_KB_Feedback::TABLE, 'article_id = 900' ) );
    }

    /**
     * How many rows of a table match a condition.
     *
     * @since 1.0.0
     *
     * @param string $table The unprefixed table name.
     * @param string $where The WHERE clause, without the keyword.
     *
     * @return int The row count.
     */
    private function count_rows_where( $table, $where ) {
        return (int) $this->db->query(
            'SELECT COUNT(*) AS total FROM ' . $this->table( $table ) . ' WHERE ' . $where
        )->fetch_object()->total;
    }

    /**
     * The source counts a dry run reports come from the source, not from a guess.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_source_row_counts_are_read_from_the_source() {
        $this->seed_heroic_site();

        $source = new Lisensio_KB_Heroic_Source();

        $this->assertSame( 4, $source->count_table( Lisensio_KB_Heroic_Schema::TABLE_VOTING ) );
        $this->assertSame( 5, $source->count_table( Lisensio_KB_Heroic_Schema::TABLE_VISITS ) );
        $this->assertSame( 3, $source->count_table( Lisensio_KB_Heroic_Schema::TABLE_SEARCHES ) );
        $this->assertSame( 1, $source->count_table( Lisensio_KB_Heroic_Schema::TABLE_EXITS ) );
    }
}
