<?php
/**
 * The extension column data of the Categories and Tags apps' rows.
 *
 * @package Licensio_KB
 * @since   1.1.0
 */

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Drives Licensio_KB_Term_Extension_Data.
 *
 * @since 1.1.0
 */
class Licensio_KB_Term_Extension_Data_Test extends Licensio_KB_Test_Case {

    /**
     * The registry the data reads the columns from.
     *
     * @since 1.1.0
     * @var Licensio_KB_Extensions
     */
    private $registry;

    /**
     * The capabilities the current user holds.
     *
     * @since 1.1.0
     * @var string[]
     */
    private $caps = array();

    /**
     * The term IDs each `_prime_term_caches()` call was given.
     *
     * @since 1.1.0
     * @var array<int, int[]>
     */
    private $primed = array();

    /**
     * Stub the registry's and the term loading's core functions.
     *
     * @since 1.1.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->caps     = array( 'manage_licensio_kb' );
        $this->primed   = array();
        $this->registry = new Licensio_KB_Extensions();

        Functions\when( 'current_user_can' )->alias(
            function ( $capability ) {
                return in_array( $capability, $this->caps, true );
            }
        );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'absint' )->alias(
            function ( $value ) {
                return abs( (int) $value );
            }
        );
        Functions\when( '_prime_term_caches' )->alias(
            function ( $ids, $update_meta ) {
                $this->assertFalse( $update_meta );

                $this->primed[] = $ids;
            }
        );
        Functions\when( 'get_term' )->alias(
            function ( $id, $taxonomy ) {
                $this->assertSame( 'licensio_tag', $taxonomy );

                // Term 9 was deleted between the list query and this read.
                return 9 === $id ? null : new WP_Term(
                    array(
                        'term_id'  => $id,
                        'name'     => 'Tag ' . $id,
                        'taxonomy' => $taxonomy,
                    )
                );
            }
        );
    }

    /**
     * Two tag rows and one whose term no longer exists.
     *
     * @since 1.1.0
     *
     * @return array<int, array<string, mixed>> The rows.
     */
    private function rows() {
        return array(
            array(
                'id'   => 3,
                'name' => 'Tag 3',
            ),
            array(
                'id'   => 7,
                'name' => 'Tag 7',
            ),
            array(
                'id'   => 9,
                'name' => 'Tag 9',
            ),
        );
    }

    /**
     * The data under test.
     *
     * @since 1.1.0
     *
     * @return Licensio_KB_Term_Extension_Data The term extension data.
     */
    private function data() {
        return new Licensio_KB_Term_Extension_Data( $this->registry );
    }

