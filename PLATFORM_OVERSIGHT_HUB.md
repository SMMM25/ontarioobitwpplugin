# PLATFORM OVERSIGHT HUB

> **MANDATORY**: All developers (human and AI) MUST read this file before performing
> ANY work on this repository. Non-compliance will result in rejected PRs.

## Purpose

This document establishes mandatory guardrails, quality gates, and accountability
rules for the Ontario Obituaries WordPress plugin (`ontarioobitwpplugin`).

After 14+ commits introducing regressions (empty scraper results, corrupted dates,
double-slash URLs, test data in production, version mismatches), these rules exist
to prevent further breakage.

---

## Section 0 — FULL PROJECT STATE SNAPSHOT (for AI memory)

> **WHY THIS EXISTS**: AI developers have limited memory across sessions. This section
> is the single source of truth for the current project state. It MUST be updated
> after every deployment or significant change. Read this FIRST before doing any work.

### Project Identity
- **Plugin**: Ontario Obituaries (`ontario-obituaries.php`)
- **Business**: Monaco Monuments (`monacomonuments.ca`) — headstone/monument company
- **Goal**: Scrape Ontario obituaries, display them, generate memorial SEO pages
  to drive organic traffic → headstone/monument sales
- **Repo**: `github.com/SMMM25/ontarioobitwpplugin` (PRIVATE)
- **WordPress theme**: Litho + Elementor page builder
- **Cache**: LiteSpeed Cache (sole cache layer — W3 Total Cache MUST stay disabled)
- **Hosting**: Shared hosting with cPanel, no SSH access, no WP-CLI
- **Deployment**: Manual upload via cPanel File Manager (WP Pusher can't do private repos)

### Current Versions (as of 2026-02-13)
| Environment | Version | Notes |
|-------------|---------|-------|
| **Live site** | 4.2.3 | monacomonuments.ca — deployed via cPanel |
| **Main branch** | 4.2.3 | PR #54 merged |
| **Sandbox / PR** | 4.2.4 | PR #55 pending — death date fix + AI Rewriter activation fix |

### Live Site Stats (verified 2026-02-13 post-deploy)
- **725 obituaries** displayed across 37 pagination pages
- **7 active sources** (all Postmedia/Remembering.ca network):
  - obituaries.yorkregion.com, obituaries.thestar.com, obituaries.therecord.com,
    obituaries.thespec.com, obituaries.simcoe.com, obituaries.niagarafallsreview.ca,
    obituaries.stcatharinesstandard.ca
- **22 disabled sources** (Legacy.com 403, Dignity Memorial 403, FrontRunner JS-only, etc.)
- **Cron**: every 12h via `ontario_obituaries_collection_event`
- **528 URLs** in sitemap (`/obituaries-sitemap.xml`), **117 unique city slugs**
- **70 city cards** on the Ontario hub page
- **Pages**: `/ontario-obituaries/` (shortcode listing), `/obituaries/ontario/` (SEO hub),
  `/obituaries/ontario/{city}/` (city hubs), `/obituaries/ontario/{city}/{name}-{id}/` (memorial pages)
- **Memorial pages verified**: QR code (qrserver.com), lead capture form with AJAX handler,
  Schema.org markup (Person, BurialEvent, BreadcrumbList, LocalBusiness)

### Feature Deployment Status

| Feature | Version | Status |
|---------|---------|--------|
| Logo filter (rejects images < 15 KB) | v4.0.1 | ✅ LIVE |
| AI rewrite engine (Groq/Llama) | v4.1.0 | ✅ LIVE (code deployed, **needs Groq API key to activate**) |
| BurialEvent JSON-LD schema | v4.2.0 | ✅ LIVE |
| IndexNow search engine notification | v4.2.0 | ✅ LIVE |
| QR code on memorial pages | v4.2.0 | ✅ LIVE |
| Lead capture form | v4.2.0 | ✅ LIVE |
| Domain lock | v4.2.0 | ✅ LIVE |
| QR API fix (Google → QR Server) | v4.2.1 | ✅ LIVE |
| Lead form AJAX handler | v4.2.1 | ✅ LIVE |
| City data quality repair (round 1) | v4.2.2 | ✅ LIVE — 16 truncated slugs fixed |
| Sitemap ai_description fix | v4.2.2 | ✅ LIVE |
| Hardened normalize_city() | v4.2.2 | ✅ LIVE |
| Admin UI for AI Rewriter toggle | v4.2.3 | ✅ LIVE |
| Admin UI for Groq API key input | v4.2.3 | ✅ LIVE |
| AI Rewrite stats on settings page | v4.2.3 | ✅ LIVE |
| City slug fix round 2 (14 address patterns) | v4.2.3 | ✅ LIVE |
| Death date cross-validation fix | v4.2.4 | 🔶 PR #55 pending |
| AI Rewriter immediate batch on save | v4.2.4 | 🔶 PR #55 pending |
| Future death date rejection | v4.2.4 | 🔶 PR #55 pending |
| q2l0eq garbled slug cleanup | v4.2.4 | 🔶 PR #55 pending |

### AI Rewriter Status
- **Code**: Complete (`class-ai-rewriter.php`)
- **Admin UI**: v4.2.3 adds a settings page section (checkbox toggle + API key input + live stats)
- **Activation (current v4.2.2)**: Set Groq API key via `wp_options` → `ontario_obituaries_groq_api_key`
- **Activation (after v4.2.3)**: Go to WP Admin → Ontario Obituaries → Settings → AI Rewrite Engine section
- **Get key**: Free at https://console.groq.com (no credit card needed)
- **What it does**: Rewrites scraped obituary text into original prose using Llama 3.3 70B
- **Rate**: 25 per batch, 1 request per 6 seconds, auto-reschedules until caught up
- **Copyright protection**: Until this is active, site displays original scraped text
- **Groq API key set** (2026-02-13) — AI rewrites enabled via admin settings
- **⚠️ v4.2.4 FIX NEEDED**: AI batch only fired after cron collection, not after settings save — fixed in PR #55
- **⚠️ CRITICAL**: Until AI rewrites complete, all 725 obituaries display original copyrighted text

### Known Data Quality Issues
1. ~~**Truncated/garbled city names**~~ → ✅ Fixed by v4.2.2 + v4.2.3 migrations
2. ~~**14 address-pattern city slugs**~~ → ✅ Fixed by v4.2.3 migration
3. **Wrong death years on ~8 obituaries** → v4.2.4 migration pending (source metadata says 2026 but text says 2024/2025)
4. **1 future death date** (Michael McCarty: 2026-05-07) → v4.2.4 migration pending
5. **q2l0eq garbled slug** still in sitemap → v4.2.4 migration pending
6. **Fabricated YYYY-01-01 dates** from legacy scraper → needs separate data repair PR
7. **Out-of-province obituaries** (Calgary, Vancouver, etc.) → valid records, low priority
8. **Schema redesign needed** for records without death date → future work

### Key Files to Know
| File | What it does |
|------|-------------|
| `ontario-obituaries.php` | Main plugin file — activation, cron, dedup, migrations, version |
| `includes/class-ontario-obituaries-seo.php` | SEO pages, sitemap, schema, virtual page routing |
| `includes/class-ontario-obituaries-display.php` | Shortcode listing page rendering |
| `includes/class-ai-rewriter.php` | AI rewrite engine (Groq API) |
| `includes/class-indexnow.php` | IndexNow search engine notification |
| `includes/class-ontario-obituaries-ajax.php` | AJAX handlers (lead capture, removal, etc.) |
| `includes/sources/class-source-adapter-base.php` | Shared adapter logic (normalize_city, HTTP, dates) |
| `includes/sources/class-adapter-remembering-ca.php` | Main adapter for all 7 active sources |
| `includes/sources/class-source-collector.php` | Scrape pipeline orchestrator |
| `templates/seo/individual.php` | Memorial page template (QR, lead form, CTA) |
| `templates/obituaries.php` | Shortcode listing template |
| `PLATFORM_OVERSIGHT_HUB.md` | THIS FILE — rules + project state |
| `DEVELOPER_LOG.md` | Full version history + PR log + roadmap |

### Database
- **Table**: `{prefix}ontario_obituaries`
- **Key columns**: id, name, date_of_birth, date_of_death, age, funeral_home,
  location, image_url, description, **ai_description** (v4.1.0), source_url,
  source_domain, source_type, city_normalized, provenance_hash, suppressed_at, created_at
- **Unique key**: `(name(100), date_of_death, funeral_home(100))`
- **Known limitation**: Records without `date_of_death` cannot be ingested

### PR History (recent)
| PR | Status | Version | What |
|----|--------|---------|------|
| #51 | Merged | v4.0.0 | 6 new Postmedia sources (1→7) |
| #52 | Merged | v4.2.1 | AI Memorial System phases 1-4 + QA audit fixes |
| #53 | Merged | v4.2.2 | City data quality repair + sitemap fix |
| #54 | Merged | v4.2.3 | Admin UI for AI Rewriter + Groq key + additional city slug fixes |
| #55 | Open | v4.2.4 | Death date cross-validation fix + AI Rewriter activation fix |

### Remaining Work (priority order)
1. ~~**Deploy v4.2.2** to live site~~ → ✅ Done 2026-02-13
2. ~~**Merge PR #54 (v4.2.3)** and deploy~~ → ✅ Done 2026-02-13
3. ~~**Enable AI Rewriter** via admin settings page~~ → ✅ Done 2026-02-13 (Groq key set)
4. **Merge PR #55 (v4.2.4)** and deploy — fixes death dates + AI Rewriter batch trigger
5. **Verify AI rewrites are running** after v4.2.4 deploy (check settings page stats)
6. **Data repair**: Clean fabricated YYYY-01-01 dates (developer — future PR)
7. **Schema redesign**: Handle records without death date (developer — future PR)
8. **Out-of-province filtering** (developer — low priority)
9. **Automated deployment** via GitHub Actions or WP Pusher paid (developer — low priority)

---

## RULE 1: Read Before You Code

Before making ANY change, you MUST:

1. Read this file (`PLATFORM_OVERSIGHT_HUB.md`) in full.
2. Read the existing `README.md`.
3. Run a live-site check:
   ```bash
   curl -s 'https://monacomonuments.ca/ontario-obituaries/' | grep -c 'obituary-card'
   curl -s 'https://monacomonuments.ca/obituaries/ontario/' | grep -c 'ontario-obituary-card'
   curl -s 'https://monacomonuments.ca/obituaries-sitemap.xml' | head -20
   ```
4. Understand the current data state before proposing changes.

---

## RULE 2: Present Code for Approval Before Committing

**No code may be committed or pushed without explicit owner approval.**

The developer MUST:

1. **Paste the complete diff** (every changed line) in the chat/PR description.
2. **Provide an Explanation Script**: a plain-English summary of:
   - What each change does and WHY it is needed.
   - What regression it fixes or prevents.
   - What the expected live-site behavior will be after deployment.
3. **Wait for explicit "APPROVED" from the repo owner** before committing.

---

## RULE 3: Version Discipline

- The **plugin header comment** (`Version: X.Y.Z`) and the **PHP constant**
  (`ONTARIO_OBITUARIES_VERSION`) MUST always match.
- Every PR that changes plugin behavior MUST bump the version.
- Version format: `MAJOR.MINOR.PATCH` (semver).
- The `on_plugin_update()` function uses the version to trigger one-time
  migrations. A mismatch causes migrations to either skip or re-run.

---

## RULE 4: Never Delete Production Data Without a Re-Scrape Guard

If a migration deletes records (corrupt data repair, dedup, test cleanup):

1. It MUST log exactly how many records were removed and why.
2. It MUST schedule a re-scrape (`ontario_obituaries_initial_collection`)
   only if the scraper can be verified to work (test connection first).
3. It MUST be **idempotent** — running twice must not cause double-deletes
   or double-schedules.
4. It MUST be gated behind a version check so it runs exactly once.

---

## RULE 5: Scraper Changes Require Diagnostic Logging

Any change to the scraper pipeline (`class-source-collector.php`, any adapter,
or `class-ontario-obituaries-scraper.php`) MUST include:

1. **Zero-result logging**: If an adapter returns 0 cards from a page that
   was successfully fetched, log the URL, the response size, and the first
   500 characters of the HTML body so we can diagnose selector mismatches.
2. **Per-source summary**: After each source completes, log found/added/errors.
3. **Connection test before bulk scrape**: If a re-scrape is triggered by
   a data repair migration, test at least one source URL first.

---

## RULE 6: Pre-Merge Checklist

Before any PR is merged, verify ALL of the following:

| # | Check | How to Verify |
|---|-------|---------------|
| 1 | PHP syntax valid | `php -l ontario-obituaries.php` (and all changed files) |
| 2 | Brace balance | `grep -c '{' file` == `grep -c '}' file` for every PHP file |
| 3 | Version header matches constant | `grep 'Version:' ontario-obituaries.php` matches `grep ONTARIO_OBITUARIES_VERSION` |
| 4 | No `error_log()` calls (use `ontario_obituaries_log()`) | `grep -rn 'error_log(' includes/` returns 0 hits |
| 5 | No hardcoded test data | `grep -rn 'Test Smith\|Test Johnson\|Test Wilson\|Test Brown\|example\.com' includes/` returns 0 |
| 6 | Sitemap has no double-slashes | After deploy: `curl sitemap.xml \| grep 'ontario//'` returns 0 |
| 7 | Shortcode page shows obituaries | After deploy: card count > 0 |
| 8 | SEO hub shows city grid + recent | After deploy: city-card count > 0 |
| 9 | No external obituary links | `grep -c 'obituaries.yorkregion.com'` in page source = 0 |

---

## RULE 7: Deployment Verification

After every PR merge + WP Pusher deploy:

1. **Immediately** check the live site (both `/ontario-obituaries/` and
   `/obituaries/ontario/`).
2. Verify the plugin version in the admin dashboard matches the PR version.
3. Check the debug page (`admin.php?page=ontario-obituaries-debug`) for
   scraper errors.
4. If the site shows 0 obituaries, the deploy is **FAILED** — roll back
   or hotfix immediately.

---

## RULE 8: One Concern Per PR

Each pull request MUST address exactly one category of change:

- **Scraper fix**: Changes to adapters, collector, or scraper classes.
- **SEO fix**: Changes to rewrite rules, templates, schema, sitemaps.
- **Data repair**: One-time migration/cleanup logic.
- **Infrastructure**: Caching, versioning, asset loading.

Do NOT combine scraper fixes with SEO fixes with data repairs in a single PR.
The last 14 commits combined all three, making regressions impossible to bisect.

---

## RULE 9: Rollback Plan

Every PR description MUST include a rollback plan:

> **If this PR causes a regression**: [describe how to revert — e.g.,
> "revert commit abc1234" or "set option X back to Y in wp_options"]

---

## RULE 10: AI Developer Accountability

AI developers (GenSpark, Copilot, etc.) are held to the same standards:

1. Must read this file at the start of every session.
2. Must present full code diffs for approval.
3. Must not auto-commit or auto-push without owner sign-off.
4. Must run the pre-merge checklist before requesting approval.
5. Must provide a plain-English explanation script with every change set.

---

## RULE 12: Sandbox-First Development

All new feature development MUST be built and tested in the sandbox environment
before merging to the live site. The live site (monacomonuments.ca) is customer-facing
and cannot tolerate downtime or regressions.

1. **Build in sandbox** — All code changes happen in `/home/user/webapp/wp-plugin/`.
2. **Test locally** — PHP syntax check all files, validate logic.
3. **Present for approval** — Show the owner what the change does BEFORE committing.
4. **Owner merges** — Only the repo owner clicks "Merge" on the PR.
5. **Post-merge verify** — Check the live site after WP Pusher deploys.

---

## RULE 13: Mandatory Oversight Update on Every Commit

After EVERY commit/merge, the developer MUST:

1. **Update `DEVELOPER_LOG.md`** with:
   - PR number and commit hash
   - What changed (plain English)
   - Current roadmap task status update
2. **Update the roadmap status** in the ACTIVE ROADMAP section.
3. **Post an explainer in chat** summarizing what was done.
4. **Wait for owner approval** before pushing/creating PR.

This ensures continuity across sessions — AI developers have limited memory and
MUST rely on these documents to understand project state.

---

## Architecture Quick Reference

```
wp-plugin/
  ontario-obituaries.php          — Main plugin file, activation, cron, dedup, version
  includes/
    class-ontario-obituaries.php         — Core WP integration (shortcode, assets, REST)
    class-ontario-obituaries-display.php — Shortcode rendering + data queries
    class-ontario-obituaries-scraper.php — Legacy scraper (v2.x, fallback)
    class-ontario-obituaries-seo.php     — SEO hub pages, sitemap, schema, OG tags
    class-ontario-obituaries-admin.php   — Admin settings page
    class-ontario-obituaries-ajax.php    — AJAX handlers (quick view, removal)
    class-ontario-obituaries-debug.php   — Debug/diagnostics page
    sources/
      interface-source-adapter.php       — Adapter contract
      class-source-adapter-base.php      — Shared HTTP, date, city normalization
      class-source-registry.php          — Source database + adapter registry
      class-source-collector.php         — Orchestrates scrape pipeline
      class-adapter-remembering-ca.php   — Remembering.ca / Postmedia network (7 sources)
      class-adapter-frontrunner.php      — FrontRunner funeral home sites
      class-adapter-dignity-memorial.php — Dignity Memorial
      class-adapter-legacy-com.php       — Legacy.com
      class-adapter-tribute-archive.php  — Tribute Archive
      class-adapter-generic-html.php     — Generic HTML fallback
    pipelines/
      class-image-pipeline.php           — Image download + thumbnail
      class-suppression-manager.php      — Do-not-republish blocklist
  templates/
    obituaries.php        — Shortcode template (main listing page)
    obituary-detail.php   — Modal detail view
    seo/
      hub-ontario.php     — /obituaries/ontario/ template
      hub-city.php        — /obituaries/ontario/{city}/ template
      individual.php      — /obituaries/ontario/{city}/{slug}/ template
  assets/
    css/ontario-obituaries.css
    js/ontario-obituaries.js, ontario-obituaries-admin.js
```

## Data Flow

```
Cron/Manual Trigger
  -> ontario_obituaries_scheduled_collection()
    -> Source_Collector::collect()
      -> Source_Registry::get_active_sources()
      -> For each source:
          -> Adapter::discover_listing_urls()
          -> Adapter::fetch_listing()      (HTTP GET)
          -> Adapter::extract_obit_cards() (XPath parsing)
          -> Adapter::normalize()          (date/city/name cleanup)
          -> Source_Collector::insert_obituary()  (cross-source dedup + INSERT IGNORE)
    -> Dedup cleanup runs
    -> LiteSpeed cache purged
```

## Key Database Table

`{prefix}ontario_obituaries` — fields: id, name, date_of_birth, date_of_death,
age, funeral_home, location, image_url, description, source_url, source_domain,
source_type, city_normalized, provenance_hash, suppressed_at, created_at.

Unique key: `(name(100), date_of_death, funeral_home(100))`.

---

## RULE 11: Source Registry Health Check

Before merging any PR that changes **scraper**, **adapter**, or **source-registry** code:

### 11.1 — Verify at least one source URL returns parseable obituary links

```bash
# Must return > 0 (structural /obituary/ link pattern — stable across layout changes)
curl -s -A 'OntarioObituariesBot/3.9.0' \
  'https://obituaries.yorkregion.com/obituaries/obituaries/search' \
  | grep -cE '/obituary/[A-Za-z]'
```

### 11.2 — Verify pagination returns different data on page 2

```bash
PAGE1=$(curl -s -A 'OntarioObituariesBot/3.9.0' \
  'https://obituaries.yorkregion.com/obituaries/obituaries/search' \
  | grep -oE '/obituary/[^"]+' | head -1)
PAGE2=$(curl -s -A 'OntarioObituariesBot/3.9.0' \
  'https://obituaries.yorkregion.com/obituaries/obituaries/search?p=2' \
  | grep -oE '/obituary/[^"]+' | head -1)
[ "$PAGE1" != "$PAGE2" ] && echo "PASS: page 2 differs" || echo "FAIL: page 2 identical"
```

### 11.3 — Dead source handling

- **Permanently dead** sources (404, 403, DNS timeout confirmed across multiple days)
  MUST be seeded with `'enabled' => 0` in `seed_defaults()`. Do NOT delete the
  entry — preserve the domain key for circuit-breaker history and future re-enabling.
- **Intermittently failing** sources are handled automatically by the circuit breaker
  in `record_failure()`. No seed change needed.

### 11.4 — Re-seed safety net

`on_plugin_update()` MUST contain a guard that re-seeds via `seed_defaults()` when
the sources table has 0 rows. The guard MUST:

1. Run once per deployment (gated by `ontario_obituaries_deployed_version`).
2. Not schedule duplicate cron events (check `wp_next_scheduled()` before scheduling).

### 11.5 — Domain field convention

The `domain` column in the source registry is a **unique source slug**, not a DNS
hostname. Sources sharing a host but serving different cities (e.g.,
`dignitymemorial.com/newmarket-on` vs `dignitymemorial.com/toronto-on`) use path
segments to create unique slugs. The obituary record's `source_domain` is derived
separately from `extract_domain(base_url)` (actual hostname). **Never compare
`domain` to `source_domain`.**

### 11.6 — Image filtering: funeral home logo rejection (v4.0.1)

**Problem discovered 2026-02-13**: The Remembering.ca adapter scraped funeral home
logos (Ogden Funeral Homes, Arthur B Ridley Funeral Home, etc.) as obituary portrait
images. These logos are copyrighted business branding and must NOT be displayed as
obituary photos on monacomonuments.ca.

**Root cause**: The CDN (`d1q40j6jx1d8h6.cloudfront.net/Obituaries/{id}/Image_N.jpg`)
stores both real portraits and funeral home logos at the same URL pattern. The adapter
had no way to distinguish them.

**Fix**: `is_likely_portrait()` method in `class-adapter-remembering-ca.php` performs
a lightweight HTTP HEAD request to check `Content-Length`. Images under 15 KB are
rejected as likely logos (observed: logos 5-12 KB, portraits 20-500+ KB).

**Migration**: v4.0.1 upgrade block in `ontario-obituaries.php` scans existing
records with Cloudfront image URLs and clears `image_url` for any under 15 KB.

**Rules for future image handling**:
- NEVER display an image without verifying it is a portrait, not a business logo.
- The 15 KB threshold is conservative. If false positives arise (tiny portraits
  rejected), raise only after manual verification.
- If a funeral home provides high-resolution logos (> 15 KB), add the obituary ID
  or CDN path to a blocklist in the adapter.

---

## Section 12 — Deployment: WP Pusher Status

**Status as of 2026-02-13**: WP Pusher is installed but CANNOT auto-deploy because
the GitHub repo (`SMMM25/ontarioobitwpplugin`) is **private** and WP Pusher requires
a paid license for private repos.

**Current deployment method**: Manual upload via cPanel File Manager.

**Deployment steps**:
1. Merge PR on GitHub.
2. Download updated plugin files (or ZIP from sandbox).
3. Upload to `public_html/wp-content/plugins/ontario-obituaries/` via cPanel.
4. Extract/overwrite files.
5. Visit any site page to trigger the `init` migration hook.
6. Purge LiteSpeed Cache.
7. Verify via `/wp-json/ontario-obituaries/v1/cron`.

**Future fix options**:
- Purchase WP Pusher license for private repos (~$49/year).
- Make the repo public (not recommended — contains business logic).
- Set up a GitHub Actions workflow that deploys via SSH/SFTP on merge.

---

## Section 13 — AI Rewriter (v4.1.0)

### Architecture
- **Module**: `includes/class-ai-rewriter.php`
- **API**: Groq (OpenAI-compatible) — free tier, no credit card
- **Models**: Primary `llama-3.3-70b-versatile`, fallback `llama-3.1-8b-instant`
- **Storage**: `ai_description` column added to `wp_ontario_obituaries` table
- **Display**: Templates prefer `ai_description` over raw `description`

### API Key Management
- Stored in: `wp_options` → `ontario_obituaries_groq_api_key`
- **v4.2.3+ (current live)**: Set via WP Admin → Ontario Obituaries → Settings → AI Rewrite Engine section
- **v4.2.4+**: Saving settings with AI enabled auto-schedules the first batch (30s delay)
- Groq API key: `gsk_Ge1...7ZHT` (set 2026-02-13)
- Both models confirmed available: `llama-3.3-70b-versatile`, `llama-3.1-8b-instant`

### Security Audit Results (2026-02-13)
- **SQL injection**: All user-facing queries use `$wpdb->prepare()` with placeholders ✅
- **AJAX**: All handlers use `check_ajax_referer()` nonce verification; admin endpoints check `current_user_can('manage_options')` ✅
- **XSS**: All template outputs use `esc_html()`, `esc_attr()`, `esc_url()` or are pre-escaped ✅
- **Route params**: IDs use `intval()`, slugs use `sanitize_title()` ✅

### Rate Limits (Groq Free Tier)
- Llama 3.3 70B: 1,000 requests/day, 12,000 tokens/minute
- Llama 3.1 8B: 14,400 requests/day, 6,000 tokens/minute
- Plugin rate: 1 request per 6 seconds (10/min), 25 per batch
- At ~175 new obituaries per scrape, all can be rewritten within 1 day

### Validation Rules
- Rewrite must mention the deceased's last name (or first name)
- Length: 50–5,000 characters
- No LLM artifacts ("as an AI", "certainly!", "here is", etc.)
- Failed validations are logged but do not prevent future retries

### Cron Integration
- After each collection (`ontario_obituaries_collection_event`), a rewrite batch
  is scheduled 120 seconds later
- Batch processes 25 obituaries, then self-reschedules if more remain
- Each batch runs on the `ontario_obituaries_ai_rewrite_batch` hook

### REST Endpoints
- `GET /wp-json/ontario-obituaries/v1/ai-rewriter` — Status and stats (admin-only)
- `GET /wp-json/ontario-obituaries/v1/ai-rewriter?action=trigger` — Manual batch trigger

### Rules for AI Rewriter
- NEVER modify the original `description` field — it's the source of truth.
- The `ai_description` field is disposable — can be regenerated at any time.
- If Groq changes their API or rate limits, update the constants in class-ai-rewriter.php.
- Monitor the error log for rate limiting or validation failures.

---

## Section 14 — IndexNow Integration (v4.2.0)

- **Module**: `includes/class-indexnow.php`
- **Purpose**: Submit new obituary URLs to Bing/Yandex/Naver for instant indexing
- **API Key**: Auto-generated, stored in `ontario_obituaries_indexnow_key` option
- **Verification**: Key served dynamically at `/{key}.txt` via `template_redirect` hook
- **Trigger**: Runs automatically after each collection cycle for newly added obituaries
- **Batch limit**: Up to 10,000 URLs per submission (API maximum)

---

## Section 15 — Domain Lock (v4.2.0)

The plugin includes a domain lock that restricts scraping and cron operations
to authorized domains only.

- **Authorized domains**: `monacomonuments.ca`, `localhost`, `127.0.0.1`
- **Constant**: `ONTARIO_OBITUARIES_AUTHORIZED_DOMAINS` in `ontario-obituaries.php`
- **What's blocked**: Scheduled collection, AI rewrites on unauthorized domains
- **What's NOT blocked**: Admin pages, display, so the owner can see the lock message
- **To add a domain**: Edit the constant in the main plugin file

---

## Section 16 — Lead Capture (v4.2.0)

- **Form**: Displayed on individual obituary SEO pages (soft, non-intrusive)
- **Storage**: `ontario_obituaries_leads` option in wp_options (array of leads)
- **Fields captured**: email, city, obituary_id, timestamp, hashed IP
- **Dedup**: Same email won't be stored twice
- **AJAX handler**: `ontario_obituaries_lead_capture` in `class-ontario-obituaries-ajax.php`
- **Privacy**: No external services. Data stays in WordPress database.

---

## Section 17 — QR Codes (v4.2.0, fixed v4.2.1)

- Individual obituary pages display a QR code linking to the memorial page URL
- **v4.2.1 fix**: Google Charts QR API was deprecated (returns 404). Replaced with
  QR Server API (`https://api.qrserver.com/v1/create-qr-code/`), which is free,
  no-auth, and returns PNG images directly.
- 150×150 pixels, lazy-loaded
- Useful for funeral programs, printed materials

---

## Section 18 — Audit Fixes (v4.2.1)

**QA audit performed 2026-02-13** caught 2 bugs and 1 improvement:

1. **BUG: QR codes broken** — Google Charts QR API returns 404 (deprecated).
   Fix: Switched to `api.qrserver.com` (free, no auth required).
2. **BUG: Lead capture form had no JS handler** — Submitting the email form
   navigated the browser to `admin-ajax.php`, showing raw JSON.
   Fix: Added inline `fetch()` AJAX handler with success/error messages.
3. **IMPROVEMENT: `should_index` now considers `ai_description`** — Previously
   only checked `description` length. Obituaries with AI rewrites (but short
   originals) were incorrectly marked `noindex`.

**Rules for future QR/lead changes**:
- Never use deprecated APIs without checking their status first.
- All AJAX form submissions MUST have a JavaScript handler — never submit
  directly to `admin-ajax.php` via `<form action>`.
- Test all external API endpoints before deployment (curl -sI).

---

## Section 19 — City Data Quality Repair (v4.2.2)

**Problem identified 2026-02-13**: The `city_normalized` column contained corrupted
data from multiple sources:

1. **Truncated names**: `Hamilt` (Hamilton), `Burlingt` (Burlington), `Sutt` (Sutton), etc.
2. **Full street addresses**: `King Street East, Hamilton` stored as city name.
3. **Garbled/encoded strings**: `q2l0eq`, `mself-__next_f-push1arkham`.
4. **Biographical text**: `Jan was born in Toronto`, `Fred settled in Markham`.
5. **Facility names**: `Sunrise of Unionville`, `St. Josephs Health Centre in Guelph`.
6. **Typos**: `Kitchner` (Kitchener), `Stoiuffville` (Stouffville).
7. **Out-of-province cities**: Calgary, Vancouver, San Diego (valid obituaries, just not Ontario).

**Impact**: Bad city slugs in sitemap URLs (`/ontario/hamilt/`, `/ontario/q2l0eq/`),
broken city hub pages, and poor SEO signals.

**Fix (two-part)**:

1. **Migration** (`v4.2.2` block in `on_plugin_update`): Multi-pass repair of
   existing records — direct replacements, address extraction, garbled data clearing,
   facility name removal, and general address cleanup.

2. **Root-cause prevention** (`normalize_city()` in `class-source-adapter-base.php`):
   Strengthened the normalization function to reject street addresses, garbled strings,
   biographical text, and values > 40 chars. Includes a truncation-fix map for known
   short forms. Future scrapes will store clean city names.

**Sitemap fix**: The sitemap query now includes obituaries with `ai_description` > 100
characters (previously only checked `description`), increasing Google indexation.

**Rules for future city data**:
- Always validate city_normalized before INSERT — it must be a real city name.
- Never store street addresses, postal codes, or biographical text in city_normalized.
- The `normalize_city()` function is the single source of truth for city cleanup.
- If a city cannot be reliably extracted, store empty string (better than bad data).
- Monitor the sitemap periodically: `curl -s https://monacomonuments.ca/obituaries-sitemap.xml | grep -oP '/ontario/[^/]+/' | sort -u`
