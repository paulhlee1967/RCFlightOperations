<?php
/**
 * users.php — System users list and add.
 *
 * People who can log in to this app (not club members). Admin only.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/password_policy.php';
require_once __DIR__ . '/includes/audit_log.php';

requireAdmin();
$saved       = false;
$error       = '';
$newPassword = '';

$showForm = isset($_GET['add']) || ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['add_user']));

// ── Add user ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    csrf_validate();

    $email    = trim($_POST['email']    ?? '');
    $name     = trim($_POST['name']     ?? '');
    $role     = trim($_POST['role']     ?? 'editor');
    $password = $_POST['password']      ?? '';

    $roles = array_keys(getSystemUserRoles());
    if (!in_array($role, $roles, true)) $role = 'editor';

    if ($email === '') {
        $error = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        list($pwOk, $pwError) = validate_password_policy($password);
        if (!$pwOk) {
            $error = $pwError;
        } else {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'A user with that email already exists in this club.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare(
                    'INSERT INTO users (email, password_hash, name, role, active) VALUES (?, ?, ?, ?, 1)'
                );
                $stmt->execute([$email, $hash, $name ?: $email, $role]);
                audit_log($pdo, currentUserId(), 'user_add', 'user', (int) $pdo->lastInsertId(), '');
                $saved       = true;
                $newPassword = $password;
                $showForm    = false;
            }
        }
    }
}

// ── Fetch users ───────────────────────────────────────────────────────────────
$stmt = $pdo->query(
    'SELECT id, email, name, role, COALESCE(active, 1) AS active, created_at
     FROM   users
     ORDER  BY active DESC, name, email'
);
$users = $stmt->fetchAll();

// ── Helpers ───────────────────────────────────────────────────────────────────
function userInitials(string $name, string $email): string {
    $src   = $name ?: $email;
    $parts = preg_split('/[\s@._\-]+/', $src, -1, PREG_SPLIT_NO_EMPTY);
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
    }
    return strtoupper(substr($src, 0, 2));
}

function roleBadge(string $role): array {
    return match ($role) {
        'admin'     => ['class' => 'role-badge-admin',     'label' => 'Admin'],
        'editor'    => ['class' => 'role-badge-editor',    'label' => 'Editor'],
        'treasurer' => ['class' => 'role-badge-treasurer', 'label' => 'Treasurer'],
        'viewer'    => ['class' => 'role-badge-viewer',    'label' => 'Viewer'],
        default     => ['class' => 'role-badge-viewer',    'label' => ucfirst($role)],
    };
}

$pageTitle = 'System Users';
require_once __DIR__ . '/includes/header.php';
?>

<!-- ── Page-level styles ─────────────────────────────────────────────────────── -->
<style<?= csp_nonce_attr() ?>>
.role-badge {
    display: inline-flex; align-items: center;
    font-size: 0.7rem; font-weight: 700;
    padding: 0.2em 0.55em; border-radius: 4px;
    letter-spacing: 0.04em; text-transform: uppercase; white-space: nowrap;
}
.role-badge-admin     { background: rgba(var(--club-primary-rgb), 0.12); color: var(--club-primary); }
.role-badge-editor    { background: #e7f5eb; color: #198754; }
.role-badge-treasurer { background: #fff3cd; color: #8a6200; }
.role-badge-viewer    { background: #f0f0f0; color: #555; }

.user-avatar {
    width: 36px; height: 36px; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 0.7rem; font-weight: 700; letter-spacing: 0.02em;
    flex-shrink: 0; color: #fff; user-select: none;
}
.user-avatar-admin     { background: var(--club-primary); }
.user-avatar-editor    { background: #198754; }
.user-avatar-treasurer { background: #f6a800; }
.user-avatar-viewer    { background: #6c757d; }

.user-row td    { vertical-align: middle; padding: 0.75rem 1rem; }
.user-row.inactive { opacity: 0.5; }

#addUserCard        { display: none; }
#addUserCard.open   { display: block; }

.empty-state { text-align: center; padding: 3rem 2rem; color: #999; }
.users-page-header {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 1rem;
    margin-bottom: 1.5rem; padding-bottom: 1.25rem; border-bottom: 1px solid #e9ecef;
}
</style>

<!-- ── Page header ──────────────────────────────────────────────────────────── -->
<div class="users-page-header">
    <div>
        <h1 class="h2 mb-1">System Users</h1>
        <p class="text-muted mb-0">
            People who can log in to this app. These are <em>not</em> club members —
            use the Members section for those.
        </p>
    </div>
    <button class="btn btn-primary" id="addUserToggle" type="button">+ Add user</button>
</div>

<!-- ── Flash alerts ─────────────────────────────────────────────────────────── -->
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?= h($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($saved && $newPassword !== ''): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>User added!</strong> Share this temporary password securely — it won't be shown again:<br>
    <code class="user-select-all"><?= h($newPassword) ?></code>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php elseif ($saved): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    User added successfully.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── Add user form (collapsible) ─────────────────────────────────────────── -->
<div class="card shadow-sm mb-4 <?= $showForm ? 'open' : '' ?>" id="addUserCard">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span class="fw-semibold">Add a new user</span>
        <button type="button" class="btn-close btn-sm" id="addUserClose" aria-label="Cancel"></button>
    </div>
    <div class="card-body">
        <form method="post" action="users.php" class="row g-3" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="add_user" value="1">
            <div class="col-md-4">
                <label class="form-label" for="nu_email">Email <span class="text-danger">*</span></label>
                <input type="email" class="form-control" id="nu_email" name="email"
                       required placeholder="jane@club.org"
                       value="<?= h($_POST['email'] ?? '') ?>">
                <div class="form-text">Used as their login username.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="nu_name">Display name</label>
                <input type="text" class="form-control" id="nu_name" name="name"
                       placeholder="Jane Doe"
                       value="<?= h($_POST['name'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="nu_role">Role</label>
                <select name="role" id="nu_role" class="form-select">
                    <?php foreach (getSystemUserRoles() as $value => $label): ?>
                    <option value="<?= h($value) ?>"
                        <?= ($_POST['role'] ?? 'editor') === $value ? ' selected' : '' ?>>
                        <?= h($label) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="nu_pass">Password <span class="text-danger">*</span></label>
                <input type="password" class="form-control password-strength-input" id="nu_pass"
                       name="password" required autocomplete="new-password"
                       placeholder="Min 8 characters">
                <div class="password-strength small mt-1" aria-live="polite"></div>
            </div>
            <div class="col-12 pt-1">
                <button type="submit" class="btn btn-primary">Add user</button>
                <button type="button" class="btn btn-outline-secondary ms-2" id="addUserClose2">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Users table ───────────────────────────────────────────────────────────── -->
<div class="card shadow-sm">
    <?php if (empty($users)): ?>
    <div class="empty-state">
        <p class="fw-semibold mb-1">No users yet</p>
        <p class="small text-muted">Add a user above to let someone log in.</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:2.5rem;"></th>
                    <th>Name / Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th class="d-none d-md-table-cell">Added</th>
                    <th class="text-end pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u):
                    $badge    = roleBadge($u['role']);
                    $initials = userInitials($u['name'], $u['email']);
                    $isMe     = (int) $u['id'] === currentUserId();
                    $isActive = (int) ($u['active'] ?? 1) === 1;
                ?>
                <tr class="user-row<?= !$isActive ? ' inactive' : '' ?>">
                    <td class="ps-3">
                        <span class="user-avatar user-avatar-<?= h($u['role']) ?>">
                            <?= h($initials) ?>
                        </span>
                    </td>
                    <td>
                        <div class="fw-semibold">
                            <?= h($u['name'] ?: $u['email']) ?>
                            <?php if ($isMe): ?>
                            <span class="badge bg-secondary ms-1" style="font-size:.65rem;">You</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted small"><?= h($u['email']) ?></div>
                    </td>
                    <td>
                        <span class="role-badge <?= h($badge['class']) ?>">
                            <?= h($badge['label']) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($isActive): ?>
                        <span class="badge bg-success">Active</span>
                        <?php else: ?>
                        <span class="badge bg-secondary">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td class="d-none d-md-table-cell text-muted small">
                        <?= !empty($u['created_at']) ? date('M j, Y', strtotime($u['created_at'])) : '—' ?>
                    </td>
                    <td class="text-end pe-3">
                        <div class="d-flex gap-2 justify-content-end align-items-center">
                            <a href="user_edit.php?id=<?= (int) $u['id'] ?>"
                               class="btn btn-sm btn-outline-primary">Edit</a>
                            <?php if (!$isMe): ?>
                                <a href="user_delete.php?id=<?= (int) $u['id'] ?>"
                                   class="btn btn-sm btn-outline-danger"
                                   data-confirm="Delete this user? This cannot be undone.">Delete</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<p class="mt-3">
    <a href="index.php" class="btn btn-outline-secondary btn-sm">← Back to Home</a>
</p>

<script<?= csp_nonce_attr() ?>>
(function () {
    var toggle  = document.getElementById('addUserToggle');
    var card    = document.getElementById('addUserCard');
    var close1  = document.getElementById('addUserClose');
    var close2  = document.getElementById('addUserClose2');

    function openForm()  { if (card) { card.classList.add('open');    var f = card.querySelector('input[type=email]'); if (f) f.focus(); } }
    function closeForm() { if (card) card.classList.remove('open'); }

    if (toggle) toggle.addEventListener('click', openForm);
    if (close1) close1.addEventListener('click', closeForm);
    if (close2) close2.addEventListener('click', closeForm);

    <?php if ($error): ?>
    openForm();
    <?php endif; ?>
})();
</script>
<?php require_once __DIR__ . '/includes/password_strength_ui.php'; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>