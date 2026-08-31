# Lisensio Knowledge Base

A fully standalone WordPress documentation and self-service support plugin. It requires no other plugin: neither SLSWC nor WooCommerce need be installed.

`SPEC.md` is the authoritative technical specification. This file documents the **scaffold contracts** that every sub-issue builds on, so nothing has to be reverse-engineered from the code.

---

## Requirements

| | |
|---|---|
| PHP | 7.4+ |
| WordPress | 6.4+ |
| MySQL / MariaDB | 5.6 / 10.0+ |
| Node | 20+ |

## Conventions

- **No PHP namespaces and no fully-qualified class names.** WordPress class-file convention: `includes/class-lisensio-kb-foo.php` defines `class Lisensio_KB_Foo`. The only exception is `includes/class-lisensio-kb-db.php`, which is deliberately the single file that names the Strauss-prefixed vendor classes, so nothing else has to.
- Prefixes: `LISENSIO_KB_` constants, `Lisensio_KB_` classes, `lisensio_` for **all** hooks, filters, options and table names, and `lisensio_kb_` for global functions and shortcodes.
  The bare `lisensio_` function namespace already belongs to `themes/lisensio` (`inc/knowledge-base.php` defines `lisensio_is_knowledge_base()`), so a bare-prefixed global function here would be a fatal redeclare.
- WordPress Core coding standards, four spaces, no tabs. Full PHPDoc on every file, class, method and function.
- Every string is translatable under the `lisensio-knowledge-base` text domain.

## Getting started

```bash
composer install     # installs the toolchain and re-runs Strauss into libraries/
npm install
npm run build        # Vite -> dist/, wp-scripts -> dist/blocks/
```

### Quality gates

```bash
composer run lint      # PHPCS against WPCS
composer run fix       # PHPCBF
composer run analyze   # PHPStan level 5
vendor/bin/phpunit     # unit tests
npm test               # Playwright
```

CI runs lint, analyze and the unit tests on PHP 8.2 and 8.3, plus the JS build. The PHP 7.4 floor is enforced by `PHPCompatibilityWP` inside `phpcs.xml`; the toolchain itself needs PHP 8, so it cannot be installed on 7.4.

### Admin app development

```bash
npm run dev            # Vite on port 6913
```

then in `wp-config.php`:

```php
define( 'HMR_DEV', 'lisensio-kb' );
```

`Lisensio_KB_Assets` only uses the dev server when that constant matches **and** the port answers, so a forgotten constant falls back to the built assets rather than blanking the screen.

### Building a release

```bash
bin/build-release.sh --dry-run   # build, verify, print both manifests, discard
bin/build-release.sh             # the same, but write the zips into releases/
```

One command produces both artifacts from a clean checkout:

| Artifact | Contents |
|---|---|
| `lisensio-knowledge-base.zip` | the free plugin; no `pro/` path anywhere in it |
| `lisensio-knowledge-base-pro.zip` | the same tree plus `pro/` |

Both run the production Vite and `wp-scripts` builds first and ship compiled `dist/` output only. `libraries/` ships in both — it is the Strauss-prefixed StellarWP DB and the plugin cannot run without it.

The staged tree is assembled from an **allowlist** (`SHIP_PATHS` in the script), not by pruning a copy of the repository. An exclude list ships whatever it has not heard of yet; an allowlist fails in the safe direction. Nothing under `tools/` or `tests/`, no sources, no `node_modules` and no dev config reaches either zip, and a second assertion re-checks that after staging.

`bin/build-release.sh` requires `php`, `zip`, `unzip`, `npm` and WP-CLI. WP-CLI is used only to generate `languages/lisensio-knowledge-base.pot`; if it is not on `PATH` the script downloads `wp-cli.phar` into `.tmp/`, or you can point `WP_CLI_BIN` at your own.

### Release gates

The build refuses to package when any of these fails, and CI runs all of them in the `release` job.

