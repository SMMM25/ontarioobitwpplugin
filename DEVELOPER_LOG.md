# DEVELOPER LOG — Ontario Obituaries WordPress Plugin

> **Last updated:** 2026-02-20 (v5.3.6 — Phase 4.1 legacy logger bridge + cleanup cron + template helper)
> **Plugin version:** `5.3.6` (sandbox — awaiting deploy)
> **Live site version:** `5.3.5` (monacomonuments.ca — deployed 2026-02-20 via SSH ZIP upload)
> **Live plugin slug:** `ontario-obituaries` (folder: `~/public_html/wp-content/plugins/ontario-obituaries/`)
> **Main branch HEAD:** PR #107 merged (v5.3.5 Phase 3 docs update)
> **Project status:** AI rewriter running autonomously. ~300 published, ~302 pending. Error handling **70% complete** (Phase 1 + 2a + 2b + 2c + 2d + 3 + 4.1 deployed). **URGENT: Image hotlink issue** (Section 28 of Oversight Hub).
> **Next priority:** Phase 4.2 (DB write wrapping for top 5 hotspot files).

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

The plugin runs on WordPress with the **Litho theme** and **Elementor** page builder. Caching is via **LiteSpeed Cache**. Deployment is **manual via cPanel** (WP Pusher cannot auto-deploy private repos).

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
| #26-#45 | Merged | various | UI redesign, data enrichment, cron reliability, security hardening |
| #46 | Merged | `b7266c8` | feat(v3.17.0): fix duplicates, wrong address, broken shortcode, security |
| #47 | Merged | `9deb32e` | feat(v3.17.0): dedupe + shortcode alias + business schema |
| #48 | Merged | `7971189` | fix(v3.17.1): aggressive dedup — name-only pass + DB name cleanup |
| #49 | Merged | `a668581` | fix(urgent): remove [obituaries] shortcode alias — breaks Elementor page |
| #50 | Merged | `f031ffa` | fix(urgent): reverse redirect direction + remove broken shortcode alias |
| #51 | Merged | `251f447` | feat(v4.0.0): add 6 Postmedia obituary sources — expand coverage 1→7 |
| #52 | Merged | `a50904c` | feat(v4.2.1): Complete AI Memorial System — Phases 1-4 + QA audit fixes |
| #53 | Merged | `b71f4b1` | fix(v4.2.2): city data quality repair + sitemap ai_description fix |
| #54 | Merged | pending | feat(v4.2.3): admin UI for AI Rewriter + Groq key + additional city slug fixes |
| #55 | Merged | various | fix(v4.2.4): death date cross-validation + AI Rewriter activation fix |
| #56 | Merged | various | feat(v4.3.0): GoFundMe Auto-Linker + AI Authenticity Checker |
| #57 | Merged | various | (merge commit for v4.3.0) |
| #58 | Merged | `98f57f9` | feat(v4.5.0): AI Customer Chatbot + Google Ads Optimizer + Enhanced SEO Schema |
| #59-#70 | Merged | various | Minor fixes, UI tweaks, scraper improvements |
| #71 | Merged | various | fix(v4.6.7): fix 401/403 API errors + API key diagnostic tool |
| #72 | Merged | various | feat(v5.0.0): Groq structured JSON extraction replaces regex for 97% accuracy |
| #73 | Merged | various | feat(v5.0.0): Groq structured JSON extraction + version bump |
| #74 | Merged | various | fix(v5.0.0): Groq free-tier rate limit tuning |
| #75 | Merged | various | fix(v5.0.0): batch size 5, delay 8s to fix AJAX timeouts |
| #76 | Merged | various | fix(v5.0.0): update all UI labels to match v5.0.0 workflow |
| #77 | Merged | `c094648` | fix(v5.0.0): switch to llama-3.1-8b-instant + 15s delay |
| #78 | Merged | `3cea74a` | feat(v5.0.0): bulletproof CLI cron — 10/batch @ 6s (~250/hour) |
| #79 | Merged | `54e7095` | fix(v5.0.1): process 1 obituary at a time + mutual exclusion lock |
| #80 | Merged | `8812580` | fix(v5.0.2): respect Groq 6,000 TPM limit — 12s delay, no fallback on 429 |
| #83 | Merged | `4566eb3` | fix(v5.0.3): BUG-C1/C3 — remove 1,663 lines of dangerous historical migrations from on_plugin_update() |
| #84 | Merged | `2576ec3` | fix(v5.0.4): BUG-C2/H8 — remove status='published' gate from 18 display/SEO queries + REST API auth hardening (manage_options) + REST published-only filter + JSON-LD XSS hardening (JSON_HEX_TAG) + centralized status validation helper + RULE 14 (Core Workflow Integrity). Retroactive QC: 1 new finding (5 SEO helper queries missing suppressed_at) addressed in PR #86. |
| #85 | Merged | `7060a9c` | fix(v5.0.4-5.0.5): BUG-C4/H2/H7 — remove duplicate cleanup from init hook; WP-native lock; daily + one-shot cron; complete uninstall cleanup (22 options, 8 transients, 8 cron hooks). 4 rounds of QC review. Sprint 1 complete. |
| #86 | Merged | `aa99abd` | fix(v5.0.5): BUG-H1/H4/H5/H6/M4 — rate calc fix, undefined $result init, shutdown throttle post-success only, domain lock exact match, 5 SEO queries suppressed_at IS NULL. Sprint 2 complete (8/8). Tag v5.0.5, release created. |
| #87 | Pending | — | fix(v5.0.6): Sprint 3+4 complete — BUG-M1 (shared Groq rate limiter), BUG-M5 (staggered cron), BUG-M6 (throughput comments), Sprint 4 Tasks 19-21 (LLM eval, prompt reduction, time-spread processing). Overall: 22/23 (96%). |
| #88-#91 | Merged | various | AI rewriter fixes: validator demotions, queue deadlock prevention (v5.1.0-v5.1.2) |
| #92 | Merged | `63bf2e6` | fix(cron+admin): repeating 5-min rewrite schedule, admin cache fix, ai_rewrite_enabled gate (v5.1.4) |
| #93 | Merged | `8318b8c` | fix(activation): handle delete-upgrade settings wipe (v5.1.5) |
| #95 | Merged | `d21b132` | feat(images): Image Localizer v5.2.0 — stream-to-disk, full error handling |
| #96 | Merged | `078a943` | feat(errors): Phase 1 Error Handling Foundation — `oo_log`, `oo_safe_call`, `oo_db_check`, health counters (v5.3.0) |
| #97 | Merged | `de1a80f` | feat(errors): Phase 2a Cron Handler Hardening — all 8 cron handlers wrapped, QC-approved (v5.3.1) |
| #98 | Merged | `81587cf` | chore: bump version to v5.3.1 for Phase 2a release |
| #99 | Merged | `2029a77` | fix(rewriter): unblock queue — demote name validation + strip nicknames (v5.3.1) |
| #100 | Merged | `4002029` | feat(errors): Phase 2b — route all HTTP through oo_safe_http wrappers + QC fixes (v5.3.2) |

---

## CURRENT STATE (as of 2026-02-20)

### Plugin Version: **5.3.2** (live + main + sandbox)
### Main Branch Version: **5.3.2** (PR #100 merged 2026-02-20)
### Live Site Version: **5.3.2** (monacomonuments.ca — deployed 2026-02-20 via SSH `git clone` + `rsync`)
### Live Plugin Slug: **ontario-obituaries** (folder: `~/public_html/wp-content/plugins/ontario-obituaries/`)
### Project Status: **ERROR HANDLING 40% COMPLETE** — Phase 2b deployed live

