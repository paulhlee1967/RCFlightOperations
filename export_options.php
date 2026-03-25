<?php
/**
 * Export options: pick format, filter, year, then download.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

requireLogin();
if (!canEditMembers() && !canProcessMemberships()) {
    header('Location: index.php');
    exit;
}

$currentYear = (int) date('Y');
$pageTitle = 'Export members';
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="h2 mb-3">Export members</h1>
<p class="text-muted mb-4">Choose format and filter, then download CSV.</p>

<form method="post" action="export.php" class="card mb-4" style="max-width: 28rem;">
    <div class="card-body">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label">Format</label>
            <select name="format" class="form-select">
                <option value="full">Full (all fields, import round-trip)</option>
                <option value="short">Short (name, email, AMA, FAA, gate key)</option>
                <option value="email">Email only (Last, First, Email)</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Filter</label>
            <select name="filter" id="export-filter" class="form-select">
                <option value="all">All members</option>
                <option value="year">Members for year</option>
                <option value="not_renewed">Not renewed for year</option>
            </select>
        </div>
        <div class="mb-3" id="export-year-wrap">
            <label class="form-label">Year</label>
            <select name="year" class="form-select">
                <?php for ($y = $currentYear + 1; $y >= $currentYear - 5; $y--): ?>
                <option value="<?= $y ?>"<?= $y === $currentYear ? ' selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Download CSV</button>
        <a href="members.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>

<p class="small text-muted">
    <strong>Full</strong>: FirstName, LastName, Email, address, phones, AMA/FAA, etc. (same as import).<br>
    <strong>Short</strong>: FirstName, LastName, Email, AMA_NO, AMA_EXP, FAA_NO, FAA_EXP, GateKey.<br>
    <strong>Not renewed</strong>: Members who had last year’s renewal but no payment for the selected year.
</p>

<script<?= csp_nonce_attr() ?>>
document.getElementById('export-filter').addEventListener('change', function() {
    document.getElementById('export-year-wrap').style.display = this.value === 'all' ? 'none' : 'block';
});
document.getElementById('export-filter').dispatchEvent(new Event('change'));
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
