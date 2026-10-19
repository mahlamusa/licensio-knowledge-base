<?php
/**
 * The mapping layer of the disposable Heroic migrator.
 *
 * The migrator is not autoloaded — it ships in neither build artifact — so its
 * classes are required explicitly here.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

require_once dirname( dirname( __DIR__ ) ) . '/tools/migrate-heroic/class-licensio-kb-heroic-schema.php';
require_once dirname( dirname( __DIR__ ) ) . '/tools/migrate-heroic/class-licensio-kb-heroic-map.php';

/**
 * What the mapping layer does with a Heroic row.
 *
 * @since 1.0.0
 */
class Licensio_KB_Heroic_Map_Test extends Licensio_KB_Test_Case {

    /**
     * The address the fixture rows arrive with.
     *
     * @since 1.0.0
     * @var string
     */
    const SOURCE_IP = '203.0.113.44';

    /**
     * A salt to key the fixtures' hashes with.
     *
     * @since 1.0.0
     * @var string
     */
    const SALT = 'a-migration-salt';

    /**
     * Supply the WordPress functions the owning components call.
     *
     * `Licensio_KB_Feedback::sanitize_comment()` is the plugin's own sanitiser
     * and the migrator calls it rather than reimplementing it, so the function
     * it is built on has to exist. Stubbed to strip tags and trim, which is what
     * core does to the values these tests use.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        Brain\Monkey\Functions\when( 'sanitize_textarea_field' )->alias(
            function ( $value ) {
                return trim( wp_strip_all_tags( (string) $value ) );
            }
        );
    }

    /**
     * A `hkb_voting` row shaped exactly as Heroic writes one.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $overrides Values to replace.
     *
     * @return array<string, mixed> The row.
     */
    private function voting_row( array $overrides = array() ) {
        return array_merge(
            array(
                'vote_id'   => 7,
                'vote_key'  => 'd41d8cd98f00b204e9800998ecf8427e',
                'post_id'   => 501,
                'user_id'   => 12,
                'user_ip'   => self::SOURCE_IP,
                'datetime'  => '2025-04-01 09:30:00',
                'magnitude' => Licensio_KB_Heroic_Schema::MAGNITUDE_UP,
                'feedback'  => 'Cleared it up, thanks.',
            ),
            $overrides
        );
    }

    /**
     * A `hkb_analytics_search_atomic` row shaped exactly as Heroic writes one.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $overrides Values to replace.
     *
     * @return array<string, mixed> The row.
     */
    private function search_row( array $overrides = array() ) {
        return array_merge(
            array(
                'id'       => 33,
                'terms'    => 'Reset  Password',
                'datetime' => '2025-04-01 09:30:00',
                'hits'     => 4,
                'user_id'  => 0,
                'user_ip'  => self::SOURCE_IP,
                'source'   => Licensio_KB_Heroic_Schema::SOURCE_FULL_SEARCH,
            ),
            $overrides
        );
    }

    /**
     * A `hkb_visits` row shaped exactly as Heroic writes one.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $overrides Values to replace.
     *
     * @return array<string, mixed> The row.
     */
    private function visit_row( array $overrides = array() ) {
        return array_merge(
            array(
                'visit_id'    => 91,
                'object_type' => Licensio_KB_Heroic_Schema::OBJECT_TYPE_ARTICLE,
                'object_id'   => 501,
                'user_id'     => 0,
                'user_ip'     => self::SOURCE_IP,
                'datetime'    => '2025-04-01 09:30:00',
                'duration'    => 0,
            ),
            $overrides
        );
    }

