<?php
/**
 * Content fixtures for the live database suite.
 *
 * Seeds knowledge base articles, category trees, tags, views and votes
 * straight into a scratch database, so a test of the content manager's SQL
 * runs against rows that are known to be there. No WordPress is loaded in this
 * suite, so everything is a plain INSERT.
 *
 * Use it from a subclass of Licensio_KB_Analytics_DB_Test_Case, which creates
 * the plugin's own tables and supplies `table()`, `seed()` and `days_ago()`,
 * and call `install_content_schemas()` from `setUp()` to add the core post and
 * term tables:
 *
 *     require_once __DIR__ . '/Licensio_KB_Analytics_DB_Test_Case.php';
 *     require_once __DIR__ . '/trait-licensio-kb-content-fixtures.php';
 *
 *     class My_Test extends Licensio_KB_Analytics_DB_Test_Case {
 *         use Licensio_KB_Content_Fixtures;
 *
 *         protected function setUp(): void {
 *             parent::setUp();
 *             $this->install_content_schemas();
 *         }
 *     }
 *
 * The core tables are created with core's own column definitions, so SQL that
 * runs here runs against the same columns it meets in production.
 *
 * Consumed by the articles, categories, tags and per-category order tests.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/*
 * This suite connects to a real database on purpose. The WordPress Database API
 * is therefore not available or appropriate here.
 */
// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery

/**
 * Seeds articles, terms, views and votes into the scratch database.
 *
 * @since 1.0.0
 */
trait Licensio_KB_Content_Fixtures {

    /**
     * How many articles this test has made, for default titles.
     *
     * @since 1.0.0
     * @var int
     */
    private $fixture_article_count = 0;

    /**
     * The fully qualified name of one of the scratch tables.
     *
     * @since 1.0.0
     *
     * @param string $table The unprefixed table name.
     *
     * @return string The prefixed table name.
     */
    abstract protected function table( $table );

    /**
     * Insert rows straight into a scratch table.
     *
     * @since 1.0.0
     *
     * @param string                           $table The unprefixed table name.
     * @param array<int, array<string, mixed>> $rows  The rows to insert.
     *
     * @return void
     */
    abstract protected function seed( $table, array $rows );

    /**
     * A `Y-m-d H:i:s` timestamp a number of days before the test's "now".
     *
     * @since 1.0.0
     *
     * @param int $days How many days back.
     *
     * @return string The timestamp.
     */
    abstract protected function days_ago( $days );

