<?php
/**
 * The resume point of the disposable Heroic migrator.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

require_once dirname( dirname( __DIR__ ) ) . '/tools/migrate-heroic/class-licensio-kb-heroic-state.php';
require_once dirname( dirname( __DIR__ ) ) . '/tools/migrate-heroic/class-licensio-kb-heroic-report.php';

/**
 * How the migrator remembers where it got to.
 *
 * @since 1.0.0
 */
class Licensio_KB_Heroic_State_Test extends Licensio_KB_Test_Case {

    /**
     * A phase nobody has run reports nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_fresh_state_has_no_cursor_and_no_completed_phase() {
        $state = new Licensio_KB_Heroic_State();

        foreach ( Licensio_KB_Heroic_State::PHASES as $phase ) {
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
        $first = new Licensio_KB_Heroic_State();
        $first->set_cursor( 'searches', 4200 );

        $second = new Licensio_KB_Heroic_State();

        $this->assertSame( 4200, $second->cursor( 'searches' ), 'A resumed run must start where the last one stopped.' );
        $this->assertTrue( $second->is_in_progress() );
    }

    /**
     * A category's position base is persisted and read back by the next process.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_position_base_survives_into_a_second_run() {
        $first = new Licensio_KB_Heroic_State();

        $this->assertNull( $first->position_base( 501 ) );

        $first->set_position_base( 501, -1 );
        $first->set_position_base( 502, 40 );

        $second = new Licensio_KB_Heroic_State();

        $this->assertSame( -1, $second->position_base( 501 ) );
        $this->assertSame( 40, $second->position_base( 502 ) );
        $this->assertNull( $second->position_base( 503 ) );
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
        $state = new Licensio_KB_Heroic_State();

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
        $state = new Licensio_KB_Heroic_State();

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
        $first = new Licensio_KB_Heroic_State();
        $salt  = $first->salt();

        $this->assertNotSame( '', $salt );
        $this->assertSame( $salt, $first->salt() );

        $second = new Licensio_KB_Heroic_State();

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
        $state = new Licensio_KB_Heroic_State();
        $salt  = $state->salt();

        $state->set_cursor( 'searches', 77 );
        $state->finish();

        $stored = $this->options[ Licensio_KB_Heroic_State::OPTION_NAME ];

        $this->assertArrayNotHasKey( 'salt', $stored, 'The migration salt outlived the migration.' );
        $this->assertSame( 77, ( new Licensio_KB_Heroic_State() )->cursor( 'searches' ) );
        $this->assertNotSame( $salt, ( new Licensio_KB_Heroic_State() )->salt(), 'A destroyed salt must not be recoverable.' );
    }

    /**
     * A finished migration is not in progress.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_finished_migration_is_not_in_progress() {
        $state = new Licensio_KB_Heroic_State();

        $state->set_cursor( 'articles', 5 );

        $this->assertTrue( $state->is_in_progress() );

        $state->finish();

        $this->assertFalse( ( new Licensio_KB_Heroic_State() )->is_in_progress() );
    }

    /**
     * Resetting forgets everything.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_reset_clears_every_phase() {
        $state = new Licensio_KB_Heroic_State();

        $state->set_cursor( 'articles', 5 );
        $state->complete( 'terms' );
        $state->reset();

        $this->assertSame( 0, $state->cursor( 'articles' ) );
        $this->assertFalse( $state->is_complete( 'terms' ) );
        $this->assertArrayNotHasKey( Licensio_KB_Heroic_State::OPTION_NAME, $this->options );
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
        $state = new Licensio_KB_Heroic_State( false );

        $state->set_cursor( 'searches', 500 );
        $state->complete( 'terms' );
        $state->salt();
        $state->finish();

        $this->assertSame( 500, $state->cursor( 'searches' ), 'A dry run still has to know where it got to.' );
        $this->assertTrue( $state->is_complete( 'terms' ) );
        $this->assertSame( array(), $this->writes, 'A dry run wrote to the options table.' );
        $this->assertArrayNotHasKey( Licensio_KB_Heroic_State::OPTION_NAME, $this->options );
    }

    /**
     * The phases are ordered so that nothing depends on something later.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_phase_order_puts_dependencies_first() {
        $order = array_flip( Licensio_KB_Heroic_State::PHASES );

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
        $report = new Licensio_KB_Heroic_Report();

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
}
