<?php
/**
 * The articles controller with WP_Query answered by core's SQL.
 *
 * Used by the articles REST integration suite, which has no WordPress to run
 * WP_Query. See that suite's file docblock for what this proves and what it
 * does not.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/*
 * This double queries a real database on purpose. The WordPress Database API
 * is therefore not available or appropriate here.
 */
// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery

use PHPUnit\Framework\Assert;

/**
 * The articles controller with WP_Query answered by core's SQL on the scratch tables.
 *
 * @since 1.0.0
 */
class Licensio_KB_Articles_REST_Live_Controller extends Licensio_KB_Articles_REST_Controller {

    /**
     * The arguments the translator understands. Anything else fails the test.
     *
     * @since 1.0.0
     * @var string[]
     */
    const KNOWN_ARGS = array(
        'post_type',
        'post_status',
        'perm',
        'ignore_sticky_posts',
        'suppress_filters',
        'author',
        'tax_query',
        'date_query',
        'fields',
        'posts_per_page',
        'paged',
        'orderby',
        'update_post_term_cache',
        'update_post_meta_cache',
    );

    /**
     * The order columns core uses for each `orderby` key.
     *
     * @since 1.0.0
     * @var array<string, string>
     */
    const COLUMNS = array(
        'date'     => 'post_date',
        'modified' => 'post_modified',
        'title'    => 'post_title',
        'ID'       => 'ID',
    );

    /**
     * The live connection.
     *
     * @since 1.0.0
     * @var mysqli
     */
    private $db;

    /**
     * The scratch table prefix.
     *
     * @since 1.0.0
     * @var string
     */
    private $prefix;

    /**
     * How many queries have run.
     *
     * @since 1.0.0
     * @var int
     */
    public $queries = 0;

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param mysqli $db     The live connection.
     * @param string $prefix The scratch table prefix.
     */
    public function __construct( $db, $prefix ) {
        parent::__construct( new Licensio_KB_Article_Metrics( 50, 5 ), new Licensio_KB_Article_Bulk() );

        $this->db     = $db;
        $this->prefix = (string) $prefix;
    }

    /**
     * The list items: just the IDs, in order.
     *
     * @since 1.0.0
     *
     * @param WP_Post[] $posts  The posts.
     * @param int       $window The metrics window, in days. Unused.
     *
     * @return array<int, mixed> The IDs.
     */
    public function prepare_items( array $posts, $window ) {
        unset( $window );

        return array_map(
            function ( $post ) {
                return (int) $post->ID;
            },
            $posts
        );
    }

    /**
     * Answer a query the way WP_Query would, with core's SQL.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $args The query arguments.
     *
     * @return WP_Query The finished query.
     */
    protected function run_query( array $args ) {
        $unknown = array_diff( array_keys( $args ), self::KNOWN_ARGS );

        if ( $unknown ) {
            Assert::fail( 'The test translator does not know these query arguments: ' . implode( ', ', $unknown ) );
        }

        Assert::assertSame( 'readable', $args['perm'] );

        ++$this->queries;

        $posts = $this->prefix . 'posts';
        $where = array(
            "{$posts}.post_type = " . $this->quote( $args['post_type'] ),
            "{$posts}.post_status IN (" . implode( ', ', array_map( array( $this, 'quote' ), (array) $args['post_status'] ) ) . ')',
        );

        if ( isset( $args['author'] ) ) {
            $where[] = "{$posts}.post_author IN (" . (int) $args['author'] . ')';
        }

        if ( isset( $args['tax_query'] ) ) {
            $where = array_merge( $where, $this->tax_where( $args['tax_query'] ) );
        }

        if ( isset( $args['date_query'] ) ) {
            $where = array_merge( $where, $this->date_where( $args['date_query'] ) );
        }

        $order = array();

        foreach ( (array) $args['orderby'] as $key => $direction ) {
            Assert::assertArrayHasKey( $key, self::COLUMNS );
            Assert::assertContains( $direction, array( 'ASC', 'DESC' ) );

            $order[] = "{$posts}." . self::COLUMNS[ $key ] . ' ' . $direction;
        }

        $per_page = (int) $args['posts_per_page'];
        $offset   = ( (int) $args['paged'] - 1 ) * $per_page;
        $ids_only = isset( $args['fields'] ) && 'ids' === $args['fields'];
        $columns  = $ids_only ? "{$posts}.ID" : "{$posts}.*";
        $filter   = ' WHERE 1=1 AND ' . implode( ' AND ', $where );

        $rows  = $this->db->query( "SELECT {$columns} FROM {$posts}{$filter} ORDER BY " . implode( ', ', $order ) . " LIMIT {$offset}, {$per_page}" );
        $query = new WP_Query( $args );

        Assert::assertInstanceOf( 'mysqli_result', $rows, $this->db->error );

        while ( $row = $rows->fetch_assoc() ) {
            $query->posts[] = $ids_only ? (int) $row['ID'] : new WP_Post( array_merge( $row, array( 'ID' => (int) $row['ID'] ) ) );
        }

        // Core leaves found_posts at 0 when the page is empty.
        if ( $query->posts ) {
            $query->found_posts = (int) $this->db->query( "SELECT COUNT(*) AS total FROM {$posts}{$filter}" )->fetch_object()->total;
        }

        return $query;
    }

