<?php
/**
 * The resume point and run report of the import framework.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */


/**
 * How the migrator remembers where it got to.
 *
 * @since 1.0.0
 */
class Licensio_KB_Heroic_Import_State_Test extends Licensio_KB_Test_Case {

    /**
     * The phases of the Heroic import, in run order.
     *
     * @since 1.0.0
     * @var string[]
     */
    const PHASES = array( 'terms', 'articles', 'feedback', 'views', 'searches' );

    /**
     * Build a Heroic import state.
     *
     * @since 1.0.0
     *
     * @param bool $persist Whether the state writes to the option.
     * @param bool $dry_run Whether it is a dry run's state.
     *
     * @return Licensio_KB_Import_State The state.
     */
    private function new_state( $persist = true, $dry_run = false ) {
        return new Licensio_KB_Import_State( 'heroic', self::PHASES, $persist, $dry_run );
    }

    /**
     * A phase nobody has run reports nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_fresh_state_has_no_cursor_and_no_completed_phase() {
        $state = $this->new_state();

        foreach ( self::PHASES as $phase ) {
            $this->assertSame( 0, $state->cursor( $phase ) );
            $this->assertFalse( $state->is_complete( $phase ) );
        }

        $this->assertFalse( $state->is_in_progress() );
    }

    /**
     * A cursor is persisted and read back by the next process.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_cursor_survives_into_a_second_run() {
        $first = $this->new_state();
        $first->set_cursor( 'searches', 4200 );

        $second = $this->new_state();

        $this->assertSame( 4200, $second->cursor( 'searches' ), 'A resumed run must start where the last one stopped.' );
        $this->assertTrue( $second->is_in_progress() );
    }

    /**
     * A cursor never moves backwards.
     *
     * A batch that came back empty, or one processed out of order, must not be
     * able to lower the mark — the next run would re-import everything between
     * the two values, and there is no provenance column to catch it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_cursor_refuses_to_move_backwards() {
        $state = $this->new_state();

        $state->set_cursor( 'views', 900 );
        $state->set_cursor( 'views', 12 );
        $state->set_cursor( 'views', 0 );

        $this->assertSame( 900, $state->cursor( 'views' ) );
    }

    /**
     * Phases keep separate cursors.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_phases_do_not_share_a_cursor() {
        $state = $this->new_state();

        $state->set_cursor( 'feedback', 10 );
        $state->set_cursor( 'views', 20 );

        $this->assertSame( 10, $state->cursor( 'feedback' ) );
        $this->assertSame( 20, $state->cursor( 'views' ) );
        $this->assertSame( 0, $state->cursor( 'searches' ) );
    }

    /**
     * The salt is generated once and reused for the whole migration.
     *
     * A salt that changed per call would give one visitor several identities and
     * silently inflate every unique-visitor figure in the migrated analytics.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_salt_is_stable_within_and_across_runs() {
        $first = $this->new_state();
        $salt  = $first->salt();

        $this->assertNotSame( '', $salt );
        $this->assertSame( $salt, $first->salt() );

        $second = $this->new_state();

        $this->assertSame( $salt, $second->salt(), 'A resumed run must reuse the salt, or the same visitor gets two identities.' );
    }

    /**
     * Finishing destroys the salt but keeps the cursors.
     *
     * Keeping the salt beside the hashes it produced would make every migrated
     * identity reversible by brute force over the address space. Keeping the
     * cursors is what stops an accidental later re-run importing anything.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_finishing_destroys_the_salt_and_keeps_the_cursors() {
        $state = $this->new_state();
        $salt  = $state->salt();

        $state->set_cursor( 'searches', 77 );
        $state->finish();

        $stored = $this->options[ Licensio_KB_Import_State::option_for( 'heroic' ) ];

        $this->assertArrayNotHasKey( 'salt', $stored, 'The migration salt outlived the migration.' );
        $this->assertSame( 77, ( $this->new_state() )->cursor( 'searches' ) );
        $this->assertNotSame( $salt, ( $this->new_state() )->salt(), 'A destroyed salt must not be recoverable.' );
    }

    /**
     * A finished migration is not in progress.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_finished_migration_is_not_in_progress() {
        $state = $this->new_state();

        $state->set_cursor( 'articles', 5 );

        $this->assertTrue( $state->is_in_progress() );

        $state->finish();

        $this->assertFalse( ( $this->new_state() )->is_in_progress() );
    }

    /**
     * Resetting forgets everything.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_reset_clears_every_phase() {
        $state = $this->new_state();

        $state->set_cursor( 'articles', 5 );
        $state->complete( 'terms' );
        $state->reset();

        $this->assertSame( 0, $state->cursor( 'articles' ) );
        $this->assertFalse( $state->is_complete( 'terms' ) );
        $this->assertArrayNotHasKey( Licensio_KB_Import_State::option_for( 'heroic' ), $this->options );
    }

    /**
     * A non-persisting state writes nothing at all.
     *
     * This is what `--dry-run` rests on: the cursors still move so the reported
     * counts are the counts a real run would produce, and not one byte reaches
     * the option.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_dry_run_state_moves_in_memory_and_persists_nothing() {
        $state = $this->new_state( false );

        $state->set_cursor( 'searches', 500 );
        $state->complete( 'terms' );
        $state->salt();
        $state->finish();

        $this->assertSame( 500, $state->cursor( 'searches' ), 'A dry run still has to know where it got to.' );
        $this->assertTrue( $state->is_complete( 'terms' ) );
        $this->assertSame( array(), $this->writes, 'A dry run wrote to the options table.' );
        $this->assertArrayNotHasKey( Licensio_KB_Import_State::option_for( 'heroic' ), $this->options );
    }

    /**
     * The phases are ordered so that nothing depends on something later.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_phase_order_puts_dependencies_first() {
        $order = array_flip( self::PHASES );

        $this->assertLessThan( $order['articles'], $order['terms'], 'An article is filed under terms that must already exist.' );

        foreach ( array( 'feedback', 'views' ) as $phase ) {
            $this->assertLessThan( $order[ $phase ], $order['articles'], sprintf( 'A %s row names an article.', $phase ) );
        }
    }

    /**
     * The report counts what it is told to and nothing else.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_report_accumulates_counts_and_notes() {
        $report = new Licensio_KB_Import_Report( 'heroic' );

        $this->assertSame( 0, $report->get( 'never_touched' ) );

        $report->count( 'articles_created' );
        $report->count( 'articles_created' );
        $report->count( 'searches_migrated', 40 );
        $report->note( 'slug_collisions', 'article 12 wanted "install" and got "install-12"' );

        $this->assertSame( 2, $report->get( 'articles_created' ) );
        $this->assertSame( 40, $report->get( 'searches_migrated' ) );
        $this->assertSame(
            array(
                'articles_created'  => 2,
                'searches_migrated' => 40,
            ),
            $report->counts()
        );
        $this->assertSame( array( 'slug_collisions' ), $report->note_categories() );
        $this->assertCount( 1, $report->notes( 'slug_collisions' ) );
        $this->assertSame( array(), $report->notes( 'nothing_here' ) );
    }

    /**
     * The current phase is the first one that has not finished.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_current_phase_is_the_first_unfinished_one() {
        $state = $this->new_state();

        $this->assertSame( 'terms', $state->current_phase() );

        $state->complete( 'terms' );

        $this->assertSame( 'articles', $state->current_phase() );

        $state->complete( 'articles' );
        $state->set_cursor( 'feedback', 10 );

        $this->assertSame( 'feedback', $state->current_phase() );
    }

    /**
     * A state with every phase complete has no current phase.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_finished_state_has_no_current_phase() {
        $state = $this->new_state();

        foreach ( self::PHASES as $phase ) {
            $state->complete( $phase );
        }

        $this->assertNull( $state->current_phase() );
    }

    /**
     * The whole-run completeness check asks about every phase at once.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_run_is_complete_only_when_every_phase_is() {
        $state = $this->new_state();

        $this->assertFalse( $state->is_complete() );

        foreach ( self::PHASES as $phase ) {
            $state->complete( $phase );
        }

        $this->assertTrue( $state->is_complete() );
    }

    /**
     * The report survives between the wizard requests.
     *
     * The run spans one AJAX batch per request, so a report that only lived in
     * memory would show the last batch, not the run.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_report_accumulates_across_instances() {
        $first = new Licensio_KB_Import_Report( 'heroic' );
        $first->count( 'articles_created', 30 );
        $first->note( 'article_order', 'article 6 collapsed.' );
        $first->save();

        $second = new Licensio_KB_Import_Report( 'heroic' );
        $second->count( 'articles_created', 20 );

        $this->assertSame( 50, $second->get( 'articles_created' ), 'A second request must add to the run total, not restart it.' );
        $this->assertCount( 1, $second->notes( 'article_order' ) );

        $all = $second->get_all();

        $this->assertSame( array( 'articles_created' => 50 ), $all['counts'] );
        $this->assertArrayHasKey( 'article_order', $all['notes'] );
    }

    /**
     * Resetting the report clears the option along with the memory.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_resetting_the_report_removes_the_stored_run() {
        $report = new Licensio_KB_Import_Report( 'heroic' );
        $report->count( 'articles_created', 30 );
        $report->save();

        $report->reset();

        $this->assertArrayNotHasKey( Licensio_KB_Import_Report::option_for( 'heroic' ), $this->options );
        $this->assertSame( 0, ( new Licensio_KB_Import_Report( 'heroic' ) )->get( 'articles_created' ) );
    }

    /**
     * The state and report options are named after the source.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_options_are_named_after_the_source() {
        $this->assertSame( 'licensio_import_state_heroic', Licensio_KB_Import_State::option_for( 'heroic' ) );
        $this->assertSame( 'licensio_import_report_heroic', Licensio_KB_Import_Report::option_for( 'heroic' ) );
        $this->assertNotSame(
            Licensio_KB_Import_State::option_for( 'heroic' ),
            Licensio_KB_Import_State::option_for( 'heroic', true ),
            'A dry run must keep its own state.'
        );
    }

    /**
     * A dry run state is kept apart from the real one.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_dry_run_state_never_touches_the_real_option() {
        $this->new_state( true, true )->set_cursor( 'searches', 9 );

        $this->assertArrayNotHasKey( Licensio_KB_Import_State::option_for( 'heroic' ), $this->options );
        $this->assertSame( 0, $this->new_state()->cursor( 'searches' ) );
        $this->assertSame( 9, $this->new_state( true, true )->cursor( 'searches' ) );
    }

    /**
     * The run id is issued once and survives into the next request.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_run_id_is_stable_across_requests() {
        $first = $this->new_state();

        $this->assertFalse( $first->has_run() );

        $run_id = $first->run_id();

        $this->assertStringStartsWith( 'heroic-', $run_id );
        $this->assertTrue( $first->has_run() );
        $this->assertSame( $run_id, $this->new_state()->run_id() );
    }

    /**
     * The category mapping keeps only positive pairs.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_category_map_drops_create_entries() {
        $state = $this->new_state();

        $state->set_category_map(
            array(
                '5' => '12',
                '6' => 0,
                '0' => 3,
            )
        );

        $this->assertSame( array( 5 => 12 ), $state->category_map() );
        $this->assertSame( array( 5 => 12 ), $this->new_state()->category_map() );
    }

    /**
     * Finishing marks the run finished and out of progress.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_finishing_records_when_the_run_finished() {
        $state = $this->new_state();

        $this->assertFalse( $state->is_finished() );
        $this->assertSame( 0, $state->finished_at() );

        $state->run_id();
        $state->finish();

        $this->assertTrue( $state->is_finished() );
        $this->assertGreaterThan( 0, $state->finished_at() );
        $this->assertFalse( $state->is_in_progress() );
    }

    /**
     * A restart rewinds only the named phases and opens a fresh run.
     *
     * Analytics rows carry no provenance, so their cursors must survive or the
     * next run would import them twice.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_restart_rewinds_content_phases_and_keeps_analytics_cursors() {
        $state = $this->new_state();

        $state->run_id();
        $state->set_category_map( array( 5 => 12 ) );
        $state->set_cursor( 'articles', 40 );
        $state->set_cursor( 'searches', 900 );
        $state->complete( 'articles' );
        $state->complete( 'searches' );
        $state->finish();

        $state->restart( array( 'terms', 'articles' ) );

        $this->assertSame( 0, $state->cursor( 'articles' ) );
        $this->assertSame( 900, $state->cursor( 'searches' ), 'An analytics cursor must survive a restart.' );
        $this->assertFalse( $state->is_complete( 'articles' ) );
        $this->assertFalse( $state->is_complete( 'searches' ) );
        $this->assertFalse( $state->is_finished() );
        $this->assertFalse( $state->has_run() );
        $this->assertSame( array(), $state->category_map() );
    }
}
