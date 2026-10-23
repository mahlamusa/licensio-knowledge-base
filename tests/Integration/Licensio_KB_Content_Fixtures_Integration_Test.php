<?php
/**
 * The content fixtures seed what they claim to.
 *
 * Every content manager integration test builds on these helpers, so a helper
 * that put an article in the wrong category or a view on the wrong day would
 * make each of those tests wrong in the same way. These tests read the rows
 * back with plain SQL.
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

/**
 * Exercises Licensio_KB_Content_Fixtures against a live server.
 *
 * @since 1.0.0
 */
class Licensio_KB_Content_Fixtures_Integration_Test extends Licensio_KB_Analytics_DB_Test_Case {

    use Licensio_KB_Content_Fixtures;

    /**
     * Add the core post and term tables.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->install_content_schemas();
    }

    /**
     * One value from a query.
     *
     * @since 1.0.0
     *
     * @param string $sql The query.
     *
     * @return string|null The first column of the first row.
     */
    private function value( $sql ) {
        $result = $this->db->query( $sql );

        $this->assertInstanceOf( 'mysqli_result', $result, $this->db->error );

        $row = $result->fetch_row();

        return null === $row ? null : (string) $row[0];
    }

    /**
     * A category tree keeps its nesting.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_category_tree_keeps_its_nesting() {
        $ids = $this->make_category_tree(
            array(
                'Getting started' => array(
                    'Install' => array(
                        'Requirements' => array(),
                    ),
                ),
                'Billing'         => array(),
            )
        );

        $this->assertSame( array( 'Getting started', 'Install', 'Requirements', 'Billing' ), array_keys( $ids ) );

        $parent = function ( $term_id ) {
            return (int) $this->value( 'SELECT parent FROM ' . $this->table( 'term_taxonomy' ) . ' WHERE term_id = ' . (int) $term_id );
        };

        $this->assertSame( 0, $parent( $ids['Getting started'] ) );
        $this->assertSame( $ids['Getting started'], $parent( $ids['Install'] ) );
        $this->assertSame( $ids['Install'], $parent( $ids['Requirements'] ) );
        $this->assertSame( 0, $parent( $ids['Billing'] ) );
        $this->assertSame( 'getting-started', $this->value( 'SELECT slug FROM ' . $this->table( 'terms' ) . ' WHERE term_id = ' . $ids['Getting started'] ) );
        $this->assertSame( Licensio_KB_Post_Types::TAXONOMY_CATEGORY, $this->value( 'SELECT DISTINCT taxonomy FROM ' . $this->table( 'term_taxonomy' ) ) );
    }

    /**
     * An article is assigned its terms, and only published ones are counted.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_article_gets_its_terms_and_counts() {
        $ids = $this->make_category_tree( array( 'Billing' => array() ) );
        $tag = $this->make_term( 'Invoices' );

        $published = $this->make_article(
            array(
                'title'      => 'Download an invoice',
                'categories' => array( $ids['Billing'] ),
                'tags'       => array( $tag ),
                'author'     => 7,
            )
        );
        $draft     = $this->make_article(
            array(
                'status'     => 'draft',
                'categories' => array( $ids['Billing'] ),
            )
        );

        $posts = $this->table( 'posts' );

        $this->assertSame( 'licensio_article', $this->value( "SELECT post_type FROM {$posts} WHERE ID = {$published}" ) );
        $this->assertSame( 'download-an-invoice', $this->value( "SELECT post_name FROM {$posts} WHERE ID = {$published}" ) );
        $this->assertSame( '7', $this->value( "SELECT post_author FROM {$posts} WHERE ID = {$published}" ) );
        $this->assertSame( 'draft', $this->value( "SELECT post_status FROM {$posts} WHERE ID = {$draft}" ) );
        $this->assertSame( 'Article 2', $this->value( "SELECT post_title FROM {$posts} WHERE ID = {$draft}" ) );

        $in_billing = 'SELECT COUNT(*) FROM ' . $this->table( 'term_relationships' ) . ' r JOIN ' . $this->table( 'term_taxonomy' )
            . ' tt ON tt.term_taxonomy_id = r.term_taxonomy_id WHERE tt.term_id = ' . $ids['Billing'];

        $this->assertSame( '2', $this->value( $in_billing ) );
        $this->assertSame( '1', $this->value( 'SELECT count FROM ' . $this->table( 'term_taxonomy' ) . ' WHERE term_id = ' . $ids['Billing'] ) );
        $this->assertSame( '1', $this->value( 'SELECT count FROM ' . $this->table( 'term_taxonomy' ) . ' WHERE term_id = ' . $tag ) );
    }

    /**
     * Views and votes land on the right article and day.
     *
     * The windowed counts the article list will make are exactly these
     * queries, so a 30-day window must see the recent rows and not the old.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_views_and_votes_land_on_the_right_day() {
        $article = $this->make_article();
        $other   = $this->make_article();

        $this->add_views( $article, 3, 2 );
        $this->add_views( $article, 4, 45 );
        $this->add_views( $other, 1, 2 );
        $this->add_votes( $article, 5, 2, 1 );
        $this->add_votes( $article, 0, 9, 60 );

        $since = $this->days_ago( 30 );
        $views = $this->table( 'licensio_article_views' );
        $votes = $this->table( 'licensio_feedback' );

        $this->assertSame( '3', $this->value( "SELECT COUNT(*) FROM {$views} WHERE article_id = {$article} AND created_at >= '{$since}'" ) );
        $this->assertSame( '7', $this->value( "SELECT COUNT(*) FROM {$views} WHERE article_id = {$article}" ) );
        $this->assertSame( '3', $this->value( "SELECT COUNT(DISTINCT visitor_hash) FROM {$views} WHERE article_id = {$article} AND created_at >= '{$since}'" ) );
        $this->assertSame( '5', $this->value( "SELECT SUM(vote = 1) FROM {$votes} WHERE article_id = {$article} AND created_at >= '{$since}'" ) );
        $this->assertSame( '2', $this->value( "SELECT SUM(vote = -1) FROM {$votes} WHERE article_id = {$article} AND created_at >= '{$since}'" ) );
        $this->assertSame( '11', $this->value( "SELECT SUM(vote = -1) FROM {$votes} WHERE article_id = {$article}" ) );
    }
}
