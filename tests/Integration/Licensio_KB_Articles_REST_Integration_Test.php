<?php
/**
 * The All Articles list and its ID selection, against a real database server.
 *
 * `GET articles` and `GET articles/ids` hand their filters to WP_Query as
 * arguments built by Licensio_KB_Articles_REST_Controller::query_args(). No
 * WordPress is loaded in this suite, so WP_Query cannot run. What runs here
 * instead is the controller's own `get_items()` and `get_ids()`, with
 * `run_query()` answered by the SQL core emits for exactly the argument
 * shapes the controller builds: the post type and status list, the author,
 * the category (with every descendant), tag and NOT EXISTS taxonomy clauses
 * joined with AND, the inclusive whole-day date range, the field and ID
 * order, and the LIMIT. Any argument the translator does not know fails the
 * test, so a change to the arguments cannot pass silently.
 *
 * `perm => readable` is accepted but not mirrored: core limits private
 * articles to their author for a user who cannot read private posts, while
 * the translator returns them all, as core does for a user who can.
 *
 * The translator is test code that mirrors core; what these tests prove is
 * that the controller's arguments pick the right rows, in the right order,
 * with the right totals, under core's SQL. The per-item fields and the views
 * and rating clauses are covered elsewhere (the unit suite and the article
 * metrics integration suite), so `prepare_items()` answers with the IDs.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/*
 * This suite connects to a real database on purpose. The WordPress Database API
 * is therefore not available or appropriate here.
 */
// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery

require_once __DIR__ . '/Licensio_KB_Analytics_DB_Test_Case.php';
require_once __DIR__ . '/trait-licensio-kb-content-fixtures.php';
require_once __DIR__ . '/class-licensio-kb-articles-rest-live-controller.php';

use Brain\Monkey\Functions;

/**
 * Runs the articles list and ID routes on real rows.
 *
 * @since 1.0.0
 */
class Licensio_KB_Articles_REST_Integration_Test extends Licensio_KB_Analytics_DB_Test_Case {

    use Licensio_KB_Content_Fixtures;

    /**
     * The controller under test.
     *
     * @since 1.0.0
     * @var Licensio_KB_Articles_REST_Live_Controller
     */
    private $controller;

    /**
     * Add the core tables and stub the response and status count helpers.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->install_content_schemas();

        Functions\when( 'rest_ensure_response' )->returnArg();
        Functions\when( 'wp_count_posts' )->alias(
            function ( $type ) {
                $result = $this->db->query(
                    "SELECT post_status, COUNT(*) AS total FROM {$this->adapter->posts} WHERE post_type = "
                    . $this->adapter->quote( $type ) . ' GROUP BY post_status'
                );
                $counts = array();

                while ( $row = $result->fetch_object() ) {
                    $counts[ $row->post_status ] = (string) $row->total;
                }

                return (object) $counts;
            }
        );

        $this->controller = new Licensio_KB_Articles_REST_Live_Controller( $this->adapter );
    }

    /**
     * Run `GET articles` with some parameters.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $params The request parameters.
     *
     * @return mixed The response body, an array: rest_ensure_response() is stubbed to hand it back.
     */
    private function list_articles( array $params = array() ) {
        return $this->controller->get_items( new WP_REST_Request( array(), '/licensio-kb/v1/articles', $params ) );
    }

    /**
     * The article IDs `GET articles` lists, in order.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $params The request parameters.
     *
     * @return int[] The IDs.
     */
    private function listed( array $params = array() ) {
        return $this->list_articles( $params )['items'];
    }

    /**
     * Run `GET articles/ids` with some parameters.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $params The request parameters.
     *
     * @return mixed The response body, an array: rest_ensure_response() is stubbed to hand it back.
     */
    private function ids( array $params = array() ) {
        return $this->controller->get_ids( new WP_REST_Request( array(), '/licensio-kb/v1/articles/ids', $params ) );
    }

    /**
     * Sort a list of IDs, for comparing sets.
     *
     * @since 1.0.0
     *
     * @param int[] $ids The IDs.
     *
     * @return int[] The IDs, ascending.
     */
    private static function sorted( array $ids ) {
        sort( $ids );

        return $ids;
    }

