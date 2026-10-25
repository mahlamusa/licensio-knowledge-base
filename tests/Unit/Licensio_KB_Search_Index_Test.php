<?php
/**
 * Unit tests for search index maintenance.
 *
 * Covers what an index row is made of and when one is due, without a database.
 * The writes themselves are asserted in the integration suite, against a real
 * table, because the interesting question there is whether the SQL the query
 * builder generates addresses the right table at all.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Tests the index row builder, indexability rules and hook registration.
 *
 * @since 1.0.0
 */
class Licensio_KB_Search_Index_Test extends Licensio_KB_Test_Case {

    /**
     * The index under test.
     *
     * @since 1.0.0
     * @var Licensio_KB_Search_Index
     */
    private $index;

    /**
     * Stub the WordPress surface the index reads.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->index = new Licensio_KB_Search_Index();

        Functions\when( 'strip_shortcodes' )->alias(
            function ( $value ) {
                return (string) preg_replace( '/\[[^\]]*\]/', ' ', (string) $value );
            }
        );

        Functions\when( 'wp_get_object_terms' )->justReturn( array( 'Getting started', 'Licence' ) );
        Functions\when( 'is_wp_error' )->justReturn( false );
    }

    /**
     * Block delimiters and shortcodes never become searchable text.
     *
     * Without this a search for "paragraph" would match every article, because
     * every block comment in every body contains the word.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_block_delimiters_and_shortcodes_are_removed() {
        $content = "<!-- wp:paragraph -->\n<p>The licence server.</p>\n<!-- /wp:paragraph -->\n[contact_form id=\"4\"]";

        $plain = Licensio_KB_Search_Index::plain_text( $content );

        $this->assertSame( 'The licence server.', $plain );
        $this->assertStringNotContainsString( 'wp:paragraph', $plain );
        $this->assertStringNotContainsString( 'contact_form', $plain );
    }

    /**
     * Block-level markup becomes a word boundary, not a join.
     *
     * Stripping `</p><p>` without leaving a space glues the last word of one
     * paragraph to the first word of the next, and neither is then findable.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_block_markup_becomes_a_word_boundary() {
        $this->assertSame(
            'first second',
            Licensio_KB_Search_Index::plain_text( '<p>first</p><p>second</p>' )
        );
    }

    /**
     * Entities are decoded so the stored text matches what a visitor types.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_entities_are_decoded() {
        $this->assertSame(
            'Terms & conditions',
            Licensio_KB_Search_Index::plain_text( 'Terms &amp; conditions' )
        );
    }

    /**
     * A row carries the title, terms and plain body of its article.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_row_carries_the_indexed_fields() {
        $post = new WP_Post(
            array(
                'ID'           => 42,
                'post_title'   => 'Licence activation',
                'post_content' => '<p>How to activate a licence.</p>',
            )
        );

        $row = $this->index->build_row( $post );

        $this->assertSame( 42, $row['article_id'] );
        $this->assertSame( 'Licence activation', $row['title'] );
        $this->assertSame( 'How to activate a licence.', $row['content'] );
        $this->assertSame( 'Getting started, Licence', $row['terms'] );
    }

    /**
     * A row does not carry its own timestamp.
     *
     * `updated_at` is added by sync() immediately before the write. If
     * build_row() set it, the per-request digest that keeps one editor save to
     * one row write would differ on every call and the deduplication would never
     * fire.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_built_row_has_no_timestamp() {
        $row = $this->index->build_row( new WP_Post( array( 'ID' => 42 ) ) );

        $this->assertArrayNotHasKey( 'updated_at', $row );
    }

    /**
     * The excerpt falls back to the body when the article has none.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_excerpt_falls_back_to_the_body() {
        $post = new WP_Post(
            array(
                'ID'           => 42,
                'post_content' => 'Body text about licences.',
            )
        );

        $this->assertSame( 'Body text about licences.', $this->index->build_row( $post )['excerpt'] );
    }

    /**
     * A published, unprotected article is indexable.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_published_article_is_indexable() {
        $this->assertTrue( Licensio_KB_Search_Index::is_indexable( new WP_Post( array( 'ID' => 1 ) ) ) );
    }

    /**
     * Anything a visitor cannot read is not indexed.
     *
     * The index is the post-status filter. A drafted or trashed article with a
     * row would surface in a public search result even though nobody may read
     * it.
     *
     * @since 1.0.0
     *
     * @dataProvider unreadable_post_provider
     *
     * @param array<string, mixed> $fields The post fields that make it unreadable.
     *
     * @return void
     */
    public function test_an_unreadable_article_is_not_indexable( array $fields ) {
        $this->assertFalse(
            Licensio_KB_Search_Index::is_indexable( new WP_Post( $fields ) )
        );
    }

