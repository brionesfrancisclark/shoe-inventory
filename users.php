<?php
declare(strict_types=1);
require __DIR__ . '/layout.php';
require_once __DIR__ . '/inventory-lib.php';      // form_str()
require_once __DIR__ . '/permissions-lib.php';

/*
 * Users (admin only). There is exactly ONE administrator account: every account created here is
 * Staff, and what each staff member may do is chosen per person on the "Manage permissions" page.
 * The single-admin rule is enforced here AND by the database (unique admin slot + triggers), so a
 * forged request cannot create, promote, demote, deactivate or delete an administrator.
 */

// Server-side gate for EVERY request to this page, including direct POSTs.
$me = require_role('admin');

$errors = [];
$old    = ['display_name' => '', 'username' => ''];

/** A readable message for the errors the database raises to protect accounts, or null. */
function account_db_problem(PDOException $ex): ?string
{
    $code   = (int) ($ex->errorInfo[1] ?? 0);
    $detail = (string) ($ex->errorInfo[2] ?? '');
    if ($code === 1644) {                                    // a protection trigger refused it
        return $detail !== '' ? $detail : 'The database refused this change.';
    }
    if ($code === 1062) {
        return str_contains($detail, "uq_single_admin'") ? 'There can only be one administrator account.' : 'That username is already taken.';
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors[] = 'Security token invalid. Please reload the page and try again.';

    } elseif ($action === 'add_user') {
        // New accounts are ALWAYS staff. A "role" sent with the form is ignored on purpose.
        $old['display_name'] = trim(form_str($_POST, 'display_name'));
        $old['username']     = trim(form_str($_POST, 'username'));
        $password            = form_str($_POST, 'password');

        if ($old['display_name'] === '' || mb_strlen($old['display_name']) > 80) $errors[] = 'Name is required (max 80 characters).';
        if ($msg = validate_username($old['username'])) $errors[] = $msg;
        if ($msg = validate_password($password))        $errors[] = $msg;

        if (!$errors) {
            $exists = $pdo->prepare('SELECT 1 FROM users WHERE username = :u');
            $exists->execute([':u' => $old['username']]);
            if ($exists->fetchColumn()) {
                $errors[] = 'That username is already taken.';
            }
        }
        if (!$errors) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare(
                    "INSERT INTO users (username, display_name, password_hash, role, is_active, created_at)
                     VALUES (:u, :d, :h, 'staff', 1, :t)"
                )->execute([
                    ':u' => $old['username'], ':d' => $old['display_name'],
                    ':h' => password_hash($password, PASSWORD_DEFAULT), ':t' => time(),
                ]);
                $newId = (int) $pdo->lastInsertId();
                grant_default_permissions($pdo, $newId, (int) $me['id']);     // view-only to start with
                $pdo->commit();
                flash('success', 'Account "' . $old['username'] . '" created as Staff with view-only access. '
                               . 'Tick what else they may do, then save.');
                redirect('user-permissions.php?id=' . $newId);
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('users.php add_user: ' . $ex->getMessage());
                $errors[] = account_db_problem($ex) ?? 'Database error: the account was not created.';
            }
        }

    } elseif ($action === 'update_user') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        $st = $pdo->prepare('SELECT id, username, role, is_active FROM users WHERE id = :id');
        $st->execute([':id' => $id ?: 0]);
        $target = $st->fetch();

        if (!$target) {
            $errors[] = 'That account was not found.';
        } else {
            // Roles never change here (no promotion, no demotion): a "role" sent with the form is ignored.
            // The administrator account always stays active.
            $active = $target['role'] === 'admin' ? 1 : (isset($_POST['is_active']) ? 1 : 0);
            $newPw  = form_str($_POST, 'new_password');
            if ($newPw !== '' && ($msg = validate_password($newPw))) $errors[] = $msg;

            if (!$errors) {
                $sql    = 'UPDATE users SET is_active = :a, failed_attempts = 0, locked_until = NULL';
                $params = [':a' => $active, ':id' => $target['id']];
                if ($newPw !== '') {
                    $sql .= ', password_hash = :h';
                    $params[':h'] = password_hash($newPw, PASSWORD_DEFAULT);
                }
                try {
                    $pdo->prepare($sql . ' WHERE id = :id')->execute($params);
                    flash('success', 'Account "' . $target['username'] . '" updated.' . ($newPw !== '' ? ' Password was reset.' : ''));
                    redirect('users.php');
                } catch (PDOException $ex) {
                    error_log('users.php update_user: ' . $ex->getMessage());
                    $errors[] = account_db_problem($ex) ?? 'Database error: the account was not updated.';
                }
            }
        }

    } elseif ($action === 'delete_user') {
        // Result is always reported through a flash banner + redirect.
        $id      = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        $problem = null;      // error message, if the delete was refused or failed
        $result  = null;      // 'deleted' | 'deactivated'
        $label   = '';

        if ($id === false || $id < 1) {
            $problem = 'Invalid account.';
        } elseif ($id === (int) $me['id']) {
            $problem = 'You cannot delete your own account while you are signed in.';
        } else {
            try {
                $pdo->beginTransaction();
                // Lock the target row so two requests can't act on it at the same time.
                $st = $pdo->prepare('SELECT id, username, role, is_active FROM users WHERE id = :id FOR UPDATE');
                $st->execute([':id' => $id]);
                $target = $st->fetch();

                if (!$target) {
                    $problem = 'That account no longer exists.';
                } elseif ($target['role'] === 'admin') {
                    $problem = 'The administrator account cannot be deleted.';
                } else {
                    $label = '"' . $target['username'] . '" (' . (ROLES[$target['role']] ?? $target['role']) . ')';
                }

                if ($problem !== null) {
                    $pdo->rollBack();
                } else {
                    try {
                        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $id]);   // its permissions go with it
                        $result = 'deleted';
                    } catch (PDOException $fk) {
                        // 1451 = the stock history still refers to this person. Keep that history intact
                        // and disable the account instead.
                        if ((int) ($fk->errorInfo[1] ?? 0) !== 1451) {
                            throw $fk;
                        }
                        $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = :id')->execute([':id' => $id]);
                        $result = 'deactivated';
                    }
                    $pdo->commit();
                }
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('users.php delete_user: ' . $ex->getMessage());
                $problem = account_db_problem($ex) ?? 'Database error: the account was not deleted.';
                $result  = null;
            }
        }

        if ($problem !== null) {
            flash('danger', $problem);
        } elseif ($result === 'deactivated') {
            flash('warning', 'Account ' . $label . ' has stock history, so it was disabled instead of deleted (the history keeps who did what). It can no longer sign in.');
        } else {
            flash('success', 'Account ' . $label . ' was deleted.');
        }
        redirect('users.php');
    }
}

