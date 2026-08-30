<?php
/**
 * Shared unit test case.
 *
 * Boots Brain Monkey and backs the WordPress options API with an in-memory
 * store so the plugin's option-driven helpers can be exercised without a
 * database or a WordPress installation.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Base class for the plugin's unit tests.
 *
 * @since 1.0.0
 */
abstract class Lisensio_KB_Test_Case extends TestCase {

    /**
     * The in-memory options table.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    protected $options = array();

    /**
     * Every value written through update_option(), in order.
     *
     * Lets a test assert on everything the code under test tried to persist.
     *
     * @since 1.0.0
     * @var array<int, array{name: string, value: mixed}>
     */
    protected $writes = array();

    /**
     * Boot Brain Monkey and the option store.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->options = array();
        $this->writes  = array();

        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();

        Functions\when( 'get_option' )->alias(
            function ( $name, $fallback = false ) {
                return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
            }
        );

        /*
         * Mimics core: update_option() returns false when the new value equals
         * the stored one. A double that always returned true structurally hid
         * the fact that Settings::set() reported a no-op save as a failure.
         */
        Functions\when( 'update_option' )->alias(
            function ( $name, $value ) {
                $this->writes[] = array(
                    'name'  => $name,
                    'value' => $value,
                );

                if ( array_key_exists( $name, $this->options ) && $this->options[ $name ] === $value ) {
                    return false;
                }

                $this->options[ $name ] = $value;

                return true;
            }
        );

        /*
         * Mimics core: add_option() refuses to overwrite an existing option and
         * returns false. The upgrade lock relies on exactly that atomicity, so a
         * double that always returned true would let both racers proceed and
         * certify a lock that does not lock.
         */
        Functions\when( 'add_option' )->alias(
            function ( $name, $value = '', $deprecated = '', $autoload = true ) {
                unset( $deprecated, $autoload );

                if ( array_key_exists( $name, $this->options ) ) {
                    return false;
                }

                $this->options[ $name ] = $value;

                return true;
            }
        );

        Functions\when( 'delete_option' )->alias(
            function ( $name ) {
                unset( $this->options[ $name ] );

                return true;
            }
        );

        Functions\when( 'sanitize_text_field' )->alias(
            function ( $value ) {
                return is_string( $value ) ? trim( strip_tags( $value ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
            }
        );

        Functions\when( 'wp_unslash' )->alias(
            function ( $value ) {
                return is_string( $value ) ? stripslashes( $value ) : $value;
            }
        );

        Functions\when( 'wp_generate_password' )->alias(
            function ( $length = 12 ) {
                return substr( str_repeat( 'a', (int) $length ), 0, (int) $length );
            }
        );

        Functions\when( 'sanitize_title' )->alias(
            function ( $value ) {
                $value = strtolower( wp_strip_all_tags( (string) $value ) );
                $value = preg_replace( '/[^a-z0-9_-]+/', '-', $value );

                return trim( (string) $value, '-' );
            }
        );

        Functions\when( 'wp_strip_all_tags' )->alias(
            function ( $value ) {
                return strip_tags( (string) $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
            }
        );

        Functions\when( 'sanitize_hex_color' )->alias(
            function ( $value ) {
                return preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', (string) $value ) ? (string) $value : null;
            }
        );

        Functions\when( 'is_multisite' )->justReturn( false );

        Functions\when( 'is_admin' )->justReturn( false );

        Functions\when( 'wp_json_encode' )->alias(
            function ( $value ) {
                return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
            }
        );

        Functions\when( 'trailingslashit' )->alias(
            function ( $value ) {
                return rtrim( (string) $value, '/\\' ) . '/';
            }
        );
    }

    /**
     * Tear Brain Monkey down.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function tearDown(): void {
        /*
         * Brain Monkey expectations are Mockery expectations, and PHPUnit cannot
         * see them. Without this, a test whose whole point is an expectation —
         * "this hook is registered", "this function is never called" — is
         * reported as risky under `beStrictAboutTestsThatDoNotTestAnything`, and
         * the honest fix of adding a filler assertion would hide that the
         * expectation is the assertion.
         */
        if ( class_exists( 'Mockery' ) ) {
            $this->addToAssertionCount( Mockery::getContainer()->mockery_getExpectationCount() );
        }

        Monkey\tearDown();
        parent::tearDown();
    }
}