    /**
     * Heroic's magnitudes are 10, 5 and 0, and the split follows Heroic's own query.
     *
     * A migrator written against the obvious assumption of 1/-1 would test
     * `magnitude < 0`, find nothing, and turn every down vote into an up vote.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_vote_reproduces_heroics_own_up_down_split() {
        $this->assertSame( Licensio_KB_Feedback::VOTE_UP, Licensio_KB_Heroic_Map::vote( 10 ), 'Heroic stores an up vote as 10.' );
        $this->assertSame( Licensio_KB_Feedback::VOTE_DOWN, Licensio_KB_Heroic_Map::vote( 0 ), 'Heroic stores a down vote as 0, not -1.' );
        $this->assertSame( Licensio_KB_Feedback::VOTE_UP, Licensio_KB_Heroic_Map::vote( 5 ), 'Heroic counts a neutral vote as helpful.' );
        $this->assertSame( Licensio_KB_Feedback::VOTE_DOWN, Licensio_KB_Heroic_Map::vote( -3 ) );
        $this->assertSame( Licensio_KB_Feedback::VOTE_DOWN, Licensio_KB_Heroic_Map::vote( '0' ) );
    }

    /**
     * Heroic's numeric search source becomes the target's source name.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_search_source_translates_heroics_integer_column() {
        $this->assertSame( 'live', Licensio_KB_Heroic_Map::search_source( Licensio_KB_Heroic_Schema::SOURCE_LIVE_SEARCH ) );
        $this->assertSame( 'site', Licensio_KB_Heroic_Map::search_source( Licensio_KB_Heroic_Schema::SOURCE_FULL_SEARCH ) );
        $this->assertSame( 'site', Licensio_KB_Heroic_Map::search_source( null ), 'Rows predating the source column are full searches.' );
        $this->assertSame( 'site', Licensio_KB_Heroic_Map::search_source( '' ) );
    }

    /**
     * Every source produced is one the target column accepts.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_produced_source_is_one_the_target_knows() {
        foreach ( array( null, '', 0, 1, 2, 99 ) as $source ) {
            $this->assertContains(
                Licensio_KB_Heroic_Map::search_source( $source ),
                Licensio_KB_Search_Log::SOURCES,
                'The migrator invented a source the analytics reports cannot group by.'
            );
        }
    }

    /**
     * A datetime is shifted by the supplied offset and never by anything else.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_to_utc_applies_exactly_the_offset_it_is_given() {
        $this->assertSame( '2025-04-01 09:30:00', Licensio_KB_Heroic_Map::to_utc( '2025-04-01 09:30:00' ) );
        $this->assertSame( '2025-04-01 07:30:00', Licensio_KB_Heroic_Map::to_utc( '2025-04-01 09:30:00', 7200 ) );
        $this->assertSame( '2025-04-01 11:30:00', Licensio_KB_Heroic_Map::to_utc( '2025-04-01 09:30:00', -7200 ) );
    }

    /**
     * An absent or zero datetime becomes the column's zero value, not today.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_to_utc_does_not_invent_a_date_for_a_row_that_has_none() {
        $this->assertSame( '0000-00-00 00:00:00', Licensio_KB_Heroic_Map::to_utc( '' ) );
        $this->assertSame( '0000-00-00 00:00:00', Licensio_KB_Heroic_Map::to_utc( '0000-00-00 00:00:00' ) );
        $this->assertSame( '0000-00-00 00:00:00', Licensio_KB_Heroic_Map::to_utc( 'not a date' ) );
        $this->assertSame( '0000-00-00 00:00:00', Licensio_KB_Heroic_Map::to_utc( null ) );
    }

    /**
     * The visitor hash is the plugin's own HMAC, and is not the address.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_visitor_hash_is_a_sha256_hmac_and_not_the_address() {
        $hash = Licensio_KB_Heroic_Map::visitor_hash( $this->voting_row(), self::SALT );

        $this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash );
        $this->assertNotSame( self::SOURCE_IP, $hash );
        $this->assertStringNotContainsString( self::SOURCE_IP, $hash );

        $this->assertSame(
            Licensio_KB_Visitor::hash( self::SOURCE_IP, Licensio_KB_Heroic_Map::IMPORT_AGENT, self::SALT ),
            $hash,
            'A migrated identity must be the same construction the live recorder uses.'
        );
    }

    /**
     * Two visitors get two identities; one visitor gets one.
     *
     * Without this, an analytics migration that hashed everything to a constant
     * would still pass a no-IP scan while destroying every unique-visitor count.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_visitor_hash_separates_visitors_and_is_stable_for_one() {
        $first  = Licensio_KB_Heroic_Map::visitor_hash( $this->voting_row(), self::SALT );
        $same   = Licensio_KB_Heroic_Map::visitor_hash( $this->voting_row( array( 'vote_id' => 8 ) ), self::SALT );
        $other  = Licensio_KB_Heroic_Map::visitor_hash( $this->voting_row( array( 'user_ip' => '198.51.100.9' ) ), self::SALT );
        $rekeyd = Licensio_KB_Heroic_Map::visitor_hash( $this->voting_row(), 'a-different-salt' );

        $this->assertSame( $first, $same, 'One visitor must resolve to one identity across rows.' );
        $this->assertNotSame( $first, $other, 'Two addresses must not collapse into one identity.' );
        $this->assertNotSame( $first, $rekeyd, 'The salt must key the hash.' );
    }

    /**
     * A migrated identity cannot collide with a live one for the same visitor.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_migrated_identity_is_domain_separated_from_a_live_one() {
        $this->assertNotSame(
            Licensio_KB_Visitor::hash( self::SOURCE_IP, 'Mozilla/5.0', self::SALT ),
            Licensio_KB_Heroic_Map::visitor_hash( $this->voting_row(), self::SALT )
        );
    }

    /**
     * A mapped feedback row has exactly the target's columns and nothing else.
     *
     * The assertion is on the whole key set, not on the absence of `user_ip`.
     * A row that grew a stray column would be a row the target table rejects,
     * and a row that lost one is a row that silently loses data.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_feedback_row_has_exactly_the_target_columns() {
        $row = Licensio_KB_Heroic_Map::feedback_row( $this->voting_row(), 900, self::SALT );

        $this->assertSame(
            array( 'article_id', 'vote', 'comment', 'visitor_hash', 'user_id', 'status', 'created_at' ),
            array_keys( $row )
        );
    }

    /**
     * A mapped feedback row preserves direction, comment, author and date.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_feedback_row_preserves_what_the_vote_said() {
        $row = Licensio_KB_Heroic_Map::feedback_row( $this->voting_row(), 900, self::SALT );

        $this->assertSame( 900, $row['article_id'] );
        $this->assertSame( Licensio_KB_Feedback::VOTE_UP, $row['vote'] );
        $this->assertSame( 'Cleared it up, thanks.', $row['comment'] );
        $this->assertSame( 12, $row['user_id'] );
        $this->assertSame( Licensio_KB_Feedback::STATUS_NEW, $row['status'] );
        $this->assertSame( '2025-04-01 09:30:00', $row['created_at'] );
    }

    /**
     * A mapped search row has exactly the target's columns.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_search_log_row_has_exactly_the_target_columns() {
        $row = Licensio_KB_Heroic_Map::search_log_row( $this->search_row(), self::SALT );

        $this->assertSame(
            array( 'term', 'normalized_term', 'results_count', 'visitor_hash', 'source', 'created_at' ),
            array_keys( $row )
        );
    }

    /**
     * A mapped search row keeps the term and normalises it the way the plugin does.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_search_log_row_normalises_through_the_owning_component() {
        $row = Licensio_KB_Heroic_Map::search_log_row( $this->search_row(), self::SALT );

        $this->assertSame( 'Reset  Password', $row['term'] );
        $this->assertSame( Licensio_KB_Search_Log::normalize( 'Reset  Password' ), $row['normalized_term'] );
        $this->assertSame( 'reset password', $row['normalized_term'] );
        $this->assertSame( 4, $row['results_count'] );
        $this->assertSame( 'site', $row['source'] );
    }

    /**
     * A term longer than the target column is cut to fit rather than truncated by MySQL.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_search_log_row_fits_the_target_column() {
        $row = Licensio_KB_Heroic_Map::search_log_row(
            $this->search_row( array( 'terms' => str_repeat( 'x', 400 ) ) ),
            self::SALT
        );

        $this->assertSame( Licensio_KB_Heroic_Map::TERM_MAX_LENGTH, strlen( $row['term'] ) );
        $this->assertLessThanOrEqual( Licensio_KB_Heroic_Map::TERM_MAX_LENGTH, strlen( $row['normalized_term'] ) );
    }

    /**
     * A mapped view row has exactly the target's columns.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_article_view_row_has_exactly_the_target_columns() {
        $row = Licensio_KB_Heroic_Map::article_view_row( $this->visit_row(), 900, self::SALT );

        $this->assertSame( array( 'article_id', 'visitor_hash', 'created_at' ), array_keys( $row ) );
        $this->assertSame( 900, $row['article_id'] );
        $this->assertSame( '2025-04-01 09:30:00', $row['created_at'] );
    }

    /**
     * No mapped row carries the address, in any column, under any name.
     *
     * This is the unit-level twin of the integration suite's schema-wide scan.
     * It does not care what a column is called, only that the value is gone.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_no_mapped_row_carries_the_address_in_any_column() {
        $rows = array(
            Licensio_KB_Heroic_Map::feedback_row( $this->voting_row(), 900, self::SALT ),
            Licensio_KB_Heroic_Map::search_log_row( $this->search_row(), self::SALT ),
            Licensio_KB_Heroic_Map::article_view_row( $this->visit_row(), 900, self::SALT ),
        );

        foreach ( $rows as $row ) {
            $this->assertNotEmpty( $row, 'An empty row would pass this vacuously.' );

            foreach ( $row as $column => $value ) {
                $this->assertStringNotContainsString(
                    self::SOURCE_IP,
                    (string) $value,
                    sprintf( 'Column %s carries the visitor address.', $column )
                );
            }

            $this->assertArrayNotHasKey( Licensio_KB_Heroic_Schema::COLUMN_IP, $row );
        }
    }

    /**
     * Only an article visit is an article view.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_only_article_visits_are_article_views() {
        $this->assertTrue( Licensio_KB_Heroic_Map::is_article_visit( $this->visit_row() ) );

        foreach ( array( 'ht_kb_archive', 'ht_kb_category', 'ht_kb_tag', '', 'undefined' ) as $type ) {
            $this->assertFalse(
                Licensio_KB_Heroic_Map::is_article_visit( $this->visit_row( array( 'object_type' => $type ) ) ),
                sprintf( 'A %s visit is not an article view.', $type )
            );
        }
    }

    /**
     * The category order and image cross; the SVG icon and its colour do not.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_category_meta_maps_order_and_image_and_reports_the_icon() {
        Brain\Monkey\Functions\when( 'get_post_type' )->justReturn( 'attachment' );

        $mapped = Licensio_KB_Heroic_Map::category_meta(
            array(
                'term_order'     => '4',
                'meta_image'     => '77',
                'meta_svg'       => '<svg viewBox="0 0 10 10"><path d="M0 0"/></svg>',
                'meta_svg_color' => '#ff0000',
                'custom_link'    => 'https://example.test/elsewhere/',
            )
        );

        $this->assertSame(
            array(
                Licensio_KB_Term_Meta::META_ORDER    => 4,
                Licensio_KB_Term_Meta::META_IMAGE_ID => 77,
            ),
            $mapped['meta']
        );

        $this->assertSame(
            array( Licensio_KB_Heroic_Schema::META_TERM_SVG, Licensio_KB_Heroic_Schema::META_TERM_SVG_COLOR ),
            $mapped['unmapped'],
            'An icon that cannot cross must be reported, not dropped in silence.'
        );
    }

    /**
     * The SVG markup never reaches a target meta value.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_category_meta_never_puts_markup_in_a_target_field() {
        Brain\Monkey\Functions\when( 'get_post_type' )->justReturn( 'attachment' );

        $mapped = Licensio_KB_Heroic_Map::category_meta(
            array(
                'meta_svg'   => '<svg onload="alert(1)"></svg>',
                'term_order' => '2',
            )
        );

        $this->assertNotEmpty( $mapped['meta'], 'An empty meta array would pass the loop below vacuously.' );

        foreach ( $mapped['meta'] as $value ) {
            $this->assertStringNotContainsString( '<svg', (string) $value );
            $this->assertStringNotContainsString( 'alert', (string) $value );
        }

        $this->assertContains( Licensio_KB_Heroic_Schema::META_TERM_SVG, $mapped['unmapped'] );
    }

    /**
     * A term meta value naming something that is not an attachment is not carried.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_category_image_is_validated_through_the_owning_component() {
        Brain\Monkey\Functions\when( 'get_post_type' )->justReturn( 'page' );

        $mapped = Licensio_KB_Heroic_Map::category_meta( array( 'meta_image' => '77' ) );

        $this->assertArrayNotHasKey( Licensio_KB_Term_Meta::META_IMAGE_ID, $mapped['meta'] );
    }

    /**
     * A category with nothing set produces nothing and reports nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_category_meta_of_an_unconfigured_term_is_empty() {
        $mapped = Licensio_KB_Heroic_Map::category_meta(
            array(
                'meta_svg'       => '',
                'meta_svg_color' => '',
            )
        );

        $this->assertSame( array(), $mapped['meta'] );
        $this->assertSame( array(), $mapped['unmapped'] );
    }

    /**
     * The article position kept is the one from the lowest category term ID.
     *
     * The fixture is deliberately built so the lowest term ID does NOT hold the
     * lowest position: an implementation that took `min()` over the values, or
     * that took whichever entry happened to come first, gives 1 or 9 here, and
     * only the documented rule gives 9.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_article_order_keeps_the_position_from_the_lowest_category() {
        $result = Licensio_KB_Heroic_Map::article_order(
            array(
                31 => '5',
                12 => '9',
                44 => '1',
            )
        );

        $this->assertSame( 9, $result['order'], 'The kept position must come from category 12, the lowest term ID.' );
        $this->assertSame( 12, $result['source_term_id'] );
        $this->assertTrue( $result['ambiguous'] );
    }

    /**
     * An article ordered identically everywhere loses nothing, and says so.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_article_order_is_not_ambiguous_when_the_source_agrees() {
        $result = Licensio_KB_Heroic_Map::article_order(
            array(
                31 => '3',
                12 => '3',
            )
        );

        $this->assertSame( 3, $result['order'] );
        $this->assertSame( 12, $result['source_term_id'] );
        $this->assertFalse( $result['ambiguous'] );
    }

    /**
     * An article Heroic never ordered gets position zero.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_article_order_of_an_unordered_article_is_zero() {
        $result = Licensio_KB_Heroic_Map::article_order( array() );

        $this->assertSame( 0, $result['order'] );
        $this->assertSame( 0, $result['source_term_id'] );
        $this->assertFalse( $result['ambiguous'] );
    }

    /**
     * A non-numeric stored position is ignored rather than cast to zero.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_article_order_ignores_a_position_that_is_not_a_number() {
        $result = Licensio_KB_Heroic_Map::article_order(
            array(
                12 => '',
                31 => '6',
            )
        );

        $this->assertSame( 6, $result['order'], 'An empty stored position must not win by having the lowest term ID.' );
        $this->assertSame( 31, $result['source_term_id'] );
    }

    /**
     * The CLI entry point can actually be loaded by wp --require.
     *
     * WP-CLI processes a --require file BEFORE it bootstraps WordPress, so the
     * entry point must not depend on anything WordPress provides at file scope.
     * It previously did three times over: a `defined( 'ABSPATH' ) || exit;`
     * guard that terminated the process silently, requires of classes carrying
     * the same guard, and an add_action() call that does not exist yet. The
     * result was a fully built, fully tested tool that could not be invoked at
     * all, because every test loaded the classes directly and none went through
     * the entry point.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_cli_entry_point_survives_being_required_before_wordpress() {
        $entry = dirname( dirname( __DIR__ ) ) . '/tools/migrate-heroic/migrate-heroic.php';

        $this->assertFileExists( $entry );

        /*
         * Statements only. The file explains this history in a comment, so a
         * plain substring search would match its own prose rather than any
         * code, and pass or fail for the wrong reason.
         */
        $statements = array_values(
            array_filter(
                array_map( 'trim', (array) file( $entry ) ),
                function ( $line ) {
                    return '' !== $line
                        && 0 !== strpos( $line, '*' )
                        && 0 !== strpos( $line, '/*' )
                        && 0 !== strpos( $line, '//' );
                }
            )
        );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file from this repository, not a remote resource.
        $source = (string) file_get_contents( $entry );

        $this->assertNotContains(
            "defined( 'ABSPATH' ) || exit;",
            $statements,
            'The entry point must not exit on a missing ABSPATH: WP-CLI loads it before WordPress.'
        );

        foreach ( $statements as $line ) {
            $this->assertStringNotContainsString(
                'add_action(',
                $line,
                'add_action() does not exist when a --require file is loaded; use WP_CLI::add_hook().'
            );
        }

        $this->assertStringContainsString(
            "WP_CLI::add_hook( 'after_wp_load'",
            $source,
            'The command must be registered on WP-CLI after_wp_load.'
        );
    }
}
