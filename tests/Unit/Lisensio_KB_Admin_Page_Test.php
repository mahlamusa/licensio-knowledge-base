<?php
/**
 * The shared shell the three Vue-backed admin screens are framed by.
 *
 * The shell exists so Analytics, Feedback and Settings cannot drift apart: one
 * of them used to print a heading and the other two printed none, which left
 * two screens nameless before their bundle parsed and the third with a heading
 * that did not line up with its own content. These tests pin the three things
 * that made consolidating worth it — a heading is always printed, it is
 * printed inside the measured column, and everything passed in is escaped.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

/**
 * Drives Lisensio_KB_Admin_Page.
 *
 * @since 1.0.0
 */
class Lisensio_KB_Admin_Page_Test extends Lisensio_KB_Test_Case {

    /**
     * Render the shell and hand back the markup.
     *
     * @since 1.0.0
     *
     * @param string $title        Screen heading.
     * @param string $mount_id     Mount element id.
     * @param string $loading_text Pre-hydration placeholder.
     * @param string $mount_class  Optional mount class.
     *
     * @return string
     */
    private function render( $title = 'Knowledge Base Settings', $mount_id = 'lisensio-kb-settings', $loading_text = 'Loading…', $mount_class = '' ) {
        ob_start();
        Lisensio_KB_Admin_Page::shell( $title, $mount_id, $loading_text, $mount_class );

        return (string) ob_get_clean();
    }

    /**
     * The screen is named before its bundle has parsed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_shell_prints_the_heading() {
        $this->assertStringContainsString(
            '<h1 class="lkb-page__title">Knowledge Base Settings</h1>',
            $this->render()
        );
    }

    /**
     * The heading sits inside the measured column, not in `.wrap`.
     *
     * A heading printed in `.wrap` sits hard against the screen edge while the
     * application draws its content in a centred 1240px column, so the screen
     * name and the screen do not share an edge. Asserting on the order of the
     * opening tags is what distinguishes the two arrangements.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_heading_is_inside_the_measured_column() {
        $markup = $this->render();

        $inner   = strpos( $markup, 'lkb-page__inner' );
        $heading = strpos( $markup, 'lkb-page__title' );

        $this->assertNotFalse( $inner );
        $this->assertNotFalse( $heading );
        $this->assertLessThan( $heading, $inner );
    }

    /**
     * The application gets the mount point it was promised.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_shell_prints_the_mount_point() {
        $markup = $this->render( 'Analytics', 'lisensio-kb-analytics', 'Loading…', 'lisensio-kb-analytics-app' );

        $this->assertStringContainsString( 'id="lisensio-kb-analytics"', $markup );
        $this->assertStringContainsString( 'class="lisensio-kb-analytics-app"', $markup );
    }

    /**
     * The placeholder stands in until the bundle runs.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_shell_prints_the_loading_placeholder() {
        $this->assertStringContainsString(
            '<p class="lkb-page__loading">Loading feedback</p>',
            $this->render( 'Feedback', 'lisensio-kb-feedback', 'Loading feedback' )
        );
    }

    /**
     * Every value the shell is handed is escaped.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_shell_escapes_what_it_is_given() {
        $markup = $this->render( '<script>t</script>', '"><script>i</script>', '<script>l</script>', '"><script>c</script>' );

        $this->assertStringNotContainsString( '<script>', $markup );
    }
}
