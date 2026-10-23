# Licensio theme ↔ Licensio KB: what the migration breaks

Scope: `wp-content/themes/licensio` against `licensio-knowledge-base` at `main`.
Every symbol below was read out of the installed source, not inferred.

## The headline

The theme calls **34 distinct Heroic functions across 19 files**. This plugin
provides **none of them**, and the post type and both taxonomies are renamed.

Migrating content with `wp licensio-kb migrate-heroic` moves the articles but
leaves the theme calling functions that no longer exist. **Deactivating Heroic KB
after the migration takes the knowledge base section of the site down** — fatal
errors on every KB template, not degraded output.

This is a theme problem, not a migrator defect. The migrator was scoped to move
data; the theme was always going to need its own change. It is recorded here so
the sequencing decision is made deliberately.

## Blast radius

| | |
|---|---|
| Theme files referencing Heroic | 19 |
| `hkb-templates/` overrides | 18 |
| Distinct Heroic functions called | 34 |
| Of those, provided by this plugin | **0** |
| CPT / taxonomy references to rename | 6 |

## Function map

### Has a direct equivalent — mechanical rename, different signature

| Theme calls | This plugin |
|---|---|
| `hkb_get_template_part()` | `licensio_kb_get_template()` |
| `hkb_locate_template()` | `licensio_kb_locate_template()` |
| `hkb_get_archive_tax_terms()` | `Licensio_KB_Templates::top_level_categories()` |
| `hkb_get_subcategories()` | `Licensio_KB_Templates::child_categories()` |
| `ht_kb_get_ancestors()` | `Licensio_KB_Templates::category_trail()` |
| `hkb_show_knowledgebase_breadcrumbs()` | `Licensio_KB_Templates::breadcrumb_items()` |
| `ht_kb_is_ht_kb_search()` | `Licensio_KB_Templates::is_article_search()` |
| `hkb_category_thumb_img()` | `Licensio_KB_Term_Meta` → `licensio_image_id` |
| `hkb_get_term_id()` | core `get_queried_object_id()` |
| `hkb_term_name()` | core `single_term_title()` |
| `hkb_posts_nav_link()` | core pagination |

### No equivalent — the theme needs rewriting, not renaming

| Theme calls | Why |
|---|---|
| `hkb_get_related_articles()` | Related articles are a v2 content extra. Not built. |
| `hkb_get_uncategorized_articles()` | No helper; a `WP_Query` would replace it. |
| `hkb_get_archive_articles()` | Same — the archive template runs its own loop. |
| `hkb_has_category_custom_icon()` | See the SVG gap below. |
| `ht_kb_voting_get_new_vote()` | Our feedback widget is self-contained and renders itself on `licensio_article_footer`. It exposes no per-vote read API. |
| `ht_kb_voting_get_users_post_vote_direction()` | Same. |
| `ht_kb_voting_show_feedback_form()` | Same. |
| `ht_kb_voting_enable_anonymous()` | Closest is the `enable_feedback` setting, which is not the same question. |
| `ht_kb_get_kb_archive_page_id()` | **Structural difference.** Heroic's KB home is a normal WP page (`has_archive => false`); ours is a real post type archive at `/docs/`. There is no page id to return. |
| `hkb_get_knowledgebase_header_style()` | |
| `hkb_get_knowledgebase_archive_header_text()` | |
| `hkb_get_knowledgebase_header_background_image_attachment_src_url()` | |
| `hkb_get_knowledgebase_searchbox_placeholder_text()` | |
| `hkb_archive_columns_string()` | |
| `hkb_archive_display_subcategories()` | |
| `hkb_archive_display_subcategory_count()` | |
| `hkb_sidebar_sticky()` / `_archive_position()` / `_article_position()` / `_category_position()` | |
| `hkb_no_public_posts()`, `hkb_post_format_class()`, `hkb_after_releated_post_reset()`, `ht_kb_get_template_part_name()`, `ht_kb_article_meta_display()`, `ht_kb_end_article()`, `hkb_current_term_id()`, `hkb_current_term_class()`, `hkb_current_term_class_prefix()`, `hkb_get_knowledgebase_header_background_image()` | Presentation settings and helpers with no counterpart in our settings registry. |

The eleven presentation settings are the substantive gap: Heroic exposed header
style, archive columns, sidebar placement and searchbox text as settings, and
the theme reads all of them. Our registry has twelve keys and none of these.

## Renames

| Heroic | This plugin |
|---|---|
| `ht_kb` | `licensio_article` |
| `ht_kb_category` | `licensio_category` |
| `ht_kb_tag` | `licensio_tag` |
| `hkb-templates/` | `licensio-kb/` |

## Two gaps the migrator already reports

1. **Category icons have somewhere to go, but nothing carries them yet.**
   Heroic's `meta_svg` is raw inline SVG; `licensio_icon` is a dashicon name
   printed into a `class` attribute and `licensio_icon_id` is an attachment ID,
   so neither can hold markup. `licensio_icon_svg` now can: it stores inline
   SVG reduced to an allowlist by `Licensio_KB_SVG_Sanitizer`, which is the
   field the theme's `hkb_has_category_custom_icon()` equivalent reads. The
   migrator still reports `meta_svg` as unmapped — wiring it across is the
   remaining step. `meta_svg_color` has no destination and is not getting one:
   Heroic's importer bakes the colour into the markup and keeps that key as a
   record of the choice, and an icon drawn with `currentColor` takes the
   knowledge base colour from the card with nothing stored per category.
2. **Per-category article ordering cannot cross this migrator.** Heroic stores
   `_ht_article_order_{term_id}`, one position per category; this migrator
   collapses them into one `menu_order`. The plugin's built-in Heroic import
   keeps them, as `licensio_order_{term_id}`.

## Recommended sequencing

The theme must be updated **before** Heroic KB is deactivated, not after. Three
options, in increasing order of work:

1. **Ship the plugin's own templates and drop the theme overrides.** Delete
   `hkb-templates/` and `inc/knowledge-base.php`. The KB gets the plugin's
   default look, which is theme-agnostic and already renders correctly under a
   stock theme. Fastest, and loses the bespoke Licensio design.
2. **Port the 18 overrides to `licensio-kb/`,** rewriting the 11 direct
   equivalents and replacing the rest with core calls or plugin methods. Keeps
   the design. This is the real piece of work.
3. **Add the missing presentation settings to the plugin first,** then port. Only
   worth it if those settings are wanted as product features rather than as
   theme scaffolding.

Whichever is chosen, it belongs in `slswc/theme`, not here.
