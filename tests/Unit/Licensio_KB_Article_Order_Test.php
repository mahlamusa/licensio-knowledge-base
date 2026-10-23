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
 * A query double that can be the main query of a category archive.
 *
 * @since 1.0.0
 */
class Licensio_KB_Article_Order_Query extends WP_Query {

    /**
     * The parsed tax query.
     *
     * @since 1.0.0
     * @var WP_Tax_Query|null
     */
    public $tax_query = null;

    /**
     * Whether this is the main query of a category archive.
     *
     * @since 1.0.0
     * @var bool
     */
    public $archive = false;

    /**
     * Whether this is a search.
     *
     * @since 1.0.0
     * @var bool
     */
    public $search = false;

    /**
     * Whether this is a feed.
     *
     * @since 1.0.0
     * @var bool
     */
    public $feed = false;

    /**
     * Whether this is the main query.
     *
     * @since 1.0.0
     *
     * @return bool The archive flag.
     */
    public function is_main_query() {
        return $this->archive;
    }

    /**
     * Whether this is a search.
     *
     * @since 1.0.0
     *
     * @return bool The search flag.
     */
    public function is_search() {
        return $this->search;
    }

    /**
     * Whether this is a feed.
     *
     * @since 1.0.0
     *
     * @return bool The feed flag.
     */
    public function is_feed() {
        return $this->feed;
    }
}

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
     * Back the meta functions with the in-memory store.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->meta = array();

        Functions\when( 'absint' )->alias(
            function ( $value ) {
                return abs( (int) $value );
            }
        );

        Functions\when( 'get_posts' )->alias(
            function ( $args ) {
                $key = $args['meta_key'];
                $ids = array();

                foreach ( $this->meta as $id => $values ) {
                    if ( isset( $values[ $key ] ) ) {
                        $ids[ $id ] = (int) $values[ $key ];
                    }
                }

                asort( $ids );

                return array_keys( $ids );
            }
        );

        Functions\when( 'update_meta_cache' )->justReturn( array() );

        Functions\when( 'get_post_meta' )->alias(
            function ( $id, $key ) {
                return isset( $this->meta[ $id ][ $key ] ) ? $this->meta[ $id ][ $key ] : '';
            }
        );

        Functions\when( 'update_post_meta' )->alias(
            function ( $id, $key, $value ) {
                $this->meta[ $id ][ $key ] = $value;

                return true;
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
     * A query for one category clause.
     *
     * @since 1.0.0
     *
     * @param array<int|string, mixed> $tax_queries The parsed tax clauses.
     * @param array<string, mixed>     $vars        The query variables.
     *
     * @return Licensio_KB_Article_Order_Query The query.
     */
    private function query( array $tax_queries, array $vars = array() ) {
        $query            = new Licensio_KB_Article_Order_Query( array_merge( array( 'orderby' => 'licensio_order' ), $vars ) );
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
        $query          = $this->query( array( $this->clause( 'billing', 'slug' ) ), array( 'orderby' => '' ) );
        $query->archive = true;

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
        $query          = $this->query( array( $this->clause( 7 ) ), array( 'orderby' => 'date' ) );
        $query->archive = true;

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
        foreach ( array( 'search', 'feed' ) as $flag ) {
            $query          = $this->query( array( $this->clause( 7 ) ), array( 'orderby' => '' ) );
            $query->archive = true;
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
        $orderby        = array( 'date' => 'DESC' );
        $query          = $this->query( array( $this->clause( 7 ) ), array( 'orderby' => $orderby ) );
        $query->archive = true;

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

        $query          = $this->query( array( $this->clause( 7 ) ), array( 'orderby' => '' ) );
        $query->archive = true;

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
        $this->assertSame( '`licensio_order_meta`.meta_value IS NULL ASC, CAST(`licensio_order_meta`.meta_value AS SIGNED) ASC, `wptests_posts`.post_title ASC, `wptests_posts`.ID ASC', $clauses['orderby'] );
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

        $this->assertSame( '`licensio_order_meta`.meta_value IS NULL ASC, CAST(`licensio_order_meta`.meta_value AS SIGNED) DESC, `wptests_posts`.post_title DESC, `wptests_posts`.ID DESC', $clauses['orderby'] );
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
     * Saving writes 10, 20, 30 in the order given.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_save_writes_steps_of_ten() {
        $this->assertSame( array( 3, 1, 2 ), Licensio_KB_Article_Order::save( 7, array( 3, 1, 2 ) ) );

        $this->assertSame( 10, $this->stored( 3, 'licensio_order_7' ) );
        $this->assertSame( 20, $this->stored( 1, 'licensio_order_7' ) );
        $this->assertSame( 30, $this->stored( 2, 'licensio_order_7' ) );
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

        $this->assertSame( 10, $this->stored( 3, 'licensio_order_7' ) );
        $this->assertSame( 20, $this->stored( 1, 'licensio_order_7' ) );
        $this->assertSame( 30, $this->stored( 2, 'licensio_order_7' ), 'The rest keep their relative order.' );
        $this->assertSame( 40, $this->stored( 4, 'licensio_order_7' ), 'A far position no longer interleaves.' );
        $this->assertSame( '', $this->stored( 5, 'licensio_order_7' ), 'An article without a position stays without one.' );
        $this->assertSame( '', $this->stored( 6, 'licensio_order_7' ) );
    }

    /**
     * A renumbered article already at its place is not written again.
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

        $writes = array();

        Functions\when( 'update_post_meta' )->alias(
            function ( $id, $key, $value ) use ( &$writes ) {
                $writes[] = $id;

                $this->meta[ $id ][ $key ] = $value;

                return true;
            }
        );

        Licensio_KB_Article_Order::save( 7, array( 1 ) );

        $this->assertSame( array( 1 ), $writes );
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
        $this->assertSame( 30, $this->stored( 3, 'licensio_order_7' ) );
        $this->assertSame( 40, $this->stored( 4, 'licensio_order_7' ) );
        $this->assertSame( 50, $this->stored( 5, 'licensio_order_7' ), 'The second source follows the first.' );
        $this->assertSame( '', $this->stored( 6, 'licensio_order_7' ) );
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

        $this->assertSame( 10, $this->stored( 3, 'licensio_order_7' ) );
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
