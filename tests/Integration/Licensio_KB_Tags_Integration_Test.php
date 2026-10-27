<?php
/**
 * The tag routes against a live database.
 *
 * The list query is the controller's own SQL, so it runs here exactly as it
 * runs in production. The write routes call core's term API, which is stood
 * in by Licensio_KB_Live_Term_API over the same tables, so these tests prove
 * which assignments and terms are left once a merge, convert or bulk delete
 * has run.
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
require_once __DIR__ . '/trait-licensio-kb-live-term-api.php';

/**
 * Exercises Licensio_KB_Tags_REST_Controller against a live server.
 *
 * @since 1.0.0
 */
class Licensio_KB_Tags_Integration_Test extends Licensio_KB_Analytics_DB_Test_Case {

    use Licensio_KB_Content_Fixtures;
    use Licensio_KB_Live_Term_API;

    /**
     * The controller under test.
     *
     * @since 1.0.0
     * @var Licensio_KB_Tags_REST_Controller
     */
    private $controller;

    /**
     * Add the core tables and stand in the term API.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->install_content_schemas();
        $this->stub_category_term_api();

        // The article checks a term move makes: an editor, holding no locks.
        Brain\Monkey\Functions\when( 'get_post_type_object' )->justReturn(
            (object) array(
                'cap' => (object) Licensio_KB_Capabilities::post_type_capabilities(),
            )
        );
        Brain\Monkey\Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Brain\Monkey\Functions\when( 'current_user_can' )->justReturn( true );

        Brain\Monkey\Functions\when( 'rest_sanitize_boolean' )->alias(
            function ( $value ) {
                return (bool) $value;
            }
        );

        $this->controller = new Licensio_KB_Tags_REST_Controller();
    }

    /**
     * List tags.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $params The query, over the route defaults.
     *
     * @return array<string, mixed> The answer.
     */
    private function list_tags( array $params = array() ) {
        $request = new WP_REST_Request(
            array(),
            '/licensio-kb/v1/tags',
            array_merge(
                array(
                    'search'   => '',
                    'unused'   => false,
                    'include'  => array(),
                    'orderby'  => 'name',
                    'order'    => 'asc',
                    'page'     => 1,
                    'per_page' => 20,
                ),
                $params
            )
        );

        $result = $this->controller->get_items( $request );

        $this->assertIsArray( $result );

        return $result;
    }

    /**
     * The names of the listed tags, in order.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $params The query.
     *
     * @return string[] The names.
     */
    private function names( array $params = array() ) {
        return array_column( $this->list_tags( $params )['items'], 'name' );
    }

    /**
     * The IDs of the articles with a term.
     *
     * @since 1.0.0
     *
     * @param int    $term_id  The term.
     * @param string $taxonomy The taxonomy.
     *
     * @return int[] The article IDs, lowest first.
     */
    private function articles_in( $term_id, $taxonomy ) {
        return array_map( 'intval', get_objects_in_term( $term_id, $taxonomy ) );
    }

    /**
     * How many terms a taxonomy holds.
     *
     * @since 1.0.0
     *
     * @param string $taxonomy The taxonomy.
     *
     * @return int The count.
     */
    private function term_count( $taxonomy ) {
        return $this->count_rows( 'term_taxonomy', "taxonomy = '" . $taxonomy . "'" );
    }

    /**
     * Seed four tags across three articles, one of them a draft.
     *
     * Published counts: Billing 2, Invoices 1, Q&A 0 (only on a draft), Unused 0.
     *
     * @since 1.0.0
     *
     * @return array<string, int> Tag name to ID.
     */
    private function seed_tags() {
        $tags = array(
            'Billing'  => $this->make_term( 'Billing' ),
            'Invoices' => $this->make_term( 'Invoices' ),
            'Q&A'      => $this->make_term( 'Q&amp;A' ),
            'Unused'   => $this->make_term( 'Unused' ),
        );

        $this->make_article( array( 'tags' => array( $tags['Billing'], $tags['Invoices'] ) ) );
        $this->make_article( array( 'tags' => array( $tags['Billing'] ) ) );
        $this->make_article(
            array(
                'status' => 'draft',
                'tags'   => array( $tags['Q&A'] ),
            )
        );

        return $tags;
    }

