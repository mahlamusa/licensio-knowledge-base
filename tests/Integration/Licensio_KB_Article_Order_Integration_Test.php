<?php
/**
 * Per-category article order against a live server.
 *
 * No WordPress is loaded in this suite, so `WP_Query` cannot run. What runs
 * here instead is the SQL WordPress builds for a query prepared by
 * `Licensio_KB_Article_Order`: core's tax query join and WHERE, copied from a
 * real request, with the JOIN and ORDER BY the class itself writes in
 * `posts_clauses`. That is the part a double could get wrong, and it is what
 * every listing (category archive, category cards, Article List block and the
 * order route) sorts by.
 *
 * The writes go through the class and the REST controller as in production;
 * the post meta and term functions they call are backed by plain SQL on the
 * scratch tables (the term functions by the shared live term API), so each
 * test reads back rows that are really there.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/*
 * This suite connects to a real database on purpose. The WordPress Database API
 * is therefore not available or appropriate here.
 */
// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery

require_once __DIR__ . '/Licensio_KB_Analytics_DB_Test_Case.php';
require_once __DIR__ . '/trait-licensio-kb-content-fixtures.php';
require_once __DIR__ . '/trait-licensio-kb-live-term-api.php';

/**
 * Exercises the per-category order on real rows.
 *
 * @since 1.0.0
 */
class Licensio_KB_Article_Order_Integration_Test extends Licensio_KB_Analytics_DB_Test_Case {

    use Licensio_KB_Content_Fixtures;
    use Licensio_KB_Live_Term_API;

