<?php
/**
 * The category routes against real term rows.
 *
 * Seeds a category tree, articles and order meta with the content fixtures,
 * runs the categories controller's handlers, and reads the rows back with
 * plain SQL: the tree it answers, where articles and child categories end up
 * after a delete or a merge, and what an order request stores.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

/*
 * This suite connects to a real database on purpose. The WordPress Database API
 * is therefore not available or appropriate here.
 */
// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery

require_once __DIR__ . '/Licensio_KB_Analytics_DB_Test_Case.php';
require_once __DIR__ . '/trait-licensio-kb-content-fixtures.php';
require_once __DIR__ . '/trait-licensio-kb-live-term-api.php';

/**
 * Exercises Licensio_KB_Categories_REST_Controller against a live server.
 *
 * @since 1.0.0
 */
class Licensio_KB_Categories_REST_Integration_Test extends Licensio_KB_Analytics_DB_Test_Case {

    use Licensio_KB_Content_Fixtures;
    use Licensio_KB_Live_Term_API;

    /**
     * The seeded tree: Getting started › Install › Requirements, Getting
     * started › Upgrade, and Billing.
     *
     * @since 1.0.0
     * @var array<string, int>
     */
    private $ids = array();

    /**
     * The controller under test.
     *
     * @since 1.0.0
     * @var Licensio_KB_Categories_REST_Controller
     */
    private $controller;

    /**
     * Seed the tree and stub the term API.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->install_content_schemas();
        $this->stub_category_term_api();

        $this->ids = $this->make_category_tree(
            array(
                'Getting started' => array(
                    'Install' => array(
                        'Requirements' => array(),
                    ),
                    'Upgrade' => array(),
                ),
                'Billing'         => array(),
            )
        );

        $this->controller = new Licensio_KB_Categories_REST_Controller();
    }

    /**
     * A request with the given parameters.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $params The parameters.
     *
     * @return WP_REST_Request The request.
     */
    private function request( array $params ) {
        return new WP_REST_Request( array(), '/licensio-kb/v1/categories', $params );
    }

    /**
     * The category list a tree route answered.
     *
     * @since 1.0.0
     *
     * @param mixed $result The handler's answer.
     *
     * @return array<int, array<string, mixed>> The categories.
     */
    private function tree( $result ) {
        $this->assertIsArray( $result );

        return $result;
    }

    /**
     * A category's parent, read from the table.
     *
     * @since 1.0.0
     *
     * @param int $term_id The category.
     *
     * @return int|null The parent ID, or null when the category is gone.
     */
    private function parent_of( $term_id ) {
        $rows = $this->live_rows( 'SELECT parent FROM ' . $this->table( 'term_taxonomy' ) . ' WHERE term_id = ' . (int) $term_id );

        return empty( $rows ) ? null : (int) $rows[0]['parent'];
    }

    /**
     * The categories an article has, read from the tables.
     *
     * @since 1.0.0
     *
     * @param int $article_id The article.
     *
     * @return int[] The category IDs, ascending.
     */
    private function categories_of( $article_id ) {
        $rows = $this->live_rows(
            'SELECT tt.term_id FROM ' . $this->table( 'term_relationships' ) . ' r JOIN ' . $this->table( 'term_taxonomy' )
            . " tt ON tt.term_taxonomy_id = r.term_taxonomy_id WHERE tt.taxonomy = 'licensio_category' AND r.object_id = " . (int) $article_id . ' ORDER BY tt.term_id'
        );

        return array_map( 'intval', array_column( $rows, 'term_id' ) );
    }

