<?php
/**
 * The mapping layer of the BetterDocs import.
 *
 * Every function under test is pure, so these tests need no database and no
 * BetterDocs install: rows go in, rows come out.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

/**
 * What the mapping layer does with a BetterDocs row.
 *
 * @since 1.0.0
 */
class Lisensio_KB_BetterDocs_Import_Map_Test extends Lisensio_KB_Test_Case {

    /**
     * A salt to key the fixtures' hashes with.
     *
     * @since 1.0.0
     * @var string
     */
    const SALT = 'a-betterdocs-salt';

    /**
     * Supply the WordPress functions the mapping layer calls.
     *
     * Attachment IDs are checked against the post type, so `get_post_type()`
     * answers `attachment` unless a test says otherwise.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        Brain\Monkey\Functions\when( 'absint' )->alias(
            function ( $value ) {
                return abs( (int) $value );
            }
        );

        Brain\Monkey\Functions\when( 'get_post_type' )->justReturn( 'attachment' );

        Brain\Monkey\Functions\when( 'maybe_unserialize' )->alias(
            function ( $value ) {
                if ( ! is_string( $value ) ) {
                    return $value;
                }

                $decoded = @unserialize( $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize

                return false === $decoded && 'b:0;' !== $value ? $value : $decoded;
            }
        );
    }

    /**
     * A `betterdocs_analytics` row.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $overrides Values to replace.
     *
     * @return array<string, mixed> The row.
     */
    private function analytics_row( array $overrides = array() ) {
        return array_merge(
            array(
                'id'           => 31,
                'post_id'      => 501,
                'impressions'  => 12,
                'unique_visit' => 4,
                'happy'        => 2,
                'normal'       => 1,
                'sad'          => 3,
                'created_at'   => '2025-04-01',
            ),
            $overrides
        );
    }

