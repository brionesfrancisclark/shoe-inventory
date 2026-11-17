<?php
declare(strict_types=1);
require __DIR__ . '/layout.php';
require_once __DIR__ . '/inventory-lib.php';
require_once __DIR__ . '/permissions-lib.php';

/*
 * Manage ONE staff member's permissions (admin only - staff can never open this page, not even for
 * themselves). Ticking a permission also ticks what it needs (e.g. Stock In needs View Inventory) and
 * unticking one also unticks what depends on it; the server applies the same rule when saving, in one
 * transaction. The admin account has no checklist: it always has full access.
 */

$me = require_role('admin');
$id = (int) filter_var($_GET['id'] ?? $_POST['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 0]]);

$st = $pdo->prepare('SELECT id, username, display_name, role, is_active, last_login FROM users WHERE id = :id');
$st->execute([':id' => $id]);
$target = $st->fetch();
if (!$target) {
    flash('danger', 'That account no longer exists.');
    redirect('users.php');
}
if ($target['role'] !== 'staff') {
    flash('info', 'The administrator always has full access, so there is nothing to set.');
    redirect('users.php');
}

$catalog = permission_catalog($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        flash('danger', 'Security token invalid. Nothing was saved - please try again.');
        redirect('user-permissions.php?id=' . $id);
    }
    $codes = $_POST['perm'] ?? [];
    $codes = is_array($codes) ? array_values(array_filter($codes, 'is_string')) : [];
    $names = static fn (array $list): string => implode(', ', array_map(static fn (string $c): string => $catalog[$c]['label'] ?? $c, $list));
    try {
        $r = save_staff_permissions($pdo, $id, $codes, (int) $me['id']);
        if (!$r['added'] && !$r['removed']) {
            flash('info', 'Nothing changed - ' . $target['display_name'] . ' already had exactly these permissions.');
        } else {
            flash('success', 'Saved the permissions of ' . $target['display_name'] . '. '
                . ($r['added'] ? 'Added: ' . $names($r['added']) . '. ' : '')
                . ($r['removed'] ? 'Removed: ' . $names($r['removed']) . '. ' : '')
                . 'They now have ' . count($r['now']) . ' of ' . count($catalog) . ' permissions; the change applies on their next click.');
        }
    } catch (UserError $ex) {
        flash('danger', 'Nothing was saved. ' . $ex->getMessage());
    } catch (PDOException $ex) {
        error_log('user-permissions.php: ' . $ex->getMessage());
        flash('danger', 'Database error: nothing was saved.');
    }
    redirect('user-permissions.php?id=' . $id);
}

$has    = array_flip(load_user_permissions($pdo, $id));
$groups = [];
foreach ($catalog as $code => $p) {
    $groups[$p['category']][] = $p;
}
$css = <<<'CSS'
<style>
    .perm-item { padding: .6rem .75rem; border-radius: 12px; border: 1px solid transparent; }
    .perm-item + .perm-item { margin-top: .25rem; }
    .perm-item:hover { background: #fafafa; border-color: #ececec; }
    .perm-item.is-changed { background: #fffbea; border-color: #f2d27a; }
    .perm-item .form-check-input { margin-top: .3rem; width: 1.15em; height: 1.15em; }
    .perm-item .form-check-input:not(:checked) { border-color: #8f8f91; }   /* unticked boxes clearly visible */
    .perm-chip { display: inline-block; padding: .2rem .6rem; margin: 0 .25rem .35rem 0; border-radius: 50rem; background: #f0f0f0; font-size: .78rem; font-weight: 600; }
</style>
CSS;

render_header('Permissions: ' . $target['display_name'], 'users', true, $css);
?>
<main id="main" class="container pb-5" style="max-width: 900px;">
    <?php render_flash(); ?>

    <a href="users.php" class="btn btn-link btn-sm px-0 mb-2"><i class="bi bi-arrow-left" aria-hidden="true"></i> All users</a>
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <h1 class="page-title mb-1">Permissions</h1>
            <p class="page-sub mb-0">
                <strong class="text-dark"><?= e($target['display_name']) ?></strong> &middot; <?= e($target['username']) ?> &middot; Staff &middot;
                <span class="badge text-bg-<?= (int) $target['is_active'] ? 'success' : 'danger' ?>"><?= (int) $target['is_active'] ? 'Active' : 'Disabled' ?></span>
                &middot; last sign-in: <?= e(fmt_time($target['last_login'] !== null ? (int) $target['last_login'] : null)) ?>
            </p>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <p class="small fw-semibold mb-2">Enabled right now: <span id="enabled-count"><?= count($has) ?></span> of <?= count($catalog) ?></p>
            <div id="enabled-list">
                <?php foreach ($catalog as $code => $p): if (isset($has[$code])): ?>
                    <span class="perm-chip"><?= e($p['label']) ?></span>
                <?php endif; endforeach; ?>
                <?php if (!$has): ?><span class="small text-muted">None - this person can sign in but cannot open any page.</span><?php endif; ?>
            </div>
        </div>
    </div>

    <form method="post" action="user-permissions.php?id=<?= (int) $target['id'] ?>" id="perm-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $target['id'] ?>">

        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-all="1">Select all</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-all="0">Clear all</button>
            <span class="small text-muted ms-1" id="ticked-count" aria-live="polite"></span>
        </div>

        <?php foreach ($groups as $category => $items): $gid = 'g' . substr(md5($category), 0, 8); ?>
        <div class="card shadow-sm mb-4 perm-group" id="<?= $gid ?>">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="fw-semibold"><?= e($category) ?></span>
                <span class="d-flex gap-1">
                    <button type="button" class="btn btn-link btn-sm" data-group-all="1" aria-label="Select all in <?= e($category) ?>">Select all</button>
                    <button type="button" class="btn btn-link btn-sm" data-group-all="0" aria-label="Clear all in <?= e($category) ?>">Clear</button>
                </span>
            </div>
            <div class="card-body p-3">
                <?php foreach ($items as $p):
                    $pid      = 'p-' . str_replace('.', '-', $p['code']);
                    $requires = $p['requires'] !== null ? ($catalog[$p['requires']]['label'] ?? $p['requires']) : null; ?>
                    <div class="form-check perm-item">
                        <input class="form-check-input perm" type="checkbox" name="perm[]" value="<?= e($p['code']) ?>" id="<?= $pid ?>"
                               data-requires="<?= e((string) $p['requires']) ?>"<?= isset($has[$p['code']]) ? ' checked' : '' ?>>
                        <label class="form-check-label d-block" for="<?= $pid ?>">
                            <span class="fw-semibold"><?= e($p['label']) ?></span>
                            <span class="d-block small text-muted"><?= e($p['description']) ?><?= $requires !== null ? ' Also needs: ' . e($requires) . '.' : '' ?></span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <p class="small text-muted">The administrator always has full access. Staff only get what is ticked here. Changes apply on their next click.</p>
        <div class="d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-success px-4" id="perm-save">Save changes
                <span class="spinner-border spinner-border-sm btn-spinner ms-1" aria-hidden="true"></span></button>
            <a href="users.php" class="btn btn-light border">Cancel</a>
            <span class="small text-muted align-self-center" id="perm-changes" aria-live="polite"></span>
        </div>
    </form>
</main>

<?php render_scripts(); ?>
<script>
(function () {
    'use strict';
    const form = document.getElementById('perm-form');
    const boxes = Array.from(form.querySelectorAll('input.perm'));
    const byCode = {};
    boxes.forEach((b) => { byCode[b.value] = b; b.dataset.initial = b.checked ? '1' : '0'; });
    let saving = false;

    /* Does this permission (directly or indirectly) need `code`? */
    function needs(box, code) {
        let req = box.dataset.requires;
        for (let steps = 0; req && steps < 20; steps++) {
            if (req === code) {
                return true;
            }
            req = byCode[req] ? byCode[req].dataset.requires : '';
        }
        return false;
    }
    function tick(box) {                               // tick it and everything it needs
        box.checked = true;
        let req = box.dataset.requires;
        for (let steps = 0; req && byCode[req] && steps < 20; steps++) {
            byCode[req].checked = true;
            req = byCode[req].dataset.requires;
        }
    }
    function untick(box) {                             // untick it and everything that needs it
        box.checked = false;
        boxes.forEach((b) => { if (b.checked && needs(b, box.value)) { b.checked = false; } });
    }
    function changes() {
        return boxes.filter((b) => (b.checked ? '1' : '0') !== b.dataset.initial).length;
    }
    function refresh() {
        const ticked = boxes.filter((b) => b.checked).length;
        document.getElementById('ticked-count').textContent = ticked + ' of ' + boxes.length + ' ticked'
            + (ticked === 0 ? ' - with nothing ticked, this person cannot open any page' : '');
        boxes.forEach((b) => b.closest('.perm-item').classList.toggle('is-changed', (b.checked ? '1' : '0') !== b.dataset.initial));
        const n = changes();
        document.getElementById('perm-changes').textContent = n === 0 ? 'No changes yet' : n + ' change' + (n === 1 ? '' : 's') + ' not saved yet';
    }

    form.addEventListener('change', (ev) => {
        const box = ev.target.closest('input.perm');
        if (box) {
            if (box.checked) { tick(box); } else { untick(box); }
            refresh();
        }
    });
    form.addEventListener('click', (ev) => {
        const all = ev.target.closest('[data-all]');
        const group = ev.target.closest('[data-group-all]');
        if (all) {
            boxes.forEach((b) => { if (all.dataset.all === '1') { tick(b); } else { b.checked = false; } });
        } else if (group) {
            const inGroup = Array.from(group.closest('.perm-group').querySelectorAll('input.perm'));
            inGroup.forEach((b) => { if (group.dataset.groupAll === '1') { tick(b); } else { untick(b); } });
        } else {
            return;
        }
        refresh();
    });
    form.addEventListener('submit', () => {
        saving = true;
        document.getElementById('perm-save').classList.add('is-loading');
        setTimeout(() => { document.getElementById('perm-save').disabled = true; }, 0);
    });
    window.addEventListener('beforeunload', (ev) => {
        if (!saving && changes() > 0) {
            ev.preventDefault();
            ev.returnValue = '';
        }
    });
    refresh();
})();
</script>
</body>
</html>