    /**
     * Create the core post and term tables.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function install_content_schemas() {
        $collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

        $schemas = array(
            'posts'              => 'CREATE TABLE ' . $this->table( 'posts' ) . " (
                ID bigint(20) unsigned NOT NULL auto_increment,
                post_author bigint(20) unsigned NOT NULL default '0',
                post_date datetime NOT NULL default '0000-00-00 00:00:00',
                post_date_gmt datetime NOT NULL default '0000-00-00 00:00:00',
                post_content longtext NOT NULL,
                post_title text NOT NULL,
                post_excerpt text NOT NULL,
                post_status varchar(20) NOT NULL default 'publish',
                comment_status varchar(20) NOT NULL default 'open',
                ping_status varchar(20) NOT NULL default 'open',
                post_password varchar(255) NOT NULL default '',
                post_name varchar(200) NOT NULL default '',
                to_ping text NOT NULL,
                pinged text NOT NULL,
                post_modified datetime NOT NULL default '0000-00-00 00:00:00',
                post_modified_gmt datetime NOT NULL default '0000-00-00 00:00:00',
                post_content_filtered longtext NOT NULL,
                post_parent bigint(20) unsigned NOT NULL default '0',
                guid varchar(255) NOT NULL default '',
                menu_order int(11) NOT NULL default '0',
                post_type varchar(20) NOT NULL default 'post',
                post_mime_type varchar(100) NOT NULL default '',
                comment_count bigint(20) NOT NULL default '0',
                PRIMARY KEY  (ID),
                KEY post_name (post_name(191)),
                KEY type_status_date (post_type,post_status,post_date,ID),
                KEY post_parent (post_parent),
                KEY post_author (post_author),
                KEY type_status_author (post_type,post_status,post_author)
            ) {$collate};",
            'postmeta'           => 'CREATE TABLE ' . $this->table( 'postmeta' ) . " (
                meta_id bigint(20) unsigned NOT NULL auto_increment,
                post_id bigint(20) unsigned NOT NULL default '0',
                meta_key varchar(255) default NULL,
                meta_value longtext,
                PRIMARY KEY  (meta_id),
                KEY post_id (post_id),
                KEY meta_key (meta_key(191))
            ) {$collate};",
            'terms'              => 'CREATE TABLE ' . $this->table( 'terms' ) . " (
                term_id bigint(20) unsigned NOT NULL auto_increment,
                name varchar(200) NOT NULL default '',
                slug varchar(200) NOT NULL default '',
                term_group bigint(10) NOT NULL default 0,
                PRIMARY KEY  (term_id),
                KEY slug (slug(191)),
                KEY name (name(191))
            ) {$collate};",
            'termmeta'           => 'CREATE TABLE ' . $this->table( 'termmeta' ) . " (
                meta_id bigint(20) unsigned NOT NULL auto_increment,
                term_id bigint(20) unsigned NOT NULL default '0',
                meta_key varchar(255) default NULL,
                meta_value longtext,
                PRIMARY KEY  (meta_id),
                KEY term_id (term_id),
                KEY meta_key (meta_key(191))
            ) {$collate};",
            'term_taxonomy'      => 'CREATE TABLE ' . $this->table( 'term_taxonomy' ) . " (
                term_taxonomy_id bigint(20) unsigned NOT NULL auto_increment,
                term_id bigint(20) unsigned NOT NULL default 0,
                taxonomy varchar(32) NOT NULL default '',
                description longtext NOT NULL,
                parent bigint(20) unsigned NOT NULL default 0,
                count bigint(20) NOT NULL default 0,
                PRIMARY KEY  (term_taxonomy_id),
                UNIQUE KEY term_id_taxonomy (term_id,taxonomy),
                KEY taxonomy (taxonomy)
            ) {$collate};",
            'term_relationships' => 'CREATE TABLE ' . $this->table( 'term_relationships' ) . " (
                object_id bigint(20) unsigned NOT NULL default 0,
                term_taxonomy_id bigint(20) unsigned NOT NULL default 0,
                term_order int(11) NOT NULL default 0,
                PRIMARY KEY  (object_id,term_taxonomy_id),
                KEY term_taxonomy_id (term_taxonomy_id)
            ) {$collate};",
        );

        foreach ( $schemas as $name => $sql ) {
            $this->assertTrue(
                (bool) $this->db->query( $sql ),
                sprintf( 'Schema %1$s failed: %2$s', $name, $this->db->error )
            );
        }
    }

    /**
     * Create one article.
     *
     * Term counts follow core: only a published article is counted.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $args {
     *     Optional. The article.
     *
     *     @type string $title          Defaults to "Article {n}".
     *     @type string $slug           Defaults to the title, lowercased and hyphenated.
     *     @type string $status         Defaults to `publish`.
     *     @type int    $author         Defaults to 1.
     *     @type string $date           `Y-m-d H:i:s`. Defaults to 10 days ago.
     *     @type string $modified       `Y-m-d H:i:s`. Defaults to the date.
     *     @type string $content        Defaults to empty.
     *     @type string $comment_status Defaults to `open`.
     *     @type string $password       Defaults to empty.
     *     @type int    $menu_order     Defaults to 0.
     *     @type int[]  $categories     `licensio_category` term IDs.
     *     @type int[]  $tags           `licensio_tag` term IDs.
     * }
     *
     * @return int The new article ID.
     */
    protected function make_article( array $args = array() ) {
        ++$this->fixture_article_count;

        $title = isset( $args['title'] ) ? (string) $args['title'] : 'Article ' . $this->fixture_article_count;
        $date  = isset( $args['date'] ) ? (string) $args['date'] : $this->days_ago( 10 );

        $this->seed(
            'posts',
            array(
                array(
                    'post_author'           => isset( $args['author'] ) ? (int) $args['author'] : 1,
                    'post_date'             => $date,
                    'post_date_gmt'         => $date,
                    'post_content'          => isset( $args['content'] ) ? (string) $args['content'] : '',
                    'post_title'            => $title,
                    'post_excerpt'          => '',
                    'post_status'           => isset( $args['status'] ) ? (string) $args['status'] : 'publish',
                    'comment_status'        => isset( $args['comment_status'] ) ? (string) $args['comment_status'] : 'open',
                    'post_password'         => isset( $args['password'] ) ? (string) $args['password'] : '',
                    'post_name'             => isset( $args['slug'] ) ? (string) $args['slug'] : trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $title ) ), '-' ),
                    'to_ping'               => '',
                    'pinged'                => '',
                    'post_modified'         => isset( $args['modified'] ) ? (string) $args['modified'] : $date,
                    'post_modified_gmt'     => isset( $args['modified'] ) ? (string) $args['modified'] : $date,
                    'post_content_filtered' => '',
                    'menu_order'            => isset( $args['menu_order'] ) ? (int) $args['menu_order'] : 0,
                    'post_type'             => Licensio_KB_Post_Types::POST_TYPE,
                ),
            )
        );

        $article_id = (int) $this->db->insert_id;
        $published  = ! isset( $args['status'] ) || 'publish' === $args['status'];

        $terms = array_merge(
            isset( $args['categories'] ) ? (array) $args['categories'] : array(),
            isset( $args['tags'] ) ? (array) $args['tags'] : array()
        );

        foreach ( $terms as $term_id ) {
            $tt_id = $this->term_taxonomy_id( (int) $term_id );

            $this->seed(
                'term_relationships',
                array(
                    array(
                        'object_id'        => $article_id,
                        'term_taxonomy_id' => $tt_id,
                    ),
                )
            );

            if ( $published ) {
                $this->db->query( 'UPDATE ' . $this->table( 'term_taxonomy' ) . ' SET count = count + 1 WHERE term_taxonomy_id = ' . $tt_id );
            }
        }

        return $article_id;
    }

    /**
     * Create one term.
     *
     * @since 1.0.0
     *
     * @param string $name      The term name. The slug is derived from it.
     * @param string $taxonomy  The taxonomy. Defaults to `licensio_tag`.
     * @param int    $parent_id The parent term ID. Defaults to none.
     *
     * @return int The new term ID.
     */
    protected function make_term( $name, $taxonomy = Licensio_KB_Post_Types::TAXONOMY_TAG, $parent_id = 0 ) {
        $this->seed(
            'terms',
            array(
                array(
                    'name' => (string) $name,
                    'slug' => trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $name ) ), '-' ),
                ),
            )
        );

        $term_id = (int) $this->db->insert_id;

        $this->seed(
            'term_taxonomy',
            array(
                array(
                    'term_id'     => $term_id,
                    'taxonomy'    => (string) $taxonomy,
                    'description' => '',
                    'parent'      => (int) $parent_id,
                ),
            )
        );

        return $term_id;
    }

    /**
     * Create a tree of categories.
     *
     * The spec maps each name to its own children:
     *
     *     array(
     *         'Getting started' => array(
     *             'Install' => array(),
     *             'Upgrade' => array(),
     *         ),
     *         'Billing'         => array(),
     *     )
     *
     * Names must be unique within one tree.
     *
     * @since 1.0.0
     *
     * @param array<string, array<string, mixed>> $spec      Name to children.
     * @param int                                 $parent_id The parent of the top level. Defaults to none.
     *
     * @return array<string, int> Category name to term ID, for the whole tree.
     */
    protected function make_category_tree( array $spec, $parent_id = 0 ) {
        $ids = array();

        foreach ( $spec as $name => $children ) {
            $term_id      = $this->make_term( (string) $name, Licensio_KB_Post_Types::TAXONOMY_CATEGORY, (int) $parent_id );
            $ids[ $name ] = $term_id;
            $ids         += $this->make_category_tree( (array) $children, $term_id );
        }

        return $ids;
    }

    /**
     * Record article views.
     *
     * Each view gets its own visitor hash, so the rows look like distinct
     * readers.
     *
     * @since 1.0.0
     *
     * @param int $article_id The article viewed.
     * @param int $count      How many views.
     * @param int $days_ago   How many days before "now" the views happened.
     *
     * @return void
     */
    protected function add_views( $article_id, $count, $days_ago ) {
        $rows = array();

        for ( $i = 0; $i < (int) $count; $i++ ) {
            $rows[] = array(
                'article_id'   => (int) $article_id,
                'visitor_hash' => hash( 'sha256', 'view|' . $article_id . '|' . $days_ago . '|' . $i ),
                'created_at'   => $this->days_ago( (int) $days_ago ),
            );
        }

        $this->seed( 'licensio_article_views', $rows );
    }

    /**
     * Record helpful and not helpful votes.
     *
     * @since 1.0.0
     *
     * @param int $article_id The article voted on.
     * @param int $up         How many helpful votes.
     * @param int $down       How many not helpful votes.
     * @param int $days_ago   How many days before "now" the votes were cast.
     *
     * @return void
     */
    protected function add_votes( $article_id, $up, $down, $days_ago ) {
        $rows  = array();
        $votes = array_merge(
            array_fill( 0, max( 0, (int) $up ), Licensio_KB_Feedback::VOTE_UP ),
            array_fill( 0, max( 0, (int) $down ), Licensio_KB_Feedback::VOTE_DOWN )
        );

        foreach ( $votes as $i => $vote ) {
            $rows[] = array(
                'article_id'   => (int) $article_id,
                'vote'         => (int) $vote,
                'comment'      => '',
                'visitor_hash' => hash( 'sha256', 'vote|' . $article_id . '|' . $days_ago . '|' . $i ),
                'status'       => 'new',
                'created_at'   => $this->days_ago( (int) $days_ago ),
            );
        }

        $this->seed( 'licensio_feedback', $rows );
    }

    /**
     * The term taxonomy ID of a term.
     *
     * @since 1.0.0
     *
     * @param int $term_id The term ID.
     *
     * @return int The term taxonomy ID.
     */
    private function term_taxonomy_id( $term_id ) {
        $result = $this->db->query( 'SELECT term_taxonomy_id FROM ' . $this->table( 'term_taxonomy' ) . ' WHERE term_id = ' . (int) $term_id );

        $this->assertInstanceOf( 'mysqli_result', $result, $this->db->error );

        $row = $result->fetch_object();

        $this->assertNotNull( $row, 'No term ' . (int) $term_id . ' to assign. Make it first.' );

        return (int) $row->term_taxonomy_id;
    }
}