    /**
     * Search matches names and slugs; names come back decoded.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_search_matches_names_and_slugs() {
        $this->seed_tags();
        $this->make_term( 'Help', Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        $this->assertSame( array( 'Billing', 'Invoices', 'Q&A', 'Unused' ), $this->names(), 'Categories are never listed.' );
        $this->assertSame( array( 'Invoices' ), $this->names( array( 'search' => 'voice' ) ) );
        $this->assertSame( array( 'Q&A' ), $this->names( array( 'search' => 'q-amp' ) ), 'The slug is searched too.' );
        $this->assertSame( array(), $this->names( array( 'search' => '100%' ) ), 'A LIKE wildcard is searched literally.' );
    }

    /**
     * The count is published articles only; the Unused filter counts every status but trash and auto-draft.
     *
     * A tag only a draft, scheduled, pending or private article uses shows a
     * count of 0, as category counts do, but is not unused, so deleting the
     * Unused list never strips a tag from them.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_count_is_published_only_and_unused_counts_every_status_but_trash_and_auto_draft() {
        $tags = $this->seed_tags();

        foreach ( array( 'private', 'future', 'pending', 'trash', 'auto-draft' ) as $status ) {
            $tags[ $status ] = $this->make_term( 'Only ' . $status );

            $this->make_article(
                array(
                    'status' => $status,
                    'tags'   => array( $tags[ $status ] ),
                )
            );
        }

        $result = $this->list_tags( array( 'unused' => true ) );

        $this->assertSame( array( 'Only auto-draft', 'Only trash', 'Unused' ), array_column( $result['items'], 'name' ) );
        $this->assertSame( 3, $result['total'] );
        $this->assertSame( array( 0, 0, 0 ), array_column( $result['items'], 'count' ) );
        $this->assertSame(
            array(
                'Billing'         => 2,
                'Invoices'        => 1,
                'Only auto-draft' => 0,
                'Only future'     => 0,
                'Only pending'    => 0,
                'Only private'    => 0,
                'Only trash'      => 0,
                'Q&A'             => 0,
                'Unused'          => 0,
            ),
            array_column( $this->list_tags()['items'], 'count', 'name' ),
            'Only published articles are counted.'
        );
        $this->assertSame(
            array( 'Unused' ),
            $this->names(
                array(
                    'unused' => true,
                    'search' => 'unu',
                )
            ),
            'Filters combine.'
        );

        // Deleting everything the Unused filter lists leaves the draft's tag alone.
        $this->controller->bulk_delete(
            new WP_REST_Request(
                array(),
                '/licensio-kb/v1/tags/bulk-delete',
                array( 'ids' => array_column( $result['items'], 'id' ) )
            )
        );

        $this->assertCount( 1, $this->articles_in( $tags['Q&A'], Licensio_KB_Post_Types::TAXONOMY_TAG ), 'The draft keeps its tag.' );
        $this->assertCount( 1, $this->articles_in( $tags['private'], Licensio_KB_Post_Types::TAXONOMY_TAG ), 'The private article keeps its tag.' );
        $this->assertSame( array(), $this->list_tags( array( 'unused' => true ) )['items'] );
    }

    /**
     * A tag only a draft uses counts 0, and one published article counts 1.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_draft_only_tag_counts_zero_and_published_article_counts_one() {
        $draft     = $this->make_term( 'Draft only' );
        $published = $this->make_term( 'Published' );

        $this->make_article(
            array(
                'status' => 'draft',
                'tags'   => array( $draft ),
            )
        );
        $this->make_article( array( 'tags' => array( $published ) ) );

        $this->assertSame(
            array(
                'Draft only' => 0,
                'Published'  => 1,
            ),
            array_column( $this->list_tags()['items'], 'count', 'name' )
        );
        $this->assertSame( array(), $this->list_tags( array( 'unused' => true ) )['items'], 'A tag only a draft uses is not unused.' );
    }

    /**
     * Tags sort by name or count in either direction, and page.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_sorting_and_paging() {
        $tags = $this->seed_tags();

        $this->assertSame( array( 'Unused', 'Q&A', 'Invoices', 'Billing' ), $this->names( array( 'order' => 'desc' ) ) );
        $this->assertSame(
            array( 'Billing', 'Invoices', 'Q&A', 'Unused' ),
            $this->names(
                array(
                    'orderby' => 'count',
                    'order'   => 'desc',
                )
            ),
            'Equal counts fall back to the name.'
        );
        $this->assertSame( array( 'Q&A', 'Unused', 'Invoices', 'Billing' ), $this->names( array( 'orderby' => 'count' ) ), 'A tag only a draft uses sorts as 0.' );

        $page = $this->list_tags(
            array(
                'page'     => 2,
                'per_page' => 3,
            )
        );

        $this->assertSame( array( 'Unused' ), array_column( $page['items'], 'name' ) );
        $this->assertSame( 4, $page['total'] );
        $this->assertSame( 2, $page['pages'] );

        $this->assertSame( array( 'Billing', 'Unused' ), $this->names( array( 'include' => array( $tags['Unused'], $tags['Billing'] ) ) ) );

        $this->db->query( 'UPDATE ' . $this->table( 'term_taxonomy' ) . " SET description = 'Use &lt;b&gt; for bold' WHERE term_id = " . $tags['Unused'] );
        $this->assertSame( 'Use &lt;b&gt; for bold', $this->list_tags( array( 'include' => array( $tags['Unused'] ) ) )['items'][0]['description'], 'Descriptions are answered as stored.' );
        $this->assertSame(
            array(
                'id'          => $tags['Billing'],
                'name'        => 'Billing',
                'slug'        => 'billing',
                'description' => '',
                'count'       => 2,
            ),
            $this->list_tags()['items'][0]
        );
    }

    /**
     * Merging keeps every assignment on the target and deletes the sources.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_merge_keeps_assignments_on_the_target() {
        $a = $this->make_term( 'Alpha' );
        $b = $this->make_term( 'Beta' );
        $c = $this->make_term( 'Gamma' );

        $one   = $this->make_article( array( 'tags' => array( $a ) ) );
        $two   = $this->make_article( array( 'tags' => array( $b ) ) );
        $three = $this->make_article( array( 'tags' => array( $a, $c ) ) );

        $result = $this->controller->merge(
            new WP_REST_Request(
                array(),
                '/licensio-kb/v1/tags/merge',
                array(
                    'sources' => array( $a, $b ),
                    'target'  => $c,
                )
            )
        );

        $this->assertSame( array( $a, $b ), $result['deleted'] );
        $this->assertSame( array( $one, $two, $three ), $this->articles_in( $c, Licensio_KB_Post_Types::TAXONOMY_TAG ) );
        $this->assertSame( 3, $result['target']['count'] );
        $this->assertSame( array( 'Gamma' ), $this->names() );
        $this->assertSame( 3, $this->count_rows( 'term_relationships' ), 'Each article has the target once.' );
    }

    /**
     * Converting reuses the category with the tag's slug and keeps the articles.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_convert_reuses_the_category_with_the_same_slug() {
        $categories = $this->make_category_tree(
            array(
                'Help'    => array(),
                'Billing' => array(),
            )
        );
        $tag        = $this->make_term( 'Billing' );

        $one = $this->make_article( array( 'tags' => array( $tag ) ) );
        $two = $this->make_article(
            array(
                'tags'       => array( $tag ),
                'categories' => array( $categories['Help'] ),
            )
        );

        $result = $this->controller->convert(
            new WP_REST_Request(
                array(),
                '/licensio-kb/v1/tags/convert',
                array(
                    'ids'    => array( $tag ),
                    'parent' => $categories['Help'],
                )
            )
        );

        $this->assertSame( array(), $result['failed'] );
        $this->assertTrue( $result['converted'][0]['reused'] );
        $this->assertSame( $categories['Billing'], $result['converted'][0]['category']['id'] );
        $this->assertSame( 2, $this->term_count( Licensio_KB_Post_Types::TAXONOMY_CATEGORY ), 'No category is created.' );
        $this->assertSame( 0, get_term( $categories['Billing'], Licensio_KB_Post_Types::TAXONOMY_CATEGORY )->parent, 'The reused category is not moved.' );
        $this->assertSame( array( $one, $two ), $this->articles_in( $categories['Billing'], Licensio_KB_Post_Types::TAXONOMY_CATEGORY ) );
        $this->assertSame( array( $two ), $this->articles_in( $categories['Help'], Licensio_KB_Post_Types::TAXONOMY_CATEGORY ), 'Existing categories stay.' );
        $this->assertSame( 0, $this->term_count( Licensio_KB_Post_Types::TAXONOMY_TAG ), 'The tag is deleted.' );
    }

    /**
     * Converting reuses the category of the same name under the parent.
     *
     * Core lets a second category of one name in under the same parent when
     * its slug differs, so without the name lookup the convert would add a
     * duplicate. The names are stored entity encoded and still match.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_convert_reuses_a_same_name_category_under_the_parent() {
        $help    = $this->make_term( 'Help', Licensio_KB_Post_Types::TAXONOMY_CATEGORY );
        $sibling = $this->make_term( 'Q&amp;A', Licensio_KB_Post_Types::TAXONOMY_CATEGORY, $help );
        $other   = $this->make_term( 'Q&amp;A', Licensio_KB_Post_Types::TAXONOMY_CATEGORY );
        $tag     = $this->make_term( 'Q&amp;A' );

        $this->db->query( 'UPDATE ' . $this->table( 'terms' ) . " SET slug = 'q-a-help' WHERE term_id = " . $sibling );
        $this->db->query( 'UPDATE ' . $this->table( 'terms' ) . " SET slug = 'q-a-top' WHERE term_id = " . $other );

        $article = $this->make_article( array( 'tags' => array( $tag ) ) );

        $result = $this->controller->convert(
            new WP_REST_Request(
                array(),
                '/licensio-kb/v1/tags/convert',
                array(
                    'ids'    => array( $tag ),
                    'parent' => $help,
                )
            )
        );

        $this->assertSame( array(), $result['failed'] );
        $this->assertTrue( $result['converted'][0]['reused'] );
        $this->assertSame( $sibling, $result['converted'][0]['category']['id'], 'The child of the chosen parent is reused, not the top-level namesake.' );
        $this->assertSame( 'Q&A', $result['converted'][0]['category']['name'] );
        $this->assertSame( 3, $this->term_count( Licensio_KB_Post_Types::TAXONOMY_CATEGORY ), 'No category is created.' );
        $this->assertSame( array( $article ), $this->articles_in( $sibling, Licensio_KB_Post_Types::TAXONOMY_CATEGORY ) );
        $this->assertSame( 0, $this->term_count( Licensio_KB_Post_Types::TAXONOMY_TAG ), 'The tag is deleted.' );
    }

    /**
     * Converting creates a category under the parent and keeps the articles.
     *
     * The articles move off the tag in batches before the tag is deleted.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_convert_creates_a_category_under_the_parent() {
        $categories = $this->make_category_tree( array( 'Help' => array() ) );
        $refunds    = $this->make_term( 'Refunds' );
        $other      = $this->make_term( 'Other' );

        $this->db->query( 'UPDATE ' . $this->table( 'term_taxonomy' ) . " SET description = 'Money back' WHERE term_id = " . $refunds );

        $article = $this->make_article( array( 'tags' => array( $refunds, $other ) ) );
        $left    = null;

        Brain\Monkey\Functions\when( 'wp_delete_term' )->alias(
            function ( $term_id, $taxonomy ) use ( &$left ) {
                $left = $this->articles_in( (int) $term_id, (string) $taxonomy );

                return $this->live_delete_term( $term_id, $taxonomy );
            }
        );

        $result = $this->controller->convert(
            new WP_REST_Request(
                array(),
                '/licensio-kb/v1/tags/convert',
                array(
                    'ids'    => array( $refunds ),
                    'parent' => $categories['Help'],
                )
            )
        );

        $created = get_term_by( 'slug', 'refunds', Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        $this->assertFalse( $result['converted'][0]['reused'] );
        $this->assertInstanceOf( WP_Term::class, $created );
        $this->assertSame( 'Refunds', $created->name );
        $this->assertSame( 'Money back', $created->description );
        $this->assertSame( $categories['Help'], $created->parent );
        $this->assertSame( 1, $created->count );
        $this->assertSame( array( $article ), $this->articles_in( $created->term_id, Licensio_KB_Post_Types::TAXONOMY_CATEGORY ) );
        $this->assertSame( array( 'Other' ), $this->names(), 'Only the converted tag is deleted.' );
        $this->assertSame( array( $article ), $this->articles_in( $other, Licensio_KB_Post_Types::TAXONOMY_TAG ) );
        $this->assertSame( array(), $left, 'The tag is taken off its articles before it is deleted, so core has none to rewrite one by one.' );
    }

    /**
     * Bulk delete removes the chosen tags and their assignments only.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_bulk_delete_removes_only_the_chosen_tags() {
        $tags = $this->seed_tags();

        $result = $this->controller->bulk_delete(
            new WP_REST_Request(
                array(),
                '/licensio-kb/v1/tags/bulk-delete',
                array( 'ids' => array( $tags['Billing'], $tags['Unused'] ) )
            )
        );

        $this->assertSame( array( $tags['Billing'], $tags['Unused'] ), $result['deleted'] );
        $this->assertSame( array(), $result['failed'] );
        $this->assertSame( array( 'Invoices', 'Q&A' ), $this->names() );
        $this->assertSame( 2, $this->count_rows( 'term_relationships' ), 'The other tags keep their articles.' );
        $this->assertSame( 3, $this->count_rows( 'posts' ), 'No article is touched.' );
    }

    /**
     * Bulk delete of a tag with thousands of articles removes them in batches.
     *
     * The articles lose the tag in one statement per batch before core
     * deletes it, so core finds the tag empty. A tag whose last batch fails
     * is kept with every article, while the other tags are still deleted.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_bulk_delete_of_a_large_tag_removes_its_articles_in_batches() {
        $size    = 3000;
        $large   = $this->make_term( 'Large' );
        $failing = $this->make_term( 'Failing' );
        $ids     = $this->live_seed_articles( $large, $size );
        $tt_id   = (int) $this->live_term_row( $large, Licensio_KB_Post_Types::TAXONOMY_TAG )['term_taxonomy_id'];
        $article = $this->make_article( array( 'tags' => array( $failing ) ) );

        $this->live_fail_removal( $failing, Licensio_KB_Post_Types::TAXONOMY_TAG, $article );

        $left_at_delete = null;

        Brain\Monkey\Functions\when( 'wp_delete_term' )->alias(
            function ( $term_id, $taxonomy ) use ( $tt_id, &$left_at_delete ) {
                if ( Licensio_KB_Post_Types::TAXONOMY_TAG === $taxonomy && null === $left_at_delete ) {
                    $left_at_delete = $this->count_rows( 'term_relationships', 'term_taxonomy_id = ' . $tt_id );
                }

                return $this->live_delete_term( $term_id, $taxonomy );
            }
        );

        $result = $this->controller->bulk_delete(
            new WP_REST_Request(
                array(),
                '/licensio-kb/v1/tags/bulk-delete',
                array( 'ids' => array( $large, $failing ) )
            )
        );

        $this->assertSame( array( $large ), $result['deleted'] );
        $this->assertSame( array( $failing ), array_column( $result['failed'], 'id' ) );
        $this->assertSame( 0, $left_at_delete, 'Core deletes an empty tag.' );
        $this->assertSame( array( $article ), $this->articles_in( $failing, Licensio_KB_Post_Types::TAXONOMY_TAG ), 'The failed tag gets its article back.' );
        $this->assertSame( 1, $this->count_rows( 'term_relationships' ), 'Only the failed tag keeps an article.' );
        $this->assertSame( $size + 1, $this->count_rows( 'posts' ), 'No article is deleted.' );
        $this->assertSame( array( 'Failing' ), $this->names(), 'The failed tag is kept.' );

        $this->assertCount( (int) ceil( count( $ids ) / Licensio_KB_Term_Operations::BATCH_SIZE ), $this->live_relationship_deletes( $tt_id ), 'One statement per batch, not one per article.' );
    }

    /**
     * A merge whose second source fails leaves every term and assignment as it was.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_merge_that_fails_part_way_changes_nothing() {
        $a = $this->make_term( 'Alpha' );
        $b = $this->make_term( 'Beta' );
        $c = $this->make_term( 'Gamma' );

        $this->make_article( array( 'tags' => array( $a ) ) );
        $this->make_article( array( 'tags' => array( $a, $c ) ) );

        $failing = $this->make_article( array( 'tags' => array( $b ) ) );
        $before  = $this->live_snapshot();

        $this->live_fail_removal( $b, Licensio_KB_Post_Types::TAXONOMY_TAG, $failing );

        $result = $this->controller->merge(
            new WP_REST_Request(
                array(),
                '/licensio-kb/v1/tags/merge',
                array(
                    'sources' => array( $a, $b ),
                    'target'  => $c,
                )
            )
        );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertNotContains( 'licensio_kb_terms_merged', $this->fired_actions, 'A failed merge does not announce itself.' );
        $this->assertSame( $before, $this->live_snapshot() );
    }

    /**
     * A convert whose tags cannot be deleted leaves the tags, articles and categories as they were.
     *
     * One tag reuses an existing category, whose new articles lose it again;
     * the other gets a new category, which is deleted again.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_convert_that_cannot_delete_the_tags_changes_nothing() {
        $categories = $this->make_category_tree(
            array(
                'Help'    => array(),
                'Refunds' => array(),
            )
        );
        $refunds    = $this->make_term( 'Refunds' );
        $other      = $this->make_term( 'Other' );

        $this->make_article(
            array(
                'tags'       => array( $refunds, $other ),
                'categories' => array( $categories['Help'] ),
            )
        );
        $this->make_article( array( 'tags' => array( $refunds ) ) );

        $before = $this->live_snapshot();

        Brain\Monkey\Functions\when( 'wp_delete_term' )->alias(
            function ( $term_id, $taxonomy ) {
                return Licensio_KB_Post_Types::TAXONOMY_TAG === $taxonomy ? false : $this->live_delete_term( $term_id, $taxonomy );
            }
        );

        $result = $this->controller->convert(
            new WP_REST_Request(
                array(),
                '/licensio-kb/v1/tags/convert',
                array(
                    'ids'    => array( $refunds, $other ),
                    'parent' => $categories['Help'],
                )
            )
        );

        $this->assertSame( array(), $result['converted'] );
        $this->assertSame( array( $refunds, $other ), array_column( $result['failed'], 'id' ) );
        $this->assertSame( $before, $this->live_snapshot() );
    }

    /**
     * A new tag with a taken slug gets a unique one; an edit to a taken slug is a 409.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_taken_slug_is_made_unique_on_add_and_refused_on_edit() {
        $this->make_term( 'Billing' );

        $added = $this->controller->create_item(
            new WP_REST_Request(
                array(),
                '/licensio-kb/v1/tags',
                array(
                    'name' => 'Invoices',
                    'slug' => 'billing',
                )
            )
        );

        $this->assertSame( 'billing-2', $added->get_data()['slug'] );

        // The slug is sent already sanitised; the stand-in keeps it as it is.
        Brain\Monkey\Functions\when( 'sanitize_title' )->returnArg();

        $before = $this->live_snapshot();
        $edited = $this->controller->update_item(
            new WP_REST_Request(
                array(),
                '/licensio-kb/v1/tags/' . $added->get_data()['id'],
                array(
                    'id'   => $added->get_data()['id'],
                    'slug' => 'billing',
                )
            )
        );

        $this->assertInstanceOf( WP_Error::class, $edited );
        $this->assertSame( 409, $edited->get_error_data()['status'] );
        $this->assertSame( 'Another tag already uses the slug “billing”. Choose a different slug.', $edited->get_error_message() );
        $this->assertSame( $before, $this->live_snapshot() );

        $clash = $this->controller->create_item(
            new WP_REST_Request(
                array(),
                '/licensio-kb/v1/tags',
                array( 'name' => 'Billing' )
            )
        );

        $this->assertSame( 'term_exists', $clash->get_error_code() );
        $this->assertSame( 409, $clash->get_error_data()['status'] );
    }

    /**
     * A tag a map_meta_cap rule denies cannot be edited, deleted, merged or converted.
     *
     * The user holds every taxonomy capability; only `edit_term` and
     * `delete_term` on one tag, or `edit_term` on the parent category, are
     * refused, as a map_meta_cap filter would. Each request goes through its
     * permission callback first, as the REST server sends it, and is refused
     * before anything is written.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_tag_denied_by_its_meta_capability_is_left_alone() {
        $locked = $this->make_term( 'Locked' );
        $open   = $this->make_term( 'Open' );
        $parent = $this->make_term( 'Parent', Licensio_KB_Post_Types::TAXONOMY_CATEGORY );

        $this->make_article( array( 'tags' => array( $locked ) ) );
        $this->make_article( array( 'tags' => array( $open ) ) );

        $denied = array( 'edit_term:' . $locked, 'delete_term:' . $locked, 'edit_term:' . $parent );

        Brain\Monkey\Functions\when( 'rest_authorization_required_code' )->justReturn( 403 );
        Brain\Monkey\Functions\when( 'get_taxonomy' )->alias(
            function ( $taxonomy ) {
                return (object) array(
                    'cap' => (object) array(
                        'manage_terms' => 'manage_' . $taxonomy,
                        'edit_terms'   => 'edit_' . $taxonomy,
                        'delete_terms' => 'delete_' . $taxonomy,
                        'assign_terms' => 'assign_' . $taxonomy,
                    ),
                );
            }
        );
        Brain\Monkey\Functions\when( 'current_user_can' )->alias(
            function ( $cap, $id = 0 ) use ( $denied ) {
                return ! in_array( $cap . ':' . (int) $id, $denied, true );
            }
        );

        $before   = $this->live_snapshot();
        $requests = array(
            'update'         => array(
                'update_permissions_check',
                'update_item',
                array(
                    'id'   => $locked,
                    'name' => 'Renamed',
                ),
            ),
            'delete'         => array( 'delete_permissions_check', 'bulk_delete', array( 'ids' => array( $open, $locked ) ) ),
            'merge away'     => array(
                'merge_permissions_check',
                'merge',
                array(
                    'sources' => array( $locked ),
                    'target'  => $open,
                ),
            ),
            'merge into'     => array(
                'merge_permissions_check',
                'merge',
                array(
                    'sources' => array( $open ),
                    'target'  => $locked,
                ),
            ),
            'convert'        => array(
                'convert_permissions_check',
                'convert',
                array(
                    'ids'    => array( $open, $locked ),
                    'parent' => 0,
                ),
            ),
            'convert parent' => array(
                'convert_permissions_check',
                'convert',
                array(
                    'ids'    => array( $open ),
                    'parent' => $parent,
                ),
            ),
        );

        foreach ( $requests as $label => $request ) {
            list( $permission, $handler, $params ) = $request;

            $rest    = new WP_REST_Request( array(), '/licensio-kb/v1/tags', $params );
            $allowed = $this->controller->$permission( $rest );

            if ( true === $allowed ) {
                $this->controller->$handler( $rest );
            }

            $this->assertInstanceOf( WP_Error::class, $allowed, $label );
            $this->assertSame( 403, $allowed->get_error_data()['status'], $label );
            $this->assertSame( $before, $this->live_snapshot(), $label . ' writes nothing.' );
        }

        $this->assertTrue(
            $this->controller->convert_permissions_check(
                new WP_REST_Request(
                    array(),
                    '/licensio-kb/v1/tags/convert',
                    array(
                        'ids'    => array( $open ),
                        'parent' => 0,
                    )
                )
            ),
            'A tag the user may delete can still be converted.'
        );
    }
}
