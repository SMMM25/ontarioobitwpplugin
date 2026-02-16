# DEVELOPER LOG — Ontario Obituaries WordPress Plugin

> **Last updated:** 2026-02-16 (independent code audit completed — critical bugs found)
> **Plugin version:** `5.0.2` (live + main branch + sandbox — all in sync)
> **Live site version:** `5.0.2` (monacomonuments.ca — deployed 2026-02-15 via WP Upload)
> **Main branch HEAD:** PR #80 merged
> **Project status:** CRITICAL BUGS IDENTIFIED — 4 critical, 7 high, 6 medium-severity issues found. See PLATFORM_OVERSIGHT_HUB.md Sections 26-27.
> **Next deployment:** Bug fix PRs pending (Sprint 1 critical fixes required before plugin is usable)

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

---

## CURRENT STATE (as of 2026-02-15)

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
  - **Activation cascade** (BUG-C1): On activation, all migrations run synchronously, triggering 5+ scrapes + rewrites, exhausting Groq TPM
  - **Display deadlock** (BUG-C2): Records inserted as `pending` are invisible — display queries require `status='published'` which only happens after AI rewrite
  - **Non-idempotent migrations** (BUG-C3): Reinstall re-runs all 20+ migrations with blocking HTTP calls
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
> An independent line-by-line code review was performed on the entire plugin codebase.
> The previous developer's claim that "the plugin is stable" was found to be **incorrect**.
> **17 bugs identified**: 4 critical, 7 high-severity, 6 medium-severity.

