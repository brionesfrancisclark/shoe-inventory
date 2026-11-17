<?php
declare(strict_types=1);
require __DIR__ . '/layout.php';
require_once __DIR__ . '/inventory-lib.php';

/*
 * Inventory history: every stock change - who, when, what kind, before and after, and the note.
 * Needs the "View Inventory History" permission (the admin always has it). Read-only page.
 * Entries of deleted shoes / colorways stay readable because their names were copied into the history.
 */

$user = require_permission('inventory.history');

const HISTORY_TYPES = [
    'opening' => 'Opening stock', 'initial' => 'Starting stock', 'stock_in' => 'Stock in', 'stock_out' => 'Stock out',
    'adjustment' => 'Set count', 'size_removed' => 'Size removed', 'colorway_deleted' => 'Colorway deleted', 'shoe_deleted' => 'Shoe deleted',
];
const HISTORY_PER_PAGE = 50;

/* ---------- Filters (all optional, all checked) ---------- */
$validDate = static fn (string $d): bool => (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
$f = [
    'q'        => mb_substr(clean_text(form_str($_GET, 'q')), 0, 100),
    'type'     => form_str($_GET, 'type'),
    'who'      => form_str($_GET, 'who'),
    'from'     => form_str($_GET, 'from'),
    'to'       => form_str($_GET, 'to'),
    'colorway' => (int) filter_var($_GET['colorway'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 0]]),
];
$f['type'] = isset(HISTORY_TYPES[$f['type']]) ? $f['type'] : '';
$f['who']  = $f['who'] === 'system' || ctype_digit($f['who']) ? $f['who'] : '';
$f['from'] = $validDate($f['from']) ? $f['from'] : '';
$f['to']   = $validDate($f['to']) ? $f['to'] : '';
$page      = max(1, (int) filter_var($_GET['page'] ?? '', FILTER_VALIDATE_INT, ['options' => ['default' => 1]]));

