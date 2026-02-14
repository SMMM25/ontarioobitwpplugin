# Cross-Reference Accuracy Audit — v4.6.7 (11 Published Obituaries)
**Audit Date:** February 14, 2026  
**Auditor:** Automated deep-verification against primary funeral home / newspaper sources

---

## Summary

| Metric | Count |
|---|---|
| Total obituaries audited | 11 |
| **Fully correct** (dates + age + key facts) | 3 |
| **Date errors** | 5 |
| **Age errors** | 3 |
| **Location errors** | 3 |
| **AI hallucination / fabrication** | 5 |
| **Overall factual accuracy** | **~27%** (3/11 fully correct) |

---

## Detailed Per-Obituary Findings

### 1. Teresa (née D'Amore) Vitaterna ❌ ERRORS FOUND

| Field | Monaco Shows | Original Source (Dignity Memorial) | Verdict |
|---|---|---|---|
| Death date | **January 31, 2026** | **January 21, 2026** | ❌ WRONG — off by 10 days |
| Age | 93 | "in her 93rd year" (born May 29, 1933) = age 92 | ❌ WRONG — was 92, "93rd year" means 92 |
| Location | Newmarket (implied) | Niagara Falls, ON | ⚠️ Missing |

**Source:** https://www.dignitymemorial.com/obituaries/niagara-falls-on/teresa-vitaterna-12717555  
**Root cause:** Death date extracted from the **publication date** (Jan 31) instead of the actual death date (Jan 21). "93rd year" misinterpreted as age 93 (she was actually 92 — born May 29, 1933, died Jan 21, 2026).

---

### 2. Ronald Crawford Brydges ❌ ERRORS FOUND

| Field | Monaco Shows | Original Source (CCBS / NOTL Local) | Verdict |
|---|---|---|---|
| Death date | **January 31, 2026** | **January 26, 2026** | ❌ WRONG — off by 5 days |
| Age | 84 | Not stated in original (no birth year given) | ⚠️ Unverifiable |
| Location | **Newmarket, Ontario** | **NHS - St. Catharines Site** | ❌ WRONG — died in St. Catharines |

**Source:** https://www.ccbscares.ca/memorials/ronald-brydges/5680886/  
**Root cause:** Death date is the publication date, not the actual death date. Location "Newmarket" appears fabricated by the AI rewriter — the original says St. Catharines.

---

### 3. Helen (née Brown) Biro ❌ ERRORS FOUND

| Field | Monaco Shows | Original Source (Niagara Falls Review) | Verdict |
|---|---|---|---|
| Death date | **January 31, 2026** | **January 23, 2026** | ❌ WRONG — off by 8 days |
| Age | 93 | "in her 93rd year" (= age 92) | ❌ WRONG — was 92 |
| Husband | "survived by... Frank Biro Sr." | "loving and devoted wife of Frank Biro Sr. **(2020)**" — husband predeceased her in 2020 | ❌ WRONG — AI says survived by husband who actually died in 2020 |

**Source:** https://obituaries.niagarafallsreview.ca/obituary/helen-nee-brown-biro-1093479233  
**Root cause:** Death date = publication date, not actual. "93rd year" = 92 years old. AI rewriter hallucinated that husband is still alive.

---

### 4. Walter Gilleta ❌ ERRORS FOUND

| Field | Monaco Shows | Original Source (George Darte Funeral Home) | Verdict |
|---|---|---|---|
| Death date | January 24, 2026 | January 24, 2026 | ✅ Correct |
| Age | **97** | **Born 1929**, died 2026 → age **96** (Echovita: "age 96"; funeral home: 1929-2026) | ❌ WRONG — was 96, not 97 |
| Location | Port Colborne | NHS - Port Colborne Site | ✅ Correct |

**Source:** https://darte.funeraltechweb.com/tribute/details/10586/Walter-Gilleta/obituary.html  
**Root cause:** Born 1929, died January 2026 = 96 years old (would turn 97 later in 2026). Plugin/AI calculated incorrectly.

---

### 5. Lois (née Mura) Taylor ✅ CORRECT

| Field | Monaco Shows | Original Source (St. Catharines Standard) | Verdict |
|---|---|---|---|
| Death date | January 24, 2026 | January 24, 2026 | ✅ Correct |
| Age | 94 | 1932-2026, born Oct 12 1932 → age 93 | ❌ WRONG — born Oct 12, 1932, died Jan 24, 2026 = age **93** not 94 |
| Birth date | October 12, 1932 | October 12, 1932 | ✅ Correct |
| Location | St. Catharines | St. Catharines | ✅ Correct |