    /**
     * Add the core tables and back the meta and term functions with SQL.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->install_content_schemas();

        // The SQL-backed term API the category suites share.
        $this->stub_category_term_api();
        Brain\Monkey\Functions\when( 'rest_authorization_required_code' )->justReturn( 403 );
        Brain\Monkey\Functions\when( 'current_user_can' )->justReturn( true );
        Brain\Monkey\Functions\when( 'update_meta_cache' )->justReturn( array() );

        Brain\Monkey\Functions\when( 'get_post_meta' )->alias(
            function ( $post_id, $key ) {
                $row = $this->row( 'SELECT meta_value FROM ' . $this->table( 'postmeta' ) . ' WHERE post_id = ' . (int) $post_id . ' AND meta_key = ' . $this->live_quote( $key ) . ' LIMIT 1' );

                return null === $row ? '' : (string) $row[0];
            }
        );

        Brain\Monkey\Functions\when( 'update_post_meta' )->alias(
            function ( $post_id, $key, $value ) {
                $this->set_order_row( (int) $post_id, (string) $key, (string) $value );

                return true;
            }
        );

        Brain\Monkey\Functions\when( 'delete_metadata' )->alias(
            function ( $type, $object_id, $key, $value, $delete_all ) {
                $this->assertSame( array( 'post', 0, '', true ), array( $type, $object_id, $value, $delete_all ) );

                return (bool) $this->db->query( 'DELETE FROM ' . $this->table( 'postmeta' ) . ' WHERE meta_key = ' . $this->live_quote( $key ) );
            }
        );

        Brain\Monkey\Functions\when( 'get_posts' )->alias( array( $this, 'get_posts' ) );
    }

    /**
     * The `get_posts()` calls the order code makes, answered with SQL.
     *
     * Two shapes are used: the positions of one category (`meta_key`, sorted
     * numerically) and the save's membership check (`post__in` within one
     * category, children excluded). Trash is left out unless the status list
     * names it beside `any`, as core does.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $args The query arguments.
     *
     * @return int[] The post IDs.
     */
    public function get_posts( $args ) {
        $posts  = $this->table( 'posts' );
        $status = in_array( 'trash', (array) $args['post_status'], true ) ? '1=1' : "p.post_status <> 'trash'";

        if ( isset( $args['meta_key'] ) ) {
            $sql = "SELECT p.ID FROM {$posts} p INNER JOIN " . $this->table( 'postmeta' ) . ' m ON (p.ID = m.post_id AND m.meta_key = ' . $this->live_quote( $args['meta_key'] ) . ")
                WHERE {$status} ORDER BY CAST(m.meta_value AS SIGNED) ASC, p.ID ASC";
        } else {
            $term = (int) $args['tax_query'][0]['terms'];
            $sql  = "SELECT p.ID FROM {$posts} p
                INNER JOIN " . $this->table( 'term_relationships' ) . ' r ON (p.ID = r.object_id)
                INNER JOIN ' . $this->table( 'term_taxonomy' ) . " t ON (r.term_taxonomy_id = t.term_taxonomy_id AND t.term_id = {$term})
                WHERE {$status} AND p.ID IN (" . implode( ',', array_map( 'intval', $args['post__in'] ) ) . ')';
        }

        return array_map( 'intval', array_column( $this->rows( $sql ), 0 ) );
    }

    /**
     * The article titles a per-category listing returns, in order.
     *
     * The FROM, tax query JOIN, WHERE and GROUP BY are the ones core emits for
     * a one-category query; the order JOIN and ORDER BY are the class's own.
     *
     * @since 1.0.0
     *
     * @param int    $term_id          The category.
     * @param bool   $include_children Whether articles of sub-categories are listed too.
     * @param string $direction        `ASC` or `DESC`.
     *
     * @return string[] The titles.
     */
    private function listed( $term_id, $include_children = false, $direction = 'ASC' ) {
        $posts = $this->table( 'posts' );
        $terms = $include_children
            ? '(t.term_id = ' . (int) $term_id . ' OR t.parent = ' . (int) $term_id . ')'
            : 't.term_id = ' . (int) $term_id;

        $sql = "SELECT {$posts}.post_title FROM {$posts}
            LEFT JOIN " . $this->table( 'term_relationships' ) . " r ON ({$posts}.ID = r.object_id)
            LEFT JOIN " . $this->table( 'term_taxonomy' ) . ' t ON (r.term_taxonomy_id = t.term_taxonomy_id)
            ' . Licensio_KB_Article_Order::join_sql( $term_id ) . "
            WHERE 1=1 AND {$terms}
              AND {$posts}.post_type = 'licensio_article' AND {$posts}.post_status <> 'trash'
            GROUP BY {$posts}.ID
            ORDER BY " . Licensio_KB_Article_Order::orderby_sql( $direction );

        return array_column( $this->rows( $sql ), 0 );
    }

    /**
     * Ordered articles come first by position, then unordered ones by title.
     *
     * The unordered articles carry other meta, or none at all, on purpose:
     * ordering by core's own meta clause would sort them by those unrelated
     * values, or put them first.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_ordered_articles_come_first_then_the_rest_by_title() {
        $cat = $this->make_term( 'Billing', Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        $delta   = $this->make_article(
            array(
                'title'      => 'Delta',
                'categories' => array( $cat ),
            )
        );
        $alpha   = $this->make_article(
            array(
                'title'      => 'Alpha',
                'categories' => array( $cat ),
            )
        );
        $charlie = $this->make_article(
            array(
                'title'      => 'Charlie',
                'categories' => array( $cat ),
            )
        );
        $bravo   = $this->make_article(
            array(
                'title'      => 'Bravo',
                'categories' => array( $cat ),
            )
        );
        $this->make_article(
            array(
                'title'      => 'Echo',
                'categories' => array( $cat ),
            )
        );

        $this->set_order_row( $charlie, Licensio_KB_Article_Order::meta_key( $cat ), '20' );
        $this->set_order_row( $delta, Licensio_KB_Article_Order::meta_key( $cat ), '10' );
        $this->set_order_row( $alpha, '_edit_lock', '999999' );
        $this->set_order_row( $bravo, '_edit_lock', '-5' );

        $this->assertSame( array( 'Delta', 'Charlie', 'Alpha', 'Bravo', 'Echo' ), $this->listed( $cat ) );
    }

    /**
     * A save through the route is what the next listing returns.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_saved_order_is_what_the_listing_returns() {
        $cat = $this->make_term( 'Billing', Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        $a = $this->make_article(
            array(
                'title'      => 'Alpha',
                'categories' => array( $cat ),
            )
        );
        $b = $this->make_article(
            array(
                'title'      => 'Bravo',
                'categories' => array( $cat ),
                'status'     => 'draft',
            )
        );
        $c = $this->make_article(
            array(
                'title'      => 'Charlie',
                'categories' => array( $cat ),
            )
        );
        $this->make_article(
            array(
                'title'      => 'Zulu',
                'categories' => array( $cat ),
            )
        );
        $this->make_article(
            array(
                'title'      => 'Binned',
                'categories' => array( $cat ),
                'status'     => 'trash',
            )
        );

        $saved = ( new Licensio_KB_Category_Order_REST_Controller() )->update_order( $this->request( $cat, array( $c, $a, $b ) ) );

        $this->assertSame( array( 'ids' => array( $c, $a, $b ) ), $saved );
        $this->assertSame( '10', $this->meta_value( $c, $cat ) );
        $this->assertSame( '20', $this->meta_value( $a, $cat ) );
        $this->assertSame( '30', $this->meta_value( $b, $cat ) );
        $this->assertSame( array( 'Charlie', 'Alpha', 'Bravo', 'Zulu' ), $this->listed( $cat ), 'Trash is never listed; the unordered article comes last.' );

        ( new Licensio_KB_Category_Order_REST_Controller() )->update_order( $this->request( $cat, array( $a, $c ) ) );

        $this->assertSame( array( 'Alpha', 'Charlie', 'Bravo', 'Zulu' ), $this->listed( $cat ), 'A partial save puts the rest after it, in their order.' );
        $this->assertSame( '30', $this->meta_value( $b, $cat ) );
    }

    /**
     * Saving the first page of a long list never interleaves the rest.
     *
     * Imported positions are often small and dense (0, 1, 2…) while a saved
     * order uses steps of ten, so without renumbering an unlisted article at
     * position 15 would land between the first and second listed ones.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_partial_save_never_interleaves_the_rest() {
        $cat = $this->make_term( 'Billing', Licensio_KB_Post_Types::TAXONOMY_CATEGORY );
        $key = Licensio_KB_Article_Order::meta_key( $cat );
        $ids = array();

        foreach ( array( 'A', 'B', 'C', 'D', 'E' ) as $index => $title ) {
            $ids[ $title ] = $this->make_article(
                array(
                    'title'      => $title,
                    'categories' => array( $cat ),
                )
            );

            $this->set_order_row( $ids[ $title ], $key, (string) ( $index * 15 ) );
        }

        $binned = $this->make_article(
            array(
                'title'      => 'Binned',
                'categories' => array( $cat ),
                'status'     => 'trash',
            )
        );
        $this->set_order_row( $binned, $key, '20' );

        $this->make_article(
            array(
                'title'      => 'Unordered',
                'categories' => array( $cat ),
            )
        );

        ( new Licensio_KB_Category_Order_REST_Controller() )->update_order( $this->request( $cat, array( $ids['D'], $ids['B'] ) ) );

        $this->assertSame( array( 'D', 'B', 'A', 'C', 'E', 'Unordered' ), $this->listed( $cat ) );
        $this->assertSame( '10', $this->meta_value( $ids['D'], $cat ) );
        $this->assertSame( '20', $this->meta_value( $ids['B'], $cat ) );
        $this->assertSame( '30', $this->meta_value( $ids['A'], $cat ) );
        $this->assertSame( '40', $this->meta_value( $binned, $cat ), 'A trashed article keeps its relative place.' );
        $this->assertSame( '50', $this->meta_value( $ids['C'], $cat ) );
        $this->assertSame( '60', $this->meta_value( $ids['E'], $cat ) );
    }

    /**
     * Descending reverses the positions, and unordered articles still come last.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_descending_reverses_the_positions_with_unordered_last() {
        $cat = $this->make_term( 'Billing', Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        $first  = $this->make_article(
            array(
                'title'      => 'First',
                'categories' => array( $cat ),
            )
        );
        $second = $this->make_article(
            array(
                'title'      => 'Second',
                'categories' => array( $cat ),
            )
        );
        $this->make_article(
            array(
                'title'      => 'Alpha unordered',
                'categories' => array( $cat ),
            )
        );

        Licensio_KB_Article_Order::save( $cat, array( $first, $second ) );

        $this->assertSame( array( 'First', 'Second', 'Alpha unordered' ), $this->listed( $cat ) );
        $this->assertSame( array( 'Second', 'First', 'Alpha unordered' ), $this->listed( $cat, false, 'DESC' ) );
    }

    /**
     * The Order set on an article breaks ties and orders the unpositioned ones.
     *
     * Two articles share a position, and the unordered ones carry Order values
     * that disagree with their titles: `menu_order` wins over the title in
     * both cases. Descending reverses it too, while the unordered articles
     * stay last.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_menu_order_breaks_ties_before_the_title() {
        $cat = $this->make_term( 'Billing', Licensio_KB_Post_Types::TAXONOMY_CATEGORY );
        $key = Licensio_KB_Article_Order::meta_key( $cat );

        $tied_a = $this->make_article(
            array(
                'title'      => 'Alpha tied',
                'categories' => array( $cat ),
                'menu_order' => 2,
            )
        );
        $tied_b = $this->make_article(
            array(
                'title'      => 'Bravo tied',
                'categories' => array( $cat ),
                'menu_order' => 1,
            )
        );
        $this->make_article(
            array(
                'title'      => 'Charlie unordered',
                'categories' => array( $cat ),
                'menu_order' => 5,
            )
        );
        $this->make_article(
            array(
                'title'      => 'Delta unordered',
                'categories' => array( $cat ),
                'menu_order' => 3,
            )
        );

        $this->set_order_row( $tied_a, $key, '10' );
        $this->set_order_row( $tied_b, $key, '10' );

        $this->assertSame( array( 'Bravo tied', 'Alpha tied', 'Delta unordered', 'Charlie unordered' ), $this->listed( $cat ) );
        $this->assertSame( array( 'Alpha tied', 'Bravo tied', 'Charlie unordered', 'Delta unordered' ), $this->listed( $cat, false, 'DESC' ) );
    }

    /**
     * An article from another category, or in the trash, stops the save.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_save_naming_a_stray_article_writes_nothing() {
        $cat   = $this->make_term( 'Billing', Licensio_KB_Post_Types::TAXONOMY_CATEGORY );
        $other = $this->make_term( 'Install', Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        $a       = $this->make_article( array( 'categories' => array( $cat ) ) );
        $outside = $this->make_article( array( 'categories' => array( $other ) ) );
        $binned  = $this->make_article(
            array(
                'categories' => array( $cat ),
                'status'     => 'trash',
            )
        );

        $result = ( new Licensio_KB_Category_Order_REST_Controller() )->update_order( $this->request( $cat, array( $a, $outside, $binned ) ) );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( array( $outside, $binned ), $result->get_error_data()['ids'] );
        $this->assertSame( 0, $this->count_rows( 'postmeta', "meta_key LIKE 'licensio\\_order\\_%'" ) );
    }

    /**
     * Each category keeps its own order for the same article.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_each_category_keeps_its_own_order() {
        $one = $this->make_term( 'One', Licensio_KB_Post_Types::TAXONOMY_CATEGORY );
        $two = $this->make_term( 'Two', Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        $a = $this->make_article(
            array(
                'title'      => 'Alpha',
                'categories' => array( $one, $two ),
            )
        );
        $b = $this->make_article(
            array(
                'title'      => 'Bravo',
                'categories' => array( $one, $two ),
            )
        );

        Licensio_KB_Article_Order::save( $one, array( $b, $a ) );
        Licensio_KB_Article_Order::save( $two, array( $a, $b ) );

        $this->assertSame( array( 'Bravo', 'Alpha' ), $this->listed( $one ) );
        $this->assertSame( array( 'Alpha', 'Bravo' ), $this->listed( $two ) );
    }

    /**
     * A parent's listing puts its own ordered articles first, then sub-category articles by title.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_sub_category_articles_follow_the_parent_order() {
        $tree = $this->make_category_tree(
            array(
                'Parent' => array(
                    'Child' => array(),
                ),
            )
        );

        $own = $this->make_article(
            array(
                'title'      => 'Own',
                'categories' => array( $tree['Parent'] ),
            )
        );
        $this->make_article(
            array(
                'title'      => 'A child article',
                'categories' => array( $tree['Child'] ),
            )
        );

        Licensio_KB_Article_Order::save( $tree['Parent'], array( $own ) );

        $this->assertSame( array( 'Own', 'A child article' ), $this->listed( $tree['Parent'], true ) );
    }

    /**
     * A merge appends the source order after the target's, before the source is deleted.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_merge_appends_the_source_order() {
        $target = $this->make_term( 'Target', Licensio_KB_Post_Types::TAXONOMY_CATEGORY );
        $source = $this->make_term( 'Source', Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        $t1 = $this->make_article(
            array(
                'title'      => 'T1',
                'categories' => array( $target ),
            )
        );
        $t2 = $this->make_article(
            array(
                'title'      => 'T2',
                'categories' => array( $target ),
            )
        );
        $s1 = $this->make_article(
            array(
                'title'      => 'S1',
                'categories' => array( $target ),
            )
        );
        $s2 = $this->make_article(
            array(
                'title'      => 'S2',
                'categories' => array( $target ),
            )
        );

        $binned = $this->make_article(
            array(
                'title'      => 'Binned',
                'categories' => array( $target ),
                'status'     => 'trash',
            )
        );

        Licensio_KB_Article_Order::save( $target, array( $t2, $t1 ) );
        Licensio_KB_Article_Order::save( $source, array( $s2, $t1, $s1 ) );
        $this->set_order_row( $binned, Licensio_KB_Article_Order::meta_key( $source ), '35' );

        $order = new Licensio_KB_Article_Order();
        $order->append_merged_order( array( $source ), $target, Licensio_KB_Post_Types::TAXONOMY_CATEGORY );
        $order->delete_term_order( $source, Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        $this->assertSame( '30', $this->meta_value( $s2, $target ) );
        $this->assertSame( '40', $this->meta_value( $s1, $target ) );
        $this->assertSame( '50', $this->meta_value( $binned, $target ), 'A trashed article keeps a place for when it is restored.' );
        $this->assertSame( '20', $this->meta_value( $t1, $target ), 'An article already ordered in the target keeps its place.' );
        $this->assertSame( array( 'T2', 'T1', 'S2', 'S1' ), $this->listed( $target ) );
    }

    /**
     * The duplicates fix carries a duplicate category's order to the kept one.
     *
     * The fix moves the articles and then deletes the duplicate, and
     * deleting a category drops its order. The merged hook must fire between
     * the two, as it does for a merge from the Categories app, so the kept
     * category ends up with the order. Hooks do not run in
     * this suite, so the order class is wired to the merged hook and to the
     * term delete here, the way the plugin wires them.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_duplicates_fix_carries_the_order_to_the_kept_category() {
        $kept      = $this->make_term( 'Billing', Licensio_KB_Post_Types::TAXONOMY_CATEGORY );
        $duplicate = $this->make_term( 'Billing 2', Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        $this->db->query( 'UPDATE ' . $this->table( 'terms' ) . " SET name = 'Billing' WHERE term_id = " . (int) $duplicate );

        $first  = $this->make_article(
            array(
                'title'      => 'First',
                'categories' => array( $kept ),
            )
        );
        $second = $this->make_article(
            array(
                'title'      => 'Second',
                'categories' => array( $duplicate ),
            )
        );
        $third  = $this->make_article(
            array(
                'title'      => 'Third',
                'categories' => array( $duplicate ),
            )
        );

        Licensio_KB_Article_Order::save( $kept, array( $first ) );
        Licensio_KB_Article_Order::save( $duplicate, array( $third, $second ) );

        $order  = new Licensio_KB_Article_Order();
        $events = array();

        Brain\Monkey\Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
        Brain\Monkey\Functions\when( 'get_term_meta' )->justReturn( array() );
        Brain\Monkey\Functions\when( 'clean_object_term_cache' )->alias(
            function ( $object_ids, $object_type ) use ( &$events ) {
                $events[] = 'clean ' . implode( ',', $object_ids ) . ' ' . $object_type;
            }
        );
        Brain\Monkey\Functions\when( 'do_action' )->alias(
            function ( $hook, ...$args ) use ( $order, $duplicate, $kept, &$events ) {
                if ( 'licensio_kb_terms_merged' !== $hook ) {
                    return;
                }

                $this->assertSame( array( array( $duplicate ), $kept, Licensio_KB_Post_Types::TAXONOMY_CATEGORY ), $args );

                $events[] = 'merged';
                $order->append_merged_order( ...$args );
            }
        );
        Brain\Monkey\Functions\when( 'wp_delete_term' )->alias(
            function ( $term_id, $taxonomy ) use ( $order, &$events ) {
                $events[] = 'delete ' . $term_id;
                $order->delete_term_order( $term_id, $taxonomy );

                return $this->live_delete_term( $term_id, $taxonomy );
            }
        );

        $report = ( new Licensio_KB_Duplicates() )->fix();

        $this->assertSame( 1, $report['term_groups_merged'] );
        $this->assertSame(
            array( 'clean ' . $second . ',' . $third . ' ' . Licensio_KB_Post_Types::POST_TYPE, 'merged', 'delete ' . $duplicate ),
            $events,
            'The moved articles\' term caches are cleaned, and the order is carried over before the duplicate is deleted.'
        );
        $this->assertSame( '20', $this->meta_value( $third, $kept ) );
        $this->assertSame( '30', $this->meta_value( $second, $kept ) );
        $this->assertSame( 0, $this->count_rows( 'postmeta', 'meta_key = ' . $this->live_quote( Licensio_KB_Article_Order::meta_key( $duplicate ) ) ) );
        $this->assertSame( array( 'First', 'Third', 'Second' ), $this->listed( $kept ) );
    }

    /**
     * Deleting a category removes its keys from every article and no other key.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_deleting_a_category_removes_its_keys() {
        $gone = $this->make_term( 'Gone', Licensio_KB_Post_Types::TAXONOMY_CATEGORY );
        $kept = $this->make_term( 'Kept', Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        $a = $this->make_article( array( 'categories' => array( $gone, $kept ) ) );
        $b = $this->make_article( array( 'categories' => array( $gone ) ) );

        Licensio_KB_Article_Order::save( $gone, array( $a, $b ) );
        Licensio_KB_Article_Order::save( $kept, array( $a ) );

        ( new Licensio_KB_Article_Order() )->delete_term_order( $gone, Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        $this->assertSame( 0, $this->count_rows( 'postmeta', 'meta_key = ' . $this->live_quote( Licensio_KB_Article_Order::meta_key( $gone ) ) ) );
        $this->assertSame( '10', $this->meta_value( $a, $kept ) );
    }

    /**
     * An order request.
     *
     * @since 1.0.0
     *
     * @param int   $term_id The category.
     * @param int[] $ids     The article IDs.
     *
     * @return WP_REST_Request The request.
     */
    private function request( $term_id, array $ids ) {
        return new WP_REST_Request(
            array(),
            '/licensio-kb/v1/categories/' . $term_id . '/articles/order',
            array(
                'id'  => $term_id,
                'ids' => $ids,
            )
        );
    }

    /**
     * Replace one post meta row.
     *
     * @since 1.0.0
     *
     * @param int    $post_id The post.
     * @param string $key     The meta key.
     * @param string $value   The value.
     *
     * @return void
     */
    private function set_order_row( $post_id, $key, $value ) {
        $this->db->query( 'DELETE FROM ' . $this->table( 'postmeta' ) . ' WHERE post_id = ' . (int) $post_id . ' AND meta_key = ' . $this->live_quote( $key ) );

        $this->seed(
            'postmeta',
            array(
                array(
                    'post_id'    => (int) $post_id,
                    'meta_key'   => $key,
                    'meta_value' => $value,
                ),
            )
        );
    }

    /**
     * An article's stored position in a category.
     *
     * @since 1.0.0
     *
     * @param int $post_id The article.
     * @param int $term_id The category.
     *
     * @return string|null The stored value, or null when there is none.
     */
    private function meta_value( $post_id, $term_id ) {
        $row = $this->row( 'SELECT meta_value FROM ' . $this->table( 'postmeta' ) . ' WHERE post_id = ' . (int) $post_id . ' AND meta_key = ' . $this->live_quote( Licensio_KB_Article_Order::meta_key( $term_id ) ) );

        return null === $row ? null : (string) $row[0];
    }

    /**
     * Every row of a query, as numeric arrays.
     *
     * @since 1.0.0
     *
     * @param string $sql The query.
     *
     * @return array<int, array<int, string>> The rows.
     */
    private function rows( $sql ) {
        $result = $this->db->query( $sql );

        $this->assertInstanceOf( 'mysqli_result', $result, $this->db->error );

        return $result->fetch_all( MYSQLI_NUM );
    }

    /**
     * The first row of a query.
     *
     * @since 1.0.0
     *
     * @param string $sql The query.
     *
     * @return array<int, string>|null The row, or null when there is none.
     */
    private function row( $sql ) {
        $rows = $this->rows( $sql );

        return empty( $rows ) ? null : $rows[0];
    }
}