    /**
     * Post states that must never reach the index.
     *
     * @since 1.0.0
     *
     * @return array<string, array{0: array<string, mixed>}> The states.
     */
    public function unreadable_post_provider() {
        return array(
            'draft'              => array( array( 'post_status' => 'draft' ) ),
            'pending'            => array( array( 'post_status' => 'pending' ) ),
            'trashed'            => array( array( 'post_status' => 'trash' ) ),
            'private'            => array( array( 'post_status' => 'private' ) ),
            'future'             => array( array( 'post_status' => 'future' ) ),
            'auto-draft'         => array( array( 'post_status' => 'auto-draft' ) ),
            'password protected' => array( array( 'post_password' => 'secret' ) ),
        );
    }

    /**
     * Indexability is filterable, so a gating add-on can withhold an article.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_indexability_is_filterable() {
        Filters\expectApplied( 'licensio_search_indexable' )
            ->once()
            ->andReturn( false );

        $this->assertFalse(
            Licensio_KB_Search_Index::is_indexable( new WP_Post( array( 'ID' => 1 ) ) )
        );
    }

    /**
     * Both knowledge base taxonomies are indexed with an article.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_both_taxonomies_are_indexed() {
        $taxonomies = Licensio_KB_Search_Index::indexed_taxonomies();

        $this->assertContains( Licensio_KB_Post_Types::TAXONOMY_CATEGORY, $taxonomies );
        $this->assertContains( Licensio_KB_Post_Types::TAXONOMY_TAG, $taxonomies );
    }

    /**
     * Every content mutation WordPress reports has a listener.
     *
     * The acceptance criterion is that editing, trashing, untrashing or
     * retagging an article updates the index without a manual reindex, and each
     * of those arrives on a different hook.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_content_mutation_is_hooked() {
        Actions\expectAdded( 'save_post_licensio_article' )->once();
        Actions\expectAdded( 'transition_post_status' )->once();
        Actions\expectAdded( 'deleted_post' )->once();
        Actions\expectAdded( 'set_object_terms' )->once();
        Actions\expectAdded( 'licensio_kb_term_assignments_changed' )->once();
        Actions\expectAdded( 'edited_term' )->once();
        Actions\expectAdded( 'delete_term' )->once();
        Actions\expectAdded( Licensio_KB_Search_Index::CRON_HOOK )->once();
        Actions\expectAdded( 'admin_init' )->once();
        Actions\expectAdded( 'licensio_deactivated' )->once();

        $this->index->init_hooks();
    }

    /**
     * A revision is never indexed as though it were the article.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_revision_is_ignored() {
        Functions\when( 'wp_is_post_revision' )->justReturn( 7 );
        Functions\when( 'wp_is_post_autosave' )->justReturn( false );
        Functions\expect( 'get_post' )->never();

        $this->index->handle_save_post( 8, new WP_Post( array( 'ID' => 8 ) ) );
    }

    /**
     * A status transition on another post type is ignored.
     *
     * `transition_post_status` fires for every post on the site, so a listener
     * that did not check the type would try to index every page and product.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_status_transition_on_another_post_type_is_ignored() {
        Functions\expect( 'get_post' )->never();

        $this->index->handle_transition_post_status(
            'publish',
            'draft',
            new WP_Post(
                array(
                    'ID'        => 9,
                    'post_type' => 'page',
                )
            )
        );
    }

    /**
     * Terms set on an unrelated taxonomy are ignored.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_terms_on_another_taxonomy_are_ignored() {
        Functions\expect( 'get_post' )->never();

        $this->index->handle_set_object_terms( 5, array(), array(), 'post_tag' );
    }

    /**
     * A batched term move reindexes the articles it changed.
     *
     * Moves, copies and merges write relationship rows directly, so core's
     * `set_object_terms` never fires for those articles. Many articles start
     * a rebuild instead, and another taxonomy is ignored.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_batched_term_move_reindexes_the_changed_articles() {
        Functions\when( 'wp_next_scheduled' )->justReturn( false );
        Functions\expect( 'wp_schedule_single_event' )->once();
        Functions\expect( 'get_post' )->never();

        $this->index->handle_assignments_changed( array( 5, 6 ), 'post_tag' );
        $this->index->handle_assignments_changed( range( 1, Licensio_KB_Search_Index::TERM_FANOUT_LIMIT + 1 ), Licensio_KB_Post_Types::TAXONOMY_CATEGORY );
    }

    /**
     * A term edit touching many articles defers to a full rebuild.
     *
     * Reindexing several thousand articles inline would time out the request
     * that renamed the category.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_wide_term_edit_schedules_a_rebuild_instead() {
        Functions\when( 'wp_next_scheduled' )->justReturn( false );
        Functions\expect( 'wp_schedule_single_event' )->once();
        Functions\expect( 'get_post' )->never();

        $ids = range( 1, Licensio_KB_Search_Index::TERM_FANOUT_LIMIT + 1 );

        $this->assertSame( 0, $this->index->reindex_many( $ids ) );
        $this->assertSame( 0, $this->options[ Licensio_KB_Search_Index::STATE_OPTION ]['offset'] );
    }

    /**
     * The initial build runs once per plugin version.
     *
     * Activation cannot start it: the activation hook runs in a request where
     * `plugins_loaded` fired before the plugin was active, so nothing this class
     * registers has been hooked yet.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_initial_build_runs_once_per_version() {
        Functions\when( 'wp_next_scheduled' )->justReturn( false );
        Functions\when( 'wp_schedule_single_event' )->justReturn( true );

        $this->assertTrue( $this->index->maybe_start_initial_rebuild() );
        $this->assertSame( LICENSIO_KB_VERSION, $this->options[ Licensio_KB_Search_Index::BUILT_OPTION ] );

        $this->assertFalse( $this->index->maybe_start_initial_rebuild() );
    }

    /**
     * Deactivating the plugin clears the rebuild it scheduled.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_deactivation_clears_a_pending_rebuild() {
        $this->options[ Licensio_KB_Search_Index::STATE_OPTION ] = array( 'offset' => 100 );

        Functions\expect( 'wp_clear_scheduled_hook' )
            ->once()
            ->with( Licensio_KB_Search_Index::CRON_HOOK );

        $this->index->unschedule_rebuild();

        $this->assertArrayNotHasKey( Licensio_KB_Search_Index::STATE_OPTION, $this->options );
    }
    /**
     * Encoded markup does not survive as markup.
     *
     * `plain_text()` used to strip tags and *then* decode entities, so text
     * that merely looked like markup came back as markup - the strip had
     * already run and had nothing left to remove. A method named for plain
     * text handing back a live `script` element is wrong on its own terms,
     * whether or not a caller happens to print it today.
     *
     * Asserted on the absence of the delimiters rather than of the word
     * `script`: the payload text may legitimately survive as prose, and what
     * must not survive is anything a parser would read as a tag.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_encoded_markup_is_not_resurrected_as_markup() {
        $plain = Licensio_KB_Search_Index::plain_text(
            'Intro &lt;script&gt;alert(document.domain)&lt;/script&gt; and &lt;img src=x onerror=alert(1)&gt; end'
        );

        $this->assertStringNotContainsString( '<script', $plain );
        $this->assertStringNotContainsString( '<img', $plain );
        $this->assertStringNotContainsString( '</script', $plain );

        // The surrounding prose is still indexed, so this is not passing by
        // returning nothing at all.
        $this->assertStringContainsString( 'Intro', $plain );
        $this->assertStringContainsString( 'end', $plain );
    }

    /**
     * A real entity in prose still decodes.
     *
     * The counterpart: an implementation that simply stopped decoding would
     * also pass the test above, and would quietly index `Terms &amp;amp;
     * conditions`.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_plain_entity_still_decodes() {
        $this->assertSame(
            'Terms & conditions',
            Licensio_KB_Search_Index::plain_text( 'Terms &amp; conditions' )
        );
    }
}
