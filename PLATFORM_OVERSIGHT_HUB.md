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
      class-adapter-remembering-ca.php   — Remembering.ca / yorkregion.com
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
