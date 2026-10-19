<?php
/**
 * Unit tests for the shared relevance function.
 *
 * The weighting lives in one method that both drivers agree on: the LIKE driver
 * calls it, and the FULLTEXT driver reproduces its arithmetic in SQL. These
 * tests pin the arithmetic itself. Whether MySQL really orders by it is asserted
 * in the integration suite, because that is a question about a server.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Filters;

/**
 * Tests the per-field relevance weighting.
 *
 * @since 1.0.0
 */
class Licensio_KB_Search_Ranking_Test extends Licensio_KB_Test_Case {

    /**
     * A title match scores three times a body match.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_title_match_outscores_a_tag_match_outscores_a_body_match() {
        $tokens = array( 'licence' );

        $title = Licensio_KB_Search_Driver::score( $tokens, 'Licence guide', 'Support', 'Nothing here.' );
        $terms = Licensio_KB_Search_Driver::score( $tokens, 'Activation guide', 'Licence', 'Nothing here.' );
        $body  = Licensio_KB_Search_Driver::score( $tokens, 'Activation guide', 'Support', 'The licence terms.' );

        $this->assertSame( 3.0, $title );
        $this->assertSame( 2.0, $terms );
        $this->assertSame( 1.0, $body );

        $this->assertGreaterThan( $terms, $title );
        $this->assertGreaterThan( $body, $terms );
    }

    /**
     * A row matching in every field scores the sum of the weights.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_matching_every_field_sums_the_weights() {
        $this->assertSame(
            6.0,
            Licensio_KB_Search_Driver::score( array( 'licence' ), 'Licence', 'Licence', 'licence' )
        );
    }

    /**
     * Each token contributes independently.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_each_token_scores_separately() {
        $this->assertSame(
            4.0,
            Licensio_KB_Search_Driver::score( array( 'licence', 'server' ), 'Licence notes', 'None', 'The server is here.' )
        );
    }

    /**
     * Matching is case-insensitive, as the database collation is.
     *
     * A ranking that folded case in SQL but not in PHP would order the same rows
     * differently on the two drivers.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_matching_ignores_case() {
        $this->assertSame(
            3.0,
            Licensio_KB_Search_Driver::score( array( 'licence' ), 'LICENCE GUIDE', '', '' )
        );
    }

    /**
     * A row that matches nothing scores zero.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_no_match_scores_zero() {
        $this->assertSame(
            0.0,
            Licensio_KB_Search_Driver::score( array( 'licence' ), 'Deployment', 'Hosting', 'Nothing relevant.' )
        );
    }

    /**
     * An empty field never matches, even an empty token.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_empty_values_never_match() {
        $this->assertSame( 0.0, Licensio_KB_Search_Driver::score( array( '' ), 'Licence', 'Licence', 'Licence' ) );
        $this->assertSame( 0.0, Licensio_KB_Search_Driver::score( array( 'licence' ), '', '', '' ) );
    }

    /**
     * The weights are filterable, and the filter governs both drivers.
     *
     * Driven through `expectApplied()` rather than by adding a callback: Brain
     * Monkey does not invoke callbacks registered with `add_filter()`, so a test
     * written that way would pass even if the production code never consulted
     * the filter at all.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_weights_are_filterable() {
        Filters\expectApplied( 'licensio_search_weights' )
            ->once()
            ->andReturn(
                array(
                    'title'   => 10,
                    'terms'   => 1,
                    'content' => 1,
                )
            );

        $this->assertSame(
            10.0,
            Licensio_KB_Search_Driver::score( array( 'licence' ), 'Licence', '', '' )
        );
    }

    /**
     * A filter that returns nonsense leaves the shipped weights in place.
     *
     * The weights are multiplied into raw SQL by the FULLTEXT driver, so a
     * filter returning a string, or dropping a key, must not be able to produce
     * a malformed expression.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_broken_weight_filter_is_ignored() {
        Filters\expectApplied( 'licensio_search_weights' )
            ->once()
            ->andReturn( array( 'title' => 'lots' ) );

        $weights = Licensio_KB_Search_Driver::weights();

        $this->assertSame( Licensio_KB_Search_Driver::WEIGHT_TITLE, $weights['title'] );
        $this->assertSame( Licensio_KB_Search_Driver::WEIGHT_TERMS, $weights['terms'] );
        $this->assertSame( Licensio_KB_Search_Driver::WEIGHT_CONTENT, $weights['content'] );
    }

    /**
     * The shipped weights are the ones the specification asks for.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_shipped_weights_are_three_two_one() {
        $this->assertSame( 3, Licensio_KB_Search_Driver::WEIGHT_TITLE );
        $this->assertSame( 2, Licensio_KB_Search_Driver::WEIGHT_TERMS );
        $this->assertSame( 1, Licensio_KB_Search_Driver::WEIGHT_CONTENT );
    }
}