**Critical findings (abbreviated — see PLATFORM_OVERSIGHT_HUB.md Section 26 for full details):**
- **BUG-C1: Activation cascade** — `on_plugin_update()` runs all 20+ migrations synchronously on activation, including scrapes with `usleep()`, HTTP HEAD requests, and immediate rewrite scheduling. This causes the "5-15 obituaries then crash" behavior the owner reported. The previous dev spent 10 PRs (#71-#80) tuning Groq delays when the root cause was the activation cascade.
- **BUG-C2: Display pipeline deadlock** — `class-ontario-obituaries-display.php` requires `status='published'` but records are inserted as `pending`. Only ~15 of 725 records are visible because only AI-rewritten records become published. 710+ obituaries in the DB are invisible to users.
- **BUG-C3: Non-idempotent migrations** — No fresh-install guard. Every reinstall re-runs all historical migrations with blocking HTTP calls.
- **BUG-C4: Dedup on every page load** — `ontario_obituaries_cleanup_duplicates()` runs a full-table GROUP BY on the `init` hook (every page load, frontend and admin).

**High-severity findings:**
- **BUG-H2: Incomplete uninstall** — Groq API key, Google Ads OAuth credentials, and 4+ cron hooks persist after uninstall.
- **BUG-H6: Domain lock bypass** — `strpos()` substring match allows spoofed domains.
- **BUG-H7: Stale cron hooks** — 4 orphaned hooks fire after uninstall, causing PHP fatals.
- **BUG-H1, H3, H4, H5** — Rate calculation, duplicate indexes, undefined variables, premature throttling.

**Medium-severity findings:**
- **BUG-M1: Shared Groq key** — 3 consumers compete for 6,000 TPM with no coordination.
- **BUG-M3: 1,721-line function** — `on_plugin_update()` is unmaintainable.
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

### Phase 6: AI Rewriter Rate-Limit Resolution (v5.0.x — PAUSED)
> Fix Groq free-tier rate limiting that stops AI rewrites after ~15 items per run

| ID | Task | Status |
|----|------|--------|
| P6-1 | Switch to structured JSON extraction (v5.0.0) | **DONE** (PR #72-#73) |
| P6-2 | Switch primary model to llama-3.1-8b-instant | **DONE** (PR #77) |
| P6-3 | Implement 1-at-a-time processing + mutual exclusion | **DONE** (PR #79) |
| P6-4 | Fix TRUNCATE bug that wiped data on reinstall | **DONE** (PR #79) |
| P6-5 | Increase delay to 12s + retry-after header parsing | **DONE** (PR #80) |
| P6-6 | Remove wasteful fallback retries on 429 | **DONE** (PR #80) |
| P6-7 | Resolve Groq 6,000 TPM limit | **BLOCKED** — requires paid Groq plan or alternative API |
| P6-8 | Complete all 725+ obituary rewrites | **BLOCKED** — depends on P6-7 |

### Phase 7: Critical Bug Fixes (identified 2026-02-16 audit)
> Fix all bugs found during independent code audit. See PLATFORM_OVERSIGHT_HUB.md Sections 26-27.

| ID | Task | Status |
|----|------|--------|
| P7-1 | Fix activation cascade (BUG-C1) — fresh-install guard + async scrapes | **TODO** |
| P7-2 | Fix display deadlock (BUG-C2) — remove published gate, show all records | **TODO** |
| P7-3 | Fix non-idempotent migrations (BUG-C3) — existence checks + bypass | **TODO** |
| P7-4 | Fix dedup on init (BUG-C4) — move to post-scrape hook | **TODO** |
| P7-5 | Complete uninstall cleanup (BUG-H2, H7) — all options + all cron hooks | **TODO** |
| P7-6 | Fix domain lock bypass (BUG-H6) — exact hostname match | **TODO** |
| P7-7 | Fix minor high-severity bugs (BUG-H1, H3, H4, H5) | **TODO** |
| P7-8 | Implement shared Groq rate limiter (BUG-M1) | **TODO** |
| P7-9 | Add date guard to name-only dedup (BUG-M4) | **TODO** |
| P7-10 | Stagger cron scheduling (BUG-M5) | **TODO** |
| P7-11 | Update all throughput comments (BUG-M6) | **TODO** |
| P7-12 | Refactor migrations into versioned files (BUG-M3) — long-term | **TODO** |

---

## PENDING WORK (consolidated — updated 2026-02-16 after code audit)

### CRITICAL (blocks core functionality)
1. **BUG-C1: Activation cascade** — Add fresh-install guard, move scrapes to async cron
2. **BUG-C2: Display deadlock** — Remove `status='published'` gate, show all non-suppressed records
3. **BUG-C3: Non-idempotent migrations** — Add existence checks, fresh-install bypass
4. **BUG-C4: Dedup on every page load** — Move to post-scrape hook or daily cron

### HIGH (security + functional)
5. **BUG-H2 + H7: Incomplete uninstall** — Clean up all options, transients, and cron hooks
6. **BUG-H6: Domain lock bypass** — Switch from `strpos()` to exact match
7. **BUG-H1: Nonsense rate calculation** — Guard division by zero in cron-rewriter.php
8. **BUG-H3: Duplicate index creation** — Add existence guard in v4.3.0 migration
9. **BUG-H4: Undefined $result** — Initialize variable before conditional block
10. **BUG-H5: Premature shutdown throttle** — Set throttle AFTER success, not before

### MEDIUM (architecture + safety)
11. **BUG-M1: Shared Groq rate limiter** — Coordinate API usage across 3 consumers
12. **BUG-M3: Monolithic migrations** — Refactor 1,721-line function into versioned files
13. **BUG-M4: Risky name-only dedup** — Add date guard to prevent merging different people
14. **BUG-M5: Activation race conditions** — Stagger cron scheduling, add transient locks
15. **BUG-M6: Unrealistic throughput comments** — Update all to reflect actual ~15/5-min

### PREVIOUSLY KNOWN (carried forward)
16. **BLOCKED: AI Rewriter Groq TPM limit** — Upgrade plan, switch API, or accept slow throughput
17. **Data repair** — Clean existing fabricated `YYYY-01-01` rows in DB
18. **Schema/dedupe redesign** — Add `birth_year`/`death_year` columns, new unique key
19. **Max-age audit** — Prevent pagination drift into old archive pages
20. **Google Ads Optimizer** — Enable in spring (owner action)
21. **Out-of-province filtering** — Low priority

> See PLATFORM_OVERSIGHT_HUB.md Section 27 for the full systematic fix plan with
> sprint organization, PR mapping, and estimated effort.

---

## HOW TO DEPLOY (for server admin)

**WP Pusher cannot auto-deploy** (private repo, no license). Deploy manually:

1. Merge PR on GitHub.
2. Download the plugin ZIP from the sandbox (or export from GitHub).
3. In cPanel File Manager, navigate to `public_html/wp-content/plugins/ontario-obituaries/`.
4. Upload the ZIP, extract (overwrite existing files), delete the ZIP.
5. Visit any site page to trigger the `init` migration hook.
6. **WP Admin -> LiteSpeed Cache -> Toolbox -> Purge All**
7. **WP Admin -> Settings -> Permalinks -> Save Changes** (click Save, don't change anything)
8. Hard-refresh browser: `Ctrl+Shift+R`
9. Verify the post-deploy checklist above

Assume no SSH/WP-CLI; all operations should be doable via WP Admin + cPanel.

---

## RULES REMINDER

Before doing ANY work, read `PLATFORM_OVERSIGHT_HUB.md`. Key rules:
- **Rule 2:** Paste complete diff + explanation for approval BEFORE committing
- **Rule 3:** Version header must match constant; bump on behavior changes
- **Rule 8:** One concern per PR (don't mix scraper + SEO + data repair)
- **Rule 10:** AI developers follow the same rules — no auto-commit without approval
