# Licensio Knowledge Base — Technical Specification

**Repo:** `slswc/licensio-knowledge-base` (private)
**Version under development:** 1.0.0
**Status:** Specification complete, ready for decomposition
**Competitive audit:** `docs/heroic-kb-audit.md`

---

## Overview

Licensio Knowledge Base is a WordPress documentation and self-service support plugin. It is a **fully standalone knowledge base** — it must work with no other plugin installed — with an optional **pro add-on** that integrates deeply with Licensio (SLSWC) for product-scoped, licence-gated documentation and support-ticket deflection.

It replaces Heroic Knowledge Base on the Licensio site and competes with it commercially. The audit identified where Heroic is weak: no blocks, a 122 KB procedural settings file, a bundled CMB2 fork, mediocre search relevance, raw IP storage with 10-year retention, no product/version awareness, and deflection that is measured but never acted upon.

## Goals

**Primary**
1. A best-in-class standalone KB: fast, relevant search with instant type-ahead; clean content model; modern block-based authoring.
2. Measurement that drives action — article feedback and search analytics that identify documentation gaps, with privacy-safe collection by default.
3. A free wordpress.org plugin strong enough to stand on its own and act as top-of-funnel for Licensio.

**Secondary (v2, pro)**
4. Documentation scoped to a software product and version range, sourced from `WC_Product_Software`.
5. Licence- and purchase-based gating for private, beta and customer-only documentation.
6. In-ticket deflection against the existing SLSWC support desk, with real deflection measurement.
7. A Licensio-hosted AI assistant grounded in the customer's own knowledge base.

## Non-Goals

- **No importers beyond Heroic KB and BetterDocs.** The shipped import wizard (see *Import wizard*) covers those two; Echo Knowledge Base and weDocs are left to the driver filter. The disposable one-time Heroic tool in `tools/` still exists, excluded from both builds.
- **No theme work in this epic.** The plugin ships complete theme-agnostic default templates. Updating `themes/licensio` (repo `slswc/theme`, 18 `hkb-templates/` overrides + `inc/knowledge-base.php`) is tracked separately.
- No dedicated KB theme (Heroic sells one; out of scope).
- No Chrome extension.
- No third-party helpdesk integrations (HelpScout, Slack, form builders) in v1 or v2.
- No AI in v1.
- No multisite-specific features in v1 (must not break under multisite, but network-level admin is out of scope).

---

## Product decisions

| Decision | Choice | Rationale |
|---|---|---|
| Relationship to Heroic KB | Clean replacement, own CPT and taxonomies | Avoids inheriting `ht_kb` naming and Heroic's architecture permanently |
| Product scoping | `licensio_product` taxonomy auto-synced from WC software products | Free archives, fast `tax_query`, clean join for gating |
| Gating posture | Public by default, gating opt-in per article and per category | Documentation should be findable; gating is the exception |
| Gating rules (v2) | Logged-in; role/capability (free) · active licence; any purchase (pro) | Covers internal docs without WooCommerce, and customer docs with it |
| Distribution | Free on wordpress.org + paid pro add-on | Reach plus revenue |
| Codebase | Monorepo, two build targets | One PR stream; repo stays private, only the free zip is published |
| Search | Custom index table, MySQL FULLTEXT, LIKE fallback | Beats Heroic on their strongest feature; works on shared hosting |
| Admin UI | Vue 3 + TypeScript + Pinia + Ant Design Vue on Vite | Matches SLSWC conventions and reviewers |
| Authoring | Blocks first, shortcode fallback from the same render callbacks | Answers Heroic's biggest structural gap without stranding page-builder users |
| Analytics privacy | No IPs; rotating salted visitor hash; 90-day default retention | Safest for wordpress.org review and GDPR; sufficient for every report |
| AI (v2, pro) | Licensio-hosted service, authenticated by licence key | Best onboarding; ties recurring value to the licence |
| Deflection (v2, pro) | Live non-blocking suggestions while typing a ticket + deflection measurement | Recommended: intervenes where Heroic only observes, without gating a customer asking for help |

### Free / Pro boundary

**Free (wordpress.org, standalone, no dependencies):** CPT and taxonomies, search index and type-ahead, templates and theme override layer, blocks and shortcodes, voting and feedback, search/view analytics and reporting dashboard, settings, logged-in and role/capability gating, content extras.

