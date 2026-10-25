<?php
/**
 * The content phase of the disposable Heroic migrator.
 *
 * The provenance lookups this class makes are driven against a real MySQL
 * server by `Licensio_KB_Heroic_Migration_Integration_Test`. What is exercised
 * here is the orchestration around them: what gets skipped, what gets reported,
 * and what shape the object handed to `wp_insert_post()` has.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

require_once dirname( dirname( __DIR__ ) ) . '/tools/migrate-heroic/class-licensio-kb-heroic-schema.php';
require_once dirname( dirname( __DIR__ ) ) . '/tools/migrate-heroic/class-licensio-kb-heroic-map.php';
require_once dirname( dirname( __DIR__ ) ) . '/tools/migrate-heroic/class-licensio-kb-heroic-state.php';
require_once dirname( dirname( __DIR__ ) ) . '/tools/migrate-heroic/class-licensio-kb-heroic-report.php';
require_once dirname( dirname( __DIR__ ) ) . '/tools/migrate-heroic/class-licensio-kb-heroic-source.php';
require_once dirname( dirname( __DIR__ ) ) . '/tools/migrate-heroic/class-licensio-kb-heroic-redirects.php';
require_once dirname( dirname( __DIR__ ) ) . '/tools/migrate-heroic/class-licensio-kb-heroic-content-migrator.php';

/**
 * A `$wpdb` stand-in that answers the migrator's two provenance lookups.
 *
 * Deliberately minimal and deliberately explicit: every answer is queued by the
 * test, and every statement is recorded so a test can assert on what was asked
 * rather than only on what came back. A double that answered every lookup with
 * the same value would let a migrator that never translates an ID at all pass.
 *
 * @since 1.0.0
 */
class Licensio_KB_Heroic_Content_WPDB {

    /**
     * The posts table name.
     *
     * @since 1.0.0
     * @var string
     */
    public $posts = 'wp_posts';

    /**
     * The post meta table name.
     *
     * @since 1.0.0
     * @var string
     */
    public $postmeta = 'wp_postmeta';

    /**
     * The terms table name.
     *
     * @since 1.0.0
     * @var string
     */
    public $terms = 'wp_terms';

    /**
     * The term taxonomy table name.
     *
     * @since 1.0.0
     * @var string
     */
    public $term_taxonomy = 'wp_term_taxonomy';

    /**
     * The term relationships table name.
     *
     * @since 1.0.0
     * @var string
     */
    public $term_relationships = 'wp_term_relationships';

    /**
     * The term meta table name.
     *
     * @since 1.0.0
     * @var string
     */
    public $termmeta = 'wp_termmeta';

    /**
     * Heroic post ID to the target article ID the lookup should report.
     *
     * @since 1.0.0
     * @var array<int, int>
     */
    public $articles = array();

    /**
     * Taxonomy and Heroic term ID to the target term ID the lookup should report.
     *
     * @since 1.0.0
     * @var array<string, int>
     */
    public $target_terms = array();

    /**
     * Every statement the migrator ran.
     *
     * @since 1.0.0
     * @var string[]
     */
    public $queries = array();

    /**
     * Rows `get_results()` should hand back, in order.
     *
     * @since 1.0.0
     * @var array<int, array<int, array<string, mixed>>>
     */
    public $results = array();

    /**
     * Columns `get_col()` should hand back, in order.
     *
     * @since 1.0.0
     * @var array<int, array<int, mixed>>
     */
    public $columns = array();

    /**
     * Answer a single-value lookup.
     *
     * @since 1.0.0
     *
     * @param string $query The statement.
     *
     * @return int The looked-up ID, or 0.
     */
    public function get_var( $query ) {
        $this->queries[] = $query;

        if ( false !== strpos( $query, Licensio_KB_Heroic_Content_Migrator::META_SOURCE_POST ) ) {
            return isset( $this->articles[ $this->id_in( $query ) ] ) ? $this->articles[ $this->id_in( $query ) ] : 0;
        }

        if ( false !== strpos( $query, Licensio_KB_Heroic_Content_Migrator::META_SOURCE_TERM ) ) {
            $key = $this->taxonomy_in( $query ) . ':' . $this->id_in( $query );

            return isset( $this->target_terms[ $key ] ) ? $this->target_terms[ $key ] : 0;
        }

        return 0;
    }

    /**
     * Answer a multi-row lookup.
     *
     * @since 1.0.0
     *
     * @param string $query  The statement.
     * @param mixed  $output Ignored.
     *
     * @return array<int, array<string, mixed>> The queued rows.
     */
    public function get_results( $query = '', $output = null ) {
        unset( $output );

        $this->queries[] = $query;

        return empty( $this->results ) ? array() : (array) array_shift( $this->results );
    }

