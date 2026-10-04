<?php
/**
 * The BetterDocs reader: knowledge-base folding, article order and old URLs.
 *
 * The reads that touch the database live in the shared base class and are
 * covered by the integration suite. Everything here runs on a double that
 * answers `terms()`, `post_terms()` and `term_meta()` from arrays, so no
 * `$wpdb` is involved.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

/**
 * A BetterDocs reader whose database reads come from arrays.
 *
 * @since 1.0.0
 */
class Lisensio_KB_BetterDocs_Import_Source_Double extends Lisensio_KB_BetterDocs_Import_Source {

    /**
     * Term rows by taxonomy.
     *
     * @since 1.0.0
     * @var array<string, array<int, array<string, mixed>>>
     */
    public $term_rows = array();

    /**
     * Term IDs by post ID, then taxonomy.
     *
     * @since 1.0.0
     * @var array<int, array<string, int[]>>
     */
    public $post_term_ids = array();

    /**
     * Term meta by term ID.
     *
     * @since 1.0.0
     * @var array<int, array<string, string>>
     */
    public $meta = array();

    /**
     * How many times terms() was called.
     *
     * @since 1.0.0
     * @var int
     */
    public $terms_calls = 0;

    /**
     * The term rows of a taxonomy.
     *
     * @since 1.0.0
     *
     * @param string $taxonomy The source taxonomy.
     *
     * @return array<int, array<string, mixed>> The rows.
     */
    public function terms( $taxonomy ) {
        ++$this->terms_calls;

        return isset( $this->term_rows[ $taxonomy ] ) ? $this->term_rows[ $taxonomy ] : array();
    }

    /**
     * The term IDs a post is filed under.
     *
     * @since 1.0.0
     *
     * @param int    $post_id  The post ID.
     * @param string $taxonomy The source taxonomy.
     *
     * @return int[] The term IDs.
     */
    public function post_terms( $post_id, $taxonomy ) {
        return isset( $this->post_term_ids[ $post_id ][ $taxonomy ] ) ? $this->post_term_ids[ $post_id ][ $taxonomy ] : array();
    }

    /**
     * One term's meta.
     *
     * @since 1.0.0
     *
     * @param int $term_id The term ID.
     *
     * @return array<string, string> The meta.
     */
    public function term_meta( $term_id ) {
        return isset( $this->meta[ $term_id ] ) ? $this->meta[ $term_id ] : array();
    }
}

/**
 * What the BetterDocs reader does with BetterDocs' structure.
 *
 * @since 1.0.0
 */
class Lisensio_KB_BetterDocs_Import_Source_Test extends Lisensio_KB_Test_Case {

    /**
     * Supply `home_url()` and the maybe-unserialize the knowledge-base meta needs.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        Brain\Monkey\Functions\when( 'home_url' )->alias(
            function ( $path = '' ) {
                return 'https://example.test' . $path;
            }
        );

        Brain\Monkey\Functions\when( 'remove_accents' )->alias(
            function ( $value ) {
                return strtr(
                    (string) $value,
                    array(
                        'É' => 'E',
                        'é' => 'e',
                    )
                );
            }
        );

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
     * A term row as the shared read returns it.
     *
     * @since 1.0.0
     *
     * @param int                  $term_id   The term ID.
     * @param string               $slug      The slug.
     * @param array<string, mixed> $overrides Values to replace.
     *
     * @return array<string, mixed> The row.
     */
    private function term( $term_id, $slug, array $overrides = array() ) {
        return array_merge(
            array(
                'term_id'  => $term_id,
                'name'     => ucfirst( $slug ),
                'slug'     => $slug,
                'taxonomy' => Lisensio_KB_BetterDocs_Import_Schema::TAXONOMY_CATEGORY,
                'parent'   => 0,
                'count'    => 1,
            ),
            $overrides
        );
    }

    /**
     * A knowledge base term row.
     *
     * @since 1.0.0
     *
     * @param int    $term_id The term ID.
     * @param string $slug    The slug.
     *
     * @return array<string, mixed> The row.
     */
    private function base( $term_id, $slug ) {
        return $this->term( $term_id, $slug, array( 'taxonomy' => Lisensio_KB_BetterDocs_Import_Schema::TAXONOMY_KNOWLEDGE_BASE ) );
    }

