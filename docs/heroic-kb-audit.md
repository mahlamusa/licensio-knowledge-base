# Heroic Knowledge Base — Competitive Audit

Sources:
- Landing page: https://herothemes.com/plugins/heroic-wordpress-knowledge-base/ (fetched 2026-08-30)
- Installed code: `wp-content/plugins/ht-knowledge-base` v3.10.4 (build 3933), 172 PHP files, 18 MB
- Companion plugins installed: `heroic-blocks`, `heroic-glossary`, `heroic-table-of-contents`

## 1. Data model

| Object | Name | Notes |
|---|---|---|
| CPT | `ht_kb` | Articles. `show_in_rest` true, filterable capabilities |
| Taxonomy | `ht_kb_category` | Hierarchical, REST base `ht-kb-category`, custom term meta (icon, image, description, order) |
| Taxonomy | `ht_kb_tag` | Flat, REST base `ht-kb-tag` |

Custom DB tables (`{$wpdb->prefix}` + name):
- `hkb_analytics_search_atomic` — every search: terms, datetime, hits, user_id, user_ip, source
- `hkb_visits` — per-article view events
- `hkb_voting` — up/down votes + feedback comments
- `hkb_exits` — outbound "exit" clicks (ticket-deflection tracking)

Legacy/aggregate tables referenced: `hkb_analytics_search`, `hkb_analytics_search_recent`.

## 2. Module map (`ht-knowledge-base/`)

| Dir | Responsibility |
|---|---|
| `php/` (33 files) | Core: CPT/tax, settings (122 KB single file), live search, restrict access, article + category ordering, category meta, reading time, view count, edit columns, data tools, reports, template helpers, WPML compat, site health, updater, sample importer |
| `hkb-templates/` (27) | Overridable frontend templates — theme can override by shipping its own `hkb-templates/` dir |
| `widgets/` (5) | KB Articles, Authors, Categories, Search, Table of Contents |
| `voting/` | Helpful/not-helpful voting + free-text feedback, AJAX (`wp_ajax_ht_voting`) |
| `analytics/` + `analytics-reporting/` | Search capture, dashboards, `stats-server.php` (40 KB) reporting engine, scheduled email reports |
| `exits/` | Tracked "exit" links — measures which articles fail and push users to support |
| `embed/` | Frontend embeddable KB / help assistant widget |
| `setup/` | Setup assistant + demo/sample content import |
| `sl-updater/` | EDD Software Licensing updater (their own licensing client) |
| `cmb290/` | Bundled CMB2 fork for meta boxes |

## 3. Feature inventory

**Search**
- Instant type-ahead / live AJAX search, debounced, own results endpoint
- Filterable search post types (`hkb_search_post_types`), search URL/base/affix filters
- Search analytics: popular searches, zero-result ("empty") searches, effectiveness %
- Search widget + searchbox template

**Content**
- Unlimited nested categories, drag-and-drop article and category ordering
- Category icons and images (term meta)
- Tags, attachments, revision history, video embeds
- Auto table of contents (widget + separate block plugin)
- Reading time calculation, view counts
- Related articles, breadcrumbs, subcategories listing, author box

**Feedback / analytics**
- Helpful / not-helpful voting with optional comment
- Article "usefulness" score recalculated and stored as post meta
- Exit tracking (which articles send people to support)
- Reporting dashboard + scheduled email reports
- Data tools: clear/recalc search, voting, exits, visits data; retention-based cleanup cron

**Access control**
- `hkb_restrict_access_levels` — restrict whole KB, a category, or an article
- Enforced in `posts_where`, `pre_get_posts`, `the_content`, `the_excerpt`, `the_title`, and term listings
- Public / password-protected / private-intranet modes

**Presentation**
- Template hierarchy with theme override via `hkb-templates/`
- Settings sections: General, Slugs, Archive, Article, Article Feedback, Search, Sidebars, Custom Styles, Transfers, Embed, Reports, License
- Custom styles / brand colour controls; works with any theme; dedicated KB theme sold separately