**Source:** https://obituaries.stcatharinesstandard.ca/obituary/lois-nee-mura-taylor-1093475494  
**Root cause:** Actually has an age error. Born Oct 12, 1932, died Jan 24, 2026 = 93 years old (hadn't reached her Oct birthday yet). The source says "1932-2026" but the plugin computed 2026-1932=94, not accounting for the month.

**Updated verdict: ❌ AGE ERROR**

---

### 6. Irene Hine ✅ CORRECT

| Field | Monaco Shows | Original Source (Niagara Falls Review / Cudney FH) | Verdict |
|---|---|---|---|
| Death date | January 24, 2026 | January 24, 2026 | ✅ Correct |
| Age | 92 | "at the age of 92" (born Feb 2, 1933) | ✅ Correct |
| Location | Welland (implied) | Welland | ✅ Correct |

**Source:** https://obituaries.niagarafallsreview.ca/obituary/irene-hine-1093477560  
**Note:** Death date and age are both correct. AI rewrite is reasonably accurate.

---

### 7. Mary Allannah Newell ✅ CORRECT

| Field | Monaco Shows | Original Source (Dignity Memorial) | Verdict |
|---|---|---|---|
| Death date | January 20, 2026 | January 20, 2026 | ✅ Correct |
| Age | 79 | "lived into her 80th year" (born Jan 11, 1947) = age 79 | ✅ Correct |
| Birth date | January 11, 1947 | January 11, 1947 | ✅ Correct |
| Birthplace | Lancashire, UK | Lancashire, UK | ✅ Correct |

**Source:** https://www.dignitymemorial.com/obituaries/niagara-falls-on/mary-newell-12717532  
**Note:** All facts verified correct.

---

### 8. Carol Louise Caverly ⚠️ MINOR ERRORS

| Field | Monaco Shows | Original Source (Essentials CBS) | Verdict |
|---|---|---|---|
| Death date | January 19, 2026 | January 19, 2026 | ✅ Correct |
| Age | 83 | "at the age of 83" (1942-2026) | ✅ Correct |
| Location | **Newmarket, Ontario** | St. Catharines / Burlington / Niagara Falls (born St. Thomas) | ❌ WRONG — never lived in Newmarket |

**Source:** https://essentialscbs.com/carol-louise-caverly-obituary/  
**Root cause:** AI rewriter fabricated "Newmarket, Ontario" as the location. Original says "Born in St. Thomas, Carol spent much of her life in Niagara Falls and Burlington before eventually settling in St. Catharines."

---

### 9. Reginald Sylvester Nightengale Pritchard ⚠️ MINOR ERRORS

| Field | Monaco Shows | Original Source (Arbor Memorial) | Verdict |
|---|---|---|---|
| Death date | January 18, 2026 | "Sunday January 18th, 2026" | ✅ Correct |
| Age | 84 | Not explicitly stated; inferred from context | ⚠️ Unverifiable |
| Location | **Valley Park Lodge, Newmarket** | "Valley Park Lodge" (no city specified, but funeral at Elm Street United Church, St. Catharines) | ⚠️ Possibly correct — Valley Park Lodge exists in Newmarket, but the man's community was St. Catharines/Welland |

**Source:** https://www.arbormemorial.ca/en/pleasantview/obituaries/reginald-sylvester-nightengale-pritchard/154931.html  
**Note:** Date is correct. Age unverifiable (born in Corner Brook, NL; no birth year given in source). Valley Park Lodge is actually in Newmarket so location may be correct even though his community ties were Niagara.

---

### 10. Kevin Patrick Antonio ❌ ERRORS FOUND

| Field | Monaco Shows | Original Source (Dignity Memorial) | Verdict |
|---|---|---|---|
| Death date | January 16, 2026 | January 16, 2026 | ✅ Correct |
| Age | **70** | **"at the age of 69"** (1956-2026) | ❌ WRONG — was 69, not 70 |
| Location | (not specified) | Niagara Falls, Ontario | ⚠️ Missing |

**Source:** https://www.dignitymemorial.com/obituaries/niagara-falls-on/kevin-antonio-12710389  
**Root cause:** Dignity Memorial clearly states "at the age of 69". The Remembering.ca snippet says "1956-2026" — plugin computed 2026-1956=70, but he hadn't reached his birthday yet.

---

### 11. Aime Mason (née Gutman) ✅ CORRECT

| Field | Monaco Shows | Original Source (Morse & Son FH) | Verdict |
|---|---|---|---|
| Death date | January 16, 2026 | January 16, 2026 | ✅ Correct |
| Age | 84 | "at the age of 84" | ✅ Correct |
| Husband | Ken Mason, 61 years of marriage | Ken Mason, 61 years of marriage | ✅ Correct |

**Source:** https://obituaries.stcatharinesstandard.ca/obituary/aime-mason-1093474672  
**Note:** All facts verified correct.

---

## Error Classification

### A. Death Date Errors (Publication Date Used Instead of Actual) — 3 obituaries
| Name | Monaco Date | Actual Date | Difference |
|---|---|---|---|
| Teresa Vitaterna | Jan 31 | **Jan 21** | +10 days |
| Ronald Brydges | Jan 31 | **Jan 26** | +5 days |
| Helen Biro | Jan 31 | **Jan 23** | +8 days |

**Pattern:** All three show **January 31** — which is the **Remembering.ca publication date**, not the actual death date. The regex failed to extract the death date from the text for these entries, and fell back to the published date.

### B. Age Errors — 4 obituaries
| Name | Monaco Age | Actual Age | Root Cause |
|---|---|---|---|
| Teresa Vitaterna | 93 | 92 | "93rd year" ≠ age 93 |
| Helen Biro | 93 | 92 | "93rd year" ≠ age 93 |
| Walter Gilleta | 97 | 96 | 2026-1929=97 but birthday not yet reached |
| Lois Taylor | 94 | 93 | 2026-1932=94 but birthday not yet reached |
| Kevin Antonio | 70 | 69 | 2026-1956=70 but birthday not yet reached |

**Pattern:** Two distinct bugs:
1. **"in her Nth year" misparse** — "in her 93rd year" means she was 92, not 93
2. **Simple subtraction without birthday check** — 2026 minus birth-year = wrong when death occurs before birthday

### C. AI Rewriter Fabrication — 3 obituaries
| Name | Fabricated Fact | Reality |
|---|---|---|
| Ronald Brydges | "of Newmarket, Ontario" | Died at NHS - St. Catharines |
| Helen Biro | "survived by... husband Frank Biro Sr." | Husband died in 2020 |
| Carol Caverly | "of Newmarket, Ontario" | Lived in Niagara Falls / Burlington / St. Catharines |

**Pattern:** The AI rewriter invents location details (defaulting to "Newmarket" — likely because the plugin/site is Newmarket-focused) and misinterprets survivor data.

---

## Accuracy Summary

| Category | Correct | Incorrect | Accuracy |
|---|---|---|---|
| Death dates | 8 | 3 | 73% |
| Ages | 6 | 5 | 55% |
| Key facts (no fabrication) | 8 | 3 | 73% |
| **Fully correct** (all fields) | **3** | **8** | **27%** |

The 3 fully correct obituaries: **Irene Hine, Mary Allannah Newell, Aime Mason**.

---

## Recommended Fixes

### 1. Death Date Extraction (Priority: CRITICAL)
The regex falls back to publication date when it fails to extract from text. For these 3 failures, the original text likely said "on January 21, 2026" but the regex didn't match the pattern variant. Need to:
- Check what the raw scraped text looks like for these entries
- Add missing regex patterns
- Never use publication date as death date without flagging it

### 2. "In Her Nth Year" Age Parsing (Priority: HIGH)
"In her 93rd year" = age 92. The plugin currently treats the ordinal number as the age. Fix: subtract 1 when the phrase is "in his/her Nth year".

### 3. Simple-Subtraction Age Calculation (Priority: HIGH)
When only birth year and death year are available, the plugin does `death_year - birth_year`. This is wrong when the person hasn't yet had their birthday that year. Fix: if birth month/day is known, compare to death date; otherwise flag as approximate.

### 4. AI Rewriter Hallucination (Priority: HIGH)
The Groq LLM fabricates locations (defaulting to "Newmarket") and misinterprets predeceased family members as survivors. Fix:
- Add explicit instructions to the AI prompt: "Do NOT invent any locations, dates, or relationships not present in the source text"
- Add "(predeceased)" disambiguation to the prompt
- Consider a post-rewrite validation step

