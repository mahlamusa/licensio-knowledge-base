<?php
/**
 * Unit tests for the search query facade.
 *
 * Everything here is the part of search that has no database in it: what a
 * visitor's raw input is reduced to, what boolean expression that becomes, and
 * which driver ends up answering. Ranking itself is asserted in the integration
 * suite, against a real server, because no double can say whether MySQL really
 * ordered a title match above a body match.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Tests term sanitising, tokenising, boolean expressions and driver choice.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Search_Query_Test extends Lisensio_KB_Test_Case {

    /**
     * Stub what the facade reads outside itself.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        Functions\when( 'wp_strip_all_tags' )->alias(
            function ( $value ) {
                return strip_tags( (string) $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
            }
        );
    }

    /**
     * Every FULLTEXT boolean operator is removed from visitor input.
     *
     * Unstripped, `-` deletes results, `*` in the wrong place is a syntax error
     * on a public endpoint, and `"` opens a phrase that never closes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_boolean_operators_are_stripped() {
        $term = Lisensio_KB_Search_Query::sanitize_term( '+licence -activation >server <client (group) ~near *star "phrase" @dist' );

        foreach ( Lisensio_KB_Search_Query::BOOLEAN_OPERATORS as $operator ) {
            $this->assertStringNotContainsString( $operator, $term, sprintf( 'The %s operator survived sanitising.', $operator ) );
        }

        $this->assertSame( 'licence activation server client group near star phrase dist', $term );
    }

    /**
     * Angle brackets are treated as operators, not as tag delimiters.
     *
     * The two characters are both. Stripping markup first made `a < b > c` lose
     * everything between them, because `strip_tags()` read `< b >` as a tag.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_text_between_angle_brackets_survives() {
        $this->assertSame(
            'a b c',
            Lisensio_KB_Search_Query::sanitize_term( 'a < b > c' )
        );
    }

    /**
     * Markup is reduced to its words, and no tag survives.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_markup_never_survives_into_the_term() {
        $term = Lisensio_KB_Search_Query::sanitize_term( '<script>alert(1)</script> licence' );

        $this->assertStringNotContainsString( '<', $term );
        $this->assertStringNotContainsString( '>', $term );
        $this->assertStringContainsString( 'licence', $term );
    }

    /**
     * The term is cut to the width of the column it is logged in.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_term_is_truncated_to_the_column_width() {
        $term = Lisensio_KB_Search_Query::sanitize_term( str_repeat( 'a', 500 ) );

        $this->assertSame( Lisensio_KB_Search_Query::MAX_TERM_LENGTH, strlen( $term ) );
    }

    /**
     * Tokens are alphanumeric runs, deduplicated and capped.
     *
     * The tokens are embedded in LIKE patterns, so anything that could act as a
     * wildcard must not survive tokenising.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_tokens_are_alphanumeric_and_deduplicated() {
        $this->assertSame(
            array( 'licence', 'server' ),
            Lisensio_KB_Search_Query::tokenize( 'licence server licence' )
        );

        $this->assertSame(
            array( '100', 'percent' ),
            Lisensio_KB_Search_Query::tokenize( '100% percent' )
        );

        $this->assertSame(
            array( 'a_b' ),
            Lisensio_KB_Search_Query::tokenize( 'a_b' )
        );
    }

    /**
     * A very long query does not become an unbounded SQL expression.
     *
     * Each token adds three LIKE comparisons to the FULLTEXT driver's score
     * expression, so an uncapped token list is a way for an anonymous caller to
     * make the server do arbitrary work.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_token_list_is_capped() {
        $words  = array();
        $expect = Lisensio_KB_Search_Query::MAX_TOKENS;

        for ( $i = 0; $i < 40; $i++ ) {
            $words[] = 'word' . $i;
        }

        $this->assertCount( $expect, Lisensio_KB_Search_Query::tokenize( implode( ' ', $words ) ) );
    }

    /**
     * Non-Latin text still produces tokens.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_non_latin_text_tokenizes() {
        $this->assertSame( array( 'lizenz', 'schlüssel' ), Lisensio_KB_Search_Query::tokenize( 'Lizenz schlüssel' ) );
        $this->assertSame( array( 'licence' ), Lisensio_KB_Search_Query::tokenize( 'Licence licence LICENCE' ) );
    }

    /**
     * Every token is required, and only the last is prefix-expanded.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_only_the_final_token_is_prefix_expanded() {
        $this->assertSame(
            '+licence +server +activ*',
            Lisensio_KB_Search_Query::boolean_query( array( 'licence', 'server', 'activ' ) )
        );

        $this->assertSame( '+licence*', Lisensio_KB_Search_Query::boolean_query( array( 'licence' ) ) );
    }

    /**
     * Tokens below the server's minimum indexed length are left out.
     *
     * A `+ab` term makes the whole boolean query match nothing on a default
     * InnoDB install, which would turn a search for "ab testing" into a search
     * that finds nothing at all.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_short_tokens_are_not_required() {
        $this->assertSame(
            '+testing*',
            Lisensio_KB_Search_Query::boolean_query( array( 'ab', 'testing' ) )
        );

        $this->assertSame( '', Lisensio_KB_Search_Query::boolean_query( array( 'ab', 'cd' ) ) );
    }

    /**
     * A token carrying an operator never reaches the boolean expression.
     *
     * The boolean-mode string is the only value in the plugin that MySQL parses
     * as an expression rather than binding as a value. The drivers are public,
     * so a caller can hand one a token list that never went through
     * sanitize_term(), and the expression builder has to hold that on its own.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_hostile_token_never_reaches_the_boolean_expression() {
        $this->assertSame(
            '+licence*',
            Lisensio_KB_Search_Query::boolean_query( array( '-activation', 'licence' ) )
        );

        $this->assertSame(
            '',
            Lisensio_KB_Search_Query::boolean_query( array( '"phrase"', '(group)', '~near' ) )
        );
    }

    /**
     * The minimum token length is filterable, for MyISAM's higher floor.
     *
     * Driven through the filter rather than by registering a callback, because
     * Brain Monkey does not run callbacks added with `add_filter()` — a test
     * that registered one and asserted the result would pass whether or not the
     * production code ever called `apply_filters()`.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_minimum_token_length_is_filterable() {
        Filters\expectApplied( 'lisensio_search_min_token_length' )
            ->once()
            ->with( Lisensio_KB_Search_Query::MIN_FULLTEXT_TOKEN_LENGTH )
            ->andReturn( 8 );

        $this->assertSame( '', Lisensio_KB_Search_Query::boolean_query( array( 'licence' ) ) );
    }

    /**
     * A recorded FULLTEXT index selects the FULLTEXT driver.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_fulltext_install_uses_the_fulltext_driver() {
        $this->options[ Lisensio_KB_DB_Installer::FULLTEXT_OPTION ] = 'yes';

        $driver = ( new Lisensio_KB_Search_Query() )->driver( array( 'licence' ) );

        $this->assertInstanceOf( 'Lisensio_KB_Search_Fulltext_Driver', $driver );
        $this->assertSame( 'fulltext', $driver->name() );
    }

    /**
     * No recorded FULLTEXT index selects the LIKE driver.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_install_without_fulltext_uses_the_like_driver() {
        $this->options[ Lisensio_KB_DB_Installer::FULLTEXT_OPTION ] = 'no';

        $driver = ( new Lisensio_KB_Search_Query() )->driver( array( 'licence' ) );

        $this->assertInstanceOf( 'Lisensio_KB_Search_Like_Driver', $driver );
        $this->assertSame( 'like', $driver->name() );
    }

    /**
     * The installer's filter can force the fallback at runtime.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_fulltext_filter_forces_the_fallback() {
        $this->options[ Lisensio_KB_DB_Installer::FULLTEXT_OPTION ] = 'yes';

        Filters\expectApplied( 'lisensio_supports_fulltext' )
            ->once()
            ->with( true )
            ->andReturn( false );

        $this->assertInstanceOf(
            'Lisensio_KB_Search_Like_Driver',
            ( new Lisensio_KB_Search_Query() )->driver( array( 'licence' ) )
        );
    }

    /**
     * A query FULLTEXT cannot express falls back for that query alone.
     *
     * Every token is below the indexed minimum, so the boolean expression would
     * be empty and the FULLTEXT driver would return nothing at all.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_query_of_only_short_words_falls_back() {
        $this->options[ Lisensio_KB_DB_Installer::FULLTEXT_OPTION ] = 'yes';

        $this->assertInstanceOf(
            'Lisensio_KB_Search_Like_Driver',
            ( new Lisensio_KB_Search_Query() )->driver( array( 'ab' ) )
        );
    }

    /**
     * A pinned driver overrides the automatic choice.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_pinned_driver_wins() {
        $this->options[ Lisensio_KB_DB_Installer::FULLTEXT_OPTION ] = 'yes';

        $query = new Lisensio_KB_Search_Query();
        $query->set_driver( new Lisensio_KB_Search_Like_Driver() );

        $this->assertInstanceOf( 'Lisensio_KB_Search_Like_Driver', $query->driver( array( 'licence' ) ) );

        $query->set_driver( null );

        $this->assertInstanceOf( 'Lisensio_KB_Search_Fulltext_Driver', $query->driver( array( 'licence' ) ) );
    }

    /**
     * An empty term never reaches a driver.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_empty_term_short_circuits_before_the_database() {
        $driver = new Lisensio_KB_Search_Recording_Driver();
        $query  = new Lisensio_KB_Search_Query();
        $query->set_driver( $driver );

        $found = $query->search( '   +++   ' );

        $this->assertFalse( $driver->called, 'An empty term must never reach a driver.' );
        $this->assertSame( array(), $found['ids'] );
        $this->assertSame( 0, $found['total'] );
        $this->assertSame( 'none', $found['driver'] );
        $this->assertSame( '', $found['term'] );
    }

    /**
     * The result of a search is filterable.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_result_is_filterable() {
        $query = new Lisensio_KB_Search_Query();
        $query->set_driver( new Lisensio_KB_Search_Fake_Driver( array( 7, 8 ), 2 ) );

        Filters\expectApplied( 'lisensio_search_results' )->once();

        $found = $query->search( 'licence' );

        $this->assertSame( array( 7, 8 ), $found['ids'] );
        $this->assertSame( 2, $found['total'] );
        $this->assertSame( 'fake', $found['driver'] );
    }
}

/**
 * A driver that records whether anything asked it to search.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Search_Recording_Driver extends Lisensio_KB_Search_Driver {

    /**
     * Whether search() was called.
     *
     * @since 1.0.0
     * @var bool
     */
    public $called = false;

    /**
     * The driver's name.
     *
     * @since 1.0.0
     *
     * @return string The name.
     */
    public function name() {
        return 'recording';
    }

    /**
     * Record the call and answer with a result nothing should ever see.
     *
     * @since 1.0.0
     *
     * @param string[]             $tokens The tokens.
     * @param array<string, mixed> $args   The arguments.
     *
     * @return array{ids: int[], total: int} A result that would be visibly wrong.
     */
    public function search( array $tokens, array $args = array() ) {
        unset( $tokens, $args );

        $this->called = true;

        return array(
            'ids'   => array( 999 ),
            'total' => 1,
        );
    }
}

/**
 * A driver that returns a canned result.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Search_Fake_Driver extends Lisensio_KB_Search_Driver {

    /**
     * The IDs to return.
     *
     * @since 1.0.0
     * @var int[]
     */
    private $ids;

    /**
     * The total to report.
     *
     * @since 1.0.0
     * @var int
     */
    private $total;

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param int[] $ids   The IDs to return.
     * @param int   $total The total to report.
     */
    public function __construct( array $ids, $total ) {
        $this->ids   = $ids;
        $this->total = (int) $total;
    }

    /**
     * The driver's name.
     *
     * @since 1.0.0
     *
     * @return string The name.
     */
    public function name() {
        return 'fake';
    }

    /**
     * Return the canned result.
     *
     * @since 1.0.0
     *
     * @param string[]             $tokens The tokens.
     * @param array<string, mixed> $args   The arguments.
     *
     * @return array{ids: int[], total: int} The canned result.
     */
    public function search( array $tokens, array $args = array() ) {
        unset( $tokens, $args );

        return array(
            'ids'   => $this->ids,
            'total' => $this->total,
        );
    }
}