**Ops**
- CSV article import, migration wizard (BetterDocs, Echo KB), sample content importer
- WPML support (`wpml-config.xml`), site health checks, debug info panel

**Pro-tier / paid**
- Slack, Heroic Inbox, HelpScout, WPForms, Formidable, Gravity Forms integrations
- Heroic AI Assistant (HelpJet-powered, content-grounded chatbot)
- Chrome extension

**Pricing** — Essential $169/yr (1 site), Plus $349/yr (3 sites, analytics + access control), Pro+AI $599/yr (5 sites, AI + integrations). Claims: 60% ticket deflection, 32,120 users, 4.8/5 from 300+ reviews.

## 4. Weaknesses / opportunities

1. **No Gutenberg blocks in core** — zero `register_block_type` calls; blocks are a separate bundled plugin. Shortcodes are near-absent (only the exits shortcode).
2. **Monolithic settings** — a single 122 KB procedural settings file, hand-rolled options array in one `ht_knowledge_base_settings` option. No REST-driven settings UI.
3. **Bundled CMB2 fork (`cmb290`)** — conflict risk, dead weight.
4. **No product/version awareness** — articles cannot be scoped to a software product, release, or licence. Critical gap for a licence-server vendor.
5. **Analytics stored raw with user IP** — no anonymisation or retention default; GDPR exposure.
6. **jQuery-era frontend**, single 6.6 KB minified bundle; no modern build.
7. **Deflection is measured but not acted on** — exits are only tracked; nothing suggests articles inside a support form.
8. **AI is an upsell to a third-party SaaS** (HelpJet), not self-hosted or bring-your-own-key.

## 5. Licensio (SLSWC) integration surface

`slswc` v1.0.1 already provides what a KB companion should plug into:

- **Support desk**: tables `slswc_tickets`, `slswc_messages`, `slswc_categories`, `slswc_agents`, `slswc_attachments`; models in `includes/models/`; `class-slswc-support-api.php` (28 KB)
- **Licensing**: `slswc_licenses`, `slswc_subscriptions`; REST at `/wp-json/slswc/v1/`
- **My Account apps**: `apps/myaccount-licenses`, `-subscriptions`, `-api-keys`, `-support`
- **Admin apps**: Vue 3 + TypeScript + Pinia + Ant Design Vue + Chart.js, built with Vite (port 6912, `HMR_DEV`)
- **DB layer**: StellarWP DB query builder (`DB::table()`), not raw `$wpdb`
- **Conventions**: filterable prefix `apply_filters('slswc_slug', 'slswc_')`, Strauss vendor prefixing, PHPCS (WPCS), PHPStan level 5, PHPUnit + Playwright
- **Product types**: `WC_Product_Software`, variable + variation — the natural anchor for "docs for this product/version"

**Theme**: `themes/licensio` already ships an `hkb-templates/` override dir (18 templates) and `inc/knowledge-base.php`, which hard-codes `ht_kb`, `ht_kb_category`, `ht_kb_tag`, `ht_kb_is_ht_kb_search()`, `ht_kb_get_kb_archive_page_id()`. Any replacement plugin must either reuse those names/functions or ship a theme update in the same epic.

## 6. Differentiators available to Licensio

- Articles scoped to a **software product + version range** (from `WC_Product_Software`)
- **Licence-gated docs** — private/beta documentation visible only to holders of an active licence for that product
- **In-ticket deflection** — suggest articles inside the SLSWC support ticket form before submission, then measure actual deflection against `slswc_tickets`
- **Docs in My Account** — a Vue app alongside the existing licences/support apps
- **Changelog-linked articles** — tie docs to releases already tracked by the update server
- Modern stack from day one: blocks, REST-driven settings, StellarWP DB, Vue admin — reusing SLSWC's existing toolchain rather than a second one
