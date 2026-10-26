<?php
/**
 * Per-category article order.
 *
 * The query rules are asserted on the variables `prepare_query()` leaves on a
 * query and the JOIN and ORDER BY `order_clauses()` writes; the SQL itself
 * runs against a live server in the integration suite. The merge and delete listeners are
 * driven through an in-memory meta store.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * Drives Licensio_KB_Article_Order.
 *
 * @since 1.0.0
 */
class Licensio_KB_Article_Order_Test extends Licensio_KB_Test_Case {

    /**
     * Post ID to meta key to value.
     *
     * @since 1.0.0
     * @var array<int, array<string, mixed>>
     */
    private $meta = array();

    /**
     * The post meta cache clears and posts cache bumps, in order.
     *
     * @since 1.0.0
     * @var array<int, mixed>
     */
    private $cache = array();

    /**
     * Back the positions query and the meta writes with the in-memory store.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->meta  = array();
        $this->cache = array();

        Functions\when( 'absint' )->alias(
            function ( $value ) {
                return abs( (int) $value );
            }
        );

        $GLOBALS['wpdb'] = new Licensio_KB_Order_WPDB( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
            function () {
                return $this->meta;
            },
            function ( $id, $key, $value ) {
                $this->meta[ $id ][ $key ] = $value;
            }
        );

        Functions\when( 'wp_cache_delete_multiple' )->alias(
            function ( $ids, $group ) {
                $this->cache[] = array( $ids, $group );

                return array();
            }
        );
        Functions\when( 'wp_cache_set_posts_last_changed' )->alias(
            function () {
                $this->cache[] = 'last_changed';
            }
        );

        Functions\when( 'is_tax' )->alias(
            function ( $taxonomy = '' ) {
                return Licensio_KB_Post_Types::TAXONOMY_CATEGORY === $taxonomy;
            }
        );

        Functions\when( 'get_term_by' )->alias(
            function ( $field, $value, $taxonomy ) {
                if ( 'slug' === $field && 'billing' === $value && Licensio_KB_Post_Types::TAXONOMY_CATEGORY === $taxonomy ) {
                    return new WP_Term( array( 'term_id' => 7 ) );
                }

                return false;
            }
        );
    }

    /**
     * Drop the $wpdb double.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );

        parent::tearDown();
    }

    /**
     * A query for one category clause.
     *
     * @since 1.0.0
     *
     * @param array<int|string, mixed> $tax_queries The parsed tax clauses.
     * @param array<string, mixed>     $vars        The query variables.
     *
     * @return WP_Query The query.
     */
    private function query( array $tax_queries, array $vars = array() ) {
        $query            = new WP_Query( array_merge( array( 'orderby' => 'licensio_order' ), $vars ) );
        $query->tax_query = new WP_Tax_Query( $tax_queries );

        return $query;
    }

    /**
     * A parsed category clause.
     *
     * @since 1.0.0
     *
     * @param mixed  $terms The terms.
     * @param string $field The field.
     *
     * @return array<string, mixed> The clause.
     */
    private function clause( $terms, $field = 'term_id' ) {
        return array(
            'taxonomy'         => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
            'terms'            => (array) $terms,
            'field'            => $field,
            'operator'         => 'IN',
            'include_children' => true,
        );
    }

