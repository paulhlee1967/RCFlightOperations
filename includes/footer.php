<?php
/**
 * includes/footer.php
 *
 * Shared layout footer: closes the main container, renders the RC Flight Operations
 * attribution bar, loads Bootstrap JS, then closes <body> and <html>.
 *
 * Branding strategy:
 *   The footer is a subtle but permanent home for the RC Flight Operations identity.
 *   Colors come from the same CSS variables as the rest of the app (set in header.php
 *   from the club row): --club-bg, --club-primary, --club-text, --club-muted, etc.
 */

// Grab app version from FLIGHT_OPS_VERSION (defined in includes/db.php).
$_footerVersion = defined('FLIGHT_OPS_VERSION') ? FLIGHT_OPS_VERSION : '1.6.0';
$_copyrightStart = defined('FLIGHT_OPS_COPYRIGHT_YEAR_START') ? (int) FLIGHT_OPS_COPYRIGHT_YEAR_START : 2025;
$_copyrightEnd   = (int) date('Y');
$_footerCopyrightYears = $_copyrightEnd > $_copyrightStart
    ? $_copyrightStart . '–' . $_copyrightEnd
    : (string) $_copyrightStart;
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
            <span>Copyright <?= htmlspecialchars($_footerCopyrightYears) ?> Paul H Lee</span>
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
    font-weight: 700;
    font-size: 0.82rem;
    letter-spacing: 0.01em;
    color: var(--club-text);
}

.fo-footer-tag {
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
    font-size: 0.72rem;
    color: var(--club-muted);
    flex-wrap: wrap;
    justify-content: flex-end;
}

.fo-footer-dot { opacity: 0.3; }

/* ── Print: hide footer entirely ──────────────────────────────────────────── */
@media print { .fo-footer { display: none !important; } }

/* ── Email sending wait overlay (forms with data-email-sending) ───────────── */
.fo-email-wait {
    position: fixed; inset: 0; z-index: 2000;
    display: flex; align-items: center; justify-content: center;
    padding: 1.5rem;
    animation: foEmailWaitIn 0.35s ease;
}
.fo-email-wait[hidden] { display: none !important; }
.fo-email-wait-backdrop {
    position: absolute; inset: 0;
    background: rgba(37, 32, 24, 0.45);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
}
.fo-email-wait-card {
    position: relative; z-index: 1;
    width: min(100%, 22rem);
    background: #fff;
    border-radius: 1rem;
    padding: 2rem 1.75rem 1.5rem;
    text-align: center;
    box-shadow: 0 1.25rem 3rem rgba(0,0,0,0.18);
    border: 1px solid color-mix(in srgb, var(--club-primary) 18%, #e8e0d4);
}
.fo-email-wait-icon {
    width: 4.5rem; height: 4.5rem; margin: 0 auto 1.1rem;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    background: linear-gradient(145deg, var(--club-primary), var(--club-primary-dark));
    color: var(--club-on-primary);
    box-shadow: 0 0.5rem 1.25rem rgba(var(--club-primary-rgb), 0.35);
}
.fo-email-wait-plane {
    width: 2rem; height: 2rem;
    animation: foEmailWaitBob 1.8s ease-in-out infinite;
}
.fo-email-wait-trail {
    display: flex; justify-content: center; gap: 0.35rem; margin-top: 1rem;
}
.fo-email-wait-trail span {
    width: 0.45rem; height: 0.45rem; border-radius: 50%;
    background: var(--club-primary); opacity: 0.25;
    animation: foEmailWaitDot 1.2s ease-in-out infinite;
}
.fo-email-wait-trail span:nth-child(2) { animation-delay: 0.15s; }
.fo-email-wait-trail span:nth-child(3) { animation-delay: 0.3s; }
.fo-email-wait-title {
    margin: 0 0 0.35rem; font-size: 1.15rem; font-weight: 700;
    color: var(--club-text); letter-spacing: -0.01em;
}
.fo-email-wait-status {
    margin: 0 0 0.75rem; font-size: 0.92rem; color: var(--club-muted);
    min-height: 1.4em; transition: opacity 0.25s ease;
}
.fo-email-wait-hint {
    margin: 0; font-size: 0.78rem;
    color: color-mix(in srgb, var(--club-muted) 80%, transparent);
}
@keyframes foEmailWaitIn {
    from { opacity: 0; transform: scale(0.96); }
    to { opacity: 1; transform: scale(1); }
}
@keyframes foEmailWaitBob {
    0%, 100% { transform: translateY(0) rotate(-8deg); }
    50% { transform: translateY(-5px) rotate(-4deg); }
}
@keyframes foEmailWaitDot {
    0%, 80%, 100% { opacity: 0.2; transform: scale(0.85); }
    40% { opacity: 1; transform: scale(1); }
}
</style>

<div id="fo-email-wait" class="fo-email-wait" hidden role="alertdialog" aria-modal="true"
     aria-labelledby="fo-email-wait-title" aria-live="polite">
    <div class="fo-email-wait-backdrop" aria-hidden="true"></div>
    <div class="fo-email-wait-card">
        <div class="fo-email-wait-icon" aria-hidden="true">
            <svg class="fo-email-wait-plane" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                <path d="M21 16v-2l-8-5V3.5a1.5 1.5 0 0 0-3 0V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z"/>
            </svg>
        </div>
        <h2 class="fo-email-wait-title" id="fo-email-wait-title">Sending email</h2>
        <p class="fo-email-wait-status" id="fo-email-wait-status">Preparing for departure…</p>
        <p class="fo-email-wait-hint">Please keep this tab open — we&rsquo;ll be right back.</p>
        <div class="fo-email-wait-trail" aria-hidden="true"><span></span><span></span><span></span></div>
    </div>
</div>

<?php require_once __DIR__ . '/vendor_assets.php'; ?>
<!-- Bootstrap 5 bundle (Popper included) — local copy in assets/vendor/ -->
<script src="<?= htmlspecialchars(flightops_bootstrap_js_url()) ?>"></script>
<script src="js/flightops_ui.js?v=<?= htmlspecialchars($_footerVersion) ?>" defer></script>
</body>
</html>