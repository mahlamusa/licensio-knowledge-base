<?php
/**
 * The resume point of the disposable Heroic migrator.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */


/**
 * How the migrator remembers where it got to.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Heroic_Import_State_Test extends Lisensio_KB_Test_Case {

    /**
     * A phase nobody has run reports nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_fresh_state_has_no_cursor_and_no_completed_phase() {
        $state = new Lisensio_KB_Heroic_Import_State();

        foreach ( Lisensio_KB_Heroic_Import_State::PHASES as $phase ) {
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
        $first = new Lisensio_KB_Heroic_Import_State();
        $first->set_cursor( 'searches', 4200 );

        $second = new Lisensio_KB_Heroic_Import_State();

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
        $state = new Lisensio_KB_Heroic_Import_State();

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
        $state = new Lisensio_KB_Heroic_Import_State();

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
        $first = new Lisensio_KB_Heroic_Import_State();
        $salt  = $first->salt();

        $this->assertNotSame( '', $salt );
        $this->assertSame( $salt, $first->salt() );

        $second = new Lisensio_KB_Heroic_Import_State();

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
        $state = new Lisensio_KB_Heroic_Import_State();
        $salt  = $state->salt();

        $state->set_cursor( 'searches', 77 );
        $state->finish();

        $stored = $this->options[ Lisensio_KB_Heroic_Import_State::OPTION_NAME ];

        $this->assertArrayNotHasKey( 'salt', $stored, 'The migration salt outlived the migration.' );
        $this->assertSame( 77, ( new Lisensio_KB_Heroic_Import_State() )->cursor( 'searches' ) );
        $this->assertNotSame( $salt, ( new Lisensio_KB_Heroic_Import_State() )->salt(), 'A destroyed salt must not be recoverable.' );
    }

    /**
     * A finished migration is not in progress.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_finished_migration_is_not_in_progress() {
        $state = new Lisensio_KB_Heroic_Import_State();

        $state->set_cursor( 'articles', 5 );

        $this->assertTrue( $state->is_in_progress() );

        $state->finish();

        $this->assertFalse( ( new Lisensio_KB_Heroic_Import_State() )->is_in_progress() );
    }

    /**
     * Resetting forgets everything.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_reset_clears_every_phase() {
        $state = new Lisensio_KB_Heroic_Import_State();

        $state->set_cursor( 'articles', 5 );
        $state->complete( 'terms' );
        $state->reset();

        $this->assertSame( 0, $state->cursor( 'articles' ) );
        $this->assertFalse( $state->is_complete( 'terms' ) );
        $this->assertArrayNotHasKey( Lisensio_KB_Heroic_Import_State::OPTION_NAME, $this->options );
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
        $state = new Lisensio_KB_Heroic_Import_State( false );

        $state->set_cursor( 'searches', 500 );
        $state->complete( 'terms' );
        $state->salt();
        $state->finish();

        $this->assertSame( 500, $state->cursor( 'searches' ), 'A dry run still has to know where it got to.' );
        $this->assertTrue( $state->is_complete( 'terms' ) );
        $this->assertSame( array(), $this->writes, 'A dry run wrote to the options table.' );
        $this->assertArrayNotHasKey( Lisensio_KB_Heroic_Import_State::OPTION_NAME, $this->options );
    }

    /**
     * The phases are ordered so that nothing depends on something later.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_phase_order_puts_dependencies_first() {
        $order = array_flip( Lisensio_KB_Heroic_Import_State::PHASES );

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
        $report = new Lisensio_KB_Heroic_Import_Report();

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
        $state = new Lisensio_KB_Heroic_Import_State();

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
        $state = new Lisensio_KB_Heroic_Import_State();

        foreach ( Lisensio_KB_Heroic_Import_State::PHASES as $phase ) {
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
        $state = new Lisensio_KB_Heroic_Import_State();

        $this->assertFalse( $state->is_complete() );

        foreach ( Lisensio_KB_Heroic_Import_State::PHASES as $phase ) {
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
        $first = new Lisensio_KB_Heroic_Import_Report();
        $first->count( 'articles_created', 30 );
        $first->note( 'article_order', 'article 6 collapsed.' );
        $first->save();

        $second = new Lisensio_KB_Heroic_Import_Report();
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
        $report = new Lisensio_KB_Heroic_Import_Report();
        $report->count( 'articles_created', 30 );
        $report->save();

        $report->reset();

        $this->assertArrayNotHasKey( Lisensio_KB_Heroic_Import_Report::OPTION_NAME, $this->options );
        $this->assertSame( 0, ( new Lisensio_KB_Heroic_Import_Report() )->get( 'articles_created' ) );
    }
}