    /**
     * Only the default visible columns the user may see are requested.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_the_visible_columns_are_the_allowed_default_visible_ones() {
        $this->registry->add_column(
            'tags',
            'acme/score',
            array(
                'label'           => 'Score',
                'default_visible' => true,
            )
        );
        $this->registry->add_column( 'tags', 'acme/hidden', array( 'label' => 'Hidden' ) );
        $this->registry->add_column(
            'tags',
            'acme/secret',
            array(
                'label'           => 'Secret',
                'default_visible' => true,
                'capability'      => 'manage_options',
            )
        );
        $this->registry->add_column(
            'categories',
            'acme/other',
            array(
                'label'           => 'Other screen',
                'default_visible' => true,
            )
        );

        $this->assertSame( array( 'acme/score' ), $this->data()->column_keys( 'tags' ) );
    }

    /**
     * With no visible column the rows are untouched and no filter runs.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_rows_are_left_alone_when_no_column_is_visible() {
        $this->registry->add_column( 'tags', 'acme/hidden', array( 'label' => 'Hidden' ) );

        Filters\expectApplied( 'licensio_kb_term_extension_data_batch' )->never();
        Filters\expectApplied( 'licensio_kb_term_extension_data' )->never();

        $this->assertSame( $this->rows(), $this->data()->add( 'tags', 'licensio_tag', $this->rows() ) );
        $this->assertSame( array(), $this->primed );
    }

    /**
     * The batch filter runs once with every term, keyed by ID, and seeds each row's filter.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_the_batch_runs_once_and_seeds_each_row() {
        $this->registry->add_column(
            'tags',
            'acme/score',
            array(
                'label'           => 'Score',
                'default_visible' => true,
            )
        );

        Filters\expectApplied( 'licensio_kb_term_extension_data_batch' )
            ->once()
            ->andReturnUsing(
                function ( $data, $terms, $keys ) {
                    $this->assertSame( array(), $data );
                    $this->assertSame( array( 3, 7 ), array_keys( $terms ) );
                    $this->assertSame( 7, $terms[7]->term_id );
                    $this->assertSame( array( 'acme/score' ), $keys );

                    return array(
                        3 => array( 'acme/score' => array( 'text' => 'Good' ) ),
                    );
                }
            );

        $seen = array();

        Filters\expectApplied( 'licensio_kb_term_extension_data' )
            ->twice()
            ->andReturnUsing(
                function ( $cells, $term, $keys ) use ( &$seen ) {
                    $seen[ $term->term_id ] = $cells;

                    $this->assertSame( array( 'acme/score' ), $keys );

                    return 7 === $term->term_id ? array( 'acme/score' => array( 'text' => 'Poor' ) ) : $cells;
                }
            );

        $rows = $this->data()->add( 'tags', 'licensio_tag', $this->rows() );

        $this->assertSame( array( array( 3, 7, 9 ) ), $this->primed, 'Every term is loaded in one query.' );
        $this->assertSame(
            array(
                3 => array( 'acme/score' => array( 'text' => 'Good' ) ),
                7 => array(),
            ),
            $seen,
            'Each row starts from its part of the batch.'
        );
        $this->assertEquals( (object) array( 'acme/score' => array( 'text' => 'Good' ) ), $rows[0]['extensions'] );
        $this->assertEquals( (object) array( 'acme/score' => array( 'text' => 'Poor' ) ), $rows[1]['extensions'] );
        $this->assertEquals( new stdClass(), $rows[2]['extensions'], 'A missing term gets an empty object, which JSON encodes as {}.' );
        $this->assertSame( 'Tag 3', $rows[0]['name'], 'The row keeps its own fields.' );
    }

    /**
     * Undeclared keys and non-array cells are dropped, and a cell's text is a string.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_the_cells_are_cleaned() {
        $this->registry->add_column(
            'tags',
            'acme/score',
            array(
                'label'           => 'Score',
                'default_visible' => true,
            )
        );
        $this->registry->add_column(
            'tags',
            'acme/count',
            array(
                'label'           => 'Count',
                'default_visible' => true,
            )
        );
        $this->registry->add_column(
            'tags',
            'acme/flag',
            array(
                'label'           => 'Flag',
                'default_visible' => true,
            )
        );

        Filters\expectApplied( 'licensio_kb_term_extension_data_batch' )->once()->andReturn( 'not an array' );
        Filters\expectApplied( 'licensio_kb_term_extension_data' )->andReturn(
            array(
                'acme/score'      => array(
                    'text'  => 42,
                    'score' => 42,
                ),
                'acme/count'      => 'not a cell',
                'acme/flag'       => array( 'text' => array( 'nested' ) ),
                'acme/undeclared' => array( 'text' => 'Dropped' ),
            )
        );

        $rows = $this->data()->add( 'tags', 'licensio_tag', array( array( 'id' => 3 ) ) );

        $this->assertEquals(
            (object) array(
                'acme/score' => array(
                    'text'  => '42',
                    'score' => 42,
                ),
                'acme/flag'  => array( 'text' => '' ),
            ),
            $rows[0]['extensions']
        );
    }

    /**
     * An empty page fires nothing.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_an_empty_page_fires_nothing() {
        $this->registry->add_column(
            'tags',
            'acme/score',
            array(
                'label'           => 'Score',
                'default_visible' => true,
            )
        );

        Filters\expectApplied( 'licensio_kb_term_extension_data_batch' )->never();

        $this->assertSame( array(), $this->data()->add( 'tags', 'licensio_tag', array() ) );
    }
}
