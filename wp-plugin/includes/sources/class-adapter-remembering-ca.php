<?php
/**
 * Remembering.ca / Postmedia Obituary Network Adapter
 *
 * Handles obituaries from the Remembering.ca platform, which powers
 * obituary sections for Postmedia newspapers across Canada:
 *   - obituaries.yorkregion.com
 *   - obituaries.thestar.com
 *   - obituaries.thespec.com
 *   - obituaries.therecord.com
 *   - obituaries.simcoe.com
 *   - obituaries.niagarafallsreview.ca
 *   - obituaries.stcatharinesstandard.ca
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
        //
        // v4.6.5 FIX: Changed \w+ to \S+ in all word-token patterns.
        // \w+ only matches [a-zA-Z0-9_] and BREAKS on commas/punctuation.
        // Example: "passing of David Beveridge Herriot, age 95, on January 5, 2025"
        //   - \w+ stops at "Herriot" (comma breaks match) → regex fails → wrong date
        //   - \S+ matches "Herriot," (any non-whitespace) → regex succeeds → correct date
        // This bug caused ~28% of obituaries to get the Published date instead of
        // the actual death date (e.g., Jan 9 instead of Jan 5 for Herriot).
        $month_pattern = '(?:January|February|March|April|May|June|July|August|September|October|November|December)';
        $weekday = '(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)';
        $death_phrases = array(
            // v4.6.6: "passed away [up to 18 words] [on [weekday,]] Month Day, Year"
            // v4.6.5 had {0,12} but real obituaries like Burton have 14 words between
            // "passed away" and the date (e.g., "surrounded by the love and comfort of
            // family at Southlake Regional Health Centre on February 10, 2026").
            '/(?:passed\s+away|passed\s+peacefully|passed\s+suddenly|peacefully\s+passed)(?:\s+\S+){0,18}?\s+(?:on\s+)?(?:' . $weekday . ',?\s+)?(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
            // "peaceful passing of...on/at...Month Day, Year"
            '/(?:peaceful\s+)?passing\s+of(?:\s+\S+){0,10}?(?:\s+on|\s+at)(?:\s+\S+){0,3}?\s+(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
            // v4.6.5: "[her/his/their] passing on Month Day, Year"
            // Handles: "announce her passing on November 5, 2025"
            // v4.6.8: Added optional adjective (gentle, sudden, unexpected, untimely) and weekday.
            // Helen Biro's obit says "announce her gentle passing on Friday, January 23, 2026".
            '/(?:his|her|their)\s+(?:gentle\s+|sudden\s+|unexpected\s+|untimely\s+)?passing\s+(?:on\s+)?(?:' . $weekday . ',?\s+)?(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
            // v4.6.5: "Peacefully [at/in place words] on [weekday,] Month Day, Year"
            // Handles: "Peacefully at home with family on Friday, July 11, 2025"
            '/[Pp]eacefully(?:\s+\S+){0,12}?\s+on\s+(?:' . $weekday . ',?\s+)?(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
            // v4.6.5: "Passing gently/peacefully/quietly [at place] on [weekday,] Month Day, Year"
            // Handles: "Passing gently at home on Saturday, November 8, 2025"
            '/[Pp]assing\s+(?:gently|peacefully|quietly|softly)(?:\s+\S+){0,8}?\s+on\s+(?:' . $weekday . ',?\s+)?(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
            // "left us [peacefully] [on] Month Day, Year"
            '/left\s+us\s+(?:peacefully\s+)?(?:on\s+)?(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
            // v4.6.6: "entered into rest" / "called to [her/his] [eternal] rest" on Month Day, Year
            // v4.6.5 only had "entered into rest"; obituaries also say "called to her eternal rest".
            '/(?:entered\s+into|called\s+to(?:\s+\S+){0,3}?)\s+rest(?:\s+on)?\s+(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
            // v4.6.6: "died [up to 15 words] [on] Month Day, Year"
            // v4.6.5 had {0,8} but Horsman has 11 words: "died peacefully, in her sleep,
            // surrounded by the love of her family on February 11, 2026".
            '/died(?:\s+\S+){0,15}?\s+(?:on\s+)?(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
            // v4.6.6: "went [home] to be with" / "called home" — up to 6 words.
            // v4.6.5 missed "went home to be with" (had "went to be with" without "home").
            '/(?:went\s+(?:home\s+)?to\s+be\s+with|called\s+home)(?:\s+\S+){0,6}?\s+(?:on\s+)?(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
            // v4.6.6: "On [weekday,] Month Day, Year [0-5 words] passed away/peacefully/died"
            // v4.6.5 had {0,3} and missed "passed peacefully". Expanded word gap and variants.
            '/[Oo]n\s+(?:' . $weekday . ',?\s+)?(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})\s*,?\s+(?:\S+\s+){0,5}(?:passed\s+away|passed\s+peacefully|passed|died)/iu',
            // Date range in parens: (Month Day, Year - Month Day, Year)
            '/\(' . $month_pattern . '\s+\d{1,2},?\s+\d{4}\s*[-\x{2013}\x{2014}]\s*(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})\)/u',
            // v4.6.5: "announce the passing of [Name] on Month Day, Year"
            '/announce\s+the\s+(?:sudden\s+)?passing\s+of(?:\s+\S+){0,10}?\s+(?:on|at)\s+(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
            // v4.6.6: "announce the death of [Name] on Month Day, Year"
            // Handles: "we announce the death of our beloved mother on February 11, 2026"
            '/announce\s+the\s+(?:sudden\s+)?death\s+of(?:\s+\S+){0,10}?\s+(?:on|at)\s+(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
            // v4.6.6: "the death of ... on Month Day, Year" (broad fallback)
            '/(?:the\s+)?death\s+of(?:\s+\S+){0,10}?\s+(?:on\s+)?(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
            // v4.6.6: "left this world [peacefully] on Month Day, Year"
            '/left\s+this\s+world(?:\s+\S+){0,10}?\s+(?:on\s+)?(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
            // v4.6.8: Broad fallback — "on [weekday,] Month Day, Year, in his/her Nth year"
            // Catches: "at the Greater Niagara General Hospital, on January 21, 2026, in her 93rd year"
            // where no explicit death keyword precedes the date.
            '/(?:on\s+)?(?:' . $weekday . ',?\s+)?(' . $month_pattern . '\s+\d{1,2},?\s+\d{4}),?\s+in\s+(?:his|her|their)\s+\d+(?:st|nd|rd|th)\s+year/iu',
            // v4.6.8: "on [weekday,] Month Day, Year, at the age of N"
            // Catches dates followed by age statements even without a death keyword.
            '/(?:on\s+)?(?:' . $weekday . ',?\s+)?(' . $month_pattern . '\s+\d{1,2},?\s+\d{4}),?\s+at\s+the\s+age\s+of\s+\d+/iu',
            // v4.6.8: "on Monday, Month Day, Year at the NHS" — date before hospital
            '/(?:on\s+)?(?:' . $weekday . ',?\s+)?(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})\s+at\s+(?:the\s+)?(?:NHS|hospital|home|hospice)/iu',
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

        // v3.15.3: Extract location from description text when structured location is empty.
        // Many Remembering.ca listings start with "City, ON - Name" in the description.
        // Example: "Unionville, ON - Ralph George Harvey Pyle"
        if ( empty( $card['location'] ) ) {
            $desc_text_for_loc = $this->node_text( $node );
            if ( preg_match( '/\b([A-Z][a-zA-Z\s\.\'-]+),\s*ON\b/', $desc_text_for_loc, $loc_text_m ) ) {
                $loc_candidate = trim( $loc_text_m[1] );
                if ( strlen( $loc_candidate ) > 1 && strlen( $loc_candidate ) < 50 ) {
                    $card['location'] = $loc_candidate;
                }
            }
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
            // v3.15.2: Add space before stripping tags to prevent word concatenation
            // (e.g., "Pyle</span>1926" should become "Pyle 1926", not "Pyle1926")
            $desc_text = preg_replace( '/<\/(?:p|div|h[1-6]|li|span|strong|em|b|i)>/i', ' ', $desc_text );
            $desc_text = wp_strip_all_tags( $desc_text );
            $desc_text = preg_replace( '/[ \t]+/', ' ', $desc_text ); // Collapse horizontal whitespace
            $desc_text = preg_replace( '/\n{3,}/', "\n\n", trim( $desc_text ) );
            if ( strlen( $desc_text ) > 50 ) {
                $detail['detail_full_description'] = $desc_text;
            }
        }

        // 2. Funeral home from "Funeral Arrangements by" section
        //    SSR HTML: <h6>Funeral Arrangements by</h6><div class="...">Name</div>
        //    v3.15.2: Also decode HTML entities (e.g., &amp; → &) in funeral home name.
        if ( preg_match( '/Funeral\s+Arrangements\s+by<\/h6>\s*<div[^>]*>([^<]+)<\/div>/i', $html, $fh_m ) ) {
            $fh_name = html_entity_decode( trim( $fh_m[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
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
        //    or Image_N.jpg (e.g., Image_3.jpg, Image_85.jpg) depending on the obituary.
        //    URL may appear with escaped quotes (\\") in RSC payload or normal quotes in SSR HTML.
        //    Also try the d36ewrdt9mbbbo CDN which some pages use.
        //
        //    v3.15.2: Fixed regex to match Image_N.jpg variants (previously only matched Image.jpg).
        if ( preg_match( '/(https:\\/\\/d1q40j6jx1d8h6\\.cloudfront\\.net\\/Obituaries\\/\\d+\\/Image(?:_\\d+)?\\.jpg)/', $html, $img_m ) ) {
            $detail['detail_image_url'] = $img_m[1];
        } elseif ( preg_match( '/(https:\\/\\/d36ewrdt9mbbbo\\.cloudfront\\.net\\/Obituaries\\/\\d+\\/Image(?:_\\d+)?\\.(?:jpg|webp))/', $html, $img_m2 ) ) {
            $detail['detail_image_url'] = $img_m2[1];
        }

        // 5. Location from detail page
        //    v3.15.3: The RSC JSON city field is unreliable — tested on 24 live
        //    pages and it returned no matches for any. Instead, extract location
        //    from the obituary description text itself. Common patterns:
        //      - "City, ON -" at start of description (e.g., "Unionville, ON -")
        //      - "at [Hospital] in [City]" or "at home in [City]"
        //      - Google Maps link in page HTML with funeral home address
        //
        //    Priority:
        //      1. "City, ON" pattern at start of description (most reliable)
        //      2. Google Maps query string containing city/province
        //      3. RSC escaped JSON (kept as fallback, rarely works)

        $loc_found = false;

        // Strategy 1: "City, ON" at start of description
        if ( ! $loc_found && ! empty( $detail['detail_full_description'] ) ) {
            // "Unionville, ON - Ralph George Harvey Pyle"
            if ( preg_match( '/^([A-Z][a-zA-Z\s\.\'-]+),\s*ON\b/u', trim( $detail['detail_full_description'] ), $loc_desc ) ) {
                $city = trim( $loc_desc[1] );
                if ( strlen( $city ) > 1 && strlen( $city ) < 50 ) {
                    $detail['detail_location'] = $city;
                    $loc_found = true;
                }
            }
        }

        // Strategy 2: Google Maps link with funeral home address — city before ",+ON"
        // URL format: ...+City+Name%2C+ON+PostalCode or ...+City+Name,+ON+PostalCode
        if ( ! $loc_found && preg_match( '/google\.com\/maps\/search\/[^"]*?([A-Z][a-z]+(?:\+[A-Z][a-z]+)*?)(?:%2C|\+),?\+?ON\b/', $html, $maps_m ) ) {
            $city = str_replace( '+', ' ', trim( $maps_m[1] ) );
            if ( strlen( $city ) > 1 && strlen( $city ) < 50 ) {
                $detail['detail_location'] = $city;
                $loc_found = true;
            }
        }

        // Strategy 3: RSC escaped JSON (legacy fallback)
        if ( ! $loc_found && preg_match( '/\\\\"city\\\\":\\\\"([^\\\\]+)\\\\"/', $html, $loc_m ) ) {
            $city = trim( $loc_m[1], ', ' );
            if ( ! empty( $city ) && strlen( $city ) > 1 ) {
                $detail['detail_location'] = $city;
                $loc_found = true;
            }
        }
        if ( ! $loc_found && preg_match( '/"city":\s*"([^"]+?)"/', $html, $loc_m2 ) ) {
            $city = trim( $loc_m2[1], ', ' );
            if ( ! empty( $city ) && strlen( $city ) > 1 ) {
                $detail['detail_location'] = $city;
            }
        }

        // 6. Extract death date and age from the full description text.
        //    v3.15.2: Extract death date and age from the SSR description,
        //    which is the most reliable source of this data.
        if ( ! empty( $detail['detail_full_description'] ) ) {
            $desc_text = $detail['detail_full_description'];
            $month_p = '(?:January|February|March|April|May|June|July|August|September|October|November|December)';

            // Death date extraction — expanded patterns for v3.15.2
            // v4.2.5: Added 's' (DOTALL) flag to all patterns so \s matches \n
            // Source descriptions contain newlines mid-date ("May 7,\n\n2024")
            // v4.6.5 FIX: Changed \w+ to \S+, increased word limits, added weekday
            // handling, and new patterns. See extract_card() for full explanation.
            $weekday_p = '(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)';
            $death_patterns = array(
                // v4.6.6: "passed away [up to 18 words] [on [weekday,]] Month Day, Year"
                // Increased from {0,12} to {0,18} — see extract_card() for explanation.
                '/(?:passed\s+away|passed\s+peacefully|passed\s+suddenly|peacefully\s+passed)(?:\s+\S+){0,18}?\s+(?:on\s+)?(?:' . $weekday_p . ',?\s+)?(' . $month_p . '\s+\d{1,2},?\s+\d{4})/isu',
                // "peaceful passing of...on/at...Month Day, Year" (Betty Cudmore pattern)
                '/(?:peaceful\s+)?passing\s+of(?:\s+\S+){0,10}?(?:\s+on|\s+at)(?:\s+\S+){0,3}?\s+(' . $month_p . '\s+\d{1,2},?\s+\d{4})/isu',
                // "[her/his/their] [gentle/sudden] passing on [weekday,] Month Day, Year"
                // v4.6.8: Added optional adjective and weekday — see extract_card() for details.
                '/(?:his|her|their)\s+(?:gentle\s+|sudden\s+|unexpected\s+|untimely\s+)?passing\s+(?:on\s+)?(?:' . $weekday_p . ',?\s+)?(' . $month_p . '\s+\d{1,2},?\s+\d{4})/isu',
                // "Peacefully [at/in place words] on [weekday,] Month Day, Year"
                '/[Pp]eacefully(?:\s+\S+){0,12}?\s+on\s+(?:' . $weekday_p . ',?\s+)?(' . $month_p . '\s+\d{1,2},?\s+\d{4})/isu',
                // "Passing gently/peacefully/quietly [at place] on [weekday,] Month Day, Year"
                '/[Pp]assing\s+(?:gently|peacefully|quietly|softly)(?:\s+\S+){0,8}?\s+on\s+(?:' . $weekday_p . ',?\s+)?(' . $month_p . '\s+\d{1,2},?\s+\d{4})/isu',
                // "left us peacefully on Month Day, Year"
                '/left\s+us\s+(?:peacefully\s+)?(?:on\s+)?(' . $month_p . '\s+\d{1,2},?\s+\d{4})/isu',
                // v4.6.6: "entered into rest" / "called to [her/his] [eternal] rest"
                '/(?:entered\s+into|called\s+to(?:\s+\S+){0,3}?)\s+rest(?:\s+on)?\s+(' . $month_p . '\s+\d{1,2},?\s+\d{4})/isu',
                // v4.6.6: "died [up to 15 words] [on] Month Day, Year"
                // Increased from {0,8} — see extract_card() for explanation.
                '/died(?:\s+\S+){0,15}?\s+(?:on\s+)?(' . $month_p . '\s+\d{1,2},?\s+\d{4})/isu',
                // v4.6.6: "went [home] to be with" / "called home" — up to 6 words
                '/(?:went\s+(?:home\s+)?to\s+be\s+with|called\s+home)(?:\s+\S+){0,6}?\s+(?:on\s+)?(' . $month_p . '\s+\d{1,2},?\s+\d{4})/isu',
                // "on Friday, Month Day, Year, in his/her Nth year"
                '/on\s+\S+,?\s+(' . $month_p . '\s+\d{1,2},?\s+\d{4}),?\s+in\s+(?:his|her)/isu',
                // v4.6.6: "On [weekday,] Month Day, Year [0-5 words] passed away/peacefully/died"
                '/[Oo]n\s+(?:' . $weekday_p . ',?\s+)?(' . $month_p . '\s+\d{1,2},?\s+\d{4})\s*,?\s+(?:\S+\s+){0,5}(?:passed\s+away|passed\s+peacefully|passed|died)/isu',
                // Date range in parens: (Month Day, Year - Month Day, Year)
                '/\(' . $month_p . '\s+\d{1,2},?\s+\d{4}\s*[-\x{2013}\x{2014}]\s*(' . $month_p . '\s+\d{1,2},?\s+\d{4})\)/us',
                // Date range without parens: "Month Day, Year - Month Day, Year"
                '/' . $month_p . '\s+\d{1,2},?\s+\d{4}\s*[-\x{2013}\x{2014}]\s*(' . $month_p . '\s+\d{1,2},?\s+\d{4})/us',
                // "announce the passing of [Name] on Month Day, Year"
                '/announce\s+the\s+(?:sudden\s+)?passing\s+of(?:\s+\S+){0,10}?\s+(?:on|at)\s+(' . $month_p . '\s+\d{1,2},?\s+\d{4})/isu',
                // v4.6.6: "announce the death of [Name] on Month Day, Year"
                '/announce\s+the\s+(?:sudden\s+)?death\s+of(?:\s+\S+){0,10}?\s+(?:on|at)\s+(' . $month_p . '\s+\d{1,2},?\s+\d{4})/isu',
                // v4.6.6: "the death of ... on Month Day, Year"
                '/(?:the\s+)?death\s+of(?:\s+\S+){0,10}?\s+(?:on\s+)?(' . $month_p . '\s+\d{1,2},?\s+\d{4})/isu',
                // v4.6.6: "left this world [peacefully] on Month Day, Year"
                '/left\s+this\s+world(?:\s+\S+){0,10}?\s+(?:on\s+)?(' . $month_p . '\s+\d{1,2},?\s+\d{4})/isu',
                // v4.6.8: Broad fallback — "on [weekday,] Month Day, Year, in his/her Nth year"
                '/(?:on\s+)?(?:' . $weekday_p . ',?\s+)?(' . $month_p . '\s+\d{1,2},?\s+\d{4}),?\s+in\s+(?:his|her|their)\s+\d+(?:st|nd|rd|th)\s+year/isu',
                // v4.6.8: "on [weekday,] Month Day, Year, at the age of N"
                '/(?:on\s+)?(?:' . $weekday_p . ',?\s+)?(' . $month_p . '\s+\d{1,2},?\s+\d{4}),?\s+at\s+the\s+age\s+of\s+\d+/isu',
                // v4.6.8: "on Monday, Month Day, Year at the NHS" — date before hospital
                '/(?:on\s+)?(?:' . $weekday_p . ',?\s+)?(' . $month_p . '\s+\d{1,2},?\s+\d{4})\s+at\s+(?:the\s+)?(?:NHS|hospital|home|hospice)/isu',
            );
            foreach ( $death_patterns as $dp ) {
                if ( preg_match( $dp, $desc_text, $dm ) ) {
                    // v4.2.5: collapse whitespace (newlines inside dates break strtotime)
                    $detail['detail_death_date'] = preg_replace( '/\s+/', ' ', trim( $dm[1] ) );
                    break;
                }
            }

            // Age extraction — v3.15.2: expanded patterns
            // v4.6.8 FIX: "in his/her Nth year" means the person was N-1 years old.
            // Example: "in her 93rd year" = she was 92 (had completed 92 years, was in the 93rd).
            // Previously this extracted 93 as the age, which was wrong.
            // Same fix applies to "lived into her Nth year" and "his/her Nth year" patterns.
            //
            // v5.1.0 FIX (ID-454): "at the age of N" and "aged N" patterns now require
            // the same sentence to contain a death keyword. This prevents biographical
            // mentions (e.g. "admitted at the age of 16") from being captured as death age.
            if ( preg_match( '/(?:in|into|lived\s+into)\s+(?:his|her|their)\s+(\d+)(?:st|nd|rd|th)\s+year/i', $desc_text, $age_m ) ) {
                $detail['detail_age'] = intval( $age_m[1] ) - 1;
            } elseif ( preg_match( '/(?:his|her|their)\s+(\d+)(?:st|nd|rd|th)\s+birthday/i', $desc_text, $age_m4 ) ) {
                // "her 96th birthday" — means they reached/would have reached age 96.
                $detail['detail_age'] = intval( $age_m4[1] );
            } elseif ( preg_match( '/(?:his|her|their)\s+(\d+)(?:st|nd|rd|th)\s+year/i', $desc_text, $age_m5 ) ) {
                // "his 80th year" — same as "in his 80th year" = age 79.
                $detail['detail_age'] = intval( $age_m5[1] ) - 1;
            } else {
                // v5.1.0: sentence-scoped "at the age of N" / "aged N" — require death keyword.
                // Use shared constant from base adapter for keyword consistency.
                // Normalize text first: strip HTML tags (replace block-level with ". "),
                // collapse whitespace, so sentence splitting is reliable.
                $clean_text = preg_replace( '/<\s*(?:br|p|div)[^>]*>/i', '. ', $desc_text );
                $clean_text = wp_strip_all_tags( $clean_text );
                $clean_text = preg_replace( '/\s+/', ' ', $clean_text );
                $clean_text = trim( $clean_text );
                $sentences = preg_split( '/(?<=[.!?;])\s+/', $clean_text );
                foreach ( $sentences as $s ) {
                    if ( ! preg_match( '/' . self::DEATH_KEYWORDS_REGEX . '/i', $s ) ) {
                        continue;
                    }
                    if ( preg_match( '/\bat\s+the\s+age\s+of\s+(\d+)/i', $s, $age_m3 ) ) {
                        $detail['detail_age'] = intval( $age_m3[1] );
                        break;
                    }
                    if ( preg_match( '/\bage(?:d)?\s+(\d+)/i', $s, $age_m2 ) ) {
                        $detail['detail_age'] = intval( $age_m2[1] );
                        break;
                    }
                }
            }

            // Birth date from text: "born on Month Day, Year" or "born Month Day, Year"
            if ( preg_match( '/born(?:\s+(?:on|in))?\s+(?:' . $month_p . '\s+\d{1,2},?\s+\d{4})/iu', $desc_text, $birth_m ) ) {
                $detail['detail_birth_date'] = trim( $birth_m[0] );
                // Extract just the date part
                if ( preg_match( '/(' . $month_p . '\s+\d{1,2},?\s+\d{4})/iu', $detail['detail_birth_date'], $bd ) ) {
                    $detail['detail_birth_date'] = $bd[1];
                }
            }
            // Birth date from range: (Month Day, Year - Month Day, Year) — birth is the first date
            if ( empty( $detail['detail_birth_date'] ) && preg_match( '/\((' . $month_p . '\s+\d{1,2},?\s+\d{4})\s*[-\x{2013}\x{2014}]\s*' . $month_p . '\s+\d{1,2},?\s+\d{4}\)/u', $desc_text, $bdr ) ) {
                $detail['detail_birth_date'] = trim( $bdr[1] );
            }

            // v3.15.2: Extract year range (e.g., "1926 - 2026") from description.
            // Some obituaries only provide birth/death years, not full dates.
            // Example: Ralph Pyle — "1926 - 2026" in the description text.
            // This provides year_birth/year_death for age computation.
            if ( preg_match( '/\b(\d{4})\s*[-\x{2013}\x{2014}~]\s*(\d{4})\b/u', $desc_text, $yr_range ) ) {
                $yr_birth = intval( $yr_range[1] );
                $yr_death = intval( $yr_range[2] );
                if ( $yr_death > $yr_birth && $yr_death >= 2020 && $yr_death <= 2030 && $yr_birth >= 1890 ) {
                    $detail['detail_year_birth'] = $yr_range[1];
                    $detail['detail_year_death'] = $yr_range[2];
                    // Compute age from year range if not already extracted.
                    // v4.6.8: Use full birth/death dates for precise calculation when available.
                    if ( empty( $detail['detail_age'] ) ) {
                        if ( ! empty( $detail['detail_birth_date'] ) && ! empty( $detail['detail_death_date'] ) ) {
                            // Try precise calculation from full dates
                            $bd_ts = strtotime( $detail['detail_birth_date'] );
                            $dd_ts = strtotime( $detail['detail_death_date'] );
                            if ( $bd_ts && $dd_ts && $dd_ts > $bd_ts ) {
                                $bd_obj = new DateTime( '@' . $bd_ts );
                                $dd_obj = new DateTime( '@' . $dd_ts );
                                $detail['detail_age'] = $dd_obj->diff( $bd_obj )->y;
                            } else {
                                $detail['detail_age'] = $yr_death - $yr_birth;
                            }
                        } else {
                            $detail['detail_age'] = $yr_death - $yr_birth;
                        }
                    }
                }
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

        // v4.2.4 FIX: Death-date priority — phrase-based extraction from the
        // obituary body ("passed away on...") is the most reliable source.
        // The structured year range on Remembering.ca can be WRONG — the platform
        // sometimes updates the death year to the current year when republishing
        // older obituaries (e.g., "1950-2026" when the person actually died in 2024).
        //
        // Priority order (highest → lowest):
        //   1. death_date_from_text   — "passed away on Jan 6, 2025" (from listing card body)
        //   2. detail_death_date      — phrase-extracted from detail page description
        //   3. dates['death']         — from structured date range ("Jan 1, 1940 - Jan 10, 2026")
        //   4. published_date         — "Published online January 10, 2026" (last resort)
        //
        // v4.2.4: Swapped priorities #1 and #2. The listing card death_date_from_text
        // is extracted using ONLY death-keyword phrases ("passed away", "died", etc.)
        // while detail_death_date can also match generic date ranges that may contain
        // the wrong year from the source platform's metadata.

        // First priority: phrase-extracted death date from listing card text
        if ( ! empty( $card['death_date_from_text'] ) ) {
            $text_death = $this->normalize_date( $card['death_date_from_text'] );
            if ( ! empty( $text_death ) ) {
                $date_of_death = $text_death;
            }
        }

        // Second priority: phrase-extracted death date from detail page
        if ( empty( $date_of_death ) && ! empty( $card['detail_death_date'] ) ) {
            $detail_death = $this->normalize_date( $card['detail_death_date'] );
            if ( ! empty( $detail_death ) ) {
                $date_of_death = $detail_death;
            }
        }

        // v4.2.4: Cross-validation — if detail_death_date disagrees with
        // death_date_from_text on the YEAR, trust the text-based phrase.
        // This catches cases where Remembering.ca shows "1950-2026" in metadata
        // but the obituary body says "passed away on April 9, 2025".
        if ( ! empty( $date_of_death ) && ! empty( $card['death_date_from_text'] ) ) {
            $text_death_norm = $this->normalize_date( $card['death_date_from_text'] );
            if ( ! empty( $text_death_norm ) ) {
                $current_year = intval( substr( $date_of_death, 0, 4 ) );
                $text_year    = intval( substr( $text_death_norm, 0, 4 ) );
                if ( $current_year !== $text_year && $text_year >= 2018 && $text_year <= intval( date( 'Y' ) ) ) {
                    ontario_obituaries_log(
                        sprintf(
                            'v4.2.4: Death date cross-validation override — %s (structured) → %s (text phrase) for "%s".',
                            $date_of_death, $text_death_norm, $name
                        ),
                        'info'
                    );
                    $date_of_death = $text_death_norm;
                }
            }
        }

        // v4.2.4: Reject future death dates — if date_of_death is in the future,
        // it's almost certainly wrong metadata from the source platform.
        if ( ! empty( $date_of_death ) && $date_of_death > date( 'Y-m-d' ) ) {
            ontario_obituaries_log(
                sprintf(
                    'v4.2.4: Rejecting future death date %s for "%s" — using text-based date or clearing.',
                    $date_of_death, $name
                ),
                'warning'
            );
            // Try text-based date as fallback
            if ( ! empty( $card['death_date_from_text'] ) ) {
                $fallback = $this->normalize_date( $card['death_date_from_text'] );
                if ( ! empty( $fallback ) && $fallback <= date( 'Y-m-d' ) ) {
                    $date_of_death = $fallback;
                } else {
                    $date_of_death = '';
                }
            } else {
                $date_of_death = '';
            }
        }

        // v5.1.0 FIX (ID-454): REMOVED fallback that copied published_date into
        // date_of_death. This violated the 100% factual rule — the publication
        // date is NOT the death date. When no death date can be extracted, the
        // record MUST have an empty date_of_death, which the collector's
        // validation gate (class-source-collector.php line 327) will block from
        // ingest. This is the correct behaviour: we do not publish records we
        // cannot date reliably.
        //
        // Previously (v3.12.0):
        //   if ( empty( $date_of_death ) && ! empty( $card['published_date'] ) ) {
        //       $date_of_death = $this->normalize_date( $card['published_date'] );
        //   }
        //
        // The published_date is still captured in extract_card() (line 303) and
        // stored in the card array for audit/debugging, but it is no longer
        // promoted to date_of_death.

        // v3.15.2: Birth-date extraction — detail page takes priority.
        if ( empty( $date_of_birth ) && ! empty( $card['detail_birth_date'] ) ) {
            $detail_birth = $this->normalize_date( $card['detail_birth_date'] );
            if ( ! empty( $detail_birth ) ) {
                $date_of_birth = $detail_birth;
            }
        }
        // v3.14.0: Birth-date extraction — listing card text.
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
        // v3.15.2: detail_age from SSR description is most accurate
        if ( ! empty( $card['detail_age'] ) && intval( $card['detail_age'] ) > 0 ) {
            $age = intval( $card['detail_age'] );
        } elseif ( ! empty( $card['age'] ) ) {
            $age = intval( $card['age'] );
        } elseif ( ! empty( $description ) ) {
            $age = $this->extract_age_from_text( $description );
        }
        if ( 0 === $age && ! empty( $date_of_birth ) && ! empty( $date_of_death ) ) {
            $age = $this->calculate_age( $date_of_birth, $date_of_death );
        }
        // v3.10.2: Compute age from year_birth/year_death when full dates
        // are not available (no longer relies on fabricated -01-01 dates).
        // v4.6.8 FIX: Year-only age calculation must account for whether the
        // birthday has already occurred in the death year. Simple subtraction
        // (death_year - birth_year) gives age+1 when death occurs before birthday.
        // When we have a full birth date AND full death date, use calculate_age().
        // When we only have years, the result is approximate — subtract 1 to avoid
        // overstating (it's better to show 96 than 97 when the real age is 96).
        if ( 0 === $age && ! empty( $card['year_birth'] ) && ! empty( $card['year_death'] ) ) {
            $year_age = intval( $card['year_death'] ) - intval( $card['year_birth'] );
            // If we have full birth and death dates, compute precisely
            if ( ! empty( $date_of_birth ) && ! empty( $date_of_death ) ) {
                $precise_age = $this->calculate_age( $date_of_birth, $date_of_death );
                if ( $precise_age > 0 ) {
                    $year_age = $precise_age;
                }
            }
            if ( $year_age > 0 && $year_age <= 130 ) {
                $age = $year_age;
            }
        }
        // v3.15.2: Also use detail page year range when listing card didn't have years
        if ( 0 === $age && ! empty( $card['detail_year_birth'] ) && ! empty( $card['detail_year_death'] ) ) {
            $year_age = intval( $card['detail_year_death'] ) - intval( $card['detail_year_birth'] );
            // If we have full birth and death dates, compute precisely
            if ( ! empty( $date_of_birth ) && ! empty( $date_of_death ) ) {
                $precise_age = $this->calculate_age( $date_of_birth, $date_of_death );
                if ( $precise_age > 0 ) {
                    $year_age = $precise_age;
                }
            }
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
                // v4.0.1: Filter out funeral home logos masquerading as obituary images.
                // Logos are typically under 15 KB; real portraits are 20 KB+.
                // A lightweight HEAD request checks Content-Length without downloading
                // the full image. This prevents copyright issues from displaying
                // funeral home branding (e.g., Ogden, Ridley logos) as obituary photos.
                if ( $this->is_likely_portrait( $card['image_url'] ) ) {
                    $record['image_url'] = esc_url_raw( $card['image_url'] );
                }
            }
        }

        $record['provenance_hash'] = $this->generate_provenance_hash( $record );
        return $record;
    }

    /**
     * v4.0.1: Check if an image URL is likely a real portrait (not a funeral home logo).
     *
     * Funeral home logos on Remembering.ca CDN are typically small files (< 15 KB),
     * while real obituary portraits are 20 KB+. This method does a lightweight HTTP
     * HEAD request to check Content-Length without downloading the image body.
     *
     * Returns false (skip image) if:
     *   - The image is smaller than 15 KB (likely a logo/graphic)
     *   - The HEAD request fails (conservative: skip rather than risk a logo)
     *
     * Performance: HEAD requests are ~50ms each and only fire for CDN images.
     * With ~175 obituaries per cycle, this adds ~9s total — acceptable.
     *
     * @param string $url The image URL to check.
     * @return bool True if the image is likely a portrait, false if likely a logo.
     */
    private function is_likely_portrait( $url ) {
        // Minimum file size for a real portrait photo (in bytes).
        // Observed: logos 5-12 KB, portraits 20 KB - 500+ KB.
        $min_portrait_bytes = 15360; // 15 KB

        $response = oo_safe_http_head( 'SCRAPE', $url, array(
            'timeout' => 5,
        ), array( 'source' => 'class-adapter-remembering-ca', 'purpose' => 'portrait-check' ) );

        if ( is_wp_error( $response ) ) {
            // Network error or HTTP ≥ 400 — skip image to be safe.
            // Wrapper already logged the failure.
            return false;
        }

        $content_length = wp_remote_retrieve_header( $response, 'content-length' );

        if ( empty( $content_length ) ) {
            // No Content-Length header — allow the image (can't determine size).
            return true;
        }

        $size = intval( $content_length );

        if ( $size < $min_portrait_bytes ) {
            ontario_obituaries_log(
                sprintf(
                    'v4.0.1: Rejected image as likely logo — %s (%s bytes, threshold %s).',
                    $url,
                    number_format( $size ),
                    number_format( $min_portrait_bytes )
                ),
                'debug'
            );
            return false;
        }

        return true;
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