    /**
     * Answer a single-column lookup.
     *
     * @since 1.0.0
     *
     * @param string $query The statement.
     *
     * @return array<int, mixed> The queued column.
     */
    public function get_col( $query = '' ) {
        $this->queries[] = $query;

        return empty( $this->columns ) ? array() : (array) array_shift( $this->columns );
    }

    /**
     * Interpolate a statement the way `wpdb::prepare()` does for these lookups.
     *
     * @since 1.0.0
     *
     * @param string $query   The statement.
     * @param mixed  ...$args The values to bind.
     *
     * @return string The prepared statement.
     */
    public function prepare( $query, ...$args ) {
        if ( 1 === count( $args ) && is_array( $args[0] ) ) {
            $args = $args[0];
        }

        $position = 0;

        return (string) preg_replace_callback(
            '/%[sdf]/',
            function () use ( &$position, $args ) {
                $value = array_key_exists( $position, $args ) ? $args[ $position ] : '';
                ++$position;

                return "'" . $value . "'";
            },
            (string) $query
        );
    }

    /**
     * Escape a string for a LIKE comparison.
     *
     * @since 1.0.0
     *
     * @param string $text The text.
     *
     * @return string The escaped text.
     */
    public function esc_like( $text ) {
        return addcslashes( (string) $text, '_%\\' );
    }

    /**
     * The bound source ID in a provenance lookup.
     *
     * @since 1.0.0
     *
     * @param string $query The statement.
     *
     * @return int The source ID.
     */
    private function id_in( $query ) {
        return preg_match( "/meta_value = '(\d+)'/", $query, $matches ) ? (int) $matches[1] : 0;
    }

    /**
     * The taxonomy named in a term provenance lookup.
     *
     * @since 1.0.0
     *
     * @param string $query The statement.
     *
     * @return string The taxonomy.
     */
    private function taxonomy_in( $query ) {
        return preg_match( "/taxonomy = '([a-z_]+)'/", $query, $matches ) ? $matches[1] : '';
    }
}

/**
 * What the content phase does with a Heroic post and a Heroic term.
 *
 * @since 1.0.0
 */
class Licensio_KB_Heroic_Content_Migrator_Test extends Licensio_KB_Test_Case {

    /**
     * The database double.
     *
     * @since 1.0.0
     * @var Licensio_KB_Heroic_Content_WPDB
     */
    private $wpdb;

    /**
     * Everything handed to `wp_insert_post()`.
     *
     * @since 1.0.0
     * @var array<int, array<string, mixed>>
     */
    private $inserted_posts = array();

    /**
     * Everything handed to `wp_insert_term()`.
     *
     * @since 1.0.0
     * @var array<int, array<string, mixed>>
     */
    private $inserted_terms = array();

    /**
     * Everything handed to `wp_set_object_terms()`.
     *
     * @since 1.0.0
     * @var array<int, array<string, mixed>>
     */
    private $assigned_terms = array();

    /**
     * Post meta written, keyed by post ID then meta key.
     *
     * @since 1.0.0
     * @var array<int, array<string, mixed>>
     */
    private $post_meta = array();

    /**
     * Term meta written, keyed by term ID then meta key.
     *
     * @since 1.0.0
     * @var array<int, array<string, mixed>>
     */
    private $term_meta = array();

    /**
     * Everything handed to `wp_update_post()`.
     *
     * @since 1.0.0
     * @var array<int, array<string, mixed>>
     */
    private $updated_posts = array();

    /**
     * The slug `get_post_field()` reports for a created post.
     *
     * Set by a test to simulate core silently uniquifying a colliding slug.
     *
     * @since 1.0.0
     * @var string|null
     */
    private $stored_post_slug = null;

    /**
     * The slug `get_term_field()` reports for a created term.
     *
     * @since 1.0.0
     * @var string|null
     */
    private $stored_term_slug = null;

    /**
     * The run report.
     *
     * @since 1.0.0
     * @var Licensio_KB_Heroic_Report
     */
    private $report;

