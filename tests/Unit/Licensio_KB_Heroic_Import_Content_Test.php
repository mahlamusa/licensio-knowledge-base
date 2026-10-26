<?php
/**
 * The generic content phase, driven by a Heroic source double.
 *
 * The provenance lookups this class makes are driven against a real MySQL
 * server by `Licensio_KB_Heroic_Import_Integration_Test`. What is exercised
 * here is the orchestration around them: what gets skipped, what gets reported,
 * and what shape the object handed to `wp_insert_post()` has.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */


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
class Licensio_KB_Heroic_Import_Content_WPDB {

    /**
     * The meta key an imported article carries its Heroic ID in.
     *
     * @since 1.0.0
     * @var string
     */
    const META_SOURCE_POST = '_licensio_heroic_post_id';

    /**
     * The meta key an imported term carries its Heroic ID in.
     *
     * @since 1.0.0
     * @var string
     */
    const META_SOURCE_TERM = '_licensio_heroic_term_id';

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
     * Slug to the target article ID a slug lookup should report.
     *
     * @since 1.0.0
     * @var array<string, int>
     */
    public $slugs = array();

    /**
     * Taxonomy and Heroic term ID to the target term ID the lookup should report.
     *
     * @since 1.0.0
     * @var array<string, int>
     */
    public $target_terms = array();