    /**
     * A `betterdocs_search_log` row joined to its keyword.
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
                'id'              => 9,
                'keyword_id'      => 3,
                'keyword'         => 'Reset  Password',
                'count'           => 2,
                'not_found_count' => 1,
                'created_at'      => '2025-04-02',
            ),
            $overrides
        );
    }

    /**
     * The order key maps onto the plugin's manual order.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_category_order_maps_to_lisensio_order() {
        $result = Lisensio_KB_BetterDocs_Import_Map::category_meta( array( 'doc_category_order' => '7' ) );

        $this->assertSame( array( Lisensio_KB_Term_Meta::META_ORDER => 7 ), $result['meta'] );
        $this->assertSame( array(), $result['unmapped'] );
    }

    /**
     * A knowledge base's order key maps the same way.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_knowledge_base_order_maps_to_lisensio_order() {
        $result = Lisensio_KB_BetterDocs_Import_Map::category_meta( array( 'kb_order' => '3' ) );

        $this->assertSame( array( Lisensio_KB_Term_Meta::META_ORDER => 3 ), $result['meta'] );
        $this->assertSame( array(), $result['unmapped'] );
    }

    /**
     * When both order keys are present the category order wins.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_category_order_takes_precedence_over_knowledge_base_order() {
        $result = Lisensio_KB_BetterDocs_Import_Map::category_meta(
            array(
                'kb_order'           => '9',
                'doc_category_order' => '2',
            )
        );

        $this->assertSame( 2, $result['meta'][ Lisensio_KB_Term_Meta::META_ORDER ] );
    }

    /**
     * A non-numeric category order falls through to the knowledge base order.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_non_numeric_category_order_falls_back_to_knowledge_base_order() {
        $result = Lisensio_KB_BetterDocs_Import_Map::category_meta(
            array(
                'doc_category_order' => 'first',
                'kb_order'           => '5',
            )
        );

        $this->assertSame( 5, $result['meta'][ Lisensio_KB_Term_Meta::META_ORDER ] );
    }

    /**
     * A negative order is clamped to zero by the shared sanitiser.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_negative_order_is_clamped_to_zero() {
        $result = Lisensio_KB_BetterDocs_Import_Map::category_meta( array( 'doc_category_order' => '-4' ) );

        $this->assertSame( 0, $result['meta'][ Lisensio_KB_Term_Meta::META_ORDER ] );
    }

    /**
     * The icon attachment maps onto the icon ID.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_category_image_id_maps_to_the_icon_id() {
        $result = Lisensio_KB_BetterDocs_Import_Map::category_meta( array( 'doc_category_image-id' => '88' ) );

        $this->assertSame( array( Lisensio_KB_Term_Meta::META_ICON_ID => 88 ), $result['meta'] );
        $this->assertSame( array(), $result['unmapped'] );
    }

    /**
     * The thumbnail attachment maps onto the image ID.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_category_thumb_id_maps_to_the_image_id() {
        $result = Lisensio_KB_BetterDocs_Import_Map::category_meta( array( 'doc_category_thumb-id' => '91' ) );

        $this->assertSame( array( Lisensio_KB_Term_Meta::META_IMAGE_ID => 91 ), $result['meta'] );
        $this->assertSame( array(), $result['unmapped'] );
    }

    /**
     * An ID that is not an attachment is dropped, and not reported as unmapped.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_attachment_id_that_is_not_an_attachment_is_dropped() {
        Brain\Monkey\Functions\when( 'get_post_type' )->justReturn( 'post' );

        $result = Lisensio_KB_BetterDocs_Import_Map::category_meta(
            array(
                'doc_category_image-id' => '88',
                'doc_category_thumb-id' => '91',
            )
        );

        $this->assertSame( array(), $result['meta'] );
        $this->assertSame( array(), $result['unmapped'] );
    }

    /**
     * Knowledge base membership and article order are carried elsewhere.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_knowledge_base_and_docs_order_keys_are_not_reported_as_unmapped() {
        $result = Lisensio_KB_BetterDocs_Import_Map::category_meta(
            array(
                'doc_category_knowledge_base' => 'a:1:{i:0;s:3:"faq";}',
                '_docs_order'                 => '4,5,6',
            )
        );

        $this->assertSame( array(), $result['meta'] );
        $this->assertSame( array(), $result['unmapped'] );
    }

    /**
     * Any other key with a value is reported so the log can say it was not copied.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_other_keys_with_a_value_are_unmapped() {
        $result = Lisensio_KB_BetterDocs_Import_Map::category_meta(
            array(
                'doc_category_order' => '1',
                'custom_colour'      => '#ff0000',
                'another_setting'    => 'on',
            )
        );

        $this->assertSame( array( 'custom_colour', 'another_setting' ), $result['unmapped'] );
    }

    /**
     * Empty and whitespace-only values are ignored.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_empty_values_are_not_reported_as_unmapped() {
        $result = Lisensio_KB_BetterDocs_Import_Map::category_meta(
            array(
                'custom_colour' => '',
                'blank'         => "  \n",
            )
        );

        $this->assertSame( array(), $result['unmapped'] );
    }

    /**
     * A non-scalar value always counts as a value.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_non_scalar_value_is_unmapped() {
        $result = Lisensio_KB_BetterDocs_Import_Map::category_meta( array( 'custom_list' => array( 'a' ) ) );

        $this->assertSame( array( 'custom_list' ), $result['unmapped'] );
    }

    /**
     * The handled keys are the four the import carries by other means.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_handled_term_meta_lists_the_four_keys() {
        $this->assertSame(
            array( 'doc_category_order', 'kb_order', 'doc_category_knowledge_base', '_docs_order' ),
            Lisensio_KB_BetterDocs_Import_Map::handled_term_meta()
        );
    }

    /**
     * An article's position is its index in each list that names it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_positions_are_zero_based_indexes_in_each_list() {
        $positions = Lisensio_KB_BetterDocs_Import_Map::positions(
            30,
            array(
                4 => '10,20,30,40',
                5 => '30,31',
            )
        );

        $this->assertSame(
            array(
                4 => 2,
                5 => 0,
            ),
            $positions
        );
    }

    /**
     * A list that does not name the article contributes nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_positions_omit_lists_without_the_article() {
        $positions = Lisensio_KB_BetterDocs_Import_Map::positions(
            99,
            array(
                4 => '10,20',
                5 => '',
            )
        );

        $this->assertSame( array(), $positions );
    }

    /**
     * Whitespace around the commas does not matter.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_positions_tolerate_whitespace_and_a_trailing_comma() {
        $positions = Lisensio_KB_BetterDocs_Import_Map::positions( 30, array( 4 => ' 10 , 20 ,  30 ,' ) );

        $this->assertSame( array( 4 => 2 ), $positions );
    }

    /**
     * An empty item in the middle of a list must not shift the positions after it.
     *
     * array_filter() keeps the original keys, so without re-indexing
     * '10, ,20,30' would report the article at 3 instead of 2.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_positions_ignore_an_empty_item_inside_the_list() {
        $this->assertSame( array( 4 => 2 ), Lisensio_KB_BetterDocs_Import_Map::positions( 30, array( 4 => '10, ,20,30' ) ) );
    }

    /**
     * A serialised list is unpacked into slugs.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_knowledge_bases_unpacks_a_serialised_array() {
        $value = serialize( array( 'Getting Started', 'faq' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

        $this->assertSame( array( 'getting-started', 'faq' ), Lisensio_KB_BetterDocs_Import_Map::knowledge_bases( $value ) );
    }

    /**
     * A bare string is one knowledge base.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_knowledge_bases_accepts_a_plain_string() {
        $this->assertSame( array( 'faq' ), Lisensio_KB_BetterDocs_Import_Map::knowledge_bases( 'faq' ) );
    }

    /**
     * Anything unusable yields no knowledge bases.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_knowledge_bases_returns_nothing_for_garbage() {
        $this->assertSame( array(), Lisensio_KB_BetterDocs_Import_Map::knowledge_bases( '' ) );
        $this->assertSame( array(), Lisensio_KB_BetterDocs_Import_Map::knowledge_bases( null ) );
        $this->assertSame( array(), Lisensio_KB_BetterDocs_Import_Map::knowledge_bases( 42 ) );
        $this->assertSame( array(), Lisensio_KB_BetterDocs_Import_Map::knowledge_bases( true ) );
    }

    /**
     * A truncated serialisation is not an array, so it is read as one plain slug.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_knowledge_bases_reads_a_broken_serialisation_as_a_plain_string() {
        $this->assertSame( array( 'a-1-broken' ), Lisensio_KB_BetterDocs_Import_Map::knowledge_bases( 'a:1:{broken' ) );
    }

    /**
     * Blank entries in a stored list are dropped.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_knowledge_bases_drops_blank_entries() {
        $value = serialize( array( 'faq', '', '!!!' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

        $this->assertSame( array( 'faq' ), Lisensio_KB_BetterDocs_Import_Map::knowledge_bases( $value ) );
    }

    /**
     * A well-formed day becomes noon on that day.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_day_to_datetime_puts_a_valid_day_at_noon() {
        $this->assertSame( '2025-04-01 12:00:00', Lisensio_KB_BetterDocs_Import_Map::day_to_datetime( '2025-04-01' ) );
        $this->assertSame( '2025-04-01 12:00:00', Lisensio_KB_BetterDocs_Import_Map::day_to_datetime( ' 2025-04-01 ' ) );
    }

    /**
     * The zero date and anything malformed become the zero datetime.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_day_to_datetime_rejects_the_zero_date_and_garbage() {
        $zero = '0000-00-00 00:00:00';

        $this->assertSame( $zero, Lisensio_KB_BetterDocs_Import_Map::day_to_datetime( '0000-00-00' ) );
        $this->assertSame( $zero, Lisensio_KB_BetterDocs_Import_Map::day_to_datetime( 'yesterday' ) );
        $this->assertSame( $zero, Lisensio_KB_BetterDocs_Import_Map::day_to_datetime( '2025-04-01 09:00:00' ) );
        $this->assertSame( $zero, Lisensio_KB_BetterDocs_Import_Map::day_to_datetime( '' ) );
        $this->assertSame( $zero, Lisensio_KB_BetterDocs_Import_Map::day_to_datetime( array( '2025-04-01' ) ) );
        $this->assertSame( $zero, Lisensio_KB_BetterDocs_Import_Map::day_to_datetime( null ) );
    }

    /**
     * Happy and normal are helpful, sad is not, and every reaction is a row.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_feedback_rows_expand_each_reaction_with_the_right_vote() {
        $rows = Lisensio_KB_BetterDocs_Import_Map::feedback_rows( $this->analytics_row(), 700, self::SALT );

        $this->assertCount( 6, $rows );

        $votes = array_count_values( array_column( $rows, 'vote' ) );

        $this->assertSame( 3, $votes[ Lisensio_KB_Feedback::VOTE_UP ], 'Two happy and one normal.' );
        $this->assertSame( 3, $votes[ Lisensio_KB_Feedback::VOTE_DOWN ], 'Three sad.' );
    }

    /**
     * The expanded feedback row carries no comment, no user and the noon date.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_feedback_rows_have_the_expected_shape() {
        $rows = Lisensio_KB_BetterDocs_Import_Map::feedback_rows(
            $this->analytics_row(
                array(
                    'happy'  => 1,
                    'normal' => 0,
                    'sad'    => 0,
                )
            ),
            700,
            self::SALT
        );

        $this->assertCount( 1, $rows );
        $this->assertSame( 700, $rows[0]['article_id'] );
        $this->assertSame( '', $rows[0]['comment'] );
        $this->assertSame( 0, $rows[0]['user_id'] );
        $this->assertSame( Lisensio_KB_Feedback::STATUS_NEW, $rows[0]['status'] );
        $this->assertSame( '2025-04-01 12:00:00', $rows[0]['created_at'] );
    }

    /**
     * Every feedback row gets its own identity.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_feedback_rows_have_distinct_visitor_hashes() {
        $rows   = Lisensio_KB_BetterDocs_Import_Map::feedback_rows( $this->analytics_row(), 700, self::SALT );
        $hashes = array_column( $rows, 'visitor_hash' );

        $this->assertCount( count( $rows ), array_unique( $hashes ) );
    }

    /**
     * Negative and missing counts produce no rows.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_feedback_rows_ignore_negative_and_missing_counts() {
        $rows = Lisensio_KB_BetterDocs_Import_Map::feedback_rows(
            array(
                'id'         => 1,
                'happy'      => -3,
                'created_at' => '2025-04-01',
            ),
            700,
            self::SALT
        );

        $this->assertSame( array(), $rows );
    }

    /**
     * An impression count becomes that many view rows on the same noon.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_view_rows_expand_one_row_per_impression() {
        $rows = Lisensio_KB_BetterDocs_Import_Map::view_rows( $this->analytics_row(), 700, self::SALT );

        $this->assertCount( 12, $rows );

        foreach ( $rows as $row ) {
            $this->assertSame( 700, $row['article_id'] );
            $this->assertSame( '2025-04-01 12:00:00', $row['created_at'] );
        }
    }

    /**
     * Impressions are shared out among the unique visitors in turn.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_view_identities_cycle_over_the_unique_visitors() {
        $rows   = Lisensio_KB_BetterDocs_Import_Map::view_rows( $this->analytics_row(), 700, self::SALT );
        $hashes = array_column( $rows, 'visitor_hash' );

        $this->assertCount( 4, array_unique( $hashes ), 'Four unique visitors.' );
        $this->assertSame( $hashes[0], $hashes[4], 'The cycle repeats every four impressions.' );
        $this->assertSame( $hashes[3], $hashes[11] );
        $this->assertNotSame( $hashes[0], $hashes[1] );
    }

    /**
     * A day with no unique-visitor figure gives every impression its own identity.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_view_rows_with_zero_unique_visitors_are_all_distinct() {
        $rows   = Lisensio_KB_BetterDocs_Import_Map::view_rows( $this->analytics_row( array( 'unique_visit' => 0 ) ), 700, self::SALT );
        $hashes = array_column( $rows, 'visitor_hash' );

        $this->assertCount( 12, array_unique( $hashes ) );
    }

    /**
     * More unique visitors than impressions is clamped to the impressions.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_view_rows_clamp_unique_visitors_to_the_impressions() {
        $rows   = Lisensio_KB_BetterDocs_Import_Map::view_rows(
            $this->analytics_row(
                array(
                    'impressions'  => 3,
                    'unique_visit' => 50,
                )
            ),
            700,
            self::SALT
        );
        $hashes = array_column( $rows, 'visitor_hash' );

        $this->assertCount( 3, $rows );
        $this->assertCount( 3, array_unique( $hashes ) );
    }

    /**
     * A single unique visitor means one shared identity.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_view_rows_with_one_unique_visitor_share_an_identity() {
        $rows   = Lisensio_KB_BetterDocs_Import_Map::view_rows( $this->analytics_row( array( 'unique_visit' => 1 ) ), 700, self::SALT );
        $hashes = array_column( $rows, 'visitor_hash' );

        $this->assertCount( 1, array_unique( $hashes ) );
    }

    /**
     * No impressions, no rows, whatever the unique figure says.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_view_rows_for_zero_impressions_are_empty() {
        $this->assertSame(
            array(),
            Lisensio_KB_BetterDocs_Import_Map::view_rows(
                $this->analytics_row(
                    array(
                        'impressions'  => 0,
                        'unique_visit' => 5,
                    )
                ),
                700,
                self::SALT
            )
        );
        $this->assertSame( array(), Lisensio_KB_BetterDocs_Import_Map::view_rows( $this->analytics_row( array( 'impressions' => -2 ) ), 700, self::SALT ) );
    }

    /**
     * Found searches come first, each with one result, then the not-found ones.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_search_rows_expand_found_then_not_found() {
        $rows = Lisensio_KB_BetterDocs_Import_Map::search_rows( $this->search_row(), self::SALT );

        $this->assertSame( array( 1, 1, 0 ), array_column( $rows, 'results_count' ) );
    }

    /**
     * The expanded search row has the term, its normalised form and the noon date.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_search_rows_have_the_expected_shape() {
        $rows = Lisensio_KB_BetterDocs_Import_Map::search_rows( $this->search_row(), self::SALT );

        $this->assertSame( 'Reset  Password', $rows[0]['term'] );
        $this->assertSame( 'reset password', $rows[0]['normalized_term'] );
        $this->assertSame( 'site', $rows[0]['source'] );
        $this->assertSame( '2025-04-02 12:00:00', $rows[0]['created_at'] );
        $this->assertCount( 3, array_unique( array_column( $rows, 'visitor_hash' ) ) );
    }

    /**
     * An empty keyword produces no rows at all.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_search_rows_for_an_empty_keyword_are_empty() {
        $this->assertSame( array(), Lisensio_KB_BetterDocs_Import_Map::search_rows( $this->search_row( array( 'keyword' => '   ' ) ), self::SALT ) );

        $row = $this->search_row();

        unset( $row['keyword'] );

        $this->assertSame( array(), Lisensio_KB_BetterDocs_Import_Map::search_rows( $row, self::SALT ) );
    }

    /**
     * A keyword that is only found, or only not found, expands one group.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_search_rows_with_a_single_group() {
        $found     = Lisensio_KB_BetterDocs_Import_Map::search_rows(
            $this->search_row(
                array(
                    'count'           => 2,
                    'not_found_count' => 0,
                )
            ),
            self::SALT
        );
        $not_found = Lisensio_KB_BetterDocs_Import_Map::search_rows(
            $this->search_row(
                array(
                    'count'           => 0,
                    'not_found_count' => 2,
                )
            ),
            self::SALT
        );

        $this->assertSame( array( 1, 1 ), array_column( $found, 'results_count' ) );
        $this->assertSame( array( 0, 0 ), array_column( $not_found, 'results_count' ) );
    }

    /**
     * A keyword longer than the column is cut to 191 characters, not bytes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_search_rows_truncate_a_long_keyword_to_191_characters() {
        $keyword = str_repeat( 'é', 300 );
        $rows    = Lisensio_KB_BetterDocs_Import_Map::search_rows( $this->search_row( array( 'keyword' => $keyword ) ), self::SALT );

        $this->assertSame( 191, mb_strlen( $rows[0]['term'] ) );
        $this->assertSame( 191, mb_strlen( $rows[0]['normalized_term'] ) );
        $this->assertSame( str_repeat( 'é', 191 ), $rows[0]['term'] );
    }

    /**
     * The same input and salt always give the same hash; a new salt does not.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_visitor_hash_is_stable_per_salt() {
        $first = Lisensio_KB_BetterDocs_Import_Map::visitor_hash( 'view:31:0', self::SALT );

        $this->assertSame( $first, Lisensio_KB_BetterDocs_Import_Map::visitor_hash( 'view:31:0', self::SALT ) );
        $this->assertNotSame( $first, Lisensio_KB_BetterDocs_Import_Map::visitor_hash( 'view:31:0', 'another-salt' ) );
        $this->assertNotSame( $first, Lisensio_KB_BetterDocs_Import_Map::visitor_hash( 'view:31:1', self::SALT ) );
    }

    /**
     * No hash is, or contains, the identity it was derived from.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_visitor_hashes_never_contain_the_identity_in_clear() {
        $rows = array_merge(
            Lisensio_KB_BetterDocs_Import_Map::feedback_rows( $this->analytics_row(), 700, self::SALT ),
            Lisensio_KB_BetterDocs_Import_Map::view_rows( $this->analytics_row(), 700, self::SALT ),
            Lisensio_KB_BetterDocs_Import_Map::search_rows( $this->search_row(), self::SALT )
        );

        $this->assertNotEmpty( $rows );

        foreach ( $rows as $row ) {
            $this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $row['visitor_hash'] );

            foreach ( array( 'reaction:', 'view:', 'search:', 'betterdocs', self::SALT ) as $needle ) {
                $this->assertStringNotContainsString( $needle, $row['visitor_hash'] );
            }
        }
    }

    /**
     * The imported identities are domain-separated from live ones.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_visitor_hash_is_keyed_with_the_import_agent() {
        $expected = Lisensio_KB_Visitor::hash( 'betterdocs:view:31:0', Lisensio_KB_BetterDocs_Import_Map::IMPORT_AGENT, self::SALT );

        $this->assertSame( $expected, Lisensio_KB_BetterDocs_Import_Map::visitor_hash( 'view:31:0', self::SALT ) );
    }

    /**
     * BetterDocs settings with an equivalent are mapped, URL settings are
     * excluded, and the rest are named without their values.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_settings_map_equivalents_and_exclude_urls() {
        $mapped = Lisensio_KB_BetterDocs_Import_Map::settings(
            array(
                'search_placeholder'   => 'Search docs',
                'column_number'        => 4,
                'posts_number'         => 12,
                'nested_subcategory'   => false,
                'post_count'           => true,
                'enable_toc'           => '1',
                'docs_slug'            => 'docs',
                'permalink_structure'  => 'docs/%doc_category%',
                'ai_autowrite_api_key' => 'SECRET',
            )
        );

        $this->assertSame(
            array(
                'search_placeholder'     => 'Search docs',
                'archive_columns'        => 4,
                'articles_per_page'      => 12,
                'show_subcategories'     => false,
                'show_article_counts'    => true,
                'show_table_of_contents' => true,
            ),
            $mapped['settings']
        );
        $this->assertSame( array( 'docs_slug', 'permalink_structure' ), array_keys( $mapped['excluded'] ) );
        $this->assertSame( array( 'ai_autowrite_api_key' ), $mapped['unmapped'] );
        $this->assertStringNotContainsString( 'SECRET', wp_json_encode( $mapped ) );
    }
}