$users = $pdo->query(
    'SELECT u.id, u.username, u.display_name, u.role, u.is_active, u.last_login, u.created_at,
            (SELECT COUNT(*) FROM user_permissions up WHERE up.user_id = u.id) AS permissions
       FROM users u ORDER BY u.role ASC, u.display_name ASC'
)->fetchAll();
$permissionTotal = (int) $pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn();

render_header('Users', 'users');
?>
<main id="main" class="container pb-5">
    <?php render_flash(); ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger" role="alert">
            <strong>Please fix the following:</strong>
            <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <div class="mb-4">
        <h1 class="page-title mb-1">Users &amp; Permissions</h1>
        <p class="page-sub mb-0">Create staff accounts and choose, for each person, exactly what they may do.</p>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card h-100 shadow-sm"><div class="card-body d-flex align-items-start gap-3">
                <span class="role-icon" aria-hidden="true"><i class="bi bi-shield-lock"></i></span>
                <div>
                    <span class="badge text-bg-primary mb-2">Admin</span>
                    <p class="mb-0 small text-muted">Full access to everything, always. There is only one administrator account, and it cannot be
                        deleted, disabled or changed to staff.</p>
                </div>
            </div></div>
        </div>
        <div class="col-md-6">
            <div class="card h-100 shadow-sm"><div class="card-body d-flex align-items-start gap-3">
                <span class="role-icon" aria-hidden="true"><i class="bi bi-person-badge"></i></span>
                <div>
                    <span class="badge text-bg-secondary mb-2">Staff</span>
                    <p class="mb-0 small text-muted">Only what you tick for that person under <strong>Manage permissions</strong>.
                        New staff accounts start with view-only access.</p>
                </div>
            </div></div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">Add a staff account</div>
        <div class="card-body">
            <form method="post" action="users.php" class="row g-3 needs-validation" novalidate>
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="add_user">
                <div class="col-md-4">
                    <label for="display_name" class="form-label">Name</label>
                    <input type="text" class="form-control" id="display_name" name="display_name" maxlength="80" required value="<?= e($old['display_name']) ?>">
                    <div class="invalid-feedback">Enter a name.</div>
                </div>
                <div class="col-md-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" class="form-control" id="username" name="username" minlength="3" maxlength="30"
                           pattern="[A-Za-z0-9_.\-]+" autocomplete="off" required value="<?= e($old['username']) ?>">
                    <div class="invalid-feedback">3-30 letters, numbers, . - _</div>
                </div>
                <div class="col-md-3">
                    <label for="password" class="form-label">Temporary password</label>
                    <input type="password" class="form-control" id="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" required>
                    <div class="invalid-feedback">At least 8 characters.</div>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-success w-100">Add staff</button>
                </div>
                <div class="col-12">
                    <p class="form-text mb-0">New accounts are always <strong>Staff</strong> and start with view-only access.
                        After adding, you choose what else they may do.</p>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-grid align-middle mb-0">
                <thead class="table-light">
                    <tr><th scope="col">Name</th><th scope="col">Username</th><th scope="col">Role</th><th scope="col">Status</th>
                        <th scope="col">Access</th><th scope="col">Last sign-in</th><th scope="col" class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u):
                    $uid     = (int) $u['id'];
                    $isMe    = $uid === (int) $me['id'];
                    $isAdmin = $u['role'] === 'admin'; ?>
                    <tr>
                        <td class="fw-semibold"><?= e($u['display_name']) ?><?= $isMe ? ' <span class="badge text-bg-light border">You</span>' : '' ?></td>
                        <td><?= e($u['username']) ?></td>
                        <td><span class="badge text-bg-<?= $isAdmin ? 'primary' : 'secondary' ?>"><?= e(ROLES[$u['role']] ?? $u['role']) ?></span></td>
                        <td><span class="badge text-bg-<?= (int) $u['is_active'] ? 'success' : 'danger' ?>"><?= (int) $u['is_active'] ? 'Active' : 'Disabled' ?></span></td>
                        <td class="small"><?= $isAdmin ? 'Full access' : (int) $u['permissions'] . ' of ' . $permissionTotal . ' permissions' ?></td>
                        <td class="small text-muted"><?= e(fmt_time($u['last_login'] !== null ? (int) $u['last_login'] : null)) ?></td>
                        <td class="text-end">
                            <div class="d-inline-flex flex-wrap justify-content-end gap-2">
                                <?php if (!$isAdmin): ?>
                                    <a class="btn btn-primary btn-sm" href="user-permissions.php?id=<?= $uid ?>">Manage permissions</a>
                                <?php endif; ?>
                                <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#edit-user-<?= $uid ?>" aria-expanded="false">Edit</button>
                                <?php if ($isAdmin): ?>
                                    <button class="btn btn-outline-danger btn-sm" type="button" disabled
                                            title="The administrator account cannot be deleted">Delete</button>
                                <?php else: ?>
                                    <button class="btn btn-outline-danger btn-sm btn-delete-user" type="button"
                                            data-id="<?= $uid ?>" data-name="<?= e($u['display_name']) ?>" data-username="<?= e($u['username']) ?>"
                                            data-role="<?= e($u['role']) ?>" data-role-label="<?= e(ROLES[$u['role']] ?? $u['role']) ?>">Delete</button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <tr class="collapse" id="edit-user-<?= $uid ?>">
                        <td colspan="7" class="bg-light">
                            <form method="post" action="users.php" class="row g-3 align-items-end p-2">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="update_user">
                                <input type="hidden" name="id" value="<?= $uid ?>">
                                <div class="col-md-4">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="active-<?= $uid ?>"
                                               name="is_active" value="1" <?= (int) $u['is_active'] ? 'checked' : '' ?> <?= $isAdmin ? 'disabled' : '' ?>>
                                        <label class="form-check-label small" for="active-<?= $uid ?>">Account active</label>
                                    </div>
                                    <?php if ($isAdmin): ?><div class="form-text">The administrator account always stays active.</div><?php endif; ?>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label small mb-1" for="pw-<?= $uid ?>">Reset password</label>
                                    <input type="password" class="form-control form-control-sm" id="pw-<?= $uid ?>" name="new_password"
                                           minlength="8" maxlength="72" autocomplete="new-password" placeholder="Leave blank to keep current">
                                </div>
                                <div class="col-md-3">
                                    <button type="submit" class="btn btn-primary btn-sm w-100">Save</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- Delete account confirmation -->
