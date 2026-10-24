# Heroic Knowledge Base migrator

A **disposable, one-time** WP-CLI tool that moves the Licensio site off Heroic
Knowledge Base 3.10.4.

This is not a plugin feature. It is not autoloaded, it is not wired into
`Licensio_KB::boot()`, it ships in neither build artifact and it is not part of
the wordpress.org submission. **Delete this directory once the migration is
signed off.**

## Running it

The plugin must be active. Heroic does not need to be, and normally is not.

```bash
cd /path/to/wordpress

# See exactly what would happen. Writes nothing at all.
wp --require=wp-content/plugins/licensio-knowledge-base/tools/migrate-heroic/migrate-heroic.php \
   licensio-kb migrate-heroic --dry-run

# Do it.
wp --require=wp-content/plugins/licensio-knowledge-base/tools/migrate-heroic/migrate-heroic.php \
   licensio-kb migrate-heroic

# Afterwards, rebuild the search index over the imported articles.
wp licensio-kb reindex --flush
```

### Options

| Flag | Effect |
| --- | --- |
| `--dry-run` | Report the counts a real run would produce and write nothing — no posts, no terms, no rows, no files, no resume state. |
| `--batch-size=<n>` | Source rows per batch. Default 200. |
| `--phase=<phase>` | Run one phase only: `terms`, `articles`, `feedback`, `views`, `searches`. |
| `--output-dir=<path>` | Where the URL map and redirect drop-in are written. Default `wp-content/licensio-heroic-migration`. |
| `--source-timezone-offset=<seconds>` | Seconds to subtract from Heroic datetimes to reach UTC. Default 0. See "Timezones" below — the default is right for votes and may be wrong for analytics. |
| `--reset` | Forget the resume point. **Read the idempotency section before using this.** |

## The one thing this tool exists to get right

Every one of Heroic's four tables has a `user_ip` column. Not one address may
reach any `licensio_*` table — that is the plugin's headline privacy promise.

The boundary is `Licensio_KB_Heroic_Map`. It is the only class that reads
`user_ip`, and it reads it exactly once per row: to build an HMAC through
`Licensio_KB_Visitor::hash()`, the plugin's own hashing function. Every target
row is assembled there by naming each target column, never by copying, filtering
or spreading a source row, so there is no code path along which an unrecognised
source column can survive into the output.

The salt is generated at the start of the migration, kept in the resume state so
a resumed run gives one visitor one identity, and **destroyed when the migration
finishes**. A stored salt beside the hashes it produced would make every
migrated identity reversible by brute force over the address space.

`Licensio_KB_Heroic_Migration_Integration_Test` proves this against a real MySQL
server: it creates Heroic's tables with Heroic's own DDL, seeds them with real
addresses, migrates for real, and then reads every column of every row of every
table the plugin owns looking for those addresses. Checking that no column is
called `user_ip` would not have been enough — it would miss an address written
into a column named something else.

## Idempotency and resuming

Two different mechanisms, deliberately:

- **Content** carries its provenance. Every migrated article gets
  `_licensio_heroic_post_id` post meta and every migrated term gets
  `_licensio_heroic_term_id` term meta, and both phases look that up before
  creating anything. A re-run finds the existing object and skips it — even if
  the resume state has been wiped, the batch size changed, or the previous run
  died between the insert and the state write.
- **Analytics** has nothing to carry provenance in. `licensio_feedback`,
  `licensio_article_views` and `licensio_search_log` have no column naming the
  Heroic row they came from, and adding one would mean altering a schema owned
  by another component to serve a throwaway script. So the resume cursor — the
  primary key of the last source row written, per phase — is the only record.

**Consequence:** `--reset` is safe for content and destructive for analytics. It
will re-import every vote, view and search. Empty those three tables first, or
don't use it.

## What crosses, and what does not

### Crosses cleanly

| Heroic | Target |
| --- | --- |
| `ht_kb` posts | `licensio_article`, with author, dates, status, excerpt, comment status |
| `ht_kb_category` / `ht_kb_tag` terms | `licensio_category` / `licensio_tag`, hierarchy preserved |
| term meta `term_order` | `licensio_order` |
| post meta `_ht_article_order_{term_id}` | `licensio_order_{term_id}` on the migrated category |
| term meta `meta_image` (an attachment ID) | `licensio_image_id` |
| featured image, uploaded attachments | copied and re-parented onto the new article |
| `hkb_voting` | `licensio_feedback`, direction, comment, author and date preserved |
| `hkb_analytics_search_atomic` | `licensio_search_log` |
| `hkb_visits` where `object_type = ht_kb_article` | `licensio_article_views` |

Heroic orders an article once per category it belongs to, in a separate
`_ht_article_order_{term_id}` post meta key each time
(`ht-knowledge-base.php:2722`). Each position is written to the migrated
category as `licensio_order_{term_id}`, the per-category order the plugin's
order screen and its built-in importers use. The positions follow any order the
category already held before the run, so migrating into a category ordered by
hand never interleaves with it. That existing order is read once per run; a run
resumed in a new process reads it again, so its articles still follow the
existing order, only further down. `menu_order` is still set, as the fallback
for listings not limited to one category: the position recorded against the
lowest Heroic category term ID.

