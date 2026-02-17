# Investigation: Obituary ID 454 — Factual Inconsistencies

## 1. SQL Query to Fetch the Row

```sql
SELECT * FROM wp_ontario_obituaries WHERE id = 454;
```

---

## 2. Evidence Collected from Rendered Page (monacomonuments.ca)

| Field | Rendered Value | DB Column |
|-------|---------------|-----------|
| **Top date** (hero) | `February 14, 2026` | `date_of_death` (line 41→225 of `individual.php`) |
| **Footer "Date published"** | `February 15, 2026` | `created_at` (line 326→330 of `individual.php`) |
| **Age** (hero) | `Age 16` | `age` (line 229→231 of `individual.php`) |
| **JSON-LD deathDate** | `2026-02-14` | `date_of_death` (line 60–61 of `individual.php`) |
| **JSON-LD datePublished** | `2026-02-15T09:08:28+00:00` | `created_at` (line 92 of `individual.php`) |
| **Source URL** | `https://obituaries.thespec.com/obituary/isobel-carle-47253890` | `source_url` |

### Source Page Status
- **The original source page now returns HTTP 404** (`pagetype: ad_error404`).
- The original obituary has been removed from obituaries.thespec.com.
- Source page cannot be consulted for comparison — data can only be compared against the stored description.

---

## 3. Stored Description Analysis (from rendered HTML)

The AI-rewritten or stored description begins:
> *"Carle, Isobel Mary (Cocklin) was born in Nottawasaga Township and grew up as a farm girl in Creemore, Ontario..."*

Key biographical facts in the text:
- **"admitted to Teacher's College in Toronto at the age of 16"** ← This is the only `age N` phrase.
- Taught 35+ years for the Hamilton School Board.
- Married Robert in 1956; he passed in 2015.
- Both retired from teaching in 1988.
- Was in long-term care ("Cama Woodlands in Burlington") in her last years.
- Visitation at Smith's Funeral Home on **Friday, February 20th**.
- **No explicit death date** in the text (no "passed away on...", no "died on...", no year range).
- **No explicit age at death** in the text.

---

## 4. Root Cause Classification

### 4A. `age` field = 16 → **(A) Extraction/scrape bug**

- **Root cause:** The regex in `extract_age_from_text()` (file: `class-source-adapter-base.php`, line 350–353) matches `/\bat\s+the\s+age\s+of\s+(\d{1,3})\b/i` against the description.
- The text says *"admitted to Teacher's College in Toronto at the age of 16"*.
- The regex correctly matched `at the age of 16` — but this refers to her age when entering college, **not her age at death**.
- The function has no semantic context to distinguish "age at event X" from "age at death".
- **This is a known limitation of regex-based age extraction.**
- The AI rewriter (file: `class-ai-rewriter.php`, line 619–622) could have corrected this if it ran and returned a correct age, but apparently either:
  - (a) The AI rewriter did not override the age field, or
  - (b) The AI rewriter also extracted 16 from the same text.
- **Code location:** `wp-plugin/includes/sources/class-source-adapter-base.php` line 350 (`extract_age_from_text()`), called from `wp-plugin/includes/sources/class-adapter-remembering-ca.php` lines 866 and 647.

### 4B. `date_of_death` = 2026-02-14 (displayed as "February 14, 2026") → **(A) Extraction/scrape bug**

- **Root cause:** The obituary body text has **no death date phrase** (no "passed away on...", no "died on...", no year range).
- The remembering.ca adapter's death-date extraction cascade (`class-adapter-remembering-ca.php` lines 758–823):
  1. `death_date_from_text` (from listing card body phrases) — **empty** (no death keyword phrase).
  2. `detail_death_date` (from detail page SSR) — **likely empty** if detail fetch was skipped.
  3. `dates['death']` (from structured date range) — **possibly empty** if the listing card had year-only dates.
  4. `published_date` (from "Published online..." line) — **this is the fallback that fired**.
