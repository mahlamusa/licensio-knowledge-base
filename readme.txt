=== Lisensio Knowledge Base ===
Contributors: lisensio
Tags: knowledge base, documentation, faq, support, help desk
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A fast, standalone knowledge base with block-first authoring, relevant search and privacy-safe analytics.

== Description ==

Lisensio Knowledge Base turns WordPress into a documentation and self-service support site. It is completely standalone: it needs no other plugin, no e-commerce platform and no external service.

Articles are a first-class post type with their own categories and tags, their own templates, and their own search index. Everything a visitor does — the searches that found nothing, the articles that were read, the answers that were voted unhelpful — is recorded without ever storing an IP address, and reported back to you in the admin.

= Authoring =

* A `Knowledge Base` post type with category and tag taxonomies, category icons and per-term descriptions.
* Five dynamic blocks — search, article list, category grid, breadcrumbs and FAQ — each previewed in the editor by the same code that renders it on the front end.
* Every block is also a shortcode (`[lisensio_kb_search]`, `[lisensio_kb_articles]`, `[lisensio_kb_categories]`, `[lisensio_kb_breadcrumbs]`, `[lisensio_kb_faq]`), so page builders and classic themes get identical markup.

= Search that finds things =

* A dedicated search index maintained as articles are saved, so search does not depend on `WP_Query`'s post search.
* MySQL FULLTEXT relevance ranking where the server supports it, with an automatic fallback that keeps working where it does not.
* Type-ahead suggestions on the front end from a dependency-free script with full ARIA combobox semantics and keyboard navigation.
* Every search is logged, including the ones that returned nothing — the fastest way to find the article you have not written yet.

= Feedback =

* Was this helpful? voting on every article, with an optional comment.
* A feedback inbox in the admin for reading, triaging and clearing responses.

= Analytics, without tracking people =

* Article views, searches, zero-result searches and helpfulness, reported in a dashboard with sortable tables and trend charts.
* Visitors are counted through a rotating, salted hash. No IP address is ever written to the database.
* A retention cron prunes raw events on a schedule you choose, and the privacy layer answers WordPress's personal-data export and erase requests.

= Switching from another knowledge base =

* An import wizard under **Knowledge Base > Tools** brings articles, categories, tags, votes, views and searches across from Heroic Knowledge Base and BetterDocs, even with the old plugin deactivated.
* Map each old category onto a new one or onto one you already have, verify everything with a dry run, then import. An interrupted import resumes where it stopped, and running it again never duplicates anything.
* Old article, category and tag URLs redirect (301) to their new addresses automatically, and the full old-to-new URL map downloads as a CSV.
* A migration log records every item: what it was, where it went, its categories, each meta field and whether it was carried over, and a plain-language reason for anything that failed.
* The old plugin's data is only ever read, never changed, and no IP address from its analytics tables is imported.

= Themes =

* Classic PHP templates for archives, single articles, categories and tags, all overridable from your theme.
* Block templates and a template part registered with the Site Editor, so block themes can edit the knowledge base like any other template.
* Both layers fire the same hooks, so anything you attach works under either.

== Installation ==

1. Upload the `lisensio-knowledge-base` folder to `/wp-content/plugins/`, or install the plugin through the **Plugins > Add New** screen.
2. Activate the plugin through the **Plugins** screen.
3. Visit **Knowledge Base > Settings** to choose your archive slug and analytics retention period.
4. Visit **Settings > Permalinks** once if your article URLs do not resolve immediately.

The plugin creates its own database tables on activation. On a multisite network it creates them per site, including for sites added later.

== Frequently Asked Questions ==

= Does this require any other plugin? =

No. The plugin is entirely standalone: no e-commerce plugin, no licensing plugin, no page builder and no external service. Install it on a bare WordPress site and it works.

= What happens to my articles if I deactivate the plugin? =

Nothing. Deactivating leaves all content and data in place. Data is only removed when you delete the plugin, and then only if you have opted in to data removal on the settings screen.

= Does the plugin store visitors' IP addresses? =

No. Visitors are identified by a salted hash that rotates on a schedule, which is enough to avoid double-counting a page view but not enough to re-identify anyone. No IP address is written to any table.

= Can I override the templates in my theme? =

Yes. Copy any file from the plugin's `templates/` directory into a `lisensio-kb/` directory in your theme and edit it there. If you use a block theme, the block templates are editable directly in the Site Editor.

= Does search need a third-party service? =

No. Search runs against a table in your own database, using MySQL FULLTEXT indexing where your server supports it and a portable fallback where it does not.

= Is the plugin translatable? =

Yes. Every string is translatable under the `lisensio-knowledge-base` text domain, and a `.pot` file ships in `languages/`.

== Screenshots ==

1. The knowledge base archive, rendered by the plugin's own templates.
2. A single article with breadcrumbs and the helpfulness prompt.
3. The search block with live type-ahead suggestions.
4. The analytics dashboard: views, searches and zero-result searches over time.
5. The feedback inbox.
6. The settings screen.

== Changelog ==

= 1.0.0 =
* Initial release.
* Knowledge base post type with category and tag taxonomies, category icons and term meta.
* Dedicated search index with FULLTEXT relevance ranking, a portable fallback driver, a REST search endpoint and front-end type-ahead.
* Classic PHP templates and Site Editor block templates, both overridable, sharing one hook contract.
* Five dynamic blocks, each also registered as a shortcode from the same render callback.
* Article voting, comment feedback and an admin feedback inbox.
* Privacy-safe search and view analytics with a reporting dashboard, retention cron and personal-data export and erase support.
* REST-backed settings screen.

== Upgrade Notice ==

= 1.0.0 =
First public release.