    /**
     * The tree answers every category, siblings in manual order then by name.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_the_tree_answers_order_counts_and_plain_names() {
        $this->live_update_term_meta( $this->ids['Upgrade'], 'licensio_order', 10 );
        $this->live_update_term_meta( $this->ids['Install'], 'licensio_order', 20 );
        $this->live_update_term_meta( $this->ids['Billing'], 'licensio_icon', 'dashicons-money' );

        $amp = $this->make_term( 'Tips &amp; tricks', 'licensio_category' );

        $this->make_article( array( 'categories' => array( $this->ids['Billing'] ) ) );
        $this->make_article( array( 'categories' => array( $this->ids['Billing'] ) ) );
        $this->make_article(
            array(
                'categories' => array( $this->ids['Billing'] ),
                'status'     => 'draft',
            )
        );

        $items = $this->tree( $this->controller->get_items( $this->request( array() ) ) );
        $by_id = array_column( $items, null, 'id' );

        $this->assertCount( 6, $items );
        $this->assertSame( 2, $by_id[ $this->ids['Billing'] ]['count'], 'Only published articles are counted.' );
        $this->assertSame( 'dashicons-money', $by_id[ $this->ids['Billing'] ]['icon'] );
        $this->assertSame( 'Tips & tricks', $by_id[ $amp ]['name'] );
        $this->assertSame( $this->ids['Getting started'], $by_id[ $this->ids['Install'] ]['parent'] );

        $children = array_values(
            array_filter(
                $items,
                function ( $item ) {
                    return $this->ids['Getting started'] === $item['parent'];
                }
            )
        );

        $this->assertSame( array( 'Upgrade', 'Install' ), array_column( $children, 'name' ), 'Manual order wins over the name.' );
    }

    /**
     * Deleting with `none` drops the articles' category and lifts the children.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_delete_leaving_articles_uncategorised() {
        $article = $this->make_article( array( 'categories' => array( $this->ids['Install'] ) ) );

        $result = $this->controller->delete_item(
            $this->request(
                array(
                    'id'                => $this->ids['Install'],
                    'reassign_articles' => 'none',
                    'reassign_children' => 'parent',
                )
            )
        );

        $this->assertSame( 0, $result['moved'] );
        $this->assertSame( array( $this->ids['Requirements'] ), $result['reparented'] );
        $this->assertNull( $this->parent_of( $this->ids['Install'] ) );
        $this->assertSame( $this->ids['Getting started'], $this->parent_of( $this->ids['Requirements'] ) );
        $this->assertSame( array(), $this->categories_of( $article ) );
    }

    /**
     * Deleting with `parent` moves the articles up a level.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_delete_moving_articles_to_the_parent() {
        $article = $this->make_article( array( 'categories' => array( $this->ids['Install'] ) ) );
        $both    = $this->make_article( array( 'categories' => array( $this->ids['Install'], $this->ids['Getting started'] ) ) );

        $result = $this->controller->delete_item(
            $this->request(
                array(
                    'id'                => $this->ids['Install'],
                    'reassign_articles' => 'parent',
                    'reassign_children' => 'parent',
                )
            )
        );

        $this->assertSame( 2, $result['moved'] );
        $this->assertSame( array( $this->ids['Getting started'] ), $this->categories_of( $article ) );
        $this->assertSame( array( $this->ids['Getting started'] ), $this->categories_of( $both ), 'An article already in the parent is not assigned twice.' );

        $count = $this->live_rows( 'SELECT count FROM ' . $this->table( 'term_taxonomy' ) . ' WHERE term_id = ' . $this->ids['Getting started'] );

        $this->assertSame( '2', $count[0]['count'] );
    }

    /**
     * Deleting with chosen targets moves articles and children there.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_delete_moving_articles_and_children_to_chosen_categories() {
        $article = $this->make_article( array( 'categories' => array( $this->ids['Getting started'] ) ) );

        $result = $this->controller->delete_item(
            $this->request(
                array(
                    'id'                => $this->ids['Getting started'],
                    'reassign_articles' => (string) $this->ids['Billing'],
                    'reassign_children' => (string) $this->ids['Billing'],
                )
            )
        );

        $this->assertSame( $this->ids['Getting started'], $result['deleted'] );
        $this->assertSame( array( $this->ids['Billing'] ), $this->categories_of( $article ) );
        $this->assertSame( $this->ids['Billing'], $this->parent_of( $this->ids['Install'] ) );
        $this->assertSame( $this->ids['Billing'], $this->parent_of( $this->ids['Upgrade'] ) );
        $this->assertSame( $this->ids['Install'], $this->parent_of( $this->ids['Requirements'] ), 'Grandchildren stay with their own parent.' );
    }

    /**
     * A delete that would loop the children into themselves changes nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_delete_into_a_descendant_changes_nothing() {
        $article = $this->make_article( array( 'categories' => array( $this->ids['Getting started'] ) ) );

        $result = $this->controller->delete_item(
            $this->request(
                array(
                    'id'                => $this->ids['Getting started'],
                    'reassign_articles' => (string) $this->ids['Billing'],
                    'reassign_children' => (string) $this->ids['Requirements'],
                )
            )
        );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'licensio_kb_category_cycle', $result->get_error_code() );
        $this->assertSame( 0, $this->parent_of( $this->ids['Getting started'] ) );
        $this->assertSame( array( $this->ids['Getting started'] ), $this->categories_of( $article ) );
    }

    /**
     * A merge moves articles, re-parents children and deletes the sources.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_merge_moves_articles_and_reparents_children() {
        $in_install = $this->make_article( array( 'categories' => array( $this->ids['Install'] ) ) );
        $in_both    = $this->make_article( array( 'categories' => array( $this->ids['Install'], $this->ids['Billing'] ) ) );
        $in_upgrade = $this->make_article( array( 'categories' => array( $this->ids['Upgrade'] ) ) );

        $result = $this->controller->merge(
            $this->request(
                array(
                    'sources' => array( $this->ids['Install'], $this->ids['Upgrade'] ),
                    'target'  => $this->ids['Billing'],
                )
            )
        );

        $this->assertSame( array( $this->ids['Requirements'] ), $result['reparented'] );
        $this->assertSame( array( $this->ids['Install'], $this->ids['Upgrade'] ), $result['deleted'] );
        $this->assertSame( 3, $result['moved'] );

        $this->assertSame( $this->ids['Billing'], $this->parent_of( $this->ids['Requirements'] ), 'The child of a source moves under the target, not up to the source parent.' );
        $this->assertNull( $this->parent_of( $this->ids['Install'] ) );
        $this->assertNull( $this->parent_of( $this->ids['Upgrade'] ) );

        foreach ( array( $in_install, $in_both, $in_upgrade ) as $article ) {
            $this->assertSame( array( $this->ids['Billing'] ), $this->categories_of( $article ) );
        }
    }

    /**
     * A merge into a descendant of a source changes nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_merge_into_a_descendant_changes_nothing() {
        $result = $this->controller->merge(
            $this->request(
                array(
                    'sources' => array( $this->ids['Getting started'] ),
                    'target'  => $this->ids['Requirements'],
                )
            )
        );

        $this->assertSame( 'licensio_kb_category_cycle', $result->get_error_code() );
        $this->assertSame( 0, $this->parent_of( $this->ids['Getting started'] ) );
        $this->assertSame( $this->ids['Getting started'], $this->parent_of( $this->ids['Install'] ) );
    }

    /**
     * An order request stores the parent and the order, and the tree reflects it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_order_persists_parent_and_position() {
        $items = $this->tree(
            $this->controller->update_order(
                $this->request(
                    array(
                        'parent' => $this->ids['Billing'],
                        'ids'    => array( $this->ids['Upgrade'], $this->ids['Install'] ),
                    )
                )
            )
        );

        $this->assertSame( $this->ids['Billing'], $this->parent_of( $this->ids['Upgrade'] ) );
        $this->assertSame( $this->ids['Billing'], $this->parent_of( $this->ids['Install'] ) );
        $this->assertSame( '10', $this->live_get_term_meta( $this->ids['Upgrade'], 'licensio_order' ) );
        $this->assertSame( '20', $this->live_get_term_meta( $this->ids['Install'], 'licensio_order' ) );

        $under_billing = array_values(
            array_filter(
                $items,
                function ( $item ) {
                    return $this->ids['Billing'] === $item['parent'];
                }
            )
        );

        $this->assertSame( array( 'Upgrade', 'Install' ), array_column( $under_billing, 'name' ) );

        $reread = $this->tree( $this->controller->get_items( $this->request( array() ) ) );

        $this->assertSame( $items, $reread, 'A fresh read answers the same tree.' );
    }

    /**
     * An order that puts a category inside its own descendant changes nothing.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_an_order_cycle_is_rejected_without_writes() {
        $result = $this->controller->update_order(
            $this->request(
                array(
                    'parent' => $this->ids['Requirements'],
                    'ids'    => array( $this->ids['Billing'], $this->ids['Getting started'] ),
                )
            )
        );

        $this->assertSame( 'licensio_kb_category_cycle', $result->get_error_code() );
        $this->assertSame( 0, $this->parent_of( $this->ids['Billing'] ) );
        $this->assertSame( '', $this->live_get_term_meta( $this->ids['Billing'], 'licensio_order' ) );
    }

    /**
     * Creating and updating a category writes the term and its meta.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_create_and_update_write_the_term_and_meta() {
        $created = $this->controller->create_item(
            $this->request(
                array(
                    'name'     => 'Security',
                    'parent'   => $this->ids['Billing'],
                    'icon'     => 'dashicons-lock',
                    'icon_svg' => '',
                )
            )
        );

        $this->assertSame( 201, $created->get_status() );

        $id = $created->get_data()['id'];

        $this->assertSame( $this->ids['Billing'], $this->parent_of( $id ) );
        $this->assertSame( 'dashicons-lock', $this->live_get_term_meta( $id, 'licensio_icon' ) );

        $updated = $this->controller->update_item(
            $this->request(
                array(
                    'id'          => $id,
                    'description' => 'Keeping accounts safe',
                    'parent'      => 0,
                )
            )
        );

        $this->assertSame( 0, $updated['parent'] );
        $this->assertSame( 'Keeping accounts safe', $updated['description'] );
        $this->assertSame( 'dashicons-lock', $updated['icon'], 'Fields not sent are left alone.' );
    }

    /**
     * A name with `&` is stored encoded, as core does, and answered as plain text.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_name_with_an_ampersand_round_trips_as_plain_text() {
        $created = $this->controller->create_item( $this->request( array( 'name' => 'Tips & tricks' ) ) );

        $this->assertSame( 201, $created->get_status() );

        $id   = $created->get_data()['id'];
        $rows = $this->live_rows( 'SELECT name, slug FROM ' . $this->table( 'terms' ) . ' WHERE term_id = ' . (int) $id );

        $this->assertSame( 'Tips &amp; tricks', $rows[0]['name'], 'Stored encoded.' );
        $this->assertSame( 'tips-tricks', $rows[0]['slug'] );
        $this->assertSame( 'Tips & tricks', $created->get_data()['name'], 'Answered as plain text.' );

        $updated = $this->controller->update_item(
            $this->request(
                array(
                    'id'   => $id,
                    'name' => 'Tips & more tricks',
                    'slug' => '',
                )
            )
        );

        $this->assertSame( 'Tips & more tricks', $updated['name'] );
        $this->assertSame( 'tips-more-tricks', $updated['slug'], 'An empty slug is made from the name.' );
    }

    /**
     * A description with escaped and real markup is answered as stored and kept by a rename.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_description_round_trips_through_a_rename_unchanged() {
        $stored = 'Use the &lt;head&gt; element, not <b>body</b>.';
        $id     = $this->ids['Billing'];

        $this->live_query(
            'UPDATE ' . $this->table( 'term_taxonomy' ) . " SET description = '" . $this->db->real_escape_string( $stored ) . "' WHERE term_id = " . (int) $id
        );

        $before = array_column( $this->tree( $this->controller->get_items( $this->request( array() ) ) ), null, 'id' );

        $this->assertSame( $stored, $before[ $id ]['description'], 'Answered exactly as stored.' );

        $renamed = $this->controller->update_item(
            $this->request(
                array(
                    'id'   => $id,
                    'name' => 'Billing and invoices',
                )
            )
        );

        $rows = $this->live_rows( 'SELECT description FROM ' . $this->table( 'term_taxonomy' ) . ' WHERE term_id = ' . (int) $id );

        $this->assertSame( 'Billing and invoices', $renamed['name'] );
        $this->assertSame( $stored, $rows[0]['description'], 'The stored description is unchanged.' );
        $this->assertSame( $stored, $renamed['description'] );
    }

    /**
     * A name a sibling already has is refused with a 400; a cousin may share it.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_duplicate_sibling_name_is_refused() {
        $result = $this->controller->create_item(
            $this->request(
                array(
                    'name'   => 'Install',
                    'parent' => $this->ids['Getting started'],
                )
            )
        );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'term_exists', $result->get_error_code() );
        $this->assertSame( array( 'status' => 400 ), $result->get_error_data() );

        $elsewhere = $this->controller->create_item(
            $this->request(
                array(
                    'name'   => 'Install',
                    'parent' => $this->ids['Billing'],
                )
            )
        );

        $this->assertSame( 201, $elsewhere->get_status() );
        $this->assertSame( 'install-billing', $elsewhere->get_data()['slug'], 'A taken slug is made unique with the parent slug.' );
    }

    /**
     * An order listing only some children puts the rest after them, in their old order.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_a_partial_order_renumbers_the_rest_after_it() {
        $this->live_update_term_meta( $this->ids['Billing'], 'licensio_order', 10 );
        $this->live_update_term_meta( $this->ids['Getting started'], 'licensio_order', 20 );

        $late = $this->make_term( 'Added by someone else', 'licensio_category' );

        $items = $this->tree(
            $this->controller->update_order(
                $this->request(
                    array(
                        'parent' => 0,
                        'ids'    => array( $this->ids['Getting started'] ),
                    )
                )
            )
        );

        $this->assertSame( '10', $this->live_get_term_meta( $this->ids['Getting started'], 'licensio_order' ) );
        $this->assertSame( '20', $this->live_get_term_meta( $late, 'licensio_order' ), 'An unpositioned sibling no longer jumps to the top.' );
        $this->assertSame( '30', $this->live_get_term_meta( $this->ids['Billing'], 'licensio_order' ) );

        $top = array_values(
            array_filter(
                $items,
                function ( $item ) {
                    return 0 === $item['parent'];
                }
            )
        );

        $this->assertSame( array( 'Getting started', 'Added by someone else', 'Billing' ), array_column( $top, 'name' ) );
    }
}