    /**
     * The highest position the target category lookup should report.
     *
     * Null, as MySQL answers, when the category holds no positions.
     *
     * @since 1.0.0
     * @var string|null
     */
    public $highest_position = null;

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
     * @return int|string|null The looked-up ID, or 0; for the highest position lookup, the queued value.
     */
    public function get_var( $query ) {
        $this->queries[] = $query;

        if ( false !== strpos( $query, 'MAX(CAST(meta_value AS SIGNED))' ) ) {
            return $this->highest_position;
        }

        if ( false !== strpos( $query, 'post_name =' ) ) {
            preg_match( "/post_name = '([^']*)'/", $query, $matches );
            $slug = isset( $matches[1] ) ? $matches[1] : '';

            return isset( $this->slugs[ $slug ] ) ? $this->slugs[ $slug ] : 0;
        }

        if ( false !== strpos( $query, self::META_SOURCE_POST ) ) {
            return isset( $this->articles[ $this->id_in( $query ) ] ) ? $this->articles[ $this->id_in( $query ) ] : 0;
        }

        if ( false !== strpos( $query, self::META_SOURCE_TERM ) ) {
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
class Licensio_KB_Heroic_Import_Content_Test extends Licensio_KB_Test_Case {

    /**
     * The database double.
     *
     * @since 1.0.0
     * @var Licensio_KB_Heroic_Import_Content_WPDB
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
     * Provenance meta an existing post already carries, by post ID.
     *
     * @since 1.0.0
     * @var array<int, string>
     */
    private $existing_provenance = array();

    /**
     * The run report.
     *
     * @since 1.0.0
     * @var Licensio_KB_Import_Report
     */
    private $report;

    /**
     * The resume point the last migrator was built with.
     *
     * @since 1.0.0
     * @var Licensio_KB_Import_State|null
     */
    private $state = null;

    /**
     * Every term meta write made through add_term_meta().
     *
     * @since 1.0.0
     * @var array<int, array<string, mixed>>
     */
    private $added_term_meta = array();

    /**
     * The terms get_term() should find, by term ID.
     *
     * @since 1.0.0
     * @var array<int, WP_Term>
     */
    private $existing_terms = array();

    /**
     * Stub the WordPress content API and record everything it is asked to do.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->wpdb                = new Licensio_KB_Heroic_Import_Content_WPDB();
        $GLOBALS['wpdb']           = $this->wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        $this->inserted_posts      = array();
        $this->inserted_terms      = array();
        $this->assigned_terms      = array();
        $this->post_meta           = array();
        $this->term_meta           = array();
        $this->updated_posts       = array();
        $this->stored_post_slug    = null;
        $this->stored_term_slug    = null;
        $this->existing_provenance = array();
        $this->added_term_meta     = array();
        $this->existing_terms      = array();
        $this->state               = null;
        $this->report              = new Licensio_KB_Import_Report( 'heroic' );

        Brain\Monkey\Functions\when( 'is_wp_error' )->alias(
            function ( $thing ) {
                return $thing instanceof WP_Error;
            }
        );

        Brain\Monkey\Functions\when( 'wp_insert_post' )->alias(
            function ( $post ) {
                $this->inserted_posts[] = self::core_unslash( $post );

                return 900 + count( $this->inserted_posts );
            }
        );

        Brain\Monkey\Functions\when( 'wp_insert_term' )->alias(
            function ( $name, $taxonomy, $args = array() ) {
                $this->inserted_terms[] = array(
                    'name'     => self::core_unslash( $name ),
                    'taxonomy' => $taxonomy,
                    'args'     => self::core_unslash( $args ),
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
                $this->term_meta[ (int) $term_id ][ (string) $key ] = self::core_unslash( $value );

                return true;
            }
        );

        Brain\Monkey\Functions\when( 'add_term_meta' )->alias(
            function ( $term_id, $key, $value ) {
                $this->added_term_meta[] = array(
                    'term_id' => (int) $term_id,
                    'key'     => (string) $key,
                );

                $this->term_meta[ (int) $term_id ][ (string) $key ] = $value;

                return true;
            }
        );

        Brain\Monkey\Functions\when( 'get_term_meta' )->alias(
            function ( $term_id, $key ) {
                return isset( $this->term_meta[ (int) $term_id ][ (string) $key ] )
                    ? array( $this->term_meta[ (int) $term_id ][ (string) $key ] )
                    : array();
            }
        );

        Brain\Monkey\Functions\when( 'get_term' )->alias(
            function ( $term_id ) {
                return isset( $this->existing_terms[ (int) $term_id ] ) ? $this->existing_terms[ (int) $term_id ] : null;
            }
        );

        Brain\Monkey\Functions\when( 'get_post_meta' )->alias(
            function ( $post_id, $key, $single = false ) {
                unset( $single );

                if ( Licensio_KB_Heroic_Import_Content_WPDB::META_SOURCE_POST === $key ) {
                    return isset( $this->existing_provenance[ (int) $post_id ] )
                        ? $this->existing_provenance[ (int) $post_id ]
                        : '';
                }

                return 0;
            }
        );

        Brain\Monkey\Functions\when( 'wp_update_post' )->alias(
            function ( $post ) {
                $this->updated_posts[] = self::core_unslash( $post );

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
     * @param Licensio_KB_Heroic_Import_Source $source     The batched reader.
     * @param bool                             $dry_run    Whether the migrator may write.
     * @param string[]                         $claim_keys Every source's provenance key; the source's own when empty.
     *
     * @return Licensio_KB_Import_Content The migrator under test.
     */
    private function migrator( $source, $dry_run = false, array $claim_keys = array() ) {
        $this->state = new Licensio_KB_Import_State( 'heroic', array( 'terms', 'articles', 'feedback', 'views', 'searches' ), false );

        return new Licensio_KB_Import_Content(
            $source,
            $this->state,
            new Licensio_KB_Import_Redirects( 'heroic', sys_get_temp_dir() . '/licensio-kb-unused', true ),
            $this->report,
            $dry_run,
            null,
            $claim_keys
        );
    }

    /**
     * A source double reporting a fixed set of articles and terms.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $data What the source should report.
     *
     * @return Licensio_KB_Heroic_Import_Source The source double.
     */
    private function source( array $data ) {
        return new Licensio_KB_Heroic_Import_Source_Double( $data );
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
     * An article already living under the Heroic slug is adopted and updated in place.
     *
     * Recreated-by-hand content is the common case on a site prepared for
     * migration: the article exists, carries no provenance, and would
     * otherwise be duplicated as `slug-2`. It is claimed instead — same field
     * array a create would write, plus the provenance meta, the terms, the
     * thumbnail and the attachments.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_existing_article_under_the_heroic_slug_is_adopted_and_updated() {
        $this->wpdb->slugs['email-logs'] = 950;

        $source = $this->source(
            array(
                'articles' => array(
                    array(
                        'ID'           => 12,
                        'post_title'   => 'Email logs',
                        'post_content' => '<p>The real body.</p>',
                        'post_status'  => 'publish',
                        'post_name'    => 'email-logs',
                    ),
                ),
            )
        );

        $this->migrator( $source )->migrate_articles_batch( 10 );

        $this->assertSame( array(), $this->inserted_posts, 'An adoptable article must not be duplicated.' );

        $this->assertCount( 1, $this->updated_posts );
        $this->assertSame( 950, $this->updated_posts[0]['ID'] );
        $this->assertSame( 'Email logs', $this->updated_posts[0]['post_title'] );
        $this->assertSame( '<p>The real body.</p>', $this->updated_posts[0]['post_content'] );
        $this->assertSame( 'email-logs', $this->updated_posts[0]['post_name'] );

        $this->assertSame( 12, $this->post_meta[950][ Licensio_KB_Heroic_Import_Content_WPDB::META_SOURCE_POST ], 'An adopted article must gain the provenance meta.' );
        $this->assertSame( 1, $this->report->get( 'articles_adopted' ) );
        $this->assertSame( 0, $this->report->get( 'articles_created' ) );
    }

    /**
     * A slug held by an article migrated from a different Heroic ID is a collision, not an adoption.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_slug_claimed_by_another_migrated_article_is_not_adopted() {
        $this->wpdb->slugs['email-logs'] = 950;
        $this->existing_provenance[950]  = '42';
        $this->stored_post_slug          = 'email-logs-2';

        $source = $this->source(
            array(
                'articles' => array(
                    array(
                        'ID'           => 12,
                        'post_title'   => 'Email logs',
                        'post_content' => 'Body.',
                        'post_status'  => 'publish',
                        'post_name'    => 'email-logs',
                    ),
                ),
            )
        );

        $this->migrator( $source )->migrate_articles_batch( 10 );

        $this->assertCount( 1, $this->inserted_posts, 'Two distinct Heroic articles must never merge into one target.' );
        $this->assertSame( array(), $this->updated_posts );
        $this->assertSame( 1, $this->report->get( 'articles_created' ) );
        $this->assertSame( 1, $this->report->get( 'slug_collisions' ), 'The uniquified slug must still be reported.' );
    }

    /**
     * A dry run counts an adoption as an adoption.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_dry_run_counts_an_adoption_as_an_adoption() {
        $this->wpdb->slugs['email-logs'] = 950;

        $source = $this->source(
            array(
                'articles' => array(
                    array(
                        'ID'          => 12,
                        'post_title'  => 'Email logs',
                        'post_status' => 'publish',
                        'post_name'   => 'email-logs',
                    ),
                ),
            )
        );

        $this->migrator( $source, true )->migrate_articles_batch( 10 );

        $this->assertSame( 1, $this->report->get( 'articles_adopted' ) );
        $this->assertSame( 0, $this->report->get( 'articles_created' ) );
        $this->assertSame( array(), $this->inserted_posts );
        $this->assertSame( array(), $this->updated_posts );
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
                    Licensio_KB_Heroic_Import_Schema::TAXONOMY_CATEGORY => array(
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
     * Each category position is kept, and menu_order falls back to the lowest category.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_each_category_position_is_written_against_the_imported_category() {
        $this->wpdb->target_terms[ Licensio_KB_Post_Types::TAXONOMY_CATEGORY . ':31' ] = 501;
        $this->wpdb->target_terms[ Licensio_KB_Post_Types::TAXONOMY_CATEGORY . ':12' ] = 502;

        $source = $this->source(
            array(
                'articles'   => array(
                    array(
                        'ID'        => 11,
                        'post_name' => 'install',
                    ),
                ),
                'orders'     => array(
                    11 => array(
                        31 => '5',
                        12 => '9',
                    ),
                ),
                'post_terms' => array(
                    '11:' . Licensio_KB_Heroic_Import_Schema::TAXONOMY_CATEGORY => array( 31, 12 ),
                ),
            )
        );

        $this->migrator( $source )->migrate_articles_batch( 10 );

        $this->assertSame( 9, $this->inserted_posts[0]['menu_order'], 'The fallback position must come from category 12.' );
        $this->assertSame( 5, $this->post_meta[901]['licensio_order_501'] );
        $this->assertSame( 9, $this->post_meta[901]['licensio_order_502'] );
        $this->assertSame( 0, $this->report->get( 'articles_order_collapsed' ) );
        $this->assertSame( array(), $this->report->notes( 'article_order' ) );
    }

    /**
     * The log says the Heroic order keys become per-category positions.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_order_keys_are_classified_as_per_category_positions() {
        $class = $this->source( array() )->classify_post_meta( '_ht_article_order_12' );

        $this->assertSame( 'migrated', $class['action'] );
        $this->assertSame( 'licensio_order_{term} (per category)', $class['target'] );
    }

    /**
     * A position in a category that was not imported has nowhere to go.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_position_in_an_unimported_category_is_not_written() {
        $this->wpdb->target_terms[ Licensio_KB_Post_Types::TAXONOMY_CATEGORY . ':31' ] = 501;

        $source = $this->source(
            array(
                'articles'   => array(
                    array(
                        'ID'        => 11,
                        'post_name' => 'install',
                    ),
                ),
                'orders'     => array(
                    11 => array(
                        31 => '3',
                        12 => '7',
                    ),
                ),
                'post_terms' => array(
                    '11:' . Licensio_KB_Heroic_Import_Schema::TAXONOMY_CATEGORY => array( 31, 12 ),
                ),
            )
        );

        $this->migrator( $source )->migrate_articles_batch( 10 );

        $this->assertSame( 3, $this->post_meta[901]['licensio_order_501'] );
        $this->assertSame( array( 'licensio_order_501' ), array_values( preg_grep( '/^licensio_order_/', array_keys( $this->post_meta[901] ) ) ) );
    }

    /**
     * Imported positions follow the category's existing ones, measured once per run.
     *
     * The category already holds positions up to 30. The first batch writes
     * after them; by the second batch the category's highest position is the
     * one the first batch wrote, but the run keeps the measure it took first,
     * so the second batch's positions keep their source spacing instead of
     * jumping past the first batch's.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_imported_positions_follow_the_existing_ones_in_every_batch() {
        $this->wpdb->target_terms[ Licensio_KB_Post_Types::TAXONOMY_CATEGORY . ':31' ] = 501;
        $this->wpdb->highest_position = '30';
        $this->term_meta[501][ $this->source( array() )->provenance_term_key() ] = 31;

        $state = new Licensio_KB_Import_State( 'heroic', array( 'terms', 'articles', 'feedback', 'views', 'searches' ), false );
        $batch = function ( $id, $position ) use ( $state ) {
            $source = $this->source(
                array(
                    'articles'   => array(
                        array(
                            'ID'        => $id,
                            'post_name' => 'article-' . $id,
                        ),
                    ),
                    'orders'     => array( $id => array( 31 => (string) $position ) ),
                    'post_terms' => array(
                        $id . ':' . Licensio_KB_Heroic_Import_Schema::TAXONOMY_CATEGORY => array( 31 ),
                    ),
                )
            );

            ( new Licensio_KB_Import_Content(
                $source,
                $state,
                new Licensio_KB_Import_Redirects( 'heroic', sys_get_temp_dir() . '/licensio-kb-unused', true ),
                $this->report
            ) )->migrate_articles_batch( 10 );
        };

        $batch( 11, 0 );

        $this->wpdb->highest_position = '31';

        $batch( 12, 3 );

        $this->assertSame( 31, $this->post_meta[901]['licensio_order_501'] );
        $this->assertSame( 34, $this->post_meta[902]['licensio_order_501'] );
        $this->assertSame( 30, $state->position_base( 501 ) );
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
                    '11:' . Licensio_KB_Heroic_Import_Schema::TAXONOMY_CATEGORY => array( 31 ),
                    '11:' . Licensio_KB_Heroic_Import_Schema::TAXONOMY_TAG      => array( 44 ),
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
                    '11:' . Licensio_KB_Heroic_Import_Schema::TAXONOMY_CATEGORY => array( 31 ),
                ),
            )
        );

        $this->migrator( $source )->migrate_articles_batch( 10 );

        $this->assertSame( array(), $this->assigned_terms, 'An article must not be filed under term 0.' );
        $this->assertSame( 1, $this->report->get( 'article_terms_missing' ) );
    }

    /**
     * The featured image follows the article and the source attachments stay put.
     *
     * The source is read-only, so an attachment is never re-parented to the
     * imported article; nothing is written to the source's posts.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_thumbnail_is_copied_and_the_source_attachments_are_not_reparented() {
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

        $this->assertSame( array(), $this->updated_posts, 'The source attachments must keep their parent.' );
        $this->assertArrayNotHasKey( 71, $this->post_meta );
        $this->assertSame( 0, $this->report->get( 'attachments_reparented' ) );
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

        $this->assertSame( 11, $this->post_meta[901][ Licensio_KB_Heroic_Import_Content_WPDB::META_SOURCE_POST ] );
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
                    Licensio_KB_Heroic_Import_Schema::TAXONOMY_CATEGORY => array(
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
                    Licensio_KB_Heroic_Import_Schema::TAXONOMY_CATEGORY => array(
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
     * A category's order, image and SVG icon cross; the icon colour is reported as unmapped.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_category_meta_crosses_and_the_icon_colour_is_reported() {
        $source = $this->source(
            array(
                'terms'     => array(
                    Licensio_KB_Heroic_Import_Schema::TAXONOMY_CATEGORY => array(
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
        $this->assertSame( 8, $this->term_meta[501][ Licensio_KB_Heroic_Import_Content_WPDB::META_SOURCE_TERM ] );

        // The icon crosses through the sanitizer; only the colour has no field.
        $this->assertArrayHasKey( Licensio_KB_Term_Meta::META_ICON_SVG, $this->term_meta[501], 'A safe SVG icon should cross into the icon field.' );
        $this->assertSame( 1, $this->report->get( 'term_meta_unmapped' ) );
        $this->assertCount( 1, $this->report->notes( 'unmapped_term_meta' ) );
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
                    Licensio_KB_Heroic_Import_Schema::TAXONOMY_TAG => array(
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
     * An imported category is not placed at the end of its level.
     *
     * Core fires `created_licensio_category` inside `wp_insert_term()`, which
     * would number imported categories in creation order. A category without
     * a source order keeps none, so it still sorts by name.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_imported_category_is_not_placed() {
        $term_meta = new Licensio_KB_Term_Meta();

        Brain\Monkey\Functions\when( 'wp_insert_term' )->alias(
            function () use ( $term_meta ) {
                $term_meta->place_new_category( 501 );

                return array( 'term_id' => 501 );
            }
        );

        $source = $this->source(
            array(
                'terms' => array(
                    Licensio_KB_Heroic_Import_Schema::TAXONOMY_CATEGORY => array(
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

        $this->assertSame( 1, $this->report->get( 'terms_created' ) );
        $this->assertArrayNotHasKey( Licensio_KB_Term_Meta::META_ORDER, isset( $this->term_meta[501] ) ? $this->term_meta[501] : array() );
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
                    Licensio_KB_Heroic_Import_Schema::TAXONOMY_CATEGORY => array(
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
        $this->assertSame( 8, $this->term_meta[640][ Licensio_KB_Heroic_Import_Content_WPDB::META_SOURCE_TERM ] );
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
                    Licensio_KB_Heroic_Import_Schema::TAXONOMY_CATEGORY => array(
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
                    Licensio_KB_Heroic_Import_Schema::TAXONOMY_CATEGORY => array(
                        array(
                            'term_id' => 8,
                            'name'    => 'Renewals',
                            'slug'    => 'renewals',
                            'parent'  => 0,
                        ),
                    ),
                    Licensio_KB_Heroic_Import_Schema::TAXONOMY_TAG      => array(
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

    /**
     * A source category mapped onto an existing category is merged into it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_mapped_category_is_merged_into_the_existing_one() {
        $this->existing_terms[77] = new WP_Term(
            array(
                'term_id' => 77,
                'name'    => 'Licensing',
            )
        );

        $source = $this->source(
            array(
                'terms' => array(
                    Licensio_KB_Heroic_Import_Schema::TAXONOMY_CATEGORY => array(
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

        $migrator = $this->migrator( $source );
        $this->state->set_category_map( array( 8 => 77 ) );
        $migrator->migrate_terms();

        $this->assertSame( array(), $this->inserted_terms, 'A merged category must not be created again.' );
        $this->assertSame( 1, $this->report->get( 'terms_merged' ) );
        $this->assertSame( 0, $this->report->get( 'terms_created' ) );
        $this->assertSame( 8, $this->term_meta[77][ Licensio_KB_Heroic_Import_Content_WPDB::META_SOURCE_TERM ], 'The target must gain the source ID so its articles are filed under it.' );
    }

    /**
     * A mapping onto a category that no longer exists fails that term only.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_mapping_onto_a_missing_category_is_a_failure() {
        $source = $this->source(
            array(
                'terms' => array(
                    Licensio_KB_Heroic_Import_Schema::TAXONOMY_CATEGORY => array(
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

        $migrator = $this->migrator( $source );
        $this->state->set_category_map( array( 8 => 77 ) );
        $migrator->migrate_terms();

        $this->assertSame( 1, $this->report->get( 'terms_failed' ) );
        $this->assertSame( 0, $this->report->get( 'terms_merged' ) );
        $this->assertSame( array(), $this->inserted_terms );
    }

    /**
     * A dry run counts a merge but writes no provenance.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_dry_run_counts_a_merge_without_writing() {
        $this->existing_terms[77] = new WP_Term(
            array(
                'term_id' => 77,
                'name'    => 'Licensing',
            )
        );

        $source = $this->source(
            array(
                'terms' => array(
                    Licensio_KB_Heroic_Import_Schema::TAXONOMY_CATEGORY => array(
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

        $migrator = $this->migrator( $source, true );
        $this->state->set_category_map( array( 8 => 77 ) );
        $migrator->migrate_terms();

        $this->assertSame( 1, $this->report->get( 'terms_merged' ) );
        $this->assertSame( array(), $this->term_meta );
        $this->assertSame( array(), $this->added_term_meta );
    }

    /**
     * Term provenance is added once, never duplicated.
     *
     * Several source categories can be merged into one target, so the meta is
     * added rather than updated; but the same source ID must not be added twice.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_term_provenance_is_not_added_twice() {
        $this->existing_terms[77] = new WP_Term(
            array(
                'term_id' => 77,
                'name'    => 'Licensing',
            )
        );
        $this->term_meta[77][ Licensio_KB_Heroic_Import_Content_WPDB::META_SOURCE_TERM ] = 8;

        $source = $this->source(
            array(
                'terms' => array(
                    Licensio_KB_Heroic_Import_Schema::TAXONOMY_CATEGORY => array(
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

        $migrator = $this->migrator( $source );
        $this->state->set_category_map( array( 8 => 77 ) );
        $migrator->migrate_terms();

        $this->assertSame( array(), $this->added_term_meta );
    }

    /**
     * An article carrying another importer's provenance is not adopted.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_article_claimed_by_another_source_is_not_adopted() {
        $this->wpdb->slugs['email-logs'] = 950;
        $this->stored_post_slug          = 'email-logs-2';

        Brain\Monkey\Functions\when( 'get_post_meta' )->alias(
            function ( $post_id, $key ) {
                unset( $post_id );

                return '_licensio_betterdocs_post_id' === $key ? '31' : '';
            }
        );

        $source = $this->source(
            array(
                'articles' => array(
                    array(
                        'ID'          => 12,
                        'post_title'  => 'Email logs',
                        'post_status' => 'publish',
                        'post_name'   => 'email-logs',
                    ),
                ),
            )
        );

        $this->migrator( $source, false, array( '_licensio_heroic_post_id', '_licensio_betterdocs_post_id' ) )->migrate_articles_batch( 10 );

        $this->assertCount( 1, $this->inserted_posts, 'Another importer\'s article must not be taken over.' );
        $this->assertSame( array(), $this->updated_posts );
        $this->assertSame( 0, $this->report->get( 'articles_adopted' ) );
    }

    /**
     * Without claim keys the same article is adopted.
     *
     * The control for the test above: only the claim keys differ.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_same_article_is_adopted_when_no_other_source_claims_it() {
        $this->wpdb->slugs['email-logs'] = 950;

        Brain\Monkey\Functions\when( 'get_post_meta' )->alias(
            function ( $post_id, $key ) {
                unset( $post_id );

                return '_licensio_betterdocs_post_id' === $key ? '31' : '';
            }
        );

        $source = $this->source(
            array(
                'articles' => array(
                    array(
                        'ID'          => 12,
                        'post_title'  => 'Email logs',
                        'post_status' => 'publish',
                        'post_name'   => 'email-logs',
                    ),
                ),
            )
        );

        $this->migrator( $source )->migrate_articles_batch( 10 );

        $this->assertSame( 1, $this->report->get( 'articles_adopted' ) );
    }

    /**
     * A failed article insert is counted and noted under the source label.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_failed_article_is_reported_under_the_source_label() {
        Brain\Monkey\Functions\when( 'wp_insert_post' )->alias(
            function () {
                return new WP_Error( 'db_insert_error', 'Could not insert' );
            }
        );

        $source = $this->source(
            array(
                'articles' => array(
                    array(
                        'ID'        => 5,
                        'post_name' => 'install',
                    ),
                ),
            )
        );

        $this->migrator( $source )->migrate_articles_batch( 10 );

        $this->assertSame( 1, $this->report->get( 'articles_failed' ) );
        $this->assertSame( 0, $this->report->get( 'articles_created' ) );
        $this->assertSame(
            'Heroic Knowledge Base article 5 could not be created: Could not insert',
            $this->report->notes( 'articles' )[0]
        );
    }

    /**
     * Backslashes in an imported article reach the database intact.
     *
     * Block attributes hold `<` and `>` as `\u003c`-style escapes, and a code
     * sample holds Windows paths. Core unslashes the post array, so an
     * unslashed source row would lose every backslash and break the blocks.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_imported_article_keeps_every_backslash() {
        $content = '<!-- wp:paragraph {"placeholder":"\u003cb\u003e"} --><p>C:\Temp</p><!-- /wp:paragraph -->';
        $source  = $this->source(
            array(
                'articles' => array(
                    array(
                        'ID'           => 11,
                        'post_title'   => 'Paths C:\Temp',
                        'post_content' => $content,
                        'post_excerpt' => 'Use C:\Temp.',
                        'post_status'  => 'publish',
                        'post_name'    => 'paths',
                    ),
                ),
            )
        );

        $this->migrator( $source )->migrate_articles_batch( 10 );

        $this->assertSame( $content, $this->inserted_posts[0]['post_content'] );
        $this->assertSame( 'Paths C:\Temp', $this->inserted_posts[0]['post_title'] );
        $this->assertSame( 'Use C:\Temp.', $this->inserted_posts[0]['post_excerpt'] );
    }

    /**
     * Backslashes survive when an existing article is adopted and updated.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_adopted_article_keeps_every_backslash() {
        $this->wpdb->slugs['paths'] = 950;

        $content = '<!-- wp:paragraph {"placeholder":"\u003cb\u003e"} --><p>C:\Temp</p><!-- /wp:paragraph -->';
        $source  = $this->source(
            array(
                'articles' => array(
                    array(
                        'ID'           => 12,
                        'post_title'   => 'Paths',
                        'post_content' => $content,
                        'post_status'  => 'publish',
                        'post_name'    => 'paths',
                    ),
                ),
            )
        );

        $this->migrator( $source )->migrate_articles_batch( 10 );

        $this->assertSame( $content, $this->updated_posts[0]['post_content'] );
    }

    /**
     * Backslashes in an imported term's name, description and meta survive.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_imported_term_keeps_every_backslash() {
        $source = $this->source(
            array(
                'terms'     => array(
                    Licensio_KB_Heroic_Import_Schema::TAXONOMY_CATEGORY => array(
                        array(
                            'term_id'     => 8,
                            'name'        => 'Paths C:\Temp',
                            'slug'        => 'paths',
                            'description' => 'Use C:\Temp.',
                            'parent'      => 0,
                        ),
                    ),
                ),
                'term_meta' => array(
                    8 => array(
                        'meta_svg' => '<svg viewBox="0 0 24 24"><title>C:\Temp</title></svg>',
                    ),
                ),
            )
        );

        $this->migrator( $source )->migrate_terms();

        $this->assertSame( 'Paths C:\Temp', $this->inserted_terms[0]['name'] );
        $this->assertSame( 'Use C:\Temp.', $this->inserted_terms[0]['args']['description'] );
        $this->assertStringContainsString( '<title>C:\Temp</title>', $this->term_meta[501][ Licensio_KB_Term_Meta::META_ICON_SVG ] );
    }
}

/**
 * A source double reporting a fixed Heroic site.
 *
 * @since 1.0.0
 */
class Licensio_KB_Heroic_Import_Source_Double extends Licensio_KB_Heroic_Import_Source {

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
     * The seeded post meta.
     *
     * @since 1.0.0
     *
     * @param int $post_id The Heroic post ID.
     *
     * @return array<string, mixed> The meta.
     */
    public function post_meta( $post_id ) {
        return isset( $this->data['post_meta'][ (int) $post_id ] ) ? $this->data['post_meta'][ (int) $post_id ] : array();
    }

    /**
     * The seeded term names, from the seeded terms.
     *
     * @since 1.0.0
     *
     * @param int[] $term_ids The Heroic term IDs.
     *
     * @return array<int, string> Term ID to name.
     */
    public function term_names( array $term_ids ) {
        $names = array();

        foreach ( isset( $this->data['terms'] ) ? $this->data['terms'] : array() as $rows ) {
            foreach ( $rows as $row ) {
                if ( in_array( (int) $row['term_id'], array_map( 'intval', $term_ids ), true ) ) {
                    $names[ (int) $row['term_id'] ] = (string) $row['name'];
                }
            }
        }

        return $names;
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