$where  = [];
$params = [];
if ($f['q'] !== '') {
    $like = '%' . addcslashes($f['q'], '%_\\') . '%';
    $where[] = '(m.brand LIKE :q1 OR m.shoe_name LIKE :q2 OR m.colorway_name LIKE :q3 OR m.note LIKE :q4)';
    $params += [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like];
}
if ($f['type'] !== '') {
    $where[] = 'm.movement_type = :type';
    $params[':type'] = $f['type'];
}
if ($f['who'] === 'system') {
    $where[] = 'm.user_id IS NULL';
} elseif ($f['who'] !== '') {
    $where[] = 'm.user_id = :who';
    $params[':who'] = (int) $f['who'];
}
if ($f['from'] !== '') {
    $where[] = 'm.created_at >= :from';
    $params[':from'] = strtotime($f['from'] . ' 00:00:00');
}
if ($f['to'] !== '') {
    $where[] = 'm.created_at <= :to';
    $params[':to'] = strtotime($f['to'] . ' 23:59:59');
}
if ($f['colorway'] > 0) {
    $where[] = 'm.colorway_id = :cw';
    $params[':cw'] = $f['colorway'];
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$count = $pdo->prepare('SELECT COUNT(*) FROM stock_movements m' . $whereSql);
$count->execute($params);
$total = (int) $count->fetchColumn();
$pages = max(1, (int) ceil($total / HISTORY_PER_PAGE));
$page  = min($page, $pages);

$list = $pdo->prepare(
    'SELECT m.*, u.display_name FROM stock_movements m LEFT JOIN users u ON u.id = m.user_id' . $whereSql
    . ' ORDER BY m.id DESC LIMIT ' . HISTORY_PER_PAGE . ' OFFSET ' . (($page - 1) * HISTORY_PER_PAGE)
);
$list->execute($params);
$rows = $list->fetchAll();

$people = $pdo->query('SELECT id, display_name FROM users ORDER BY display_name')->fetchAll();
$query  = static fn (array $extra): string => http_build_query(array_filter(array_merge($f, $extra), static fn ($v): bool => $v !== '' && $v !== 0));
$filtered = array_filter($f, static fn ($v): bool => $v !== '' && $v !== 0) !== [];

render_header('Inventory history', 'history');
?>
<main id="main" class="container pb-5">
    <?php render_flash(); ?>
    <div class="mb-4">
        <h1 class="page-title mb-1">Inventory history</h1>
        <p class="page-sub mb-0">Every stock change: who made it, when, and the count before and after.</p>
    </div>

    <form method="get" action="inventory-history.php" class="card shadow-sm mb-3"><div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label for="h-q" class="form-label small mb-1">Shoe, colorway or note</label>
                <input type="search" id="h-q" name="q" class="form-control" maxlength="100" value="<?= e($f['q']) ?>" placeholder="e.g. Air Force 1, White, delivery">
            </div>
            <div class="col-6 col-md-2">
                <label for="h-type" class="form-label small mb-1">What</label>
                <select id="h-type" name="type" class="form-select">
                    <option value="">Everything</option>
                    <?php foreach (HISTORY_TYPES as $k => $label): ?><option value="<?= $k ?>"<?= $f['type'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="h-who" class="form-label small mb-1">Who</label>
                <select id="h-who" name="who" class="form-select">
                    <option value="">Anyone</option>
                    <?php foreach ($people as $p): ?><option value="<?= (int) $p['id'] ?>"<?= $f['who'] === (string) $p['id'] ? ' selected' : '' ?>><?= e($p['display_name']) ?></option><?php endforeach; ?>
                    <option value="system"<?= $f['who'] === 'system' ? ' selected' : '' ?>>System (setup)</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="h-from" class="form-label small mb-1">From</label>
                <input type="date" id="h-from" name="from" class="form-control" value="<?= e($f['from']) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label for="h-to" class="form-label small mb-1">To</label>
                <input type="date" id="h-to" name="to" class="form-control" value="<?= e($f['to']) ?>">
            </div>
            <?php if ($f['colorway'] > 0): ?><input type="hidden" name="colorway" value="<?= $f['colorway'] ?>"><?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-3">
            <button type="submit" class="btn btn-primary btn-sm">Show</button>
            <?php if ($filtered): ?><a href="inventory-history.php" class="btn btn-outline-secondary btn-sm">Clear filters</a><?php endif; ?>
            <span class="small text-muted align-self-center" id="history-count"><?= number_format($total) ?> entr<?= $total === 1 ? 'y' : 'ies' ?><?= $f['colorway'] > 0 ? ' for one colorway' : '' ?></span>
        </div>
    </div></form>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-grid align-middle mb-0" id="history-table">
                <thead class="table-light">
                    <tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">Shoe &middot; colorway</th><th scope="col">Size</th>
                        <th scope="col">What</th><th scope="col" class="text-center">Pairs</th><th scope="col">Note</th></tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" class="text-center text-muted py-5">No stock changes<?= $filtered ? ' match these filters' : ' yet' ?>.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r):
                    $change = (int) $r['quantity_change'];
                    $gone   = $r['shoe_id'] === null ? 'model deleted' : ($r['colorway_id'] === null ? 'colorway deleted' : ''); ?>
                    <tr>
                        <td class="small text-nowrap"><?= e(fmt_time((int) $r['created_at'])) ?></td>
                        <td class="small"><?= $r['user_id'] === null ? '<span class="text-muted">System</span>' : e((string) $r['display_name']) ?></td>
                        <td>
                            <span class="fw-semibold"><?= e($r['brand'] . ' ' . $r['shoe_name']) ?></span> &middot; <?= e($r['colorway_name']) ?>
                            <?php if ($gone !== ''): ?><span class="badge text-bg-secondary ms-1"><?= $gone ?></span><?php endif; ?>
                        </td>
                        <td class="text-nowrap">US <?= e((string) $r['size_number']) ?></td>
                        <td class="small text-nowrap"><?= e(HISTORY_TYPES[$r['movement_type']] ?? $r['movement_type']) ?></td>
                        <td class="text-center text-nowrap">
                            <?= (int) $r['quantity_before'] ?> &rarr; <strong><?= (int) $r['quantity_after'] ?></strong>
                            <span class="badge <?= $change > 0 ? 'text-bg-success' : ($change < 0 ? 'text-bg-danger' : 'text-bg-secondary') ?> ms-1"><?= $change > 0 ? '+' : '' ?><?= $change ?></span>
                        </td>
                        <td class="small text-muted"><?= e((string) $r['note']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
        <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span class="small text-muted">Page <?= $page ?> of <?= $pages ?></span>
            <nav aria-label="History pages"><ul class="pagination pagination-sm mb-0">
                <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="?<?= e($query(['page' => $page - 1])) ?>">Previous</a></li>
                <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>"><a class="page-link" href="?<?= e($query(['page' => $page + 1])) ?>">Next</a></li>
            </ul></nav>
        </div>
        <?php endif; ?>
    </div>
</main>
<?php render_scripts(); ?>
</body>
</html>
