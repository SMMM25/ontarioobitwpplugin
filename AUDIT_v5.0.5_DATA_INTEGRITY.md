# Ontario Obituaries v5.0.5 — Data Integrity Audit Plan

**Date**: 2026-02-16
**Plugin version**: 5.0.5 (deployed from PR #86, commit b1e9807)
**Site**: monacomonuments.ca
**Workflow invariant**: SCRAPE → AI REVIEW → REWRITE → PUBLISH

---

## CRITICAL CONTEXT: How the AI Rewriter Works

Before running any checks, understand what the code actually does (verified from `class-ai-rewriter.php`):

### What the AI rewriter WRITES to the database

The rewriter processes records with `status='pending'`. For each record, Groq returns a JSON object with structured fields + rewritten prose. The code then:

1. **Always writes**: `ai_description` (the rewritten prose) and `status = 'published'`
2. **Conditionally overwrites** (only if Groq returned a non-empty value that passes sanity checks):
   - `date_of_death` — only if valid YYYY-MM-DD, not future, not before 1900
   - `date_of_birth` — only if valid YYYY-MM-DD, before death date, after 1880
   - `age` — only if 0-130
   - `location` — only if 2-60 chars, no code/garbage
   - `funeral_home` — only if 3-150 chars
   - `city_normalized` — only if it was previously empty

3. **Never writes/changes**: `name`, `source_url`, `source_domain`, `description` (original scraped text), `image_url`, `created_at`

### Key implication for auditing

The AI rewriter **can correct** date_of_death, date_of_birth, age, location, and funeral_home — it is designed to fix regex extraction errors by reading the original obituary text. This means:

- A mismatch between DB structured fields and the original `description` text could be an **AI correction** (intentional, correct) or an **AI hallucination** (wrong).
- The audit must compare DB fields against the **original source page** (via `source_url`), not just the `description` column, because the description may itself have been truncated or incomplete.

### Validation already in the code (lines 881-972)

Before publishing, the rewriter validates:
- Name (last name or first name must appear in rewritten text)
- Death year and day must appear in rewritten text
- Age must appear in rewritten text
- Location must appear in rewritten text
- No LLM artifacts ("as an AI", "here is", etc.)

---

## SECTION A: Site-Level Sanity (run first)

### A1. Plugin Version Check

**Where**: WP Admin → Plugins screen
**Expected**: Ontario Obituaries — Version 5.0.5

### A2. No Fatal Errors

**Where**: WP Admin → any admin page (Dashboard, Plugins, Settings)
**How**: If `WP_DEBUG` is enabled, check for PHP errors. Otherwise, confirm no white screen.
**Also check**: Load the public obituaries page (monacomonuments.ca/obituaries/ or equivalent) — confirm it renders.

### A3. Cron Schedules Exist

**SQL (read-only, run in phpMyAdmin or WP-CLI)**:

```sql
-- Check that cron hooks are scheduled
SELECT option_name, LEFT(option_value, 200) AS snippet
FROM wp_options
WHERE option_name = 'cron'
LIMIT 1;
```

Then search the output for these hook names:
- `ontario_obituaries_collection_event` (scraper)
- `ontario_obituaries_ai_rewrite_batch` (rewriter)
- `ontario_obituaries_dedup_daily` (dedup)

**Alternative (WP Admin)**: Tools → Ontario Obituaries Debug tab → check "Last Collection" and "Pending" count.

### A4. Last Collection Timestamp

```sql
SELECT option_value FROM wp_options
WHERE option_name = 'ontario_obituaries_last_collection';
```

**Expected**: A recent Unix timestamp or date string. If it's older than your configured frequency, the scraper may not be running.

### A5. Record Counts Overview

```sql
SELECT
    status,
    COUNT(*) AS total,
    SUM(CASE WHEN ai_description IS NOT NULL AND ai_description != '' THEN 1 ELSE 0 END) AS has_ai_desc,
    SUM(CASE WHEN suppressed_at IS NOT NULL THEN 1 ELSE 0 END) AS suppressed
FROM wp_ontario_obituaries
GROUP BY status
ORDER BY status;
```

**Expected**:
- `published` rows should ALL have `has_ai_desc > 0` (that's the invariant)
- `pending` rows should have `has_ai_desc = 0`
- `suppressed` count should match known suppressions

---

## SECTION B: Published-Only Integrity Checks

### B1. Pull the 25 Most Recent Published Records

```sql
SELECT
    id,
    name,
    date_of_birth,
    date_of_death,
    age,
    funeral_home,
    location,
    city_normalized,
    source_url,
    source_domain,
    LEFT(description, 500) AS description_excerpt,
    LEFT(ai_description, 500) AS ai_description_excerpt,
    status,
    suppressed_at,
    created_at
FROM wp_ontario_obituaries
WHERE status = 'published'
  AND ai_description IS NOT NULL
  AND ai_description != ''
  AND suppressed_at IS NULL
ORDER BY created_at DESC
LIMIT 25;
```

**Copy the full result into a spreadsheet for the audit.**

### B2. Confirm ai_description Is What the Frontend Shows

The code (verified in templates):
- `templates/obituaries.php` line 143: `if (!empty($obituary->ai_description)) $display_desc = $obituary->ai_description;`
- `templates/obituary-detail.php` line 92: same pattern
- `templates/seo/individual.php` line 262: same pattern
- SEO class (meta description, OG tags, schema): same pattern

**Check**: For 3 of the 25 records, open the public obituary page and view source. The text shown should match `ai_description`, NOT `description`.

### B3. Per-Obituary Factuality Check (for each of the 25)

For each record, verify these fields **have not been incorrectly altered by the AI**:

| Field | How to Check | PASS criteria |
|-------|-------------|---------------|
| `name` | AI cannot write this field. Compare DB `name` to original `description` text. | Name in DB matches original scrape. No change expected. |
| `date_of_death` | Compare DB value to the text in `description` (original scrape). | If Groq corrected it, the new value must match what the original obituary text actually says. |
| `date_of_birth` | Same as above. | Same — correction must match original text. Many obituaries omit this (NULL is fine). |
| `age` | Compare DB value to `description` text. | Must match what the original says ("aged 85", "in her 86th year" = age 85). |
| `location` | Compare DB value to `description` text. | City/town of residence or death. Must be stated in original text, not invented. |
| `funeral_home` | Compare DB value to `description` text. | Must be explicitly mentioned in original. Not fabricated. |
| `ai_description` | Read the full text. | Must mention the correct name, death date, age, location. Must not contain invented family members, occupations, or personality traits not in the original. |

### B4. Quick SQL to Find Potential AI-Corrected Records

These records had their structured fields CHANGED by the AI rewriter (Groq extracted different values than the regex scraper). These are higher-risk for errors:

```sql
-- Records where AI might have corrected date_of_death
-- (description mentions a date that differs from the DB date_of_death)
-- Manual review required — just flags candidates.
SELECT id, name, date_of_death, LEFT(description, 300) AS desc_snippet
FROM wp_ontario_obituaries
WHERE status = 'published'
  AND ai_description IS NOT NULL AND ai_description != ''
  AND suppressed_at IS NULL
ORDER BY created_at DESC
LIMIT 25;
```

**Note**: The plugin logs corrections with messages like `date_of_death: 2026-01-15 → 2026-01-16`. If you have access to the WP debug log, search for `"AI Rewriter: Published obituary ID"` and `"Corrections:"` to find which records had fields corrected.

### B5. Anomaly Detection Queries

```sql
-- Published records with suspiciously short ai_description (< 100 chars)
SELECT id, name, CHAR_LENGTH(ai_description) AS ai_len
FROM wp_ontario_obituaries
WHERE status = 'published'
  AND ai_description IS NOT NULL AND ai_description != ''
  AND CHAR_LENGTH(ai_description) < 100
  AND suppressed_at IS NULL;

-- Published records with no death date (potential data gap)
SELECT id, name, date_of_death, LEFT(description, 200) AS desc_snippet
FROM wp_ontario_obituaries
WHERE status = 'published'
  AND (date_of_death IS NULL OR date_of_death = '0000-00-00')
  AND suppressed_at IS NULL;

-- Published records with no location (may be legitimate — sparse obits)
SELECT id, name, location, city_normalized
FROM wp_ontario_obituaries
WHERE status = 'published'
  AND location = ''
  AND city_normalized = ''
  AND suppressed_at IS NULL;

-- Confirm NO pending records have ai_description (would violate the pipeline)
SELECT COUNT(*) AS pending_with_ai_desc
FROM wp_ontario_obituaries
WHERE status = 'pending'
  AND ai_description IS NOT NULL
  AND ai_description != '';
```

**Expected for last query**: 0 (zero). If non-zero, the pipeline invariant is broken.

---

## SECTION C: Cross-Reference with Sources

### C1. Primary Source Verification (source_url)

For each of the 25 records:

1. Open the `source_url` in a browser.
2. Find the person's obituary on that page.
3. Compare:

| Fact | DB Value | Source Page Value | Match? |
|------|----------|-------------------|--------|
| Full name | `name` column | Name on source page | |
| Date of death | `date_of_death` column | Date on source page | |
| Date of birth | `date_of_birth` column | Date on source page (if shown) | |
| Age | `age` column | Age on source page (if shown) | |
| Location/City | `location` / `city_normalized` | City on source page | |
| Funeral home | `funeral_home` column | Funeral home on source page | |

**If source_url is dead/404**: Flag as "NEEDS INPUT — source unavailable" and note the domain.

### C2. Secondary Source Verification

**The plugin's source registry** (from `includes/sources/`) scrapes these domains:
- remembering.ca
- dignitymemorial.com
- frontrunner (various funeral home sites)
- legacy.com
- tributearchive.com
- generic HTML adapters (various funeral home websites)

**For each of the 25 records, attempt a second-source check**:

1. **Check if the DB has multiple source indicators**: Some records may have been deduplicated from multiple sources. The `source_domain` field tells you where the record came from.

2. **Manual second-source lookup** (lowest risk):
   - Go to **remembering.ca** and search the person's name + "Ontario"
   - Go to **legacy.com** and search the person's name
   - Go to the funeral home's website (if `funeral_home` is populated) and check their obituary listing
   - These are public, read-only lookups. Zero risk to the live site.

3. **Record the result**:

| Record ID | Name | Primary Source Match? | Second Source Found? | Second Source URL | Second Source Match? | Notes |
|-----------|------|----------------------|---------------------|-------------------|---------------------|-------|
| 123 | John Smith | PASS | YES | remembering.ca/... | PASS | |
| 456 | Jane Doe | PASS | NO | — | N/A — no listing found on secondary sources | Flag: single-source only |

4. **If no second source exists**: This is common and acceptable for smaller/rural funeral homes. Flag it explicitly:
   - `"SINGLE-SOURCE: No matching listing found on remembering.ca, legacy.com, or funeral home website. Record relies solely on [source_domain]."`

### C3. SQL to Identify Source Distribution

```sql
-- Which sources are most common in the recent 25?
SELECT source_domain, COUNT(*) AS cnt
FROM wp_ontario_obituaries
WHERE status = 'published'
  AND ai_description IS NOT NULL AND ai_description != ''
  AND suppressed_at IS NULL
ORDER BY created_at DESC
LIMIT 25;
```

Better version (subquery):
```sql
SELECT source_domain, COUNT(*) AS cnt
FROM (
    SELECT source_domain
    FROM wp_ontario_obituaries
    WHERE status = 'published'
      AND ai_description IS NOT NULL AND ai_description != ''
      AND suppressed_at IS NULL
    ORDER BY created_at DESC
    LIMIT 25
) AS recent
GROUP BY source_domain
ORDER BY cnt DESC;
```

---

## SECTION D: Edge-Case Checks

### D1. Suppressed Obituary Metadata Leak Test

```sql
-- Find a suppressed record to test with
SELECT id, name, city_normalized
FROM wp_ontario_obituaries
WHERE suppressed_at IS NOT NULL
LIMIT 3;
```

For each suppressed record ID:
1. Construct the SEO URL: `https://monacomonuments.ca/obituaries/ontario/{city}/{name-slug}-{id}/`
2. Open it in a browser.
3. **Expected**: 404 page (not the obituary)
4. **View source**: Search for the person's name. It must NOT appear in `<title>`, `<meta name="description">`, `<meta property="og:title">`, or `<script type="application/ld+json">`.

### D2. REST Endpoint Security

**Rate-limit test**:
1. Open: `https://monacomonuments.ca/wp-json/ontario-obituaries/v1/cron`
2. Wait for response (should be 200 OK with JSON).
3. Immediately reload (within 60 seconds).
4. **Expected on second hit**: HTTP 429 with body containing `"Rate limited. Try again in 60 seconds."`

**Secret test** (if `ontario_obituaries_cron_secret` is set in DB):
```sql
SELECT option_value FROM wp_options WHERE option_name = 'ontario_obituaries_cron_secret';
```
If non-empty:
1. Hit `/wp-json/ontario-obituaries/v1/cron` without `?secret=` → **Expected**: 403
2. Hit `/wp-json/ontario-obituaries/v1/cron?secret=WRONG` → **Expected**: 403
3. Hit `/wp-json/ontario-obituaries/v1/cron?secret=CORRECT` → **Expected**: 200

**Admin-only endpoints**:
1. Open (logged out): `https://monacomonuments.ca/wp-json/ontario-obituaries/v1/status` → **Expected**: 401 or 403
2. Open (logged out): `https://monacomonuments.ca/wp-json/ontario-obituaries/v1/ai-rewriter` → **Expected**: 401 or 403

### D3. CLI Script Web Access Block

1. Open in browser: `https://monacomonuments.ca/wp-content/plugins/ontario-obituaries/cron-rewriter.php`
2. **Expected**: HTTP 403 "CLI only." (or .htaccess blocks it entirely)

---

## SECTION E: Report Template

Copy this template and fill in results for each check.

### Site-Level Sanity

- [ ] **A1** Plugin version shows 5.0.5: `__________` (PASS / FAIL)
- [ ] **A2** No fatal errors on admin + public pages: `__________` (PASS / FAIL)
- [ ] **A3** Cron hooks scheduled (collection, rewriter, dedup): `__________` (PASS / FAIL)
- [ ] **A4** Last collection timestamp is recent: `__________` (PASS / FAIL / value: _______)
- [ ] **A5** Record counts — published all have ai_desc, pending have none: `__________` (PASS / FAIL)

### Published Integrity (25 records)

For each record, fill one row:

| ID | Name | ai_desc exists? | Name unchanged? | DoD matches source? | DoB matches? | Age matches? | Location matches? | Funeral home matches? | ai_description factual? | Source URL works? | Second source? | Result |
|----|------|----------------|-----------------|---------------------|--------------|--------------|-------------------|----------------------|------------------------|-------------------|----------------|--------|
| | | | | | | | | | | | | PASS/FAIL |

### Cross-Reference Summary

- Total records checked: ___/25
- Primary source confirmed: ___/25
- Secondary source confirmed: ___/25
- Single-source only (no secondary found): ___/25
- Source URL dead/404: ___/25

### Edge Cases

- [ ] **D1** Suppressed records return 404, no metadata leak: `__________` (PASS / FAIL)
- [ ] **D2a** REST /cron rate-limited on second hit within 60s: `__________` (PASS / FAIL)
- [ ] **D2b** REST /status and /ai-rewriter blocked when logged out: `__________` (PASS / FAIL)
- [ ] **D3** cron-rewriter.php blocked via web browser: `__________` (PASS / FAIL)

### Overall Verdict

`PASS / FAIL` — _one sentence reason_

---

## Data I Need From You (NEEDS INPUT items)

If you want me to perform the actual fact-checking on the 25 records, paste:

1. **The SQL output** from query B1 (the 25 records with all fields).
2. **For each record**: either paste the source page HTML/text, or confirm I should tell you which `source_url` values to visit.
3. **Access to WP debug log** (if available): Search for lines containing `AI Rewriter: Published obituary ID` — these show which fields were AI-corrected.

I cannot browse the web myself. For every source_url check, you'll need to either:
- Paste the source page content here, OR
- Visit each source_url yourself and confirm the facts match

---

## Appendix: Full SQL for Export (all 25 records, all columns)

If you want the FULL data (not truncated), use this query in phpMyAdmin and export as CSV:

```sql
SELECT
    id,
    name,
    date_of_birth,
    date_of_death,
    age,
    funeral_home,
    location,
    city_normalized,
    source_url,
    source_domain,
    description,
    ai_description,
    status,
    suppressed_at,
    created_at
FROM wp_ontario_obituaries
WHERE status = 'published'
  AND ai_description IS NOT NULL
  AND ai_description != ''
  AND suppressed_at IS NULL
ORDER BY created_at DESC
LIMIT 25;
```

Export as CSV → attach to this audit for permanent record.
