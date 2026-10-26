=== Licensio Knowledge Base ===
Contributors: mahlamusa
Tags: knowledge base, documentation, faq, help center, docs
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A fast, standalone knowledge base with block-first authoring, relevant search and privacy-safe analytics.

== Description ==

Licensio Knowledge Base turns WordPress into a documentation and self-service support site. It is completely standalone: it needs no other plugin, no e-commerce platform and no external service.

Articles are a post type of their own, with their own categories and tags, their own templates and their own search index. Everything a visitor does (the searches that found nothing, the articles that were read, the answers voted unhelpful) is recorded without ever storing an IP address, and reported back to you in the admin.

= A knowledge base home that points the way =

* A help topics page that groups every article under its category, with an article count beside each category name and a View all link into it.
* A sidebar with Popular Articles (your most viewed) and a Need Support card that leads wherever you choose, such as your contact page.
* Category pages that list every article in the category, with the full category list in the sidebar and the current one highlighted.
* Single articles with breadcrumbs, a search bar, the last-updated date, and an "On this page" table of contents built from the article's headings.

= Authoring =

* A Knowledge Base post type with category and tag taxonomies.
* Category icons (inline SVG, sanitized on save, or an image), descriptions and a manual display order.
* Five dynamic blocks, each previewed in the editor by the same code that renders it on the front end.
* **Knowledge Base Search** block: an instant search box with keyboard-navigable suggestions.
* **Knowledge Base Articles** block: a list of articles, optionally filtered to one category.
* **Knowledge Base Categories** block: a grid of categories with their article counts.
* **Knowledge Base Breadcrumbs** block: the trail from the site home to the article or category being viewed.
* **Knowledge Base FAQ** block: articles as an expandable list of questions and answers.
* Every block is also a shortcode (`[licensio_kb_search]`, `[licensio_kb_articles]`, `[licensio_kb_categories]`, `[licensio_kb_breadcrumbs]`, `[licensio_kb_faq]`), and the sidebar is available as `[licensio_kb_sidebar]`, so page builders and classic themes get identical markup.

= Managing articles, categories and tags =

* **All Articles**: status tabs with counts, search, and filters for category, tag, author, date, uncategorised, untagged and articles that need attention. Columns for categories, tags, attachments, helpfulness rating, views and reading time, with views and rating over the last 7, 30 or 90 days, so you can see at a glance which articles work. Quick edit inline, and bulk actions that can cover every matching article, not just one page.
* **Categories**: a drag-and-drop category tree with icons and images, your own article order inside each category, and delete or merge that moves the articles and subcategories where you choose.
* **Tags**: find unused tags, rename inline, merge tags, bulk delete them, or convert them into categories.
* Every change asks for confirmation before it is saved, and trash, status and category or tag changes can be undone for a few seconds afterwards.
* Prefer the classic WordPress screens? Turn on **Classic screens** under **Knowledge Base > Settings > General**, or return false from the `licensio_kb_use_content_manager` filter.

= Search that finds things =

* A dedicated search index, kept up to date as articles are saved, so search does not depend on WordPress's own post search.
* MySQL FULLTEXT relevance ranking where the server supports it, with an automatic fallback that keeps working where it does not.
* Type-ahead suggestions with the matching words highlighted, from a dependency-free script with full ARIA combobox semantics and keyboard navigation.
* Every search is logged, including the ones that returned nothing: the fastest way to find the article you have not written yet.
* Rebuild the index from **Knowledge Base > Tools > Search Index**, or with `wp licensio-kb reindex` from WP-CLI.

= Feedback =

* "Was this helpful?" voting on every article, with a comment box after a negative vote.
* A feedback inbox for reading, filtering and triaging responses as New, Reviewed or Actioned, with bulk actions.
* A helpfulness score per article, shown in the article list and sortable.

= Analytics, without tracking people =

* Searches, search effectiveness, article views and empty searches, reported in a dashboard with a date-range picker and an activity chart.
* A "Questions with no answer" list of the searches that found nothing, each with a one-click link to write the missing article.
* Visitors are counted through a salted hash that rotates every day. No IP address is ever written to the database.
* Analytics can be switched off entirely. Raw events are pruned after the retention period you choose, and WordPress's personal-data export and erase tools cover the plugin's data.

= Switching from another knowledge base =

* An import wizard under **Knowledge Base > Tools** brings articles, categories, tags, votes, views and searches across from Heroic Knowledge Base and BetterDocs, even with the old plugin deactivated.
* Map each old category onto a new one or onto one you already have, verify everything with a dry run, then import. An interrupted import resumes where it stopped, and running it again never duplicates anything.
* Old article, category and tag URLs redirect (301) to their new addresses automatically, and the full old-to-new URL map downloads as a CSV.
* A migration log records every item: what it was, where it went, its categories, each meta field and whether it was carried over, and a plain-language reason for anything that failed.
* The old plugin's data is only ever read, never changed, and no IP address from its analytics tables is imported.