**Pro (`pro/`, requires SLSWC + WooCommerce):** `licensio_product` taxonomy sync and version ranges, licence and purchase gating, in-ticket deflection, docs in My Account, AI assistant, and the `slswc-client` licensing/updater for the pro plugin itself.

The free build must not contain `pro/`. This is enforced by a CI check on the release artifact.

---

## Release scope

### v1.0.0 — free standalone core
CPT + taxonomies · search index, FULLTEXT query and type-ahead · classic PHP templates and block-theme templates, both overridable · blocks + shortcodes · voting, feedback and the feedback inbox · search/view analytics, privacy layer, retention cron and reporting dashboard · REST-backed settings · release pipeline.

### v2.0.0 — pro and content extras
Gating and access control (all four rules) · `licensio_product` taxonomy sync and version ranges · in-ticket deflection · docs in My Account · AI assistant · table of contents, related articles, attachments, reading time, drag-and-drop ordering, category icons, FAQ and glossary builders.

---

## User stories (v1)

**US-1 — Find an answer fast.**
A visitor types in the KB search box and sees relevant articles appear as they type, without a page load.
*Acceptance:* results appear within 300 ms of a 250 ms-debounced keystroke on a 500-article KB; title matches rank above content matches; keyboard `↑`/`↓`/`Enter`/`Esc` navigate and select; the field implements the ARIA combobox pattern; zero results shows a distinct empty state; pressing Enter without a selection goes to the full results page.

**US-2 — Browse structured documentation.**
A visitor browses categories and sub-categories to reach an article.
*Acceptance:* the KB archive lists top-level categories with article counts; a category page lists its sub-categories and articles; breadcrumbs reflect the true term hierarchy; every page works under a classic theme with no theme support declared.

**US-3 — Say whether an article helped.**
A reader votes an article helpful or not and can leave a comment.
*Acceptance:* voting works for logged-out visitors; one vote per visitor per article, enforced server-side by visitor hash; a negative vote reveals an optional comment field; the stored usefulness score updates immediately; votes are rejected without a valid nonce.

**US-4 — Find documentation gaps.**
An admin opens the analytics dashboard and sees what people searched for and what failed.
*Acceptance:* dashboard shows total searches, zero-result searches, effectiveness percentage, top searches, top zero-result searches, and most/least useful articles, each over a selectable date range; every zero-result term links to "create an article for this"; no IP address is stored anywhere.

**US-5 — Triage feedback.**
An admin works through negative feedback in an inbox.
*Acceptance:* feedback is listed with article, vote, comment and date; filterable by vote and status; each item can be marked reviewed or actioned and links to the article editor; bulk actions available.

**US-6 — Embed KB pieces anywhere.**
An editor places KB search, article lists or a category grid on any page.
*Acceptance:* each block previews live in the editor with its real render callback; every block has a shortcode equivalent producing identical markup; blocks work in the site editor and in classic page builders.

**US-7 — Restyle to match a brand.**
A site owner changes KB colours and layout without writing CSS.
*Acceptance:* brand colour, layout width and card style are settings; all default styling derives from CSS custom properties on a single root scope; a classic theme can override any template by placing it in `licensio-kb/` in the theme directory, and a block theme can override the equivalent block template from the Site Editor.

**US-8 — Trust the data collection.**
A site owner satisfies a privacy review.
*Acceptance:* no IP address is written to any table; visitor identity is a rotating daily salted hash; retention defaults to 90 days and is configurable; a daily cron purges expired rows; WordPress privacy exporter and eraser handlers are registered; analytics collection can be disabled entirely.

---

## Technical architecture

### Conventions
- No namespaces. WordPress class-file convention: `includes/class-licensio-kb-search-index.php` → `class Licensio_KB_Search_Index`.
- Prefixes: `LICENSIO_KB_` constants; `Licensio_KB_` classes in `class-licensio-kb-*.php`; **`licensio_` for all hooks, filters, options and table names**; `licensio_kb_` for PHP global functions and shortcodes.
- **Function-prefix rationale:** `themes/licensio` already owns the bare `licensio_` function namespace (`inc/knowledge-base.php:29` defines `licensio_is_knowledge_base()`, and `inc/template-tags.php` uses the same prefix). Plugin global functions therefore use `licensio_kb_` to avoid a fatal redeclare. Hooks, filters and tables are unaffected and use plain `licensio_`.
- Base branch: `main`.
- Text domain `licensio-knowledge-base`.
- PHP 7.4 minimum, WordPress 6.4 minimum, MySQL 5.6 / MariaDB 10.0 minimum.
- WordPress Core coding standards, 4 spaces, PHPCS + PHPCBF clean, PHPStan level 5.
- Full PHPDoc on every class, function and file, with accurate `@since` tags (current dev version 1.0.0).