    /**
     * Stub the WordPress content API and record everything it is asked to do.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->wpdb             = new Licensio_KB_Heroic_Content_WPDB();
        $GLOBALS['wpdb']        = $this->wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        $this->inserted_posts   = array();
        $this->inserted_terms   = array();
        $this->assigned_terms   = array();
        $this->post_meta        = array();
        $this->term_meta        = array();
        $this->updated_posts    = array();
        $this->stored_post_slug = null;
        $this->stored_term_slug = null;
        $this->report           = new Licensio_KB_Heroic_Report();

        Brain\Monkey\Functions\when( 'is_wp_error' )->alias(
            function ( $thing ) {
                return $thing instanceof WP_Error;
            }
        );

        Brain\Monkey\Functions\when( 'wp_insert_post' )->alias(
            function ( $post ) {
                $this->inserted_posts[] = $post;

                return 900 + count( $this->inserted_posts );
            }
        );

        Brain\Monkey\Functions\when( 'wp_insert_term' )->alias(
            function ( $name, $taxonomy, $args = array() ) {
                $this->inserted_terms[] = array(
                    'name'     => $name,
                    'taxonomy' => $taxonomy,
                    'args'     => $args,
                );

                return array( 'term_id' => 500 + count( $this->inserted_terms ) );
            }
        );

        Brain\Monkey\Functions\when( 'wp_set_object_terms' )->alias(
            function ( $object_id, $terms, $taxonomy, $append = false ) {
                unset( $append );

                $this->assigned_terms[] = array(
                    'object_id' => $object_id,
                    'terms'     => $terms,
                    'taxonomy'  => $taxonomy,
                );

                return $terms;
            }
        );

        Brain\Monkey\Functions\when( 'update_post_meta' )->alias(
            function ( $post_id, $key, $value ) {
                $this->post_meta[ (int) $post_id ][ (string) $key ] = $value;

                return true;
            }
        );

        Brain\Monkey\Functions\when( 'update_term_meta' )->alias(
            function ( $term_id, $key, $value ) {
                $this->term_meta[ (int) $term_id ][ (string) $key ] = $value;

                return true;
            }
        );

        Brain\Monkey\Functions\when( 'get_post_meta' )->justReturn( 0 );

        Brain\Monkey\Functions\when( 'wp_update_post' )->alias(
            function ( $post ) {
                $this->updated_posts[] = $post;

                return isset( $post['ID'] ) ? (int) $post['ID'] : 0;
            }
        );

        Brain\Monkey\Functions\when( 'get_post_field' )->alias(
            function ( $field, $post_id ) {
                unset( $post_id );

                if ( 'post_name' !== $field ) {
                    return '';
                }

                return null === $this->stored_post_slug ? $this->requested_post_slug() : $this->stored_post_slug;
            }
        );

        Brain\Monkey\Functions\when( 'get_term_field' )->alias(
            function ( $field, $term_id ) {
                unset( $term_id );

                if ( 'slug' !== $field ) {
                    return '';
                }

                return null === $this->stored_term_slug ? $this->requested_term_slug() : $this->stored_term_slug;
            }
        );

        Brain\Monkey\Functions\when( 'get_post_type' )->justReturn( 'attachment' );
        Brain\Monkey\Functions\when( 'home_url' )->justReturn( 'https://example.test/' );
        Brain\Monkey\Functions\when( 'get_permalink' )->justReturn( '' );
        Brain\Monkey\Functions\when( 'get_term_link' )->justReturn( '' );
        Brain\Monkey\Functions\when( 'wp_parse_url' )->justReturn( '' );
    }

    /**
     * Release the database double.
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
     * The slug of the most recent `wp_insert_post()` call.
     *
     * @since 1.0.0
     *
     * @return string The slug.
     */
    private function requested_post_slug() {
        $last = end( $this->inserted_posts );

        return is_array( $last ) && isset( $last['post_name'] ) ? (string) $last['post_name'] : '';
    }

    /**
     * The slug of the most recent `wp_insert_term()` call.
     *
     * @since 1.0.0
     *
     * @return string The slug.
     */
    private function requested_term_slug() {
        $last = end( $this->inserted_terms );

        return is_array( $last ) && isset( $last['args']['slug'] ) ? (string) $last['args']['slug'] : '';
    }

    /**
     * A migrator wired to the doubles.
     *
     * @since 1.0.0
     *
     * @param Licensio_KB_Heroic_Source     $source  The batched reader.
     * @param bool                          $dry_run Whether the migrator may write.
     * @param Licensio_KB_Heroic_State|null $state   The resume state to share, or null for a fresh one.
     *
     * @return Licensio_KB_Heroic_Content_Migrator The migrator under test.
     */
    private function migrator( $source, $dry_run = false, $state = null ) {
        return new Licensio_KB_Heroic_Content_Migrator(
            $source,
            null === $state ? new Licensio_KB_Heroic_State( false ) : $state,
            new Licensio_KB_Heroic_Redirects( sys_get_temp_dir() . '/licensio-kb-unused', true ),
            $this->report,
            $dry_run
        );
    }

    /**
     * A source double reporting a fixed set of articles and terms.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $data What the source should report.
     *
     * @return Licensio_KB_Heroic_Source The source double.
     */
    private function source( array $data ) {
        return new Licensio_KB_Heroic_Source_Double( $data );
    }

