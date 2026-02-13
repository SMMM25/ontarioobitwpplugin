<?php
/**
 * SEO Template: Individual Obituary Page (content-only partial)
 *
 * v3.10.2 PR #24b: Converted to content-only partial. This template no longer
 * calls get_header() / get_footer(). It is included by wrapper.php which
 * provides the full HTML shell and correct Elementor header/footer.
 *
 * v3.13.3: Visible dates removed from header; "Date published" added to footer.
 * v3.14.0: Complete UI redesign to match Monaco Monuments brand.
 * v3.14.1: Full Monaco Monuments themed detail page with dates, image, CTA.
 * v3.15.0: Forced enrichment sweep populates images; year_death extraction fix.
 * v3.15.1: Better images (300x400 print-only CDN); full description extraction; SPA skip.
 *
 * Variables provided via query var 'ontario_obituaries_seo_data' (extracted by wrapper):
 *   $obituary     — object the obituary record
 *   $city_slug    — string URL slug for the city
 *   $should_index — bool whether this page should be indexed
 *
 * @package Ontario_Obituaries
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$city_name = ! empty( $obituary->city_normalized ) ? $obituary->city_normalized : $obituary->location;

// Format dates for display
$birth_display = '';
$death_display = '';
$date_range_display = '';

if ( ! empty( $obituary->date_of_birth ) && '0000-00-00' !== $obituary->date_of_birth ) {
    $birth_display = date_i18n( 'F j, Y', strtotime( $obituary->date_of_birth ) );
}
if ( ! empty( $obituary->date_of_death ) && '0000-00-00' !== $obituary->date_of_death ) {
    $death_display = date_i18n( 'F j, Y', strtotime( $obituary->date_of_death ) );
}

if ( $birth_display && $death_display ) {
    $date_range_display = $birth_display . ' &ndash; ' . $death_display;
} elseif ( $death_display ) {
    $date_range_display = $death_display;
}
?>

<div class="ontario-obituary-individual" itemscope itemtype="https://schema.org/Person">

    <!-- Breadcrumbs -->
    <nav class="ontario-obituaries-breadcrumbs">
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'ontario-obituaries' ); ?></a> <span class="ontario-breadcrumb-sep">&rsaquo;</span>
        <a href="<?php echo esc_url( home_url( '/obituaries/ontario/' ) ); ?>"><?php esc_html_e( 'Ontario Obituaries', 'ontario-obituaries' ); ?></a> <span class="ontario-breadcrumb-sep">&rsaquo;</span>
        <?php if ( ! empty( $city_name ) ) : ?>
            <a href="<?php echo esc_url( home_url( '/obituaries/ontario/' . sanitize_title( $city_name ) . '/' ) ); ?>">
                <?php echo esc_html( $city_name ); ?>
            </a> <span class="ontario-breadcrumb-sep">&rsaquo;</span>
        <?php endif; ?>
        <span class="ontario-breadcrumb-current"><?php echo esc_html( $obituary->name ); ?></span>
    </nav>

    <article class="ontario-obituary-page">
        <!-- Hero section: image + name + dates -->
        <div class="ontario-obituary-hero">
            <?php if ( ! empty( $obituary->image_url ) ) : ?>
            <div class="ontario-obituary-hero-image">
                <img src="<?php echo esc_url( $obituary->image_url ); ?>"
                     alt="<?php echo esc_attr( sprintf( __( 'Photo of %s', 'ontario-obituaries' ), $obituary->name ) ); ?>"
                     loading="lazy">
            </div>
            <?php endif; ?>

            <div class="ontario-obituary-hero-info">
                <h1 itemprop="name"><?php echo esc_html( $obituary->name ); ?></h1>

                <?php if ( $date_range_display ) : ?>
                <div class="ontario-obituary-dates">
                    <?php echo $date_range_display; // Contains HTML entities, already escaped ?>
                </div>
                <?php endif; ?>

                <?php if ( ! empty( $obituary->age ) && intval( $obituary->age ) > 0 ) : ?>
                <div class="ontario-obituary-age">
                    <?php printf( esc_html__( 'Age %d', 'ontario-obituaries' ), intval( $obituary->age ) ); ?>
                </div>
                <?php endif; ?>

                <div class="ontario-obituary-meta-details">
                    <?php if ( ! empty( $obituary->location ) ) : ?>
                        <div class="ontario-obituary-location-tag" itemprop="deathPlace" itemscope itemtype="https://schema.org/Place">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                            <span itemprop="name"><?php echo esc_html( $obituary->location ); ?></span>, Ontario
                        </div>
                    <?php endif; ?>

                    <?php if ( ! empty( $obituary->funeral_home ) ) : ?>
                        <div class="ontario-obituary-funeral-tag">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M3 10h18M5 6l7-3 7 3M4 10v11M20 10v11M8 14v4M12 14v4M16 14v4"></path></svg>
                            <?php echo esc_html( $obituary->funeral_home ); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Schema.org meta (hidden) -->
        <?php if ( ! empty( $obituary->date_of_birth ) && '0000-00-00' !== $obituary->date_of_birth ) : ?>
            <meta itemprop="birthDate" content="<?php echo esc_attr( $obituary->date_of_birth ); ?>">
        <?php endif; ?>
        <meta itemprop="deathDate" content="<?php echo esc_attr( $obituary->date_of_death ); ?>">

        <!-- Obituary content -->
        <?php
        // v4.1.0: Prefer AI-rewritten description when available.
        $seo_desc = ! empty( $obituary->ai_description ) ? $obituary->ai_description : $obituary->description;
        ?>
        <?php if ( ! empty( $seo_desc ) ) : ?>
        <div class="ontario-obituary-body">
            <?php echo wpautop( esc_html( $seo_desc ) ); ?>
        </div>
        <?php else : ?>
        <div class="ontario-obituary-body ontario-obituary-body-minimal">
            <p><?php printf(
                esc_html__( 'We have recorded the passing of %s of %s, Ontario.', 'ontario-obituaries' ),
                esc_html( $obituary->name ),
                esc_html( ! empty( $city_name ) ? $city_name : 'Ontario' )
            ); ?></p>
            <p><?php esc_html_e( 'A full obituary was not available at the time of publication. If you are a family member or representative and would like to add details, please contact us.', 'ontario-obituaries' ); ?></p>
        </div>
        <?php endif; ?>

        <?php
        // v4.3.0: GoFundMe Auto-Linker — show verified campaign link.
        if ( ! empty( $obituary->gofundme_url ) ) : ?>
        <div class="ontario-obituary-gofundme">
            <div class="ontario-obituary-gofundme-inner">
                <div class="ontario-gofundme-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>
                </div>
                <div class="ontario-gofundme-text">
                    <h4><?php esc_html_e( 'Support the Family', 'ontario-obituaries' ); ?></h4>
                    <p><?php printf(
                        esc_html__( 'A verified GoFundMe campaign has been created for %s. Your support can make a meaningful difference during this difficult time.', 'ontario-obituaries' ),
                        esc_html( $obituary->name )
                    ); ?></p>
                    <a href="<?php echo esc_url( $obituary->gofundme_url ); ?>" target="_blank" rel="noopener noreferrer" class="ontario-gofundme-btn">
                        <?php esc_html_e( 'Donate on GoFundMe', 'ontario-obituaries' ); ?>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                    </a>
                </div>
            </div>
            <p class="ontario-gofundme-disclaimer">
                <?php esc_html_e( 'This link was automatically matched using name, date, and location verification. Monaco Monuments is not affiliated with GoFundMe.', 'ontario-obituaries' ); ?>
            </p>
        </div>
        <?php endif; ?>

        <?php
        // v4.3.0: AI Authenticity audit badge — show verified status.
        if ( ! empty( $obituary->audit_status ) && 'pass' === $obituary->audit_status ) : ?>
        <div class="ontario-obituary-verified-badge">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            <span><?php esc_html_e( 'AI Verified — data accuracy confirmed', 'ontario-obituaries' ); ?></span>
        </div>
        <?php endif; ?>

        <!-- Footer / provenance -->
        <footer class="ontario-obituary-footer">
            <?php if ( ! empty( $obituary->source_url ) ) : ?>
                <!-- v3.10.0 FIX: Source attribution is text-only (no clickable external link).
                     Per PLATFORM_OVERSIGHT_HUB Rule 6 #9: no external obituary links.
                     source_url retained in DB for provenance/audit. -->
                <p class="ontario-obituary-provenance">
                    <?php esc_html_e( 'Source record retained internally for provenance.', 'ontario-obituaries' ); ?>
                </p>
            <?php endif; ?>

            <?php // v3.13.3: "Date published" shown here (moved from header per owner request). ?>
            <?php if ( ! empty( $obituary->created_at ) ) : ?>
                <p class="ontario-obituary-published-date">
                    <?php printf(
                        esc_html__( 'Date published: %s', 'ontario-obituaries' ),
                        esc_html( date_i18n( get_option( 'date_format' ), strtotime( $obituary->created_at ) ) )
                    ); ?>
                </p>
            <?php endif; ?>

            <!-- Back to city hub -->
            <?php if ( ! empty( $city_name ) ) : ?>
                <a href="<?php echo esc_url( home_url( '/obituaries/ontario/' . sanitize_title( $city_name ) . '/' ) ); ?>" class="ontario-obituary-back-link">
                    &larr; <?php printf( esc_html__( 'Back to %s Obituaries', 'ontario-obituaries' ), esc_html( $city_name ) ); ?>
                </a>
            <?php endif; ?>
        </footer>
    </article>

    <!-- v4.2.0: QR Code for sharing this memorial page -->
    <?php
    $memorial_url = home_url( sprintf( '/obituaries/ontario/%s/%s-%d/',
        sanitize_title( $city_name ? $city_name : 'ontario' ),
        sanitize_title( $obituary->name ),
        $obituary->id
    ) );
    // Lightweight QR code via QR Server API (no JS dependency).
    // Note: Google Charts QR API was deprecated and returns 404.
    $qr_url = sprintf(
        'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=%s&format=png',
        rawurlencode( $memorial_url )
    );
    ?>
    <div class="ontario-obituary-qr-section">
        <div class="ontario-obituary-qr-inner">
            <img src="<?php echo esc_url( $qr_url ); ?>"
                 alt="<?php echo esc_attr( sprintf( __( 'QR code for %s memorial page', 'ontario-obituaries' ), $obituary->name ) ); ?>"
                 width="150" height="150" loading="lazy">
            <p class="ontario-qr-label"><?php esc_html_e( 'Scan to share this memorial page', 'ontario-obituaries' ); ?></p>
        </div>
    </div>

    <!-- Monument Services CTA -->
    <aside class="ontario-obituaries-cta-block">
        <div class="ontario-obituaries-cta-inner">
            <h3><?php esc_html_e( 'Personalized Monuments', 'ontario-obituaries' ); ?></h3>
            <p class="ontario-cta-subtitle"><?php esc_html_e( 'Monaco Monuments, Newmarket', 'ontario-obituaries' ); ?></p>
            <p class="ontario-cta-desc"><?php esc_html_e( 'Each monument we create is unique, a one-of-a-kind celebration of life meant to be cherished for eternity. Serving families in York Region, Newmarket, Aurora, Richmond Hill, and throughout Southern Ontario.', 'ontario-obituaries' ); ?></p>
            <p class="ontario-cta-address">
                <?php esc_html_e( '1190 Twinney Dr. Unit #8, Newmarket, ON', 'ontario-obituaries' ); ?> &bull; <?php esc_html_e( '(905) 392-0778', 'ontario-obituaries' ); ?>
            </p>
            <div class="ontario-cta-buttons">
                <a href="https://monacomonuments.ca/contact/" class="ontario-cta-btn-primary">
                    <?php esc_html_e( 'Contact Monaco Monuments', 'ontario-obituaries' ); ?>
                </a>
                <a href="https://monacomonuments.ca/catalog/" class="ontario-cta-btn-secondary">
                    <?php esc_html_e( 'Browse Our Catalog', 'ontario-obituaries' ); ?> &rarr;
                </a>
            </div>
        </div>
    </aside>

    <!-- v4.2.0: Soft lead capture — condolence/notification signup -->
    <div class="ontario-obituary-lead-capture">
        <div class="ontario-obituary-lead-inner">
            <h4><?php esc_html_e( 'Stay Connected', 'ontario-obituaries' ); ?></h4>
            <p><?php esc_html_e( 'Receive updates about memorial services and monument dedications in your area.', 'ontario-obituaries' ); ?></p>
            <form class="ontario-lead-form" id="ontario-lead-form" method="post">
                <?php wp_nonce_field( 'ontario_obituaries_lead', 'ontario_lead_nonce' ); ?>
                <input type="hidden" name="action" value="ontario_obituaries_lead_capture">
                <input type="hidden" name="obituary_id" value="<?php echo intval( $obituary->id ); ?>">
                <input type="hidden" name="city" value="<?php echo esc_attr( $city_name ); ?>">
                <div class="ontario-lead-fields">
                    <input type="email" name="lead_email" id="ontario-lead-email" placeholder="<?php esc_attr_e( 'Your email address', 'ontario-obituaries' ); ?>" required>
                    <button type="submit" class="ontario-lead-submit"><?php esc_html_e( 'Subscribe', 'ontario-obituaries' ); ?></button>
                </div>
                <p class="ontario-lead-privacy"><?php esc_html_e( 'We respect your privacy. Unsubscribe at any time.', 'ontario-obituaries' ); ?></p>
                <p class="ontario-lead-message" id="ontario-lead-message" style="display:none;"></p>
            </form>
            <script>
            (function(){
                var form = document.getElementById('ontario-lead-form');
                if (!form) return;
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var btn = form.querySelector('.ontario-lead-submit');
                    var msg = document.getElementById('ontario-lead-message');
                    var data = new FormData(form);
                    btn.disabled = true;
                    btn.textContent = '<?php echo esc_js( __( 'Sending...', 'ontario-obituaries' ) ); ?>';
                    fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                        method: 'POST',
                        body: data,
                        credentials: 'same-origin'
                    })
                    .then(function(r){ return r.json(); })
                    .then(function(resp){
                        msg.style.display = 'block';
                        if (resp.success) {
                            msg.className = 'ontario-lead-message ontario-lead-success';
                            msg.textContent = resp.data.message;
                            form.querySelector('#ontario-lead-email').value = '';
                        } else {
                            msg.className = 'ontario-lead-message ontario-lead-error';
                            msg.textContent = (resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js( __( 'Something went wrong. Please try again.', 'ontario-obituaries' ) ); ?>';
                        }
                        btn.disabled = false;
                        btn.textContent = '<?php echo esc_js( __( 'Subscribe', 'ontario-obituaries' ) ); ?>';
                    })
                    .catch(function(){
                        msg.style.display = 'block';
                        msg.className = 'ontario-lead-message ontario-lead-error';
                        msg.textContent = '<?php echo esc_js( __( 'Something went wrong. Please try again.', 'ontario-obituaries' ) ); ?>';
                        btn.disabled = false;
                        btn.textContent = '<?php echo esc_js( __( 'Subscribe', 'ontario-obituaries' ) ); ?>';
                    });
                });
            })();
            </script>
        </div>
    </div>

    <!-- Removal notice -->
    <div class="ontario-obituary-removal-notice">
        <p><?php esc_html_e( 'This information was compiled from publicly available sources.', 'ontario-obituaries' ); ?>
        <a href="https://monacomonuments.ca/contact/"><?php esc_html_e( 'Request removal or correction', 'ontario-obituaries' ); ?></a>.</p>
    </div>

</div>

<?php
// v3.10.2 PR #24b: get_footer() removed — wrapper.php handles footer rendering.