Heroic Knowledge Base and BetterDocs are trademarks of their respective owners. Licensio Knowledge Base is not affiliated with, endorsed by or sponsored by them; the names are used only to identify the plugins the import wizard reads from.

= Tools for keeping it tidy =

* **Duplicates**: finds articles and terms that exist twice, typically after a migration, and resolves each set: one article is kept and its twins go to the trash.
* **Data maintenance**: recount every article's helpfulness score from the raw votes, or delete analytics past the retention window now instead of waiting for the scheduled cleanup.

= Make it look like your site =

* Takes its text colour, link colour and fonts from your theme, on light and dark themes alike. Set an optional brand colour to drive links, buttons and accents; a readable text colour is picked for you.
* Choose the layout width, the card style (raised, outlined or flat), the archive header (solid colour, gradient or image), the archive heading, the search placeholder and the number of category columns.
* Put the sidebar on the left, the right or nowhere, separately for the home, categories and articles, and make it follow the reader down long articles.
* Choose your own article, category and tag URL bases, with optional nested category URLs.

= Works with any theme =

* Classic PHP templates for the archive, single articles, categories and tags, all overridable from your theme.
* Block templates registered with the Site Editor, which render the same knowledge base layout as the classic templates inside your block theme's header and footer.
* Both layers fire the same hooks, so anything you attach works under either.

= For developers =

* More than 60 actions and filters, all prefixed `licensio_`.
* A REST API under `licensio-kb/v1` for search, feedback, analytics and settings.
* A `manage_licensio_kb` capability, granted to administrators and editors, controls every knowledge base screen.
* Every string is translatable under the `licensio-knowledge-base` text domain.

== Installation ==

1. In the WordPress admin, go to **Plugins > Add New**, search for "Licensio Knowledge Base" and click **Install Now**. Or upload the `licensio-knowledge-base` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen.
3. Add your first articles under **Knowledge Base > Add New Article**, and file them into categories.
4. Visit **Knowledge Base > Settings** to set your URL bases, brand colour, sidebar and analytics retention.
5. If article URLs do not resolve straight away, visit **Settings > Permalinks** once to refresh them.

Your knowledge base is served at `/docs/` by default. The plugin creates its own database tables on activation. On a multisite network it creates them per site, including for sites added later.

== Frequently Asked Questions ==

= Does this require any other plugin? =

No. The plugin is entirely standalone: no e-commerce plugin, no page builder and no external service. Install it on a bare WordPress site and it works.

= Will it work with my theme? =

Yes. Classic themes get the plugin's own PHP templates, and block themes get Site Editor templates. Both use the same stylesheet, which sets no colours or fonts of its own: text, links and fonts come from your theme. The optional brand colour, layout width and card style settings fine-tune the match.

= Can I override the templates in my theme? =

Yes. Copy any file from the plugin's `templates/` directory into a `licensio-kb/` directory in your theme and edit it there.

This works for block themes too. The five block templates (Single Article, Article Archive, Article Category, Article Tag and Article Search Results) each render the knowledge base layout as one Custom HTML block holding a `[licensio_kb_view]` shortcode, which outputs the same `*-body.php` file the classic templates use. In the Site Editor you can still change the header and footer template parts, add blocks before or after the knowledge base layout, or reset a template to the plugin's copy. To change the layout itself, copy the matching body file (`archive-body.php`, `category-body.php`, `single-body.php`, `tag-body.php` or `search-body.php`) into `licensio-kb/` in your theme, or use the plugin's `licensio_` actions and filters.

= Can I change the /docs/ address? =

Yes. Under **Knowledge Base > Settings > Slugs and permalinks** you can set the article, category and tag bases, and choose whether category URLs include their parent categories.

= How do I show the "Need Support?" card? =

Enter a URL under **Knowledge Base > Settings > Sidebar > Support URL**, such as your contact page. The card stays hidden while the field is empty.

= Does search need a third-party service? =

No. Search runs against a table in your own database, using MySQL FULLTEXT indexing where your server supports it and a portable fallback where it does not.

= Does the plugin store visitors' IP addresses? =

No. Visitors are identified by a salted hash that rotates every day, which is enough to avoid double-counting a page view but not enough to re-identify anyone. No IP address is written to any table. See the Privacy section below.

= Can I move my articles over from Heroic Knowledge Base or BetterDocs? =

Yes. The import wizard under **Knowledge Base > Tools** carries over articles, categories, tags, votes, views and searches, sets up redirects from the old URLs, and can be run with a dry run first. The old plugin does not need to be active.

= What happens to my articles if I deactivate the plugin? =

Nothing. Deactivating leaves all content and data in place. Data is only removed when you delete the plugin, and then only if you have turned on **Delete everything on uninstall** under **Knowledge Base > Settings > Privacy**. With it on, deleting the plugin drops its tables (feedback and votes, the search index, search and view history, and the import log) and deletes the settings (including the permalink slugs), the import redirects and the import URL map files. With it off, all of that is kept. Articles, categories and tags are never deleted by uninstall.