    /**
     * A category lists its own articles and those of every sub-category, at any depth.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_category_filter_includes_every_descendant() {
        $tree = $this->make_category_tree(
            array(
                'Getting started' => array(
                    'Install' => array(
                        'Linux' => array(),
                    ),
                ),
                'Billing'         => array(),
            )
        );

        $top        = $this->make_article( array( 'categories' => array( $tree['Getting started'] ) ) );
        $child      = $this->make_article( array( 'categories' => array( $tree['Install'] ) ) );
        $grandchild = $this->make_article( array( 'categories' => array( $tree['Linux'] ) ) );
        $billing    = $this->make_article( array( 'categories' => array( $tree['Billing'] ) ) );
        $both       = $this->make_article( array( 'categories' => array( $tree['Linux'], $tree['Billing'] ) ) );

        $this->make_article();

        $this->assertSame(
            self::sorted( array( $top, $child, $grandchild, $both ) ),
            self::sorted( $this->listed( array( 'category' => $tree['Getting started'] ) ) )
        );
        $this->assertSame(
            self::sorted( array( $child, $grandchild, $both ) ),
            self::sorted( $this->listed( array( 'category' => $tree['Install'] ) ) ),
            'A sub-category lists its own subtree, not its parent.'
        );
        $this->assertSame(
            self::sorted( array( $billing, $both ) ),
            self::sorted( $this->listed( array( 'category' => $tree['Billing'] ) ) )
        );
        $this->assertSame( 4, $this->list_articles( array( 'category' => $tree['Getting started'] ) )['total'], 'An article in two matching categories counts once.' );
    }

    /**
     * A tag lists only its own articles.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_tag_filter() {
        $how_to = $this->make_term( 'How to' );
        $faq    = $this->make_term( 'FAQ' );

        $one  = $this->make_article( array( 'tags' => array( $how_to ) ) );
        $two  = $this->make_article( array( 'tags' => array( $how_to, $faq ) ) );
        $none = $this->make_article( array( 'tags' => array( $faq ) ) );

        $this->make_article();

        $this->assertSame( self::sorted( array( $one, $two ) ), self::sorted( $this->listed( array( 'tag' => $how_to ) ) ) );
        $this->assertSame( self::sorted( array( $two, $none ) ), self::sorted( $this->listed( array( 'tag' => $faq ) ) ) );
    }

    /**
     * An author lists only the articles they wrote.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_author_filter() {
        $mine   = $this->make_article( array( 'author' => 2 ) );
        $also   = $this->make_article(
            array(
                'author' => 2,
                'status' => 'draft',
            )
        );
        $theirs = $this->make_article( array( 'author' => 3 ) );

        $this->assertSame( self::sorted( array( $mine, $also ) ), self::sorted( $this->listed( array( 'author' => 2 ) ) ) );
        $this->assertSame( array( $theirs ), $this->listed( array( 'author' => 3 ) ) );
        $this->assertSame( array(), $this->listed( array( 'author' => 9 ) ) );
    }

    /**
     * The date range takes whole days and includes both ends.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_date_range_includes_both_whole_days() {
        $before_start = $this->make_article( array( 'date' => '2026-03-09 23:59:59' ) );
        $start        = $this->make_article( array( 'date' => '2026-03-10 00:00:00' ) );
        $middle       = $this->make_article( array( 'date' => '2026-03-15 12:00:00' ) );
        $end          = $this->make_article( array( 'date' => '2026-03-20 23:59:59' ) );
        $after_end    = $this->make_article( array( 'date' => '2026-03-21 00:00:00' ) );

        $this->assertSame(
            array( $end, $middle, $start ),
            $this->listed(
                array(
                    'after'  => '2026-03-10',
                    'before' => '2026-03-20',
                )
            )
        );
        $this->assertSame( array( $after_end, $end, $middle, $start ), $this->listed( array( 'after' => '2026-03-10' ) ), 'An open end.' );
        $this->assertSame( array( $end, $middle, $start, $before_start ), $this->listed( array( 'before' => '2026-03-20' ) ), 'An open start.' );
        $this->assertSame(
            array( $middle ),
            $this->listed(
                array(
                    'after'  => '2026-03-15',
                    'before' => '2026-03-15',
                )
            ),
            'One day.'
        );
        $this->assertSame( 5, $this->list_articles( array( 'after' => '2026-02-30' ) )['total'], 'An impossible day is ignored.' );
    }

    /**
     * Uncategorised lists the articles with no category, whatever their tags.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_uncategorised_lists_articles_without_a_category() {
        $category = $this->make_term( 'Billing', Licensio_KB_Post_Types::TAXONOMY_CATEGORY );
        $tag      = $this->make_term( 'Billing' );

        $this->make_article( array( 'categories' => array( $category ) ) );
        $this->make_article(
            array(
                'categories' => array( $category ),
                'tags'       => array( $tag ),
            )
        );
        $bare   = $this->make_article();
        $tagged = $this->make_article( array( 'tags' => array( $tag ) ) );

        $this->assertSame( self::sorted( array( $bare, $tagged ) ), self::sorted( $this->listed( array( 'uncategorised' => true ) ) ) );
    }

    /**
     * No tags lists the articles with no tag, whatever their categories.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_no_tags_lists_articles_without_a_tag() {
        $category = $this->make_term( 'Billing', Licensio_KB_Post_Types::TAXONOMY_CATEGORY );
        $tag      = $this->make_term( 'Billing' );

        $this->make_article( array( 'tags' => array( $tag ) ) );
        $this->make_article(
            array(
                'categories' => array( $category ),
                'tags'       => array( $tag ),
            )
        );
        $bare        = $this->make_article();
        $categorised = $this->make_article( array( 'categories' => array( $category ) ) );

        $this->assertSame( self::sorted( array( $bare, $categorised ) ), self::sorted( $this->listed( array( 'untagged' => true ) ) ) );
    }

    /**
     * Every filter narrows the others: they combine with AND.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_filters_combine_with_and() {
        $tree = $this->make_category_tree(
            array(
                'Guides' => array(
                    'Setup' => array(),
                ),
            )
        );
        $tag  = $this->make_term( 'How to' );

        $all   = array(
            'categories' => array( $tree['Setup'] ),
            'tags'       => array( $tag ),
            'author'     => 2,
            'date'       => '2026-05-05 10:00:00',
            'status'     => 'draft',
        );
        $match = $this->make_article( $all );

        // Each of these misses exactly one of the filters.
        $this->make_article( array_diff_key( $all, array( 'categories' => true ) ) );
        $this->make_article( array_diff_key( $all, array( 'tags' => true ) ) );
        $this->make_article( array_merge( $all, array( 'author' => 3 ) ) );
        $this->make_article( array_merge( $all, array( 'date' => '2026-05-07 10:00:00' ) ) );
        $this->make_article( array_diff_key( $all, array( 'status' => true ) ) );

        $params = array(
            'status'   => 'draft',
            'category' => $tree['Guides'],
            'tag'      => $tag,
            'author'   => 2,
            'after'    => '2026-05-01',
            'before'   => '2026-05-06',
        );

        $this->assertSame( array( $match ), $this->listed( $params ) );
        $this->assertSame( 1, $this->list_articles( $params )['total'] );
        $this->assertSame( array( $match ), $this->ids( $params )['ids'], 'The ID selection takes the same filters.' );

        $bare = $this->make_article();

        $this->assertSame(
            array( $bare ),
            $this->listed(
                array(
                    'uncategorised' => true,
                    'untagged'      => true,
                )
            ),
            'Uncategorised and No tags together.'
        );
    }

    /**
     * "All" lists every status but the trash; one status lists only itself.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_all_leaves_out_the_trash() {
        $published = $this->make_article();
        $draft     = $this->make_article( array( 'status' => 'draft' ) );
        $private   = $this->make_article( array( 'status' => 'private' ) );
        $trashed   = $this->make_article( array( 'status' => 'trash' ) );

        $this->assertSame( self::sorted( array( $published, $draft, $private ) ), self::sorted( $this->listed() ) );
        $this->assertSame( array( $trashed ), $this->listed( array( 'status' => 'trash' ) ) );
        $this->assertSame( array( $draft ), $this->listed( array( 'status' => 'draft' ) ) );
    }

    /**
     * Title, date and modified sort both ways, with the ID breaking ties in the same direction.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_title_date_and_modified_sort_both_ways() {
        $banana = $this->make_article(
            array(
                'title'    => 'Banana',
                'date'     => '2026-01-02 09:00:00',
                'modified' => '2026-06-01 09:00:00',
            )
        );
        $apple  = $this->make_article(
            array(
                'title'    => 'apple',
                'date'     => '2026-01-03 09:00:00',
                'modified' => '2026-04-01 09:00:00',
            )
        );
        $cherry = $this->make_article(
            array(
                'title'    => 'Cherry',
                'date'     => '2026-01-01 09:00:00',
                'modified' => '2026-05-01 09:00:00',
            )
        );
        $twin   = $this->make_article(
            array(
                'title'    => 'Cherry',
                'date'     => '2026-01-01 09:00:00',
                'modified' => '2026-05-01 09:00:00',
            )
        );

        $expected = array(
            'title'    => array( $apple, $banana, $cherry, $twin ),
            'date'     => array( $cherry, $twin, $banana, $apple ),
            'modified' => array( $apple, $cherry, $twin, $banana ),
        );

        foreach ( $expected as $orderby => $ascending ) {
            $this->assertSame(
                $ascending,
                $this->listed(
                    array(
                        'orderby' => $orderby,
                        'order'   => 'asc',
                    )
                ),
                $orderby . ' ascending'
            );
            $this->assertSame(
                array_reverse( $ascending ),
                $this->listed(
                    array(
                        'orderby' => $orderby,
                        'order'   => 'desc',
                    )
                ),
                $orderby . ' descending'
            );
        }

        $this->assertSame( $expected['date'], array_reverse( $this->listed() ), 'Newest first by default.' );
    }

    /**
     * The total and page count describe every match, on any page, even past the end.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_totals_and_pages() {
        $ids = array();

        for ( $day = 1; $day <= 5; $day++ ) {
            $ids[] = $this->make_article( array( 'date' => sprintf( '2026-02-%02d 08:00:00', $day ) ) );
        }

        $this->make_article( array( 'status' => 'draft' ) );
        $this->make_article( array( 'status' => 'trash' ) );

        $newest = array_reverse( $ids );
        $params = array(
            'status'   => 'publish',
            'per_page' => 2,
        );

        $first = $this->list_articles( $params );

        $this->assertSame( array_slice( $newest, 0, 2 ), $first['items'] );
        $this->assertSame( 5, $first['total'] );
        $this->assertSame( 3, $first['pages'] );

        $last = $this->list_articles( array_merge( $params, array( 'page' => 3 ) ) );

        $this->assertSame( array( $newest[4] ), $last['items'] );
        $this->assertSame( array( 5, 3 ), array( $last['total'], $last['pages'] ) );

        $past = $this->list_articles( array_merge( $params, array( 'page' => 9 ) ) );

        $this->assertSame( array(), $past['items'] );
        $this->assertSame( array( 5, 3 ), array( $past['total'], $past['pages'] ), 'Past the end the totals are counted again, so the app can step back.' );

        $this->assertSame(
            array(
                'all'     => 6,
                'publish' => 5,
                'future'  => 0,
                'draft'   => 1,
                'pending' => 0,
                'private' => 0,
                'trash'   => 1,
            ),
            $first['counts']
        );

        $none = $this->list_articles( array( 'author' => 9 ) );

        $this->assertSame( array( array(), 0, 0 ), array( $none['items'], $none['total'], $none['pages'] ) );
    }

    /**
     * Below the cap the ID selection is every match, newest ID first, and not truncated.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_ids_below_the_cap_are_every_match() {
        $tag = $this->make_term( 'How to' );
        $ids = array(
            $this->make_article( array( 'tags' => array( $tag ) ) ),
            $this->make_article(
                array(
                    'tags'   => array( $tag ),
                    'status' => 'draft',
                )
            ),
            $this->make_article( array( 'tags' => array( $tag ) ) ),
        );
        $this->make_article();

        $this->assertSame(
            array(
                'ids'       => array_reverse( $ids ),
                'total'     => 3,
                'truncated' => false,
            ),
            $this->ids( array( 'tag' => $tag ) )
        );
    }

    /**
     * The ID selection stops at 10,000 and says it was cut off.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_ids_stop_at_the_cap() {
        $count = Licensio_KB_Articles_REST_Controller::IDS_CAP + 1;
        $posts = $this->table( 'posts' );

        $this->assertSame( 10000, Licensio_KB_Articles_REST_Controller::IDS_CAP );

        // Seeded in batches: one row per INSERT would make this test slow.
        for ( $offset = 0; $offset < $count; $offset += 1000 ) {
            $rows = array();
            $end  = min( $count, $offset + 1000 );

            for ( $i = $offset; $i < $end; $i++ ) {
                $rows[] = "(1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', '', 'Bulk " . $i . "', '', 'publish', '', '', '', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '', 'licensio_article')";
            }

            $this->assertTrue(
                (bool) $this->db->query(
                    "INSERT INTO {$posts} (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_type) VALUES "
                    . implode( ', ', $rows )
                ),
                $this->db->error
            );
        }

        $lowest = (int) $this->db->query( "SELECT MIN(ID) AS id FROM {$posts}" )->fetch_object()->id;
        $result = $this->ids();

        $this->assertSame( $count, $result['total'] );
        $this->assertTrue( $result['truncated'] );
        $this->assertCount( 10000, $result['ids'] );
        $this->assertSame( $lowest + $count - 1, $result['ids'][0], 'Newest ID first.' );
        $this->assertSame( $lowest + 1, $result['ids'][9999] );
        $this->assertNotContains( $lowest, $result['ids'], 'The oldest article is the one left out.' );
        $this->assertSame( 10000, count( array_unique( $result['ids'] ) ) );

        $this->assertSame( 1, $this->controller->queries, 'One query, whatever the size.' );

        $this->make_article( array( 'status' => 'draft' ) );

        $drafts = $this->ids( array( 'status' => 'draft' ) );

        $this->assertSame( array( 1, false ), array( $drafts['total'], $drafts['truncated'] ), 'A filter that fits under the cap is not truncated.' );
    }
}