    /**
     * Core's WHERE parts for an AND tax query.
     *
     * A term ID clause matches the term and, with `include_children`, every
     * descendant at any depth, as `get_term_children()` does. NOT EXISTS is
     * core's correlated subquery on the taxonomy.
     *
     * @since 1.0.0
     *
     * @param array<int|string, mixed> $tax_query The tax query.
     *
     * @return string[] The WHERE parts.
     */
    private function tax_where( array $tax_query ) {
        Assert::assertSame( 'AND', $tax_query['relation'] );
        unset( $tax_query['relation'] );

        $posts         = $this->prefix . 'posts';
        $relationships = $this->prefix . 'term_relationships';
        $taxonomy      = $this->prefix . 'term_taxonomy';
        $where         = array();

        foreach ( $tax_query as $clause ) {
            if ( isset( $clause['operator'] ) ) {
                Assert::assertSame( 'NOT EXISTS', $clause['operator'] );

                $where[] = "NOT EXISTS ( SELECT 1 FROM {$relationships} INNER JOIN {$taxonomy} ON {$taxonomy}.term_taxonomy_id = {$relationships}.term_taxonomy_id WHERE {$taxonomy}.taxonomy = " . $this->quote( $clause['taxonomy'] ) . " AND {$relationships}.object_id = {$posts}.ID )";
                continue;
            }

            Assert::assertSame( 'term_id', $clause['field'] );

            $terms    = array_map( 'intval', (array) $clause['terms'] );
            $frontier = $terms;

            while ( $clause['include_children'] && $frontier ) {
                $rows     = $this->db->query( "SELECT term_id FROM {$taxonomy} WHERE taxonomy = " . $this->quote( $clause['taxonomy'] ) . ' AND parent IN (' . implode( ', ', $frontier ) . ')' );
                $frontier = array();

                while ( $row = $rows->fetch_object() ) {
                    if ( ! in_array( (int) $row->term_id, $terms, true ) ) {
                        $frontier[] = (int) $row->term_id;
                        $terms[]    = (int) $row->term_id;
                    }
                }
            }

            $where[] = "{$posts}.ID IN ( SELECT object_id FROM {$relationships} WHERE term_taxonomy_id IN ( SELECT term_taxonomy_id FROM {$taxonomy} WHERE taxonomy = " . $this->quote( $clause['taxonomy'] ) . ' AND term_id IN (' . implode( ', ', $terms ) . ') ) )';
        }

        return $where;
    }

    /**
     * Core's WHERE parts for an inclusive whole-day date query.
     *
     * An inclusive `after` day starts at midnight; an inclusive `before` day
     * runs to the last second.
     *
     * @since 1.0.0
     *
     * @param array<int, array<string, mixed>> $date_query The date query.
     *
     * @return string[] The WHERE parts.
     */
    private function date_where( array $date_query ) {
        Assert::assertCount( 1, $date_query );

        $clause = $date_query[0];
        $posts  = $this->prefix . 'posts';
        $where  = array();

        Assert::assertSame( 'post_date', $clause['column'] );
        Assert::assertTrue( $clause['inclusive'] );

        $bounds = array(
            'after'  => array( '>=', '00:00:00' ),
            'before' => array( '<=', '23:59:59' ),
        );

        foreach ( $bounds as $bound => $sql ) {
            if ( isset( $clause[ $bound ] ) ) {
                $day     = sprintf( '%04d-%02d-%02d', $clause[ $bound ]['year'], $clause[ $bound ]['month'], $clause[ $bound ]['day'] );
                $where[] = "{$posts}.post_date {$sql[0]} " . $this->quote( $day . ' ' . $sql[1] );
            }
        }

        return $where;
    }

    /**
     * Quote a string value for SQL.
     *
     * @since 1.0.0
     *
     * @param mixed $value The value.
     *
     * @return string The quoted value.
     */
    private function quote( $value ) {
        return "'" . $this->db->real_escape_string( (string) $value ) . "'";
    }
}