= Who can manage the knowledge base? =

Administrators and editors. Both are given the `manage_licensio_kb` capability on activation, and you can grant it to other roles with any role editor.

= Is the plugin translatable? =

Yes. Every string is translatable under the `licensio-knowledge-base` text domain, and a `.pot` file ships in `languages/`.

== Privacy ==

Licensio Knowledge Base stores the following in your own database, and sends nothing anywhere else:

* **Article views and searches**, each with a visitor hash, the time, and (for searches) the search terms and the number of results. The visitor hash is an HMAC of the IP address and user agent with a secret salt that rotates every day, so it cannot be reversed or matched across days. The IP address itself is never stored or logged.
* **Feedback votes and comments** that visitors choose to leave on an article, with the same visitor hash and, for a signed-in visitor, their user ID.

Analytics can be switched off under **Knowledge Base > Settings > Privacy**, where you also set how long analytics are kept before they are deleted automatically. The plugin registers personal-data exporters and erasers with WordPress's privacy tools.

The plugin sets no cookies of its own and loads no third-party scripts, fonts or images.

== External services ==

This plugin does not connect to any external service. Search, analytics and every admin screen run entirely on your own server, and all scripts and styles ship inside the plugin.

== Source code and build tools ==

The admin screens and the editor blocks are compiled. The compiled files are in `dist/`, and their full, human-readable source is published alongside the plugin in the WordPress.org SVN repository:

* SVN trunk: https://plugins.svn.wordpress.org/licensio-knowledge-base/trunk/
* `apps/`: the Vue 3 admin applications (articles, categories, tags, settings, analytics, feedback and tools).
* `blocks/`: the editor blocks.
* `package.json`, `package-lock.json`, `vite.config.mjs` and `tools/build-apps.mjs` / `tools/build-blocks.mjs`: the build configuration.

To rebuild `dist/` from source, install Node.js 22 or later, then run `npm ci` followed by `npm run build` in the plugin folder.

The admin applications are built with Vite and the blocks with `@wordpress/scripts`. Everything in `assets/`, `includes/` and `templates/` ships uncompiled.

== Third-party libraries ==

Bundled into the compiled admin applications in `dist/`:

* Vue 3.5.44, MIT License, https://github.com/vuejs/core
* Pinia 4.1.0, MIT License, https://github.com/vuejs/pinia
* Ant Design Vue 4.2.6, MIT License, https://github.com/vueComponent/ant-design-vue
* Chart.js 4.6.1, MIT License, https://github.com/chartjs/Chart.js

These are installed together with the smaller packages they depend on, at the exact versions pinned in `package-lock.json`.

Included as PHP source in `libraries/`:

* StellarWP DB 1.3.1, GPL-2.0, https://github.com/stellarwp/db. The classes are prefixed with `Licensio_KB\` using Strauss so they cannot collide with another plugin's copy.

== Screenshots ==

1. The knowledge base home: help topics grouped by category, with the Popular Articles and Need Support sidebar.
2. A category page listing all of its articles, with the category navigation in the sidebar.
3. A single article with breadcrumbs, a search bar and an "On this page" table of contents.
4. The search block with live type-ahead suggestions.
5. All Articles: status tabs, filters, and categories, helpfulness rating, views and reading time columns.
6. The analytics dashboard: searches and article views over time, search effectiveness, and the questions with no answer.
7. The feedback inbox.
8. The settings screen.
9. Categories: the add category form beside the drag-and-drop category tree, with each category's own article order.
10. Tags: the add tag form beside the tag list, with search, the unused filter, and bulk merge, delete and convert to category.

== Changelog ==

= 1.0.0 =
* Initial release.
* Knowledge base post type with category and tag taxonomies, category icons and term meta.
* Knowledge base home with a help topics grid, category pages listing every article, and a sidebar with a table of contents, categories, tags, Popular Articles and a Need Support card.
* Dedicated search index with FULLTEXT relevance ranking, a portable fallback driver, a REST search endpoint, front-end type-ahead and a WP-CLI reindex command.
* Classic PHP templates and Site Editor block templates, both overridable, sharing one hook contract.
* Five dynamic blocks, each also registered as a shortcode from the same render callback.
* Article voting, comment feedback and an admin feedback inbox.
* All Articles, Categories and Tags admin screens with filters, quick edit, confirmed and undoable bulk actions, a drag-and-drop category tree, per-category article order, and tag merge and convert, with a setting and a filter to keep the classic WordPress screens.
* Privacy-safe search and view analytics with a reporting dashboard, retention cleanup and personal-data export and erase support.
* Import wizard for Heroic Knowledge Base and BetterDocs, with dry runs, redirects from old URLs and a migration log.
* Tools for duplicate articles and terms, search index rebuilds and data maintenance.
* REST-backed settings screen with appearance, permalink, sidebar, search, feedback and privacy options.

== Upgrade Notice ==

= 1.0.0 =
First public release.