### Content model

| Object | Name | Notes |
|---|---|---|
| CPT | `licensio_article` | `show_in_rest`, Gutenberg, revisions, author, excerpt, thumbnail. Rewrite slug configurable, default `docs` |
| Taxonomy | `licensio_category` | Hierarchical, `show_in_rest`, term meta: icon, image, order |
| Taxonomy | `licensio_tag` | Flat, `show_in_rest` |
| Taxonomy (v2, pro) | `licensio_product` | Synced from `WC_Product_Software`; product ID in term meta |

Capabilities map to a `manage_licensio_kb` custom capability granted to administrators and editors, filterable.

### Database

All tables created and versioned by `class-licensio-kb-db-installer.php`, accessed through the StellarWP DB query builder (vendored via Composer + Strauss), matching SLSWC.

**`licensio_search_index`**
`article_id` BIGINT PK · `title` TEXT · `excerpt` TEXT · `content` LONGTEXT · `terms` TEXT · `updated_at` DATETIME
`FULLTEXT KEY ft_all (title, content, terms)` — created only when FULLTEXT is supported; capability probed at install and stored in an option.

**`licensio_search_log`**
`id` · `term` VARCHAR(191) KEY · `normalized_term` VARCHAR(191) KEY · `results_count` INT · `visitor_hash` CHAR(64) · `source` ENUM('site','live','ticket') · `created_at` DATETIME KEY

**`licensio_article_views`**
`id` · `article_id` BIGINT KEY · `visitor_hash` CHAR(64) · `created_at` DATETIME KEY

**`licensio_feedback`**
`id` · `article_id` BIGINT KEY · `vote` TINYINT (−1 / 1) · `comment` TEXT · `visitor_hash` CHAR(64) · `user_id` BIGINT · `status` ENUM('new','reviewed','actioned') KEY · `created_at` DATETIME KEY

**No `user_ip` column exists in any table.**

### Privacy layer
`class-licensio-kb-visitor.php` produces `visitor_hash = hash_hmac( 'sha256', ip . '|' . user_agent, daily_salt )`. The salt is a key, not a prefix, and the `|` delimiter keeps the inputs unambiguous — without it, IP `1.2.3.4` with user agent `5Mozilla` collides with IP `1.2.3.45` and `Mozilla`, collapsing two visitors into one identity and breaking the one-vote-per-visitor and rate-limiting rules built on the hash.

The salt is stored in an autoloaded option and rotated **lazily on read as well as by cron**: `get_salts()` compares `current_created` against the rotation interval and rotates before returning. Cron is an optimisation, not the guarantee — a site with `DISABLE_WP_CRON` and a misconfigured system cron, or simply very little traffic, would otherwise hold one salt for the whole retention window, and anyone able to read the option could then brute-force the IPv4 space against a known user agent and recover every stored hash. The previous salt is retained for 24 hours to avoid double-counting across a rotation.

The raw IP is never persisted. Retention is `licensio_analytics_retention_days` (default 90); a value of `0` falls back to the default rather than being clamped to one day, since `0` is commonly meant as "keep forever" and silently applying one-day retention would destroy analytics history. Unbounded retention is deliberately not offered. `licensio_analytics_cleanup` runs daily and deletes expired rows in chunks. WordPress privacy exporter/eraser handlers are registered against `visitor_hash` and `user_id`, and are owned by the analytics issue.

### Search

`class-licensio-kb-search-index.php` — maintains `licensio_search_index` on `save_post`, `deleted_post`, `set_object_terms` and term edits; provides a chunked full rebuild driven by cron and exposed as `wp licensio-kb reindex`.

`class-licensio-kb-search-query.php` — two drivers behind one interface:
- **FULLTEXT** — `MATCH … AGAINST` in boolean mode; relevance weighted title ×3, tags ×2, content ×1; prefix-expands the final token for type-ahead.
- **LIKE fallback** — used when FULLTEXT is unavailable; same ranking applied in PHP.

Both drivers apply post-status and (from v2) gating filters before returning IDs. Results are hydrated through `WP_Query` with `post__in` to keep the rest of the stack conventional.

### REST API

Namespace `licensio-kb/v1`:

