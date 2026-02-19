<?php
/**
 * Image Localizer
 *
 * Downloads external obituary images to the local server, eliminating hotlink
 * dependencies on third-party CDNs (cdn-otf-cas.prfct.cc, etc.).
 *
 * Architecture:
 *   - Runs as a batched cron job (20 images per tick, every 5 minutes)
 *   - Self-removes the cron hook when migration is complete
 *   - Uses wp_safe_remote_get() with stream-to-disk (zero in-memory body)
 *   - Validates filesize on disk after download (CDN can't lie about Content-Length)
 *   - Updates image_url in-place (templates need ZERO changes)
 *   - Backs up original external URL to image_local_url column
 *   - Stores thumbnail path in image_thumb_url column
 *
 * Data flow:
 *   BEFORE: image_url = "https://cdn-otf-cas.prfct.cc/dfs1/eyJ..."
 *           image_local_url = NULL
 *           image_thumb_url = NULL
 *
 *   AFTER:  image_url = "https://monacomonuments.ca/wp-content/uploads/ontario-obituaries/obit-abc123.jpg"
 *           image_local_url = "https://cdn-otf-cas.prfct.cc/dfs1/eyJ..."  (backup of original)
 *           image_thumb_url = "https://monacomonuments.ca/wp-content/uploads/ontario-obituaries/obit-abc123-thumb.jpg"
 *
 * Safety:
 *   - Skips images already on monacomonuments.ca (idempotent)
 *   - Skips images smaller than 5 KB (likely tracking pixels)
 *   - 10-second timeout per download (won't hang shared hosting)
 *   - 2 MB max file size (prevents disk abuse)
 *   - Transient lock prevents overlapping batches
 *   - Logs errors but never stops the batch for one bad image
 *   - On permanent failure: clears image_url (template hides the area)
 *   - On transient failure: keeps remote URL until next retry (max 3 retries)
 *   - Atomic file writes via temp-file-then-rename
 *   - SSL verification on by default (filterable for legacy hosts)
 *   - Uses wp_safe_remote_head/get (blocks private/reserved IPs)
 *   - Filetype validated by extension AND wp_check_filetype after download
 *
 * Column semantics (image_local_url is dual-purpose — by design):
 *   - On SUCCESS: image_local_url = original remote URL (backup for rollback)
 *   - On PERMANENT FAIL: image_local_url = 'failed:<reason>' (prevents re-processing)
 *   - This avoids adding extra columns to an already 20-column table for a
 *     one-time migration feature. Templates NEVER read image_local_url.
 *   - The 'failed:' prefix is explicitly excluded in get_completed_count(),
 *     get_pending_count(), the rollback query, and get_stats().
 *
 * Rollback (only reverses SUCCESSFUL localizations, not failures):
 *   UPDATE wp_ontario_obituaries
 *   SET image_url = image_local_url, image_local_url = NULL, image_thumb_url = NULL
 *   WHERE image_local_url IS NOT NULL
 *     AND image_local_url != ''
 *     AND image_local_url NOT LIKE 'failed:%'
 *     AND image_local_url NOT LIKE 'retry:%';
 *
 * Rollback for permanent failures (restore remote URLs that were cleared):
 *   -- Not possible from image_local_url alone because it contains 'failed:reason'
 *   -- not the original URL. If needed, re-scrape the source to get fresh URLs.
 *
 * Post-migration verification query:
 *   SELECT
 *     SUM(image_url LIKE '%cdn-otf-cas.prfct.cc%') AS still_remote,
 *     SUM(image_url LIKE '%/wp-content/uploads/%')  AS local,
 *     SUM(image_url = '')                           AS cleared,
 *     SUM(image_local_url LIKE 'failed:%')          AS failed,
 *     SUM(image_local_url LIKE 'retry:%')           AS retrying
 *   FROM wp_ontario_obituaries
 *   WHERE suppressed_at IS NULL;
 *
 * State transitions (3-row example):
 *   ┌──────────┬──────────────────────────┬──────────────────┬───────────────────┐
 *   │ State    │ image_url                │ image_local_url  │ image_thumb_url   │
 *   ├──────────┼──────────────────────────┼──────────────────┼───────────────────┤
 *   │ BEFORE   │ https://cdn.example/a.jpg│ NULL             │ NULL              │
 *   │ RETRY    │ https://cdn.example/a.jpg│ retry:1          │ NULL              │
 *   │ SUCCESS  │ /uploads/.../obit-*.jpg  │ https://cdn.ex...│ /uploads/..thumb  │
 *   │ FAIL     │ (empty)                  │ failed:http_404  │ NULL              │
 *   └──────────┴──────────────────────────┴──────────────────┴───────────────────┘
 *   On SUCCESS the original remote URL is ALWAYS written to image_local_url,
 *   overwriting any previous retry:N value. Templates render image_url only.
 *
 * @package Ontario_Obituaries
 * @since   5.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_Image_Localizer {

    /** @var int Max images to process per batch. */
    const BATCH_SIZE = 20;

    /** @var int Max download timeout in seconds. */
    const DOWNLOAD_TIMEOUT = 10;

    /** @var int Max file size in bytes (2 MB). */
    const MAX_FILE_SIZE = 2097152;

    /** @var int Min file size in bytes (5 KB) — skip tracking pixels and logos. */
    const MIN_FILE_SIZE = 5120;

    /** @var int Max transient-failure retries before marking permanent. */
    const MAX_RETRIES = 3;

    /** @var string Upload subdirectory (matches Image Pipeline). */
    const UPLOAD_DIR = 'ontario-obituaries';

    /** @var string Cron hook name for the migration batch. */
    const MIGRATION_HOOK = 'ontario_obituaries_image_migration';

    /** @var string Transient lock key to prevent overlapping batches. */
    const LOCK_KEY = 'ontario_obituaries_image_localizer_lock';

    /** @var string Option key tracking migration progress. */
    const PROGRESS_KEY = 'ontario_obituaries_image_migration_progress';

    /** @var array Allowed MIME types. */
    private static $allowed_mimes = array(
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    );

    /**
     * Get the count of images that still need to be localized.
     *
     * An image needs localization when:
     *   1. image_url is not empty
     *   2. image_url does NOT start with the local site URL
     *   3. image_local_url is empty (not yet processed)
     *   4. Record is not suppressed
     *
     * Also includes rows that have had transient failures (retry_count < MAX_RETRIES)
     * where image_local_url = 'retry:N' — these should be retried.
     *
     * @return int Count of images needing localization.
     */
    public static function get_pending_count() {
        global $wpdb;
        $table    = $wpdb->prefix . 'ontario_obituaries';
        $site_url = home_url();

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE image_url IS NOT NULL
               AND image_url != ''
               AND image_url NOT LIKE %s
               AND (
                   image_local_url IS NULL
                   OR image_local_url = ''
                   OR image_local_url LIKE 'retry:%%'
               )
               AND suppressed_at IS NULL",
            $wpdb->esc_like( $site_url ) . '%'
        ) );
    }

    /**
     * Get the count of images already localized (successfully downloaded).
     *
     * Excludes failure markers ('failed:*') written by download_single()
     * to avoid inflating the completed count in admin stats.
     *
     * @return int
     */
    public static function get_completed_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE image_local_url IS NOT NULL
               AND image_local_url != ''
               AND image_local_url NOT LIKE 'failed:%'
               AND suppressed_at IS NULL"
        );
    }

    /**
     * Get the count of images that failed localization.
     *
     * These have image_local_url = 'failed:*' markers and will NOT
     * be retried by the migration batch (they are excluded from
     * get_pending_count() by the NOT NULL/empty check on image_local_url).
     *
     * @return int
     */
    public static function get_failed_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE image_local_url LIKE 'failed:%'
               AND suppressed_at IS NULL"
        );
    }

    /**
     * Process a batch of images.
     *
     * Fetches up to BATCH_SIZE obituaries with external image_url values,
     * downloads each image locally, and updates the database.
     *
     * @return array {
     *     @type int $processed  Total images attempted.
     *     @type int $succeeded  Images successfully localized.
     *     @type int $failed     Images that failed (kept external URL).
     *     @type int $skipped    Images skipped (too small, invalid type, etc.).
     *     @type int $remaining  Images still pending after this batch.
     * }
     */
    public static function process_batch() {
        $result = array(
            'processed' => 0,
            'succeeded' => 0,
            'failed'    => 0,
            'skipped'   => 0,
            'remaining' => 0,
        );

        // Defensive schema check: verify required columns exist before running.
        // Covers edge case where plugin updated but activation hook didn't fire
        // (e.g., WP Pusher deploys, manual file overwrites).
        if ( ! self::verify_schema() ) {
            ontario_obituaries_log( 'Image Localizer: Required columns missing (image_local_url / image_thumb_url). Run plugin deactivate/reactivate.', 'error' );
            return $result;
        }

        // Acquire lock — prevent overlapping batches.
        if ( get_transient( self::LOCK_KEY ) ) {
            ontario_obituaries_log( 'Image Localizer: Another batch is running (lock active). Skipping.', 'info' );
            $result['remaining'] = self::get_pending_count();
            return $result;
        }
        set_transient( self::LOCK_KEY, 1, 300 ); // 5-minute TTL.

        global $wpdb;
        $table    = $wpdb->prefix . 'ontario_obituaries';
        $site_url = home_url();

        // Fetch batch of obituaries with external images.
        // Includes retryable rows (image_local_url = 'retry:N') alongside
        // fresh rows (NULL/empty).
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, image_url, image_local_url FROM `{$table}`
             WHERE image_url IS NOT NULL
               AND image_url != ''
               AND image_url NOT LIKE %s
               AND (
                   image_local_url IS NULL
                   OR image_local_url = ''
                   OR image_local_url LIKE 'retry:%%'
               )
               AND suppressed_at IS NULL
             ORDER BY id ASC
             LIMIT %d",
            $wpdb->esc_like( $site_url ) . '%',
            self::BATCH_SIZE
        ) );

        if ( empty( $rows ) ) {
            delete_transient( self::LOCK_KEY );
            $result['remaining'] = 0;
            return $result;
        }

        // Ensure upload directory exists.
        $upload_dir = self::get_upload_dir();
        if ( is_wp_error( $upload_dir ) ) {
            ontario_obituaries_log( 'Image Localizer: Cannot create upload directory: ' . $upload_dir->get_error_message(), 'error' );
            delete_transient( self::LOCK_KEY );
            $result['remaining'] = count( $rows );
            return $result;
        }

        // GAP-FIX #1: Wrap loop in try/finally so the transient lock is ALWAYS
        // released, even if download_single() throws an uncaught \Error or
        // \Exception (e.g. TypeError from a corrupt $wpdb response, OOM from
        // a malicious response body, etc.). Without this, the lock stays for
        // its full 5-min TTL, blocking the next cron tick.
        try {
            foreach ( $rows as $row ) {
                $result['processed']++;

                // Determine current retry count from image_local_url.
                $retry_count = 0;
                if ( ! empty( $row->image_local_url ) && 0 === strpos( $row->image_local_url, 'retry:' ) ) {
                    $retry_count = (int) substr( $row->image_local_url, 6 );
                }

                $download_result = self::download_single( $row->id, $row->image_url, $upload_dir, $retry_count );

                if ( 'success' === $download_result ) {
                    $result['succeeded']++;
                } elseif ( 'skipped' === $download_result ) {
                    $result['skipped']++;
                } else {
                    $result['failed']++;
                }

                // Brief pause between downloads to be kind to shared hosting.
                if ( $result['processed'] < count( $rows ) ) {
                    usleep( 250000 ); // 0.25 seconds.
                }
            }
        } catch ( \Throwable $e ) {
            // Catch both \Exception and \Error (PHP 7+).
            ontario_obituaries_log(
                sprintf( 'Image Localizer batch error: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine() ),
                'error'
            );
        } finally {
            delete_transient( self::LOCK_KEY );
        }
        $result['remaining'] = self::get_pending_count();

        // Update progress tracker.
        update_option( self::PROGRESS_KEY, array(
            'last_batch_at' => current_time( 'mysql' ),
            'last_batch'    => $result,
            'completed'     => self::get_completed_count(),
            'remaining'     => $result['remaining'],
        ), false );

        ontario_obituaries_log(
            sprintf(
                'Image Localizer batch: %d processed, %d succeeded, %d failed, %d skipped. %d remaining.',
                $result['processed'], $result['succeeded'], $result['failed'], $result['skipped'], $result['remaining']
            ),
            'info'
        );

        return $result;
    }

    /**
     * Download a single image and update the database row.
     *
     * @param int    $obit_id     Obituary row ID.
     * @param string $image_url   External image URL.
     * @param array  $upload_dir  { path, url } from get_upload_dir().
     * @param int    $retry_count Current transient-failure retry count (0 = first attempt).
     * @return string 'success', 'skipped', or 'failed'.
     */
    private static function download_single( $obit_id, $image_url, $upload_dir, $retry_count = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        // Validate URL.
        $url = esc_url_raw( $image_url, array( 'https', 'http' ) );
        if ( empty( $url ) ) {
            ontario_obituaries_log( sprintf( 'Image Localizer #%d: Invalid URL skipped.', $obit_id ), 'warning' );
            // Permanent failure: clear display URL so template hides image area.
            self::mark_permanent_failure( $table, $obit_id, $image_url, 'invalid_url' );
            return 'skipped';
        }

        // SSL verification: default true, filterable for hosts with outdated CA bundles.
        $sslverify = apply_filters( 'ontario_obituaries_image_sslverify', true );

        // HEAD request to check content type and size before downloading.
        // wp_safe_remote_head blocks private/reserved IPs (SSRF protection).
        $head = wp_safe_remote_head( $url, array(
            'timeout'    => self::DOWNLOAD_TIMEOUT,
            'user-agent' => 'Mozilla/5.0 (compatible; OntarioObituariesBot/5.2; +https://monacomonuments.ca)',
            'sslverify'  => $sslverify,
        ) );

        if ( is_wp_error( $head ) ) {
            ontario_obituaries_log( sprintf( 'Image Localizer #%d: HEAD failed (attempt %d/%d) — %s', $obit_id, $retry_count + 1, self::MAX_RETRIES, $head->get_error_message() ), 'warning' );
            // Transient failure — increment retry counter.
            return self::handle_transient_failure( $table, $obit_id, $image_url, $retry_count, 'head_error' );
        }

        $http_code = wp_remote_retrieve_response_code( $head );
        if ( $http_code >= 400 ) {
            ontario_obituaries_log( sprintf( 'Image Localizer #%d: HTTP %d — image no longer available.', $obit_id, $http_code ), 'warning' );
            // Permanent failure: resource gone — clear display URL.
            self::mark_permanent_failure( $table, $obit_id, $image_url, 'http_' . $http_code );
            return 'failed';
        }

        // Check content type.
        // GAP-FIX #3: If the server returns NO content-type header at all,
        // we treat it as suspicious and skip. Previously an empty content_type
        // bypassed the MIME check entirely, allowing any file type through.
        $content_type = wp_remote_retrieve_header( $head, 'content-type' );
        $content_type = strtolower( trim( explode( ';', $content_type )[0] ) );
        if ( empty( $content_type ) ) {
            // No content-type — can't verify it's an image. Skip but allow retry
            // (some CDNs omit content-type on HEAD but include it on GET).
            ontario_obituaries_log( sprintf( 'Image Localizer #%d: No content-type in HEAD response. Will verify after download.', $obit_id ), 'info' );
            // Fall through to download — we'll check the GET response content-type below.
        } elseif ( ! in_array( $content_type, self::$allowed_mimes, true ) ) {
            ontario_obituaries_log( sprintf( 'Image Localizer #%d: Not an image (%s). Skipping.', $obit_id, $content_type ), 'warning' );
            self::mark_permanent_failure( $table, $obit_id, $image_url, 'not_image' );
            return 'skipped';
        }

        // Check content length (if reported).
        $content_length = intval( wp_remote_retrieve_header( $head, 'content-length' ) );
        if ( $content_length > self::MAX_FILE_SIZE ) {
            ontario_obituaries_log( sprintf( 'Image Localizer #%d: Too large (%d bytes). Skipping.', $obit_id, $content_length ), 'warning' );
            self::mark_permanent_failure( $table, $obit_id, $image_url, 'too_large' );
            return 'skipped';
        }
        if ( $content_length > 0 && $content_length < self::MIN_FILE_SIZE ) {
            ontario_obituaries_log( sprintf( 'Image Localizer #%d: Too small (%d bytes, likely logo/pixel). Skipping.', $obit_id, $content_length ), 'warning' );
            self::mark_permanent_failure( $table, $obit_id, $image_url, 'too_small' );
            return 'skipped';
        }

        // ── Stream-to-disk download ──────────────────────────────────────
        // Uses wp_safe_remote_get with 'stream' => true so the response body
        // is written directly to a temp file on disk. This means a 50 MB
        // HTML error page from a CDN will NEVER spike PHP memory — the body
        // is never loaded into $response. After download we validate filesize
        // on disk and only then rename into the final path.
        //
        // Deterministic filename: md5(original_url) ensures idempotency.
        $hash         = md5( $image_url );
        $tmp_filepath = $upload_dir['path'] . '/obit-' . $hash . '.tmp.' . getmypid();

        $response = wp_safe_remote_get( $url, array(
            'timeout'     => self::DOWNLOAD_TIMEOUT,
            'user-agent'  => 'Mozilla/5.0 (compatible; OntarioObituariesBot/5.2; +https://monacomonuments.ca)',
            'sslverify'   => $sslverify,
            'redirection' => 3,
            'stream'      => true,
            'filename'    => $tmp_filepath,
        ) );

        if ( is_wp_error( $response ) ) {
            ontario_obituaries_log( sprintf( 'Image Localizer #%d: Download failed (attempt %d/%d) — %s', $obit_id, $retry_count + 1, self::MAX_RETRIES, $response->get_error_message() ), 'warning' );
            @unlink( $tmp_filepath ); // Clean up any partial stream file.
            return self::handle_transient_failure( $table, $obit_id, $image_url, $retry_count, 'download_error' );
        }

        // Validate file exists and has content.
        if ( ! file_exists( $tmp_filepath ) ) {
            ontario_obituaries_log( sprintf( 'Image Localizer #%d: Stream file not created (attempt %d/%d).', $obit_id, $retry_count + 1, self::MAX_RETRIES ), 'warning' );
            return self::handle_transient_failure( $table, $obit_id, $image_url, $retry_count, 'no_stream_file' );
        }

        $file_size = filesize( $tmp_filepath );

        // Validate actual downloaded size on disk.
        if ( $file_size > self::MAX_FILE_SIZE ) {
            ontario_obituaries_log( sprintf( 'Image Localizer #%d: Downloaded file too large (%d bytes).', $obit_id, $file_size ), 'warning' );
            @unlink( $tmp_filepath );
            self::mark_permanent_failure( $table, $obit_id, $image_url, 'too_large' );
            return 'skipped';
        }
        if ( $file_size < self::MIN_FILE_SIZE ) {
            ontario_obituaries_log( sprintf( 'Image Localizer #%d: Downloaded file too small (%d bytes).', $obit_id, $file_size ), 'warning' );
            @unlink( $tmp_filepath );
            self::mark_permanent_failure( $table, $obit_id, $image_url, 'too_small' );
            return 'skipped';
        }

        // GAP-FIX #2 & #3: Validate the ACTUAL content-type from the GET response.
        // Catches CDNs that redirect to login/error pages on GET.
        $actual_type = wp_remote_retrieve_header( $response, 'content-type' );
        $actual_type = strtolower( trim( explode( ';', $actual_type )[0] ) );
        if ( ! empty( $actual_type ) && ! in_array( $actual_type, self::$allowed_mimes, true ) ) {
            ontario_obituaries_log( sprintf( 'Image Localizer #%d: GET returned non-image type (%s). Skipping.', $obit_id, $actual_type ), 'warning' );
            @unlink( $tmp_filepath );
            self::mark_permanent_failure( $table, $obit_id, $image_url, 'not_image' );
            return 'skipped';
        }

        // Validate file extension from MIME matches an image type.
        // wp_check_filetype uses WordPress's allowed MIME list as a second opinion.
        $ext = self::extension_from_mime( $actual_type );
        $filename = 'obit-' . $hash . '.' . $ext;
        $filepath = $upload_dir['path'] . '/' . $filename;

        $wp_filetype = wp_check_filetype( $filename );
        if ( empty( $wp_filetype['type'] ) || 0 !== strpos( $wp_filetype['type'], 'image/' ) ) {
            ontario_obituaries_log( sprintf( 'Image Localizer #%d: wp_check_filetype rejected %s.', $obit_id, $filename ), 'warning' );
            @unlink( $tmp_filepath );
            self::mark_permanent_failure( $table, $obit_id, $image_url, 'not_image' );
            return 'skipped';
        }

        // Atomic rename: on POSIX systems rename() is atomic within the same filesystem.
        if ( ! @rename( $tmp_filepath, $filepath ) ) {
            // Fallback for cross-device or permission issues.
            @copy( $tmp_filepath, $filepath );
            @unlink( $tmp_filepath );
        }
        if ( ! file_exists( $filepath ) ) {
            ontario_obituaries_log( sprintf( 'Image Localizer #%d: Rename/copy failed for %s', $obit_id, $filepath ), 'error' );
            return self::handle_transient_failure( $table, $obit_id, $image_url, $retry_count, 'rename_error' );
        }

        // Generate thumbnail.
        $thumb_filename = 'obit-' . $hash . '-thumb.' . $ext;
        $thumb_filepath = $upload_dir['path'] . '/' . $thumb_filename;
        $thumb_url      = $upload_dir['url'] . '/' . $filename; // Default: same as full image.

        if ( function_exists( 'wp_get_image_editor' ) ) {
            $editor = wp_get_image_editor( $filepath );
            if ( ! is_wp_error( $editor ) ) {
                // Strip EXIF by re-saving.
                $editor->save( $filepath );

                // Create 300x400 thumbnail.
                $editor_thumb = wp_get_image_editor( $filepath );
                if ( ! is_wp_error( $editor_thumb ) ) {
                    $editor_thumb->resize( 300, 400, true );
                    $saved_thumb = $editor_thumb->save( $thumb_filepath );
                    if ( ! is_wp_error( $saved_thumb ) && isset( $saved_thumb['path'] ) ) {
                        $thumb_url = $upload_dir['url'] . '/' . basename( $saved_thumb['path'] );
                    }
                }
            }
        }

        $local_url = $upload_dir['url'] . '/' . $filename;

        // Atomic DB update: swap image_url to local, backup original to image_local_url.
        // IMPORTANT: This unconditionally writes image_local_url = $image_url (the
        // original remote URL), which overwrites any previous 'retry:N' value.
        // This is the SUCCESS terminal state — the remote URL is preserved for rollback.
        $updated = $wpdb->update(
            $table,
            array(
                'image_url'       => $local_url,   // Display: now local.
                'image_local_url' => $image_url,   // Backup: original remote URL (overwrites retry:N).
                'image_thumb_url' => $thumb_url,   // Thumbnail: local.
            ),
            array( 'id' => $obit_id ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );

        if ( false === $updated ) {
            ontario_obituaries_log( sprintf( 'Image Localizer #%d: DB update failed.', $obit_id ), 'error' );
            // Clean up the downloaded file since DB wasn't updated.
            @unlink( $filepath );
            if ( file_exists( $thumb_filepath ) ) {
                @unlink( $thumb_filepath );
            }
            return 'failed';
        }

        return 'success';
    }

    /**
     * Mark an image as a permanent failure.
     *
     * Clears image_url so templates hide the image area (no broken img tag,
     * no hotlink to a dead resource). Backs up the original remote URL in
     * image_local_url with a 'failed:' prefix for diagnostics.
     *
     * @param string $table     Table name.
     * @param int    $obit_id   Row ID.
     * @param string $image_url Original remote URL (preserved for debugging).
     * @param string $reason    Short failure reason (e.g., 'http_404', 'not_image').
     */
    private static function mark_permanent_failure( $table, $obit_id, $image_url, $reason ) {
        global $wpdb;
        $wpdb->update(
            $table,
            array(
                'image_url'       => '',           // Clear display URL — template hides area.
                'image_local_url' => 'failed:' . $reason, // Prevents re-processing.
            ),
            array( 'id' => $obit_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    /**
     * Handle a transient (retryable) failure.
     *
     * Increments the retry counter stored in image_local_url as 'retry:N'.
     * If MAX_RETRIES is exceeded, escalates to a permanent failure (clears
     * image_url to stop hotlinking the dead resource).
     *
     * @param string $table       Table name.
     * @param int    $obit_id     Row ID.
     * @param string $image_url   Original remote URL.
     * @param int    $retry_count Current retry count before this attempt.
     * @param string $reason      Short failure reason for logging.
     * @return string 'failed'.
     */
    private static function handle_transient_failure( $table, $obit_id, $image_url, $retry_count, $reason ) {
        global $wpdb;
        $new_count = $retry_count + 1;

        if ( $new_count >= self::MAX_RETRIES ) {
            // Exhausted retries — escalate to permanent failure.
            ontario_obituaries_log(
                sprintf( 'Image Localizer #%d: Max retries (%d) exhausted for %s. Marking permanent failure.',
                         $obit_id, self::MAX_RETRIES, $reason ),
                'warning'
            );
            self::mark_permanent_failure( $table, $obit_id, $image_url, 'max_retries_' . $reason );
            return 'failed';
        }

        // Record retry count — row stays in pending queue for next batch.
        $wpdb->update(
            $table,
            array( 'image_local_url' => 'retry:' . $new_count ),
            array( 'id' => $obit_id ),
            array( '%s' ),
            array( '%d' )
        );
        return 'failed';
    }

    /**
     * Localize a single obituary's image immediately after insert.
     *
     * Called by the source collector after inserting a new record.
     * Unlike the batch migration, this processes exactly one image
     * synchronously (adds ~1-2 seconds to the scrape per new obituary).
     *
     * @param int    $obit_id   Newly inserted obituary ID.
     * @param string $image_url External image URL from the scraper.
     * @return bool True if localized, false if skipped/failed.
     */
    public static function localize_on_insert( $obit_id, $image_url ) {
        if ( empty( $image_url ) ) {
            return false;
        }

        // Skip if already local.
        $site_url = home_url();
        if ( 0 === strpos( $image_url, $site_url ) ) {
            return false;
        }

        // TIMEOUT GUARD: During interactive rescans (AJAX) or batch scrapes,
        // the source collector may be inserting many obituaries. Adding 1-2s
        // per image download on top of the scrape time risks exceeding
        // LiteSpeed's 120s timeout. Skip synchronous localization if we're
        // already 90+ seconds into the request — the migration batch cron
        // will pick it up within 5 minutes.
        if ( defined( 'ONTARIO_OBITUARIES_REQUEST_START' ) ) {
            $elapsed = time() - ONTARIO_OBITUARIES_REQUEST_START;
            if ( $elapsed > 90 ) {
                return false; // Let migration cron handle it.
            }
        }

        $upload_dir = self::get_upload_dir();
        if ( is_wp_error( $upload_dir ) ) {
            return false;
        }

        $result = self::download_single( $obit_id, $image_url, $upload_dir );
        return ( 'success' === $result );
    }

    /**
     * Verify that the required columns exist in the database table.
     *
     * The activation hook in ontario-obituaries.php adds image_local_url and
     * image_thumb_url via CREATE TABLE + ALTER TABLE. However, if the plugin
     * is updated via WP Pusher (file overwrite, no activation hook), the columns
     * might be missing. This method checks once per request (cached).
     *
     * @return bool True if columns exist, false otherwise.
     */
    private static function verify_schema() {
        static $verified = null;
        if ( null !== $verified ) {
            return $verified;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        // SHOW COLUMNS is fast — MySQL reads from the information_schema cache.
        $columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" );
        $verified = in_array( 'image_local_url', $columns, true )
                 && in_array( 'image_thumb_url', $columns, true );

        return $verified;
    }

    /**
     * Get or create the upload directory.
     *
     * Creates /wp-content/uploads/ontario-obituaries/ with an index.php
     * for security (prevents directory listing).
     *
     * Uses year/month subdirectories for scale:
     *   /wp-content/uploads/ontario-obituaries/2026/02/obit-abc123.jpg
     *
     * @return array|WP_Error { path, url }
     */
    private static function get_upload_dir() {
        $upload = wp_upload_dir();
        if ( ! empty( $upload['error'] ) ) {
            return new WP_Error( 'upload_dir', $upload['error'] );
        }

        // Use flat directory for simplicity (year/month adds complexity
        // to rollback queries and deterministic filenames). At 50,000 files,
        // ext4 handles this fine — the filesystem bottleneck is ~100K+ files
        // per directory.
        $path = $upload['basedir'] . '/' . self::UPLOAD_DIR;
        $url  = $upload['baseurl'] . '/' . self::UPLOAD_DIR;

        if ( ! is_dir( $path ) ) {
            wp_mkdir_p( $path );
        }

        // Security: prevent directory listing.
        $index = $path . '/index.php';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, '<?php // Silence is golden.' ); // phpcs:ignore
        }

        // .htaccess: prevent PHP execution in uploads dir.
        $htaccess = $path . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            @file_put_contents( $htaccess, "<Files *.php>\nDeny from all\n</Files>" ); // phpcs:ignore
        }

        return array( 'path' => $path, 'url' => $url );
    }

    /**
     * Map MIME type to file extension.
     *
     * @param string $mime MIME type.
     * @return string File extension (without dot).
     */
    private static function extension_from_mime( $mime ) {
        $map = array(
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        );
        return isset( $map[ $mime ] ) ? $map[ $mime ] : 'jpg';
    }

    /**
     * Schedule the migration cron if there are pending images.
     *
     * Called on plugin activation and version update.
     * Self-removes when no images remain.
     *
     * @return bool True if scheduled, false if not needed or already scheduled.
     */
    public static function maybe_schedule_migration() {
        $pending = self::get_pending_count();
        if ( $pending < 1 ) {
            // No pending images — clean up any leftover cron.
            wp_clear_scheduled_hook( self::MIGRATION_HOOK );
            return false;
        }

        if ( wp_next_scheduled( self::MIGRATION_HOOK ) ) {
            return false; // Already scheduled.
        }

        wp_schedule_event( time() + 60, 'ontario_five_minutes', self::MIGRATION_HOOK );
        ontario_obituaries_log(
            sprintf( 'Image Localizer: Migration scheduled. %d images pending.', $pending ),
            'info'
        );
        return true;
    }

    /**
     * Cron handler: process one batch and check if migration is complete.
     *
     * Registered as the callback for self::MIGRATION_HOOK.
     */
    public static function handle_migration_cron() {
        $result = self::process_batch();

        if ( $result['remaining'] < 1 ) {
            // Migration complete — remove the recurring cron.
            wp_clear_scheduled_hook( self::MIGRATION_HOOK );
            ontario_obituaries_log( 'Image Localizer: Migration complete! All images localized. Cron removed.', 'info' );

            // Purge cache so localized images appear immediately.
            if ( function_exists( 'ontario_obituaries_purge_litespeed' ) ) {
                ontario_obituaries_purge_litespeed( 'ontario_obits' );
            }
        }
    }

    /**
     * Get migration stats for the admin UI.
     *
     * @return array {
     *     @type int    $completed  Images already localized.
     *     @type int    $pending    Images still external.
     *     @type int    $total      Total images (completed + pending).
     *     @type float  $percent    Percent complete.
     *     @type bool   $running    Whether the migration cron is active.
     *     @type string $last_batch Last batch timestamp.
     * }
     */
    public static function get_stats() {
        $completed = self::get_completed_count();
        $pending   = self::get_pending_count();
        $failed    = self::get_failed_count();
        $total     = $completed + $pending + $failed;
        $progress  = get_option( self::PROGRESS_KEY, array() );

        return array(
            'completed'  => $completed,
            'pending'    => $pending,
            'failed'     => $failed,
            'total'      => $total,
            'percent'    => $total > 0 ? round( ( $completed / $total ) * 100, 1 ) : 100.0,
            'running'    => (bool) wp_next_scheduled( self::MIGRATION_HOOK ),
            'last_batch' => isset( $progress['last_batch_at'] ) ? $progress['last_batch_at'] : '',
        );
    }

    /**
     * Cleanup on plugin deactivation.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook( self::MIGRATION_HOOK );
        delete_transient( self::LOCK_KEY );
    }

    /**
     * Cleanup on plugin uninstall.
     *
     * Removes the progress option. Does NOT delete downloaded images
     * (they are in the uploads directory and may be needed for backup).
     */
    public static function uninstall() {
        self::deactivate();
        delete_option( self::PROGRESS_KEY );
    }
}