| Gate | What it asserts |
|---|---|
| `bin/check-versions.sh` | the version is identical in the plugin header, `LISENSIO_KB_VERSION`, `readme.txt`'s `Stable tag` and `package.json`, and that the readme's `Requires at least` and `Requires PHP` match the plugin header |
| `bin/check-i18n.sh` | no PHP translation call is missing the `lisensio-knowledge-base` text domain or carries another one, and `.eslintrc.json` still pins the JS rule |
| `bin/check-free-boundary.sh` | the free artifact contains no `pro/` path and no SLSWC or WooCommerce symbol |
| `bin/check-gates-selftest.sh` | each of the three gates above still goes red on a violation, and still goes green on something that only looks like one |

The boundary check is about **symbols**, not prose. Two shipped files name WooCommerce in a comment and must keep doing so, so PHP is run through `bin/php-code-only.php` — PHP's own tokeniser, not a regex — and the patterns are matched against the code that is left. Files under `dist/` are minified: a minifier mints identifiers like `wC(` and `WC_a`, so bundles are matched against the long tokens (`slswc`, `woocommerce`, `wcSettings`, `wc/v1`) only. The hole that would open there — a WooCommerce call written in `apps/` or `blocks/` and only ever shipped minified — is closed by scanning those source directories with the full pattern set even though they are not themselves shipped.

There is no per-line exemption marker. `readme.txt` therefore answers *"Does this require any other plugin?"* rather than naming the plugin it does not require: a legitimate free-side mention has to be an argued change to the pattern list, reviewed like any other.

The JavaScript half of the text-domain rule lives in `.eslintrc.json` and runs under `npm run lint:js`. `@wordpress/eslint-plugin` enables `@wordpress/i18n-text-domain` by default but leaves `allowedTextDomain` unset, and with no domains configured the rule reads a missing domain as WordPress core's and says nothing. Pinning it is what makes the rule do anything, which is why `bin/check-i18n.sh` asserts the pin is still there.

---

## Repository layout

```
lisensio-knowledge-base.php  bootstrap, constants, lifecycle hooks
uninstall.php                drops tables and options
readme.txt                   the wordpress.org listing        (#11)
includes/                    class-lisensio-kb-*.php and the function files
libraries/                   Strauss-prefixed vendor code (committed)
languages/                   lisensio-knowledge-base.pot, regenerated on release
bin/                         Strauss wrapper, the release build and its gates (#11); never shipped
templates/                   classic PHP templates          (#5)
block-templates/             block theme templates          (#6)
blocks/                      wp-scripts block sources       (#7)
apps/                        Vue admin apps                 (#8, #9, #10)
assets/                      frontend css + js sources
tools/                       build helpers and the disposable migrator (#12); never shipped
pro/                         pro-only code; excluded from the free build
tests/                       PHPUnit (tests/Unit) and Playwright (tests/Playwright)
```

## What the scaffold gives you

### Container

`Lisensio_KB::instance()` (or the `lisensio_kb()` helper) boots on `plugins_loaded` at priority 5 and exposes `settings()`, `post_types()`, `assets()`, `visitor()` and `rest_api()`. Attach your own component on `lisensio_loaded`, which fires with the container as its argument.

### Autoloading

Any `Lisensio_KB_*` class placed in `includes/`, `includes/abstracts/`, `includes/admin/` or `includes/rest/` is autoloaded from its `class-lisensio-kb-*.php` file. Add a directory with the `lisensio_autoload_paths` filter.

### Database

All access goes through `Lisensio_KB_DB`, never raw `$wpdb`:

```php
Lisensio_KB_DB::table( 'feedback' )->where( 'article_id', $id )->getAll();
```

Table names are passed unprefixed (`search_index`, `search_log`, `article_views`, `feedback`); the `lisensio_` and WordPress prefixes are both applied for you. `Lisensio_KB_DB_Installer` owns the schema and the schema version. **No table has an IP column**, and a unit test enforces that.

