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

### Current Versions (as of 2026-02-16)
| Environment | Version | Notes |
|-------------|---------|-------|
| **Live site** | 5.0.2 | monacomonuments.ca — deployed via WordPress Upload Plugin |
| **Main branch** | 5.0.3 | PR #83 merged |
| **Sandbox** | 5.0.4 | BUG-C2 fix — pending PR review |

### PROJECT STATUS: CRITICAL BUGS IDENTIFIED (2026-02-16)
> **Independent code audit** performed 2026-02-16 found **4 critical bugs, 7 high-severity
> issues, and 6 medium-severity concerns** in the plugin codebase. The previous developer's
> claim that "the plugin is stable and all other features work correctly" and that "the AI
> Rewriter issue is a Groq free-tier limitation, not a code bug" is **INCORRECT**.
>
> Multiple architectural and logic bugs exist that cause the activation cascade, display
> pipeline failure, performance degradation, and unclean uninstall behavior.
> See **Section 26** for the full audit and **Section 27** for the systematic fix plan.

### Live Site Stats (verified 2026-02-15)
- **725+ obituaries** displayed across 37+ pagination pages
- **~15 obituaries AI-rewritten** via v5.0.2 before Groq TPM limit hit
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
| AI Customer Service Chatbot (Groq-powered) | v4.5.0 | ✅ LIVE — enabled, working on frontend |
| Google Ads Campaign Optimizer | v4.5.0 | ✅ DEPLOYED — disabled (owner's off-season, toggle-ready) |
| Enhanced SEO Schema (DonateAction for GoFundMe) | v4.5.0 | ✅ LIVE |
| GoFundMe Auto-Linker | v4.3.0 | ✅ LIVE (2 matched, 360 pending) |
| AI Authenticity Checker (24/7 audits) | v4.3.0 | ✅ LIVE (725 never audited — processing) |
| Logo filter (rejects images < 15 KB) | v4.0.1 | ✅ LIVE |
| AI rewrite engine (Groq/Llama) | v5.0.2 | ❌ BROKEN — multiple code bugs (activation cascade, display pipeline deadlock) + Groq free-tier TPM limit. See Section 26. |
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
| Death date cross-validation fix | v4.2.4 | ✅ LIVE |
| AI Rewriter immediate batch on save | v4.2.4 | ✅ LIVE |
| Future death date rejection | v4.2.4 | ✅ LIVE |
| q2l0eq garbled slug cleanup | v4.2.4 | ✅ LIVE |

### AI Rewriter Status (BROKEN — 2026-02-16 Audit)
- **Code**: Multiple bugs identified (`class-ai-rewriter.php`, `ontario-obituaries.php`, `class-ontario-obituaries-display.php`) — v5.0.2
- **Audit finding**: The previous developer misdiagnosed this as "only a Groq TPM issue." The real problems are:
  1. **Activation cascade** triggers 5-15 synchronous scrapes + rewrites on plugin activation (Section 26, BUG-C1)
  2. **Display pipeline deadlock** — records inserted as `pending` are invisible because display queries require `status='published'`, but records only become `published` AFTER a successful AI rewrite, which is rate-limited (Section 26, BUG-C2)
  3. **Non-idempotent migrations** run on every activation, performing blocking HTTP calls (Section 26, BUG-C3)
4. ~~**Performance killer** — duplicate cleanup runs on every page load (Section 26, BUG-C4)~~ ✅ **FIXED (v5.0.4, PR #85)**
- **Primary model**: `llama-3.1-8b-instant` (switched from 70B in v5.0.0 to reduce token usage)
- **Fallback models**: `llama-3.3-70b-versatile`, `llama-4-scout` (NOT used on 429 errors as of v5.0.2)
- **Admin UI**: Settings page section (checkbox toggle + API key input + live stats)
- **Activation**: WP Admin → Ontario Obituaries → Settings → AI Rewrite Engine section
- **Get key**: Free at https://console.groq.com (no credit card needed)
- **What it does**: Rewrites scraped obituary text into original prose, extracts structured fields (death date, birth date, age, location, funeral home) via JSON
- **Rate**: 1 obituary per call, 12-second delay between requests (~5 req/min)
- **Groq API key set** (2026-02-13) — AI rewrites enabled via admin settings
- **Progress**: ~15 of 725+ rewritten before Groq TPM limit stops processing
- **BLOCKER**: Groq free-tier TPM limit (6,000 tokens/min for llama-3.1-8b-instant) is exhausted after ~5-6 requests/min. Each obituary uses ~900-1,400 tokens (prompt + response). Even with a 12s delay, cumulative token usage hits the ceiling after ~15 items per 5-minute cron window.
- **Execution paths** (all use mutual-exclusion transient lock `ontario_obituaries_rewriter_running`):
  1. **WP-Cron handler** (`ontario_obituaries_ai_rewrite_batch`) — processes 1 obituary, 12s delay, runs up to 4 min
  2. **Shutdown hook** (`ontario_obituaries_shutdown_rewriter`) — processes 1 obituary on admin page load, 1-min throttle
  3. **AJAX button** (`ontario_obituaries_ajax_run_rewriter`) — processes 1 per call, JS auto-repeats
  4. **CLI cron** (`cron-rewriter.php`) — standalone script, file-lock at `/tmp/ontario_rewriter.lock`
- **cPanel cron command**: `/usr/local/bin/php /home/monaylnf/public_html/wp-cron.php >/dev/null 2>&1` (every 5 min)
- **v5.0.2 fixes applied**: 12s delay (was 6s), no fallback retry on 429, retry-after header parsing

### AI Chatbot Status (v4.5.0 — NEW)
- **Code**: `includes/class-ai-chatbot.php` (32 KB)
- **Frontend**: `assets/css/ontario-chatbot.css` (11 KB) + `assets/js/ontario-chatbot.js` (13 KB)
- **Enabled**: ✅ Live on all public pages (floating bottom-right chat bubble)
- **AI Engine**: Groq LLM (same API key as rewriter) with smart rule-based fallback
- **Email**: Sends customer inquiries to `info@monacomonuments.ca`
- **Intake Form**: Directs customers to https://monacomonuments.ca/contact/ — explains no-cost, priority queue
- **Features**: Auto-greeting, quick-action buttons (Get Started, Pricing, Catalog, Contact), conversation history, email forwarding
- **Security**: Rate-limiting (1 msg/2s/IP), nonce verification, XSS protection (25+ esc_ calls)
- **Cost**: Zero — uses Groq free tier, no external SaaS
- **Admin toggle**: Ontario Obituaries → Settings → AI Customer Chatbot → Enable checkbox

### Google Ads Optimizer Status (v4.5.0 — NEW)
- **Code**: `includes/class-google-ads-optimizer.php` (43 KB)
- **Enabled**: ❌ Disabled (owner's off-season decision — toggle-ready for spring)
- **Google Ads Customer ID**: 903-524-8478 (pre-configured)
- **Features**: Campaign metrics, keyword analysis, AI bid/budget recommendations, daily analysis
- **Admin toggle**: Ontario Obituaries → Settings → Google Ads Campaign Optimizer → Enable checkbox + enter API credentials
- **Credentials needed to activate**: Developer Token, OAuth2 Client ID, Client Secret, Refresh Token

### Known Data Quality Issues
1. ~~**Truncated/garbled city names**~~ → ✅ Fixed by v4.2.2 + v4.2.3 migrations
2. ~~**14 address-pattern city slugs**~~ → ✅ Fixed by v4.2.3 migration
3. ~~**Wrong death years on ~8 obituaries**~~ → ✅ Fixed by v4.2.4 migration
4. ~~**1 future death date** (Michael McCarty)~~ → ✅ Fixed by v4.2.4 migration
5. ~~**q2l0eq garbled slug**~~ → ✅ Fixed by v4.2.4 migration
6. **Fabricated YYYY-01-01 dates** from legacy scraper → needs separate data repair PR
7. **Out-of-province obituaries** (Calgary, Vancouver, etc.) → valid records, low priority
8. **Schema redesign needed** for records without death date → future work
9. ~~**Display deadlock** — 710+ of 725 records invisible due to `status='published'` filter in display queries.~~ → ✅ **FIXED (v5.0.4, PR #84)** — All display/SEO queries now show all non-suppressed records regardless of status.
10. **Name-only dedup risk** — 3rd dedup pass could merge different people with same name. See BUG-M4 in Section 26.

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
| `includes/class-ai-chatbot.php` | AI chatbot (Groq, email, intake form) |
| `includes/class-google-ads-optimizer.php` | Google Ads API optimizer |
| `includes/class-gofundme-linker.php` | GoFundMe campaign auto-linker |
| `includes/class-ai-authenticity-checker.php` | AI data quality auditor |
| `assets/css/ontario-chatbot.css` | Chatbot frontend styles |
| `assets/js/ontario-chatbot.js` | Chatbot frontend JavaScript |
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
| #55 | Merged | v4.2.4 | Death date cross-validation fix + AI Rewriter activation fix |
| #56 | Merged | v4.3.0 | GoFundMe Auto-Linker + AI Authenticity Checker |
| #57 | Merged | v4.3.0 | (merge commit) |
| #58 | Merged | v4.5.0 | AI Customer Chatbot + Google Ads Optimizer + Enhanced SEO Schema |
| #71 | Merged | v4.6.7 | Fix 401/403 API errors + API key diagnostic tool |
| #72 | Merged | v5.0.0 | Groq structured JSON extraction replaces regex (97% accuracy) |
| #73 | Merged | v5.0.0 | Groq structured JSON extraction + version bump |
| #74 | Merged | v5.0.0 | Groq free-tier rate limit tuning |
| #75 | Merged | v5.0.0 | Batch size 5, delay 8s to fix AJAX timeouts |
| #76 | Merged | v5.0.0 | Update UI labels to match v5.0.0 workflow |
| #77 | Merged | v5.0.0 | Switch to 8B model + 15s delay to eliminate rate limiting |
| #78 | Merged | v5.0.0 | Bulletproof CLI cron — 10/batch @ 6s (~250/hour) |
| #79 | Merged | v5.0.1 | Process 1 obituary at a time + mutual exclusion lock |
| #80 | Merged | v5.0.2 | Respect Groq 6,000 TPM token limit — 12s delay, no fallback on 429 |
| #83 | Merged | v5.0.3 | BUG-C1/C3 fix: Remove 1,663 lines of dangerous historical migrations from on_plugin_update() |
| #84 | Pending | v5.0.4 | BUG-C2 fix: Remove status='published' gate from 18 display/SEO queries + add RULE 14 (Core Workflow Integrity) |

### Remaining Work (priority order — updated 2026-02-16 after code audit)

> **IMPORTANT**: Items 1-15 are NEW findings from the 2026-02-16 independent code audit.
> These supersede the previous developer's claim that "the plugin is stable."
> See Section 26 for full details on each bug and Section 27 for the systematic fix plan.

#### CRITICAL (plugin does not function correctly until fixed)
1. ~~**BUG-C1: Activation cascade**~~ — ✅ **FIXED in v5.0.3** (PR #83). Removed 1,663 lines of historical migration blocks (v3.9.0–v4.3.0) from `on_plugin_update()` that ran synchronous HTTP scrapes, usleep() enrichment loops, and unguarded ALTER TABLE on every fresh install or option loss. Schema is handled by `activate()`; data freshness by cron + heartbeat. KNOWN LIMITATIONS: `init` hook retained (needed for WP Pusher); no capability gate (would break cron/CLI); `wp_cache_flush()` kept (separate PR).
2. **BUG-C2: Display pipeline deadlock** — Records are inserted as `pending` but `class-ontario-obituaries-display.php` only queries `status='published'`. Without successful AI rewrite, no obituaries appear. **FIX**: Show records without AI rewrite; make rewrite an enhancement, not a gate.
3. ~~**BUG-C3: Non-idempotent migrations**~~ — ✅ **FIXED in v5.0.3** (PR #83). Historical migration blocks removed entirely; remaining operations (rewrite flush, cache purge, transient delete, registry seed, cron schedule) are all idempotent. `deployed_version` write moved to end of function so partial failures retry safely.
4. ~~**BUG-C4: Duplicate cleanup on every init**~~ — ✅ **FIXED in v5.0.4** (PR #85). Removed `init` hook; replaced with daily cron (`ontario_obituaries_dedup_daily`) + post-scrape deferred one-shot cron (`ontario_obituaries_dedup_once`). Lock uses WP-native `add_option()`/`get_option()` (not raw SQL) with JSON state `{ts,state}` — distinguishes "running" from "done" (cooldown). Stale-lock auto-cleared after 1h; cooldown 1h; no recursive retry. Admin rescan bypasses cooldown via `$force=true`. Logging: errors always, success at debug, throttled silent.

#### HIGH (significant functional or security issues)
5. **BUG-H1: Nonsense rate calculation in cron-rewriter.php** — Line ~224 divides by `$processed` which can be 0 or produces misleading rates. **FIX**: Guard division, use accurate timing.
6. ~~**BUG-H2: Lingering API keys after uninstall**~~ — ✅ **FIXED in v5.0.5** (PR #86). `uninstall.php` now deletes all 22 plugin options (including 3 sensitive API keys), 8 transients, and clears all 8 cron hooks. Inventory method documented in code.
7. ~~**BUG-H3: Duplicate index creation**~~ — ✅ **FIXED in v5.0.3** (PR #83). v4.3.0 migration block removed entirely; index creation handled by `activate()` which has existence checks.
8. **BUG-H4: Possible undefined $result** — Line ~1208 in `ontario-obituaries.php` may reference `$result` before assignment in edge cases. **FIX**: Initialize variable before conditional block.
9. **BUG-H5: Premature throttling in shutdown rewriter** — Line ~1293 sets 1-minute throttle on shutdown hook, but the lock is checked before processing even starts. **FIX**: Only throttle after successful processing.
10. **BUG-H6: Over-permissive domain lock** — Line ~38 uses `strpos()` which matches substrings (e.g., `evilmonacomonuments.ca` would pass). **FIX**: Use exact match or parsed hostname comparison.
11. ~~**BUG-H7: Stale cron hooks survive uninstall**~~ — ✅ **FIXED in v5.0.5** (PR #86). `uninstall.php` now clears all 8 scheduled cron hooks (was 2). Inventory method documented in code.

#### MEDIUM (architectural debt, misleading docs, or edge-case risks)
12. **BUG-M1: Shared Groq API key across 3 consumers** — AI Rewriter, Chatbot, and Authenticity Checker all use the same Groq key with no shared rate limiter. They can collectively exceed TPM. **FIX**: Implement a shared Groq rate limiter or stagger schedules.
13. **BUG-M2: Misleading Oversight Hub claims** — Previous docs state "plugin is stable, all other features work correctly." This is false — display deadlock, activation cascade, and performance bugs exist. **FIX**: This update corrects the record.
14. ~~**BUG-M3: 1,721-line on_plugin_update() function**~~ — ✅ **FIXED in v5.0.3** (PR #83). Function reduced from ~1,721 lines to ~100 lines by removing historical migration blocks. Future migrations follow documented rules (no sync HTTP, idempotent, check-before-ALTER).
15. **BUG-M4: Risky name-only dedup pass** — 3rd dedup pass matches by name alone (no date), risking deletion of different people with the same name. **FIX**: Add a date-range guard or remove name-only pass.
16. **BUG-M5: Activation race conditions** — Multiple cron events scheduled in quick succession can cause overlapping scrapes. **FIX**: Use transient locks for scrape scheduling.
17. **BUG-M6: Unrealistic throughput comments** — Code comments claim "~200 rewrites/hour" but math shows ~15 per 5-min window = ~180/hour max theoretical. **FIX**: Update comments to reflect reality.

#### PREVIOUSLY KNOWN (carried forward)
18. ~~**Deploy v4.2.2-v5.0.2**~~ → Done (2026-02-13 through 2026-02-15)
19. **BLOCKED: AI Rewriter Groq TPM limit** — See Section 25 for solutions (upgrade plan, switch API, etc.)
20. **Enable Google Ads Optimizer** when busy season starts (spring)
21. **Data repair**: Clean fabricated YYYY-01-01 dates (future PR)
22. **Schema redesign**: Handle records without death date (future PR)
23. **Out-of-province filtering** (low priority)
24. **Automated deployment** via GitHub Actions or WP Pusher paid (low priority)

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

## RULE 14: Core Workflow Integrity — DO NOT ALTER

> **THIS IS THE MOST IMPORTANT RULE.** All bug fixes, refactors, and new features
> MUST preserve the plugin's core plan and vision. Any PR that breaks this workflow
> will be rejected.

**The Plugin's Core Pipeline:**

```
SCRAPE → AI VIEWS DATA → AI REWRITES OBITUARY → SYSTEM PUBLISHES
```

1. **SCRAPE**: The collector scrapes Ontario obituary sources on a scheduled cron.
   Raw obituary data (name, dates, location, description) is stored in the DB
   with `status = 'pending'`.
2. **AI VIEWS DATA**: The AI rewriter reads the raw scraped data from the DB.
3. **AI REWRITES OBITUARY**: The AI produces an original, fact-preserving rewrite
   stored in `ai_description`. The record's status is updated to `published`.
4. **SYSTEM PUBLISHES**: The display and SEO classes render obituaries on
   `www.monacomonuments.ca` with full Schema.org markup, sitemaps, and memorial pages.

**Factual Integrity Requirement:**
- Published data MUST be **100% factual**: names, dates of birth, dates of death,
  locations, funeral homes, and all other obituary details must come directly from
  the original scraped source.
- The AI rewrite is an **enhancement** (better prose, structured extraction) — it
  MUST NOT fabricate, alter, or omit any factual data.
- Templates MUST display `ai_description` when available, falling back to the
  original `description` when the AI rewrite has not yet completed.

**What This Means for Bug Fixes:**
- Removing `status='published'` from display queries (BUG-C2) is allowed because
  it makes records visible sooner using their **original factual data**. The AI
  rewrite still runs in the background and enhances the display when complete.
- Any change that would skip, bypass, or disable the AI rewrite pipeline requires
  explicit owner approval.
- Any change that would display fabricated or altered data is **PROHIBITED**.

**Known Visibility Side‑Effects (v5.0.4, PR #84):**

After removing the `status='published'` gate, these exposures exist by design:

| # | Risk | Status | Evidence |
|---|------|--------|----------|
| 1 | ~~**REST API exposes pending records**~~ | ✅ **FIXED (v5.0.4)** — REST endpoints now require `manage_options` capability (admin-only). API also applies `status='published'` filter internally, preserving the original API contract. Eliminates unauthenticated access, bulk enumeration, and DoS. See `class-ontario-obituaries.php` `rest_permission_check()` and `get_obituaries_api()`. |
| 2 | **System split‑brain** — Frontend/SEO shows all non‑suppressed records; IndexNow, GoFundMe linker, authenticity checker, dashboard stats, and REST API still filter on `status='published'`. | **INTENTIONAL** — Frontend serves factual scraped data immediately. AI‑dependent subsystems (IndexNow, GoFundMe, authenticity, REST API) correctly operate only on AI‑reviewed (`published`) records. Dashboard stats should be updated in a future PR to show both counts. |
| 3 | **SEO indexing gate** — Length check (`CHAR_LENGTH > 100`) may allow low‑quality boilerplate to be indexed. | **PRE‑EXISTING** — Unchanged from before BUG‑C2 fix. Pages with short descriptions get `noindex` (SEO lines 415‑423). Future improvement: add content‑quality heuristic. |
| 4 | **Status column has no DB‑level constraint** — Only 'pending' and 'published' are written in code (`class-source-collector.php:481`, `class-ai-rewriter.php:388`, `ontario-obituaries.php:495`). Canonical whitelist defined in `ontario_obituaries_valid_statuses()` (`ontario-obituaries.php`). Display layer's optional status filter validates via `ontario_obituaries_is_valid_status()`. Backfill query builds its IN-list from the same function, using `LOWER(COALESCE(status,''))` for collation safety and `LIMIT 500` for bounded execution. | **MITIGATED** — Single source of truth: `ontario_obituaries_valid_statuses()`. Adding a new status requires updating one function. Dirty/legacy/NULL statuses auto‑cleaned on plugin update (batched, collation‑safe). DB CHECK constraint is a future hardening task. |
| 5 | **Cache invalidation** — Transients cleared after every scrape (`class-source-collector.php:1042‑1043`) and on plugin update, admin delete, debug reset, and rescan. Not status‑specific. | **NOT A RISK** — Caches were never keyed on status; invalidation covers all insert/delete paths. |
| 6 | **Performance at scale** — Dropping `status='published'` scans all non‑suppressed rows (~725 now). | **ACCEPTED** — Trivial at current scale. Add composite index `(suppressed_at, date_of_death)` when rows exceed 5,000. |
| 7 | **API/frontend count divergence** — REST API count is published‑only; frontend count is all‑visible. Admin seeing "725 on site, 15 in API" is expected. | **INTENTIONAL** — REST API preserves original contract (`published` only). Frontend shows all factual scraped data per RULE 14. No current API consumers exist (verified via codebase grep). If future consumers need all‑visible counts, they can pass `status` parameter or a new endpoint can be added. |

**JSON‑LD XSS Hardening (v5.0.4):**
- All 6 `wp_json_encode()` calls inside `<script type="application/ld+json">` blocks now use `JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT`.
- This escapes `<` → `\u003C` and `>` → `\u003E`, preventing `</script>` breakout from scraped content.
- Affected files: `class-ontario-obituaries-seo.php` (4 sinks), `templates/seo/individual.php` (1 sink), `mu-plugins/monaco-site-hardening.php` (1 sink).
- Reference: WordPress Core Trac #63851 confirms `wp_json_encode()` without `JSON_HEX_TAG` is insufficient for script contexts.

---

## Architecture Quick Reference

```
wp-plugin/
  ontario-obituaries.php          — Main plugin file, activation, cron, dedup, version
  includes/
    class-ontario-obituaries.php         — Core WP integration (shortcode, assets, REST, settings UI)
    class-ontario-obituaries-display.php — Shortcode rendering + data queries
    class-ontario-obituaries-scraper.php — Legacy scraper (v2.x, fallback)
    class-ontario-obituaries-seo.php     — SEO hub pages, sitemap, schema, OG tags
    class-ontario-obituaries-admin.php   — Admin settings page
    class-ontario-obituaries-ajax.php    — AJAX handlers (quick view, removal)
    class-ontario-obituaries-debug.php   — Debug/diagnostics page
    class-ai-rewriter.php                — AI rewrite engine (Groq/Llama) [v4.1.0]
    class-ai-chatbot.php                 — AI customer chatbot (Groq + rule-based) [v4.5.0]
    class-ai-authenticity-checker.php    — AI data quality auditor [v4.3.0]
    class-gofundme-linker.php            — GoFundMe campaign auto-linker [v4.3.0]
    class-google-ads-optimizer.php       — Google Ads API optimizer [v4.5.0]
    class-indexnow.php                   — IndexNow search engine notification [v4.2.0]
    class-ontario-obituaries-reset-rescan.php — Reset & rescan tool [v3.11.0]
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
      wrapper.php         — Full HTML5 shell (Elementor header/footer)
      hub-ontario.php     — /obituaries/ontario/ template
      hub-city.php        — /obituaries/ontario/{city}/ template
      individual.php      — /obituaries/ontario/{city}/{slug}/ template
  assets/
    css/ontario-obituaries.css
    css/ontario-chatbot.css              — Chatbot frontend styles [v4.5.0]
    js/ontario-obituaries.js, ontario-obituaries-admin.js
    js/ontario-chatbot.js                — Chatbot frontend JavaScript [v4.5.0]
  mu-plugins/
    monaco-site-hardening.php            — Performance + security MU-plugin
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

## Section 13 — AI Rewriter (v5.0.2 — PAUSED)

### Architecture
- **Module**: `includes/class-ai-rewriter.php`
- **API**: Groq (OpenAI-compatible) — free tier, no credit card
- **Primary model**: `llama-3.1-8b-instant` (switched from 70B in v5.0.0 for lower token usage)
- **Fallback models**: `llama-3.3-70b-versatile`, `llama-4-scout` (used on 403 only, NOT on 429)
- **Storage**: `ai_description` column + extracted fields (date_of_death, date_of_birth, age, location, funeral_home) in `wp_ontario_obituaries` table
- **Display**: Templates prefer `ai_description` over raw `description`
- **Processing**: v5.0.0+ uses structured JSON output from Groq for field extraction (replaces regex)

### API Key Management
- Stored in: `wp_options` → `ontario_obituaries_groq_api_key`
- **v4.2.3+ (current live)**: Set via WP Admin → Ontario Obituaries → Settings → AI Rewrite Engine section
- **v4.2.4+**: Saving settings with AI enabled auto-schedules the first batch (30s delay)
- Groq API key: `gsk_Ge1...7ZHT` (set 2026-02-13)
- Models confirmed available: `llama-3.1-8b-instant`, `llama-3.3-70b-versatile`

### Security Audit Results (2026-02-13)
- **SQL injection**: All user-facing queries use `$wpdb->prepare()` with placeholders ✅
- **AJAX**: All handlers use `check_ajax_referer()` nonce verification; admin endpoints check `current_user_can('manage_options')` ✅
- **XSS**: All template outputs use `esc_html()`, `esc_attr()`, `esc_url()` or are pre-escaped ✅
- **Route params**: IDs use `intval()`, slugs use `sanitize_title()` ✅

### Rate Limits (Groq Free Tier) — THE BLOCKER
- **llama-3.1-8b-instant**: RPM 30, RPD 14,400, **TPM 6,000**, TPD 500,000
- **llama-3.3-70b-versatile**: RPM 30, RPD 1,000, **TPM 12,000**, TPD 500,000
- Each obituary rewrite: ~900-1,400 total tokens (prompt ~400 + obituary ~200-500 + response ~300-500)
- At 6,000 TPM, maximum ~5 requests/min before token quota exhausted
- **Current plugin rate**: 1 request per 12 seconds (~5 req/min) — matches RPM but TPM is the real limit
- **Result**: Processing stops after ~15 obituaries per 5-minute cron window when cumulative tokens exceed TPM

### Validation Rules
- Rewrite must mention the deceased's last name (or first name)
- Length: 50–5,000 characters
- No LLM artifacts ("as an AI", "certainly!", "here is", etc.)
- Cross-validates extracted dates and ages
- Failed validations are logged but do not prevent future retries

### Execution Paths (v5.0.1+)
All paths use mutual-exclusion transient `ontario_obituaries_rewriter_running`:
1. **WP-Cron** (`ontario_obituaries_ai_rewrite_batch`) — 1 per call, loops up to 4 min, 12s delay
2. **Shutdown hook** (`ontario_obituaries_shutdown_rewriter`) — 1 per admin page load, 1-min throttle
3. **AJAX button** (`ontario_obituaries_ajax_run_rewriter`) — 1 per call, JS auto-repeats from frontend
4. **CLI cron** (`cron-rewriter.php`) — standalone script with file lock at `/tmp/ontario_rewriter.lock`

### Cron Integration
- After each collection (`ontario_obituaries_collection_event`), a rewrite batch is scheduled 30 seconds later
- Batch processes 1 obituary per call, then self-reschedules if more remain
- Each batch runs on the `ontario_obituaries_ai_rewrite_batch` hook
- **cPanel cron** (every 5 min): `/usr/local/bin/php /home/monaylnf/public_html/wp-cron.php >/dev/null 2>&1`

### REST Endpoints
- `GET /wp-json/ontario-obituaries/v1/ai-rewriter` — Status and stats (admin-only)
- `GET /wp-json/ontario-obituaries/v1/ai-rewriter?action=trigger` — Manual batch trigger

### Rules for AI Rewriter
- NEVER modify the original `description` field — it's the source of truth.
- The `ai_description` field is disposable — can be regenerated at any time.
- If Groq changes their API or rate limits, update the constants in class-ai-rewriter.php.
- Monitor the error log for rate limiting or validation failures.
- **v5.0.2**: Do NOT retry with fallback models on 429 errors — org-level TPM limits affect ALL models.

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

---

## Section 20 — AI Customer Chatbot (v4.5.0)

**Deployed 2026-02-13** — A sophisticated AI-powered customer service chatbot for Monaco Monuments.

### What It Does
- **Auto-greets** every visitor with a warm, professional welcome message
- **Answers questions** about monuments, headstones, pricing, materials, process
- **Directs to intake form** at https://monacomonuments.ca/contact/ — emphasizes no cost, priority queue position
- **Email forwarding** — sends customer inquiries directly to `info@monacomonuments.ca`
- **Quick-action buttons**: Get Started, Pricing, Catalog, Contact
- **Works with or without Groq API key** — falls back to smart rule-based responses if no key

### Architecture
- **PHP**: `includes/class-ai-chatbot.php` (32 KB) — Groq API integration, AJAX handlers, REST endpoints, conversation logging, email forwarding
- **CSS**: `assets/css/ontario-chatbot.css` (11 KB) — Monaco-branded dark theme (#2c3e50), mobile-responsive, floating widget
- **JS**: `assets/js/ontario-chatbot.js` (13 KB) — Chat UI, message history, typing indicators, quick actions, form validation
- **Hooks**: `wp_footer` (renders widget), `wp_enqueue_scripts` (loads assets), `rest_api_init` + `wp_ajax_*` (message handlers)
- **Option keys**: `ontario_obituaries_chatbot_settings` (config), `ontario_obituaries_chatbot_conversations` (logs)

### REST / AJAX Endpoints
- `POST /wp-json/ontario-obituaries/v1/chatbot/message` — Send message, get AI response
- `POST /wp-json/ontario-obituaries/v1/chatbot/email` — Forward conversation to business email
- `wp_ajax_ontario_chatbot_message` / `wp_ajax_nopriv_ontario_chatbot_message` — AJAX fallback
- `wp_ajax_ontario_chatbot_send_email` / `wp_ajax_nopriv_ontario_chatbot_send_email` — Email AJAX fallback

### Security
- **Rate limiting**: 1 message per 2 seconds per IP (prevents spam/abuse)
- **Nonce verification**: `check_ajax_referer('ontario_chatbot_nonce', 'nonce')` on all handlers
- **XSS protection**: 25+ `esc_html()` / `esc_attr()` calls, all output escaped
- **Input sanitization**: `sanitize_textarea_field()` on all user messages
- **No sensitive data exposure**: Groq API key never sent to frontend

### Business Configuration (hardcoded defaults, changeable via options)
- **Email**: `info@monacomonuments.ca`
- **Phone**: `(905) 392-0778`
- **Address**: `1190 Twinney Dr. Unit #8, Newmarket, ON L3Y 1C8`
- **Contact page**: `https://monacomonuments.ca/contact/`
- **Catalog page**: `https://monacomonuments.ca/catalog/`
- **Intake form messaging**: "Filling it out does not hold you to any financial cost — it simply puts you at the top of the list for customers to get serviced first."

### Rules for Future Chatbot Changes
- NEVER remove the intake form messaging — it's a core business requirement
- NEVER change the email from `info@monacomonuments.ca` without owner approval
- The chatbot MUST work without a Groq key (rule-based fallback is mandatory)
- Rate limiting MUST remain enabled — the free Groq tier has strict limits
- Test in incognito mode after any CSS/JS change (LiteSpeed caches aggressively)

---

## Section 21 — Google Ads Campaign Optimizer (v4.5.0)

**Deployed 2026-02-13** — Currently DISABLED (owner's off-season). Toggle-ready for spring.

### What It Does
- Connects to Google Ads API (account 903-524-8478)
- Fetches campaign metrics, keyword performance, search terms daily
- AI-powered analysis generates bid, budget, keyword, and ad copy recommendations
- Dashboard cards: 30-day spend, clicks, CTR, avg CPC, conversions
- Optimization score (0-100) with quick wins and warnings

### Architecture
- **PHP**: `includes/class-google-ads-optimizer.php` (43 KB)
- **API**: Google Ads REST API + Groq AI for analysis
- **Cron**: `ontario_obituaries_google_ads_analysis` — runs every 180s after settings save (when enabled)
- **Storage**: `ontario_obituaries_google_ads_credentials` (encrypted option), campaign data cached in transients

### How to Enable (for future reference)
1. WP Admin → Ontario Obituaries → Settings
2. Check "Enable Google Ads Optimizer"
3. Enter: Developer Token, OAuth2 Client ID, Client Secret, Refresh Token
4. Customer ID `903-524-8478` is pre-filled
5. Save Settings — first analysis runs in ~3 minutes

### Rules for Google Ads Changes
- NEVER store API credentials in code — they go in wp_options only
- NEVER auto-enable this feature — it requires explicit owner opt-in
- The Customer ID `903-524-8478` is Monaco Monuments' real ad account
- Respect Google Ads API rate limits and Terms of Service

---

## Section 22 — GoFundMe Auto-Linker (v4.3.0)

**Deployed 2026-02-13** — Active, auto-processing.

### What It Does
- Searches GoFundMe for memorial/funeral campaigns matching obituary records
- Uses strict 3-point verification: name + death date + location
- Adds "Support the Family" button on matched memorial pages
- Schema.org `DonateAction` added for GoFundMe links (v4.5.0 SEO enhancement)

### Stats (as of deploy)
- 725 total | 2 matched | 365 checked | 360 pending
- Batch: 20 obituaries per run, 1 search every 3 seconds

### Architecture
- **PHP**: `includes/class-gofundme-linker.php`
- **DB columns**: `gofundme_url`, `gofundme_checked_at` (added v4.3.0 migration)
- **Cron**: `ontario_obituaries_gofundme_batch` — 60s after settings save

---

## Section 23 — AI Authenticity Checker (v4.3.0)

**Deployed 2026-02-13** — Active, processing 725 never-audited records.

### What It Does
- Runs 24/7 random audits using AI to verify dates, names, locations, consistency
- 10 obituaries per cycle (8 never-audited + 2 re-checks)
- Flags issues for admin review, auto-corrects high-confidence errors
- Uses same Groq API key as AI Rewriter

### Architecture
- **PHP**: `includes/class-ai-authenticity-checker.php`
- **DB columns**: `last_audit_at`, `audit_status`, `audit_flags` (added v4.3.0 migration)
- **Cron**: `ontario_obituaries_authenticity_audit` — every 4 hours (120s after settings save)

---

## Section 25 — AI Rewriter Rate-Limit Investigation (v5.0.0–v5.0.2, 2026-02-14/15)

> **STATUS: PAUSED** — Owner decided to pause AI Rewriter development after v5.0.2
> still hit Groq free-tier limits. The plugin is stable; all other features work.

### Timeline of Attempts
| Version | PR | Delay | Batch | Model | Result |
|---------|-----|-------|-------|-------|--------|
| v4.1.0–v4.6.7 | #52-#71 | 6-15s | 3-25 | llama-3.3-70b | Worked slowly, occasional 429s |
| v5.0.0 | #72-#73 | 8s | 5 | llama-3.1-8b | Hit rate limits, switched to JSON extraction |
| v5.0.0 | #74-#76 | 8-15s | 5 | llama-3.1-8b | Still hitting limits, UI label fixes |
| v5.0.0 | #77 | 15s | 3 | llama-3.1-8b | Switched to 8B model to reduce tokens |
| v5.0.0 | #78 | 6s | 10 | llama-3.1-8b | CLI cron with 250/hr target — crashed on rate limit |
| v5.0.1 | #79 | 6s | 1 | llama-3.1-8b | 1-at-a-time + mutual exclusion — still crashed after ~6 |
| v5.0.2 | #80 | 12s | 1 | llama-3.1-8b | 12s delay + retry-after — stopped after ~15 |

### Root Cause Analysis
The fundamental issue is **Groq's free-tier token-per-minute (TPM) limit**, not requests-per-minute:
- `llama-3.1-8b-instant` TPM limit: **6,000 tokens/minute**
- Each obituary rewrite: **~900-1,400 total tokens** (system prompt ~400 + obituary text ~200-500 + JSON response ~300-500)
- At 5 requests/min (12s delay): ~5,000-7,000 tokens/min → exceeds 6,000 TPM
- The limit is **organization-wide** — switching models doesn't help (same org quota)
- The 429 response includes a `retry-after` header, but waiting that long (60-120s) makes throughput impractical

### What Was Built & Works Correctly
1. **Structured JSON extraction** (v5.0.0) — Groq returns JSON with rewritten text + extracted fields
2. **Mutual exclusion lock** (v5.0.1) — transient-based lock prevents concurrent API calls
3. **1-at-a-time processing** (v5.0.1) — all 4 execution paths process single obituaries
4. **Retry-after header parsing** (v5.0.2) — respects Groq's recommended backoff
5. **No fallback on 429** (v5.0.2) — stops wasting tokens on org-wide rate limits
6. **TRUNCATE bug fix** (v5.0.1) — fixed critical bug that wiped all obituary data on reinstall
7. **cPanel cron setup** — every 5 min via `wp-cron.php` (confirmed working)

### Potential Solutions (NOT IMPLEMENTED — for future developer)
1. **Upgrade Groq plan** — Paid tier has much higher TPM limits (easiest fix)
2. **Switch to a different free LLM API** — e.g., OpenRouter, Together.ai, Cloudflare Workers AI
3. **Reduce prompt size** — Shorter system prompt could save ~100-200 tokens per request
4. **Process fewer per window** — Process 3-4 per 5-min cron, accept ~50/hr throughput
5. **Use the 70B model** — Has 12,000 TPM (2x the 8B model), but uses more tokens per request
6. **Queue with longer delays** — 20s+ between requests, accept ~3 req/min (~180/hr)
7. **Time-spread processing** — Process 1 obituary every 2 minutes across the day (720/day)

### Files Modified in v5.0.0–v5.0.2
| File | What changed |
|------|-------------|
| `ontario-obituaries.php` | Version bump to 5.0.2, mutual exclusion transient lock for WP-Cron/shutdown/AJAX, TRUNCATE bug fix in uninstall, corrected batch scheduling |
| `includes/class-ai-rewriter.php` | batch_size=1, request_delay=12s, JSON prompt/response, structured field extraction, retry-after parsing, no fallback on 429, temperature 0.1 |
| `includes/class-ontario-obituaries.php` | Updated throughput comments |
| `includes/class-ontario-obituaries-reset-rescan.php` | Updated UI text to "1 at a time" |
| `assets/js/ontario-obituaries-reset-rescan.js` | Corrected "up to 5 obituaries" → "1 obituary" |
| `cron-rewriter.php` | batch_size=1, 12s delay, sleep(10) on failure |
| `uninstall.php` | Protected `db_version` option from deletion |

---

## Section 24 — v4.5.0 Deployment Session Log (2026-02-13)

> This section documents the complete deployment session for v4.5.0 to help
> future developers understand what was done, why, and the current state.

### What Was Accomplished (in order)
1. **Built AI Customer Chatbot** (`class-ai-chatbot.php`, `ontario-chatbot.css`, `ontario-chatbot.js`)
   - Groq-powered with rule-based fallback
   - Email forwarding to `info@monacomonuments.ca`
   - Intake form guidance with no-cost/priority-queue messaging
   - Quick-action buttons, mobile-responsive, Monaco-branded
2. **Comprehensive QA audit** of all v4.5.0 code:
   - PHP syntax checks: all 5 key files passed
   - JavaScript syntax: `ontario-chatbot.js` passed
   - Brace balance: verified on all PHP files
   - Nonce flow: validated end-to-end (PHP create → JS send → PHP verify)
   - XSS: 25+ escape calls verified
   - Rate limiting: 1 msg/2s/IP confirmed
   - No duplicate hooks
3. **Committed and pushed** to `genspark_ai_developer` branch
4. **Updated PR #58** with full description and setup steps
5. **Built deployment ZIP** (`ontario-obituaries-v4.5.0.zip`, 237 KB)
6. **Owner merged PR #58** on GitHub
7. **Owner created full site backup** via UpdraftPlus (Feb 13, 21:45 — Database + Plugins + Themes + Uploads + Others)
8. **Owner uploaded v4.5.0 ZIP** via WordPress Admin → Plugins → Add New → Upload Plugin → Replace current
9. **Owner enabled AI Chatbot** via Ontario Obituaries → Settings → Enable AI Chatbot → Save
10. **Owner disabled Google Ads** (off-season decision — toggle-ready for spring)
11. **Verified chatbot live on frontend** — chat bubble visible, greeting works, intake form link works, phone number displayed

### Deployment Method Used
- **NOT cPanel** this time — used WordPress Admin → Plugins → Upload Plugin → "Replace current with uploaded"
- This is equivalent to the cPanel method but simpler for the owner
- Full UpdraftPlus backup was created BEFORE the upload as a safety net

### Files Added in v4.5.0
| File | Size | Purpose |
|------|------|---------|
| `includes/class-ai-chatbot.php` | 32 KB | AI chatbot backend |
| `assets/css/ontario-chatbot.css` | 11 KB | Chatbot styles |
| `assets/js/ontario-chatbot.js` | 13 KB | Chatbot frontend |
| `includes/class-google-ads-optimizer.php` | 43 KB | Google Ads optimizer |

### Files Modified in v4.5.0
| File | What changed |
|------|-------------|
| `ontario-obituaries.php` | Version bump to 4.5.0, chatbot class loader, v4.5.0 migration block |
| `includes/class-ontario-obituaries.php` | Chatbot settings UI section, Google Ads settings UI, `chatbot_enabled` in sanitizer |
| `templates/seo/individual.php` | Schema.org DonateAction for GoFundMe links, version header update |

---

## Section 26 — Independent Code Audit (2026-02-16)

> **Performed by**: Independent code review (not the previous developer)
> **Scope**: Line-by-line inspection of all PHP files in the plugin
> **Finding**: The plugin has significant bugs beyond the Groq TPM rate limit.
> The previous developer's diagnosis of "code works, it's just a Groq limit"
> was **incomplete and misleading**. Multiple architectural bugs prevent the
> plugin from functioning correctly even if Groq had unlimited capacity.

### CRITICAL BUGS (4)

#### BUG-C1: Activation Cascade — Plugin Activation Publishes 5-15 Obituaries Then Crashes — ✅ FIXED (v5.0.3, PR #83)

**Location**: `ontario-obituaries.php`, `ontario_obituaries_activate()` (line ~279) and `ontario_obituaries_on_plugin_update()` (line ~3544)

**What happens**:
1. On activation, `ontario_obituaries_activate()` schedules `ontario_obituaries_initial_collection` (immediate cron event).
2. `ontario_obituaries_on_plugin_update()` runs on `init` hook and executes **all** versioned migration blocks from v3.9.0 through v5.0.2 on a fresh install (because `ontario_obituaries_deployed_version` starts empty).
3. Multiple migration blocks trigger synchronous scrapes with `usleep(500000)` pauses, HTTP HEAD requests for image validation (logo filtering), and immediate rewrite scheduling.
4. This chain consumes several minutes of execution time, publishes 5-15 obituaries via the AI rewriter, then Groq's TPM limit kicks in and everything halts with 429 errors.
5. The user sees "5-15 obituaries published, then it stops" — which is not primarily a Groq issue, it's a runaway activation pipeline.

**Why the previous developer missed this**: They focused exclusively on tuning the Groq delay (6s → 8s → 12s → 15s) across PRs #72-#80 without examining what triggers the initial burst. The burst comes from the activation cascade, not from steady-state cron.

**Root cause**: `on_plugin_update()` is a 1,721-line monolith with no fresh-install guard. Every migration block runs sequentially on first activation.

**Fix plan**:
- Add a "fresh install" guard at the top of `on_plugin_update()` — if no `ontario_obituaries_deployed_version` exists, set it to current version and skip all historical migrations.
- Move all scrape triggers to deferred cron events (never synchronous on `init`).
- Split the function into versioned migration files for maintainability.

---

#### BUG-C2: Display Pipeline Deadlock — Obituaries Invisible Without AI Rewrite — ✅ FIXED (v5.0.4, PR #84)

**Location**: `includes/class-ontario-obituaries-display.php`, `includes/class-ontario-obituaries-seo.php`

**What happened**:
1. The scraper inserts obituary records with `status = 'pending'`.
2. `get_obituaries()` and all SEO queries added `WHERE status = 'published'` to every query.
3. Records only became `published` after the AI rewriter successfully processed them.
4. The AI rewriter was rate-limited (Groq TPM), so only ~15 records got rewritten per run.
5. **Result**: 710+ of 725 obituaries were invisible on the frontend.

**Fix applied (v5.0.4)**:
- Removed `AND status = 'published'` from all 5 display queries and all 13 SEO queries (18 total).
- Records are now visible as soon as they are scraped, using their original factual data.
- The AI rewrite pipeline continues to run — when complete, `ai_description` replaces `description` in display.
- The `status` column still tracks progress (`pending` → `published`) but no longer gates visibility.
- This preserves the core workflow (SCRAPE → AI VIEW → AI REWRITE → PUBLISH) per RULE 14.
- Unchanged: AI rewriter, authenticity checker, GoFundMe linker, IndexNow — all still operate only on `published` records.
- **REST API hardening (BUG-H8)**: REST endpoints now require `manage_options` capability (admin-only). REST API also applies `status='published'` filter internally, preserving the original API contract. Display layer's `get_obituaries()` and `count_obituaries()` accept an optional `status` parameter validated via the centralized `ontario_obituaries_is_valid_status()` helper (single source of truth defined in `ontario-obituaries.php`). Backfill query also derives its IN-list from `ontario_obituaries_valid_statuses()` — adding a new status requires updating one function.
- **JSON-LD XSS hardening**: Added `JSON_HEX_TAG` flag to all 6 `wp_json_encode()` calls inside `<script type="application/ld+json">` blocks to prevent `</script>` breakout from scraped content. See RULE 14 section for full details.
- **SQL cleanup**: Removed blank-line artifacts left by status-gate removal; all queries are now cleanly formatted.
- **Status validation**: Display layer enforces strict `in_array('pending','published')` whitelist on optional status filter. Backfill uses `LOWER(COALESCE())` for collation safety with `LIMIT 500` for bounded execution.

---

#### BUG-C3: Non-Idempotent Migrations — Re-Run on Every Reinstall — ✅ FIXED (v5.0.3, PR #83)

**Location**: `ontario-obituaries.php`, `ontario_obituaries_on_plugin_update()` and `uninstall.php`

**What happens**:
1. `on_plugin_update()` compares `ontario_obituaries_deployed_version` (stored in `wp_options`) against each migration block's version.
2. `uninstall.php` does **not** delete `ontario_obituaries_deployed_version` from the options table.
3. However, on a truly fresh install (new WordPress or new site), the option doesn't exist.
4. When the option is empty/missing, ALL migration blocks from v3.9.0 onward execute — including ones that run synchronous HTTP requests, schedule cron events, and modify database schema.
5. Each reinstall triggers the same cascade as BUG-C1 because there's no "I'm a fresh install, skip old migrations" guard.

**Additional problem**: Some migration blocks (e.g., v4.0.1 logo filter) perform HTTP HEAD requests to CDN URLs for every record in the database. On a site with 725+ records, this is ~725 HTTP requests during `init`.

**Fix plan**:
- On fresh install (no deployed version): Set version to current and skip all migrations.
- Add idempotency guards to each migration block (check if the work has already been done).
- `uninstall.php` should either delete the version option (for clean reinstall) or leave it (to prevent re-migration). Choose one strategy and document it.

---

#### BUG-C4: Duplicate Cleanup Runs on Every Page Load — ✅ FIXED (v5.0.4, PR #85)

**Location**: `ontario-obituaries.php`, `ontario_obituaries_cleanup_duplicates()` + `ontario_obituaries_maybe_cleanup_duplicates()`

**What happened** (pre-fix):
1. `ontario_obituaries_cleanup_duplicates()` was hooked to WordPress `init` at priority 5, meaning it fired on **every single page load** (frontend and admin).
2. The function runs 3 passes: exact GROUP BY, fuzzy full-table SELECT, and name-only full-table SELECT across the entire `ontario_obituaries` table (725+ rows).
3. For each duplicate group found, it loads all records, compares them, picks a winner, enriches it, and deletes the losers.
4. This added measurable DB load to every page request — a DoS vector on shared hosting.

**Fix applied (v5.0.4)**:
1. **Removed** `add_action('init', 'ontario_obituaries_cleanup_duplicates', 5)`.
2. **Added** `ontario_obituaries_maybe_cleanup_duplicates($force)` — a throttled wrapper using WP-native `add_option()`/`get_option()` (respects object-cache and notoptions-cache, unlike raw `INSERT IGNORE` into `wp_options`). Lock value is JSON `{ts,state}` distinguishing `running` (actively executing) from `done` (cooldown). Corrupt/legacy JSON treated as stale-running (safe fallback — cleared via stale_ttl). No recursive retry on stale-lock — returns false; next scheduled event retries. Cooldown timestamp written only on success (not in `finally` block), so fatal errors don't create false cooldowns.
3. **Daily cron**: `ontario_obituaries_dedup_daily` registered on activation and `on_plugin_update()`, cleared on deactivation. Fires once per day as a safety net.
4. **Post-scrape**: `ontario_obituaries_scheduled_collection()` calls `ontario_obituaries_schedule_dedup_once()` — defers cleanup to a one-shot cron event 10s later using a **separate** hook `ontario_obituaries_dedup_once` (so `wp_clear_scheduled_hook` on one doesn't cancel the other). Guard: `wp_next_scheduled()` prevents duplicate events; lock prevents double execution.
5. **Post-REST-cron**: `/cron` endpoint uses the same `ontario_obituaries_schedule_dedup_once()` — no inline DB work, preventing DoS via the public endpoint and cron-queue churn (repeated hits are no-ops while an event is pending).
6. **Admin rescan**: `class-ontario-obituaries-reset-rescan.php` calls `ontario_obituaries_maybe_cleanup_duplicates(true)` — `$force=true` bypasses the 1-hour cooldown but NOT a 60-second refractory window (prevents admin click-spam from triggering repeated full-table scans). The underlying lock still prevents overlap with concurrent cron runs.
7. **Lock lifecycle**: Stale "running" locks auto-cleared after 1 hour. Lock + throttle-log option cleared on deactivate + on_plugin_update. Both `dedup_daily` and `dedup_once` hooks cleared on deactivate and during plugin update.
8. **Logging**: Errors always logged; stale-lock warnings logged; success at 'debug' level; throttled hits silent except once-per-day heartbeat (prevents stuck-lock blindness without log spam).
9. **Deterministic merge** (verified, rounds 2–3): All 3 passes produce deterministic candidate sets:
   - Pass 1: `GROUP BY name, date_of_death` — set-based, same data → same groups.
   - Pass 2: `SELECT ... ORDER BY id` → PHP grouping by `normalize_name()|date_of_death` — deterministic key.
   - Pass 3: `SELECT ... ORDER BY id` → PHP grouping by `normalize_name()` only — deterministic key.
   - Both `merge_duplicate_group()` and `merge_duplicate_ids()` use `ORDER BY desc_len DESC, id ASC` — `id ASC` tiebreaker ensures the same winner is picked even under concurrent runs. DELETE on already-deleted IDs is a no-op (0 rows affected).
10. **JSON decode self-healing** (verified, round 3): Corrupt option → fallback `{ts:0, running}` → age exceeds `stale_ttl` → `delete_option` → return false (no run). Next call: option absent → `add_option` succeeds → cleanup runs → writes valid JSON → done state. Self-heals in exactly 2 calls. Continuous external corruption (astronomically unlikely given unique key name) bounded by cooldown: at worst one extra run, then 1-hour cooldown applies.

**Accepted residual risks** (documented, not fixable without external dependencies):
- `add_option()` is not a true mutex under persistent object cache with sub-ms race windows. Mitigated: dedup merge is deterministic (ORDER BY id ASC tiebreaker + deterministic grouping), so concurrent runs converge to the same state.
- `wp_next_scheduled()` in `schedule_dedup_once()` is non-atomic; duplicate one-shot events possible. Mitigated: lock prevents double execution.
- `stale_ttl = HOUR_IN_SECONDS` is a time assumption; extremely slow DBs could exceed it. Mitigated: 120× safety margin over observed execution time (<30s); PHP `max_execution_time` kills the process before 1h.
- Clearing `dedup_once` on plugin update drops an imminent one-shot run. Acceptable: `on_plugin_update()` deletes the lock, so the next daily cron or scrape will re-trigger dedup promptly.
- 60s force-mode refractory still permits per-minute admin scans. Bounded: 725 rows × <30s = tolerable shared-hosting load; raising refractory regresses admin-expects-immediate UX.
- Public `/cron` endpoint can trigger collection (the expensive part) regardless of dedup scheduling. Tracked as BUG-H2 scope (Sprint 2 — uninstall/auth hardening).
- Throttle heartbeat writes one `update_option` per day. Negligible vs existing `ontario_obituaries_log()` DB writes on every log call.
- `ontario_obituaries_dedup_lock` and `ontario_obituaries_dedup_throttle_log` persist as `autoload=no` rows if plugin is deleted without deactivation. Tracked in BUG-H2 (uninstall cleanup).

---

### HIGH-SEVERITY BUGS (7)

#### BUG-H1: Nonsense Rate Calculation in cron-rewriter.php

**Location**: `cron-rewriter.php`, line ~224

**What happens**:
- After processing, the script calculates a "rate" by dividing processed count by elapsed time.
- If `$processed` is 0, or if the elapsed time calculation is wrong, the rate is nonsensical.
- The logged rate misleads developers about actual throughput.

**Fix**: Guard division by zero; log actual tokens consumed if available from Groq response headers.

---

#### BUG-H2: Incomplete Uninstall — API Keys and Cron Hooks Persist — ✅ FIXED (v5.0.5, PR #86)

**Location**: `uninstall.php`

**What happened** (pre-fix):
- The uninstall script deleted only 11 of 22+ plugin options, missing:
  - `ontario_obituaries_groq_api_key` (Groq API key — sensitive!)
  - `ontario_obituaries_chatbot_settings`
  - `ontario_obituaries_google_ads_credentials` (Google Ads OAuth tokens — very sensitive!)
  - `ontario_obituaries_indexnow_key`
  - `ontario_obituaries_leads` (lead capture data)
  - `ontario_obituaries_deployed_version`, reset/rescan session state options
  - BUG-C4 lock options (`dedup_lock`, `dedup_throttle_log`)
- Only 2 of 8 cron hooks were cleared.
- Only 4 of 8 transients were deleted.

**Security risk**: API keys for Groq and Google Ads remained in `wp_options` after uninstall. If the site is compromised later, these keys are exposed.

**Fix applied (v5.0.5)**:
1. **Options**: Now deletes all 22 plugin options (grouped by category in code for auditability). 3 sensitive API keys (`groq_api_key`, `google_ads_credentials`, `indexnow_key`) explicitly listed. `db_version` intentionally preserved to prevent migration re-run on reinstall.
2. **Transients**: Now deletes all 8 plugin transients (added `last_cron_spawn`, `rewriter_running`, `rewriter_scheduling`, `rewriter_throttle`).
3. **Cron hooks**: Now clears all 8 scheduled hooks (added `ai_rewrite_batch`, `gofundme_batch`, `authenticity_audit`, `google_ads_analysis`, `dedup_daily`, `dedup_once`).
4. **Audit trail**: Inventory method documented in code comments (`grep -rn` commands + audit date).
5. **Versioned dedup flags**: LIKE query retained to catch dynamic `ontario_obituaries_dedup_*` option names.

---

#### BUG-H3: Duplicate Index Creation in v4.3.0 Migration — ✅ FIXED (v5.0.3, PR #83)

**Location**: `ontario-obituaries.php`, v4.3.0 migration block (lines ~3532-3534)

**What happens**:
- The migration adds indexes on `gofundme_checked_at` and `last_audit_at`.
- If the migration runs twice (due to BUG-C3), MySQL may throw "Duplicate key name" errors.
- No `IF NOT EXISTS` guard or prior existence check.

**Fix**: Check if index exists before creating, or use `CREATE INDEX IF NOT EXISTS` (MySQL 8+) or wrap in a try/catch.

---

#### BUG-H4: Possible Undefined $result Variable

**Location**: `ontario-obituaries.php`, line ~1208

**What happens**:
- In certain conditional branches (e.g., when scraping is skipped), `$result` may be used without having been assigned.
- This would generate a PHP Notice or Warning, and downstream code relying on `$result` would behave unpredictably.

**Fix**: Initialize `$result = null;` (or appropriate default) before the conditional block.

---

#### BUG-H5: Premature Throttling in Shutdown Rewriter

**Location**: `ontario-obituaries.php`, line ~1293

**What happens**:
- The shutdown hook checks a throttle transient (`ontario_obituaries_last_shutdown_rewrite`) before processing.
- The transient is set for 60 seconds, meaning the shutdown rewriter can only fire once per minute.
- But the check happens BEFORE any processing occurs, so even if the last attempt failed (e.g., Groq 429), the next admin page load is still throttled.
- Combined with the mutual exclusion lock, this means the shutdown rewriter is effectively dead after the first Groq rate limit hit.

**Fix**: Set the throttle transient AFTER successful processing, not before. On failure, allow immediate retry on next page load.

---

#### BUG-H6: Over-Permissive Domain Lock

**Location**: `ontario-obituaries.php`, line ~38 (domain check function)

**What happens**:
- The domain lock uses `strpos($current_host, $authorized_domain)` to check if the current host is authorized.
- `strpos()` checks for substring containment, not exact match.
- A malicious domain like `evilmonacomonuments.ca` or `monacomonuments.ca.attacker.com` would pass the check.

**Fix**: Use `$current_host === $authorized_domain` (exact match) or parse the hostname and compare the registered domain.

---

#### BUG-H7: Stale Cron Hooks Survive Uninstall — ✅ FIXED (v5.0.5, PR #86)

**Location**: `uninstall.php`

**What happened** (pre-fix):
- Only `ontario_obituaries_collection_event` and `ontario_obituaries_initial_collection` were cleared.
- 6 cron hooks persisted after uninstall: `ai_rewrite_batch`, `gofundme_batch`, `authenticity_audit`, `google_ads_analysis`, `dedup_daily`, `dedup_once`.
- These orphaned hooks would fire WordPress callbacks that no longer exist, generating PHP fatal errors.

**Fix applied (v5.0.5)**: All 8 scheduled cron hooks are now cleared in a documented loop. Inventory method (grep command) recorded in code comments for future audits.

---

### MEDIUM-SEVERITY ISSUES (6)

#### BUG-M1: Shared Groq API Key — No Rate Coordination

**Location**: `class-ai-rewriter.php`, `class-ai-chatbot.php`, `class-ai-authenticity-checker.php`

**What happens**:
- Three separate classes all use the same Groq API key (`ontario_obituaries_groq_api_key`).
- None of them coordinate their API usage. Each has its own delay/throttle logic.
- If a visitor uses the chatbot while the AI rewriter is processing, both consume from the same 6,000 TPM quota.
- The authenticity checker (10 requests per 4-hour cycle) further compounds this.

**Fix**: Implement a shared rate limiter (e.g., a transient-based token bucket) that all three consumers check before making API calls.

---

#### BUG-M2: Misleading Documentation (CORRECTED)

**Location**: This file (`PLATFORM_OVERSIGHT_HUB.md`) and `DEVELOPER_LOG.md`

**What happened**:
- The previous developer wrote "the plugin is stable and all other features work correctly" and "the AI Rewriter issue is a Groq free-tier limitation, not a code bug."
- This was incorrect. The activation cascade (BUG-C1), display deadlock (BUG-C2), init-phase duplicate cleanup (BUG-C4), and several other bugs exist independently of the Groq TPM limit.
- This misleading documentation caused the project to be PAUSED instead of fixed.

**Status**: CORRECTED by this audit (2026-02-16). This document now reflects the true state.

---

#### BUG-M3: Monolithic on_plugin_update() — 1,721 Lines — ✅ FIXED (v5.0.3, PR #83)

**Location**: `ontario-obituaries.php`, `ontario_obituaries_on_plugin_update()`

**What happens**:
- Every version migration from v3.9.0 to v5.0.2 is a sequential block inside one gigantic function.
- Adding new migrations is error-prone — a misplaced brace or missing version guard breaks all subsequent migrations.
- The function mixes schema changes, data repairs, HTTP calls, cron scheduling, and cache purging.

**Fix**: Refactor into a migration runner pattern:
1. Each migration is a separate file in `includes/migrations/` (e.g., `migration-4.2.2.php`).
2. A runner in `on_plugin_update()` scans the directory, sorts by version, and runs any migration newer than the deployed version.
3. Each migration file is self-contained and idempotent.

---

#### BUG-M4: Risky Name-Only Dedup Pass

**Location**: `ontario-obituaries.php`, 3rd dedup pass in `ontario_obituaries_cleanup_duplicates()`

**What happens**:
- The 3rd dedup pass matches records by normalized name alone (no date comparison).
- Two different people with the same name (e.g., "John Smith" who died in 2024 and another "John Smith" who died in 2025) could be incorrectly merged/deleted.
- This is especially risky for common names.

**Fix**: Add a date-range guard (e.g., only merge name-only matches if death dates are within 30 days) or remove the name-only pass entirely.

---

#### BUG-M5: Activation Race Conditions

**Location**: `ontario-obituaries.php`, multiple `wp_schedule_single_event()` calls

**What happens**:
- During activation and migration, multiple cron events are scheduled in quick succession (collection, rewrite batch, GoFundMe batch, authenticity audit).
- If WP-Cron fires immediately (e.g., on the next page load), multiple scrape and API operations can overlap.
- The mutual exclusion lock only protects the rewriter — not the scraper, GoFundMe linker, or authenticity checker.

**Fix**: Add a staggered scheduling pattern (e.g., collection at +0s, rewrite at +300s, GoFundMe at +600s) and implement transient locks for all API-consuming processes.

---

#### BUG-M6: Unrealistic Throughput Comments

**Location**: `class-ai-rewriter.php`, `cron-rewriter.php`, `class-ontario-obituaries.php`

**What happens**:
- Code comments claim "~200 rewrites/hour" or "~250/hour" throughput.
- Actual math: 12s delay × 5 req/min = 300 req/hour theoretical, but TPM limit of 6,000 tokens/min with ~1,100 tokens/req = ~5.4 req/min max = ~324 req/hour theoretical.
- In practice, the plugin processes ~15 per 5-min window before TPM is exhausted, then waits for the next window. Real throughput is far lower.

**Fix**: Update all comments to state actual observed throughput (~15 per 5-min cron window, ~180/hour maximum theoretical).

---

## Section 27 — Systematic Bug Fix To-Do List (2026-02-16)

> **Purpose**: Step-by-step plan to fix every bug identified in Section 26.
> Each item maps to a specific bug ID and includes file locations, the fix
> description, and which PR category it belongs to (per Rule 8: one concern per PR).
>
> **Priority**: CRITICAL items must be fixed before any other development.
> HIGH items should follow. MEDIUM items can be batched into improvement PRs.

### Overall Progress: 8 of 23 tasks complete (35%)

| Category | Total | Done | Remaining |
|----------|-------|------|-----------|
| Sprint 1 (Critical) | 6 | **6 (tasks 1,2,3,4,5,6)** | **0 — SPRINT COMPLETE** |
| Sprint 2 (High) | 8 | 4 (tasks 8,9,10,13b) | 4 (tasks 7,11,12,13) |
| Sprint 3 (Medium) | 5 | 1 (task 15) | 4 (tasks 14,16,17,18) |
| Sprint 4 (Groq TPM) | 4 | 0 | 4 (tasks 19,20,21,22) |
| **Total** | **23** | **11** | **12** |

> **Bugs resolved**: BUG-C1 ✅, BUG-C2 ✅, BUG-C3 ✅, BUG-C4 ✅, BUG-H2 ✅, BUG-H3 ✅, BUG-H7 ✅, BUG-H8 ✅, BUG-M3 ✅ (C1/C3/H3/M3 via PR #83 v5.0.3; C2/H8 via PR #84 v5.0.4; C4 via PR #85 v5.0.4; H2/H7 via PR #86 v5.0.5)
> **Sprint 1 COMPLETE.** Sprint 2 in progress: 4/8 done. Next: BUG-H1, H4, H5, H6.

### Sprint 1: Critical Fixes (must-fix before plugin is usable)

| # | Bug ID | PR Category | Task | Files to Modify | Est. Effort |
|---|--------|-------------|------|-----------------|-------------|
| 1 | BUG-C1 | Infrastructure | ~~**Add fresh-install guard to `on_plugin_update()`**~~ ✅ **DONE (PR #83, v5.0.3)** — All historical migration blocks removed entirely. Function reduced from ~1,721 to ~100 lines. Fresh installs hit only idempotent operations. | `ontario-obituaries.php` | 30 min |
| 2 | BUG-C1 | Infrastructure | ~~**Move all scrape triggers to deferred cron**~~ ✅ **DONE (PR #83, v5.0.3)** — All 5 synchronous HTTP blocks removed (v3.10.0 collect, v3.15.3 usleep enrichment, v3.16.0/1 collect, v4.0.1 wp_remote_head loop). Data freshness handled by existing cron + heartbeat. | `ontario-obituaries.php` | 1 hour |
| 3 | BUG-C2 | Display fix | ~~**Remove `status='published'` gate from display queries**~~ ✅ **DONE (PR #84, v5.0.4)** — Removed `AND status='published'` from 5 display queries and 13 SEO queries (18 total). Records now visible as soon as scraped. AI rewrite enhances description in background. Core workflow preserved per RULE 14. | `includes/class-ontario-obituaries-display.php`, `includes/class-ontario-obituaries-seo.php` | 45 min |
| 4 | BUG-C2 | Display fix | ~~**Update templates to gracefully handle missing `ai_description`**~~ ✅ **DONE (PR #84, v5.0.4)** — Templates already fall back to `description` when `ai_description` is absent. SEO class prefers `ai_description` for indexing, falls back to `description`. No template changes needed. | `templates/obituaries.php`, `templates/seo/individual.php` | 30 min |
| 5 | BUG-C3 | Infrastructure | ~~**Make migrations idempotent**~~ ✅ **DONE (PR #83, v5.0.3)** — Historical migration blocks removed; remaining operations are all naturally idempotent (rewrite flush, cache purge, transient delete, registry upsert, cron schedule). `deployed_version` write moved to end of function for safe retry on partial failure. | `ontario-obituaries.php` | 1.5 hours |
| 6 | BUG-C4 | Infrastructure | ~~**Move duplicate cleanup off `init` hook**~~ ✅ **DONE (PR #85, v5.0.4)** — Removed `add_action('init', ...)`. Lock uses WP-native `add_option()`/`get_option()` with JSON `{ts,state}` (not raw INSERT IGNORE). Separate hooks: `ontario_obituaries_dedup_daily` (recurring) + `ontario_obituaries_dedup_once` (one-shot post-scrape). Admin rescan uses `$force=true` to bypass cooldown. No recursive retry; stale-lock cleared after 1h. Cooldown written only on success. Logging: errors always, stale warnings, success at debug, throttled silent. | `ontario-obituaries.php`, `class-ontario-obituaries-reset-rescan.php` | 30 min |

### Sprint 2: High-Severity Fixes

| # | Bug ID | PR Category | Task | Files to Modify | Est. Effort |
|---|--------|-------------|------|-----------------|-------------|
| 7 | BUG-H1 | Infrastructure | **Fix rate calculation in cron-rewriter.php** — Guard division by zero; calculate rate from elapsed time and token count, not just processed count. | `cron-rewriter.php` | 15 min |
| 8 | BUG-H2 | Security | ~~**Complete uninstall cleanup**~~ ✅ **DONE (PR #86, v5.0.5)** — `uninstall.php` now deletes all 22 plugin options (3 sensitive API keys), 8 transients, and clears all 8 cron hooks. Inventory method documented in code. | `uninstall.php` | 30 min |
| 9 | BUG-H7 | Security | ~~**Clear ALL cron hooks on uninstall**~~ ✅ **DONE (PR #86, v5.0.5)** — All 8 cron hooks now cleared (was 2). Inventory method documented in code. | `uninstall.php` | 15 min |
| 10 | BUG-H3 | Infrastructure | ~~**Guard duplicate index creation**~~ ✅ **DONE (PR #83, v5.0.3)** — v4.3.0 migration block removed entirely. Index creation is now handled exclusively by `ontario_obituaries_activate()` which already has existence checks. | `ontario-obituaries.php` | 15 min |
| 11 | BUG-H4 | Infrastructure | **Initialize $result variable** — Add `$result = null;` before the conditional block at line ~1208. | `ontario-obituaries.php` | 5 min |
| 12 | BUG-H5 | Infrastructure | **Fix shutdown rewriter throttle** — Move transient set to AFTER successful processing. On failure, delete the throttle transient to allow immediate retry. | `ontario-obituaries.php` | 20 min |
| 13 | BUG-H6 | Security | **Fix domain lock to exact match** — Replace `strpos()` with strict `===` comparison against `parse_url(home_url(), PHP_URL_HOST)`. | `ontario-obituaries.php` | 15 min |
| 13b | BUG-H8 | Security | ~~**Harden REST API auth + preserve contract**~~ ✅ **DONE (PR #84, v5.0.4)** — Replaced `__return_true` with `manage_options` capability check on both REST endpoints. Added `status='published'` filter to REST queries so API contract is unchanged. Display layer `get_obituaries()`/`count_obituaries()` now accept optional `status` param validated via centralized `ontario_obituaries_is_valid_status()` helper. Backfill derives IN-list from `ontario_obituaries_valid_statuses()` (collation-safe, batched LIMIT 500). Single source of truth — adding a status requires updating one function. | `class-ontario-obituaries.php`, `class-ontario-obituaries-display.php`, `ontario-obituaries.php` | 30 min |

### Sprint 3: Medium-Severity Improvements

| # | Bug ID | PR Category | Task | Files to Modify | Est. Effort |
|---|--------|-------------|------|-----------------|-------------|
| 14 | BUG-M1 | Infrastructure | **Implement shared Groq rate limiter** — Create a `class-groq-rate-limiter.php` singleton that tracks cumulative token usage in a transient. All three consumers (rewriter, chatbot, authenticity) check it before API calls. | New file + 3 class modifications | 2 hours |
| 15 | BUG-M3 | Infrastructure | ~~**Refactor migrations into separate files**~~ ✅ **DONE (PR #83, v5.0.3)** — Superseded by complete removal of historical migration blocks. Function reduced from ~1,721 to ~100 lines. Future migrations follow documented rules in the function docblock (no sync HTTP, idempotent, check-before-ALTER). | `ontario-obituaries.php` | 4 hours |
| 16 | BUG-M4 | Data integrity | **Add date guard to name-only dedup** — In the 3rd dedup pass, require death dates to be within 30 days of each other (or both empty) before merging by name alone. | `ontario-obituaries.php` | 30 min |
| 17 | BUG-M5 | Infrastructure | **Stagger cron scheduling** — Add offsets to all `wp_schedule_single_event()` calls: collection +0s, rewrite +300s, GoFundMe +600s, authenticity +900s. Add transient locks for scraper and GoFundMe. | `ontario-obituaries.php`, `includes/class-ontario-obituaries.php` | 45 min |
| 18 | BUG-M6 | Documentation | **Correct all throughput comments** — Find and update all references to "200/hour", "250/hour", "360/hour" to reflect actual ~15 per 5-min window. | Multiple files | 15 min |

### Sprint 4: Groq TPM Resolution (original blocked item)

| # | Bug ID | PR Category | Task | Files to Modify | Est. Effort |
|---|--------|-------------|------|-----------------|-------------|
| 19 | — | Infrastructure | **Evaluate alternative free LLM APIs** — Test OpenRouter, Together.ai, Cloudflare Workers AI for higher free-tier limits. | Research + `class-ai-rewriter.php` | 2 hours |
| 20 | — | Infrastructure | **Reduce prompt token usage** — Trim system prompt to <200 tokens. Use a compressed instruction format. | `class-ai-rewriter.php` | 1 hour |
| 21 | — | Infrastructure | **Implement time-spread processing** — Process 1 obituary every 2 minutes across the day (720/day) instead of batching in 5-min windows. | `class-ai-rewriter.php`, `ontario-obituaries.php` | 1 hour |
| 22 | — | Owner action | **Upgrade Groq plan** — Paid tier provides 10x+ higher TPM limits. Easiest fix if budget allows. | Admin only | N/A |

### Execution Order

1. **Start with Sprint 1** — These fixes unblock the plugin's core functionality.
2. **Sprint 2 immediately after** — Security issues (API key cleanup, domain lock) are time-sensitive.
3. **Sprint 3 when time allows** — Architectural improvements that reduce future risk.
4. **Sprint 4 is a business decision** — Owner chooses between upgrading Groq or switching APIs.

### PR Mapping (per Rule 8: one concern per PR)

| PR | Category | Bug IDs Covered | Description |
|----|----------|-----------------|-------------|
| PR-A (#83) | Infrastructure | BUG-C1, BUG-C3 | ✅ **MERGED** — Activation cascade fix: removed all historical migration blocks (1,663 lines). |
| PR-B (#84) | Display fix + Security | BUG-C2, BUG-H8 | ✅ **MERGED** — Display pipeline fix: removed `status='published'` gate from 18 queries (5 display + 13 SEO). REST API hardened: `manage_options` capability check + published-only filter. JSON-LD XSS hardening (JSON_HEX_TAG on 6 sinks). Status whitelist validation. Core workflow preserved per RULE 14. |
| PR-C (#85) | Infrastructure | BUG-C4 | **PENDING** — Duplicate cleanup: removed `init` hook, added daily cron (`dedup_daily`) + one-shot post-scrape cron (`dedup_once`). Lock via WP-native `add_option()`/`get_option()` with JSON `{ts,state}`. No recursive retry; stale auto-clear 1h; cooldown 1h; admin `$force=true`. Sprint 1 critical bugs now 100% complete. |
| PR-D (#86) | Security | BUG-H2, BUG-H7 | **PENDING** — Complete uninstall cleanup: 22 options (3 sensitive API keys), 8 transients, 8 cron hooks. Inventory method documented. |
| PR-E | Security | BUG-H6 | Security hardening: fix domain lock to exact match |
| PR-F | Infrastructure | BUG-H1, BUG-H4, BUG-H5 | Minor infrastructure fixes: rate calc, init vars, throttle fix |
| PR-F | Infrastructure | BUG-M1, BUG-M5 | Rate limiter: shared Groq coordinator + staggered scheduling |
| PR-G | Data integrity | BUG-M4 | Dedup safety: date guard on name-only pass |
| PR-H | Infrastructure | BUG-M3 | ✅ **SUPERSEDED by PR #83** — Migration blocks removed entirely instead of refactored into files. |
| PR-I | Documentation | BUG-M2, BUG-M6 | Documentation corrections: throughput comments, Hub updates |