### What's Working (Live — v5.3.2)
- **AI Rewriter** — ✅ Autonomous. ~300 published (all AI-rewritten), ~302 pending.
- **Error Handling Phase 1** — ✅ `oo_log()`, `oo_safe_call()`, `oo_db_check()`, health counters, log deduplication
- **Error Handling Phase 2a** — ✅ All 8 cron handlers wrapped with structured error handling
- **Error Handling Phase 2b** — ✅ Deployed live (PR #100, v5.3.2): All 15 `wp_remote_*` → `oo_safe_http_*`
  - SSRF protection via `wp_safe_remote_*`, URL sanitization via `esc_url_raw()`, body truncation ≤ 4 KB
  - Helper functions: `oo_http_error_status()`, `oo_http_error_body()`, `oo_http_error_header()`
  - QC gates passed: raw HTTP = 0, no duplicate logging, status-code preserved, no secrets logged
  - QC fixes applied: header normalization, redirection 0–5 clamp, malformed-URL guard, array header key normalization
- **Health monitoring** — ✅ `oo_health_get_summary()` reports pipeline status, last_ran, last_success per subsystem
- **Name validation hotfix** — ✅ Parenthesized nicknames stripped, name_missing demoted to warning
- Source collector pipeline with remembering_ca adapter (7 active Postmedia sources)
- **602 obituaries** in database (~300 published + ~296 pending)
- All 7 sources collecting successfully every 12h via WP-Cron
- Memorial pages with QR code, lead capture form, Schema.org markup
- IndexNow, domain lock, logo filter, LiteSpeed cache — all active
- **AI Customer Chatbot** — Groq-powered, live on frontend
- **GoFundMe Auto-Linker** — active
- **AI Authenticity Checker** — active
- **cPanel cron** — `*/5 * * * * /usr/local/bin/php /usr/local/sbin/wp --path=/home/monaylnf/public_html cron event run --due-now >/dev/null 2>&1`
- **cPanel cron-rewriter.php** — Primary rewrite processor (standalone PHP script with file lock)
- **WP-CLI access** — Available at `/usr/local/sbin/wp` (SSH confirmed working)

### v5.3.2 Phase 2b Session (2026-02-20)

**Phase 2b — HTTP Wrapper Conversion (PR #100, v5.3.2)**:
- Reverted PR #100's original logging-only changes, then implemented proper wrapper conversion
- Enhanced `class-error-handler.php` wrappers:
  - WP_Error now contains `status` (int), `body` (≤4 KB), `headers` (allowlisted)
  - New helpers: `oo_http_error_status()`, `oo_http_error_body()`, `oo_http_error_header()`
  - New utilities: `oo_redact_url()` (strips query strings), `oo_safe_error_headers()` (allowlist filter)
  - Safety clamps: `sslverify` filter-only, `redirection` ≤ 5, `timeout` 1–60 s
  - URL validation: `esc_url_raw()` + `wp_http_validate_url()` before every request
- Converted 15 call sites across 9 files:
  - `class-source-adapter-base.php` → `oo_safe_http_get('SCRAPE', ...)`
  - `class-adapter-remembering-ca.php` → `oo_safe_http_head('SCRAPE', ...)`
  - `class-ai-rewriter.php` (3 sites) → `oo_safe_http_post('REWRITE', ...)`
  - `class-ai-chatbot.php` → `oo_safe_http_post('CHATBOT', ...)`
  - `class-ai-authenticity-checker.php` → `oo_safe_http_post('AUDIT', ...)`
  - `class-gofundme-linker.php` → `oo_safe_http_post('GOFUNDME', ...)`
  - `class-indexnow.php` → `oo_safe_http_post('SEO', ...)`
  - `class-google-ads-optimizer.php` (5 sites) → `oo_safe_http_post('GOOGLE_ADS', ...)`
  - `class-image-pipeline.php` → `oo_safe_http_head('IMAGE', ...)`
- QC gates all passed: raw HTTP = 0, oo_log outside wrapper = 0, helper uses = 13, secrets logged = 0
- **QC Review — 3 required fixes + 1 optional improvement applied:**
  1. **Fix 1**: `oo_http_error_header()` now lower-cases the `$header` lookup key + lower-cases array keys via `array_change_key_case()` — callers can pass any case.
  2. **Fix 2**: `redirection` arg clamped to `max(0, min(val, 5))` in all three wrappers — prevents negative values.
  3. **Fix 3**: `oo_redact_url()` now checks for `false === wp_parse_url()` and returns `'(malformed-url)'` instead of crashing.
  4. **Optional**: `oo_safe_error_headers()` normalizes array keys via `array_change_key_case()` before lookup — mixed-case header keys now matched consistently.
- Version bumped to 5.3.2 (runtime behavior change: SSRF protection + URL sanitization)
- 11 files changed, 583 insertions, 297 deletions
- ZIP built: `ontario-obituaries-v5.3.2.zip` (307 KB)

### What's NOT Working / Needs Attention
- **🔴 URGENT: Image hotlink** — All published obituary images hotlinked from `cdn-otf-cas.prfct.cc` (Tribute Archive CDN). Not stored locally. See PLATFORM_OVERSIGHT_HUB.md Section 28.
- **Error handling progressing** — Phase 2b (HTTP wrappers) COMPLETE: 15 call sites converted. Phase 2c (DB hotspots) NEXT.
- **Google Ads Optimizer** — Disabled by owner choice (off-season). Toggle on in spring.
- **PR #87** — Still pending merge (v5.0.12 QC-R12 hardening: atomic CAS rate limiter). Not blocking live operations.

### v5.3.x Error Handling Session (2026-02-20)

**Phase 1 — Foundation (PR #96, v5.3.0)**:
- New `includes/class-error-handler.php` (779 lines)
- Wrappers: `oo_safe_call()`, `oo_safe_http()`, `oo_db_check()`
- Structured logging: `oo_log()` with subsystem + code + run_id + context
- Health counters: `oo_health_increment()`, `oo_health_get_summary()`, `oo_health_record_ran()`, `oo_health_record_success()`
- Log deduplication (5-min window, discriminator-based: source/source_domain/hook/url host)
- SQL redaction by default (opt-in via `OO_DEBUG_LOG_SQL`)
- Run IDs: `oo_run_id()` for correlating log entries

**Phase 2a — Cron Hardening (PR #97, v5.3.1)**:
- All 8 cron handlers wrapped with error handling
- `ai_rewrite_batch()`: try/catch before lock, settings gate, reschedule check, try/catch/finally for main processing, guaranteed lock release
- Other batches: `oo_safe_call()` + `oo_health_record_ran()` + `oo_health_record_success()`
- 13 new error codes (CRON_REWRITE_BOOTSTRAP_CRASH, CRON_REWRITE_DISABLED, CRON_REWRITE_RESCHEDULE_FAIL, etc.)

**Hotfix — Name Validation (PR #99, v5.3.1)**:
- Root cause: Obituary ID 1083 ("Patricia Gillian Ansaldo (Gillian)") had parenthesized nickname
- Validator split name into ["Patricia", "Gillian", "Ansaldo", "(Gillian)"], couldn't match in rewrite
- Queue blocked for 8+ hours (101 consecutive failures)
- Fix: Strip parenthesized nicknames, demote name_missing to warning
- Result: Queue unblocked, pending count dropping

**Deployment**:
- Method: SSH terminal (unzip directly into plugin directory)
- Post-deploy: `pipeline_healthy = true`, all subsystems reporting success
- cPanel `cron-rewriter.php` confirmed as primary processor; WP-Cron correctly skips when lock set

**v5.3.2 Deployment (2026-02-20)**:
- PR #100 merged on GitHub, then deployed via SSH: `git clone --depth 1` + `rsync --delete` into `~/public_html/wp-content/plugins/ontario-obituaries/`
- **Plugin slug changed**: Old folder `ontario-obituaries-v5.3.1` removed; new canonical folder `ontario-obituaries` (slug: `ontario-obituaries`). This eliminates the version-in-folder-name anti-pattern.
- Resolved `Cannot redeclare` fatal caused by both old and new folders coexisting (WordPress scanned both plugin dirs)
- Fix: deactivated old slug, deleted old folder, activated new slug — clean transition
- Post-deploy verification:
  - `ontario-obituaries` active, version 5.3.2
  - All 4 QC-fixed functions loaded: `oo_safe_http_get`, `oo_http_error_header`, `oo_redact_url`, `oo_safe_error_headers` — all OK
  - `ONTARIO_OBITUARIES_VERSION` = `5.3.2`
  - Raw `wp_remote_*` calls in includes/ = 0
  - Cron ran 52s (rewrite batch processed obituaries)
  - `pipeline_healthy = 1`, `REWRITE` last_success updated
  - Site HTTP/2 200 confirmed
- **Future deploys**: `git clone --depth 1 ... && rsync -av --delete ... ~/public_html/wp-content/plugins/ontario-obituaries/ && rm -rf ~/oo-deploy-tmp` then deactivate/activate

**Phase 2c — DB Hotspots (PR #102, v5.3.3)**:
- 35 new `oo_db_check()` calls across 8 files (all unchecked `$wpdb` writes wrapped)
- Strict `false === $result` prevents treating return 0 as error
- `verification_token` NULL fix: replaced `$wpdb->update()` with `$wpdb->query($wpdb->prepare(...))` for true SQL NULL
- New error codes: `DB_SCHEMA_FAIL`, `DB_DELETE_FAIL`
- 0 remaining unchecked DB writes

**Phase 2d — AJAX + Remaining DB Checks (PR #104, v5.3.4)**:
- AJAX delete: `oo_db_check()` on `$wpdb->delete()`; `OBIT_DELETED` audit only when `$result > 0`
- Reset/rescan purge: `oo_db_check()` on batch DELETE with `session_id` context
- Groq rate limiter: `oo_db_check()` on START TRANSACTION + COMMIT; legacy `log_message` guarded behind `!function_exists('oo_db_check')` to prevent duplicate logging
- Display: `oo_log` warning on `get_obituary()` when `$wpdb->last_error` is non-empty
- 4 files changed, +49/−8 lines
- All 21 AJAX handlers audited — every one has nonce + capability checks

**Phase 3 — Health Dashboard (PR #106, v5.3.5)** — merged `f36e7f4` 2026-02-20:
- New `includes/class-health-monitor.php` (~480 lines)
  - Admin submenu page: Ontario Obituaries → System Health
  - Pipeline summary banner (healthy/needs attention)
  - Cron job status table (scheduled/overdue/missing + last success)
  - Subsystem checks (DB table, Groq key, uploads, WP-Cron, PHP memory, error handler)
  - Error code breakdown table (24h, sorted by count)
  - Last success/ran timestamps per subsystem
- Admin bar badge: yellow (warnings) or red (errors/critical) dot with issue count
- REST endpoint: `GET /wp-json/ontario-obituaries/v1/health` (admin-only)
- No new DB table — reads from existing wp_options and transients
- Registered via `Ontario_Obituaries::register_admin_menu()` + `Ontario_Obituaries_Health_Monitor::init()`
- QC fixes applied (conditional approve → approved):
  - QC-R1: `require_once` + `init()` guarded with `is_admin() || REST_REQUEST` — zero frontend overhead
  - QC-R2: Table name validated against `/^[A-Za-z0-9_]+$/` regex before raw SQL count query
  - Non-blocking: REST fallback changed 500 → 503 (feature unavailable, not server error)

**Phase 4.1 — Legacy Logger Bridge + Cleanup Cron + Template Helper (PR #108, v5.3.6)**:
- `ontario_obituaries_log()` now forwards to `oo_log()` when available (one-way bridge)
  - All 154 legacy log calls now route through structured logging (subsystem=LEGACY, code=LEGACY_LOG)
  - No recursion: `oo_log()` no longer calls `ontario_obituaries_log()` (writes directly to `error_log`)
  - URL redaction via `oo_redact_url()` on any URLs in legacy messages
  - Level mapping: info→info, warning→warning, error→error (1:1 pass-through)
  - Fallback: raw `error_log()` if Phase 1 handler not yet loaded
- `oo_log()` now respects `debug_logging` setting directly (error/critical always logged, info/warning gated)
- New `oo_safe_render_template($name, $callable)` helper in `class-error-handler.php`
  - Output buffer + Throwable catch
  - On crash: discards partial output, logs TEMPLATE_CRASH, returns user-friendly placeholder
  - Does NOT wrap templates yet (that's Phase 4.3, v5.3.8)
- Daily health cleanup cron: `ontario_obituaries_health_cleanup_daily`
  - Scheduled on activation (only if `oo_health_cleanup` exists)
  - Cleared on deactivation
  - Handler wrapped in `oo_safe_call()` so cleanup can't crash WP-Cron
  - Calls existing `oo_health_cleanup()` to prune stale dedupe transients + expired counters
- 2 files changed: `ontario-obituaries.php`, `class-error-handler.php`

### Previous State (as of 2026-02-18)

**v5.1.2** (PRs #88-91): Demoted age/death-date validators to warnings (non-blocking). Prevents queue deadlock on edge cases like "age not mentioned" (ID 1311).

**v5.1.3** (squashed into PR #92): Admin settings page gets X-LiteSpeed-Cache-Control no-cache headers + live AJAX status refresh.

**v5.1.4** (PR #92, merged):
- Replaced one-shot self-reschedule with repeating `wp_schedule_event()` on `ontario_five_minutes` interval (300s).
- Activation registers the repeating event gated by `ai_rewrite_enabled` + Groq key.
- Deactivation clears the hook. Post-collection ensures event persists.
- Batch function no longer self-reschedules but re-registers as safety net.

**v5.1.5** (PR #93, merged):
- Delete→Upload wipes settings via `uninstall.php`. `ai_rewrite_enabled` defaults to `false`.
- Fix: On activation, if Groq key exists but settings are missing, infer `ai_rewrite_enabled=true`.
- Safety net: batch function re-registers event unconditionally if missing while running.

**cPanel Cron Fix**: Changed from bare `wp` to `/usr/local/bin/php /usr/local/sbin/wp` — bare `wp` causes `$argv` undefined fatal in cron. WARNING: `crontab -l | sed | crontab -` wiped cPanel cron — always edit via cPanel interface.

**Copyright Safety**: All 178 published obituaries have AI rewrites (0 without). 403 pending not displayed.

**Image Hotlink Discovered**: All `image_url` values point to `cdn-otf-cas.prfct.cc`. Not stored locally. Marked URGENT.

### Previous State (as of 2026-02-15)

### Plugin Version: **5.0.2** (all environments in sync)
### Main Branch Version: **5.0.2** (PR #80 merged 2026-02-15)
### Live Site Version: **5.0.2** (monacomonuments.ca — deployed 2026-02-15 via WP Upload)
### Project Status: **CRITICAL BUGS IDENTIFIED** (2026-02-16 independent code audit)

### What's Working (Live — v5.0.2)
- Source collector pipeline with remembering_ca adapter (7 active Postmedia sources)
- **725+ obituaries** in database, displaying on /ontario-obituaries/ with 37+ pages
- All 7 sources collecting successfully every 12h via WP-Cron
- **528+ URLs** in sitemap, **117+ unique city slugs**
- **70+ city cards** on Ontario hub page
- Memorial pages with QR code (qrserver.com), lead capture form with AJAX handler
- Schema.org markup (Person, BurialEvent, BreadcrumbList, LocalBusiness, DonateAction)
- IndexNow search engine notification active
- Domain lock security feature active
- Logo filter active — images < 15 KB rejected at scrape time
- LiteSpeed cache with tag-based purge
- Suppression/removal system
- **AI Customer Chatbot** — Groq-powered, live on frontend, working
- **GoFundMe Auto-Linker** — active, auto-processing
- **AI Authenticity Checker** — active, auditing records every 4h
- **cPanel cron** — configured: `*/5 * * * * /usr/local/bin/php /home/monaylnf/public_html/wp-cron.php`

### What's NOT Working / Broken (2026-02-16 Audit Findings)
- **AI Rewriter** — Multiple code bugs in addition to Groq TPM limit:
  - ~~**Activation cascade** (BUG-C1)~~: ✅ **FIXED in v5.0.3 (PR #83)** — All historical migration blocks removed from `on_plugin_update()`
  - ~~**Display deadlock** (BUG-C2)~~: ✅ **FIXED in v5.0.4 (PR #84)** — Removed `status='published'` gate from 18 display/SEO queries. All non-suppressed records now visible with original factual data. AI rewrite enhances display in background. Core workflow preserved per RULE 14.
  - ~~**Non-idempotent migrations** (BUG-C3)~~: ✅ **FIXED in v5.0.3 (PR #83)** — Migration blocks removed; remaining operations are idempotent
  - **Init-phase dedup** (BUG-C4): Full-table GROUP BY runs on every page load
- **Uninstall incomplete** (BUG-H2, H7) — API keys (Groq, Google Ads) and 4+ cron hooks persist after uninstall
- **Domain lock bypass** (BUG-H6) — substring match allows spoofed domains
- **Shared Groq key** (BUG-M1) — Rewriter, chatbot, and authenticity checker compete for same 6,000 TPM quota
- **Google Ads Optimizer** — Disabled by owner choice (off-season). Toggle on in spring.
- See PLATFORM_OVERSIGHT_HUB.md Section 26 for full audit details and Section 27 for fix plan.

### What Was Attempted in v5.0.0-v5.0.2 (2026-02-14/15)
The AI Rewriter underwent extensive rework across 10 PRs (#71-#80) to solve rate-limiting:
- **v5.0.0** (PRs #72-#78): Switched from regex parsing to Groq structured JSON extraction. Swapped primary model from `llama-3.3-70b-versatile` to `llama-3.1-8b-instant` for lower token usage. Tried various batch sizes (3, 5, 10) and delays (6s, 8s, 15s). Built standalone CLI cron script.
- **v5.0.1** (PR #79): Reduced to 1 obituary per API call across all 4 execution paths. Added mutual-exclusion transient lock. Fixed critical TRUNCATE bug in uninstall that was wiping all obituary data.
- **v5.0.2** (PR #80): Increased delay from 6s to 12s to respect 6,000 TPM. Removed wasteful fallback-model retries on 429 errors. Added retry-after header parsing.
- **Outcome**: Processing improved from 2 obituaries to ~15 per run, but still hits the Groq TPM ceiling. The issue is fundamental to the free tier — not a code bug.

### What v4.2.2 Changes (SANDBOX — Pending PR)
**v4.0.1 — Logo Filter:**
- `is_likely_portrait()` rejects images < 15 KB as funeral home logos
- Migration cleans existing DB records with logo images

**v4.1.0 — AI Rewrite Engine:**
- New `class-ai-rewriter.php` (Groq API, Llama 3.3 70B + 3.1 8B fallback)
- `ai_description` column, fact-preserving prompt, validation layer
- Batch processing: 25/run, 1 req/6s, self-rescheduling cron
- REST endpoint: `/wp-json/ontario-obituaries/v1/ai-rewriter` (admin-only)

**v4.2.0 — Memorial Enhancements + Security:**
- BurialEvent JSON-LD schema when funeral home is present
- IndexNow integration: instant search engine notification on new obituaries
- QR code on individual obituary pages (QR Server API)
- Soft lead capture form (email + city, stored in wp_options)
- Domain lock: plugin only operates on monacomonuments.ca
- `.htaccess` direct PHP access blocking (pre-existing, verified)

**v4.2.1 — QA Audit Fixes:**
- **BUG FIX**: QR codes used deprecated Google Charts API (404) → switched to QR Server API
- **BUG FIX**: Lead capture form had no JS handler → added inline fetch() AJAX with success/error UX
- **IMPROVEMENT**: `should_index` now considers `ai_description` (not just `description`)
- Version bump: 4.0.0 → 4.2.1

**v4.2.2 — City Data Quality Repair:**
- **DATA REPAIR**: Multi-pass migration fixes `city_normalized` column:
  - Truncated city names (hamilt → Hamilton, burlingt → Burlington, etc.)
  - Street addresses stored as cities (King Street East Hamilton → Hamilton)
  - Garbled/encoded values (q2l0eq, mself-__next_f) → cleared
  - Biographical text (Jan was born in Toronto) → extracts city or clears
  - Facility names (Sunrise of Unionville) → cleared
  - Typos (Kitchner → Kitchener, Stoiuffville → Stouffville)
- **ROOT-CAUSE FIX**: Strengthened `normalize_city()` in adapter base to reject bad data at ingest time
- **SITEMAP FIX**: Query now includes obituaries where `ai_description` > 100 chars (not just `description`)
- Version bump: 4.2.1 → 4.2.2

**v4.2.3 — Admin UI + Extended City Repair:**
- **ADMIN UI**: Added AI Rewrite settings to Settings page (enable/disable checkbox + Groq API key field + live stats)
- **DATA REPAIR**: Extended migration with 17 additional address→city mappings for remaining bad slugs
- **NO MORE wp_options EDITING**: Owner can now enable AI rewrites from WP Admin → Ontario Obituaries → Settings
- Version bump: 4.2.2 → 4.2.3

**v4.2.4 — Death Date Cross-Validation + AI Rewriter Fix:**
- **BUG FIX**: Death dates from Remembering.ca structured metadata can have WRONG year. Fixed date priority: phrase-based extraction now overrides structured date ranges.
- **BUG FIX**: Future death dates (after today) are now rejected.
- **BUG FIX**: AI Rewriter batch now schedules immediately (30s delay) when settings are saved.
- **DATA REPAIR**: Migration cross-validates all death dates, fixes ~8 year mismatches + q2l0eq slug.
- Version bump: 4.2.3 → 4.2.4

**v4.3.0 — GoFundMe Auto-Linker + AI Authenticity Checker:**
- **GoFundMe Auto-Linker**: Searches GoFundMe for matching memorial campaigns. 3-point verification (name + death date + location). 20 per batch, 1 search/3s. Adds "Support the Family" button.
- **AI Authenticity Checker**: 24/7 random audits via Groq AI. 10 per cycle (8 new + 2 re-checks). Flags issues, auto-corrects high-confidence errors.
- **DB migration**: Added `gofundme_url`, `gofundme_checked_at`, `last_audit_at`, `audit_status`, `audit_flags` columns + indexes.
- Version bump: 4.2.4 → 4.3.0

**v4.5.0 — AI Customer Chatbot + Google Ads Optimizer + Enhanced SEO Schema:**
- **AI Customer Chatbot** (`class-ai-chatbot.php`, 32 KB): Groq-powered conversational AI with rule-based fallback. Greets visitors, answers service questions, directs to intake form (no-cost, priority queue), forwards inquiries to `info@monacomonuments.ca`. Quick-action buttons (Get Started, Pricing, Catalog, Contact). Rate-limited (1 msg/2s/IP), nonce-verified, XSS-protected. Zero cost (Groq free tier).
- **Google Ads Campaign Optimizer** (`class-google-ads-optimizer.php`, 43 KB): Connects to Google Ads API (account 903-524-8478). AI-driven campaign analysis, keyword optimization, bid/budget recommendations. Dashboard with spend, clicks, CTR, CPC, conversions, optimization score. Currently DISABLED (owner's off-season).
- **Enhanced SEO Schema**: Schema.org `DonateAction` added to individual memorial pages for GoFundMe links.
- **Frontend assets**: `ontario-chatbot.css` (11 KB), `ontario-chatbot.js` (13 KB).
- **Settings UI**: Added chatbot toggle + stats, Google Ads toggle + credential fields + dashboard.
- **QA audit**: PHP syntax (5 files), JS syntax, brace balance, nonce flow, XSS escaping — all passed.
- Version bump: 4.3.0 → 4.5.0

**v5.0.0 — Groq Structured JSON Extraction (2026-02-14):**
- **AI Rewriter overhaul**: Replaced regex field extraction with Groq structured JSON output
- Switched primary model from `llama-3.3-70b-versatile` to `llama-3.1-8b-instant` (lower token usage)
- Added `parse_structured_response()` for JSON parsing + field extraction
- `build_prompt()` now requests JSON output with rewritten text + structured fields
- `call_api()` updated: temperature 0.1, JSON response format
- `process_batch()` writes corrected fields (death date, birth date, age, location, funeral home)
- Multiple rate-limit tuning iterations (batch sizes 3-10, delays 6-15s)
- Version bump: 4.6.x → 5.0.0

**v5.0.1 — One-at-a-Time Processing + Mutual Exclusion (2026-02-15):**
- **ROOT CAUSE FIX**: Shutdown hook was the only running handler (processed 2, then 5-min throttle)
- **batch_size reduced to 1** across all 4 execution paths
- **Mutual exclusion transient** `ontario_obituaries_rewriter_running` prevents concurrent API calls:
  - WP-Cron: 5-min TTL
  - Shutdown hook: 1-min TTL
  - AJAX: 2-min TTL
  - Lock deleted after processing completes
- **CRITICAL BUG FIX**: `uninstall.php` used TRUNCATE TABLE which wiped all obituary data on reinstall
  - Changed to targeted `DELETE FROM wp_options WHERE option_name LIKE 'ontario_obituaries_%'`
  - Protected `db_version` option from deletion
- **JS text corrected**: "up to 5 obituaries" → "1 obituary per call"
- Version bump: 5.0.0 → 5.0.1

**v5.0.2 — Groq TPM Limit Respect (2026-02-15):**
- **request_delay increased from 6s to 12s** (~5 req/min, ~6,000 TPM)
- **No fallback model retries on 429** — org-level TPM limits affect all models
- **Retry-after header parsing** — reads Groq's `retry-after` header for precise backoff
- **Updated throughput estimates** — ~200 rewrites/hour (was ~360)
- **OUTCOME**: Improved from ~6 to ~15 items per run, but still hits TPM ceiling
- **PROJECT PAUSED** after this version — Groq free-tier limitation, not a code bug
- Version bump: 5.0.1 → 5.0.2

**INDEPENDENT CODE AUDIT (2026-02-16):**

**v5.0.6 — Sprint 3+4 Complete (2026-02-16):**
- **BUG-M1 FIX: Shared Groq rate limiter** — New `class-groq-rate-limiter.php` singleton coordinates TPM usage across AI Rewriter, Chatbot, and Authenticity Checker. 60-second sliding-window transient with 5,500 TPM budget. All consumers call `may_proceed()` before and `record_usage()` after API calls. Chatbot gracefully falls back to rule-based responses when budget exhausted.
- **BUG-M5 FIX: Staggered cron scheduling** — Post-scrape events now staggered: dedup +10s, rewrite +60s, GoFundMe +300s. Prevents API consumer overlap.
- **BUG-M6 FIX: Corrected throughput comments** — cron-rewriter.php header updated from "~200/hour" to "~180 theoretical, ~15 practical". ontario-obituaries.php batch handler updated from "~360/hour" to "~18/run theoretical".
- **Sprint 4 Task 19: Alternative LLM API evaluation** — Evaluated OpenRouter (free tier, multiple models), Together.ai (free credits), Cloudflare Workers AI (free, built-in rate limiting), Cerebras (free, fastest inference). Recommendation: OpenRouter or Cerebras as drop-in Groq replacement if TPM remains insufficient.
- **Sprint 4 Task 20: Prompt token reduction** — System prompt consolidated from ~400 to ~280 tokens. Saves ~120 tokens/call × 5 calls/min = ~600 TPM headroom.
- **Sprint 4 Task 21: Time-spread processing** — Batch max_runtime reduced from 240s to 90s; self-reschedule interval from 300s to 120s. Each batch processes 3-5 obituaries then yields. Sustainable ~60-90/hour vs. bursty 15/5-min.
- **Cleanup**: New `groq_tpm_window` transient added to deactivation and uninstall routines. Rate limiter class loaded before consumer classes.
- **Files changed**: ontario-obituaries.php (+version bump to 5.0.6, stagger fix, time-spread, deactivation cleanup), class-ai-rewriter.php (rate limiter integration, prompt reduction), class-ai-chatbot.php (rate limiter integration), class-ai-authenticity-checker.php (rate limiter integration), cron-rewriter.php (comment fix), uninstall.php (new transient cleanup), NEW: class-groq-rate-limiter.php.
- Version bump: 5.0.5 → 5.0.6
> An independent line-by-line code review was performed on the entire plugin codebase.
> The previous developer's claim that "the plugin is stable" was found to be **incorrect**.
> **17 bugs identified**: 4 critical, 7 high-severity, 6 medium-severity.

**Critical findings (abbreviated — see PLATFORM_OVERSIGHT_HUB.md Section 26 for full details):**
- ~~**BUG-C1: Activation cascade**~~ — ✅ **FIXED in v5.0.3 (PR #83)**. Removed 1,663 lines of historical migration blocks from `on_plugin_update()`. Function reduced from ~1,721 to ~100 lines. All sync HTTP blocks eliminated.
- ~~**BUG-C2: Display pipeline deadlock**~~ — ✅ **FIXED in v5.0.4 (PR #84)**. Removed `status='published'` gate from 18 display/SEO queries (5 in Display class, 13 in SEO class). All non-suppressed records now visible with original factual data. AI rewrite enhances display in background. Core workflow (SCRAPE → AI VIEW → AI REWRITE → PUBLISH) preserved per RULE 14.
- ~~**BUG-C3: Non-idempotent migrations**~~ — ✅ **FIXED in v5.0.3 (PR #83)**. Historical migration blocks removed entirely; remaining operations are naturally idempotent. `deployed_version` write moved to end of function for safe retry on partial failure.
- **BUG-C4: Dedup on every page load** — `ontario_obituaries_cleanup_duplicates()` runs a full-table GROUP BY on the `init` hook (every page load, frontend and admin).

**High-severity findings:**
- **BUG-H2: Incomplete uninstall** — Groq API key, Google Ads OAuth credentials, and 4+ cron hooks persist after uninstall.
- **BUG-H6: Domain lock bypass** — `strpos()` substring match allows spoofed domains.
- **BUG-H7: Stale cron hooks** — 4 orphaned hooks fire after uninstall, causing PHP fatals.
- **BUG-H1, H3, H4, H5** — Rate calculation, duplicate indexes, undefined variables, premature throttling.

**Medium-severity findings:**
- **BUG-M1: Shared Groq key** — 3 consumers compete for 6,000 TPM with no coordination.
- ~~**BUG-M3: 1,721-line function**~~ — ✅ **FIXED in v5.0.3 (PR #83)**. `on_plugin_update()` reduced to ~100 lines.
- **BUG-M4: Risky name-only dedup** — Could merge different people with same name.
- **BUG-M5: Activation races** — Multiple cron events can overlap.
- **BUG-M6: False throughput claims** — Comments say "200/hour" but reality is ~15/5-min.

**Systematic fix plan created**: 4 sprints, 22 tasks, 8 PRs mapped. See Section 27 of PLATFORM_OVERSIGHT_HUB.md.

### What v4.0.0 Changed (DEPLOYED 2026-02-13)
- **6 new Postmedia/Remembering.ca sources** added to seed_defaults()
- Version bump: 3.17.2 → 4.0.0 (header + constant)
- Migration block in on_plugin_update() re-seeds registry + schedules background scrape
- Total active sources: 1 → 7 (~175 obituaries per scrape cycle)
- **Zero adapter code changes** — all 7 sites use identical HTML structure

### What v4.0.1 Changed (DEPLOYED 2026-02-13)
- **BUG FIX**: Funeral home logos (Ogden, Ridley, etc.) were scraped as obituary portraits
- Added `is_likely_portrait()` method — HTTP HEAD checks Content-Length, rejects < 15 KB
- Migration cleans existing records: removes logo image_url from DB
- Version bump: 4.0.0 → 4.0.1

### Deployment Method
- **WP Pusher CANNOT auto-deploy** — repo is private, WP Pusher needs paid license
- **Current method**: Manual upload via cPanel File Manager
- MU-plugin deployed manually via cPanel File Manager to wp-content/mu-plugins/

### Source Registry Status (after v4.0.0 deploy)
- **Active (7):** obituaries.yorkregion.com, obituaries.thestar.com, obituaries.therecord.com, obituaries.thespec.com, obituaries.simcoe.com, obituaries.niagarafallsreview.ca, obituaries.stcatharinesstandard.ca
- **Disabled (22):** Legacy.com (403), Dignity Memorial (403), FrontRunner sites (JS-rendered), Arbor Memorial (JS shell)

### Dedup Audit Results (2026-02-13)
- Cross-source overlap: 25 obituaries appear on both niagarafallsreview.ca AND stcatharinesstandard.ca
- Dedup catches them via normalize_name_for_dedup() + same date_of_death → enriches, doesn't duplicate
- 3-pass dedup cleanup runs after every scrape: exact match → fuzzy match → name-only match
- Unique key (name(100), date_of_death, funeral_home(100)) provides DB-level backup
- **Verdict: NO doubles or triples will be created**

---

## ACTIVE ROADMAP: AI Memorial System (v4.x)

### Phase 1: Expand Sources (v4.0.0)
> Add 6 new Postmedia/Remembering.ca sources → ~175 obituaries per scrape

| ID | Task | Status |
|----|------|--------|
| P1-1 | Add 6 new Postmedia sources to seed_defaults | **DONE** |
| P1-2 | Test each source card extraction (all 7 return 25 cards) | **DONE** |
| P1-3 | Rate limiting verified (2s/req, 5 pages max — pre-existing) | **DONE** |
| P1-4 | Version bump to v4.0.0, migration block added | **DONE** |
| P1-5 | Dedup audit: 6 checks passed, no doubles/triples possible | **DONE** |
| P1-6 | PHP syntax check (29/29 pass), final code review | **DONE** |
| P1-7 | Present PR for owner approval | **DONE** (PR #51 merged) |
| P1-8 | Deploy to live site (manual via cPanel — WP Pusher can't do private repos) | **DONE** |
| P1-9 | Fix funeral home logo images appearing as portraits (v4.0.1) | **DONE** |

### Phase 2: AI Rewrite Engine (v4.1.0)
> Free LLM rewrites every obituary into unique professional prose

| ID | Task | Status |
|----|------|--------|
| P2-1 | Select free LLM API (Groq/Llama 3.3 70B) | **DONE** |
| P2-2 | Build class-ai-rewriter.php module | **DONE** |
| P2-3 | Create fact-preserving prompt template | **DONE** |
| P2-4 | Add validation layer (reject hallucinated facts) | **DONE** |
| P2-5 | Integrate into scraper pipeline (cron after collection) | **DONE** |
| P2-6 | Store original + AI description in DB (ai_description column) | **DONE** |
| P2-7 | Update templates to display ai_description (listing, detail, SEO) | **DONE** |
| P2-8 | REST endpoint for status/manual trigger | **DONE** |
| P2-9 | Present PR for owner approval | **DONE** (PR #52) |

### Phase 3: Memorial Pages (v4.2.0)
> Auto-generated SEO memorial pages per obituary

| ID | Task | Status |
|----|------|--------|
| P3-1 | Memorial pages (enhanced existing SEO individual pages) | **DONE** (no CPT needed) |
| P3-2 | Memorial page enhancements (QR, lead capture) | **DONE** |
| P3-3 | AI Tribute Writer (uses AI Rewriter from P2) | **DONE** |
| P3-4 | Auto-create memorial per obituary (existing scrape pipeline) | **DONE** (no change needed) |
| P3-5 | JSON-LD schema (Person + BurialEvent) | **DONE** |
| P3-6 | IndexNow integration (class-indexnow.php) | **DONE** |
| P3-7 | QR code generator per memorial page (Google Charts API) | **DONE** |
| P3-8 | Soft CTA lead capture (AJAX form, wp_options storage) | **DONE** |

### Phase 4: Security & Hardening (v4.3.0)
| ID | Task | Status |
|----|------|--------|
| P4-1 | Block direct PHP file access in plugin dir | **DONE** (pre-existing .htaccess) |
| P4-2 | Verify GitHub repo is private | **DONE** (confirmed via WP Pusher failure) |
| P4-3 | Domain lock (plugin only runs on monacomonuments.ca) | **DONE** |

### Phase 5: Testing & Deployment
| ID | Task | Status |
|----|------|--------|
| P5-1 | Full end-to-end test (PHP syntax all pass) | **DONE** |
| P5-2 | Build plugin ZIP (v4.2.1) | **DONE** (PR #52, 182 KB) |
| P5-3 | QA audit — found & fixed 2 bugs + 1 improvement (v4.2.1) | **DONE** |
| P5-4 | Create PR with full documentation | **DONE** (PR #52 — MERGED) |
| P5-5 | City data quality repair (v4.2.2) | **DONE** (PR #53 — MERGED) |
| P5-6 | Build plugin ZIP (v4.2.2) | **DONE** (186 KB) |
| P5-7 | Deploy v4.2.2 to live site (manual via cPanel) | **DONE** (2026-02-13) |
| P5-8 | Verify live site post-deploy | **DONE** (725 obits, 528 URLs, 16 slugs fixed) |
| P5-9 | Admin UI for AI Rewriter (v4.2.3) | **DONE** (PR #54 — MERGED) |
| P5-10 | Set Groq API key to enable AI rewrites | **DONE** (2026-02-13) |
| P5-11 | Deploy v4.2.3 to live site | **DONE** (2026-02-13) |
| P5-12 | Death date cross-validation fix (v4.2.4) | **DONE** (PR #55 — OPEN) |
| P5-13 | AI Rewriter trigger fix (v4.2.4) | **DONE** (PR #55 — OPEN) |
| P5-14 | Security audit (SQL/AJAX/XSS) | **DONE** — all passed |
| P5-15 | Deploy v4.2.4 to live site | **DONE** (2026-02-13) |
| P5-16 | Deploy v4.3.0 (GoFundMe + Authenticity) | **DONE** (2026-02-13, PR #56) |
| P5-17 | Deploy v4.5.0 (Chatbot + Google Ads + SEO Schema) | **DONE** (2026-02-13, PR #58) |
| P5-18 | Enable AI Chatbot on live site | **DONE** (2026-02-13 — verified working) |
| P5-19 | Full site backup before v4.5.0 deploy | **DONE** (UpdraftPlus, Feb 13, 21:45) |
| P5-20 | Google Ads Optimizer — enable when ready | **PENDING — owner action (spring)** |

### Phase 6: AI Rewriter Rate-Limit Resolution (v5.0.x–v5.1.x — ✅ RESOLVED)
> Fixed: Autonomous 5-minute repeating schedule deployed in v5.1.5.

| ID | Task | Status |
|----|------|--------|
| P6-1 | Switch to structured JSON extraction (v5.0.0) | **DONE** (PR #72-#73) |
| P6-2 | Switch primary model to llama-3.1-8b-instant | **DONE** (PR #77) |
| P6-3 | Implement 1-at-a-time processing + mutual exclusion | **DONE** (PR #79) |
| P6-4 | Fix TRUNCATE bug that wiped data on reinstall | **DONE** (PR #79) |
| P6-5 | Increase delay to 12s + retry-after header parsing | **DONE** (PR #80) |
| P6-6 | Remove wasteful fallback retries on 429 | **DONE** (PR #80) |
| P6-7 | Repeating 5-min schedule (replace one-shot) | **DONE** (PR #92, v5.1.4) |
| P6-8 | Handle delete-upgrade settings wipe | **DONE** (PR #93, v5.1.5) |
| P6-9 | Fix cPanel cron ($argv fatal) | **DONE** (2026-02-18, manual cPanel fix) |
| P6-10 | Demote validators to warnings (prevent deadlock) | **DONE** (PRs #88-91, v5.1.2) |
| P6-11 | Complete all pending obituary rewrites | **IN PROGRESS** — 178/581 done, ~6-8h remaining |

### Phase 7: Critical Bug Fixes (identified 2026-02-16 audit)
> Fix all bugs found during independent code audit. See PLATFORM_OVERSIGHT_HUB.md Sections 26-27.

| ID | Task | Status |
|----|------|--------|
| P7-1 | Fix activation cascade (BUG-C1) — removed all historical migrations | ✅ **DONE (PR #83)** |
| P7-2 | Fix display deadlock (BUG-C2) — removed published gate from 18 queries | ✅ **DONE (PR #84)** |
| P7-3 | Fix non-idempotent migrations (BUG-C3) — blocks removed, remaining ops idempotent | ✅ **DONE (PR #83)** |
| P7-4 | Fix dedup on init (BUG-C4) — move to post-scrape hook | ✅ **DONE (PR #85)** |
| P7-5 | Complete uninstall cleanup (BUG-H2, H7) — all options + all cron hooks | ✅ **DONE (PR #85)** |
| P7-6 | Fix domain lock bypass (BUG-H6) — exact hostname match | ✅ **DONE (PR #86)** |
| P7-7 | Fix minor high-severity bugs (BUG-H1, H3, H4, H5) | ✅ **DONE (PR #83, #86)** |
| P7-8 | Implement shared Groq rate limiter (BUG-M1) | ✅ **DONE (PR #87, v5.0.10 — QC-R10 rewrite: atomic reserve, CAS fail-closed, input hardening, tiered cache, pool normalization, multisite try/finally)** |
| P7-9 | Add date guard to name-only dedup (BUG-M4) | ✅ **DONE (PR #86)** |
| P7-10 | Stagger cron scheduling (BUG-M5) | ✅ **DONE (PR #87, v5.0.10 — QC-R10: jitter path audit + cooldown-after-collect)** |
| P7-11 | Update all throughput comments (BUG-M6) | ✅ **DONE (PR #87, v5.0.10)** |
| P7-12 | Refactor migrations into versioned files (BUG-M3) — superseded by removal | ✅ **DONE (PR #83)** |

### Phase 8: Image Hotlink Fix (URGENT — discovered 2026-02-18)
> All published obituary images are hotlinked from `cdn-otf-cas.prfct.cc`. Must be downloaded locally.

| ID | Task | Status |
|----|------|--------|
| P8-1 | Audit image_url values in published records | **DONE** (2026-02-18 — all external CDN URLs) |
| P8-2 | Modify image pipeline to always download (remove allowlist gate) | **PENDING** |
| P8-3 | Backfill: download existing image_url values to wp-content/uploads/ | **PENDING** |
| P8-4 | Update templates to only output local URLs or placeholder SVG | **PENDING** |
| P8-5 | Verify logo filter still rejects < 15 KB images | **PENDING** |
| P8-6 | Test with published obituaries on live site | **PENDING** |

### Phase 9: Error Handling Overhaul (v5.3.x — in progress, 25% complete)
> Systematic error handling across all 38 PHP files. See ERROR_HANDLING_PROPOSAL.md.

| ID | Task | Status |
|----|------|--------|
| P9-1 | Phase 1: Error handling foundation (`oo_log`, `oo_safe_call`, `oo_db_check`) | ✅ **DONE (PR #96, v5.3.0)** |
| P9-2 | Phase 2a: Cron handler hardening (all 8 cron handlers) | ✅ **DONE (PR #97, v5.3.1)** |
| P9-3 | Phase 2a QC fixes (bootstrap crash, settings gate, reschedule check) | ✅ **DONE (PR #97)** |
| P9-4 | Hotfix: name validation queue-blocking bug | ✅ **DONE (PR #99, v5.3.1)** |
| P9-5 | Phase 2b: HTTP wrappers (15 call sites) | ✅ **DONE (PR #100, v5.3.2)** — All `wp_remote_*` → `oo_safe_http_*`. QC gates: raw HTTP = 0, no dup logging, status preserved, no secrets. |
| P9-6 | Phase 2c: Top 10 DB hotspots | **PENDING** |
| P9-7 | Phase 2d: AJAX nonce/capability checks (29 handlers) | **PENDING** |
| P9-8 | Phase 3: Health dashboard (admin tab, admin bar badge, REST) | **PENDING** |
| P9-9 | Phase 4: Advanced (DB error table, email alerts, full coverage) | **PENDING** |

### QC-R7: Rate Limiter Hardening (v5.0.7 — addresses PR #87 review round 1)
> Initial hardening pass. All 10 reviewer concerns addressed.

| ID | Concern | Fix |
|----|---------|-----|
| QC-R7-1 | Sliding-window is actually fixed-window, non-atomic | Replaced with **atomic CAS** via `$wpdb->query("UPDATE...WHERE")` — InnoDB row-lock guarantees. 3-retry CAS loop; worst case = 1 brief overshoot (Groq 429 backstop). |
| QC-R7-2 | Public endpoints (chatbot) can exhaust cron quota (DoS) | **Split budget pools**: 80% cron (4,400 TPM), 20% chatbot (1,100 TPM). Visitor traffic isolated. |
| QC-R7-3 | Fallback must not expose prompts/keys | Audited `rule_based_response()` — returns only public business info. Defensive docblock added. |
| QC-R7-4 | Hard-coded 5,500 TPM | Now stored in `wp_options` (`ontario_obituaries_groq_tpm_budget`), overridable via `ontario_obituaries_groq_tpm_budget` filter. |
| QC-R7-5 | Cron stagger by seconds can overlap if prior job runs long | Added **random jitter**: rewriter ±30s (150-210s interval), GoFundMe +0-60s, authenticity +0-120s. `wp_next_scheduled()` guards prevent double-scheduling. |
| QC-R7-6 | 90s batch windows raise CPU on shared hosts | Reduced `max_runtime` from 90s → **60s** (~33% duty cycle). Self-reschedule every 3 min ± 30s. |
| QC-R7-7 | Visitor-triggered WP-Cron reliance | No change needed — cPanel cron (`cron-rewriter.php`) is the primary mechanism. WP-Cron is only backup. |
| QC-R7-8 | Uninstall must clean new options | Added `ontario_obituaries_groq_rate_window`, `ontario_obituaries_groq_tpm_budget` to option cleanup, `ontario_obituaries_collection_cooldown` to transient cleanup. |
| QC-R7-9 | Purge & rescrape can spike outbound requests | Added **collection cooldown transient** (10 min) — prevents back-to-back scrapes from duplicate WP-Cron fires or rapid admin clicks. |
| QC-R7-10 | Need code diff for limiter/scheduling/integration | All code included in PR. 9 files changed.

### QC-R8: Second-Pass Hardening (v5.0.8 — addresses PR #87 review round 2)
> Follow-up to QC-R7. Addresses serialization precision, multisite, and cooldown timing.

| ID | Concern | Fix |
|----|---------|-----|
| QC-R8-1 | CAS WHERE clause may fail due to JSON re-encoding precision | **Full rewrite of `record_usage()`**: raw string from `get_option()` used directly in WHERE clause (never re-encoded). `fresh_window()` helper uses integer-only values (no floats stored). `add_option(..., '', 'no')` for creation — autoload=no. `wp_cache_delete()` on every `$wpdb->update()`. |
| QC-R8-2 | Pool isolation must be verified: all consumers route through `get_pool()` | Confirmed: `get_pool()` is the SOLE mapping function. Docblock added stating this. All `may_proceed()`/`record_usage()` callers pass consumer name; pool determined internally. |
| QC-R8-3 | Configurable TPM option should avoid autoload bloat | `BUDGET_OPTION_KEY` stored with `autoload='no'`. Filter `ontario_obituaries_groq_tpm_budget` runs once at singleton construction. Added 500 TPM floor to prevent abuse. |
| QC-R8-4 | Use `update_option()` / proper cache invalidation | All reads via `get_option()`, creates via `add_option(..., 'no')`, atomic writes via `$wpdb->update()` + `wp_cache_delete()`. No raw INSERT/UPDATE without cache sync. |
| QC-R8-5 | Jitter max_runtime vs interval: need gap analysis | Gap proven: max_runtime(60s) + min_interval(150s=180-30) = 210s minimum cycle. Added `max(120, ...)` floor on rewrite interval. GoFundMe: 300-360s. Authenticity: 300-420s. |
| QC-R8-6 | Collection cooldown should not block retries on failure | Cooldown `set_transient()` moved AFTER collector construction + start. Domain check failure, missing class, or DB error → no cooldown set → next cron tick retries immediately. |
| QC-R8-7 | Uninstall: `delete_option()` only clears single-site | **Multisite support**: `is_multisite()` → `get_sites()` → `switch_to_blog()` → per-site cleanup → `restore_current_blog()`. Single-site unchanged. Also added `ontario_obituaries_chatbot_conversations` to option cleanup. |
| QC-R8-8 | Core pipeline must remain unchanged | Verified: SCRAPE→AI REVIEW→REWRITE→PUBLISH pipeline intact. No status values changed, no query modifications, no workflow reordering. |

### QC-R9: Third-Pass Hardening (v5.0.9 — addresses PR #87 review round 3)
> Full code-level review. Addresses CAS robustness, cache triad, consumer audit, filter lifecycle, cooldown timing, multisite timeout, and publish gating.

| ID | Concern | Fix |
|----|---------|-----|
| QC-R9-1 | Direct `$wpdb->update()` CAS clashes with WP object cache; `notoptions`/`alloptions` not cleared | **Cache triad invalidation**: new `invalidate_option_cache()` clears `OPTION_KEY` + `alloptions` + `notoptions` after every `$wpdb->query()` UPDATE and on `add_option()` creation. Covers per-option cache, autoload bundle, and negative-lookup cache. |
| QC-R9-1b | Raw DB string comparison fragile if other code normalises whitespace/serialization | **Version counter**: each window JSON now includes a monotonic `v` field, incremented on every write. Even if external code re-serialises the JSON (key reordering, whitespace normalisation), the version counter ensures the CAS WHERE clause fails correctly, triggering a clean retry. |
| QC-R9-2 | Verify only intended "sole routing function" calls the limiter | **Exhaustive audit**: all 6 call sites documented in class docblock. 3× `may_proceed()` + 3× `record_usage()`, each passing a consumer string routed through `get_pool()`. No stray calls found. Consumer→pool mapping: `rewriter`→cron, `chatbot`→chatbot, `authenticity`→cron. |
| QC-R9-3 | Filter running only at singleton construction prevents runtime budget changes | **Added `refresh()` static method**: destroys and recreates the singleton, forcing a full re-read of `wp_options` and re-application of the filter. Callers (admin AJAX, WP-CLI) can call `Ontario_Obituaries_Groq_Rate_Limiter::refresh()` to apply runtime changes without a process restart. |
| QC-R9-4 | Mixing `get_option()` reads with direct DB writes risks cache incoherence | **Resolved by QC-R9-1**: every `$wpdb->query()` UPDATE is immediately followed by `invalidate_option_cache()`. Subsequent `get_option()` reads in the same request hit the DB, not stale cache. On retries (attempt > 0), cache is busted before the `get_option()` read. |
| QC-R9-5 | Jitter/gap logic: alternate reschedule pathways could cause more-frequent events | **Jitter path audit**: documented all 4 code paths that schedule `ontario_obituaries_ai_rewrite_batch`. Only the self-reschedule block in `ontario_obituaries_ai_rewrite_batch()` uses jitter (150-210s). The +60s post-collection schedule is a one-shot with `wp_next_scheduled()` guard. Shutdown and AJAX handlers do NOT self-reschedule. CLI cron-rewriter.php uses its own loop with `usleep()`, not WP-Cron. |
| QC-R9-6 | Cooldown set before scrape actually begins suppresses retries on collect() failure | **Moved `set_transient()` to AFTER `$collector->collect()` returns**. If `collect()` throws an exception or triggers a PHP fatal, execution never reaches the `set_transient()` line → no cooldown → next cron tick retries immediately. Domain check failure and constructor failure already prevented cooldown in v5.0.8. |
| QC-R9-7 | Multisite uninstall loop can timeout; must handle partial completion and network-level keys | **Timeout guard**: tracks elapsed time against 50% of `max_execution_time` (min 30s, max 120s). **Batch pagination**: fetches sites in batches of 100 to avoid memory exhaustion. **Network-level cleanup**: new `ontario_obituaries_uninstall_network()` calls `delete_site_option()`/`delete_site_transient()` for wp_sitemeta keys. **Partial completion safe**: all operations are idempotent; re-running uninstall continues where it left off. **Logging**: logs cleaned/skipped site counts and IDs on timeout. |
| QC-R9-8 | Limiter/fallback must not alter publish gating or treat pending as published | **Verified and documented**: rate limiter ONLY returns `WP_Error('rate_limited', ...)` (rewriter/authenticity) or triggers rule-based fallback (chatbot). It NEVER writes to the obituaries table, NEVER changes the `status` column, and NEVER treats `pending` as `published`. The `pending→published` transition is exclusively in `class-ai-rewriter.php:process_batch()` after successful API call + validation. Explicit docblocks added to `may_proceed()` and `record_usage()`. |

### QC-R10: Fourth-Pass Hardening (v5.0.10 — addresses PR #87 review round 4)
> Addresses race conditions, CAS fail-open, input hardening, cache churn, WP_Error compatibility, pool routing, and multisite context safety.

| ID | Concern | Fix |
|----|---------|-----|
| QC-R10-1 | `may_proceed()` is read-only; concurrent callers oversubscribe before `record_usage()` runs | **Atomic reserve pattern**: `may_proceed()` now performs CAS write that both checks availability AND reserves tokens in one operation. On CAS failure, caller is BLOCKED (fail-closed). `record_usage()` adjusts the reservation to actual usage (delta). If CAS fails during adjustment, the reservation stands (overcount = safe direction). No silent budget leak. |
| QC-R10-1b | `record_usage()` CAS failure logs warning but proceeds — allows silent overspend | **Fail-safe**: since `may_proceed()` now reserves tokens, `record_usage()` only adjusts. On CAS failure, the original reservation stands (overcount, never undercount). The "CAS failed → proceed" path is eliminated. |
| QC-R10-2 | CAS compares full raw JSON string; formatting/key-order changes cause lost accounting | **Version counter differentiator**: the monotonic `v` field in the window JSON ensures that any concurrent write changes the raw string. CAS uses exact raw-string match, but the version counter guarantees distinct strings per write. Key reordering/whitespace changes by external processes cause CAS failure → clean retry. |
| QC-R10-3 | No input hardening: negative/zero token values could manipulate budget | **Input clamping**: `may_proceed()` rejects `$estimated_tokens <= 0` with early `return false` and warning log. `record_usage()` clamps `$actual_tokens` to `max(0, ...)` and skips no-ops. Prevents budget inflation from invalid input. |

### QC-R12: Sixth-Pass Hardening (v5.0.12 — addresses PR #87 review round 6)
> Addresses QC's requirement for true atomic CAS, verifiable release paths, enhanced unknown-consumer logging, cache churn reduction, multisite set_time_limit replacement, and a checksummed deployable ZIP.

| ID | QC Concern | Fix |
|----|-----------|-----|
| QC-R12-1 (Bug 1) | **Verify double-count fix** | Verified: rewriter=1100, chatbot=500, authenticity=800. All 6 record_usage calls pass estimate. All 6 release_reservation calls match. |
| QC-R12-2 (Bug 2) | **Release on every non-success path, clamp >=0** | All error paths release. NEW: missing usage.total_tokens defaults to estimate (reservation stands). max(0) clamp on pool values. |
| QC-R12-3 (Bug 3) | **True atomic CAS** | SELECT FOR UPDATE replaces LIKE pattern. InnoDB row lock. Version compared in PHP. Concurrent callers block, not fail. |
| QC-R12-4 (Bug 4) | **Unknown consumer logging** | Error logs include raw string, caller file:line, valid consumer list. Logged at error level. |
| QC-R12-5 (Bug 5) | **Cache churn reduction** | FOR UPDATE eliminates most CAS failures. Happy path = 1 cache delete. Full triad only on add_option/retry. |
| QC-R12-6 (Bug 6) | **Multisite set_time_limit** | Replaced with elapsed-time measurement. No E_WARNING on restricted hosts. |
| QC-R12-7 (Bug 7) | **Checksummed ZIP** | ontario-obituaries-v5.0.12.zip (277 KB). SHA-256 in PR body. |

### QC-R11: Fifth-Pass Hardening (v5.0.11 — addresses PR #87 review round 5)
> Fixes critical double-counting bug, budget bypass on failed API calls, brittle CAS comparison, fail-open pool routing, and multisite safety gaps. Produces versioned ZIP release asset.

| ID | Concern | Fix |
|----|---------|-----|
| QC-R11-1 | **Double-counting tokens**: `may_proceed()` reserves `estimated_tokens` but all three consumers call `record_usage(actual, consumer)` WITHOUT the estimate, inflating usage via the legacy `$estimated=0` pure-increment path. Budget bypass: reservation remains after failed API call, permanently reducing pool capacity until window expiry. | **Three-part fix**: (a) All 3 consumers now pass `$estimated` as 3rd arg to `record_usage()` — delta = actual - estimated, NOT pure add. (b) New `release_reservation($estimated, $consumer)` method credits unused reservation back on API failure. All error paths in rewriter (3 paths), chatbot (2 paths), and authenticity (1 path) call `release_reservation()`. (c) Legacy `$estimated=0` path logs deprecation warning instead of silently double-counting. |
| QC-R11-2 | **Brittle CAS**: WHERE clause compares full raw JSON string — any key reordering, whitespace normalization, or serialization round-trip breaks the match, causing repeated CAS misses and fail-closed blocking. | **Version-prefix LIKE match**: new `cas_update()` private method uses `WHERE option_name = %s AND option_value LIKE '{"v":N,%'` matching ONLY the version-counter prefix. Key reordering and whitespace changes no longer affect CAS. The `v` field is always the first JSON key (enforced by `fresh_window()` insertion order + `wp_json_encode` preserving order). |
| QC-R11-3 | `add_option()` invalidates `notoptions` + `alloptions` but NOT the per-option cache key (`OPTION_KEY`), leaving stale reads in some object-cache setups. | **Full cache bust**: `add_option()` path now calls `invalidate_full_cache()` which includes per-option key + alloptions + notoptions. This covers Redis/Memcached setups that cache individual option keys separately. |
| QC-R11-4 | **Pool routing fail-open**: unknown consumer strings log a warning but silently route to cron pool, masking misconfiguration. | **Fail-closed**: `get_pool()` returns `false` for unknown consumers (was `'cron'`). `may_proceed()` returns `false` and logs error. `record_usage()` returns early. `normalize_consumer()` no longer defaults unknowns to `'unknown'` — just lowercases/trims. Typos are immediately visible as blocked requests in logs. |
| QC-R11-5 | **Consumer integration mismatch**: `may_proceed()` reserves tokens, but consumers treat `record_usage()` as a simple addition (no `$estimated` parameter), causing the double-counting bug. | **All 6 `record_usage()` call sites updated**: rewriter passes `1100`, chatbot passes `500`, authenticity passes `800` as 3rd argument. Plus 6 `release_reservation()` calls on error paths. Consumer ↔ limiter contract is now: `may_proceed(N, c)` → API call → `record_usage(actual, c, N)` or `release_reservation(N, c)`. |
| QC-R11-6 | **Multisite uninstall**: `switch_to_blog()` return value ignored — failure can unbalance `restore_current_blog()` stack. Skip-list unlimited (10k+ IDs possible). No per-site timeout — single slow `ontario_obituaries_uninstall_site()` can overrun budget. | **Three fixes**: (a) `switch_to_blog()` return checked — if `false`, site is skipped and logged. (b) Skip-list capped at 500 entries (`$max_skip_ids`). (c) `set_time_limit()` called per-site with `$per_site_limit` (15% of budget, max 15s) to prevent single-site overrun. |
| QC-R11-7 | No versioned ZIP/release asset — cannot safely deploy PR #87 to live WordPress. | **Deployable ZIP**: `ontario-obituaries-v5.0.11.zip` built from `wp-plugin/` directory with all QC-R11 fixes. Suitable for WordPress Admin → Plugins → Upload. |
| QC-R10-4 | `invalidate_option_cache()` deletes alloptions on every successful write — heavy cache churn | **Tiered invalidation**: new `invalidate_option_only()` (per-option key only) for happy-path writes; `invalidate_full_cache()` (triad: option + alloptions + notoptions) only on retry paths and `add_option()`. Reduces cache churn from 3 deletes/write to 1 delete/write on the common path. |
| QC-R10-4b | `add_option()` only deletes `notoptions` — stale "missing option" reads persist | **Fixed**: `add_option()` path now busts both `notoptions` AND `alloptions` to ensure the new option is visible through all WP cache paths. |
| QC-R10-5 | Pool routing uses exact string match; typos silently route to wrong pool | **Normalized routing**: new `normalize_consumer()` lowercases and trims input. `CONSUMER_POOL_MAP` constant defines explicit allowlist (`rewriter→cron`, `chatbot→chatbot`, `authenticity→cron`). Unknown consumers route to `cron` (safe default) with logged warning. |
| QC-R10-6 | WP_Error instantiated with scalar data — breaks REST/JSON serialization | **Array data**: rewriter's `WP_Error('rate_limited', ...)` now uses `array('seconds_until_reset' => ...)` instead of scalar. Authenticity checker likewise uses array data with reset timer. Compatible with `wp_send_json_error()` and REST framework. |
| QC-R10-7 | Multisite uninstall: no try/finally around switch_to_blog()/restore_current_blog() | **Context safety**: `switch_to_blog()` + `try { uninstall_site() } catch(\Throwable) { log } finally { restore_current_blog() }`. Exception in per-site cleanup cannot leave WP in wrong blog context. |
| QC-R10-8 | Timeout handling: loop continues iterating when budget exceeded | **Immediate break**: timeout guard now uses `break` instead of `continue`. When time budget is exceeded, the inner foreach breaks immediately, sets `$timed_out` flag, and the outer do-while breaks too. No wasted iteration. |
| QC-R10-9 | `get_sites(['number'=>0])` behavior varies across WP versions | **Safe pagination**: explicit `'number' => $batch_size` (100) in all `get_sites()` calls. Never uses `'number' => 0`. Post-timeout remaining-sites fetch uses `'number' => 10000` upper bound. |

---

## PENDING WORK (consolidated — updated 2026-02-20)

### URGENT (discovered 2026-02-18)
0. **🔴 IMAGE HOTLINK** — All published obituary images hotlinked from `cdn-otf-cas.prfct.cc`. Not stored locally. See Oversight Hub Section 28.

### ERROR HANDLING PROJECT (40% complete — in progress)
1. ~~**Phase 1: Foundation**~~ — ✅ DONE (PR #96, v5.3.0) — `oo_log`, `oo_safe_call`, `oo_db_check`, health counters
2. ~~**Phase 2a: Cron Hardening**~~ — ✅ DONE (PR #97, v5.3.1) — All 8 cron handlers wrapped
3. ~~**Hotfix: Name validation**~~ — ✅ DONE (PR #99, v5.3.1) — Strip nicknames, demote to warning
4. ~~**Phase 2b: HTTP wrappers**~~ — ✅ DONE (PR #100, v5.3.2) — 15 call sites converted to `oo_safe_http_*`
5. **Phase 2c: Top 10 DB hotspots** — NEXT
6. **Phase 2d: AJAX nonce/capability** — PENDING (29 handlers)
7. **Phase 3: Health dashboard** — PENDING (admin tab, admin bar badge, REST)
8. **Phase 4: Advanced** — PENDING (DB error table, email alerts, full coverage)

### PREVIOUS BUGS (all fixed)
9. ~~**BUG-C1-C4**~~ — ✅ FIXED (PRs #83-#85)
10. ~~**BUG-H1-H7**~~ — ✅ FIXED (PRs #83-#86)
11. ~~**BUG-M1-M6**~~ — ✅ FIXED (PRs #83-#87)
12. ~~**AI Rewriter queue blocked**~~ — ✅ FIXED (v5.1.2-v5.1.5 + v5.3.1 hotfix)

### PREVIOUSLY KNOWN (carried forward)
13. **Data repair** — Clean existing fabricated `YYYY-01-01` rows in DB
14. **Schema/dedupe redesign** — Add `birth_year`/`death_year` columns, new unique key
15. **Max-age audit** — Prevent pagination drift into old archive pages
16. **Google Ads Optimizer** — Enable in spring (owner action)
17. **Out-of-province filtering** — Low priority

> See PLATFORM_OVERSIGHT_HUB.md Section 27 for the full bug fix plan (all complete).
> See ERROR_HANDLING_PROPOSAL.md for the v6.0 error handling plan.

---

## HOW TO DEPLOY (for server admin)

**WP Pusher cannot auto-deploy** (private repo, no license). Deploy manually:

**⚠️ WARNING: Delete→Upload wipes ALL settings via `uninstall.php`.**

### Pre-deploy backup (run via SSH / WP-CLI)
```bash
wp --path=$HOME/public_html option get ontario_obituaries_groq_api_key > ~/settings_backup.txt
wp --path=$HOME/public_html option get ontario_obituaries_settings --format=json >> ~/settings_backup.txt
echo "Backup saved"
```

### Deploy steps
1. Merge PR on GitHub.
2. Download the plugin ZIP from the sandbox (or export from GitHub).
3. WP Admin → Plugins → Deactivate "WP Plugin" → Delete → Add New → Upload ZIP → Activate.
4. **Restore settings** (if wiped by delete):
   ```bash
   wp --path=$HOME/public_html option update ontario_obituaries_groq_api_key "YOUR_KEY_HERE"
   wp --path=$HOME/public_html option update ontario_obituaries_settings '{"enabled":true,"auto_publish":true,...,"ai_rewrite_enabled":true,...}' --format=json
   wp --path=$HOME/public_html plugin deactivate wp-plugin && wp --path=$HOME/public_html plugin activate wp-plugin
   ```
5. **WP Admin → LiteSpeed Cache → Toolbox → Purge All**
6. Verify:
   ```bash
   wp --path=$HOME/public_html eval "echo ONTARIO_OBITUARIES_VERSION;"
   wp --path=$HOME/public_html cron event list 2>&1 | grep -i ontario
   wp --path=$HOME/public_html db query "SELECT SUM(CASE WHEN status='published' THEN 1 ELSE 0 END) AS published, SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending FROM wp_ontario_obituaries WHERE suppressed_at IS NULL;"
   ```

### cPanel Cron
The cron command MUST use PHP to invoke WP-CLI:
```
*/5 * * * * /usr/local/bin/php /usr/local/sbin/wp --path=/home/monaylnf/public_html cron event run --due-now >/dev/null 2>&1
```
**Do NOT use bare `wp`** — it causes `$argv` undefined fatal error in cron's restricted shell.

---

## RULES REMINDER

Before doing ANY work, read `PLATFORM_OVERSIGHT_HUB.md`. Key rules:
- **Rule 2:** Paste complete diff + explanation for approval BEFORE committing
- **Rule 3:** Version header must match constant; bump on behavior changes
- **Rule 8:** One concern per PR (don't mix scraper + SEO + data repair)
- **Rule 10:** AI developers follow the same rules — no auto-commit without approval