| Route | Method | Auth |
|---|---|---|
| `/search` | GET | Public, throttled per visitor hash |
| `/articles` | GET | Public |
| `/categories` | GET | Public |
| `/feedback` | POST | Public, nonce + one-per-visitor |
| `/views` | POST | Public, nonce, throttled |
| `/analytics/{overview\|searches\|articles\|feedback}` | GET | `manage_licensio_kb` |
| `/feedback/{id}` | PATCH | `manage_licensio_kb` |
| `/settings` | GET/POST | `manage_licensio_kb` |

### Frontend

`assets/js/search.js` — dependency-free type-ahead (no Vue on the frontend), debounced 250 ms, aborts in-flight requests, full ARIA combobox semantics, keyboard navigable.
`assets/js/feedback.js` — vote submission and comment reveal.
Styling uses CSS custom properties on `.licensio-kb` so a theme or the settings screen can restyle without overriding rules.

### Templates — dual system (classic + block themes)

The plugin ships a complete, overridable template set for **both** theme paradigms. `licensio_kb_get_template()` is block-theme aware: it calls `wp_is_block_theme()` and routes to the correct layer.

**Classic themes (PHP templates)** — plugin templates live in `templates/`. Resolution order: `{theme}/licensio-kb/{template}.php` → plugin `templates/{template}.php`. Templates: archive, single, category, tag, search results, breadcrumbs, article card, category card, feedback form, no-results.

**Block themes / Site Editor (HTML block templates)** — the plugin registers block templates and template parts so they appear in the Site Editor and can be overridden there like any theme template. Registered via `get_block_templates` / `wp_template` resolution against `block-templates/`: `single-licensio_article`, `archive-licensio_article`, `taxonomy-licensio_category`, `taxonomy-licensio_tag`, `search-licensio_article`, plus a `licensio-kb-header` template part. Query Loop supports the `licensio_article` post type and both taxonomies. A theme overrides by shipping its own `templates/single-licensio_article.html`.

Both layers fire the same frontend hook contract (`licensio_before_article`, `licensio_after_article`, `licensio_article_footer`), so feedback, analytics and future gating attach once and work under either paradigm.

### Blocks and shortcodes

`blocks/` built with `wp-scripts`: `licensio-kb/search`, `licensio-kb/article-list`, `licensio-kb/category-grid`, `licensio-kb/breadcrumbs`, `licensio-kb/faq`. Every block's `render_callback` is also registered as a shortcode (`[licensio_kb_search]`, `[licensio_kb_articles]`, `[licensio_kb_categories]`, `[licensio_kb_breadcrumbs]`, `[licensio_kb_faq]`) so both paths produce identical markup from one implementation.

### Admin

Article editing stays native Gutenberg with native list tables. Only the data-heavy screens are Vue apps:

- `apps/analytics/` — reporting dashboard (Ant Design tables, Chart.js)
- `apps/feedback/` — feedback inbox
- `apps/settings/` — settings

Stack matches SLSWC: Vue 3, TypeScript, Pinia, Ant Design Vue, Axios, Vite. Dev server on port **6913** (SLSWC uses 6912), gated by `HMR_DEV === 'licensio-kb'`; production reads `dist/.vite/manifest.json`.

Menu: top-level **Knowledge Base** → Articles · Categories · Tags · Feedback · Analytics · Settings.

### Build and release

- Composer with Strauss for vendor prefixing; PHPCS, PHPStan, PHPUnit.
- npm: Vite for admin apps, `wp-scripts` for blocks, both emitting to `dist/`.
- `bin/build-release.sh` produces two artifacts: `licensio-knowledge-base.zip` (free, `pro/` excluded) and `licensio-knowledge-base-pro.zip`.
- CI asserts the free artifact contains no path under `pro/` and no reference to SLSWC or WooCommerce symbols outside `pro/`.
- `readme.txt` maintained to wordpress.org standards; all strings translatable; `.pot` generated on release.

### The disposable migrator

`tools/migrate-heroic/` — a WP-CLI command run once against the Licensio site, excluded from both build artifacts and from the wordpress.org submission. Migrates `ht_kb` posts, `ht_kb_category`/`ht_kb_tag` terms and term meta, article and category ordering, historical votes from `hkb_voting`, and search/visit/exit analytics from Heroic's three tables into the new schema (dropping IPs during the import). Generates 301 redirects from every old Heroic URL to the new permalink and writes a mapping file. Idempotent, resumable, with a `--dry-run` mode.

