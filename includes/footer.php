<?php
/**
 * includes/footer.php
 *
 * Shared layout footer: closes the main container, renders the RC Flight Operations
 * attribution bar, loads Bootstrap JS, then closes <body> and <html>.
 *
 * Branding strategy:
 *   The footer is a subtle but permanent home for the RC Flight Operations identity.
 *   Colours come from the same CSS variables as the rest of the app (set in header.php
 *   from the club row): --club-bg, --club-primary, --club-text, --club-muted, etc.
 */

// Grab app version from a constant if defined (set it in config.php or db.php)
// Falls back gracefully if not defined.
$_footerVersion = defined('FLIGHT_OPS_VERSION') ? FLIGHT_OPS_VERSION : '1.0';
$_footerYear    = date('Y');
?>

</div><!-- /.container (opened in header.php) -->

<!-- ══════════════════════════════════════════════════════════════════════════
     RC Flight Operations footer bar
     Intentionally muted — the club owns the top; RC Flight Operations signs the bottom.
     ════════════════════════════════════════════════════════════════════════ -->
<footer class="fo-footer" role="contentinfo">
    <div class="container fo-footer-inner">

        <!-- Left: RC Flight Operations logo + wordmark -->
        <div class="fo-footer-brand">
            <?php
            if (!function_exists('flightops_logo')) {
                require_once __DIR__ . '/flightops_logo.php';
            }
            flightops_logo(22, false);
            ?>

            <span class="fo-footer-name">RC Flight Operations</span>
            <span class="fo-footer-tag">Member Management</span>
        </div>

        <!-- Right: version + copyright -->
        <div class="fo-footer-meta">
            <span>v<?= htmlspecialchars($_footerVersion) ?></span>
            <span class="fo-footer-dot" aria-hidden="true">·</span>
            <span>&copy; <?= $_footerYear ?> RC Flight Operations</span>
            <span class="fo-footer-dot" aria-hidden="true">·</span>
            <span>Open source · MIT</span>
        </div>

    </div>
</footer>

<style<?= csp_nonce_attr() ?>>
/* ── RC Flight Operations footer (uses club theme from :root in header.php) ─ */
.fo-footer {
    margin-top: 3rem;
    padding: 0.9rem 0;
    background: var(--club-bg);
    border-top: 4px solid;
    border-image: linear-gradient(
        90deg,
        var(--club-primary) 0%,
        var(--club-muted) 38%,
        var(--club-primary-dark) 68%,
        var(--club-primary) 100%
    ) 1;
}

.fo-footer-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.5rem;
}

/* Brand cluster: icon + name + tagline */
.fo-footer-brand {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.fo-footer-brand .fo-brand img {
    flex-shrink: 0;
    border-radius: 5px;
    filter: drop-shadow(0 1px 2px rgba(0,0,0,.12));
}

.fo-footer-name {
    font-family: 'Segoe UI', system-ui, sans-serif;
    font-weight: 700;
    font-size: 0.82rem;
    letter-spacing: 0.01em;
    color: var(--club-text);
}

.fo-footer-tag {
    font-family: 'Segoe UI', system-ui, sans-serif;
    font-size: 0.72rem;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--club-muted);
    /* hide tagline on very small screens to prevent wrapping */
}
@media (max-width: 400px) { .fo-footer-tag { display: none; } }

/* Meta: version · year · license */
.fo-footer-meta {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    font-family: 'Segoe UI', system-ui, sans-serif;
    font-size: 0.72rem;
    color: var(--club-muted);
    flex-wrap: wrap;
    justify-content: flex-end;
}

.fo-footer-dot { opacity: 0.3; }

/* ── Print: hide footer entirely ──────────────────────────────────────────── */
@media print { .fo-footer { display: none !important; } }
</style>

<!-- Bootstrap 5 bundle (Popper included) — CDN, no cost, version-pinned -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
        crossorigin="anonymous"></script>
<script src="js/flightops_ui.js" defer></script>
</body>
</html>