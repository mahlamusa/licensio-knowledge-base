<?php
/**
 * The duplicate detector of the Tools screen.
 *
 * The database paths are exercised on the live site; what is tested here is
 * the matching itself — what groups, what a group is called, and which of its
 * members survives — because those rules decide what gets overwritten.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/**
 * How duplicates are found and classified.
 *
 * @since 1.0.0
 */
class Licensio_KB_Duplicates_Test extends Licensio_KB_Test_Case {

    /**
     * The detector under test.
     *
     * @since 1.0.0
     * @var Licensio_KB_Duplicates
     */
    private $duplicates;

    /**
     * Supply the text helpers the normalizer reads.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        Brain\Monkey\Functions\when( 'wp_strip_all_tags' )->alias(
            function ( $text ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- A test double for wp_strip_all_tags, whose real implementation is itself a strip_tags wrapper.
                return trim( strip_tags( (string) $text ) );
            }
        );

        $this->duplicates = new Licensio_KB_Duplicates();
    }

    /**
     * A row shaped the way the scanner reads one.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $overrides Field overrides.
     *
     * @return array<string, mixed> The row.
     */
    private function row( array $overrides = array() ) {
        return array_merge(
            array(
                'ID'           => 1,
                'post_type'    => Licensio_KB_Post_Types::POST_TYPE,
                'post_title'   => 'Email logs',
                'post_name'    => 'email-logs',
                'post_date'    => '2026-08-01 10:00:00',
                'post_status'  => 'publish',
                'post_content' => '<p>Email logs keep a record of every message.</p>',
            ),
            $overrides
        );
    }

    /**
     * The uniquifier suffix is recognized wherever core puts it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_suffix_detection_and_stripping() {
        $this->assertTrue( Licensio_KB_Duplicates::has_suffix( 'email-logs-2' ) );
        $this->assertTrue( Licensio_KB_Duplicates::has_suffix( 'email-logs-10' ) );
        $this->assertFalse( Licensio_KB_Duplicates::has_suffix( 'email-logs' ) );

        $this->assertSame( 'email-logs', Licensio_KB_Duplicates::base_slug( 'email-logs-2' ) );
        $this->assertSame( 'email-logs', Licensio_KB_Duplicates::base_slug( 'email-logs-10' ) );
        $this->assertSame( 'email-logs', Licensio_KB_Duplicates::base_slug( 'email-logs' ) );
        $this->assertSame( 'article-2', Licensio_KB_Duplicates::base_slug( 'article-2-3' ), 'Only the last numeric segment is a uniquifier.' );
    }

    /**
     * A post and its suffixed twin group; anything that differs on one of the
     * four keys does not.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_posts_group_only_on_all_four_keys() {
        $original = $this->row(
            array(
                'ID'        => 10,
                'post_name' => 'email-logs',
            )
        );
        $twin     = $this->row(
            array(
                'ID'        => 11,
                'post_name' => 'email-logs-2',
            )
        );

        $other_title = $this->row(
            array(
                'ID'         => 12,
                'post_name'  => 'email-logs-3',
                'post_title' => 'Different title',
            )
        );
        $other_date  = $this->row(
            array(
                'ID'        => 13,
                'post_name' => 'email-logs-4',
                'post_date' => '2026-08-02 10:00:00',
            )
        );
        $other_body  = $this->row(
            array(
                'ID'           => 14,
                'post_name'    => 'email-logs-5',
                'post_content' => '<p>A completely different opening.</p>',
            )
        );
        $other_slug  = $this->row(
            array(
                'ID'        => 15,
                'post_name' => 'mail-logs-2',
            )
        );

        $groups = $this->duplicates->group_posts( array( $original, $twin, $other_title, $other_date, $other_body, $other_slug ) );

        $this->assertCount( 1, $groups, 'Only the true twin pair may group.' );

        $members = reset( $groups );

        $this->assertSame( array( 10, 11 ), array_column( $members, 'ID' ) );
    }

    /**
     * Content and title comparison ignores markup, case and whitespace runs.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_signature_ignores_markup_case_and_whitespace() {
        $original = $this->row( array( 'ID' => 10 ) );
        $reworded = $this->row(
            array(
                'ID'           => 11,
                'post_name'    => 'email-logs-2',
                'post_title'   => 'EMAIL   LOGS',
                'post_content' => '<div><p>email logs  keep a record of EVERY message.</p></div>',
            )
        );

        $groups = $this->duplicates->group_posts( array( $original, $reworded ) );

        $this->assertCount( 1, $groups, 'Markup, case and spacing must not hide a duplicate.' );
    }

    /**
     * A single post never groups.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_lone_post_is_not_a_group() {
        $this->assertSame( array(), $this->duplicates->group_posts( array( $this->row() ) ) );
    }

    /**
     * An all-article group with an un-suffixed member is fixable, and the
     * member with the most meta provides the content.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_all_article_group_is_fixable_and_picks_its_members() {
        $group = $this->duplicates->describe_group(
            array(
                $this->row(
                    array(
                        'ID'        => 10,
                        'post_name' => 'email-logs',
                    )
                ),
                $this->row(
                    array(
                        'ID'        => 11,
                        'post_name' => 'email-logs-2',
                    )
                ),
            ),
            array(
                10 => 1,
                11 => 4,
            )
        );

        $this->assertTrue( $group['fixable'] );
        $this->assertSame( 'merge', $group['kind'] );
        $this->assertSame( 10, $group['canonical'], 'The member without a suffix keeps the slug.' );
        $this->assertSame( 11, $group['winner'], 'The member with the most meta provides the content.' );
    }

    /**
     * A group holding a post type the knowledge base does not own is flagged,
     * never fixed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_foreign_post_type_flags_the_group() {
        $group = $this->duplicates->describe_group(
            array(
                $this->row(
                    array(
                        'ID'        => 10,
                        'post_name' => 'email-logs',
                    )
                ),
                $this->row(
                    array(
                        'ID'        => 11,
                        'post_name' => 'email-logs-2',
                        'post_type' => 'page',
                    )
                ),
            ),
            array(
                10 => 0,
                11 => 2,
            )
        );

        $this->assertFalse( $group['fixable'], 'The tool must not overwrite a post type it does not own.' );
        $this->assertSame( 'flagged', $group['kind'] );
    }

    /**
     * A term row shaped the way the scanner reads one.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $overrides Field overrides.
     *
     * @return array<string, mixed> The row.
     */
    private function term_row( array $overrides = array() ) {
        return array_merge(
            array(
                'term_id'          => 1,
                'name'             => 'Billing',
                'slug'             => 'billing',
                'description'      => 'Questions about invoices.',
                'count'            => 3,
                'taxonomy'         => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                'term_taxonomy_id' => 1,
            ),
            $overrides
        );
    }

