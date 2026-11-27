# Extension API for the content manager apps

Licensio Knowledge Base 1.1.0 lets other plugins add columns, filters, bulk
actions and row actions to the **All Articles**, **Categories** and **Tags**
apps. PHP declares what exists, its label and who may see it, and fills the
row data; a JavaScript file supplies the behaviour. None of it shows when
the site uses the classic screens instead of the apps (the classic screens
setting).

This guide covers every hook and object of the API, then builds a complete
example plugin you can copy into `wp-content/plugins/`.

- [What each app supports](#what-each-app-supports)
- [Detecting the API](#detecting-the-api)
- [Declaring items in PHP](#declaring-items-in-php)
- [Row data](#row-data)
- [Narrowing and sorting the Articles list](#narrowing-and-sorting-the-articles-list)
- [Enqueueing your script](#enqueueing-your-script)
- [Registering behaviour in JavaScript](#registering-behaviour-in-javascript)
- [REST additions](#rest-additions)
- [Capabilities and escaping](#capabilities-and-escaping)
- [Performance](#performance)
- [Reading a FAQ block's questions](#reading-a-faq-blocks-questions)
- [Example plugin](#example-plugin)

## What each app supports

| | Articles | Categories | Tags |
|---|---|---|---|
| Screen name | `articles` | `categories` | `tags` |
| Columns | Yes, in the Columns picker | Yes, `default_visible` ones only | Yes, `default_visible` ones only |
| Column `position` | Yes | No, always between the name and the count | Yes |
| Sortable columns | Yes | No | No |
| Filters | Yes | No, refused | No, refused |
| Bulk actions | Yes, not in the Trash view | No, refused | No, refused |
| Row actions | Yes, not in the Trash view | Yes | Yes |
| `ctx.clearSelection()` | Clears the selection | Does nothing (no multiple selection) | Clears the selection |

The Categories and Tags apps show no filters or bulk actions, so the registry
refuses them for `categories` and `tags`: `add_filter()` and
`add_bulk_action()` return `false` there and call `_doing_it_wrong()`.

## Detecting the API

Check the `LICENSIO_KB_EXTENSIONS_API` constant (an integer, `1` in KB
1.1.0), not the plugin version. Your plugin may load before the knowledge
base does, so check it on `plugins_loaded` or later, never when your main
file loads:

```php
add_action( 'plugins_loaded', function () {
    if ( ! defined( 'LICENSIO_KB_EXTENSIONS_API' ) ) {
        return;
    }

    // Add your extension hooks here.
} );
```

## Declaring items in PHP

Declare everything from a callback on `licensio_kb_register_extensions`. It
passes the registry, a `Licensio_KB_Extensions` (also reachable as
`licensio_kb()->extensions()`).

The action fires **once per request**, at priority 5 on `admin_init` or on
`rest_api_init`, whichever comes first, and never on other front-end requests.
Add your callback when your plugin loads, or on `plugins_loaded` or `init`.
Never add it from inside an `admin_init` or `rest_api_init` callback: by then
the action may have fired already, and your items are silently missed.

```php
add_action( 'licensio_kb_register_extensions', function ( $extensions ) {
    $extensions->add_column( 'articles', 'acme/score', array( 'label' => 'Score' ) );
} );
```

### Keys

Every item has a key of the form `{namespace}/{name}`, in lowercase letters,
digits, dashes and underscores, for example `acme/score`. Use your plugin's
slug as the namespace, so you never collide with another plugin or with the
apps' own columns. The same key may be used for items of different kinds (a
column and a filter can both be `acme/reviewed`). Declaring a key again
replaces the earlier item of that kind.

### Methods

Each method takes the screen (`articles`, `categories` or `tags`), the key and
an array of arguments, and returns `true` when the item was added. An unknown
screen, a filter or bulk action for `categories` or `tags`, a malformed key, a
missing label, a capability that is not a string, or a filter with no options
is refused: the method returns `false` and calls `_doing_it_wrong()`, so the
notice shows with `WP_DEBUG` on.

#### `add_column( $screen, $key, $args )`

| Argument | Type | Default | |
|---|---|---|---|
| `label` | string | required | The column heading. |
| `group` | string | none | The Columns picker group (Articles only). |
| `default_visible` | bool | `false` | Whether the column shows before the user picks columns. Categories and Tags have no picker, so there a column shows **only** when this is true. |
| `sortable` | bool | `false` | Whether the list can sort by it (Articles only). You map the sort yourself; see [sorting](#sorting-by-your-column). |
| `position` | string | end | The column key it follows. Articles: `title`, `author`, `categories`, `tags`, `attachments`, `rating`, `views`, `reading_time`, `date`, `modified`. Tags: `name`, `slug`, `description`, `count`. It may also name another extension column that has a position itself and was declared before this one. Categories ignore it. |
| `capability` | string | the app page's | Who sees the column. |

In the Articles app, a `default_visible` column shows for users who never
saved their column choice. A user who saved before your column existed sees
it once; when they save again it counts as offered, and stays hidden if they
hid it. Saved column choices drop the keys of a deactivated extension
silently.

#### `add_filter( $screen, $key, $args )`

| Argument | Type | Default | |
|---|---|---|---|
| `label` | string | required | The dropdown's label. |
| `options` | array | required | Option value => label. Values pass through `sanitize_key()`, so use lowercase slugs. At least one option. |
| `query_arg` | string | none | The query argument a core list link uses for this filter, such as `acme_reviewed`. With it, `edit.php?post_type=licensio_article&acme_reviewed=no` opens the Articles app with the filter set, for every user who may use the app, including a role with only the KB capabilities and no `edit_posts`. Other core list arguments, such as `post_status`, are carried over too. Passed through `sanitize_key()`. |
| `capability` | string | the app page's | Who sees the filter. |

Only `articles` takes filters; `categories` and `tags` are refused. KB
renders the dropdown; no JavaScript is needed. You apply the chosen option
on [`licensio_kb_articles_query_args`](#narrowing-and-sorting-the-articles-list).

#### `add_bulk_action( $screen, $key, $args )` and `add_row_action( $screen, $key, $args )`

| Argument | Type | Default | |
|---|---|---|---|
| `label` | string | required | The action's label. |
| `capability` | string | the app page's | Who sees the action. |

An action shows only when your script registered a `run` handler for it. Row
actions follow the app's own row actions (in Categories, in each row's
menu); bulk actions follow the built-in ones in the Articles bulk bar. Only
`articles` takes bulk actions; `categories` and `tags` are refused.

#### Reading the registry

- `items( $screen, $type )` returns every declared item of a type (`columns`,
  `filters`, `bulk_actions` or `row_actions`), whoever may see it, keyed by
  key in the order added.
- `allowed( $screen, $type )` returns the ones the current user may see. Use
  it only while a request is being handled; during `rest_api_init` the REST
  nonce has not been checked yet, so the current user is not known. That is
  why the Articles route schemas name no extension key: `orderby` and the
  preferences `columns` are checked against `allowed()` while the request is
  validated.

## Row data

Each row the apps load carries `extensions`: an object keyed by column key
with the data of the extension columns the user is shown. Each cell is an
array. Its `text` (a string) is what the app shows when no script renders the
column; anything else in it reaches your script's `render()` as
`row.extensions[ key ]`. Cells for keys that were not asked for, and cells
that are not arrays, are dropped.

Two filters fill it for each app, and neither runs when no extension column
is shown:

| Filter | Runs | Arguments |
|---|---|---|
| `licensio_kb_article_extension_data_batch` | Once per Articles page | `array $data` (default empty; article ID => key => cell), `WP_Post[] $posts`, `string[] $keys` |
| `licensio_kb_article_extension_data` | Once per article, after the batch | `array $cells` (that article's part of the batch), `WP_Post $post`, `string[] $keys` |
| `licensio_kb_term_extension_data_batch` | Once per Categories or Tags answer | `array $data` (default empty; term ID => key => cell), `WP_Term[] $terms` (keyed by term ID), `string[] $keys` |
| `licensio_kb_term_extension_data` | Once per term, after the batch | `array $cells` (that term's part of the batch), `WP_Term $term`, `string[] $keys` |

`$keys` lists the visible extension column keys, from every plugin. Check that
yours is in it before doing any work. Either fill every row in the batch
filter with one query, or prime a cache there and fill each row in the
per-row filter.

## Narrowing and sorting the Articles list

```php
apply_filters( 'licensio_kb_articles_query_args', array $args, array $ext_filters, WP_REST_Request $request );
```

Filters the `WP_Query` arguments of the Articles list. `$ext_filters` maps
each extension filter key to the chosen option, already checked: only filters
the current user may use, and only declared options. It runs after KB's own
filters, sort, paging and the own-articles limit are in place, so add to
`$args` rather than replacing it (append to an existing `meta_query`, for
example).

It runs for the page (`GET articles`) and for "Select all matching"
(`GET articles/ids`), so a bulk action gets exactly the filtered set. The IDs
query has `fields` set to `ids` and is not sorted: narrow it the same way, but
leave its order alone.

### Sorting by your column

When the list is sorted by a sortable extension column, the request's
`orderby` is that column's key and the list keeps its date order until you
replace it. Read `$request->get_param( 'orderby' )` and
`$request->get_param( 'order' )` (`asc` or `desc`) and replace the order;
for a meta value, join it yourself as below. A sort by a column the current user may not see is turned into a sort by
date before the filter runs.

```php
add_filter( 'licensio_kb_articles_query_args', function ( $args, $ext_filters, $request ) {
    if ( 'acme/score' === $request->get_param( 'orderby' ) && 'ids' !== ( $args['fields'] ?? '' ) ) {
        $args['acme_score_order'] = 'asc' === $request->get_param( 'order' ) ? 'ASC' : 'DESC';
    }

    return $args;
}, 10, 3 );

add_filter( 'posts_clauses', function ( $clauses, $query ) {
    global $wpdb;

    $order = $query->get( 'acme_score_order' );

    if ( 'ASC' !== $order && 'DESC' !== $order ) {
        return $clauses;
    }

    $clauses['join']   .= $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} AS acme_score ON acme_score.post_id = {$wpdb->posts}.ID AND acme_score.meta_key = %s", '_acme_score' );
    $clauses['orderby'] = "CAST( acme_score.meta_value AS SIGNED ) {$order}, {$wpdb->posts}.ID {$order}";

    return $clauses;
}, 10, 2 );
```

The first filter marks the query; the second joins the score and sorts by
it, with the ID as a tiebreak so paging stays stable. Articles with no score
come first when ascending and last when descending. It assumes one
`_acme_score` row per article; a second row would list the article twice.

Do not sort with `meta_key`: it leaves out the articles that have no score,
so the sorted list shows fewer articles than the unsorted one. A `meta_query`
that pairs an `EXISTS` clause on the key with a `NOT EXISTS` clause (relation
`OR`) keeps them, but only the `NOT EXISTS` clause gets a join limited to your
key; the `EXISTS` clause joins every meta row of the article. An `orderby`
that names the `EXISTS` clause therefore sorts an article that has other meta
and no score by one of those other values. Name the `NOT EXISTS` clause
instead, or join the key yourself as above.

## Enqueueing your script

```php
do_action( 'licensio_kb_enqueue_app_extensions', string $screen, string $hook_suffix );
```

Fires on each app's own page (`$screen` is `articles`, `categories` or
`tags`), after the app's script is enqueued and its config localised. Enqueue
your script there. It must:

- be a **classic** script: not a module, not `defer` or `async`;
- depend on the `licensio-kb-extensions` handle, which defines
  `window.licensioKb.extensions`;
- call `register()` straight away, not after a timer, a `fetch` or
  `DOMContentLoaded`.

The apps are ES modules, which run after every classic script on the page, so
a script that follows these rules has its handlers in place before the apps
first render. A handler registered later may be missed.

## Registering behaviour in JavaScript

```js
window.licensioKb.extensions.register( screen, {
	columns: {
		'acme/score': {
			render( el, row, ctx ) {
				// Fill el; optionally return a cleanup function.
			},
		},
	},
	bulkActions: {
		'acme/check': { run( ids, ctx ) {} },
	},
	rowActions: {
		'acme/edit': { run( row, ctx ) {}, visible( row ) { return true; } },
	},
} );
```

`register( screen, definition )` returns `false` for an unknown screen or a
definition that is not an object. A key registered again replaces the earlier
handler. `window.licensioKb.extensions.get( screen )` returns the registered
`columns`, `bulkActions` and `rowActions` handlers.

The keys must match the ones declared in PHP. PHP decides what exists and
who sees it; a handler for a key PHP did not declare for the user is never
used.

### Columns: `render( el, row, ctx )`

The app gives `render` an empty container element and never renders inside
it. `render` runs when the cell mounts and again whenever the row is replaced
(after a refresh). If it returns a function, the app calls it before the next
render and when the cell unmounts, then empties the container. A column with
no `render` shows `row.extensions[ key ].text`.

### Bulk actions: `run( ids, ctx )`

Gets the selected article IDs. "Select all matching" can pass up to 10,000
IDs, so send them in batches rather than one request per ID or all of them in
one request. There is no confirmation, progress or undo from the app: ask and
report yourself if the action needs it. The bulk bar stays busy until the
returned value settles, with no timeout. If `run` throws or its promise
rejects, the app shows a generic error toast and logs the error to the
console with the action's key.

### Row actions: `run( row, ctx )` and `visible( row )`

`run` gets the row. While its promise is pending, the same action does not run
again on that row. A failure shows a generic error toast, as for bulk actions.

The optional `visible( row )` hides the action on rows where it returns a
falsy value (a missing `return` hides it too),
for a check the PHP capability cannot make, such as whether the user may edit
that one article. It runs on every render of every row, so keep it
synchronous and cheap, working from the row's own data. If it throws, the
action is hidden and the error is logged once.

### The row

Every row has `id`. Articles rows always have `extensions`; Categories and
Tags rows have it only when the app shows an extension column, so read it as
`( row.extensions || {} )[ key ]`.
The other fields are each app's own:

- Articles (`apps/articles/types.ts`, `ArticleItem`): `title`, `title_raw`,
  `slug`, `status`, `date`, `modified`, `author`, `categories`, `tags`,
  `edit_url`, `view_url`, `can_delete` and more.
- Categories (`apps/categories/types.ts`, `CategoryItem`): `name`, `slug`,
  `parent`, `description`, `count`, `order` and the icon fields.
- Tags (`apps/tags/types.ts`, `TagItem`): `name`, `slug`, `description`,
  `count`.

### `ctx`

| Member | |
|---|---|
| `refresh( ids? )` | Returns a `Promise<void>` that never rejects; a failed load shows the app's own error. With IDs, fetches just those rows again and swaps them in place; IDs not in the list are ignored, and if a row is no longer returned the whole list reloads. In Articles the refetch keeps the current filters, so a row that no longer matches them also reloads the list; in Tags a refreshed row stays even if it no longer matches the search. In Categories more than 100 listed IDs reload the list. With no IDs, reloads the list. |
| `clearSelection()` | Clears the list's selection. Does nothing in the Categories app. |
| `screen` | `'articles'`, `'categories'` or `'tags'`. |
| `rest` | `{ root, nonce }`: the `licensio-kb/v1` namespace URL and a `wp_rest` nonce. The nonce works for your own routes too. |

TypeScript types for all of the above (`ExtensionDefinition`,
`ExtensionContext`, `ColumnHandler`, `BulkActionHandler`, `RowActionHandler`)
are in `apps/components/extensions.ts`.

## REST additions

All under `licensio-kb/v1`.

- `GET articles` and `GET articles/ids` take `ext_filters[{key}]={option}`.
  Unknown keys, options and filters the user may not use are ignored.
- `GET articles` takes `orderby={key}` for a sortable extension column, and
  `include[]` with up to 100 article IDs to return just those rows. The other
  filters and the own-articles limit still apply.
- `GET categories` and `GET tags` take `include[]` with up to 100 term IDs.
- Each `GET articles` item carries `extensions` (see [Row data](#row-data)),
  `{}` when it has none. `GET categories` and `GET tags` items carry it only
  when the app shows an extension column, `{}` for a term with no data.
- The Articles column preferences accept the extension column keys the user
  may see.
- `orderby` and the preferences `columns` are checked against the columns the
  current user may see, once the request is authenticated. A key the user may
  not see is refused with a 400 like any unknown value, and the route schemas
  (the OPTIONS response and the REST index) list no extension keys, so a
  column gated by a capability is never named to someone who may not see
  it.

## Capabilities and escaping

- Every item has a capability. When you give none, it is the app page's own:
  `edit_licensio_articles` for Articles, `manage_licensio_kb` for Categories
  and Tags. Items the user lacks the capability for never reach the page, and
  capabilities themselves are never sent to the browser.
- A capability only decides who **sees** an item. Your REST routes must check
  permissions again for every ID they change (`edit_post`, `edit_term`).
- Labels, column groups and option labels are kept as plain text: tags and
  line breaks are removed and entities decoded, so `Words < 300`,
  `Words <300`, `Score < 50 & up` or `CTR %ab` shows exactly as written. A
  `<` followed by a letter and closed by a later `>`, as in `a <b> c`, reads
  as a tag and is removed. The apps show labels as text, never as HTML. A
  cell's `text` is shown as text.
- What `render()` puts in its container is yours to escape. Build it with
  `textContent` and `createElement`, never `innerHTML` with row data.

## Performance

- Row data filters run only for the extension columns the user is shown, and
  not at all when none is. Check `$keys` before doing any work.
- Use the batch filters to read a whole page in one query
  (`update_postmeta_cache()`, `update_termmeta_cache()` or your own `IN (...)`
  query), not one query per row.
- Keep `visible( row )` synchronous and based on the row.
- Batch bulk work: a bulk action can receive up to 10,000 IDs.
- Refresh only the rows you changed: `ctx.refresh( ids )`, not `ctx.refresh()`.

## Reading a FAQ block's questions

`Licensio_KB_FAQ_Block::entries( $attributes )` returns the questions and
answers a `licensio-kb/faq` block renders, for a plugin that marks them up,
for example as `FAQPage` structured data. It is part of extension API
version 1, so check `LICENSIO_KB_EXTENSIONS_API` before calling it.

Pass the attributes as stored, such as `$block['attrs']` from
`parse_blocks()` or a shortcode's attributes; they are normalised the way the
block does it.

```php
if ( defined( 'LICENSIO_KB_EXTENSIONS_API' ) ) {
    foreach ( Licensio_KB_FAQ_Block::entries( $block['attrs'] ) as $entry ) {
        // $entry: question, answer, url (strings) and post_id (int).
    }
}
```

- One entry per article, in display order. The block renders its markup from
  the same call, so the entries are the same articles in the same order: the
  same query, the `licensio_block_article_query_args` filter and the
  per-category article order.
- `answer` is the excerpt, or an empty string when `showExcerpt` is off;
  you decide whether to skip it. An untitled article has an empty `question`,
  which the block does not print.
- `question` and `answer` are what `get_the_title()` and `get_the_excerpt()`
  return, not escaped, so they may carry HTML entities or tags. Escape them
  for HTML, or strip the tags and decode the entities for structured data.
- The result is computed once per request for each set of attributes, so the
  block and your plugin share one query, whichever runs first. It is dropped
  as soon as a post or term changes (the posts or terms `last_changed` value
  moves), so a save followed by a read in the same request never sees stale
  entries. Nothing is kept across requests.
- The filters the query, title, excerpt and permalink pass through are
  applied by the first call only. A callback added later in the request, or
  one whose answer depends on where it runs (the current user or post), does
  not change the stored entries.

## Example plugin

A complete plugin, **Acme KB Review**, that lets editors mark articles,
categories and tags as reviewed. It adds:

- Articles: a **Reviewed** column rendered by a script, a **Review** filter
  (also reachable as `edit.php?post_type=licensio_article&acme_reviewed=no`),
  a **Mark reviewed** bulk action and a **Mark reviewed** row action, hidden
  on rows already reviewed.
- Categories and Tags: a **Reviewed** column shown from its `text`, with no
  script, and the **Mark reviewed** row action.

Create the folder `wp-content/plugins/acme-kb-review/` with these two files,
then activate **Acme KB Review**.

`acme-kb-review/acme-kb-review.php`:

```php
<?php
/**
 * Plugin Name: Acme KB Review
 * Description: Example extension for the Licensio Knowledge Base apps: marks articles, categories and tags as reviewed.
 * Version: 1.0.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: acme-kb-review
 */

defined( 'ABSPATH' ) || exit;

add_action( 'plugins_loaded', 'acme_kb_review_boot' );

/**
 * Hook in once the knowledge base has loaded, if it has the extension API.
 *
 * @return void
 */
function acme_kb_review_boot() {
    if ( ! defined( 'LICENSIO_KB_EXTENSIONS_API' ) ) {
        return;
    }

    add_action( 'licensio_kb_register_extensions', 'acme_kb_review_declare' );
    add_filter( 'licensio_kb_article_extension_data_batch', 'acme_kb_review_article_cells', 10, 3 );
    add_filter( 'licensio_kb_term_extension_data_batch', 'acme_kb_review_term_cells', 10, 3 );
    add_filter( 'licensio_kb_articles_query_args', 'acme_kb_review_query_args', 10, 2 );
    add_action( 'licensio_kb_enqueue_app_extensions', 'acme_kb_review_script' );
    add_action( 'rest_api_init', 'acme_kb_review_route' );
}

/**
 * Declare the column, filter and actions.
 *
 * @param Licensio_KB_Extensions $extensions The registry.
 *
 * @return void
 */
function acme_kb_review_declare( $extensions ) {
    $extensions->add_column(
        'articles',
        'acme/reviewed',
        array(
            'label'           => __( 'Reviewed', 'acme-kb-review' ),
            'group'           => __( 'Acme', 'acme-kb-review' ),
            'default_visible' => true,
            'position'        => 'title',
        )
    );
    $extensions->add_filter(
        'articles',
        'acme/reviewed',
        array(
            'label'     => __( 'Review', 'acme-kb-review' ),
            'query_arg' => 'acme_reviewed',
            'options'   => array(
                'yes' => __( 'Reviewed', 'acme-kb-review' ),
                'no'  => __( 'Not reviewed', 'acme-kb-review' ),
            ),
        )
    );
    $extensions->add_bulk_action( 'articles', 'acme/mark-reviewed', array( 'label' => __( 'Mark reviewed', 'acme-kb-review' ) ) );

    foreach ( array( 'articles', 'categories', 'tags' ) as $screen ) {
        $extensions->add_row_action( $screen, 'acme/mark-reviewed', array( 'label' => __( 'Mark reviewed', 'acme-kb-review' ) ) );
    }

    foreach ( array( 'categories', 'tags' ) as $screen ) {
        $extensions->add_column(
            $screen,
            'acme/reviewed',
            array(
                'label'           => __( 'Reviewed', 'acme-kb-review' ),
                'default_visible' => true,
            )
        );
    }
}

/**
 * One cell of the Reviewed column.
 *
 * @param bool $reviewed Whether the item is reviewed.
 *
 * @return array<string, mixed> The cell: `reviewed` for the script, `text` for the app.
 */
function acme_kb_review_cell( $reviewed ) {
    return array(
        'reviewed' => $reviewed,
        'text'     => $reviewed ? __( 'Yes', 'acme-kb-review' ) : __( 'No', 'acme-kb-review' ),
    );
}

/**
 * Fill the Reviewed column for a page of articles.
 *
 * The posts arrive with their meta cache primed, so this adds no query.
 *
 * @param array     $data  Article ID => column key => cell.
 * @param WP_Post[] $posts The page of articles.
 * @param string[]  $keys  The extension column keys shown.
 *
 * @return array The data.
 */
function acme_kb_review_article_cells( $data, $posts, $keys ) {
    if ( ! in_array( 'acme/reviewed', $keys, true ) ) {
        return $data;
    }

    foreach ( $posts as $post ) {
        $data[ $post->ID ]['acme/reviewed'] = acme_kb_review_cell( '1' === get_post_meta( $post->ID, '_acme_reviewed', true ) );
    }

    return $data;
}

/**
 * Fill the Reviewed column for a page of categories or tags, in one meta query.
 *
 * @param array     $data  Term ID => column key => cell.
 * @param WP_Term[] $terms The terms, keyed by term ID.
 * @param string[]  $keys  The extension column keys shown.
 *
 * @return array The data.
 */
function acme_kb_review_term_cells( $data, $terms, $keys ) {
    if ( ! in_array( 'acme/reviewed', $keys, true ) ) {
        return $data;
    }

    update_termmeta_cache( array_keys( $terms ) );

    foreach ( $terms as $id => $term ) {
        $data[ $id ]['acme/reviewed'] = acme_kb_review_cell( '1' === get_term_meta( $term->term_id, '_acme_reviewed', true ) );
    }

    return $data;
}

/**
 * Narrow the Articles list by the Review filter.
 *
 * @param array $args        The WP_Query arguments.
 * @param array $ext_filters Filter key => chosen option, already validated.
 *
 * @return array The arguments.
 */
function acme_kb_review_query_args( $args, $ext_filters ) {
    if ( empty( $ext_filters['acme/reviewed'] ) ) {
        return $args;
    }

    $clause = 'yes' === $ext_filters['acme/reviewed']
        ? array(
            'key'   => '_acme_reviewed',
            'value' => '1',
        )
        : array(
            'key'     => '_acme_reviewed',
            'compare' => 'NOT EXISTS',
        );

    $args['meta_query']   = isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array();
    $args['meta_query'][] = $clause;

    return $args;
}

/**
 * Enqueue the script on each app page.
 *
 * A classic script, in the footer, depending on the extension registry.
 *
 * @param string $screen `articles`, `categories` or `tags`.
 *
 * @return void
 */
function acme_kb_review_script( $screen ) {
    wp_enqueue_script( 'acme-kb-review', plugins_url( 'acme-kb-review.js', __FILE__ ), array( 'licensio-kb-extensions' ), '1.0.0', true );
    wp_add_inline_script(
        'acme-kb-review',
        'window.acmeKbReview = ' . wp_json_encode(
            array(
                'screen' => $screen,
                'url'    => rest_url( 'acme-kb/v1/review' ),
            )
        ) . ';',
        'before'
    );
}

/**
 * Register the route the actions call.
 *
 * @return void
 */
function acme_kb_review_route() {
    register_rest_route(
        'acme-kb/v1',
        '/review',
        array(
            'methods'             => 'POST',
            'callback'            => 'acme_kb_review_mark',
            'permission_callback' => function () {
                return current_user_can( 'edit_licensio_articles' );
            },
            'args'                => array(
                'screen' => array(
                    'type'     => 'string',
                    'enum'     => array( 'articles', 'categories', 'tags' ),
                    'required' => true,
                ),
                'ids'    => array(
                    'type'     => 'array',
                    'items'    => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                    ),
                    'minItems' => 1,
                    'maxItems' => 100,
                    'required' => true,
                ),
            ),
        )
    );
}

/**
 * Mark the given items reviewed, each one the user may edit.
 *
 * @param WP_REST_Request $request The request.
 *
 * @return WP_REST_Response The IDs marked.
 */
function acme_kb_review_mark( $request ) {
    $screen     = $request['screen'];
    $taxonomies = array(
        'categories' => 'licensio_category',
        'tags'       => 'licensio_tag',
    );
    $marked     = array();

    foreach ( $request['ids'] as $id ) {
        if ( 'articles' === $screen ) {
            if ( 'licensio_article' === get_post_type( $id ) && current_user_can( 'edit_post', $id ) ) {
                update_post_meta( $id, '_acme_reviewed', '1' );
                $marked[] = $id;
            }
        } elseif ( get_term( $id, $taxonomies[ $screen ] ) instanceof WP_Term && current_user_can( 'edit_term', $id ) ) {
            update_term_meta( $id, '_acme_reviewed', '1' );
            $marked[] = $id;
        }
    }

    return rest_ensure_response( array( 'marked' => $marked ) );
}
```

`acme-kb-review/acme-kb-review.js`:

```js
( function () {
	const config = window.acmeKbReview;
	const BATCH = 100;

	function mark( ids, ctx ) {
		return window
			.fetch( config.url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': ctx.rest.nonce,
				},
				body: JSON.stringify( { screen: ctx.screen, ids } ),
			} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'Acme review failed: ' + response.status );
				}
			} );
	}

	function cell( row ) {
		return ( row.extensions || {} )[ 'acme/reviewed' ];
	}

	const rowActions = {
		'acme/mark-reviewed': {
			run( row, ctx ) {
				return mark( [ row.id ], ctx ).then( function () {
					return ctx.refresh( [ row.id ] );
				} );
			},
			visible( row ) {
				const data = cell( row );

				return ! ( data && data.reviewed );
			},
		},
	};

	if ( 'articles' !== config.screen ) {
		window.licensioKb.extensions.register( config.screen, { rowActions } );
		return;
	}

	window.licensioKb.extensions.register( 'articles', {
		columns: {
			'acme/reviewed': {
				render( el, row ) {
					const data = cell( row );
					const badge = document.createElement( 'span' );

					badge.textContent = data && data.reviewed ? 'Reviewed' : 'Not reviewed';
					el.appendChild( badge );
				},
			},
		},
		bulkActions: {
			'acme/mark-reviewed': {
				async run( ids, ctx ) {
					for ( let i = 0; i < ids.length; i += BATCH ) {
						await mark( ids.slice( i, i + BATCH ), ctx );
					}

					await ctx.refresh( ids );
					ctx.clearSelection();
				},
			},
		},
		rowActions,
	} );
} )();
```

What to expect:

- **All Articles**: the Reviewed column shows after Title (in the Columns
  picker under Acme). The Filters panel has a Review dropdown. Selecting rows
  offers Mark reviewed in the bulk bar; each unreviewed row offers a Mark
  reviewed row action, which disappears once the row is reviewed.
- **Categories** and **Tags**: each row shows Yes or No in a Reviewed column,
  and offers Mark reviewed (in Categories, in the row's menu) until it is
  reviewed.
- A user without `manage_licensio_kb` (an author, say) does not see the
  Categories and Tags items, and the route marks no terms for them, while
  they can still mark the articles they may edit.