### Does not cross — a product gap, not a migration defect

**Category icons.** Heroic stores them as raw inline SVG markup in the
`meta_svg` term meta (`php/ht-knowledge-base-category-meta.php:147` echoes it
through `stripslashes()` with no escaping — a stored XSS in their plugin, and
a good reason not to carry the pattern across). Our `licensio_icon` holds a
dashicon *name* and is printed into a `class` attribute;
`licensio_icon_id` holds an attachment ID. Neither can hold markup, and
writing SVG into either would be an injection rather than a migration. Both
`meta_svg` and `meta_svg_color` are therefore counted and reported as
unmapped. Carrying icons over needs an SVG-icon field in the content model.

### Not migrated at all

`hkb_exits` — Heroic's outbound-click tracking. There is no target table; the
deflection feature it belongs to is a later phase of the product. The rows are
counted and reported so the operator can see what is being left behind, and the
source table is untouched.

Heroic's `ht_kb_archive`, `ht_kb_category` and `ht_kb_tag` visit rows are counted
and skipped: `licensio_article_views` holds article views, and filing a term ID
into `article_id` would inflate every article's view count.

## URLs and redirects

Two artefacts land in the output directory:

- `heroic-url-map.csv` — the audit record, one row per moved URL with the IDs on
  both sides and a note when the slug changed. Written as the migration goes, so
  an interrupted run still leaves a usable map.
- `licensio-heroic-redirects.php` — a self-contained mu-plugin serving the 301s.
  **Copy it into `wp-content/mu-plugins/`.** It is generated rather than
  registered by this tool because this tool gets deleted and the redirects have
  to outlive it by years.

Old URLs are built from Heroic's stored settings (`kb-article-slug`,
`kb-category-slug`, `kb-tag-slug` in the `ht_knowledge_base_settings` option),
not from Heroic's functions, which are gone once it is deactivated. New URLs come
from `get_permalink()` and `get_term_link()`, so they follow the permalink
settings as they actually are — notably `hierarchical_category_urls`, which
turns Heroic's flat category URL into a nested one and therefore changes every
child category's address.

**The knowledge base home is not in the map, and that is correct.** Heroic
registers `ht_kb` with `has_archive` false and keeps the KB home page's ID in the
`kb-archive-page-id` setting — it is an ordinary WordPress page whose permalink
the migration does not touch. Our post type does register an archive at
`/docs/`; that is a new URL, not a moved one.

Slug collisions are reported, not assumed away. The migrator compares the slug it
asked for against the slug that was stored, so it catches every case core
silently uniquified — including ones it did not predict.

## Timezones

Heroic is not internally consistent, so check before trusting the default.

- Votes are written with PHP's `date()`
  (`voting/php/ht-voting-database.php:488`), which is UTC under WordPress.
- Visits and searches are written with MySQL's `NOW()`
  (`php/ht-knowledge-base-analytics-core.php:144`), which is the database
  server's timezone.

`--source-timezone-offset` applies uniformly. The default of 0 is right for votes
and right for a UTC database server. If your server is not on UTC, run the
analytics phases separately with the offset:

```bash
wp ... licensio-kb migrate-heroic --phase=views    --source-timezone-offset=3600
wp ... licensio-kb migrate-heroic --phase=searches --source-timezone-offset=3600
```

## A note on `magnitude`

Heroic does **not** store votes as 1 and -1. An up vote is `10`, a down vote is
`0`, and a neutral vote is `5` (`voting/php/ht-vote-class.php:55, 62, 69`). A
migrator written against the obvious assumption would test `magnitude < 0`, find
nothing, and turn every down vote into an up vote.

The mapping reproduces Heroic's own reporting split — `magnitude > 0` is
helpful, everything else is not
(`voting/php/ht-voting-database.php:369-370`) — so migrated usefulness scores
match what Heroic was displaying, including its quirk of counting a neutral vote
as helpful. A migration should reproduce what the customer saw, not what we
would have designed.

## Standards

`phpcs.xml` at the repository root excludes `tools/`, because nothing under
`tools/` ships. That is about scope, not standards. This directory carries its
own ruleset applying the same WordPress standards:

```bash
vendor/bin/phpcs --standard=tools/migrate-heroic/phpcs.xml
vendor/bin/phpstan analyse -c phpstan.neon --level=5 tools/migrate-heroic
```

## Tests

```bash
vendor/bin/phpunit --filter Heroic

LICENSIO_KB_TEST_DB_HOST=localhost LICENSIO_KB_TEST_DB_USER=root LICENSIO_KB_TEST_DB_PASS=root \
  vendor/bin/phpunit --testsuite Integration --filter Heroic
```