    /**
     * Terms group on name, base slug and description, within one taxonomy.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_terms_group_within_a_taxonomy_on_all_three_keys() {
        $original = $this->term_row(
            array(
                'term_id'          => 10,
                'term_taxonomy_id' => 10,
            )
        );
        $twin     = $this->term_row(
            array(
                'term_id'          => 11,
                'slug'             => 'billing-2',
                'term_taxonomy_id' => 11,
            )
        );

        $other_name = $this->term_row(
            array(
                'term_id'          => 12,
                'slug'             => 'billing-3',
                'name'             => 'Refunds',
                'term_taxonomy_id' => 12,
            )
        );
        $other_desc = $this->term_row(
            array(
                'term_id'          => 13,
                'slug'             => 'billing-4',
                'description'      => 'Something else.',
                'term_taxonomy_id' => 13,
            )
        );
        $other_tax  = $this->term_row(
            array(
                'term_id'          => 14,
                'slug'             => 'billing-5',
                'taxonomy'         => Licensio_KB_Post_Types::TAXONOMY_TAG,
                'term_taxonomy_id' => 14,
            )
        );

        $groups = $this->duplicates->group_terms( array( $original, $twin, $other_name, $other_desc, $other_tax ) );

        $this->assertCount( 1, $groups, 'Only the true twin pair may group.' );

        $members = reset( $groups );

        $this->assertSame( array( 10, 11 ), array_column( $members, 'term_id' ) );
    }

    /**
     * A term group picks its un-suffixed member and its richest member.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_term_group_picks_its_canonical_and_winner() {
        $group = $this->duplicates->describe_term_group(
            array(
                $this->term_row(
                    array(
                        'term_id'          => 10,
                        'term_taxonomy_id' => 100,
                        'count'            => 0,
                    )
                ),
                $this->term_row(
                    array(
                        'term_id'          => 11,
                        'slug'             => 'billing-2',
                        'term_taxonomy_id' => 101,
                        'count'            => 5,
                    )
                ),
            ),
            array(
                10 => 0,
                11 => 3,
            )
        );

        $this->assertTrue( $group['fixable'] );
        $this->assertSame( 10, $group['canonical'], 'The member without a suffix survives.' );
        $this->assertSame( 100, $group['canonicalTermTaxonomyId'] );
        $this->assertSame( 11, $group['winner'], 'The member with the most meta provides it.' );
    }

    /**
     * A term group whose every slug is suffixed has nothing to merge into.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_term_group_without_an_unsuffixed_member_is_flagged() {
        $group = $this->duplicates->describe_term_group(
            array(
                $this->term_row(
                    array(
                        'term_id'          => 10,
                        'slug'             => 'billing-2',
                        'term_taxonomy_id' => 100,
                    )
                ),
                $this->term_row(
                    array(
                        'term_id'          => 11,
                        'slug'             => 'billing-3',
                        'term_taxonomy_id' => 101,
                    )
                ),
            ),
            array()
        );

        $this->assertFalse( $group['fixable'] );
        $this->assertSame( 0, $group['canonical'] );
    }

    /**
     * A group whose every slug is suffixed has nothing to merge into.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_group_without_an_unsuffixed_member_is_flagged() {
        $group = $this->duplicates->describe_group(
            array(
                $this->row(
                    array(
                        'ID'        => 10,
                        'post_name' => 'email-logs-2',
                    )
                ),
                $this->row(
                    array(
                        'ID'        => 11,
                        'post_name' => 'email-logs-3',
                    )
                ),
            ),
            array(
                10 => 0,
                11 => 1,
            )
        );

        $this->assertFalse( $group['fixable'] );
        $this->assertSame( 0, $group['canonical'] );
        $this->assertSame( 'flagged', $group['kind'] );
    }

    /**
     * A migration source post beside the article it became is residue to
     * clean up, not a duplicate to merge.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_migration_source_beside_its_article_is_a_source_group() {
        $group = $this->duplicates->describe_group(
            array(
                $this->row(
                    array(
                        'ID'        => 515,
                        'post_name' => 'heroic-article-1',
                    )
                ),
                $this->row(
                    array(
                        'ID'        => 6,
                        'post_name' => 'heroic-article-1',
                        'post_type' => 'ht_kb',
                    )
                ),
            ),
            array(
                515 => 3,
                6   => 2,
            )
        );

        $this->assertSame( 'source', $group['kind'] );
        $this->assertTrue( $group['fixable'], 'The fix trashes the source post; the article stays as it is.' );
        $this->assertSame( 515, $group['canonical'], 'The knowledge base article is the replacement.' );
    }

    /**
     * Call one of the detector's private methods.
     *
     * The fix paths are private because only `fix()` uses them, but `fix()`
     * starts with a database scan, and what they hand to core is worth proving
     * on its own.
     *
     * @since 1.0.0
     *
     * @param string       $name The method name.
     * @param array<mixed> $args The arguments.
     *
     * @return mixed What the method returned.
     */
    private function call_private( $name, array $args ) {
        $method = new ReflectionMethod( 'Licensio_KB_Duplicates', $name );

        $method->setAccessible( true );

        return $method->invokeArgs( $this->duplicates, $args );
    }