    /**
     * A Heroic article becomes a `licensio_article` carrying its dates and author.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_article_is_created_with_its_author_dates_and_status() {
        $source = $this->source(
            array(
                'articles' => array(
                    array(
                        'ID'                => 11,
                        'post_title'        => 'Install a licence',
                        'post_content'      => 'Steps.',
                        'post_excerpt'      => 'How to install.',
                        'post_status'       => 'draft',
                        'post_author'       => 4,
                        'post_name'         => 'install-a-licence',
                        'post_date'         => '2024-05-01 10:00:00',
                        'post_date_gmt'     => '2024-05-01 09:00:00',
                        'post_modified'     => '2024-06-01 10:00:00',
                        'post_modified_gmt' => '2024-06-01 09:00:00',
                        'comment_status'    => 'open',
                        'ping_status'       => 'closed',
                    ),
                ),
            )
        );

        $this->migrator( $source )->migrate_articles_batch( 10 );

        $this->assertCount( 1, $this->inserted_posts );

        $post = $this->inserted_posts[0];

        $this->assertSame( Licensio_KB_Post_Types::POST_TYPE, $post['post_type'] );
        $this->assertSame( 'Install a licence', $post['post_title'] );
        $this->assertSame( 'How to install.', $post['post_excerpt'] );
        $this->assertSame( 'draft', $post['post_status'], 'A draft must not be published by the migration.' );
        $this->assertSame( 4, $post['post_author'] );
        $this->assertSame( 'install-a-licence', $post['post_name'] );
        $this->assertSame( '2024-05-01 10:00:00', $post['post_date'] );
        $this->assertSame( '2024-05-01 09:00:00', $post['post_date_gmt'] );
        $this->assertSame( '2024-06-01 09:00:00', $post['post_modified_gmt'] );
        $this->assertSame( 'open', $post['comment_status'] );
    }

    /**
     * An article that has already been migrated is skipped, not created again.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_already_migrated_article_is_skipped() {
        $this->wpdb->articles[11] = 900;

        $source = $this->source(
            array(
                'articles' => array(
                    array(
                        'ID'        => 11,
                        'post_name' => 'install',
                    ),
                ),
            )
        );

        $this->migrator( $source )->migrate_articles_batch( 10 );

        $this->assertSame( array(), $this->inserted_posts, 'A re-run created a second copy of an article.' );
        $this->assertSame( 1, $this->report->get( 'articles_skipped' ) );
        $this->assertSame( 0, $this->report->get( 'articles_created' ) );
    }

    /**
     * A term slug core had to change is reported too, not only an article slug.
     *
     * The two go through separate branches of the same reporter, and a term slug
     * that moves silently breaks every category URL in the redirect map.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_term_slug_core_changed_is_reported_as_a_collision() {
        $this->stored_term_slug = 'renewals-2';

        $source = $this->source(
            array(
                'terms' => array(
                    Licensio_KB_Heroic_Schema::TAXONOMY_CATEGORY => array(
                        array(
                            'term_id' => 8,
                            'name'    => 'Renewals',
                            'slug'    => 'renewals',
                            'parent'  => 0,
                        ),
                    ),
                ),
            )
        );

        $this->migrator( $source )->migrate_terms();

        $this->assertSame( 1, $this->report->get( 'slug_collisions' ) );
        $this->assertStringContainsString( 'wanted slug "renewals" and got "renewals-2"', $this->report->notes( 'slug_collisions' )[0] );
    }

    /**
     * A slug core had to change is reported rather than silently accepted.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_slug_core_changed_is_reported_as_a_collision() {
        $this->stored_post_slug = 'install-2';

        $source = $this->source(
            array(
                'articles' => array(
                    array(
                        'ID'        => 11,
                        'post_name' => 'install',
                    ),
                ),
            )
        );

        $this->migrator( $source )->migrate_articles_batch( 10 );

        $this->assertSame( 1, $this->report->get( 'slug_collisions' ) );
        $this->assertStringContainsString( 'wanted slug "install" and got "install-2"', $this->report->notes( 'slug_collisions' )[0] );
    }

    /**
     * A slug that survived intact is not reported.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_slug_that_survived_is_not_reported_as_a_collision() {
        $source = $this->source(
            array(
                'articles' => array(
                    array(
                        'ID'        => 11,
                        'post_name' => 'install',
                    ),
                ),
            )
        );

        $this->migrator( $source )->migrate_articles_batch( 10 );

        $this->assertSame( 0, $this->report->get( 'slug_collisions' ) );
    }

    /**
     * Each Heroic position is written to its migrated category, after the order it already held.
     *
     * Heroic 12 and 31 were migrated as 501 and 502; 77 was not. Category 502
     * already orders an article at 40, so the position from 31 follows it.
     * `menu_order` still comes from the lowest category, as the fallback for
     * listings not limited to one category.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_each_category_position_is_written_after_the_existing_order() {
        $this->wpdb->target_terms[ Licensio_KB_Post_Types::TAXONOMY_CATEGORY . ':12' ] = 501;
        $this->wpdb->target_terms[ Licensio_KB_Post_Types::TAXONOMY_CATEGORY . ':31' ] = 502;

        Brain\Monkey\Functions\when( 'get_posts' )->alias(
            function ( $args ) {
                return Licensio_KB_Article_Order::meta_key( 502 ) === $args['meta_key'] ? array( 70 ) : array();
            }
        );
        Brain\Monkey\Functions\when( 'update_meta_cache' )->justReturn( true );
        Brain\Monkey\Functions\when( 'get_post_meta' )->alias(
            function ( $post_id, $key = '' ) {
                return 70 === (int) $post_id && Licensio_KB_Article_Order::meta_key( 502 ) === $key ? '40' : 0;
            }
        );

        $source = $this->source(
            array(
                'articles'   => array(
                    array(
                        'ID'        => 11,
                        'post_name' => 'install',
                    ),
                ),
                'post_terms' => array(
                    '11:' . Licensio_KB_Heroic_Schema::TAXONOMY_CATEGORY => array( 12, 31, 77 ),
                ),
                'orders'     => array(
                    11 => array(
                        31 => '5',
                        12 => '9',
                        77 => '2',
                    ),
                ),
            )
        );

        $this->migrator( $source )->migrate_articles_batch( 10 );

        $this->assertSame( 9, $this->inserted_posts[0]['menu_order'], 'The fallback position must come from category 12.' );
        $this->assertSame( 9, $this->post_meta[901]['licensio_order_501'] );
        $this->assertSame( 46, $this->post_meta[901]['licensio_order_502'] );
        $this->assertArrayNotHasKey( 'licensio_order_77', $this->post_meta[901] );
    }

    /**
     * The existing order is read once per run, so the run's own writes do not shift later articles.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_existing_order_is_read_once_per_run() {
        $this->wpdb->target_terms[ Licensio_KB_Post_Types::TAXONOMY_CATEGORY . ':12' ] = 501;

        $reads = 0;

        Brain\Monkey\Functions\when( 'get_posts' )->alias(
            function () use ( &$reads ) {
                ++$reads;

                return array();
            }
        );

        $source = $this->source(
            array(
                'articles'   => array(
                    array(
                        'ID'        => 11,
                        'post_name' => 'install',
                    ),
                    array(
                        'ID'        => 12,
                        'post_name' => 'upgrade',
                    ),
                ),
                'post_terms' => array(
                    '11:' . Licensio_KB_Heroic_Schema::TAXONOMY_CATEGORY => array( 12 ),
                    '12:' . Licensio_KB_Heroic_Schema::TAXONOMY_CATEGORY => array( 12 ),
                ),
                'orders'     => array(
                    11 => array( 12 => '3' ),
                    12 => array( 12 => '0' ),
                ),
            )
        );

        $this->migrator( $source )->migrate_articles_batch( 10 );

        $this->assertSame( 1, $reads );
        $this->assertSame( 3, $this->post_meta[901]['licensio_order_501'] );
        $this->assertSame( 0, $this->post_meta[902]['licensio_order_501'] );
    }

    /**
     * A resumed run keeps the Heroic order across the interruption.
     *
     * The first process migrates article 11 at 3 and dies. The second process
     * starts from the same resume state while category 501 now holds that
     * position, so reading the existing order again would push article 12,
     * which Heroic put first, after article 11.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_resumed_run_keeps_the_heroic_order() {
        $this->wpdb->target_terms[ Licensio_KB_Post_Types::TAXONOMY_CATEGORY . ':12' ] = 501;

        Brain\Monkey\Functions\when( 'get_posts' )->justReturn( array() );
        Brain\Monkey\Functions\when( 'update_meta_cache' )->justReturn( true );
        Brain\Monkey\Functions\when( 'get_post_meta' )->alias(
            function ( $post_id, $key = '' ) {
                return 901 === (int) $post_id && Licensio_KB_Article_Order::meta_key( 501 ) === $key ? '3' : 0;
            }
        );

        $data  = array(
            'articles'   => array(
                array(
                    'ID'        => 11,
                    'post_name' => 'install',
                ),
            ),
            'post_terms' => array(
                '11:' . Licensio_KB_Heroic_Schema::TAXONOMY_CATEGORY => array( 12 ),
                '12:' . Licensio_KB_Heroic_Schema::TAXONOMY_CATEGORY => array( 12 ),
            ),
            'orders'     => array(
                11 => array( 12 => '3' ),
                12 => array( 12 => '0' ),
            ),
        );
        $state = new Licensio_KB_Heroic_State( false );

        $this->migrator( $this->source( $data ), false, $state )->migrate_articles_batch( 10 );

        // The second process sees the position the first one wrote.
        Brain\Monkey\Functions\when( 'get_posts' )->justReturn( array( 901 ) );

        $data['articles'] = array(
            array(
                'ID'        => 12,
                'post_name' => 'upgrade',
            ),
        );

        $this->migrator( $this->source( $data ), false, $state )->migrate_articles_batch( 10 );

        $this->assertSame( 3, $this->post_meta[901]['licensio_order_501'] );
        $this->assertSame( 0, $this->post_meta[902]['licensio_order_501'], 'The resumed article must keep its place before the first.' );
    }

    /**
     * An article is filed under the migrated equivalents of its Heroic terms.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_article_is_filed_under_the_translated_terms() {
        $this->wpdb->target_terms[ Licensio_KB_Post_Types::TAXONOMY_CATEGORY . ':31' ] = 501;
        $this->wpdb->target_terms[ Licensio_KB_Post_Types::TAXONOMY_TAG . ':44' ]      = 502;

        $source = $this->source(
            array(
                'articles'   => array(
                    array(
                        'ID'        => 11,
                        'post_name' => 'install',
                    ),
                ),
                'post_terms' => array(
                    '11:' . Licensio_KB_Heroic_Schema::TAXONOMY_CATEGORY => array( 31 ),
                    '11:' . Licensio_KB_Heroic_Schema::TAXONOMY_TAG      => array( 44 ),
                ),
            )
        );

        $this->migrator( $source )->migrate_articles_batch( 10 );

        $this->assertCount( 2, $this->assigned_terms );
        $this->assertSame( array( 501 ), $this->assigned_terms[0]['terms'] );
        $this->assertSame( Licensio_KB_Post_Types::TAXONOMY_CATEGORY, $this->assigned_terms[0]['taxonomy'] );
        $this->assertSame( array( 502 ), $this->assigned_terms[1]['terms'] );
        $this->assertSame( Licensio_KB_Post_Types::TAXONOMY_TAG, $this->assigned_terms[1]['taxonomy'] );
    }

    /**
     * A Heroic term that was never migrated is reported, not filed as term 0.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unmigrated_term_reference_is_reported() {
        $source = $this->source(
            array(
                'articles'   => array(
                    array(
                        'ID'        => 11,
                        'post_name' => 'install',
                    ),
                ),
                'post_terms' => array(
                    '11:' . Licensio_KB_Heroic_Schema::TAXONOMY_CATEGORY => array( 31 ),
                ),
            )
        );

        $this->migrator( $source )->migrate_articles_batch( 10 );

        $this->assertSame( array(), $this->assigned_terms, 'An article must not be filed under term 0.' );
        $this->assertSame( 1, $this->report->get( 'article_terms_missing' ) );
    }

    /**
     * The featured image and the uploaded attachments follow the article.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_thumbnail_is_copied_and_the_attachments_are_reparented() {
        Brain\Monkey\Functions\when( 'get_post_meta' )->justReturn( 63 );

        $source = $this->source(
            array(
                'articles'    => array(
                    array(
                        'ID'        => 11,
                        'post_name' => 'install',
                    ),
                ),
                'attachments' => array( 11 => array( 71, 72 ) ),
            )
        );

        $this->migrator( $source )->migrate_articles_batch( 10 );

        $this->assertSame( 63, $this->post_meta[901]['_thumbnail_id'] );
        $this->assertSame( 1, $this->report->get( 'thumbnails_migrated' ) );

        $this->assertCount( 2, $this->updated_posts );
        $this->assertSame(
            array(
                'ID'          => 71,
                'post_parent' => 901,
            ),
            $this->updated_posts[0]
        );
        $this->assertSame( 2, $this->report->get( 'attachments_reparented' ) );
    }

    /**
     * The provenance meta is written, or the next run duplicates the article.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_article_records_where_it_came_from() {
        $source = $this->source(
            array(
                'articles' => array(
                    array(
                        'ID'        => 11,
                        'post_name' => 'install',
                    ),
                ),
            )
        );

        $this->migrator( $source )->migrate_articles_batch( 10 );

        $this->assertSame( 11, $this->post_meta[901][ Licensio_KB_Heroic_Content_Migrator::META_SOURCE_POST ] );
    }

    /**
     * A dry run creates nothing but still counts what it would create.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_dry_run_creates_no_article_and_still_counts_it() {
        $source = $this->source(
            array(
                'articles' => array(
                    array(
                        'ID'        => 11,
                        'post_name' => 'install',
                    ),
                ),
            )
        );

        $this->migrator( $source, true )->migrate_articles_batch( 10 );

        $this->assertSame( array(), $this->inserted_posts );
        $this->assertSame( array(), $this->post_meta );
        $this->assertSame( 1, $this->report->get( 'articles_created' ) );
    }

    /**
     * A child term is created under the migrated equivalent of its Heroic parent.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_child_term_is_created_under_the_translated_parent() {
        $this->wpdb->target_terms[ Licensio_KB_Post_Types::TAXONOMY_CATEGORY . ':4' ] = 555;

        $source = $this->source(
            array(
                'terms' => array(
                    Licensio_KB_Heroic_Schema::TAXONOMY_CATEGORY => array(
                        array(
                            'term_id'     => 8,
                            'name'        => 'Renewals',
                            'slug'        => 'renewals',
                            'description' => 'How renewals work.',
                            'parent'      => 4,
                        ),
                    ),
                ),
            )
        );

        $this->migrator( $source )->migrate_terms();

        $this->assertCount( 1, $this->inserted_terms );
        $this->assertSame( 'Renewals', $this->inserted_terms[0]['name'] );
        $this->assertSame( Licensio_KB_Post_Types::TAXONOMY_CATEGORY, $this->inserted_terms[0]['taxonomy'] );
        $this->assertSame( 555, $this->inserted_terms[0]['args']['parent'], 'The parent must be translated, not carried across as a Heroic ID.' );
        $this->assertSame( 'renewals', $this->inserted_terms[0]['args']['slug'] );
        $this->assertSame( 'How renewals work.', $this->inserted_terms[0]['args']['description'] );
    }

    /**
     * A term whose parent was not migrated becomes a root rather than an orphan.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_term_with_an_unmigrated_parent_becomes_a_root() {
        $source = $this->source(
            array(
                'terms' => array(
                    Licensio_KB_Heroic_Schema::TAXONOMY_CATEGORY => array(
                        array(
                            'term_id' => 8,
                            'name'    => 'Renewals',
                            'slug'    => 'renewals',
                            'parent'  => 4,
                        ),
                    ),
                ),
            )
        );

        $this->migrator( $source )->migrate_terms();

        $this->assertSame( 0, $this->inserted_terms[0]['args']['parent'] );
    }

    /**
     * A category's order and image cross; its SVG icon is reported as unmapped.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_category_meta_crosses_and_the_icon_is_reported() {
        $source = $this->source(
            array(
                'terms'     => array(
                    Licensio_KB_Heroic_Schema::TAXONOMY_CATEGORY => array(
                        array(
                            'term_id' => 8,
                            'name'    => 'Renewals',
                            'slug'    => 'renewals',
                            'parent'  => 0,
                        ),
                    ),
                ),
                'term_meta' => array(
                    8 => array(
                        'term_order'     => '3',
                        'meta_image'     => '77',
                        'meta_svg'       => '<svg></svg>',
                        'meta_svg_color' => '#00ff00',
                    ),
                ),
            )
        );

        $this->migrator( $source )->migrate_terms();

        $this->assertSame( 3, $this->term_meta[501][ Licensio_KB_Term_Meta::META_ORDER ] );
        $this->assertSame( 77, $this->term_meta[501][ Licensio_KB_Term_Meta::META_IMAGE_ID ] );
        $this->assertArrayNotHasKey( Licensio_KB_Term_Meta::META_ICON, $this->term_meta[501] );
        $this->assertSame( 8, $this->term_meta[501][ Licensio_KB_Heroic_Content_Migrator::META_SOURCE_TERM ] );

        $this->assertSame( 2, $this->report->get( 'term_meta_unmapped' ) );
        $this->assertCount( 2, $this->report->notes( 'unmapped_term_meta' ) );
    }

    /**
     * Tags carry no presentation meta, so none is read for them.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_tag_gets_no_category_presentation_meta() {
        $source = $this->source(
            array(
                'terms'     => array(
                    Licensio_KB_Heroic_Schema::TAXONOMY_TAG => array(
                        array(
                            'term_id' => 9,
                            'name'    => 'Billing',
                            'slug'    => 'billing',
                            'parent'  => 0,
                        ),
                    ),
                ),
                'term_meta' => array( 9 => array( 'term_order' => '3' ) ),
            )
        );

        $this->migrator( $source )->migrate_terms();

        $this->assertArrayNotHasKey( Licensio_KB_Term_Meta::META_ORDER, $this->term_meta[501] );
        $this->assertSame( 0, $this->report->get( 'term_meta_migrated' ) );
    }

    /**
     * An existing term with the same name is adopted rather than failing the run.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_existing_term_is_adopted_and_given_the_provenance_meta() {
        Brain\Monkey\Functions\when( 'wp_insert_term' )->alias(
            function () {
                return new WP_Error( 'term_exists', 'Already there', array( 'term_id' => 640 ) );
            }
        );

        $source = $this->source(
            array(
                'terms' => array(
                    Licensio_KB_Heroic_Schema::TAXONOMY_CATEGORY => array(
                        array(
                            'term_id' => 8,
                            'name'    => 'Renewals',
                            'slug'    => 'renewals',
                            'parent'  => 0,
                        ),
                    ),
                ),
            )
        );

        $this->migrator( $source )->migrate_terms();

        $this->assertSame( 1, $this->report->get( 'terms_adopted' ) );
        $this->assertSame( 0, $this->report->get( 'terms_failed' ) );
        $this->assertSame( 8, $this->term_meta[640][ Licensio_KB_Heroic_Content_Migrator::META_SOURCE_TERM ] );
    }

    /**
     * A term that cannot be created at all is reported and does not stop the run.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_term_that_cannot_be_created_is_reported() {
        Brain\Monkey\Functions\when( 'wp_insert_term' )->alias(
            function () {
                return new WP_Error( 'invalid_taxonomy', 'No such taxonomy' );
            }
        );

        $source = $this->source(
            array(
                'terms' => array(
                    Licensio_KB_Heroic_Schema::TAXONOMY_CATEGORY => array(
                        array(
                            'term_id' => 8,
                            'name'    => 'Renewals',
                            'slug'    => 'renewals',
                            'parent'  => 0,
                        ),
                    ),
                ),
            )
        );

        $this->migrator( $source )->migrate_terms();

        $this->assertSame( 1, $this->report->get( 'terms_failed' ) );
        $this->assertStringContainsString( 'No such taxonomy', $this->report->notes( 'terms' )[0] );
        $this->assertSame( array(), $this->term_meta );
    }

    /**
     * Categories are migrated before tags.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_categories_are_migrated_before_tags() {
        $source = $this->source(
            array(
                'terms' => array(
                    Licensio_KB_Heroic_Schema::TAXONOMY_CATEGORY => array(
                        array(
                            'term_id' => 8,
                            'name'    => 'Renewals',
                            'slug'    => 'renewals',
                            'parent'  => 0,
                        ),
                    ),
                    Licensio_KB_Heroic_Schema::TAXONOMY_TAG      => array(
                        array(
                            'term_id' => 9,
                            'name'    => 'Billing',
                            'slug'    => 'billing',
                            'parent'  => 0,
                        ),
                    ),
                ),
            )
        );

        $this->migrator( $source )->migrate_terms();

        $this->assertSame(
            array( Licensio_KB_Post_Types::TAXONOMY_CATEGORY, Licensio_KB_Post_Types::TAXONOMY_TAG ),
            array_column( $this->inserted_terms, 'taxonomy' )
        );
    }
}

/**
 * A source double reporting a fixed Heroic site.
 *
 * @since 1.0.0
 */
class Licensio_KB_Heroic_Source_Double extends Licensio_KB_Heroic_Source {

