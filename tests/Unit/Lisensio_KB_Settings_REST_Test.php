<?php
/**
 * The settings REST surface.
 *
 * The four behaviours the issue turns on — an unknown key is refused, an
 * unauthorised caller is refused, a bad value is refused per field, and a
 * payload with one bad value writes nothing at all — are each the difference
 * between this controller and the store it sits in front of. The store would
 * absorb every one of them silently, so most of these tests drive the
 * controller against Lisensio_KB_Settings_Spy, a store that does not sanitise,
 * and assert on what the controller asked it to write.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

use Brain\Monkey\Functions;

/**
 * Drives Lisensio_KB_REST_Settings_Controller.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Settings_REST_Test extends Lisensio_KB_Test_Case {

    /**
     * Every route registered during the test, keyed by route path.
     *
     * @since 1.0.0
     * @var array<string, array<int, array<string, mixed>>>
     */
    private $routes = array();

    /**
     * Boot the environment.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->routes = array();

        Functions\when( 'register_rest_route' )->alias(
            function ( $rest_namespace, $route, $args ) {
                unset( $rest_namespace );

                $this->routes[ $route ] = $args;

                return true;
            }
        );

        Functions\when( 'rest_ensure_response' )->alias(
            function ( $data ) {
                return $data;
            }
        );

        Functions\when( 'rest_authorization_required_code' )->justReturn( 403 );
    }

    /**
     * The registry a spy store reports.
     *
     * Deliberately not the plugin's own registry: a test that asserted against
     * the real one would break every time a sibling issue added a setting, and
     * would prove that this controller understands `brand_color` rather than
     * that it understands the registry.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> The registry.
     */
    private function registry() {
        return array(
            'a_number' => array(
                'type'    => 'int',
                'default' => 20,
                'min'     => 1,
                'max'     => 500,
            ),
            'a_flag'   => array(
                'type'    => 'bool',
                'default' => true,
            ),
            'a_colour' => array(
                'type'     => 'string',
                'default'  => '#2563eb',
                'sanitize' => array( 'Lisensio_KB_Settings', 'sanitize_brand_color' ),
            ),
            'a_slug'   => array(
                'type'           => 'string',
                'default'        => 'docs',
                'sanitize'       => array( 'Lisensio_KB_Settings', 'sanitize_slug' ),
                'flush_rewrites' => true,
            ),
        );
    }

    /**
     * A controller wired to a non-sanitising store.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $values The store's starting values.
     *
     * @return array{0: Lisensio_KB_REST_Settings_Controller, 1: Lisensio_KB_Settings_Spy} The controller and its store.
     */
    private function controller( array $values = array() ) {
        $spy = new Lisensio_KB_Settings_Spy( $this->registry(), $values );

        return array( new Lisensio_KB_REST_Settings_Controller( $spy ), $spy );
    }

    /**
     * A POST request carrying a settings payload.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $settings The submitted settings.
     *
     * @return WP_REST_Request The request.
     */
    private function post( array $settings ) {
        return new WP_REST_Request( array(), '/lisensio-kb/v1/settings', array( 'settings' => $settings ) );
    }

    /**
     * One route is registered, carrying all three methods.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_settings_route_is_registered() {
        list( $controller ) = $this->controller();

        $controller->register_routes();

        $this->assertSame( array( '/settings' ), array_keys( $this->routes ) );

        $methods = array();

        foreach ( $this->routes['/settings'] as $endpoint ) {
            $methods[] = $endpoint['methods'];
        }

        $this->assertSame( array( 'GET', 'POST', 'DELETE' ), $methods );
    }

    /**
     * Every method on the route is gated on the management capability.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_method_is_gated_on_the_management_callback() {
        list( $controller ) = $this->controller();

        $controller->register_routes();

        foreach ( $this->routes['/settings'] as $endpoint ) {
            $this->assertSame(
                array( $controller, 'manage_permissions_check' ),
                $endpoint['permission_callback'],
                sprintf( 'The %s endpoint is not gated on the management capability.', $endpoint['methods'] )
            );
        }
    }

    /**
     * An unauthorised caller is refused with a 403.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unauthorised_caller_is_refused() {
        Functions\when( 'current_user_can' )->justReturn( false );

        list( $controller ) = $this->controller();

        $result = $controller->manage_permissions_check( new WP_REST_Request() );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'lisensio_forbidden', $result->get_error_code() );
        $this->assertSame( 403, $result->get_error_data()['status'] );
    }

    /**
     * A caller holding the capability is allowed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_caller_holding_the_capability_is_allowed() {
        Functions\when( 'current_user_can' )->alias(
            function ( $capability ) {
                return 'manage_lisensio_kb' === $capability;
            }
        );

        list( $controller ) = $this->controller();

        $this->assertTrue( $controller->manage_permissions_check( new WP_REST_Request() ) );
    }

    /**
     * A key the registry does not hold is refused, not stored.
     *
     * The store would drop it and answer 200, so the caller would be told their
     * typo had been saved.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_unknown_key_is_refused() {
        list( $controller, $spy ) = $this->controller();

        $result = $controller->update_item(
            $this->post(
                array(
                    'a_number'    => 30,
                    'not_a_thing' => 'anything',
                )
            )
        );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'lisensio_unknown_setting', $result->get_error_code() );
        $this->assertSame( 400, $result->get_error_data()['status'] );
        $this->assertArrayHasKey( 'not_a_thing', $result->get_error_data()['params'] );
        $this->assertSame( array(), $spy->updates, 'An unknown key must abort the whole save.' );
    }

    /**
     * A number outside the registered bounds is refused per field.
     *
     * The store clamps rather than refuses, which is why this is driven through
     * the spy: with the real store the option would end up holding 500 and the
     * assertion would pass with the range check deleted.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_out_of_range_number_is_refused_per_field() {
        list( $controller, $spy ) = $this->controller();

        $result = $controller->update_item( $this->post( array( 'a_number' => 9999 ) ) );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'lisensio_invalid_setting', $result->get_error_code() );

        $params = $result->get_error_data()['params'];

        $this->assertArrayHasKey( 'a_number', $params );
        $this->assertStringContainsString( '500', $params['a_number'] );
        $this->assertSame( array(), $spy->updates );
    }

    /**
     * A value the registry's own sanitiser rejects is refused per field.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_value_the_sanitiser_rejects_is_refused_per_field() {
        list( $controller, $spy ) = $this->controller();

        $result = $controller->update_item( $this->post( array( 'a_colour' => 'javascript:alert(1)' ) ) );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertArrayHasKey( 'a_colour', $result->get_error_data()['params'] );
        $this->assertSame( array(), $spy->updates );
    }

    /**
     * The presentation sanitisers refuse bad values at the REST layer too.
     *
     * Wired into a spy store, which does not sanitise, so the refusal can only
     * be the controller applying the sanitiser named in the registry entry. The
     * keys are deliberately neutral for the reason given on registry(): the
     * point is that these three sanitisers reject, not that the controller has
     * heard of a setting called `article_sidebar_position`.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_presentation_sanitisers_refuse_bad_values() {
        Functions\when( 'absint' )->alias(
            function ( $value ) {
                return abs( (int) $value );
            }
        );

        Functions\when( 'get_post_type' )->justReturn( 'page' );

        $spy        = new Lisensio_KB_Settings_Spy( $this->presentation_registry() );
        $controller = new Lisensio_KB_REST_Settings_Controller( $spy );

        $result = $controller->update_item(
            $this->post(
                array(
                    'a_position' => 'top',
                    'an_image'   => 7,
                    'a_style'    => 'parallax',
                )
            )
        );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame(
            array( 'a_position', 'an_image', 'a_style' ),
            array_keys( $result->get_error_data()['params'] )
        );
        $this->assertSame( array(), $spy->updates );
    }

    /**
     * The same sanitisers pass an acceptable payload straight through.
     *
     * The companion to the refusal test: a sanitiser that rejected everything
     * would satisfy that one on its own.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_presentation_sanitisers_pass_good_values() {
        Functions\when( 'absint' )->alias(
            function ( $value ) {
                return abs( (int) $value );
            }
        );

        Functions\when( 'get_post_type' )->justReturn( 'attachment' );

        $spy        = new Lisensio_KB_Settings_Spy( $this->presentation_registry() );
        $controller = new Lisensio_KB_REST_Settings_Controller( $spy );

        $result = $controller->update_item(
            $this->post(
                array(
                    'a_position' => 'left',
                    'an_image'   => 42,
                    'a_style'    => 'gradient',
                )
            )
        );

        $this->assertNotInstanceOf( 'WP_Error', $result );
        $this->assertSame(
            array(
                'a_position' => 'left',
                'an_image'   => 42,
                'a_style'    => 'gradient',
            ),
            $spy->updates[0]
        );
    }

    /**
     * Clearing an attachment setting is a save, not a validation failure.
     *
     * Zero is how the settings screen says "no image". If the attachment
     * sanitiser refused it the way it refuses a page ID, the header image would
     * be a setting that could be chosen and never unchosen.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_attachment_setting_can_be_cleared() {
        Functions\when( 'absint' )->alias(
            function ( $value ) {
                return abs( (int) $value );
            }
        );

        Functions\when( 'get_post_type' )->justReturn( 'page' );

        $spy        = new Lisensio_KB_Settings_Spy( $this->presentation_registry(), array( 'an_image' => 42 ) );
        $controller = new Lisensio_KB_REST_Settings_Controller( $spy );

        $result = $controller->update_item( $this->post( array( 'an_image' => 0 ) ) );

        $this->assertNotInstanceOf( 'WP_Error', $result );
        $this->assertSame( array( 'an_image' => 0 ), $spy->updates[0] );
    }

    /**
     * A registry wired to the presentation sanitisers under neutral keys.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>> The registry.
     */
    private function presentation_registry() {
        return array(
            'a_position' => array(
                'type'     => 'string',
                'default'  => 'right',
                'sanitize' => array( 'Lisensio_KB_Settings', 'sanitize_sidebar_position' ),
            ),
            'an_image'   => array(
                'type'     => 'int',
                'default'  => 0,
                'sanitize' => array( 'Lisensio_KB_Settings', 'sanitize_attachment_id' ),
            ),
            'a_style'    => array(
                'type'     => 'string',
                'default'  => 'solid',
                'sanitize' => array( 'Lisensio_KB_Settings', 'sanitize_header_style' ),
            ),
        );
    }

    /**
     * A value of the wrong type is refused rather than cast.
     *
     * @since 1.0.0
     *
     * @dataProvider provide_wrong_types
     *
     * @param string $key   The setting key.
     * @param mixed  $value The submitted value.
     *
     * @return void
     */
    public function test_a_value_of_the_wrong_type_is_refused( $key, $value ) {
        list( $controller, $spy ) = $this->controller();

        $result = $controller->update_item( $this->post( array( $key => $value ) ) );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertArrayHasKey( $key, $result->get_error_data()['params'] );
        $this->assertSame( array(), $spy->updates );
    }

    /**
     * Values that do not belong to their declared type.
     *
     * @since 1.0.0
     *
     * @return array<string, array{0: string, 1: mixed}> The cases.
     */
    public function provide_wrong_types() {
        return array(
            'text in a number'    => array( 'a_number', 'twenty' ),
            'decimal in a number' => array( 'a_number', '20.5' ),
            'array in a number'   => array( 'a_number', array( 20 ) ),
            'text in a flag'      => array( 'a_flag', 'perhaps' ),
            'number in text'      => array( 'a_colour', 16711680 ),
            'array in text'       => array( 'a_slug', array( 'docs' ) ),
        );
    }

    /**
     * A payload with one bad field writes none of its good fields.
     *
     * This is the acceptance criterion the whole controller exists for. With the
     * validation loop short-circuiting on the first failure and writing what it
     * had already collected, `a_flag` and `a_colour` would land in the option
     * while `a_number` did not, leaving the site in a state the administrator
     * never asked for and cannot see.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_partial_payload_writes_nothing() {
        list( $controller, $spy ) = $this->controller();

        $result = $controller->update_item(
            $this->post(
                array(
                    'a_flag'   => false,
                    'a_colour' => '#ff0000',
                    'a_number' => 0,
                )
            )
        );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( array(), $spy->updates, 'Nothing may be written when any field is invalid.' );

        $stored = $spy->all();

        $this->assertTrue( $stored['a_flag'] );
        $this->assertSame( '#2563eb', $stored['a_colour'] );
    }

    /**
     * Every failing field is reported, not only the first.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_every_failing_field_is_reported() {
        list( $controller ) = $this->controller();

        $result = $controller->update_item(
            $this->post(
                array(
                    'a_number' => 0,
                    'a_colour' => 'not a colour',
                    'a_flag'   => 'perhaps',
                )
            )
        );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame(
            array( 'a_number', 'a_colour', 'a_flag' ),
            array_keys( $result->get_error_data()['params'] )
        );
    }

    /**
     * A valid payload is written as sent.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_valid_payload_is_written() {
        list( $controller, $spy ) = $this->controller();

        $result = $controller->update_item(
            $this->post(
                array(
                    'a_number' => '45',
                    'a_flag'   => false,
                    'a_colour' => '#ff0000',
                )
            )
        );

        $this->assertNotInstanceOf( 'WP_Error', $result );
        $this->assertCount( 1, $spy->updates );
        $this->assertSame(
            array(
                'a_number' => 45,
                'a_flag'   => false,
                'a_colour' => '#ff0000',
            ),
            $spy->updates[0]
        );
    }

    /**
     * A slug is normalised on the way in rather than refused.
     *
     * `Getting Started` is a reasonable thing to type into a slug field and a
     * perfectly good `getting-started` once normalised, so refusing it would be
     * pedantry. What must not happen is the raw value reaching the store.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_slug_is_normalised_rather_than_refused() {
        list( $controller, $spy ) = $this->controller();

        $controller->update_item( $this->post( array( 'a_slug' => 'Getting Started' ) ) );

        $this->assertSame( array( 'a_slug' => 'getting-started' ), $spy->updates[0] );
    }

    /**
     * A body that is not an object is refused.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_non_object_body_is_refused() {
        list( $controller, $spy ) = $this->controller();

        $result = $controller->update_item(
            new WP_REST_Request( array(), '', array( 'settings' => 'a_number=30' ) )
        );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'lisensio_invalid_settings', $result->get_error_code() );
        $this->assertSame( array(), $spy->updates );
    }

    /**
     * An empty payload is refused rather than answered as a successful no-op.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_empty_payload_is_refused() {
        list( $controller, $spy ) = $this->controller();

        $result = $controller->update_item( $this->post( array() ) );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'lisensio_no_settings', $result->get_error_code() );
        $this->assertSame( array(), $spy->updates );
    }

    /**
     * The write route requires the settings object.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_write_route_requires_a_settings_object() {
        list( $controller ) = $this->controller();

        $args = $controller->update_args();

        $this->assertSame( 'object', $args['settings']['type'] );
        $this->assertTrue( $args['settings']['required'] );
    }

    /**
     * Reset deletes the stored option rather than writing a defaults array.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_reset_deletes_the_stored_option() {
        list( $controller, $spy ) = $this->controller( array( 'a_number' => 45 ) );

        $controller->delete_item( new WP_REST_Request() );

        $this->assertSame( 1, $spy->deletes );
        $this->assertSame( array(), $spy->updates );
    }

    /**
     * Reset restores exactly the registry defaults.
     *
     * Driven through the real store, because "exactly the defaults" is a claim
     * about Lisensio_KB_Settings resolving an absent option from the registry,
     * and a double defining its own `all()` could only agree with itself.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_reset_restores_exactly_the_registry_defaults() {
        $settings = new Lisensio_KB_Settings();

        $settings->update(
            array(
                'brand_color'       => '#ff0000',
                'articles_per_page' => 5,
                'article_slug'      => 'manual',
            )
        );

        $this->assertSame( '#ff0000', $settings->get( 'brand_color' ) );

        $payload = ( new Lisensio_KB_REST_Settings_Controller( $settings ) )
            ->delete_item( new WP_REST_Request() );

        $this->assertSame( $settings->defaults(), $payload['settings'] );
        $this->assertArrayNotHasKey( Lisensio_KB_Settings::OPTION_NAME, $this->options );
    }

    /**
     * Reset raises the rewrite flag when a slug goes back to its default.
     *
     * Deleting the option bypasses Lisensio_KB_Settings::update(), which is what
     * normally raises the flag, so without this the old rewrite rules would stay
     * in place and every knowledge base URL would 404.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_reset_raises_the_rewrite_flag_when_a_slug_changes() {
        list( $controller ) = $this->controller( array( 'a_slug' => 'manual' ) );

        $controller->delete_item( new WP_REST_Request() );

        $this->assertSame( 1, $this->options[ Lisensio_KB_Settings::FLUSH_OPTION ] );
    }

    /**
     * Reset leaves the rewrite rules alone when no slug changed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_reset_leaves_the_rewrite_rules_alone_when_no_slug_changed() {
        list( $controller ) = $this->controller( array( 'a_number' => 45 ) );

        $controller->delete_item( new WP_REST_Request() );

        $this->assertArrayNotHasKey( Lisensio_KB_Settings::FLUSH_OPTION, $this->options );
    }

    /**
     * The read route answers with the settings, the defaults and the schema.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_read_route_answers_with_settings_defaults_and_schema() {
        list( $controller ) = $this->controller( array( 'a_number' => 45 ) );

        $payload = $controller->get_item( new WP_REST_Request() );

        $this->assertSame( 45, $payload['settings']['a_number'] );
        $this->assertSame( 20, $payload['defaults']['a_number'] );
        $this->assertNotEmpty( $payload['schema'] );
    }

    /**
     * A setting the registry gains from a third party is validated too.
     *
     * Nothing in the controller may key on a setting name; a registry extension
     * has to be held to its own declared type and bounds.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_registry_extension_is_validated_against_its_own_bounds() {
        $registry = $this->registry();

        $registry['an_extension'] = array(
            'type'    => 'int',
            'default' => 3,
            'min'     => 1,
            'max'     => 9,
        );

        $spy        = new Lisensio_KB_Settings_Spy( $registry );
        $controller = new Lisensio_KB_REST_Settings_Controller( $spy );

        $refused = $controller->update_item( $this->post( array( 'an_extension' => 10 ) ) );

        $this->assertInstanceOf( 'WP_Error', $refused );
        $this->assertArrayHasKey( 'an_extension', $refused->get_error_data()['params'] );

        $controller->update_item( $this->post( array( 'an_extension' => 9 ) ) );

        $this->assertSame( array( 'an_extension' => 9 ), $spy->updates[0] );
    }
}
