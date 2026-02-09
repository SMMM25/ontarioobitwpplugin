# DEVELOPER LOG — Ontario Obituaries WordPress Plugin

> **Last updated:** 2026-02-09 (after PR #25 merge)
> **Plugin header/constant still reads `3.10.1`; functional state includes PR #24 and PR #25; next planned bump: `3.10.2`.**
> **Main branch HEAD (at time of writing):** `1a55154` (GitHub merge commit for PR #25)
> **PR #25 squashed code commit:** `699c718`
> **PR #24 squashed code commit:** `3590e1f`

---

## READ FIRST

1. `PLATFORM_OVERSIGHT_HUB.md` — Mandatory rules for all developers.
2. This file — Project history and current state.
3. `README.md` — Repo setup (Lovable/Vite scaffolding, not plugin-specific).

---

## WHAT THIS PROJECT IS

A WordPress plugin for **Monaco Monuments** (`monacomonuments.ca`) that:

- **Scrapes** obituary notices from 6+ Ontario funeral home / newspaper sources
- **Stores** them in a custom DB table (`wp_ontario_obituaries`)
- **Displays** them on a shortcode page (`/ontario-obituaries/`) and SEO hub pages (`/obituaries/ontario/...`)
- **Generates** Schema.org structured data, sitemaps, OpenGraph tags
- **Provides** a suppression/removal system for privacy requests

The plugin runs on WordPress with the **Litho theme** and **Elementor** page builder. Caching is via **LiteSpeed Cache**. Deployment is via **WP Pusher** (auto-pulls from GitHub `main` branch on merge).

---

## ARCHITECTURE

```
wp-plugin/
  ontario-obituaries.php              — Main plugin file, activation, cron, version
  includes/
    class-ontario-obituaries.php      — Core WP integration (shortcode, assets)
    class-ontario-obituaries-display.php — Shortcode rendering + data queries
    class-ontario-obituaries-seo.php  — SEO pages, sitemap, schema, template wrapper
    class-ontario-obituaries-admin.php — Admin settings
    class-ontario-obituaries-debug.php — Debug/diagnostics page
    class-ontario-obituaries-ajax.php  — AJAX handlers
    class-ontario-obituaries-scraper.php — Legacy scraper (v2 fallback)
    sources/
      class-source-collector.php       — Scrape pipeline orchestrator
      class-source-registry.php        — Source database + adapter mapping
      class-source-adapter-base.php    — Shared HTTP, date parsing, city normalization
      class-adapter-remembering-ca.php — Remembering.ca / yorkregion.com adapter
      class-adapter-frontrunner.php    — FrontRunner funeral homes
      class-adapter-dignity-memorial.php
      class-adapter-legacy-com.php
      class-adapter-tribute-archive.php
      class-adapter-generic-html.php
    pipelines/
      class-image-pipeline.php         — Image download + thumbnail
      class-suppression-manager.php    — Do-not-republish blocklist
  templates/
    obituaries.php                     — Shortcode template (main listing)
    obituary-detail.php                — Detail view
    seo/
      wrapper.php                      — Full HTML shell (PR #25 — Pattern 2)
      hub-ontario.php                  — /obituaries/ontario/ content partial
      hub-city.php                     — /obituaries/ontario/{city}/ content partial
      individual.php                   — /obituaries/ontario/{city}/{slug}/ content partial
  assets/
    css/ontario-obituaries.css
    js/ontario-obituaries.js
    js/ontario-obituaries-admin.js
```

### Data Flow

```
Cron or Manual Trigger
  -> ontario_obituaries_scheduled_collection()
    -> Source_Collector::collect()
      -> Source_Registry::get_active_sources()
      -> For each source:
          -> Adapter::discover_listing_urls()
          -> Adapter::fetch_listing() (HTTP GET)
          -> Adapter::extract_obit_cards() (XPath parsing)
          -> Adapter::normalize() (date/city/name cleanup)
          -> Source_Collector::insert_obituary() (INSERT IGNORE, cross-source dedup)
    -> Dedup cleanup
    -> LiteSpeed cache purge
```

### Cron Hooks

The authoritative scrape job is `ontario_obituaries_collection_event`.

**Note:** `ontario_obituaries_daily_scrape` may exist in the cron table but is orphaned (no callback registered). It is a leftover from a prior version. The active recurring hook is `ontario_obituaries_collection_event`. If you see both in WP Crontrol, ignore `daily_scrape`.

### Database Table

`{prefix}ontario_obituaries` — columns: `id`, `name`, `date_of_birth`, `date_of_death`, `age`, `funeral_home`, `location`, `image_url`, `description`, `source_url`, `source_domain`, `source_type`, `city_normalized`, `provenance_hash`, `suppressed_at`, `created_at`.

**Unique key:** `(name(100), date_of_death, funeral_home(100))`

**Known issue:** This unique key depends on `date_of_death`. Records with no death date cannot be ingested (collector gate). Schema redesign pending.

### Two Page Systems

| Page | URL | How it works |
|------|-----|--------------|
| Shortcode page | `/ontario-obituaries/` | Real WP page (ID 77108), uses Litho theme `get_header()`/`get_footer()` normally. Plugin renders via `[ontario_obituaries]` shortcode. |
| SEO virtual pages | `/obituaries/ontario/...` | Virtual pages via rewrite rules. Plugin intercepts via `template_include` filter and renders using `wrapper.php` (custom HTML shell with Elementor header/footer by ID). |

---

## VERSION HISTORY (SIGNIFICANT)

| Version | PR(s) | What changed |
|---------|-------|--------------|
| v1.0-v2.2 | #1 | Initial plugin, basic scraping, Facebook integration, admin panel |
| v3.0.0 | #2 | Complete rewrite — source adapters, SEO hubs, suppression system |
| v3.1.0-v3.4.0 | #3-#11 | Auto-page creation, nav menu, SEO schema, dedup, cache-busting |
| v3.5.0 | #12 | Unified on LiteSpeed cache, removed W3TC/WP Super Cache |
| v3.6.0 | #13-#14 | Critical scraping fixes — date parsing, adapter selectors |
| v3.7.0 | #15 | Data repair, cross-source dedup enrichment, sitemap fix |
| v3.8.0 | #16 | Governance framework (PLATFORM_OVERSIGHT_HUB.md), version alignment |
| v3.9.0 | #17 | Pagination fix, source registry hardening, dead-source disabling |
| v3.10.0 | #18-#20 | Route scrape through Source_Collector, Litho blog-title-bar CSS fix |
| v3.10.1 | #21-#22 | Remove external source_url links, UI simplification (remove Quick View/Share/flag) |
| — | #23 | **CLOSED** — multi-file v3.10.2 rejected for rule breach (combined concerns) |
| — | #24 | **MERGED** — Adapter-only: remove Jan-1 date fabrication, add death-date extraction |
| — | #25 | **MERGED** — SEO-only: template_include wrapper for correct header/footer |

---

## WHAT PR #24 DID (Adapter Fix — commit `3590e1f`)

**Problem:** The Remembering.ca adapter fabricated dates as `YYYY-01-01` when only a year was available (e.g., `1945-2026` became `date_of_birth=1945-01-01`, `date_of_death=2026-01-01`). This polluted the database with fake January 1st dates.

**Fix (single file: `class-adapter-remembering-ca.php`):**
- Removed `$card['year_death'] . '-01-01'` and `$card['year_birth'] . '-01-01'` fabrication
- Added 3 regex patterns to extract real death dates from obituary text:
  - "passed away on December 14, 2025"
  - "entered into rest on January 3, 2026"
  - "died peacefully on March 5, 2025"
- All patterns require a **death keyword** trigger (no generic "on Month Day, Year")
- Falls back to `published_date` if no death date found
- If still no date: `date_of_death` stays empty, collector gate skips ingest
- Added year-only age calculation (`year_death - year_birth`, guarded `0 < age <= 130`)

**Impact:** Records with only year ranges and no extractable death phrase will no longer be ingested. This is intentional — prevents fake dates.

---

## WHAT PR #25 DID (SEO Header/Footer Fix — commit `699c718`)

**Problem:** SEO virtual pages (`/obituaries/ontario/...`) called bare `get_header()`/`get_footer()`. On the shortcode page (`/ontario-obituaries/`), a real WP post resolves to Monaco's Elementor header/footer. On SEO virtual pages, there's no real post context, so the Litho theme fell back to a demo/default header instead of Monaco's design.

**Fix (5 files, 368 insertions / 106 deletions):**

1. **`class-ontario-obituaries-seo.php`** (modified):
   - Added `template_include` filter at priority 99 (`maybe_use_seo_wrapper_template()`)
   - Double gate: requires `is_obituary_seo_request()` AND `ontario_obituaries_seo_mode` set
   - Refactored `handle_seo_pages()` to set query vars instead of including templates + exit
   - Render methods (`render_ontario_hub`, `render_city_hub`, `render_individual_page`) now store data in query vars
   - Added `output_noindex_tag()` via query var (replaces anonymous closure in individual.php)
   - Added `disable_canonical_redirect_on_seo_pages()` to prevent WP redirecting `/obituaries/ontario/...` to `/obituaries/`
   - Added ID helper methods with constants + filter overrides for Elementor template IDs
   - Registered 5 new query vars: `seo_mode`, `seo_data`, `header_template_ids`, `footer_template_id`, `noindex`

2. **`templates/seo/wrapper.php`** (NEW):
   - Pattern 2: full HTML5 shell with `wp_head()` / `wp_footer()`
   - Never calls `get_header()` / `get_footer()` (avoids Litho demo nav)
   - Never instantiates `Ontario_Obituaries_SEO` class
   - Reads all data from query vars only
   - Renders Monaco Elementor header (IDs 13039 + 15223) and footer (ID 35674) via `get_builder_content_for_display()`
   - Full Elementor availability guard (class_exists -> instance() -> frontend -> method_exists)
   - Falls back to minimal nav/footer if Elementor unavailable
   - Initializes all template variables with type-safe defaults

3. **`hub-ontario.php`, `hub-city.php`, `individual.php`** (modified):
   - Removed `get_header()` and `get_footer()` calls
   - Now content-only partials included by wrapper.php

**Elementor Template IDs (environment-specific):**
- Mini-header (top bar): `13039`
- Main header (nav + logo): `15223`
- Footer: `35674`
- Override via `wp-config.php`: `define('ONTARIO_OBITUARIES_HEADER_IDS', array(13039, 15223));`
- Override via filter: `add_filter('ontario_obituaries_header_template_ids', $callback);`

---

## CURRENT STATE (as of 2026-02-09)

### What's working
- Source collector pipeline with 6 adapter types
- Remembering.ca adapter with real death-date extraction (PR #24)
- SEO pages with template_include wrapper (PR #25)
- Shortcode page (`/ontario-obituaries/`) — unaffected by recent PRs
- Schema.org, sitemaps, canonical URLs, OpenGraph tags
- LiteSpeed cache headers with tag-based purge
- Suppression/removal system

### What needs attention

| Issue | Priority | Details |
|-------|----------|---------|
| **Cache purge needed** | HIGH | After PR #25 merge, LiteSpeed must be purged. Header may appear distorted until cache is cleared. |
| **Permalinks flush needed** | HIGH | PR #25 added new query vars. Go to Settings -> Permalinks -> Save Changes. |
| **Version bump to 3.10.2** | MEDIUM | Plugin header still says 3.10.1. PRs #24 and #25 did not bump. Needs a dedicated PR. |
| **Old Jan-1 rows in DB** | MEDIUM | PR #24 prevents NEW fabricated dates but existing `YYYY-01-01` rows remain. Needs a data repair PR. |
| **Schema redesign** | LOW | Unique key `(name, date_of_death, funeral_home)` prevents records with no death date. Need `birth_year`/`death_year` columns and new unique key. |
| **Max-age audit** | LOW | Pagination can drift into old archive pages. Adapters need max-age enforcement. |

### Deployment method
- **WP Pusher** auto-deploys from `main` branch on merge.
- After merge: purge LiteSpeed + flush permalinks via WP Admin.
- Assume no SSH/WP-CLI; operations should be doable via WP Admin.

### Post-deploy checklist
1. Visit `/obituaries/ontario/` — verify Monaco header/footer, no Litho demo nav
2. Visit `/obituaries/ontario/{city}/` — verify city hub renders
3. Visit `/obituaries/ontario/{city}/{name}-{id}/` — verify individual page
4. Visit `/ontario-obituaries/` — verify shortcode page unaffected
5. View source: single `<!DOCTYPE html>`, JSON-LD present, `wp_head` hooks fire
6. Test `/obituaries/ontario` (no trailing slash) — should NOT redirect to `/obituaries/`
7. Purge LiteSpeed Cache -> Toolbox -> Purge All
8. Settings -> Permalinks -> Save Changes

---

## PR LOG (complete)

| PR | Status | Commit | Title |
|----|--------|--------|-------|
| #1 | Merged | `641525c` | fix(v2.2.0): resolve all P0 blockers |
| #2 | Merged | `6544c80` | feat(v3.0.0): complete rewrite — source adapters, SEO hubs |
| #3 | Merged | `e60e840` | feat(pages): auto-create Obituaries page on activation |
| #4 | Merged | `ffbcabb` | feat(nav): auto-add Obituaries to site menu |
| #5 | Merged | `59291e3` | fix(nav): improve menu detection |
| #6 | Merged | `a7e949d` | fix(nav): reset stale menu flag |
| #7 | Merged | `cbb20aa` | feat(seo+local): York Region focus, sitemap, LocalBusiness |
| #8 | Merged | `dcc4d42` | fix(dedup+links): cross-source duplicate removal |
| #9 | Merged | `8dda8b8` | fix(v3.1.0): add CSS for new UI elements |
| #10 | Merged | `d8296b1` | fix(v3.2.0): dedup enrichment, SEO fixes, XSS hardening |
| #11 | Merged | `f1516f1` | fix(v3.3.0): fuzzy dedup, WP Pusher auto-purge, SEO enhancements |
| #12 | Merged | `fcf5251` | fix(v3.4.0): SEO hub rewrite conflict, cache-bust admin assets |
| #13 | Merged | `394a78b` | fix(v3.5.0): unify on LiteSpeed cache |
| #14 | Merged | `8505eaa` | fix(v3.6.0): critical scraping fixes — date parsing, adapters |
| #15 | Merged | `b694a81` | fix(v3.7.0): data repair, dedup enrichment, sitemap double-slash |
| #16 | Merged | `40b6c47` | fix(v3.8.0): governance framework, version alignment |
| #17 | Merged | `8434c7c` | fix(v3.9.0): pagination fix, source registry hardening |
| #18 | Merged | `22e34d0` | fix(v3.10.0): route scrape through Source_Collector |
| #19 | Merged | `1f8c115` | fix(v3.10.0): Fix 0 Obituaries + Litho SEO layout |
| #20 | Merged | `d584d36` | fix(v3.10.0): target Litho outer wrapper (triple p typo) |
| #21 | Merged | `9300c27` | fix(templates): remove external source_url links |
| #22 | Merged | `e2b1c2b` | fix(v3.10.1): UI simplification — remove Quick View, Share, flag |
| #23 | **CLOSED** | — | Rejected: combined adapter + SEO + version bump (rule breach) |
| #24 | Merged | `3590e1f` | fix(adapter): remove Jan-1 date fabrication, add death-date extraction |
| #25 | Merged | `699c718` | fix(seo): template_include wrapper for correct header/footer |

---

## PENDING WORK (priority order)

1. **Cache purge + permalinks flush** — WP Admin, no code change
2. **Version bump to v3.10.2** — Separate PR, plugin header + constant only
3. **Data repair** — Clean existing fabricated `YYYY-01-01` rows in DB
4. **Schema/dedupe redesign** — Add `birth_year`/`death_year` columns, new unique key
5. **Max-age audit** — Prevent pagination drift into old archive pages

---

## HOW TO DEPLOY (for server admin)

WP Pusher auto-deploys on merge to `main`. After any merge:

1. **WP Admin -> LiteSpeed Cache -> Toolbox -> Purge All**
2. **WP Admin -> Settings -> Permalinks -> Save Changes** (click Save, don't change anything)
3. Hard-refresh browser: `Ctrl+Shift+R`
4. Verify the post-deploy checklist above

Assume no SSH/WP-CLI; all operations should be doable via WP Admin.

---

## RULES REMINDER

Before doing ANY work, read `PLATFORM_OVERSIGHT_HUB.md`. Key rules:
- **Rule 2:** Paste complete diff + explanation for approval BEFORE committing
- **Rule 3:** Version header must match constant; bump on behavior changes
- **Rule 8:** One concern per PR (don't mix scraper + SEO + data repair)
- **Rule 10:** AI developers follow the same rules — no auto-commit without approval