    /**
     * The serialised knowledge-base meta value for a list of slugs.
     *
     * @since 1.0.0
     *
     * @param string[] $slugs The knowledge base slugs.
     *
     * @return string The stored value.
     */
    private function kb_meta( array $slugs ) {
        return serialize( $slugs ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
    }

    /**
     * A reader holding a small tree with two knowledge bases.
     *
     * Terms: knowledge bases 100 (faq) and 101 (guides); categories 1 alpha in
     * faq, 2 beta in guides and faq, 3 gamma under alpha but tagged guides, 4
     * delta with no knowledge base, 5 epsilon in an unknown knowledge base.
     *
     * @since 1.0.0
     *
     * @return Lisensio_KB_BetterDocs_Import_Source_Double The reader.
     */
    private function reader_with_knowledge_bases() {
        $source = new Lisensio_KB_BetterDocs_Import_Source_Double();

        $source->term_rows = array(
            Lisensio_KB_BetterDocs_Import_Schema::TAXONOMY_KNOWLEDGE_BASE => array(
                $this->base( 100, 'faq' ),
                $this->base( 101, 'guides' ),
            ),
            Lisensio_KB_BetterDocs_Import_Schema::TAXONOMY_CATEGORY => array(
                $this->term( 1, 'alpha' ),
                $this->term( 2, 'beta' ),
                $this->term( 3, 'gamma', array( 'parent' => 1 ) ),
                $this->term( 4, 'delta' ),
                $this->term( 5, 'epsilon' ),
            ),
        );

        $source->meta = array(
            1 => array( 'doc_category_knowledge_base' => $this->kb_meta( array( 'faq' ) ) ),
            2 => array( 'doc_category_knowledge_base' => $this->kb_meta( array( 'guides', 'faq' ) ) ),
            3 => array( 'doc_category_knowledge_base' => $this->kb_meta( array( 'guides' ) ) ),
            5 => array( 'doc_category_knowledge_base' => $this->kb_meta( array( 'nonexistent' ) ) ),
        );

        return $source;
    }

    /**
     * The rows of a folded tree by term ID.
     *
     * @since 1.0.0
     *
     * @param array<int, array<string, mixed>> $rows The folded rows.
     *
     * @return array<int, array<string, mixed>> The rows keyed by term ID.
     */
    private function by_id( array $rows ) {
        $keyed = array();

        foreach ( $rows as $row ) {
            $keyed[ (int) $row['term_id'] ] = $row;
        }

        return $keyed;
    }

    /**
     * Set BetterDocs' stored settings.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $settings The settings.
     *
     * @return void
     */
    private function settings( array $settings ) {
        $this->options[ Lisensio_KB_BetterDocs_Import_Schema::OPTION_SETTINGS ] = $settings;
    }

    /**
     * The reader names itself and its post type and taxonomies.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_reader_names_betterdocs_data() {
        $source = new Lisensio_KB_BetterDocs_Import_Source();

        $this->assertSame( 'betterdocs', $source->id() );
        $this->assertSame( 'BetterDocs', $source->label() );
        $this->assertSame( 'docs', $source->post_type() );
        $this->assertSame( 'doc_category', $source->category_taxonomy() );
        $this->assertSame( 'doc_tag', $source->tag_taxonomy() );
        $this->assertSame( '_lisensio_betterdocs_post_id', $source->provenance_post_key() );
        $this->assertSame( '_lisensio_betterdocs_term_id', $source->provenance_term_key() );
    }

    /**
     * Without knowledge bases the categories pass through untouched.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_folding_with_no_knowledge_bases_returns_the_categories_unchanged() {
        $source     = new Lisensio_KB_BetterDocs_Import_Source_Double();
        $categories = array(
            $this->term( 2, 'child', array( 'parent' => 1 ) ),
            $this->term( 1, 'root' ),
        );

        $this->assertSame( $categories, $source->fold_knowledge_bases( array(), $categories ) );
    }

    /**
     * Each knowledge base becomes a top-level knowledge_base item.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_knowledge_bases_become_root_knowledge_base_items() {
        $source = $this->reader_with_knowledge_bases();
        $rows   = $this->by_id( $source->import_terms( 'doc_category' ) );

        foreach ( array( 100, 101 ) as $id ) {
            $this->assertSame( 'knowledge_base', $rows[ $id ]['item_type'] );
            $this->assertSame( 0, $rows[ $id ]['parent'] );
        }

        $this->assertArrayNotHasKey( 'item_type', $rows[1], 'A category is not tagged as a knowledge base.' );
    }

    /**
     * A top-level category with knowledge-base meta is filed under the first matching base.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_top_level_category_is_filed_under_its_knowledge_base() {
        $source = $this->reader_with_knowledge_bases();
        $rows   = $this->by_id( $source->import_terms( 'doc_category' ) );

        $this->assertSame( 100, $rows[1]['parent'], 'Alpha belongs to faq.' );
        $this->assertArrayNotHasKey( 'import_note', $rows[1] );
    }

    /**
     * A category in two knowledge bases goes under the first and says so.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_category_in_two_knowledge_bases_is_filed_under_the_first_with_a_note() {
        $source = $this->reader_with_knowledge_bases();
        $rows   = $this->by_id( $source->import_terms( 'doc_category' ) );

        $this->assertSame( 101, $rows[2]['parent'], 'Beta lists guides before faq.' );
        $this->assertSame( 'Belonged to 2 knowledge bases (guides, faq); filed under the first, "guides".', $rows[2]['import_note'] );
    }

    /**
     * A child category keeps its parent, whatever knowledge-base meta it carries.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_child_category_keeps_its_parent() {
        $source = $this->reader_with_knowledge_bases();
        $rows   = $this->by_id( $source->import_terms( 'doc_category' ) );

        $this->assertSame( 1, $rows[3]['parent'] );
        $this->assertArrayNotHasKey( 'import_note', $rows[3] );
    }

    /**
     * Categories with no or unknown knowledge base stay at the root.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_categories_without_a_matching_knowledge_base_stay_at_the_root() {
        $source = $this->reader_with_knowledge_bases();
        $rows   = $this->by_id( $source->import_terms( 'doc_category' ) );

        $this->assertSame( 0, $rows[4]['parent'], 'Delta has no knowledge base.' );
        $this->assertSame( 0, $rows[5]['parent'], 'Epsilon names a knowledge base that does not exist.' );
    }

    /**
     * A category whose parent is missing is treated as top level and filed under its base.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_category_with_a_missing_parent_is_filed_under_its_knowledge_base() {
        $source = new Lisensio_KB_BetterDocs_Import_Source_Double();

        $source->meta = array( 7 => array( 'doc_category_knowledge_base' => $this->kb_meta( array( 'faq' ) ) ) );

        $rows = $this->by_id( $source->fold_knowledge_bases( array( $this->base( 100, 'faq' ) ), array( $this->term( 7, 'orphan', array( 'parent' => 999 ) ) ) ) );

        $this->assertSame( 100, $rows[7]['parent'] );
    }

    /**
     * Parents always come before their children.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_folded_tree_lists_parents_first() {
        $source = $this->reader_with_knowledge_bases();
        $ids    = array_map( 'intval', array_column( $source->import_terms( 'doc_category' ), 'term_id' ) );

        $this->assertSame( array( 100, 1, 3, 101, 2, 4, 5 ), $ids );
        $this->assertLessThan( array_search( 3, $ids, true ), array_search( 1, $ids, true ), 'Alpha precedes its child gamma.' );
        $this->assertLessThan( array_search( 1, $ids, true ), array_search( 100, $ids, true ), 'A knowledge base precedes its categories.' );
    }

    /**
     * Only the category taxonomy is folded; others are read as they are.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_import_terms_leaves_other_taxonomies_alone() {
        $source = $this->reader_with_knowledge_bases();
        $tags   = array( $this->term( 40, 'tag-one', array( 'taxonomy' => 'doc_tag' ) ) );

        $source->term_rows['doc_tag'] = $tags;

        $this->assertSame( $tags, $source->import_terms( 'doc_tag' ) );
    }

    /**
     * The folded categories are built once per reader.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_folded_categories_are_cached() {
        $source = $this->reader_with_knowledge_bases();

        $first = $source->import_terms( 'doc_category' );
        $calls = $source->terms_calls;

        $this->assertSame( $first, $source->import_terms( 'doc_category' ) );
        $this->assertSame( $calls, $source->terms_calls, 'The second call must not read the terms again.' );
    }

    /**
     * The category meta mapping is the map's.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_map_category_meta_delegates_to_the_map() {
        $source = new Lisensio_KB_BetterDocs_Import_Source_Double();

        $this->assertSame(
            array(
                'meta'     => array( Lisensio_KB_Term_Meta::META_ORDER => 4 ),
                'unmapped' => array( 'colour' ),
            ),
            $source->map_category_meta(
                array(
                    'doc_category_order' => '4',
                    'colour'             => 'red',
                )
            )
        );
    }

    /**
     * The lowest-ID category listing the article decides its order.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_article_order_takes_the_lowest_category_and_flags_a_disagreement() {
        $source = new Lisensio_KB_BetterDocs_Import_Source_Double();

        $source->post_term_ids = array( 30 => array( 'doc_category' => array( 5, 4 ) ) );
        $source->meta          = array(
            4 => array( '_docs_order' => '10,20,30' ),
            5 => array( '_docs_order' => '30,31' ),
        );

        $this->assertSame(
            array(
                'order'          => 2,
                'ambiguous'      => true,
                'source_term_id' => 4,
            ),
            $source->article_order( 30 )
        );
    }

    /**
     * An article no list names has order zero.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_article_order_is_zero_when_no_list_names_the_article() {
        $source = new Lisensio_KB_BetterDocs_Import_Source_Double();

        $source->post_term_ids = array( 30 => array( 'doc_category' => array( 4 ) ) );
        $source->meta          = array( 4 => array( '_docs_order' => '10,20' ) );

        $this->assertSame(
            array(
                'order'          => 0,
                'ambiguous'      => false,
                'source_term_id' => 0,
            ),
            $source->article_order( 30 )
        );
    }

    /**
     * Post meta is classified for the log.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_classify_post_meta() {
        $source = new Lisensio_KB_BetterDocs_Import_Source_Double();

        $this->assertSame( 'migrated', $source->classify_post_meta( '_thumbnail_id' )['action'] );
        $this->assertSame( 'derived', $source->classify_post_meta( '_betterdocs_meta_views' )['action'] );
        $this->assertSame( 'skipped', $source->classify_post_meta( '_edit_lock' )['action'] );
        $this->assertSame( 'not_migrated', $source->classify_post_meta( '_betterdocs_something' )['action'] );
    }

    /**
     * Term meta is classified for the log.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_classify_term_meta() {
        $source = new Lisensio_KB_BetterDocs_Import_Source_Double();

        foreach ( array( 'doc_category_order', 'kb_order', 'doc_category_image-id', 'doc_category_thumb-id', 'doc_category_knowledge_base', '_docs_order' ) as $key ) {
            $this->assertSame( 'migrated', $source->classify_term_meta( $key )['action'], $key );
        }

        $this->assertSame( Lisensio_KB_Term_Meta::META_ORDER, $source->classify_term_meta( 'kb_order' )['target'] );
        $this->assertSame( 'not_migrated', $source->classify_term_meta( 'anything_else' )['action'] );
    }

    /**
     * With default settings an article lives under the docs base.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_old_article_url_uses_the_default_docs_base() {
        $source = new Lisensio_KB_BetterDocs_Import_Source_Double();

        $this->assertSame(
            'https://example.test/docs/my-article/',
            $source->old_article_url(
                array(
                    'ID'        => 30,
                    'post_name' => 'my-article',
                )
            )
        );
    }

    /**
     * An article with no slug has no old URL.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_old_article_url_is_empty_without_a_slug() {
        $source = new Lisensio_KB_BetterDocs_Import_Source_Double();

        $this->assertSame(
            '',
            $source->old_article_url(
                array(
                    'ID'        => 30,
                    'post_name' => '',
                )
            )
        );
        $this->assertSame( '', $source->old_article_url( array( 'ID' => 30 ) ) );
    }

    /**
     * A structure starting with a placeholder is put under the docs base.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_old_article_url_expands_the_category_placeholder_under_the_docs_base() {
        $this->settings(
            array(
                'permalink_structure' => '%doc_category%',
                'docs_slug'           => 'help',
            )
        );

        $source = new Lisensio_KB_BetterDocs_Import_Source_Double();

        $source->term_rows     = array( 'doc_category' => array( $this->term( 4, 'getting-started' ) ) );
        $source->post_term_ids = array( 30 => array( 'doc_category' => array( 4 ) ) );

        $this->assertSame(
            'https://example.test/help/getting-started/my-article/',
            $source->old_article_url(
                array(
                    'ID'        => 30,
                    'post_name' => 'my-article',
                )
            )
        );
    }

    /**
     * A structure naming its own base keeps it, slashes and all trimmed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_old_article_url_keeps_an_explicit_base() {
        $this->settings( array( 'permalink_structure' => '/support/%doc_category%/' ) );

        $source = new Lisensio_KB_BetterDocs_Import_Source_Double();

        $source->term_rows     = array( 'doc_category' => array( $this->term( 4, 'billing' ) ) );
        $source->post_term_ids = array( 30 => array( 'doc_category' => array( 4 ) ) );

        $this->assertSame(
            'https://example.test/support/billing/my-article/',
            $source->old_article_url(
                array(
                    'ID'        => 30,
                    'post_name' => 'my-article',
                )
            )
        );
    }

    /**
     * An uncategorised article falls back to the uncategorized segment.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_old_article_url_uses_uncategorized_when_there_is_no_category() {
        $this->settings( array( 'permalink_structure' => 'docs/%doc_category%' ) );

        $source = new Lisensio_KB_BetterDocs_Import_Source_Double();

        $this->assertSame(
            'https://example.test/docs/uncategorized/my-article/',
            $source->old_article_url(
                array(
                    'ID'        => 30,
                    'post_name' => 'my-article',
                )
            )
        );
    }

    /**
     * Of several categories, the first by name is the one in the URL.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_old_article_url_picks_the_first_category_by_name() {
        $this->settings( array( 'permalink_structure' => 'docs/%doc_category%' ) );

        $source = new Lisensio_KB_BetterDocs_Import_Source_Double();

        $source->term_rows     = array(
            'doc_category' => array(
                $this->term( 4, 'zulu', array( 'name' => 'Zulu' ) ),
                $this->term( 9, 'alpha', array( 'name' => 'Alpha' ) ),
            ),
        );
        $source->post_term_ids = array( 30 => array( 'doc_category' => array( 4, 9 ) ) );

        $this->assertSame(
            'https://example.test/docs/alpha/my-article/',
            $source->old_article_url(
                array(
                    'ID'        => 30,
                    'post_name' => 'my-article',
                )
            )
        );
    }

    /**
     * Case and accents do not change which category comes first, as in a `_ci` collation.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_old_article_url_orders_categories_ignoring_case_and_accents() {
        $this->settings( array( 'permalink_structure' => 'docs/%doc_category%' ) );

        $source = new Lisensio_KB_BetterDocs_Import_Source_Double();

        $source->term_rows     = array(
            'doc_category' => array(
                $this->term( 4, 'zones', array( 'name' => 'Zones' ) ),
                $this->term( 9, 'etiquettes', array( 'name' => 'Étiquettes' ) ),
                $this->term( 7, 'billing', array( 'name' => 'billing' ) ),
            ),
        );
        $source->post_term_ids = array( 30 => array( 'doc_category' => array( 4, 9 ) ) );

        $this->assertSame(
            'https://example.test/docs/etiquettes/my-article/',
            $source->old_article_url(
                array(
                    'ID'        => 30,
                    'post_name' => 'my-article',
                )
            )
        );

        $source->post_term_ids = array( 30 => array( 'doc_category' => array( 4, 7 ) ) );

        $this->assertSame(
            'https://example.test/docs/billing/my-article/',
            $source->old_article_url(
                array(
                    'ID'        => 30,
                    'post_name' => 'my-article',
                )
            )
        );
    }

    /**
     * The knowledge-base placeholder takes the category's first knowledge base.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_old_article_url_expands_the_knowledge_base_placeholder() {
        $this->settings( array( 'permalink_structure' => 'docs/%knowledge_base%/%doc_category%' ) );

        $source = new Lisensio_KB_BetterDocs_Import_Source_Double();

        $source->term_rows     = array( 'doc_category' => array( $this->term( 4, 'billing' ) ) );
        $source->post_term_ids = array( 30 => array( 'doc_category' => array( 4 ) ) );
        $source->meta          = array( 4 => array( 'doc_category_knowledge_base' => $this->kb_meta( array( 'faq', 'guides' ) ) ) );

        $this->assertSame(
            'https://example.test/docs/faq/billing/my-article/',
            $source->old_article_url(
                array(
                    'ID'        => 30,
                    'post_name' => 'my-article',
                )
            )
        );
    }

    /**
     * The knowledge-base placeholder is dropped, with its slash, when there is none.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_old_article_url_drops_the_knowledge_base_placeholder_when_there_is_none() {
        $this->settings( array( 'permalink_structure' => 'docs/%knowledge_base%/%doc_category%' ) );

        $source = new Lisensio_KB_BetterDocs_Import_Source_Double();

        $source->term_rows     = array( 'doc_category' => array( $this->term( 4, 'billing' ) ) );
        $source->post_term_ids = array( 30 => array( 'doc_category' => array( 4 ) ) );

        $this->assertSame(
            'https://example.test/docs/billing/my-article/',
            $source->old_article_url(
                array(
                    'ID'        => 30,
                    'post_name' => 'my-article',
                )
            )
        );
    }

    /**
     * With hierarchical slugs on, the category segment is the whole parent chain.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_old_article_url_uses_the_parent_chain_when_hierarchical_slugs_are_on() {
        $this->settings(
            array(
                'permalink_structure'             => 'docs/%doc_category%',
                'enable_category_hierarchy_slugs' => true,
            )
        );

        $source = new Lisensio_KB_BetterDocs_Import_Source_Double();

        $source->term_rows     = array(
            'doc_category' => array(
                $this->term( 1, 'top' ),
                $this->term( 2, 'middle', array( 'parent' => 1 ) ),
                $this->term( 3, 'leaf', array( 'parent' => 2 ) ),
            ),
        );
        $source->post_term_ids = array( 30 => array( 'doc_category' => array( 3 ) ) );

        $this->assertSame(
            'https://example.test/docs/top/middle/leaf/my-article/',
            $source->old_article_url(
                array(
                    'ID'        => 30,
                    'post_name' => 'my-article',
                )
            )
        );
    }

    /**
     * A category archive lives under the default category base.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_old_term_url_uses_the_default_category_base() {
        $source = new Lisensio_KB_BetterDocs_Import_Source_Double();
        $row    = $this->term( 4, 'billing' );

        $source->term_rows = array( 'doc_category' => array( $row ) );

        $this->assertSame( 'https://example.test/docs-category/billing/', $source->old_term_url( $row ) );
    }

    /**
     * A tag archive lives under the default tag base.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_old_term_url_uses_the_default_tag_base() {
        $source = new Lisensio_KB_BetterDocs_Import_Source_Double();

        $this->assertSame( 'https://example.test/docs-tag/urgent/', $source->old_term_url( $this->term( 8, 'urgent', array( 'taxonomy' => 'doc_tag' ) ) ) );
    }

    /**
     * The configured category and tag bases replace the defaults.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_old_term_url_uses_the_configured_bases() {
        $this->settings(
            array(
                'category_slug' => '/topics/',
                'tag_slug'      => 'labels',
            )
        );

        $source = new Lisensio_KB_BetterDocs_Import_Source_Double();
        $row    = $this->term( 4, 'billing' );

        $source->term_rows = array( 'doc_category' => array( $row ) );

        $this->assertSame( 'https://example.test/topics/billing/', $source->old_term_url( $row ) );
        $this->assertSame( 'https://example.test/labels/urgent/', $source->old_term_url( $this->term( 8, 'urgent', array( 'taxonomy' => 'doc_tag' ) ) ) );
    }

    /**
     * A knowledge base archive lives at the docs base, not the category base.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_old_term_url_serves_a_knowledge_base_at_the_docs_base() {
        $this->settings( array( 'docs_slug' => 'help' ) );

        $source = new Lisensio_KB_BetterDocs_Import_Source_Double();

        $this->assertSame( 'https://example.test/help/faq/', $source->old_term_url( $this->base( 100, 'faq' ) ) );
    }

    /**
     * A category archive uses the parent chain when hierarchical slugs are on.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_old_term_url_uses_the_parent_chain_when_hierarchical_slugs_are_on() {
        $this->settings( array( 'enable_category_hierarchy_slugs' => true ) );

        $source = new Lisensio_KB_BetterDocs_Import_Source_Double();
        $leaf   = $this->term( 2, 'leaf', array( 'parent' => 1 ) );

        $source->term_rows = array( 'doc_category' => array( $this->term( 1, 'top' ), $leaf ) );

        $this->assertSame( 'https://example.test/docs-category/top/leaf/', $source->old_term_url( $leaf ) );
    }

    /**
     * A parent cycle in the data cannot loop the URL builder forever.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_old_term_url_survives_a_parent_cycle() {
        $this->settings( array( 'enable_category_hierarchy_slugs' => true ) );

        $source = new Lisensio_KB_BetterDocs_Import_Source_Double();
        $one    = $this->term( 1, 'one', array( 'parent' => 2 ) );
        $two    = $this->term( 2, 'two', array( 'parent' => 1 ) );

        $source->term_rows = array( 'doc_category' => array( $one, $two ) );

        $this->assertSame( 'https://example.test/docs-category/two/one/', $source->old_term_url( $one ) );
    }

    /**
     * A term with no slug has no old URL.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_old_term_url_is_empty_without_a_slug() {
        $source = new Lisensio_KB_BetterDocs_Import_Source_Double();

        $this->assertSame( '', $source->old_term_url( $this->term( 4, '' ) ) );
    }
}
