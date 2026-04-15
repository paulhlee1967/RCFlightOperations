<?php
/**
 * logs_viewer.php
 *
 * Admin-only file log viewer for files under /logs.
 * Allows listing, viewing (tail), and deleting log files.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/flash.php';

requireAdmin();

/**
 * @return array{dir:string, real:string}
 */
function flightops_logs_viewer_dir(): array {
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $real = realpath($dir) ?: $dir;
    return ['dir' => $dir, 'real' => $real];
}

function flightops_is_allowed_log_filename(string $name): bool {
    if ($name === '' || $name === '.' || $name === '..') return false;
    if (str_contains($name, '/') || str_contains($name, '\\')) return false;
    if (str_contains($name, "\0")) return false;
    // Restrict to common operational log extensions.
    return (bool) preg_match('/\.(log|txt)$/i', $name);
}

/**
 * Resolve a log file path inside /logs with traversal protection.
 * Returns absolute path or null.
 */
function flightops_resolve_log_file(string $name): string|null {
    if (!flightops_is_allowed_log_filename($name)) return null;

    $info = flightops_logs_viewer_dir();
    $path = $info['dir'] . '/' . $name;
    $real = realpath($path);
    if ($real === false) return null;

    $base = rtrim($info['real'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $realNorm = rtrim($real, DIRECTORY_SEPARATOR);
    if (!str_starts_with($realNorm . DIRECTORY_SEPARATOR, $base)) return null;
    if (!is_file($real) || !is_readable($real)) return null;
    return $real;
}

/**
 * Read the last N bytes of a file (UTF-8-ish best effort).
 */
function flightops_read_tail_bytes(string $filePath, int $maxBytes): string {
    $maxBytes = max(1024, min(1024 * 1024 * 2, $maxBytes)); // 1KB..2MB
    $size = @filesize($filePath);
    if ($size === false || $size <= 0) return '';

    $fh = @fopen($filePath, 'rb');
    if (!$fh) return '';
    try {
        $offset = max(0, $size - $maxBytes);
        @fseek($fh, $offset);
        $data = (string) @stream_get_contents($fh);
    } finally {
        @fclose($fh);
    }

    // If we started in the middle of a line, drop partial first line for readability.
    if ($offset > 0) {
        $pos = strpos($data, "\n");
        if ($pos !== false) {
            $data = substr($data, $pos + 1);
        }
    }
    return $data;
}

// ── POST: delete file ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'delete') {
        $name = (string) ($_POST['file'] ?? '');
        $resolved = flightops_resolve_log_file($name);
        if ($resolved === null) {
            flash('Invalid log file.', 'warning');
            header('Location: logs_viewer.php');
            exit;
        }
        if (@unlink($resolved)) {
            flash('Deleted log file: ' . $name, 'success');
        } else {
            flash('Could not delete log file (check permissions): ' . $name, 'warning');
        }
        header('Location: logs_viewer.php');
        exit;
    }
}

// ── GET: list files + optional view ────────────────────────────────────────
$viewName = isset($_GET['file']) ? (string) $_GET['file'] : '';
$viewPath = $viewName !== '' ? flightops_resolve_log_file($viewName) : null;
$viewText = '';
$viewSize = 0;
$viewMtime = 0;
if ($viewPath !== null) {
    $viewSize  = (int) (@filesize($viewPath) ?: 0);
    $viewMtime = (int) (@filemtime($viewPath) ?: 0);
    $viewText  = flightops_read_tail_bytes($viewPath, 256 * 1024); // 256KB tail
}

$info = flightops_logs_viewer_dir();
$files = [];
if (is_dir($info['dir'])) {
    $dh = @opendir($info['dir']);
    if ($dh) {
        while (($entry = readdir($dh)) !== false) {
            if (!flightops_is_allowed_log_filename($entry)) continue;
            $path = $info['dir'] . '/' . $entry;
            if (!is_file($path)) continue;
            $files[] = [
                'name'  => $entry,
                'size'  => (int) (@filesize($path) ?: 0),
                'mtime' => (int) (@filemtime($path) ?: 0),
            ];
        }
        closedir($dh);
    }
}
usort($files, static function ($a, $b) {
    return ($b['mtime'] <=> $a['mtime']) ?: strcmp($a['name'], $b['name']);
});

function fmtBytes(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    $kb = $bytes / 1024;
    if ($kb < 1024) return number_format($kb, 1) . ' KB';
    $mb = $kb / 1024;
    if ($mb < 1024) return number_format($mb, 1) . ' MB';
    $gb = $mb / 1024;
    return number_format($gb, 2) . ' GB';
}

$pageTitle   = 'File logs';
$breadcrumbs = [['label' => 'File logs', 'url' => '']];
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-4 pb-2 border-bottom">
    <div>
        <h1 class="h2 mb-1">File logs</h1>
        <p class="text-muted mb-0">Operational logs stored under <code>logs/</code> (cron output, background tasks, and optional app logs).</p>
    </div>
    <div class="text-muted small">
        <?= count($files) ?> file<?= count($files) === 1 ? '' : 's' ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Log files</div>
            <div class="card-body p-0">
                <?php if (empty($files)): ?>
                    <div class="p-4 text-muted small text-center">
                        No log files found in <code>logs/</code>.
                        <div class="mt-2">Tip: point cron output at a file, e.g. <code>... &gt;&gt; logs/cron.log 2&gt;&amp;1</code></div>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" style="font-size:.875rem;">
                            <thead class="table-light">
                                <tr>
                                    <th>File</th>
                                    <th style="width:7rem;">Size</th>
                                    <th style="width:11rem;">Modified</th>
                                    <th style="width:7rem;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($files as $f): ?>
                                    <?php $isActive = ($viewName !== '' && $viewName === $f['name']); ?>
                                    <tr<?= $isActive ? ' class="table-active"' : '' ?>>
                                        <td style="max-width: 18rem;">
                                            <a class="text-decoration-none fw-semibold" href="logs_viewer.php?file=<?= urlencode($f['name']) ?>">
                                                <?= h($f['name']) ?>
                                            </a>
                                        </td>
                                        <td class="text-muted small"><?= h(fmtBytes((int) $f['size'])) ?></td>
                                        <td class="text-muted small" style="white-space:nowrap;">
                                            <?= $f['mtime'] ? h(date('M j, Y g:ia', $f['mtime'])) : '—' ?>
                                        </td>
                                        <td class="text-end">
                                            <form method="post" action="logs_viewer.php" class="d-inline"
                                                  onsubmit="return confirm('Delete this log file? This cannot be undone.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="file" value="<?= h($f['name']) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="fw-semibold">Preview</div>
                <?php if ($viewPath !== null): ?>
                    <div class="text-muted small">
                        <?= h($viewName) ?> — <?= h(fmtBytes($viewSize)) ?>
                        <?php if ($viewMtime): ?> — <?= h(date('M j, Y g:ia', $viewMtime)) ?><?php endif; ?>
                        — showing last 256KB
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($viewName !== '' && $viewPath === null): ?>
                    <div class="alert alert-warning mb-0">That log file could not be opened.</div>
                <?php elseif ($viewPath === null): ?>
                    <div class="text-muted small">Select a file on the left to preview it.</div>
                <?php else: ?>
                    <pre class="mb-0 p-3 border rounded bg-light small" style="max-height: 65vh; overflow:auto; white-space: pre-wrap;"><?= h($viewText) ?></pre>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