`Lisensio_KB_DB_Installer::supports_fulltext()` reports whether the search index table actually carries its FULLTEXT key, read back from the live table after install rather than predicted in advance. That is how the search layer (#4) chooses between the FULLTEXT and LIKE drivers.

**Do not assume the tables exist just because the plugin is active.** Schema installs and upgrades run on `admin_init`, so a site nobody logs into can be running with a stale or absent schema. Guard your first read or write with a table-exists check, exactly as you would guard on `supports_fulltext()`.

### Multisite

Activation installs per site: a network activation walks every site, and `wp_initialize_site` installs into subsites created later. Very large networks (`wp_is_large_network()`) are skipped and self-heal instead, on the first admin request per site.

Uninstall walks every site too. Anything you store per site — options, cron events — must be cleaned up on the `lisensio_uninstalled` action, which fires once per site inside `switch_to_blog()`.

### Settings

`lisensio_kb()->settings()` is typed get/set over the `lisensio_settings` option:

```php
$slug = lisensio_kb()->settings()->get( 'article_slug' );   // 'docs'
lisensio_kb()->settings()->set( 'analytics_retention_days', 30 );
```

Unregistered keys are rejected. The admin UI is **Knowledge Base → Settings**, described below.

Add your own with the `lisensio_settings_registry` filter. A registry entry is:

| Key | Meaning |
|---|---|
| `type` | `string`, `int`, `float`, `bool` or `array`. |
| `default` | Used when nothing valid is stored. |
| `sanitize` | Optional callable, applied **before** the type cast. Return `null` to reject the value — the default is stored instead, so a rejected write never persists an empty string. |
| `min` / `max` | Optional bounds, clamped after casting a numeric type. Clamping is for nonsense input; a value that expresses a real intent the setting cannot honour should be rejected by `sanitize` instead, so it falls back to the default. `analytics_retention_days` does exactly that with `0` — see its sanitizer. |
| `flush_rewrites` | Set when changing the value invalidates the rewrite rules. |

**Validate through this seam, not in your own handler.** Sanitization runs on read as well as on write, so a value that reached the option by another route — a hand-edited row, a database import — still cannot hand you a malformed slug or an unescapable colour.

`update()` and `set()` return `true` when the recognised keys hold their requested values afterwards, **including when they already did**. They return `false` only when no key you passed is registered. Do not treat a no-op save as a failure.

Changing a setting marked `flush_rewrites` records a request that `Lisensio_KB_Post_Types` honours late on `init`; you do not need to call `flush_rewrite_rules()` yourself, and you should not, because settings are written long before the permastructs are registered.

`delete_data_on_uninstall` (default **false**) decides whether the four data tables are dropped when the plugin is deleted. The default preserves visitor-written feedback; the Privacy group on the settings screen surfaces the toggle.

### Settings screen

**Knowledge Base → Settings** is a Vue app (`apps/settings/`) over `GET`, `POST` and `DELETE /wp-json/lisensio-kb/v1/settings`, all three gated on `manage_lisensio_kb`. Every response carries the same three keys:

| Key | What it is |
|---|---|
| `settings` | the current values, as `Lisensio_KB_Settings::all()` resolves them |
| `defaults` | the registry defaults |
| `schema` | the groups and fields the screen renders itself from |

`POST` takes the values nested under one object — `{ "settings": { "brand_color": "#b91c1c" } }` — not as top-level parameters, because WordPress merges its own `_locale` and `context` into the request and a flat body could not tell those apart from a setting. `DELETE` deletes the option, which restores exactly whatever the registry declares, and raises the rewrite flag if a slug moved.

**The write route is stricter than the store, deliberately.** `Lisensio_KB_Settings::update()` coerces: it skips a key it does not recognise, clamps a number outside its bounds and substitutes the default for a value its sanitizer rejects. That is right for a store reading an option somebody may have edited by hand, and wrong for an API, where storing something other than what was sent is indistinguishable from a bug. So the controller:

- refuses an unknown key with a `400`, rather than dropping it and answering `200`;
- refuses an out-of-range number rather than clamping it;
- validates **every** field before writing **any**, so a payload with one bad value writes nothing at all and comes back with a message per failing field in `data.params`.

Nothing in the controller knows the name of a setting. A key you add through `lisensio_settings_registry` is validated against its own declared `type`, `min`, `max` and `sanitize` with no change there.

`Lisensio_KB_Settings_Schema` supplies the part the registry does not: a label, a description, a control and a group per key. A key it has never heard of still gets an editor — it falls into **General** with a control inferred from its type — so an extension is never stored-but-invisible. Filter `lisensio_settings_schema` to move it somewhere better.

The Appearance group previews live against the same CSS custom properties `Lisensio_KB_Templates::custom_properties()` publishes on `.lisensio-kb`; the enumerated values come from the server so the preview cannot drift from the front end, and a unit test pins the two together.

### Visitor identity

`lisensio_kb()->visitor()`:

- `get_hash()` — `hash_hmac( 'sha256', ip . '|' . user_agent, daily_salt )` for this request. The salt is the key, and the delimiter prevents two distinct visitors hashing to one identity.
- `get_hashes()` — the current hash plus, for 24 hours after a rotation, the previous-salt hash. **Enforce "once per visitor" against all of these**, or a salt rotation lets the same person vote or be counted twice.

The salt lives in an autoloaded option and is rotated daily by the `lisensio_rotate_visitor_salt` cron event. The raw IP is never persisted, never returned and never logged.

### REST API

Namespace `lisensio-kb/v1`. Extend `Lisensio_KB_REST_Controller` and register the class through the `lisensio_rest_controllers` filter — do not hook `rest_api_init` yourself. The base class supplies three permission callbacks:

| Callback | Use for |
|---|---|
| `public_permissions_check` | public reads |
| `public_write_permissions_check` | public writes; requires a valid `wp_rest` nonce |
| `manage_permissions_check` | `manage_lisensio_kb` (falls back to `manage_options` until #3 lands) |

**Know what the nonce buys on a public write.** For a logged-in user it is bound to their user ID and session token, so it is a real CSRF control. For an anonymous visitor it is not a per-visitor secret: core computes it over user ID 0 with an empty session token, so one value is valid for every logged-out visitor site-wide for the whole 12–24 hour tick, and it is printed into public markup. It proves the caller read a page of this site recently, and little else.

Anonymous write endpoints therefore need a second control. Use the shared seam:

```php
$limited = $this->check_rate_limit( 'feedback', 5, MINUTE_IN_SECONDS );

if ( is_wp_error( $limited ) ) {
    return $limited;
}
```

`check_rate_limit()` is keyed on `Lisensio_KB_Visitor::get_hashes()`, so it never touches an IP address, and it reads the count across every hash the visitor could currently be known by — a salt rotation inside the window does not hand them a fresh budget. Choosing the bucket, limit and window is yours; the scaffold calls it for no route.

Every specified route is already registered as a stub returning `501`. Stubs register at priority 100, after real controllers, so implementing a route wins it; still remove your route from `lisensio_rest_stub_routes` when you do. Note that `register_rest_route()` *merges* handler arrays, so a route you implement for `GET` only leaves the stub answering `POST` with 501 — the filter is mandatory in practice, not optional.

### Templates

```php
lisensio_kb_get_template( 'single-article', array( 'post' => $post ) );
```

`lisensio_kb_get_template()` calls `wp_is_block_theme()` and routes to the block layer or the classic layer, falling back to the classic layer when the block layer has no template for the slug.

- Classic: `{stylesheet}/lisensio-kb/{name}.php` → `{template}/lisensio-kb/{name}.php` → plugin `templates/{name}.php`.
- Block: `{stylesheet}/templates/{name}.html` → `{template}/templates/{name}.html` → plugin `block-templates/{name}.html`.

Template names are validated as plain slug paths (`single-article`, `parts/breadcrumbs`); anything else resolves to an empty string, because both resolvers turn the name into a filesystem path that is included or read.

Classic templates receive their arguments as a single `$args` array. There is no `extract()`.

#### The classic template set

`templates/` ships fourteen templates. The five page templates are loaded by
`Lisensio_KB_Templates` on `template_include`; the partials are rendered by
those pages, by a hook callback, and by the blocks, through
`lisensio_kb_get_template()`.

| Template | Loaded for | Arguments |
|---|---|---|
| `archive.php` | the article post type archive | — |
| `single.php` | a single article | — |
| `taxonomy-category.php` | a `lisensio_category` archive | — |
| `taxonomy-tag.php` | a `lisensio_tag` archive | — |
| `search-results.php` | a search carrying `post_type=lisensio_article` | — |
| `hero.php` | partial, rendered by all five page templates | `title`, `subtitle`, `breadcrumbs` |
| `breadcrumbs.php` | partial | `items` |
| `article-card.php` | partial | `post`, `show_excerpt` |
| `category-card.php` | partial | `term`, `show_children`, `show_count`, `show_icon` |
| `no-results.php` | partial | `title`, `description` |
| `feedback-form.php` | partial | `article_id`, `score` |
| `article-list.php` | the article list block and shortcode | `posts`, `columns`, `show_excerpt` |
| `category-grid.php` | the category grid block and shortcode | `terms`, `columns`, `show_count`, `show_icon`, `show_children` |
| `faq.php` | the FAQ block and shortcode | `items`, `open_first` |

Copy any of them into `{theme}/lisensio-kb/` to override it.

`hero.php` is the one heading block every page template renders — breadcrumbs,
the heading with its last word in the brand gradient, and an optional
standfirst. A theme that overrides it replaces the hero on all five views at
once, which is how a theme hands the knowledge base the hero it already uses
elsewhere. That is also what clears a fixed site header: the hero's own top
padding is the only thing standing between a theme's fixed header and the first
line of knowledge base content, so a theme with a taller header should override
this template rather than the five pages.

The loader stands aside entirely under a block theme, where the block template
layer answers the same requests.

Local variables inside a template carry the `lisensio_kb_` prefix, which the
project's PHPCS ruleset requires of anything assigned at a file's top level.

`block-templates/` is #6.

#### Styling

`assets/css/lisensio-kb.css` is scoped to `.lisensio-kb` and is enqueued only
on knowledge base views. Everything the settings control — brand colour, the
RGB triplet and readable foreground derived from it, layout width, card
shadow, border and background — is emitted by
`Lisensio_KB_Templates::inline_css()` as CSS custom properties on
`.lisensio-kb`, never as a rule-level override, so changing a setting restyles
every component that derives from it. Values are re-validated there before
being printed into the style element rather than trusted from the option.

### Blocks and shortcodes

Every knowledge base block is dynamic, and its render callback is registered as
a shortcode as well, so the block editor, a block theme and a page builder all
reach one implementation and emit the same markup.

| Block | Shortcode | Attributes |
|---|---|---|
| `lisensio-kb/article-list` | `[lisensio_kb_articles]` | `category`, `orderBy`, `order`, `count`, `columns`, `showExcerpt` |
| `lisensio-kb/category-grid` | `[lisensio_kb_categories]` | `parent`, `orderBy`, `order`, `count`, `columns`, `showCount`, `showIcon`, `showChildren` |
| `lisensio-kb/breadcrumbs` | `[lisensio_kb_breadcrumbs]` | `showHome` |
| `lisensio-kb/faq` | `[lisensio_kb_faq]` | `category`, `orderBy`, `order`, `count`, `showExcerpt`, `openFirst` |

WordPress lower-cases shortcode attribute names and hands every value over as a
string, while the editor sends the camelCase names from `block.json` carrying
real integers and booleans. `Lisensio_KB_Block::normalize()` matches names
case-insensitively and casts each value to the type of its declared default, so
`[lisensio_kb_articles showExcerpt="false"]` and the editor's
`showExcerpt: false` produce the same markup.

`get_block_wrapper_attributes()` is deliberately not used: it reads the block
currently being rendered, which does not exist on the shortcode path, so it
would make the two paths disagree. The wrapper is built by
`Lisensio_KB_Block::wrapper()` and the blocks opt out of `customClassName`.

Blocks are registered from `dist/blocks/<name>` when the plugin has been built
and from `blocks/<name>` otherwise, so a checkout that has never run
`npm run build` still renders — only the editor bundle is missing. The editor
preview is `ServerSideRender` against the real callback, never a JSX
re-implementation of the markup.

### Frontend hook contract

Both template layers fire the same three hooks, so feedback, analytics and future gating attach once:

| Hook | Fires | Argument |
|---|---|---|
| `lisensio_before_article` | before the article body | `WP_Post` |
| `lisensio_after_article` | after the article body | `WP_Post` |
| `lisensio_article_footer` | in the article footer region | `WP_Post` |

Template authors call `lisensio_kb_before_article()`, `lisensio_kb_after_article()` and `lisensio_kb_article_footer()` rather than `do_action()` directly, so the argument contract stays in one place.

### Assets

`lisensio_kb()->assets()`:

- `enqueue_app( $handle, 'apps/settings/index.ts' )` — HMR in development, `dist/.vite/manifest.json` in production. Enqueues the entry's own CSS **and** the CSS of every chunk it imports, walked recursively, which is where shared Vue/Ant Design styles live once two apps import the same component.
- `block_asset( 'search' )` — the dependency list and version from a wp-scripts `index.asset.php`. The name must be a plain slug.

HMR requires all three of: `HMR_DEV === 'lisensio-kb'`, a non-production `wp_get_environment_type()`, and a reachable dev server. The result is memoized per request.

**The shared frontend namespace (`window.lisensioKB`) is a classic script, not a Vite entry**, registered under the handle `lisensio-kb`. Declare it as a dependency:

```php
wp_enqueue_script( 'lisensio-kb-search', …, array( 'lisensio-kb' ) );
```

It cannot be a module: module scripts are always deferred and execute after every classic script, so a classic `search.js` depending on a module namespace would run first and find `window.lisensioKB` undefined. That is a browser execution-order rule, and declared dependency order cannot fix it.

## Filters and actions introduced here

| Name | Kind | Purpose |
|---|---|---|
| `lisensio_loaded` | action | The container finished booting. |
| `lisensio_activated` / `lisensio_deactivated` | action | End of activation / deactivation. |
| `lisensio_upgraded` | action | The plugin version moved forward. |
| `lisensio_db_installed` | action | Tables created or upgraded; passed the FULLTEXT flag. |
| `lisensio_uninstalled` | action | The plugin is being removed from a site; fires once per site. Passed whether the data tables were dropped. |
| `lisensio_visitor_salt_rotated` | action | The daily salt was rotated. |
| `lisensio_feedback_recorded` | action | A vote was written. Passed the article ID and the vote; never the comment. |
| `lisensio_feedback_score_updated` | action | An article's usefulness score was recomputed. Passed the article ID and the figures. |
| `lisensio_before_article`, `lisensio_after_article`, `lisensio_article_footer` | action | The frontend hook contract. |
| `lisensio_kb_after_archive` | action | The last thing in the archive, inside its wrapper. The seam a theme attaches its own closing section to — a call to action band, a support link. The plugin ships no such copy itself. No arguments. |
| `lisensio_before_template`, `lisensio_after_template` | action | Around a classic template include. |
| `lisensio_autoload_paths` | filter | Extra directories for the class autoloader. |
| `lisensio_settings_registry`, `lisensio_settings` | filter | Extend or override settings. |
| `lisensio_settings_schema` | filter | The groups and fields the settings screen renders itself from. |
| `lisensio_admin_menu_pages` | filter | Register a page under the Knowledge Base menu. |
| `lisensio_article_post_type_args`, `lisensio_category_taxonomy_args`, `lisensio_tag_taxonomy_args` | filter | Content model registration arguments. |
| `lisensio_supports_fulltext` | filter | Override the FULLTEXT capability probe. |
| `lisensio_visitor_ip` | filter | Supply the client IP behind a trusted proxy. Hashed immediately, never stored. |
| `lisensio_rest_controllers`, `lisensio_rest_stub_routes` | filter | Register controllers; release a reserved route. |
| `lisensio_template_path`, `lisensio_use_block_templates`, `lisensio_locate_template`, `lisensio_locate_block_template`, `lisensio_get_template` | filter | Template resolution. |
| `lisensio_template_name` | filter | The classic template chosen for the current request. |
| `lisensio_breadcrumb_items` | filter | The breadcrumb trail. |
| `lisensio_block_article_query_args` | filter | The `get_posts()` arguments a block lists articles with. Passed the block name. |
| `lisensio_block_category_grid_terms` | filter | The categories a category grid lists, before ordering and trimming. |
| `lisensio_custom_properties` | filter | The CSS custom properties printed on `.lisensio-kb`. |
| `lisensio_dev_server_host`, `lisensio_dev_server_port` | filter | Vite dev server location. |

### Feedback and voting

`Lisensio_KB_Feedback` owns every read and write of `lisensio_feedback` and the
derived usefulness score, and boots the three pieces around it: the frontend
widget, the inbox screen and the REST controller.

| Piece | Where |
|---|---|
| Voting UI | `Lisensio_KB_Feedback_Widget` on `lisensio_article_footer`, rendering `templates/feedback-form.php` |
| Behaviour | `assets/js/feedback.js` — dependency-free, enhances the server-rendered markup, announces the outcome in a `role="status"` region |
| Write endpoint | `POST /lisensio-kb/v1/feedback` — nonce, per-visitor rate limit, one vote per visitor per article |
| Inbox endpoints | `GET /lisensio-kb/v1/feedback`, `PATCH /lisensio-kb/v1/feedback/{id}` — `manage_lisensio_kb` |
| Inbox screen | Knowledge Base → Feedback, the Vue app in `apps/feedback/` |
| Usefulness column | supplied through `lisensio_article_usefulness_column` |

One vote per visitor per article is enforced by a lookup against every hash
`Lisensio_KB_Visitor::get_hashes()` returns, plus the user ID for a logged-in
reader. No cookie is consulted, so a rotation of the daily salt, a cleared
cookie jar or a second browser does not buy a second vote. The rule and the
score arithmetic are exercised against a real MySQL or MariaDB server in
`tests/Integration/Lisensio_KB_Feedback_Integration_Test.php`, because a `$wpdb`
double can only answer what it was told to.

The score is cached in three post meta keys — `_lisensio_usefulness_score`,
`_lisensio_usefulness_up` and `_lisensio_usefulness_down` — and recomputed from
the raw rows on every vote. An article nobody has voted on reports a `null`
score, which is not the same claim as nought per cent.

Comments are stripped of markup by `sanitize_textarea_field()` before they are
stored, escaped again wherever PHP prints them, and rendered by the inbox with
Vue text interpolation. Nothing in this flow uses `v-html`, and nothing in it
reads or writes an IP address.

## Privacy

No IP address is written to any table. Visitor identity is a rotating salted hash, the salt is not exposed to the frontend, and analytics retention defaults to 90 days. See `SPEC.md` for the full privacy layer.

## Dependency note: the esbuild override

`package.json` pins `esbuild` to `0.21.5` through `overrides`. Do not remove this
without checking that `npm ci` still works from a clean checkout.

`@wordpress/stylelint-config`, pulled in transitively by `@wordpress/scripts`,
depends on a newer vite which in turn wants esbuild `^0.27`. That put two esbuild
versions in the tree, and the three newest platform packages in the 0.27 line
(`@esbuild/netbsd-arm64`, `@esbuild/openbsd-arm64`, `@esbuild/openharmony-arm64`)
are written into `package-lock.json` without their `optional: true` flag. `npm ci`
then treats them as required and fails with `EBADPLATFORM` on any machine that is
not NetBSD or OpenBSD on arm64.

The failure only appears on a clean install. `npm install` tolerates it, so local
development and `npm run build` work fine and CI is the first thing to break.
