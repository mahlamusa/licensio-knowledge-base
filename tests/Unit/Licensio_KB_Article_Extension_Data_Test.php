<?php
/**
 * The extension column data of the Articles list rows.
 *
 * @package Licensio_KB
 * @since   1.1.0
 */

use Brain\Monkey\Filters;

/**
 * Drives Licensio_KB_Article_Extension_Data.
 *
 * @since 1.1.0
 */
class Licensio_KB_Article_Extension_Data_Test extends Licensio_KB_Test_Case {

    /**
     * Without a shown column or an article, no filter runs.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_nothing_is_asked_without_columns_or_articles() {
        Filters\expectApplied( 'licensio_kb_article_extension_data_batch' )->never();
        Filters\expectApplied( 'licensio_kb_article_extension_data' )->never();

        $this->assertSame( array(), Licensio_KB_Article_Extension_Data::for_posts( array( new WP_Post( array( 'ID' => 4 ) ) ), array() ) );
        $this->assertSame( array(), Licensio_KB_Article_Extension_Data::for_posts( array(), array( 'acme/score' ) ) );
    }

    /**
     * The batch filter can fill every row on its own, once for the page.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_the_batch_filter_fills_the_page_once() {
        Filters\expectApplied( 'licensio_kb_article_extension_data_batch' )
            ->once()
            ->andReturn(
                array(
                    4 => array( 'acme/score' => array( 'text' => '10' ) ),
                    5 => array( 'acme/score' => array( 'text' => '20' ) ),
                )
            );

        $this->assertSame(
            array(
                4 => array( 'acme/score' => array( 'text' => '10' ) ),
                5 => array( 'acme/score' => array( 'text' => '20' ) ),
                6 => array(),
            ),
            Licensio_KB_Article_Extension_Data::for_posts(
                array( new WP_Post( array( 'ID' => 4 ) ), new WP_Post( array( 'ID' => 5 ) ), new WP_Post( array( 'ID' => 6 ) ) ),
                array( 'acme/score' )
            )
        );
    }

    /**
     * Data that is not an array, or for a column not asked for, is dropped.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_malformed_data_is_dropped() {
        Filters\expectApplied( 'licensio_kb_article_extension_data_batch' )->once()->andReturn( 'junk' );
        Filters\expectApplied( 'licensio_kb_article_extension_data' )
            ->twice()
            ->andReturnValues(
                array(
                    array(
                        'acme/score' => 7,
                        'acme/other' => array( 'text' => 'x' ),
                        'acme/note'  => array( 'text' => 'kept' ),
                    ),
                    'junk',
                )
            );

        $this->assertSame(
            array(
                4 => array( 'acme/note' => array( 'text' => 'kept' ) ),
                5 => array(),
            ),
            Licensio_KB_Article_Extension_Data::for_posts(
                array( new WP_Post( array( 'ID' => 4 ) ), new WP_Post( array( 'ID' => 5 ) ) ),
                array( 'acme/score', 'acme/note' )
            )
        );
    }
}