<div class="modal fade" id="delete-user-modal" tabindex="-1" aria-labelledby="delete-user-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" action="users.php" class="modal-content">
            <input type="hidden" name="action" value="delete_user">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" id="delete-user-id" value="">
            <div class="modal-header">
                <h2 class="modal-title h5" id="delete-user-title">Delete account</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Are you sure you want to delete this account?</p>
                <div class="border rounded p-3 bg-light">
                    <div class="fw-semibold" id="delete-user-name"></div>
                    <div class="small text-muted">Username: <span id="delete-user-username"></span></div>
                    <div class="mt-2">Role: <span class="badge" id="delete-user-role"></span></div>
                </div>
                <p class="small text-muted mt-3 mb-0">The account will be removed and can no longer sign in. If it appears in the stock
                    history, it is disabled instead, so the history keeps who did what.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger" id="delete-user-yes">Yes, Delete</button>
            </div>
        </form>
    </div>
</div>

<?php render_scripts(); ?>
<script>
    document.querySelectorAll('form.needs-validation').forEach(function (f) {
        f.addEventListener('submit', function (ev) {
            if (!f.checkValidity()) { ev.preventDefault(); f.classList.add('was-validated'); f.querySelector(':invalid').focus(); }
        });
    });

    /* ---------- Delete account confirmation ---------- */
    (function () {
        const modalEl = document.getElementById('delete-user-modal');
        if (!modalEl) return;
        const modal    = new bootstrap.Modal(modalEl);
        const idInput  = document.getElementById('delete-user-id');
        const nameEl   = document.getElementById('delete-user-name');
        const userEl   = document.getElementById('delete-user-username');
        const roleEl   = document.getElementById('delete-user-role');
        const yesBtn   = document.getElementById('delete-user-yes');

        document.querySelectorAll('.btn-delete-user').forEach(function (btn) {
            btn.addEventListener('click', function () {
                idInput.value       = btn.dataset.id;
                nameEl.textContent  = btn.dataset.name;
                userEl.textContent  = btn.dataset.username;
                roleEl.textContent  = btn.dataset.roleLabel;
                roleEl.className    = 'badge text-bg-' + (btn.dataset.role === 'admin' ? 'primary' : 'secondary');
                modal.show();
            });
        });

        // Prevent double-submits (disable after the form data has been captured).
        modalEl.querySelector('form').addEventListener('submit', function () {
            setTimeout(function () { yesBtn.disabled = true; }, 0);
        });
    })();
</script>
</body>
</html>