### Import wizard

Decided 2026-10-04 (issue #13), shipped in the free build in 1.0.0. **Knowledge Base > Tools > Migration** imports from Heroic Knowledge Base and BetterDocs through one framework:

- `Licensio_KB_Import_Driver` runs every import (phases `terms`, `articles`, then the source's analytics phases, one AJAX batch at a time); `Licensio_KB_Import_Source` reads a source's posts and terms straight from the tables, so the source plugin may be deactivated. A third source is a pair of subclasses registered through the `licensio_import_drivers` filter.
- Flow: pick a detected source → map each source category to *create new* or *merge into an existing category* → dry run → import → summary and log.
- Dry runs write nothing but the log; their scratch state is deleted when they finish, and their analytics phases start from the real import's cursors so they preview only what is new.
- Content is idempotent through provenance meta (`_licensio_{source}_post_id` / `_term_id`); a re-run skips what was imported and leaves it unchanged. Analytics are idempotent through per-phase cursors, which a new run keeps, so a re-run imports only rows recorded since. An interrupted run resumes; the wizard never resets a real run.
- The source is read-only: attachments are not re-parented, the imported article points at the same attachment.
- Redirects: old paths → new URLs are stored in the `licensio_import_redirects` option and served with a 301 on requests that would otherwise 404. A per-source CSV URL map is downloadable.
- BetterDocs specifics: each `knowledge_base` term becomes a top-level category over its categories; daily analytics totals are expanded into per-event rows (happy/normal → helpful, sad → not helpful; found and not-found searches separately); today's still-changing totals wait for the next run.
- Migration log: table `licensio_import_log` (DB schema 1.0.1) with one row per category, tag and article — title, ids, categories, each source meta key with its truncated value and outcome, status and a plain-language error — plus one row per analytics batch and per failed or orphaned record. Dry runs are logged and flagged. Kept until cleared; CSV export; dropped on uninstall.

---

## Admin design system

The three Vue admin screens — Analytics, Feedback, Settings — present as one product with License Server. The binding design context lives in `.impeccable.md` (users, brand personality, colour rules, naming); this section records only what the code must hold to.

### Identity

- **Menu.** The knowledge base is a top-level menu at position 51, directly below License Server at 50. Adjacency is the point: one product, found together in the rail.
- **Icon.** `assets/images/logo/knowledge_base_icon.svg` — an open book in line art, same stroke weight and brand green as License Server's mark, deliberately a *different silhouette*. Two adjacent entries sharing a shape are unnavigable once the rail collapses and only the icon is left to read.
- **No app header.** WordPress already prints the menu, the screen name and the screen context. A sticky bar repeating the logo and wordmark on every screen adds a row of chrome that says nothing new, and contradicts "light and data-dense, not sparse". The logo belongs in the menu and in empty states.

### Colour

`#40b58a` is the brand green and is **decorative only** — 2.56:1 on white, under the 3:1 WCAG 2.1 requires of a non-text UI component, and white on it is 2.56:1 against a 4.5:1 requirement. Every interactive or meaning-bearing surface uses `--lkb-accent` `#247a5c` (5.23:1 on the page ground; 5.23:1 for white on the button).

This is also the Ant Design primary. Ant components take colour from a JS `ThemeConfig`, not from CSS custom properties, so `apps/styles/theme.ts` carries `colorPrimary: '#247a5c'` and every app wraps its root in `<a-config-provider :theme="lkbTheme">`. A token redefined in CSS alone leaves Ant on its default blue.

The front end keeps `#2ba377`, the theme's `--primary`. Admin chrome and published pages are different surfaces, and the settings screen renders the front-end colour as a swatch — a swatch is content, not chrome.

### The shared shell

`Licensio_KB_Admin_Page::shell()` prints the frame for all three screens, in PHP rather than in each app:

```
.wrap.lkb-wrap > .lkb-page > .lkb-page__inner > h1.lkb-page__title + #mount
```

- **One heading per screen, server-rendered.** The screen is named before its bundle parses. Previously Feedback printed a heading and the other two printed none.
- **The heading sits inside the measured column**, not in `.wrap`, so the screen name and the screen share a left edge.
- **A capped, centred column.** `.lkb-page__inner` is `max-width: 1240px; margin: 0 auto`. WordPress hands a screen the full width between rail and window edge — fine for a list table, wrong for form rows, where a label on the far left and its control on the far right are related only by empty space. `.lkb-wrap` zeroes `.wrap`'s asymmetric margins so the ground is full-bleed and the column centres truly.
- Section headings within a screen are `h2`, so each screen has exactly one `h1`.

### Tokens

Colour, spacing and radius come from `apps/styles/tokens.css`; the shell from `apps/styles/admin.css`. A hex literal in a component is technical debt. Local custom properties must never shadow a shared token — the settings screen redefined `--lkb-accent` as WordPress blue and silently ignored the plugin's own palette.

---

## Security considerations

- All REST writes require a nonce; all reads that expose non-public data require `manage_licensio_kb`.
- Public endpoints are rate-limited per visitor hash to prevent search-log flooding and vote stuffing.
- Search input is bound as parameters; FULLTEXT boolean operators are stripped from user input before use.
- Feedback comments are sanitised on input and escaped on output; they are never rendered as HTML.
- Settings writes are capability-checked and validated field-by-field.
- No user IP is stored; the visitor salt is not exposed to the frontend.

## Performance requirements

- Type-ahead responds in under 300 ms server-side on a 5,000-article KB on shared hosting.
- The search index adds no more than one row write per article save.
- Analytics writes are single-row inserts with no joins; dashboards query pre-filtered date ranges with covering indexes.
- Retention cleanup runs in chunks and never exceeds its time budget in a single request.
- No unbounded `meta_query` in any frontend path.

## Testing requirements

- **PHPUnit** — search ranking (both drivers), index maintenance on every content mutation, visitor hashing and salt rotation, retention cleanup, one-vote-per-visitor enforcement, REST permission callbacks, template override resolution.
- **Playwright** — type-ahead including keyboard navigation and empty state, voting as a logged-out visitor, feedback inbox triage, analytics dashboard rendering, each block in the editor and on the frontend.
- **Compatibility** — verified with FULLTEXT available and forced-unavailable; classic theme and block theme; PHP 7.4 and 8.3.
- Every PR must be PHPCS-clean and PHPStan level 5 clean.

## Implementation phases (v1)

1. **Scaffold** — repo layout, Composer + Strauss, npm/Vite/wp-scripts, PHPCS, PHPStan, PHPUnit, Playwright, CI, plugin bootstrap and activation.
2. **Content model** — CPT, taxonomies, term and post meta, capabilities, admin columns, rewrite rules.
3. **Database and privacy** — installer, all four tables, visitor hashing, retention cron, privacy handlers.
4. **Search** — indexer, both query drivers, REST search endpoint, type-ahead frontend.
5. **Classic template system** — PHP template set, theme override layer, default stylesheet with custom properties.
6. **Block theme templates** — registered block templates and template parts, Site Editor override, Query Loop support.
7. **Blocks and shortcodes** — blocks with shared render callbacks and shortcode registration.
8. **Feedback and voting** — table writes, REST, frontend UI, usefulness scoring, feedback inbox Vue app.
9. **Analytics** — search and view capture, aggregation queries, reporting dashboard Vue app.
10. **Settings** — REST-backed settings, Vue settings app, brand and layout controls.
11. **Release** — two build targets, free/pro CI boundary check, `readme.txt`, i18n, `.pot`.

The disposable migrator is a separate deliverable, built once the content model (phase 2) and database (phase 3) are stable.

Phase 1 is the scaffold PR; phases 2–11 are independent sub-issues that each branch from `main` after the scaffold merges.

## Open questions and risks

1. ~~**No in-plugin migrator is an adoption cost.**~~ Resolved 2026-10-04: the free build ships the Heroic and BetterDocs import wizard (see *Import wizard*).
2. **Theme cutover risk.** `themes/licensio` breaks when Heroic KB is deactivated. Mitigated by theme-agnostic defaults, but the Licensio site temporarily loses its bespoke KB design until `slswc/theme` is updated. Must be sequenced with whoever owns that repo.
3. **Ant Design in a wordpress.org plugin.** Bundle size and committed build artifacts will draw reviewer attention, and the admin will not look native. Accepted deliberately for consistency with SLSWC.
4. **Licensio-hosted AI is a service commitment**, not a plugin feature — token cost, uptime, abuse protection. Scoped to v2; needs its own infrastructure spec before that epic.
5. **Default branch is `main`** by decision, which differs from the rest of the `slswc` org (`slswc/slswc` and `slswc/client` both use `master`). The scaffold PR sets it.
