<?php
/**
 * The extension part of an app's localised configuration.
 *
 * Turns the registry's items for one screen into the `extensions` entry of
 * the Articles, Categories and Tags app configs. Only the items the current
 * user may see are included, and capabilities never reach the page.
 *
 * Every list is a JSON array of objects with a `key`, so the order the items
 * were added in survives, and an empty list is `[]` whether or not anything
 * was declared.
 *
 * @package Licensio_KB
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the `extensions` entry of an app's configuration.
 *
 * @since 1.1.0
 */
class Licensio_KB_Extension_Config {

    /**
     * The extensions one screen shows the current user.
     *
     * @since 1.1.0
     *
     * @param Licensio_KB_Extensions $extensions The registry.
     * @param string                 $screen     `articles`, `categories` or `tags`.
     *
     * @return array<string, array<int, array<string, mixed>>> The `columns`, `filters`, `bulkActions` and `rowActions` lists.
     */
    public static function for_screen( Licensio_KB_Extensions $extensions, $screen ) {
        $columns = array();
        $filters = array();

        foreach ( $extensions->allowed( $screen, 'columns' ) as $column ) {
            $columns[] = array(
                'key'            => $column['key'],
                'label'          => $column['label'],
                'group'          => $column['group'],
                'defaultVisible' => $column['default_visible'],
                'sortable'       => $column['sortable'],
                'position'       => $column['position'],
            );
        }

        foreach ( $extensions->allowed( $screen, 'filters' ) as $filter ) {
            $options = array();

            foreach ( $filter['options'] as $value => $label ) {
                $options[] = array(
                    'value' => (string) $value,
                    'label' => $label,
                );
            }

            $filters[] = array(
                'key'      => $filter['key'],
                'label'    => $filter['label'],
                'queryArg' => $filter['query_arg'],
                'options'  => $options,
            );
        }

        return array(
            'columns'     => $columns,
            'filters'     => $filters,
            'bulkActions' => self::actions( $extensions->allowed( $screen, 'bulk_actions' ) ),
            'rowActions'  => self::actions( $extensions->allowed( $screen, 'row_actions' ) ),
        );
    }

    /**
     * Bulk or row actions as the app reads them.
     *
     * @since 1.1.0
     *
     * @param array<string, array<string, mixed>> $actions The allowed actions.
     *
     * @return array<int, array{key: string, label: string}> The actions.
     */
    private static function actions( array $actions ) {
        $list = array();

        foreach ( $actions as $action ) {
            $list[] = array(
                'key'   => (string) $action['key'],
                'label' => (string) $action['label'],
            );
        }

        return $list;
    }
}
