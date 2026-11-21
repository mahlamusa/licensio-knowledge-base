<?php
/**
 * The extension filter values a request may carry.
 *
 * @package Licensio_KB
 * @since   1.1.0
 */

use Brain\Monkey\Functions;

/**
 * Drives Licensio_KB_Extension_Filters.
 *
 * @since 1.1.0
 */
class Licensio_KB_Extension_Filters_Test extends Licensio_KB_Test_Case {

    /**
     * The registry, with two article filters declared.
     *
     * @since 1.1.0
     * @var Licensio_KB_Extensions
     */
    private $extensions;

    /**
     * Declare an SEO filter everyone may use, a score filter only some may,
     * and a filter without a query argument.
     *
     * @since 1.1.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        Functions\when( 'current_user_can' )->alias(
            function ( $capability ) {
                return 'manage_seo' !== $capability;
            }
        );

        $this->extensions = new Licensio_KB_Extensions();

        $this->extensions->add_filter(
            'articles',
            'seo/status',
            array(
                'label'      => 'SEO',
                'query_arg'  => 'seo_filter',
                'capability' => 'edit_licensio_articles',
                'options'    => array(
                    'missing_meta' => 'Missing meta',
                    '1'            => 'One',
                ),
            )
        );
        $this->extensions->add_filter(
            'articles',
            'seo/score',
            array(
                'label'      => 'Score',
                'query_arg'  => 'seo_score',
                'capability' => 'manage_seo',
                'options'    => array( 'low' => 'Low' ),
            )
        );
        $this->extensions->add_filter(
            'articles',
            'example/plain',
            array(
                'label'      => 'Plain',
                'capability' => 'edit_licensio_articles',
                'options'    => array( 'yes' => 'Yes' ),
            )
        );
    }

    /**
     * Only declared filters the user may use, set to one of their options, are kept.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_only_allowed_filters_with_a_declared_option_are_kept() {
        $this->assertSame(
            array(
                'seo/status'    => 'missing_meta',
                'example/plain' => 'yes',
            ),
            Licensio_KB_Extension_Filters::sanitize(
                $this->extensions,
                'articles',
                array(
                    'seo/status'    => 'Missing_Meta',
                    'seo/score'     => 'low',
                    'example/plain' => 'yes',
                    'other/filter'  => 'yes',
                )
            ),
            'Values pass through sanitize_key(); undeclared and forbidden filters are dropped.'
        );

        $this->assertSame(
            array( 'seo/status' => '1' ),
            Licensio_KB_Extension_Filters::sanitize( $this->extensions, 'articles', array( 'seo/status' => 1 ) ),
            'A numeric option matches and comes back as a string.'
        );

        $this->assertSame(
            array(),
            Licensio_KB_Extension_Filters::sanitize(
                $this->extensions,
                'articles',
                array(
                    'seo/status'    => 'unknown',
                    'example/plain' => array( 'yes' ),
                )
            ),
            'Unknown options and non-scalar values are dropped.'
        );

        $this->assertSame( array(), Licensio_KB_Extension_Filters::sanitize( $this->extensions, 'articles', 'seo/status' ) );
        $this->assertSame( array(), Licensio_KB_Extension_Filters::sanitize( $this->extensions, 'tags', array( 'seo/status' => 'missing_meta' ) ), 'Another screen has its own filters.' );
    }

    /**
     * A core screen request's filter values are read from each filter's `query_arg`.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function test_query_args_map_onto_their_filters() {
        $this->assertSame(
            array( 'seo/status' => 'missing_meta' ),
            Licensio_KB_Extension_Filters::from_query_args(
                $this->extensions,
                'articles',
                array(
                    'seo_filter'    => 'missing_meta',
                    'seo_score'     => 'low',
                    'example/plain' => 'yes',
                    ''              => 'yes',
                )
            ),
            'A forbidden filter and one without a query argument are skipped.'
        );

        $this->assertSame( array(), Licensio_KB_Extension_Filters::from_query_args( $this->extensions, 'articles', array( 'seo_filter' => 'nope' ) ) );
    }
}
