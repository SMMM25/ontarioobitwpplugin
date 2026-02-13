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
- Set via WP Admin: Options → `ontario_obituaries_groq_api_key`
- Or via WP-CLI: `wp option update ontario_obituaries_groq_api_key "gsk_xxx"`
- Enable rewrites: Set `ai_rewrite_enabled` to true in plugin settings

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
