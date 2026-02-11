<?php
/**
 * Remembering.ca / Postmedia Obituary Network Adapter
 *
 * Handles obituaries from the Remembering.ca platform, which powers
 * obituary sections for Postmedia newspapers across Canada:
 *   - obituaries.yorkregion.com
 *   - nationalpost.remembering.ca
 *   - lfpress.remembering.ca
 *   etc.
 *
 * Remembering.ca is a paid obituary placement platform where families
 * or funeral homes submit and pay for obituary notices through newspapers.
 * We extract only publicly available listing-level facts.
 *
 * @package Ontario_Obituaries
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_Adapter_Remembering_Ca extends Ontario_Obituaries_Source_Adapter_Base {

    public function get_type() {
        return 'remembering_ca';
    }

    public function get_label() {
        return 'Remembering.ca / Postmedia Newspapers';
    }

    public function discover_listing_urls( $source, $max_age = 7 ) {
        $urls      = array( $source['base_url'] );
        $max_pages = isset( $source['max_pages_per_run'] ) ? intval( $source['max_pages_per_run'] ) : 5;

        // v3.9.0 FIX: The Remembering.ca / Postmedia platform uses ?p= for pagination.
        // The previous value ?page= was silently ignored, returning page-1 data every time.
        // Verified on obituaries.yorkregion.com and www.remembering.ca — ?p= is correct.
        // This adapter is only used for sources with adapter_type 'remembering_ca'.
        for ( $p = 2; $p <= $max_pages; $p++ ) {
            $urls[] = add_query_arg( 'p', $p, $source['base_url'] );
        }

        return $urls;
    }

    public function fetch_listing( $url, $source ) {
        return $this->http_get( $url, array(
            'Accept-Language' => 'en-CA,en;q=0.9',
        ) );
    }

    public function extract_obit_cards( $html, $source ) {
        $cards = array();
        $doc   = $this->parse_html( $html );
        $xpath = new DOMXPath( $doc );

        // Remembering.ca listing patterns
        // v3.6.0: Added selectors for yorkregion.com's actual HTML structure
        // (Bishop/Postmedia template uses .ap_ad_wrap > a > .listview-summary)
        $selectors = array(
            '//div[contains(@class,"ap_ad_wrap")]',
            '//div[contains(@class,"listview-summary")]/..',
            '//div[contains(@class,"obituary-card")]',
            '//div[contains(@class,"obit-listing")]//article',
            '//div[contains(@class,"listing")]//div[contains(@class,"card")]',
            '//ul[contains(@class,"obituaries")]//li',
            '//div[contains(@class,"search-results")]//div[contains(@class,"result")]',
            // Fallback: anchor-based
            '//a[contains(@href,"/obituary/") or contains(@href,"/obituaries/")]/..',
        );

        $nodes = null;
        foreach ( $selectors as $sel ) {
            $nodes = $xpath->query( $sel );
            if ( $nodes && $nodes->length > 0 ) {
                break;
            }
        }

        if ( ! $nodes || 0 === $nodes->length ) {
            // v3.8.0: Diagnostic — log when we get a page but extract 0 cards.
            // This means the HTML structure changed and our selectors are stale.
            $html_len = strlen( $html );
            $snippet  = substr( wp_strip_all_tags( $html ), 0, 300 );
            ontario_obituaries_log(
                sprintf(
                    'Remembering.ca adapter: 0 cards extracted from %s (HTML size: %d bytes). Snippet: %s',
                    isset( $source['base_url'] ) ? $source['base_url'] : 'unknown',
                    $html_len,
                    $snippet
                ),
                'warning'
            );
            return $cards;
        }

        foreach ( $nodes as $node ) {
            $card = $this->extract_card( $xpath, $node, $source );
            if ( ! empty( $card['name'] ) ) {
                $cards[] = $card;
            }
        }

        return $cards;
    }

    /**
     * Extract data from a single card.
     */
    private function extract_card( $xpath, $node, $source ) {
        $card = array();

        // Name
        $name_queries = array(
            './/h2/a', './/h3/a', './/h4/a',
            './/h2', './/h3', './/h4',
            './/*[contains(@class,"name")]',
            './/strong/a', './/strong',
        );
        foreach ( $name_queries as $q ) {
            $n = $xpath->query( $q, $node )->item( 0 );
            if ( $n && ! empty( trim( $n->textContent ) ) ) {
                $card['name'] = $this->node_text( $n );
                if ( 'a' === $n->nodeName ) {
                    $card['detail_url'] = $this->resolve_url( $this->node_attr( $n, 'href' ), $source['base_url'] );
                }
                break;
            }
        }

        // Detail URL fallback
        if ( empty( $card['detail_url'] ) ) {
            $link = $xpath->query( './/a', $node )->item( 0 );
            if ( $link ) {
                $card['detail_url'] = $this->resolve_url( $this->node_attr( $link, 'href' ), $source['base_url'] );
            }
        }

        // Dates — v3.6.0: Remembering.ca / yorkregion.com often shows only
        // years in the dates div ("1926", "1961 - 2026").  We capture those
        // as year_text and later attempt to extract full dates from the
        // content or Published line.
        $date_queries = array(
            './/*[contains(@class,"date")]',
            './/time',
            './/span[contains(@class,"dates")]',
        );
        foreach ( $date_queries as $q ) {
            $d = $xpath->query( $q, $node )->item( 0 );
            if ( $d ) {
                $dt = $this->node_attr( $d, 'datetime' );
                $card['date_text'] = ! empty( $dt ) ? $dt : $this->node_text( $d );
                break;
            }
        }

        // Full text date extraction
        if ( empty( $card['date_text'] ) ) {
            $text = $this->node_text( $node );
            if ( preg_match( '/(\w+\s+\d{1,2},?\s+\d{4})\s*[-\x{2013}\x{2014}]\s*(\w+\s+\d{1,2},?\s+\d{4})/u', $text, $m ) ) {
                $card['date_text'] = $m[1] . ' - ' . $m[2];
            }
        }

        // v3.6.0 FIX: Extract full dates from "content" paragraph and
        // "Published online" line when the structured dates div only has years.
        $full_text = $this->node_text( $node );

        // Look for "YEAR - YEAR" in dates div → store as year_birth / year_death
        if ( ! empty( $card['date_text'] ) && preg_match( '/^(\d{4})\s*[-\x{2013}\x{2014}~]\s*(\d{4})$/u', trim( $card['date_text'] ), $ym ) ) {
            $card['year_birth'] = $ym[1];
            $card['year_death'] = $ym[2];
            $card['date_text']  = ''; // Clear so normalize() doesn't try to parse it
        } elseif ( ! empty( $card['date_text'] ) && preg_match( '/^\d{4}$/', trim( $card['date_text'] ) ) ) {
            // Single year — might be birth year
            $card['year_birth'] = trim( $card['date_text'] );
            $card['date_text']  = '';
        }

        // v3.15.0 FIX: Extract "YEAR - YEAR" from content paragraph when the dates
        // div only has a single year (e.g., "1926"). The content often contains
        // "1926 - 2026" which gives us year_death for age calculation.
        if ( ! empty( $card['year_birth'] ) && empty( $card['year_death'] ) ) {
            if ( preg_match( '/\b' . preg_quote( $card['year_birth'], '/' ) . '\s*[-\x{2013}\x{2014}~]\s*(\d{4})\b/u', $full_text, $yr_m ) ) {
                $card['year_death'] = $yr_m[1];
            }
        }
        // Also try extracting year range when neither year_birth nor year_death set
        if ( empty( $card['year_birth'] ) && empty( $card['year_death'] ) ) {
            if ( preg_match( '/\b(\d{4})\s*[-\x{2013}\x{2014}~]\s*(\d{4})\b/u', $full_text, $yr_m2 ) ) {
                $birth_yr = intval( $yr_m2[1] );
                $death_yr = intval( $yr_m2[2] );
                if ( $death_yr > $birth_yr && $death_yr >= 2020 && $death_yr <= 2030 && $birth_yr >= 1890 ) {
                    $card['year_birth'] = $yr_m2[1];
                    $card['year_death'] = $yr_m2[2];
                }
            }
        }

        // Try to extract full "Month Day, Year - Month Day, Year" from content paragraph
        if ( empty( $card['date_text'] ) && preg_match( '/(\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4})\s*[-\x{2013}\x{2014}~]\s*(\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4})/u', $full_text, $dm ) ) {
            $card['date_text'] = $dm[1] . ' - ' . $dm[2];
        }

        // v3.12.0 FIX (Goal A): Extract death date from common obituary phrases
        // ALWAYS — not just when date_text is empty. The textual death date
        // (e.g., "passed away on January 6, 2026") is more reliable than the
        // structured dates div or published_date, which may reflect the posting
        // date rather than the actual date of death.
        //
        // Previous behavior (v3.10.2): only ran when date_text was empty,
        // so records with a date_text/published_date would never get the
        // more accurate death date extracted from the obituary body.
        //
        // Example: Paul Andrew Smith — yorkregion.com shows "Published
        // online January 10, 2026" but the obit body says "passed away on
        // January 6, 2026". The correct date_of_death is 2026-01-06.
        $month_pattern = '(?:January|February|March|April|May|June|July|August|September|October|November|December)';
        $death_phrases = array(
            // "passed away/peacefully/suddenly [words] Month Day, Year"
            '/(?:passed\s+away|passed\s+peacefully|passed\s+suddenly|peacefully\s+passed)(?:\s+\w+){0,3}?\s+(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
            // "entered into rest [on] Month Day, Year"
            '/entered\s+into\s+rest(?:\s+on)?\s+(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
            // "died [peacefully|suddenly] [on] Month Day, Year"
            '/died(?:\s+\w+){0,2}?\s+(?:on\s+)?(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
            // "went to be with the Lord on Month Day, Year" / "called home on ..."
            '/(?:went\s+to\s+be\s+with|called\s+home)(?:\s+\w+){0,4}?\s+(?:on\s+)?(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
        );
        foreach ( $death_phrases as $pattern ) {
            if ( preg_match( $pattern, $full_text, $death_m ) ) {
                $card['death_date_from_text'] = trim( $death_m[1] );
                break;
            }
        }

        // If no extracted death date from text, try the Published line as last resort.
        // v3.12.0: Removed the `empty( $card['date_text'] )` gate so published_date
        // is always captured as a fallback (normalize() only uses it if
        // death_date_from_text and dates['death'] are both empty).
        if ( empty( $card['death_date_from_text'] ) && preg_match( '/Published\s+online\s+.*?(\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4})\b/i', $full_text, $pm ) ) {
            $card['published_date'] = $pm[1];
        }

        // v3.15.1: Extract image from listing page — prefer the larger print-only
        // image (300×400px) over the lazy-loaded thumbnail (200×200px).
        //
        // The images are hosted on a public CDN (cdn-otf-cas.prfct.cc) served by
        // Tribute Technology. The CDN encodes transform params (width/height) in
        // a base64 JSON payload within the URL path. The print-only image is
        // served at 300×400 which displays cleanly in our 220px card layout.
        // The lazy-loaded thumbnail is only 200×200 and looks zoomed/blurry
        // when upscaled to fill the card.
        //
        // Priority:
        //   1. Print-only img (class contains "print-only") — src attr, 300×400
        //   2. Lazy-loaded img (class contains "ap_photo") — data-src attr, 200×200
        //   3. Any other img within the card
        //
        // Filter out placeholder/spacer images in all cases.
        $spacer_pattern = '/(spacer|placeholder|default|generic|no-?photo|avatar|logo|1x1|pixel)\.(?:png|gif|jpg|svg)/i';

        // Strategy 1: Print-only image (larger, better quality)
        $print_imgs = $xpath->query( './/img[contains(@class,"print-only")]', $node );
        if ( $print_imgs && $print_imgs->length > 0 ) {
            $print_img = $print_imgs->item( 0 );
            $print_src = $this->node_attr( $print_img, 'src' );
            if ( ! empty( $print_src ) && ! preg_match( $spacer_pattern, $print_src ) ) {
                $card['image_url'] = $this->resolve_url( $print_src, $source['base_url'] );
            }
        }

        // Strategy 2: Lazy-loaded thumbnail (fallback)
        if ( empty( $card['image_url'] ) ) {
            $img_selectors = array(
                './/img[contains(@class,"ap_photo") or contains(@class,"obit-image") or contains(@class,"photo")]',
                './/div[contains(@class,"photo")]//img',
                './/img',
            );
            foreach ( $img_selectors as $isel ) {
                $img_node = $xpath->query( $isel, $node )->item( 0 );
                if ( $img_node ) {
                    // Prefer data-src (lazy-loaded real image) over src (may be a spacer)
                    $img_src = $this->node_attr( $img_node, 'data-src' );
                    if ( empty( $img_src ) ) {
                        $img_src = $this->node_attr( $img_node, 'src' );
                    }
                    if ( ! empty( $img_src ) && ! preg_match( $spacer_pattern, $img_src ) ) {
                        $card['image_url'] = $this->resolve_url( $img_src, $source['base_url'] );
                        break;
                    }
                }
            }
        }

        // Location
        $loc_node = $xpath->query( './/*[contains(@class,"location") or contains(@class,"city")]', $node )->item( 0 );
        if ( $loc_node ) {
            $card['location'] = $this->node_text( $loc_node );
        }

        // Funeral home
        $fh_node = $xpath->query( './/*[contains(@class,"funeral") or contains(@class,"provider")]', $node )->item( 0 );
        if ( $fh_node ) {
            $card['funeral_home'] = $this->node_text( $fh_node );
        }

        // Description — v3.15.2: Extract content from listing card.
        // The Tribute Technology platform uses different CSS classes across
        // template versions:
        //   - Old: <p class="content"> (Bishop Waterfall v1)
        //   - New: <p class="copy-featured"> (Bishop Waterfall July 2022+)
        //   - Generic: <p> within .list-panel-info
        // We check for all known content classes, then fall back to generic <p>.
        $desc_parts = array();
        $desc_selectors = array(
            './/p[contains(@class,"copy-featured") or contains(@class,"copy-featuredhidden")]',
            './/p[contains(@class,"content")]',
            './/*[contains(@class,"excerpt")]',
        );
        foreach ( $desc_selectors as $dsel ) {
            $desc_nodes = $xpath->query( $dsel, $node );
            if ( $desc_nodes && $desc_nodes->length > 0 ) {
                foreach ( $desc_nodes as $dn ) {
                    $dt = trim( $dn->textContent );
                    if ( strlen( $dt ) > 15 ) {
                        $desc_parts[] = $this->node_text( $dn );
                    }
                }
                break; // Use the first matching selector
            }
        }
        // Fallback: generic <p> tags if no known content class found
        if ( empty( $desc_parts ) ) {
            $fallback_p = $xpath->query( './/p', $node );
            if ( $fallback_p ) {
                foreach ( $fallback_p as $fp ) {
                    $ft = trim( $fp->textContent );
                    $fc = $fp->getAttribute( 'class' );
                    // Skip structural paragraphs (name, dates, category)
                    if ( strlen( $ft ) > 30
                         && stripos( $ft, 'Published online' ) === false
                         && stripos( $fc, 'name' ) === false
                         && stripos( $fc, 'dates' ) === false
                         && stripos( $fc, 'category' ) === false ) {
                        $desc_parts[] = $this->node_text( $fp );
                    }
                }
            }
        }
        if ( ! empty( $desc_parts ) ) {
            $card['description'] = implode( "\n\n", $desc_parts );
        }

        return $card;
    }

    /**
     * v3.15.2: Fetch detail page and extract full obituary content.
     *
     * CORRECTION from v3.15.1: The detail pages are NOT empty SPAs.
     * Tribute Technology's Next.js pages are Server-Side Rendered (SSR)
     * and contain the full obituary in:
     *   - <div id="obituaryDescription"> — complete obituary text (SSR HTML)
     *   - "Funeral Arrangements by" section — funeral home name
     *   - Published date line — publication date and newspaper
     *   - High-resolution images from d1q40j6jx1d8h6.cloudfront.net
     *
     * The pages are large (~300KB) because they include RSC payloads,
     * but the SSR HTML contains all the data we need.
     *
     * @param string $detail_url URL of the detail page.
     * @param array  $card       Card data from extract_obit_cards().
     * @param array  $source     Source registry row.
     * @return array|null Enriched card data, or null on failure.
     */
    public function fetch_detail( $detail_url, $card, $source ) {
        if ( empty( $detail_url ) ) {
            return null;
        }

        $html = $this->http_get( $detail_url, array(
            'Accept-Language' => 'en-CA,en;q=0.9',
        ) );

        if ( is_wp_error( $html ) || empty( $html ) ) {
            return null;
        }

        // v3.15.2: Minimum size check — true empty shells are <1KB.
        // Valid SSR pages are 100KB+. Use a generous threshold.
        if ( strlen( $html ) < 5000 ) {
            return null;
        }

        $detail = array();

        // 1. Full obituary text from SSR-rendered div#obituaryDescription
        //    This contains the complete obituary as server-rendered HTML
        //    paragraphs (not requiring JavaScript to display).
        if ( preg_match( '/id=["\']obituaryDescription["\'][^>]*>(.*?)<\/div>/s', $html, $desc_m ) ) {
            $desc_html = $desc_m[1];
            // Strip HTML tags but preserve paragraph breaks
            $desc_text = preg_replace( '/<br\s*\/?>/i', "\n", $desc_html );
            $desc_text = preg_replace( '/<\/p>\s*<p[^>]*>/i', "\n\n", $desc_text );
            $desc_text = wp_strip_all_tags( $desc_text );
            $desc_text = preg_replace( '/\n{3,}/', "\n\n", trim( $desc_text ) );
            if ( strlen( $desc_text ) > 50 ) {
                $detail['detail_full_description'] = $desc_text;
            }
        }

        // 2. Funeral home from "Funeral Arrangements by" section
        //    SSR HTML: <h6>Funeral Arrangements by</h6><div class="...">Name</div>
        if ( preg_match( '/Funeral\s+Arrangements\s+by<\/h6>\s*<div[^>]*>([^<]+)<\/div>/i', $html, $fh_m ) ) {
            $fh_name = trim( $fh_m[1] );
            if ( strlen( $fh_name ) > 3 && strlen( $fh_name ) < 200 ) {
                $detail['detail_funeral_home'] = $fh_name;
            }
        }

        // 3. Published date from detail page
        if ( preg_match( '/Published\s+on\s+<!--\s*-->([^<]+?)<!--/i', $html, $pub_m ) ) {
            $detail['detail_published_date'] = trim( $pub_m[1] );
        }

        // 4. High-resolution image from Cloudfront CDN
        //    Detail pages use d1q40j6jx1d8h6.cloudfront.net/Obituaries/{id}/Image.jpg
        //    URL may appear with escaped quotes (\\") in RSC payload or normal quotes in SSR HTML.
        if ( preg_match( '/(https:\\/\\/d1q40j6jx1d8h6\\.cloudfront\\.net\\/Obituaries\\/\\d+\\/Image\\.jpg)/', $html, $img_m ) ) {
            $detail['detail_image_url'] = $img_m[1];
        }

        // 5. Location from detail page funeral home address
        //    RSC payload uses escaped JSON: \\\"city\\\":\\\"South Newmarket,\\\"
        //    Also try the regular JSON format for SSR-rendered data.
        $loc_found = false;
        // Try RSC escaped format first (most common)
        if ( preg_match( '/\\\\"city\\\\":\\\\"([^\\\\]+)\\\\"/', $html, $loc_m ) ) {
            $city = trim( $loc_m[1], ', ' );
            if ( ! empty( $city ) && strlen( $city ) > 1 ) {
                $detail['detail_location'] = $city;
                $loc_found = true;
            }
        }
        // Fallback: regular JSON format
        if ( ! $loc_found && preg_match( '/"city":\s*"([^"]+?)"/', $html, $loc_m2 ) ) {
            $city = trim( $loc_m2[1], ', ' );
            if ( ! empty( $city ) && strlen( $city ) > 1 ) {
                $detail['detail_location'] = $city;
            }
        }

        return ! empty( $detail ) ? $detail : null;
    }

    public function normalize( $card, $source ) {
        $dates = $this->parse_date_range( isset( $card['date_text'] ) ? $card['date_text'] : '' );

        $name          = isset( $card['name'] ) ? sanitize_text_field( $card['name'] ) : '';
        $date_of_death = ! empty( $dates['death'] ) ? $dates['death'] : '';
        $date_of_birth = ! empty( $dates['birth'] ) ? $dates['birth'] : '';
        $funeral_home  = isset( $card['funeral_home'] ) ? sanitize_text_field( $card['funeral_home'] ) : '';
        $location      = isset( $card['location'] ) ? $card['location'] : '';
        $description   = isset( $card['description'] ) ? wp_strip_all_tags( $card['description'] ) : '';

        // v3.15.2: Use detail page data when available (richer than listing snippet).
        // The detail page is an SSR-rendered Next.js page with the full obituary text,
        // funeral home, high-res image, and location extracted by fetch_detail().
        // Detail keys are prefixed with 'detail_' to avoid collision with listing data
        // when array_merge is used by the collector.

        // Full description from detail page supersedes listing snippet
        if ( ! empty( $card['detail_full_description'] ) && strlen( $card['detail_full_description'] ) > strlen( $description ) ) {
            $description = $card['detail_full_description'];
        }

        // Funeral home from detail page (structured, reliable)
        if ( empty( $funeral_home ) && ! empty( $card['detail_funeral_home'] ) ) {
            $funeral_home = sanitize_text_field( $card['detail_funeral_home'] );
        }

        // Location from detail page funeral home address
        if ( empty( $location ) && ! empty( $card['detail_location'] ) ) {
            $location = $card['detail_location'];
        }

        // Published date from detail page as fallback
        if ( empty( $card['published_date'] ) && ! empty( $card['detail_published_date'] ) ) {
            $card['published_date'] = $card['detail_published_date'];
        }

        // v3.12.0 FIX (Goal A): Death-date priority — prefer the textual death
        // date extracted from the obituary body over ALL other sources.
        //
        // Priority order (highest → lowest):
        //   1. death_date_from_text  — explicit phrase like "passed away on Jan 6, 2026"
        //   2. dates['death']        — from structured date range ("Jan 1, 1940 - Jan 10, 2026")
        //   3. published_date        — "Published online January 10, 2026" (fallback)
        //
        // Previous behavior (v3.10.2): death_date_from_text was only used when
        // dates['death'] was empty. This caused yorkregion.com records like
        // Paul Andrew Smith to store 2026-01-10 (published date) instead of
        // 2026-01-06 (actual death date from obituary text).
        if ( ! empty( $card['death_date_from_text'] ) ) {
            $text_death = $this->normalize_date( $card['death_date_from_text'] );
            if ( ! empty( $text_death ) ) {
                $date_of_death = $text_death;
            }
        }

        // Fall back to published_date as an approximate death date
        // (obituaries are typically published within days of death).
        if ( empty( $date_of_death ) && ! empty( $card['published_date'] ) ) {
            $date_of_death = $this->normalize_date( $card['published_date'] );
        }

        // v3.14.0: Birth-date extraction — use birth_date_from_text from detail page.
        if ( empty( $date_of_birth ) && ! empty( $card['birth_date_from_text'] ) ) {
            $text_birth = $this->normalize_date( $card['birth_date_from_text'] );
            if ( ! empty( $text_birth ) ) {
                $date_of_birth = $text_birth;
            }
        }

        // v3.10.2: REMOVED Jan 1 fabrication for year-only dates.
        // Previously (v3.6.0): $date_of_death = $card['year_death'] . '-01-01';
        // If no trustworthy death date exists after all extraction attempts,
        // date_of_death remains empty. The collector's validation gate
        // (class-source-collector.php line 224) will skip ingest:
        //   if ( empty( $record['date_of_death'] ) ) { continue; }
        // This is correct: we do not ingest records we cannot date reliably.
        //
        // v3.10.2: Do NOT fabricate birth dates either. date_of_birth is
        // DATE DEFAULT NULL, so empty is safe — no fabrication needed.

        // v3.14.0: Allow full description from detail page (up to 2000 chars).
        // Previously truncated to 200 chars (facts-only). Now that fetch_detail()
        // retrieves the full obituary text, we allow a much longer description
        // to give the detail page real content.
        if ( strlen( $description ) > 2000 ) {
            $description = substr( $description, 0, 1997 ) . '...';
        }

        $age = 0;
        if ( ! empty( $card['age'] ) ) {
            $age = intval( $card['age'] );
        } elseif ( ! empty( $description ) ) {
            $age = $this->extract_age_from_text( $description );
        }
        if ( 0 === $age && ! empty( $date_of_birth ) && ! empty( $date_of_death ) ) {
            $age = $this->calculate_age( $date_of_birth, $date_of_death );
        }
        // v3.10.2: Compute age from year_birth/year_death when full dates
        // are not available (no longer relies on fabricated -01-01 dates).
        if ( 0 === $age && ! empty( $card['year_birth'] ) && ! empty( $card['year_death'] ) ) {
            $year_age = intval( $card['year_death'] ) - intval( $card['year_birth'] );
            if ( $year_age > 0 && $year_age <= 130 ) {
                $age = $year_age;
            }
        }

        // v3.7.0: Fall back to the source registry's city when the card has no location.
        // Many yorkregion.com cards don't include a location in the HTML.
        if ( empty( $location ) && ! empty( $source['city'] ) ) {
            $location = $source['city'];
        }

        $city_normalized = $this->normalize_city( $location );

        $record = array(
            'name'             => $name,
            'date_of_birth'    => $date_of_birth,
            'date_of_death'    => $date_of_death,
            'age'              => $age,
            'funeral_home'     => $funeral_home,
            'location'         => sanitize_text_field( $location ),
            'image_url'        => '', // v3.14.0: See image logic below
            'description'      => $description,
            'source_url'       => isset( $card['detail_url'] ) ? esc_url_raw( $card['detail_url'] ) : '',
            'source_domain'    => $this->extract_domain( $source['base_url'] ),
            'source_type'      => $this->get_type(),
            'city_normalized'  => $city_normalized,
            'provenance_hash'  => '',
        );

        // v3.14.1: Include image from listing page CDN.
        // The images are served from cdn-otf-cas.prfct.cc (Tribute Technology CDN),
        // which are public, hotlinkable URLs designed for web display. These are
        // NOT the newspaper's own servers — they're the obituary platform's CDN.
        //
        // v3.15.2: Also allow d1q40j6jx1d8h6.cloudfront.net (Tribute Technology
        // detail page CDN) which serves higher-resolution obituary images.
        //
        // Policy:
        //   - CDN images (prfct.cc, tributetechnology, cdn-otf, cloudfront.net/Obituaries) are always included
        //   - Other image sources require image_allowlisted=1 in the source registry
        $image_allowlisted = ! empty( $source['image_allowlisted'] );

        // v3.15.2: Prefer detail page high-res image (cloudfront CDN) over listing thumbnail
        if ( ! empty( $card['detail_image_url'] ) ) {
            $card['image_url'] = $card['detail_image_url'];
        }

        if ( ! empty( $card['image_url'] ) ) {
            $is_cdn_image = (bool) preg_match( '/prfct\.cc|tributetech|cdn-otf|cloudfront\.net\/Obituaries/i', $card['image_url'] );
            if ( $is_cdn_image || $image_allowlisted ) {
                $record['image_url'] = esc_url_raw( $card['image_url'] );
            }
        }

        $record['provenance_hash'] = $this->generate_provenance_hash( $record );
        return $record;
    }

    private function parse_date_range( $text ) {
        $result = array( 'birth' => '', 'death' => '' );
        if ( empty( $text ) ) {
            return $result;
        }

        $separators = array( ' - ', ' – ', ' — ', ' to ', '~' );
        foreach ( $separators as $sep ) {
            if ( false !== strpos( $text, $sep ) ) {
                $parts = explode( $sep, $text, 2 );
                $result['birth'] = $this->normalize_date( trim( $parts[0] ) );
                $result['death'] = $this->normalize_date( trim( $parts[1] ) );
                return $result;
            }
        }

        $result['death'] = $this->normalize_date( $text );
        return $result;
    }
}
