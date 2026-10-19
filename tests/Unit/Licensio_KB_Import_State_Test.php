<?php
/**
 * Seeding a dry run's cursors from the real import's.
 *
 * The rest of the state is covered by the Heroic state test; this covers what
 * the framework added for dry runs.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/**
 * How one state copies cursors from another.
 *
 * @since 1.0.0
 */
class Licensio_KB_Import_State_Test extends Licensio_KB_Test_Case {

    /**
     * The phases of the BetterDocs import, in run order.
     *
     * @since 1.0.0
     * @var string[]
     */
    const PHASES = array( 'terms', 'articles', 'feedback', 'views', 'searches' );

    /**
     * Build a BetterDocs import state.
     *
     * @since 1.0.0
     *
     * @param bool $dry_run Whether it is a dry run's state.
     * @param bool $persist Whether the state writes to the option.
     *
     * @return Licensio_KB_Import_State The state.
     */
    private function new_state( $dry_run, $persist = true ) {
        return new Licensio_KB_Import_State( 'betterdocs', self::PHASES, $persist, $dry_run );
    }

    /**
     * Only the named phases are copied.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_seed_cursors_copies_only_the_named_phases() {
        $real = $this->new_state( false );

        $real->set_cursor( 'terms', 3 );
        $real->set_cursor( 'articles', 40 );
        $real->set_cursor( 'feedback', 120 );
        $real->set_cursor( 'views', 250 );
        $real->set_cursor( 'searches', 900 );

        $dry = $this->new_state( true );

        $dry->seed_cursors( $real, array( 'feedback', 'views', 'searches' ) );

        $this->assertSame( 120, $dry->cursor( 'feedback' ) );
        $this->assertSame( 250, $dry->cursor( 'views' ) );
        $this->assertSame( 900, $dry->cursor( 'searches' ) );
        $this->assertSame( 0, $dry->cursor( 'terms' ), 'Content phases are not seeded.' );
        $this->assertSame( 0, $dry->cursor( 'articles' ), 'Content phases are not seeded.' );
    }

    /**
     * A phase the source never started stays at zero.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_seed_cursors_leaves_an_unstarted_phase_at_zero() {
        $real = $this->new_state( false );

        $real->set_cursor( 'views', 250 );

        $dry = $this->new_state( true );

        $dry->seed_cursors( $real, array( 'feedback', 'views' ) );

        $this->assertSame( 0, $dry->cursor( 'feedback' ) );
        $this->assertSame( 250, $dry->cursor( 'views' ) );
    }

    /**
     * Seeding never moves a cursor backwards.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_seed_cursors_never_lowers_a_cursor() {
        $real = $this->new_state( false );

        $real->set_cursor( 'views', 10 );

        $dry = $this->new_state( true );

        $dry->set_cursor( 'views', 80 );
        $dry->seed_cursors( $real, array( 'views' ) );

        $this->assertSame( 80, $dry->cursor( 'views' ) );
    }

    /**
     * Seeding changes the dry run's option and leaves the real import's alone.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_seed_cursors_writes_only_the_dry_run_option() {
        $real = $this->new_state( false );

        $real->set_cursor( 'searches', 900 );

        $real_option = Licensio_KB_Import_State::option_for( 'betterdocs', false );
        $dry_option  = Licensio_KB_Import_State::option_for( 'betterdocs', true );
        $before      = $this->options[ $real_option ];

        $this->new_state( true )->seed_cursors( $real, array( 'searches' ) );

        $this->assertSame( $before, $this->options[ $real_option ], 'The real import state must not change.' );
        $this->assertSame( 900, $this->options[ $dry_option ]['searches']['cursor'] );
        $this->assertSame( 'licensio_import_dryrun_state_betterdocs', $dry_option );
    }

    /**
     * A seeded cursor survives into the next request.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_seeded_cursor_is_reloaded_by_the_next_batch() {
        $real = $this->new_state( false );

        $real->set_cursor( 'feedback', 77 );
        $this->new_state( true )->seed_cursors( $real, array( 'feedback' ) );

        $this->assertSame( 77, $this->new_state( true )->cursor( 'feedback' ) );
    }

    /**
     * Seeding from a state with no cursors writes nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_seeding_from_an_empty_state_writes_nothing() {
        $this->new_state( true )->seed_cursors( $this->new_state( false ), array( 'feedback', 'views', 'searches' ) );

        $this->assertSame( array(), $this->writes );
    }

    /**
     * A dry-run state and a real one never share an option.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_dry_run_and_real_state_use_different_options() {
        $this->assertNotSame( $this->new_state( true )->option_name(), $this->new_state( false )->option_name() );
        $this->assertSame( 'licensio_import_state_betterdocs', $this->new_state( false )->option_name() );
    }
}