    /**
     * What this double should report.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private $data;

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $data What to report.
     */
    public function __construct( array $data ) {
        $this->data = $data;
    }

    /**
     * The seeded articles, once.
     *
     * A second call returns nothing, which is what a real source does when the
     * cursor has passed the last row — and what tells the migrator the phase is
     * over.
     *
     * @since 1.0.0
     *
     * @param int $after The ID to start after.
     * @param int $limit How many rows to return.
     *
     * @return array<int, array<string, mixed>> The article rows.
     */
    public function articles( $after = 0, $limit = 200 ) {
        unset( $limit );

        $rows = isset( $this->data['articles'] ) ? $this->data['articles'] : array();
        $out  = array();

        foreach ( $rows as $row ) {
            if ( (int) $row['ID'] > (int) $after ) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * The seeded terms of one taxonomy.
     *
     * @since 1.0.0
     *
     * @param string $taxonomy The Heroic taxonomy name.
     *
     * @return array<int, array<string, mixed>> The term rows.
     */
    public function terms( $taxonomy ) {
        return isset( $this->data['terms'][ $taxonomy ] ) ? $this->data['terms'][ $taxonomy ] : array();
    }

    /**
     * The seeded term meta.
     *
     * @since 1.0.0
     *
     * @param int $term_id The Heroic term ID.
     *
     * @return array<string, string> The meta.
     */
    public function term_meta( $term_id ) {
        return isset( $this->data['term_meta'][ (int) $term_id ] ) ? $this->data['term_meta'][ (int) $term_id ] : array();
    }

    /**
     * The seeded term relationships.
     *
     * @since 1.0.0
     *
     * @param int    $post_id  The Heroic post ID.
     * @param string $taxonomy The Heroic taxonomy name.
     *
     * @return int[] The term IDs.
     */
    public function post_terms( $post_id, $taxonomy ) {
        $key = (int) $post_id . ':' . $taxonomy;

        return isset( $this->data['post_terms'][ $key ] ) ? $this->data['post_terms'][ $key ] : array();
    }

    /**
     * The seeded per-category positions.
     *
     * @since 1.0.0
     *
     * @param int $post_id The Heroic post ID.
     *
     * @return array<int, string> Term ID to position.
     */
    public function article_orders( $post_id ) {
        return isset( $this->data['orders'][ (int) $post_id ] ) ? $this->data['orders'][ (int) $post_id ] : array();
    }

    /**
     * The seeded attachments.
     *
     * @since 1.0.0
     *
     * @param int $post_id The Heroic post ID.
     *
     * @return int[] The attachment IDs.
     */
    public function attachments( $post_id ) {
        return isset( $this->data['attachments'][ (int) $post_id ] ) ? $this->data['attachments'][ (int) $post_id ] : array();
    }
}