    /**
     * Copying the winner onto the canonical post keeps every backslash.
     *
     * The stored content and meta are raw, and `wp_update_post()` and
     * `update_post_meta()` unslash what they get, so block escapes such as
     * `\u003c` and JSON `\/` escapes would otherwise be destroyed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_copying_a_post_keeps_every_backslash() {
        $content = '<!-- wp:paragraph {"placeholder":"\u003cb\u003e"} --><p>C:\Temp</p><!-- /wp:paragraph -->';
        $json    = '{"url":"https:\/\/example.test\/"}';
        $updated = array();
        $meta    = array();

        Brain\Monkey\Functions\when( 'get_post' )->justReturn(
            new WP_Post(
                array(
                    'ID'           => 11,
                    'post_title'   => 'Paths C:\Temp',
                    'post_content' => $content,
                )
            )
        );
        Brain\Monkey\Functions\when( 'wp_update_post' )->alias(
            function ( $post ) use ( &$updated ) {
                $updated = self::core_unslash( $post );

                return (int) $post['ID'];
            }
        );
        Brain\Monkey\Functions\when( 'get_post_meta' )->justReturn(
            array(
                'licensio_links' => array( $json ),
                'licensio_list'  => array( serialize( array( 'C:\Temp' ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Meta is stored serialized.
            )
        );
        Brain\Monkey\Functions\when( 'maybe_unserialize' )->alias(
            function ( $value ) {
                return is_string( $value ) && 0 === strpos( $value, 'a:' ) ? unserialize( $value ) : $value; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Stand-in for core.
            }
        );
        Brain\Monkey\Functions\when( 'update_post_meta' )->alias(
            function ( $post_id, $key, $value ) use ( &$meta ) {
                $meta[ $key ] = self::core_unslash( $value );

                return true;
            }
        );

        $this->call_private( 'copy_content', array( 11, 10 ) );

        $this->assertSame( 10, $updated['ID'] );
        $this->assertSame( $content, $updated['post_content'] );
        $this->assertSame( 'Paths C:\Temp', $updated['post_title'] );
        $this->assertSame( $json, $meta['licensio_links'] );
        $this->assertSame( array( 'C:\Temp' ), $meta['licensio_list'] );
    }

    /**
     * Merging a term group keeps every backslash in the copied term meta.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_merging_terms_keeps_every_backslash_in_the_meta() {
        $svg  = '<svg viewBox="0 0 24 24"><title>C:\Temp</title></svg>';
        $meta = array();

        Brain\Monkey\Functions\when( 'get_term_meta' )->justReturn( array( 'licensio_icon_svg' => array( $svg ) ) );
        Brain\Monkey\Functions\when( 'maybe_unserialize' )->returnArg();
        Brain\Monkey\Functions\when( 'update_term_meta' )->alias(
            function ( $term_id, $key, $value ) use ( &$meta ) {
                $meta[ $term_id ][ $key ] = self::core_unslash( $value );

                return true;
            }
        );

        $this->call_private(
            'fix_term_group',
            array(
                array(
                    'canonical' => 5,
                    'winner'    => 6,
                    'taxonomy'  => Licensio_KB_Post_Types::TAXONOMY_CATEGORY,
                    'name'      => 'Paths',
                    'members'   => array( array( 'id' => 5 ) ),
                ),
            )
        );

        $this->assertSame( $svg, $meta[5]['licensio_icon_svg'] );
    }
}