- The "Published online" date on Remembering.ca is typically the publication date, **not the death date**. Since the obituary was **published on February 14** (Valentine's Day is when it was posted), this date was stored as `date_of_death`.
- **The user's claim that the source death date was "Feb 4" cannot be verified** because the source page is now a 404. The stored `date_of_death` of `2026-02-14` likely reflects the "Published online" date fallback, not the actual death date.
- **Code location:** `wp-plugin/includes/sources/class-adapter-remembering-ca.php` lines 819–823 (published_date fallback in `normalize()`).

### 4C. Footer "Date published" = February 15, 2026 → **Expected behavior (NOT a bug)**

- The footer "Date published" renders `created_at` (file: `individual.php` lines 326–330).
- `created_at` is the timestamp when the record was inserted into `wp_ontario_obituaries` (set by MySQL `DEFAULT CURRENT_TIMESTAMP`).
- The record was scraped and inserted on Feb 15, 2026 — one day after the source published it on Feb 14, 2026.
- **This is working as designed.** The 1-day lag between source publication (Feb 14) and scrape insertion (Feb 15) is normal.

### 4D. Top date (Feb 14) vs. Footer "Date published" (Feb 15) → **Different fields, different semantics**

- **Top date** = `date_of_death` (intended to be the actual death date).
- **Footer date** = `created_at` (when our system ingested the record).
- These are **two different DB columns** with different meanings. The 1-day gap is expected.
- **The confusion arises because `date_of_death` was populated with the publication date (Feb 14) rather than the actual death date**, making it look like a date mismatch.

---

## 5. Exact Code Locations

### Extraction / Scraper Parsing

| What | File | Lines |
|------|------|-------|
| Age extraction regex (the `at the age of 16` match) | `wp-plugin/includes/sources/class-source-adapter-base.php` | 340–357 |
| Death date extraction from listing card text (phrases) | `wp-plugin/includes/sources/class-adapter-remembering-ca.php` | 236–297 |
| Death date extraction from detail page SSR | `wp-plugin/includes/sources/class-adapter-remembering-ca.php` | 575–636 |
| Death date priority cascade in normalize() | `wp-plugin/includes/sources/class-adapter-remembering-ca.php` | 758–823 |
| Published date fallback (last resort death date) | `wp-plugin/includes/sources/class-adapter-remembering-ca.php` | 819–823 |
| Detail fetch skip flag | `wp-plugin/includes/sources/class-source-collector.php` | 312 |
| AI rewriter age/date override logic | `wp-plugin/includes/class-ai-rewriter.php` | 394–401, 619–622 |

### Frontend Rendering

| What | File | Lines |
|------|------|-------|
| Top date (`date_of_death` → `$death_display` → `.ontario-obituary-dates`) | `wp-plugin/templates/seo/individual.php` | 40–41 (format), 223–226 (render) |
| Age display (`.ontario-obituary-age`) | `wp-plugin/templates/seo/individual.php` | 229–232 |
| Footer "Date published" (`created_at` → `.ontario-obituary-published-date`) | `wp-plugin/templates/seo/individual.php` | 325–332 |
| JSON-LD `deathDate` | `wp-plugin/templates/seo/individual.php` | 60–61 |
| JSON-LD `datePublished` | `wp-plugin/templates/seo/individual.php` | 92 |
| Hidden meta `itemprop="deathDate"` | `wp-plugin/templates/seo/individual.php` | 257 |

---

## 6. Checklist: DB Fields vs. Rendered HTML vs. Source

| Item | DB Column | Rendered Value | Source Page Value | Match? |
|------|-----------|---------------|-------------------|--------|
| Death date | `date_of_death` = `2026-02-14` | `February 14, 2026` | **Source 404** (alleged Feb 4) | DB→HTML: ✅ match. DB→Source: ❌ unverifiable |
| Date published | `created_at` = `2026-02-15 09:08:28` | `February 15, 2026` | N/A (our insertion time) | ✅ Expected |
| Age | `age` = `16` | `Age 16` | Source 404 | DB→HTML: ✅ match. **Factually wrong** (regex extracted college admission age) |
| Name | `name` | `Isobel Mary (Cocklin) Carle` | Source 404 | N/A |
| Image | `image_url` | CloudFront CDN image | Source 404 | N/A |
| Description | `description`/`ai_description` | Full bio text | Source 404 | Text is internally consistent with elderly person |

---

## 7. Purge & Rescan Assessment

**A purge + rescan would NOT correct the data and would make it WORSE.**

Reasons:
1. **Source page is 404**: The original obituary at `obituaries.thespec.com/obituary/isobel-carle-47253890` has been removed. A rescan cannot fetch any data from this URL.
2. **No detail fetch would succeed**: Even if the listing page still included this obituary, the detail page would fail.
3. **The same regex bugs would reproduce**: If somehow re-scraped, `extract_age_from_text()` would again match "at the age of 16" and the `published_date` fallback would again be used for `date_of_death`.
4. **Risk of data loss**: A purge would delete the existing record (including the description/image), and the rescan would fail to repopulate it since the source is gone.

---

## 8. Summary of Wrong Fields

| Field | Current Value | Likely Correct Value | Error Type |
|-------|--------------|---------------------|------------|
| `age` | 16 | Unknown (likely 90+, based on: married 1956, retired 1988, husband died 2015, long-term care) | **(A) Extraction bug** — regex matched "at the age of 16" (college admission age) |
| `date_of_death` | 2026-02-14 | Unknown (alleged Feb 4 2026; unverifiable — source is 404) | **(A) Extraction bug** — published_date fallback used because no death phrase found in text |
| `created_at` | 2026-02-15 | ✅ Correct (our insertion timestamp) | Not a bug |

---

## 9. Verdict

- **Overall: FAIL** — The `age` field (16) is factually wrong; the regex extracted a life-event age (college admission) instead of age at death. The `date_of_death` field (2026-02-14) is likely the publication date, not the actual death date, due to the published_date fallback firing when no death phrase was found in the text.
- **Approval: NOT APPROVED** — A purge + rescan would delete the record entirely because the source page returns 404; the data cannot be re-fetched.

---

## 10. Recommended Minimal Safe Corrective Actions

1. **Manual DB update for `age`:** Set `age = NULL` (or 0) for ID 454 since the correct age cannot be determined from the stored text. Alternatively, estimate from bio context (~90+) and set manually.
   ```sql
   UPDATE wp_ontario_obituaries SET age = 0 WHERE id = 454;
   ```

2. **Manual DB update for `date_of_death`:** If the user can confirm Feb 4, 2026 from an external source:
   ```sql
   UPDATE wp_ontario_obituaries SET date_of_death = '2026-02-04' WHERE id = 454;
   ```

3. **Systemic fix for `extract_age_from_text()`:** The regex at `class-source-adapter-base.php` line 350 should be hardened to reject `at the age of N` when it appears in a biographical context (e.g., preceded by "admitted", "enrolled", "started", "began"). Alternatively, only extract age when it co-occurs with a death phrase within the same sentence.

4. **Systemic fix for published_date fallback:** Add a log warning when `published_date` is used as `date_of_death` so operators can review these records. Consider flagging them for AI rewriter attention.
