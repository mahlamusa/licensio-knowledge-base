<?php
/**
 * The privacy exporter and eraser, at the boundaries.
 *
 * What the exporter returns and what the eraser removes is proven against a
 * real database in `tests/Integration`. What is proven here is what happens
 * when a request cannot be resolved to anybody — the case where a wrong answer
 * is not a missing row but every row in the table.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Drives Licensio_KB_Analytics_Privacy.
 *
 * @since 1.0.0
 */
class Licensio_KB_Analytics_Privacy_Test extends Licensio_KB_Test_Case {

    /**
     * Boot the environment.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        Functions\when( 'get_user_by' )->justReturn( false );
    }

    /**
     * Both handlers are registered under the plugin's own identifier.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_both_handlers_are_registered() {
        $privacy = new Licensio_KB_Analytics_Privacy();

        $privacy->init_hooks();

        $this->assertNotFalse(
            has_filter( 'wp_privacy_personal_data_exporters', array( $privacy, 'register_exporter' ) )
        );
        $this->assertNotFalse(
            has_filter( 'wp_privacy_personal_data_erasers', array( $privacy, 'register_eraser' ) )
        );
    }

    /**
     * The exporter registration carries a callable and a friendly name.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_exporter_registration_is_well_formed() {
        $privacy   = new Licensio_KB_Analytics_Privacy();
        $exporters = $privacy->register_exporter( array( 'core-user' => array() ) );

        $this->assertArrayHasKey( 'core-user', $exporters, 'An existing exporter was dropped.' );
        $this->assertArrayHasKey( Licensio_KB_Analytics_Privacy::HANDLER_ID, $exporters );

        $mine = $exporters[ Licensio_KB_Analytics_Privacy::HANDLER_ID ];

        $this->assertNotEmpty( $mine['exporter_friendly_name'] );
        $this->assertIsCallable( $mine['callback'] );
    }

    /**
     * The eraser registration carries a callable and a friendly name.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_eraser_registration_is_well_formed() {
        $privacy = new Licensio_KB_Analytics_Privacy();
        $erasers = $privacy->register_eraser( array( 'core-user' => array() ) );

        $this->assertArrayHasKey( 'core-user', $erasers, 'An existing eraser was dropped.' );

        $mine = $erasers[ Licensio_KB_Analytics_Privacy::HANDLER_ID ];

        $this->assertNotEmpty( $mine['eraser_friendly_name'] );
        $this->assertIsCallable( $mine['callback'] );
    }

    /**
     * An address that matches nobody resolves to nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unknown_address_resolves_to_nobody() {
        $identity = ( new Licensio_KB_Analytics_Privacy() )->identity( 'nobody@example.test' );

        $this->assertSame( 0, $identity['user_id'] );
        $this->assertSame( array(), $identity['hashes'] );
        $this->assertFalse( $identity['resolvable'] );
    }

    /**
     * An unresolvable request exports nothing and reports itself finished.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unresolvable_request_exports_nothing() {
        $response = ( new Licensio_KB_Analytics_Privacy() )->export( 'nobody@example.test' );

        $this->assertSame( array(), $response['data'] );
        $this->assertTrue( $response['done'] );
    }

    /**
     * An unresolvable request erases nothing and reports itself finished.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unresolvable_request_erases_nothing() {
        $response = ( new Licensio_KB_Analytics_Privacy() )->erase( 'nobody@example.test' );

        $this->assertFalse( $response['items_removed'] );
        $this->assertTrue( $response['done'] );
    }

    /**
     * The search log and the view log are never matched without a hash.
     *
     * Neither table has a user column, so an identity that resolved a user but
     * no visitor hash has no criterion to filter them by. If the code ran the
     * query anyway the WHERE clause would be empty — and the eraser would
     * delete every search and every view on the site while reporting success.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_hashless_tables_are_never_matched_without_a_hash() {
        $privacy = new Licensio_KB_Analytics_Privacy();
        $method  = new ReflectionMethod( $privacy, 'has_criteria' );

        $method->setAccessible( true );

        $user_only = array(
            'user_id'    => 7,
            'hashes'     => array(),
            'resolvable' => true,
        );

        $this->assertFalse(
            $method->invoke( $privacy, Licensio_KB_Analytics::TABLE_SEARCH_LOG, $user_only ),
            'The search log would have been queried with no criteria at all.'
        );
        $this->assertFalse(
            $method->invoke( $privacy, Licensio_KB_Analytics::TABLE_ARTICLE_VIEWS, $user_only ),
            'The view log would have been queried with no criteria at all.'
        );
        $this->assertTrue(
            $method->invoke( $privacy, Licensio_KB_Analytics::TABLE_FEEDBACK, $user_only ),
            'Feedback has a user column and should still be matched.'
        );
    }

    /**
     * Nothing at all is matched for an identity with neither key.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_nothing_is_matched_for_an_empty_identity() {
        $privacy = new Licensio_KB_Analytics_Privacy();
        $method  = new ReflectionMethod( $privacy, 'has_criteria' );

        $method->setAccessible( true );

        $empty = array(
            'user_id'    => 0,
            'hashes'     => array(),
            'resolvable' => false,
        );

        foreach ( Licensio_KB_Analytics_Retention::tables() as $table ) {
            $this->assertFalse(
                $method->invoke( $privacy, $table, $empty ),
                sprintf( 'Table %s would have been queried with no criteria at all.', $table )
            );
        }
    }

    /**
     * A site can supply hashes the plugin cannot resolve on its own.
     *
     * Brain Monkey does not run `add_filter` callbacks on `apply_filters`, so
     * the filtered value is staged rather than hooked.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_site_can_supply_extra_visitor_hashes() {
        Filters\expectApplied( 'licensio_privacy_visitor_hashes' )
            ->once()
            ->andReturn( array( 'abc', 'abc', '', 'def' ) );

        $identity = ( new Licensio_KB_Analytics_Privacy() )->identity( 'someone@example.test' );

        $this->assertSame( array( 'abc', 'def' ), $identity['hashes'] );
        $this->assertTrue( $identity['resolvable'] );
    }
}