    /**
     * The hooks are registered.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_hooks_are_registered() {
        $order = new Licensio_KB_Article_Order();
        $order->init_hooks();

        $this->assertNotFalse( has_action( 'pre_get_posts', array( $order, 'prepare_query' ) ) );
        $this->assertNotFalse( has_filter( 'posts_clauses', array( $order, 'order_clauses' ) ) );
        $this->assertNotFalse( has_filter( 'is_protected_meta', array( $order, 'protect_meta' ) ) );
        $this->assertNotFalse( has_action( 'licensio_kb_terms_merged', array( $order, 'append_merged_order' ) ) );
        $this->assertNotFalse( has_action( 'licensio_kb_term_removed', array( $order, 'delete_term_order' ) ) );
    }

    /**
     * One category is recorded, ascending, with filters on, and nothing else changes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_one_category_is_recorded_with_filters_on() {
        $query = $this->query( array( $this->clause( 7 ) ) );

        ( new Licensio_KB_Article_Order() )->prepare_query( $query );

        $this->assertSame( 'licensio_order', $query->get( 'orderby' ), 'The orderby stays as the marker the clauses filter checks.' );
        $this->assertSame( 7, $query->get( 'licensio_order_term' ) );
        $this->assertFalse( $query->get( 'licensio_order_desc' ), 'No order means ascending.' );
        $this->assertFalse( $query->get( 'suppress_filters' ), 'get_posts() would otherwise skip the JOIN and ORDER BY.' );
        $this->assertNull( $query->get( 'meta_query' ), 'No meta query is added.' );
    }

    /**
     * Only an explicit DESC reverses the order.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_only_an_explicit_desc_reverses_the_order() {
        foreach ( array(
            'DESC' => true,
            'desc' => true,
            'ASC'  => false,
            ''     => false,
        ) as $order => $reversed ) {
            $query = $this->query( array( $this->clause( 7 ) ), array( 'order' => $order ) );

            ( new Licensio_KB_Article_Order() )->prepare_query( $query );

            $this->assertSame( $reversed, $query->get( 'licensio_order_desc' ), 'order: ' . $order );
        }
    }

    /**
     * A category named by slug resolves to its term ID.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_category_named_by_slug_resolves() {
        $query = $this->query( array( $this->clause( 'billing', 'slug' ) ) );

        ( new Licensio_KB_Article_Order() )->prepare_query( $query );

        $this->assertSame( 7, $query->get( 'licensio_order_term' ) );
    }

    /**
     * An existing meta query is left as it is.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_existing_meta_query_is_kept() {
        $existing = array(
            array(
                'key'   => 'featured',
                'value' => '1',
            ),
        );
        $query    = $this->query( array( $this->clause( 7 ) ), array( 'meta_query' => $existing ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query

        ( new Licensio_KB_Article_Order() )->prepare_query( $query );

        $this->assertSame( $existing, $query->get( 'meta_query' ) );
        $this->assertSame( 7, $query->get( 'licensio_order_term' ) );
    }

    /**
     * Anything but exactly one category falls back to menu order, then title.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_anything_but_one_category_falls_back_to_menu_order() {
        $tag = array(
            'taxonomy' => Licensio_KB_Post_Types::TAXONOMY_TAG,
            'terms'    => array( 3 ),
            'field'    => 'term_id',
            'operator' => 'IN',
        );

        $cases = array(
            'no tax query'    => array(),
            'two terms'       => array( $this->clause( array( 7, 8 ) ) ),
            'two clauses'     => array( $this->clause( 7 ), $this->clause( 8 ) ),
            'or with a tag'   => array(
                'relation' => 'OR',
                $this->clause( 7 ),
                $tag,
            ),
            'not in'          => array( array_merge( $this->clause( 7 ), array( 'operator' => 'NOT IN' ) ) ),
            'unknown slug'    => array( $this->clause( 'nope', 'slug' ) ),
            'nested'          => array( array( $this->clause( 7 ) ) ),
            'tag clause only' => array( $tag ),
        );

        foreach ( $cases as $label => $tax_queries ) {
            $query = $this->query( $tax_queries );

            ( new Licensio_KB_Article_Order() )->prepare_query( $query );

            $this->assertSame(
                array(
                    'menu_order' => 'ASC',
                    'title'      => 'ASC',
                ),
                $query->get( 'orderby' ),
                $label
            );
            $this->assertNull( $query->get( 'meta_query' ), $label );
            $this->assertNull( $query->get( 'licensio_order_term' ), $label );
        }
    }

    /**
     * A category clause beside an AND-joined tag clause still qualifies.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_tag_filter_beside_one_category_still_qualifies() {
        $query = $this->query(
            array(
                'relation' => 'AND',
                $this->clause( 7 ),
                array(
                    'taxonomy' => Licensio_KB_Post_Types::TAXONOMY_TAG,
                    'terms'    => array( 3 ),
                    'field'    => 'term_id',
                    'operator' => 'IN',
                ),
            )
        );

        ( new Licensio_KB_Article_Order() )->prepare_query( $query );

        $this->assertSame( 7, $query->get( 'licensio_order_term' ) );
    }

    /**
     * Another ordering is left alone.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_another_ordering_is_left_alone() {
        $query = $this->query( array( $this->clause( 7 ) ), array( 'orderby' => 'date' ) );

        ( new Licensio_KB_Article_Order() )->prepare_query( $query );

        $this->assertSame( 'date', $query->get( 'orderby' ) );
        $this->assertNull( $query->get( 'meta_query' ) );
    }

    /**
     * A front-end category archive with no ordering of its own uses the order.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_category_archive_uses_the_order() {
        $query       = $this->query( array( $this->clause( 'billing', 'slug' ) ), array( 'orderby' => '' ) );
        $query->main = true;

        ( new Licensio_KB_Article_Order() )->prepare_query( $query );

        $this->assertSame( 7, $query->get( 'licensio_order_term' ) );
    }

    /**
     * The archive keeps an ordering a visitor or another plugin chose.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_category_archive_keeps_a_chosen_ordering() {
        $query       = $this->query( array( $this->clause( 7 ) ), array( 'orderby' => 'date' ) );
        $query->main = true;

        ( new Licensio_KB_Article_Order() )->prepare_query( $query );

        $this->assertSame( 'date', $query->get( 'orderby' ) );
    }

    /**
     * A search or a feed on the category archive keeps its own order.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_search_or_feed_on_the_archive_keeps_its_order() {
        foreach ( array( 'is_search', 'is_feed' ) as $flag ) {
            $query          = $this->query( array( $this->clause( 7 ) ), array( 'orderby' => '' ) );
            $query->main    = true;
            $query->{$flag} = true;

            ( new Licensio_KB_Article_Order() )->prepare_query( $query );

            $this->assertSame( '', $query->get( 'orderby' ), $flag );
            $this->assertNull( $query->get( 'licensio_order_term' ), $flag );
        }
    }

    /**
     * An array orderby on the archive is kept, without a conversion warning.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_category_archive_keeps_an_array_ordering() {
        $orderby     = array( 'date' => 'DESC' );
        $query       = $this->query( array( $this->clause( 7 ) ), array( 'orderby' => $orderby ) );
        $query->main = true;

        ( new Licensio_KB_Article_Order() )->prepare_query( $query );

        $this->assertSame( $orderby, $query->get( 'orderby' ) );
        $this->assertNull( $query->get( 'licensio_order_term' ) );
    }

    /**
     * The admin list of a category is not reordered.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_admin_is_not_reordered() {
        Functions\when( 'is_admin' )->justReturn( true );

        $query       = $this->query( array( $this->clause( 7 ) ), array( 'orderby' => '' ) );
        $query->main = true;

        ( new Licensio_KB_Article_Order() )->prepare_query( $query );

        $this->assertSame( '', $query->get( 'orderby' ) );
    }

    /**
     * The clauses gain one LEFT JOIN on the key and the ORDER BY, ascending.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_clauses_join_the_key_and_order_by_it() {
        $clauses = $this->prepared_clauses( array() );

        $this->assertSame( "J LEFT JOIN `wptests_postmeta` AS `licensio_order_meta` ON ( `wptests_posts`.ID = `licensio_order_meta`.post_id AND `licensio_order_meta`.meta_key = 'licensio_order_7' )", $clauses['join'] );
        $this->assertSame( '`licensio_order_meta`.meta_value IS NULL ASC, CAST(`licensio_order_meta`.meta_value AS SIGNED) ASC, `wptests_posts`.menu_order ASC, `wptests_posts`.post_title ASC, `wptests_posts`.ID ASC', $clauses['orderby'] );
        $this->assertSame( 'W', $clauses['where'], 'Nothing is filtered out.' );
        $this->assertSame( '', $clauses['groupby'] );
    }

    /**
     * DESC reverses the positions, and unordered articles still come last.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_desc_reverses_the_positions_with_unordered_still_last() {
        $clauses = $this->prepared_clauses( array( 'order' => 'DESC' ) );

        $this->assertSame( '`licensio_order_meta`.meta_value IS NULL ASC, CAST(`licensio_order_meta`.meta_value AS SIGNED) DESC, `wptests_posts`.menu_order DESC, `wptests_posts`.post_title DESC, `wptests_posts`.ID DESC', $clauses['orderby'] );
    }

    /**
     * A later pre_get_posts that changed the orderby wins, as the search ranking does.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_later_orderby_change_wins() {
        $query = $this->query( array( $this->clause( 7 ) ) );

        ( new Licensio_KB_Article_Order() )->prepare_query( $query );

        $query->set( 'orderby', 'post__in' );

        $clauses = array(
            'join'    => 'J',
            'orderby' => 'FIELD( wptests_posts.ID, 3, 1 )',
        );

        $this->assertSame( $clauses, ( new Licensio_KB_Article_Order() )->order_clauses( $clauses, $query ) );
    }

    /**
     * A query that was not prepared keeps its clauses.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unprepared_query_keeps_its_clauses() {
        $query = $this->query( array( $this->clause( 7 ) ), array( 'orderby' => 'date' ) );

        $clauses = ( new Licensio_KB_Article_Order() )->order_clauses( array( 'orderby' => 'post_date DESC' ), $query );

        $this->assertSame( array( 'orderby' => 'post_date DESC' ), $clauses );
    }

    /**
     * The order keys are protected post meta; other keys are left alone.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_order_keys_are_protected() {
        $order = new Licensio_KB_Article_Order();

        $this->assertTrue( $order->protect_meta( false, 'licensio_order_7', 'post' ) );
        $this->assertFalse( $order->protect_meta( false, 'licensio_order_7', 'term' ) );
        $this->assertFalse( $order->protect_meta( false, 'featured', 'post' ) );
        $this->assertTrue( $order->protect_meta( true, 'featured', 'post' ) );
    }

    /**
     * The clauses `order_clauses()` writes for a prepared one-category query.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $vars The query variables.
     *
     * @return array<string, string> The clauses.
     */
    private function prepared_clauses( array $vars ) {
        $GLOBALS['wpdb'] = new Licensio_KB_Test_WPDB(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

        $query = $this->query( array( $this->clause( 7 ) ), $vars );
        $order = new Licensio_KB_Article_Order();
        $order->prepare_query( $query );

        $clauses = $order->order_clauses(
            array(
                'join'    => 'J',
                'where'   => 'W',
                'groupby' => '',
                'orderby' => 'wptests_posts.post_date DESC',
            ),
            $query
        );

        unset( $GLOBALS['wpdb'] );

        return $clauses;
    }

    /**
     * The positions are read in one prepared query, without priming the meta cache.
     *
     * Priming loads every meta row of every positioned article, which runs
     * out of memory on a category with thousands of articles.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_positions_are_read_in_one_query_without_the_meta_cache() {
        Functions\expect( 'update_meta_cache' )->never();
        Functions\expect( 'get_posts' )->never();
        Functions\expect( 'get_post_meta' )->never();

        $this->meta = array(
            31 => array( 'licensio_order_7' => '20' ),
            32 => array( 'licensio_order_7' => '10' ),
            33 => array( 'licensio_order_8' => '5' ),
            34 => array( 'licensio_order_7' => '20' ),
        );

        $this->assertSame(
            array(
                32 => 10,
                31 => 20,
                34 => 20,
            ),
            Licensio_KB_Article_Order::positions( 7 )
        );

        $reads = $GLOBALS['wpdb']->position_reads;

        $this->assertCount( 1, $reads );
        $this->assertStringContainsString( "FROM `wptests_postmeta` pm INNER JOIN `wptests_posts` p ON p.ID = pm.post_id WHERE pm.meta_key = 'licensio_order_7' AND p.post_type = 'licensio_article' AND p.post_status <> 'auto-draft'", $reads[0] );
        $this->assertStringContainsString( 'ORDER BY CAST(pm.meta_value AS SIGNED) ASC, pm.post_id ASC', $reads[0] );
    }

    /**
     * Saving writes 10, 20, 30 in the order given.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_save_writes_steps_of_ten() {
        $this->assertSame( array( 3, 1, 2 ), Licensio_KB_Article_Order::save( 7, array( 3, 1, 2 ) ) );

        $this->assertSame( '10', $this->stored( 3, 'licensio_order_7' ) );
        $this->assertSame( '20', $this->stored( 1, 'licensio_order_7' ) );
        $this->assertSame( '30', $this->stored( 2, 'licensio_order_7' ) );
    }

    /**
     * A save writes the changed positions in one batch and refreshes the caches once.
     *
     * The old rows are deleted and the new ones inserted inside one
     * transaction; articles already in place are not touched.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_save_writes_in_one_batch() {
        $this->meta = array(
            1 => array( 'licensio_order_7' => '10' ),
            2 => array( 'licensio_order_7' => '5' ),
            3 => array( 'licensio_order_7' => '40' ),
        );

        Licensio_KB_Article_Order::save( 7, array( 1, 3, 2 ) );

        $queries = $GLOBALS['wpdb']->queries;

        $this->assertCount( 4, $queries );
        $this->assertSame( 'START TRANSACTION', $queries[0] );
        $this->assertSame( "DELETE FROM `wptests_postmeta` WHERE meta_key = 'licensio_order_7' AND post_id IN ( 3, 2 )", $queries[1] );
        $this->assertStringStartsWith( "INSERT INTO `wptests_postmeta` ( post_id, meta_key, meta_value ) VALUES ( 3, 'licensio_order_7', '20' ), ( 2, 'licensio_order_7', '30' )", $queries[2] );
        $this->assertSame( 'COMMIT', $queries[3] );
        $this->assertSame( array( array( array( 3, 2 ), 'post_meta' ), 'last_changed' ), $this->cache );
        $this->assertSame( '10', $this->stored( 1, 'licensio_order_7' ) );
    }

    /**
     * A save above one batch writes two batches in one transaction and commits once.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_save_above_one_batch_writes_two_batches_and_commits_once() {
        $ids = range( 1, 501 );

        $this->assertSame( $ids, Licensio_KB_Article_Order::save( 7, $ids ) );

        $queries = $GLOBALS['wpdb']->queries;

        $this->assertCount( 6, $queries );
        $this->assertSame( 'START TRANSACTION', $queries[0] );
        $this->assertStringStartsWith( 'DELETE FROM', $queries[1] );
        $this->assertStringStartsWith( 'INSERT INTO', $queries[2] );
        $this->assertSame( 500, substr_count( $queries[2], "'licensio_order_7'" ) );
        $this->assertSame( "DELETE FROM `wptests_postmeta` WHERE meta_key = 'licensio_order_7' AND post_id IN ( 501 )", $queries[3] );
        $this->assertSame( "INSERT INTO `wptests_postmeta` ( post_id, meta_key, meta_value ) VALUES ( 501, 'licensio_order_7', '5010' )", $queries[4] );
        $this->assertSame( 'COMMIT', $queries[5] );
        $this->assertSame( array( array( $ids, 'post_meta' ), 'last_changed' ), $this->cache );
        $this->assertSame( '5010', $this->stored( 501, 'licensio_order_7' ) );
    }

    /**
     * A failed batch rolls the save back and returns an error.
     *
     * The second batch's INSERT fails: ROLLBACK is sent, COMMIT is not, the
     * caches are left alone and the caller gets a 500 error instead of the
     * order it asked for.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_failed_batch_rolls_back_and_returns_an_error() {
        $GLOBALS['wpdb']->failing_insert = 2;

        $result = Licensio_KB_Article_Order::save( 7, range( 1, 501 ) );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'licensio_kb_order_not_saved', $result->get_error_code() );
        $this->assertSame( 500, $result->get_error_data()['status'] );

        $queries = $GLOBALS['wpdb']->queries;

        $this->assertCount( 6, $queries );
        $this->assertSame( 'START TRANSACTION', $queries[0] );
        $this->assertStringStartsWith( 'INSERT INTO', $queries[4] );
        $this->assertSame( 'ROLLBACK', $queries[5] );
        $this->assertNotContains( 'COMMIT', $queries );
        $this->assertSame( array(), $this->cache, 'No cache is cleared.' );
    }

    /**
     * A partial save renumbers the other ordered articles after the listed ones.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_partial_save_renumbers_the_rest_after_the_listed_ones() {
        $this->meta = array(
            1 => array( 'licensio_order_7' => '0' ),
            2 => array( 'licensio_order_7' => '1' ),
            3 => array( 'licensio_order_7' => '2' ),
            4 => array( 'licensio_order_7' => '150' ),
            5 => array( 'licensio_order_8' => '5' ),
            6 => array(),
        );

        $this->assertSame( array( 3, 1 ), Licensio_KB_Article_Order::save( 7, array( 3, 1 ) ) );

        $this->assertSame( '10', $this->stored( 3, 'licensio_order_7' ) );
        $this->assertSame( '20', $this->stored( 1, 'licensio_order_7' ) );
        $this->assertSame( '30', $this->stored( 2, 'licensio_order_7' ), 'The rest keep their relative order.' );
        $this->assertSame( '40', $this->stored( 4, 'licensio_order_7' ), 'A far position no longer interleaves.' );
        $this->assertSame( '', $this->stored( 5, 'licensio_order_7' ), 'An article without a position stays without one.' );
        $this->assertSame( '', $this->stored( 6, 'licensio_order_7' ) );
    }

    /**
     * An article already at its place, listed or renumbered, is not written again.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_article_already_in_place_is_not_rewritten() {
        $this->meta = array(
            1 => array( 'licensio_order_7' => '10' ),
            2 => array( 'licensio_order_7' => '20' ),
        );

        Licensio_KB_Article_Order::save( 7, array( 1 ) );

        $this->assertSame( array(), $GLOBALS['wpdb']->queries, 'Nothing is written.' );
        $this->assertSame( array(), $this->cache );
    }

    /**
     * A merge appends each source's order after the target's last position.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_merge_appends_each_source_order() {
        $this->meta = array(
            1 => array(
                'licensio_order_7' => '10',
                'licensio_order_8' => '5',
            ),
            2 => array( 'licensio_order_7' => '20' ),
            3 => array( 'licensio_order_8' => '10' ),
            4 => array( 'licensio_order_8' => '20' ),
            5 => array( 'licensio_order_9' => '30' ),
            6 => array(),
        );

        ( new Licensio_KB_Article_Order() )->append_merged_order( array( 8, 9 ), 7, Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        $this->assertSame( '10', $this->stored( 1, 'licensio_order_7' ), 'An article already ordered in the target keeps its place.' );
        $this->assertSame( '20', $this->stored( 2, 'licensio_order_7' ) );
        $this->assertSame( '30', $this->stored( 3, 'licensio_order_7' ) );
        $this->assertSame( '40', $this->stored( 4, 'licensio_order_7' ) );
        $this->assertSame( '50', $this->stored( 5, 'licensio_order_7' ), 'The second source follows the first.' );
        $this->assertSame( '', $this->stored( 6, 'licensio_order_7' ) );
        $this->assertCount( 1, $GLOBALS['wpdb']->queries, 'The appended positions are written in one INSERT.' );
        $this->assertSame( array( array( array( 3, 4, 5 ), 'post_meta' ), 'last_changed' ), $this->cache );
    }

    /**
     * A merge into an unordered target starts at the first step.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_merge_into_an_unordered_target_starts_at_ten() {
        $this->meta = array(
            3 => array( 'licensio_order_8' => '4' ),
        );

        ( new Licensio_KB_Article_Order() )->append_merged_order( array( 8 ), 7, Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        $this->assertSame( '10', $this->stored( 3, 'licensio_order_7' ) );
    }

    /**
     * A tag merge has no article order to carry.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_tag_merge_writes_nothing() {
        Functions\expect( 'update_post_meta' )->never();

        ( new Licensio_KB_Article_Order() )->append_merged_order( array( 8 ), 7, Licensio_KB_Post_Types::TAXONOMY_TAG );

        $this->addToAssertionCount( 1 );
    }

    /**
     * Deleting a category deletes its key from every article.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_deleting_a_category_deletes_its_keys() {
        Functions\expect( 'delete_metadata' )->once()->with( 'post', 0, 'licensio_order_7', '', true );

        ( new Licensio_KB_Article_Order() )->delete_term_order( 7, Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        $this->addToAssertionCount( 1 );
    }

    /**
     * Another taxonomy's delete leaves the order keys alone.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_another_taxonomy_delete_is_ignored() {
        Functions\expect( 'delete_metadata' )->never();

        ( new Licensio_KB_Article_Order() )->delete_term_order( 7, Licensio_KB_Post_Types::TAXONOMY_TAG );

        $this->addToAssertionCount( 1 );
    }

    /**
     * One value from the in-memory meta store.
     *
     * @since 1.0.0
     *
     * @param int    $post_id The post.
     * @param string $key     The meta key.
     *
     * @return mixed The value, or an empty string when there is none.
     */
    private function stored( $post_id, $key ) {
        return isset( $this->meta[ $post_id ][ $key ] ) ? $this->meta[ $post_id ][ $key ] : '';
    }
}
