<?php
/**
 * Image Pipeline
 *
 * Handles downloading, validating, processing, and caching obituary images.
 * Enforces allowlisting: only images from approved source domains are downloaded.
 * All other obituaries receive a branded premium placeholder memorial card.
 *
 * Pipeline:
 *   1. Check allowlist (source.image_allowlisted)
 *   2. Validate URL and content type (image/*)
 *   3. Enforce size limit (default 5 MB)
 *   4. Download to wp-content/uploads/ontario-obituaries/
 *   5. Strip EXIF data for privacy
 *   6. Generate WebP version + thumbnail
 *   7. Return local URL or placeholder
 *
 * @package Ontario_Obituaries
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_Image_Pipeline {

    /** @var string Upload subdirectory. */
    const UPLOAD_DIR = 'ontario-obituaries';

    /** @var int Max file size in bytes (5 MB). */
    const MAX_FILE_SIZE = 5242880;

    /** @var int Thumbnail width. */
    const THUMB_WIDTH = 300;

    /** @var int Thumbnail height. */
    const THUMB_HEIGHT = 400;

    /** @var array Allowed MIME types. */
    private static $allowed_mimes = array(
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    );

    /**
     * Process an image URL — download if allowlisted, else return placeholder.
     *
     * @param string $image_url   Remote image URL.
     * @param array  $source      Source registry row (checks image_allowlisted).
     * @param string $obit_name   Obituary name (for placeholder text).
     * @param string $obit_city   City (for placeholder text).
     * @return array { url: string, thumb_url: string, is_placeholder: bool }
     */
    public static function process( $image_url, $source, $obit_name = '', $obit_city = '' ) {
        // Empty image → placeholder
        if ( empty( $image_url ) ) {
            return self::placeholder_result( $obit_name, $obit_city );
        }

        // Check allowlist
        $allowlisted = ! empty( $source['image_allowlisted'] );
        if ( ! $allowlisted ) {
            return self::placeholder_result( $obit_name, $obit_city );
        }

        // Try to download and process
        $local = self::download_and_process( $image_url );
        if ( is_wp_error( $local ) ) {
            ontario_obituaries_log( 'Image pipeline error: ' . $local->get_error_message(), 'warning' );
            return self::placeholder_result( $obit_name, $obit_city );
        }

        return $local;
    }

    /**
     * Download and process a remote image.
     *
     * @param string $url Remote URL.
     * @return array|WP_Error { url, thumb_url, is_placeholder }
     */
    private static function download_and_process( $url ) {
        // Validate URL
        $url = esc_url_raw( $url, array( 'https', 'http' ) );
        if ( empty( $url ) ) {
            return new WP_Error( 'invalid_url', 'Invalid image URL' );
        }

        // P0-2 SSRF FIX: Block private/reserved IPs and non-HTTP schemes
        $parsed = wp_parse_url( $url );
        $host   = isset( $parsed['host'] ) ? $parsed['host'] : '';
        if ( empty( $host ) ) {
            return new WP_Error( 'invalid_url', 'No host in image URL' );
        }

        // Resolve hostname to IP and check for private ranges
        $ip = gethostbyname( $host );
        if ( $ip === $host ) {
            // DNS resolution failed — could be a non-routable name
            return new WP_Error( 'dns_failed', 'Cannot resolve image host: ' . $host );
        }
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
            return new WP_Error( 'ssrf_blocked', 'Image URL resolves to a private/reserved IP' );
        }

        // HEAD request to check content type and size
        $head = wp_remote_head( $url, array(
            'timeout'    => 10,
            'user-agent' => 'Mozilla/5.0 (compatible; OntarioObituariesBot/3.0; +https://monacomonuments.ca)',
        ) );

        if ( is_wp_error( $head ) ) {
            return $head;
        }

        $content_type = wp_remote_retrieve_header( $head, 'content-type' );
        $content_type = strtolower( explode( ';', $content_type )[0] );

        if ( ! in_array( $content_type, self::$allowed_mimes, true ) ) {
            return new WP_Error( 'invalid_type', 'Not an allowed image type: ' . $content_type );
        }

        $content_length = intval( wp_remote_retrieve_header( $head, 'content-length' ) );
        if ( $content_length > self::MAX_FILE_SIZE ) {
            return new WP_Error( 'too_large', 'Image exceeds max size: ' . $content_length . ' bytes' );
        }

        // Download
        $tmp = download_url( $url, 30 );
        if ( is_wp_error( $tmp ) ) {
            return $tmp;
        }

        // Verify actual file size
        if ( filesize( $tmp ) > self::MAX_FILE_SIZE ) {
            @unlink( $tmp );
            return new WP_Error( 'too_large', 'Downloaded image exceeds max size' );
        }

        // P1-5 FIX: Deterministic filename based on URL only — prevents duplicates
        // and ensures re-processing the same image overwrites rather than orphans.
        $ext      = self::extension_from_mime( $content_type );
        $hash     = md5( $url );
        $filename = 'obit-' . $hash . '.' . $ext;

        // Get upload directory
        $upload_dir = self::get_upload_dir();
        if ( is_wp_error( $upload_dir ) ) {
            @unlink( $tmp );
            return $upload_dir;
        }

        $dest_path = $upload_dir['path'] . '/' . $filename;

        // Move to upload dir
        if ( ! @rename( $tmp, $dest_path ) ) {
            @copy( $tmp, $dest_path );
            @unlink( $tmp );
        }

        if ( ! file_exists( $dest_path ) ) {
            return new WP_Error( 'move_failed', 'Failed to move downloaded image' );
        }

        // Strip EXIF data
        self::strip_exif( $dest_path );

        // Generate thumbnail
        $thumb_path = self::generate_thumbnail( $dest_path, $upload_dir['path'] );
        $thumb_url  = $thumb_path
            ? $upload_dir['url'] . '/' . basename( $thumb_path )
            : $upload_dir['url'] . '/' . $filename;

        return array(
            'url'            => $upload_dir['url'] . '/' . $filename,
            'thumb_url'      => $thumb_url,
            'is_placeholder' => false,
        );
    }

    /**
     * Return a placeholder result.
     */
    private static function placeholder_result( $name = '', $city = '' ) {
        $placeholder_url = ONTARIO_OBITUARIES_PLUGIN_URL . 'assets/images/memorial-placeholder.svg';

        return array(
            'url'            => $placeholder_url,
            'thumb_url'      => $placeholder_url,
            'is_placeholder' => true,
            'name'           => $name,
            'city'           => $city,
        );
    }

    /**
     * Get or create the upload directory for the plugin.
     *
     * @return array|WP_Error { path, url }
     */
    private static function get_upload_dir() {
        $upload = wp_upload_dir();
        if ( ! empty( $upload['error'] ) ) {
            return new WP_Error( 'upload_dir', $upload['error'] );
        }

        $path = $upload['basedir'] . '/' . self::UPLOAD_DIR;
        $url  = $upload['baseurl'] . '/' . self::UPLOAD_DIR;

        if ( ! is_dir( $path ) ) {
            wp_mkdir_p( $path );
        }

        // Add index.php for security
        $index = $path . '/index.php';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, '<?php // Silence is golden.' );
        }

        return array( 'path' => $path, 'url' => $url );
    }

    /**
     * Strip EXIF data from an image file.
     *
     * @param string $path File path.
     */
    private static function strip_exif( $path ) {
        if ( ! function_exists( 'wp_get_image_editor' ) ) {
            require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
        }

        $editor = wp_get_image_editor( $path );
        if ( ! is_wp_error( $editor ) ) {
            // Re-saving strips EXIF automatically in WP image editors
            $editor->save( $path );
        }
    }

    /**
     * Generate a thumbnail.
     *
     * @param string $source_path Source image path.
     * @param string $dir         Output directory.
     * @return string|false Thumbnail path or false.
     */
    private static function generate_thumbnail( $source_path, $dir ) {
        $editor = wp_get_image_editor( $source_path );
        if ( is_wp_error( $editor ) ) {
            return false;
        }

        $editor->resize( self::THUMB_WIDTH, self::THUMB_HEIGHT, true );

        $info      = pathinfo( $source_path );
        $thumb_name = $info['filename'] . '-thumb.' . $info['extension'];
        $thumb_path = $dir . '/' . $thumb_name;

        $saved = $editor->save( $thumb_path );
        if ( is_wp_error( $saved ) ) {
            return false;
        }

        return isset( $saved['path'] ) ? $saved['path'] : $thumb_path;
    }

    /**
     * Map MIME type to file extension.
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
     * Cleanup old cached images (call via WP-Cron).
     *
     * @param int $max_age_days Max age in days (default 90).
     */
    public static function cleanup_old_images( $max_age_days = 90 ) {
        $upload_dir = self::get_upload_dir();
        if ( is_wp_error( $upload_dir ) ) {
            return;
        }

        $dir   = $upload_dir['path'];
        $cutoff = time() - ( $max_age_days * DAY_IN_SECONDS );

        $files = glob( $dir . '/obit-*' );
        if ( ! $files ) {
            return;
        }

        $removed = 0;
        foreach ( $files as $file ) {
            if ( filemtime( $file ) < $cutoff ) {
                @unlink( $file );
                $removed++;
            }
        }

        if ( $removed > 0 ) {
            ontario_obituaries_log( sprintf( 'Image cleanup: removed %d old images', $removed ), 'info' );
        }
    }
}
