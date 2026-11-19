<?php
declare(strict_types=1);
require __DIR__ . '/layout.php';
require_once __DIR__ . '/inventory-lib.php';

/*
 * Inventory: every shoe model once. Open a model to see each colorway with its own photo, sizes
 * and stock. With the right permissions: Stock in / Stock out / Set count per colorway (every
 * change goes into the stock history), edit a model's details, delete a colorway or a whole
 * model (the history is kept). Every action is checked on the server, not only hidden on the page.
 * Tabs above the filters show All Shoes, Women's Shoes or Men's Shoes (admin.php?main=women etc.),
 * plus "Unassigned" while some older models have no main category yet (admin.php?main=none).
 */

$user       = require_permission('inventory.view');
require_main_categories($pdo);
$canEdit    = can('shoes.edit');
$canDelShoe = can('shoes.delete');
$canDelCw   = can('colorways.delete');
$canAddCw   = can('colorways.add');
$canAdd     = can('shoes.add') || $canAddCw;
$showLow    = can('inventory.low_stock');
$showOut    = can('inventory.out_of_stock');
$canHistory = can('inventory.history');
$canViewShoe = can('shoes.view');
$canEditCw  = can('colorways.edit') || can('images.change');

/** The three kinds of stock change, the permission each needs, and how they are shown. */
const STOCK_MODES = [
    'stock_in'   => ['permission' => 'stock.in',     'button' => 'Stock in (+)',  'title' => 'Stock in'],
    'stock_out'  => ['permission' => 'stock.out',    'button' => 'Stock out (-)', 'title' => 'Stock out'],
    'adjustment' => ['permission' => 'stock.adjust', 'button' => 'Set count',     'title' => 'Stock count'],
];
$modes       = array_values(array_filter(array_keys(STOCK_MODES), static fn (string $m): bool => can(STOCK_MODES[$m]['permission'])));
$defaultMode = in_array('adjustment', $modes, true) ? 'adjustment' : ($modes[0] ?? '');

/** A positive whole number from the form, or 0. */
function int_field(string $key): int
{
    $value = filter_var($_POST[$key] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $value === false ? 0 : (int) $value;
}

/** Stock in / Stock out / Set count for the sizes of one colorway - all in one transaction. Returns [flash type, message]. */
function save_stock(PDO $pdo, int $shoeId, int $colorwayId, int $userId): array
{
    $mode = form_str($_POST, 'mode');
    if (!isset(STOCK_MODES[$mode])) {
        throw new UserError('Choose Stock in, Stock out or Set count.', 'invalid');
    }
    authorize(STOCK_MODES[$mode]['permission']);
    $model    = get_shoe_model($pdo, $shoeId);
    $colorway = get_colorway($pdo, $colorwayId, $shoeId);
    if ($model === null || $colorway === null) {
        throw new UserError('That colorway no longer exists.', 'missing');
    }
    $note = clean_text(form_str($_POST, 'note'));
    if ($note !== '' && ($problem = check_label($note, 'Note', 255)) !== null) {
        throw new UserError($problem, 'invalid');
    }
    $st = $pdo->prepare('SELECT id, size_number FROM shoe_sizes WHERE colorway_id = :c');
    $st->execute([':c' => $colorwayId]);
    $labels   = array_column($st->fetchAll(), 'size_number', 'id');
    $amounts  = is_array($_POST['qty'] ?? null) ? $_POST['qty'] : [];
    $expected = is_array($_POST['expected'] ?? null) ? $_POST['expected'] : [];

    $wanted = [];
    foreach ($amounts as $sizeId => $raw) {
        if (!is_string($raw) || trim($raw) === '') {
            continue;                                         // empty box = no change for that size
        }
        $amount = parse_qty($raw);
        if ($amount === null) {
            throw new UserError('Quantities must be whole numbers from 0 to 100,000 - no negative numbers or decimals.', 'invalid');
        }
        if (!isset($labels[$sizeId])) {
            throw new UserError('That size is no longer part of this colorway. Reload the page and try again.', 'missing');
        }
        if ($mode !== 'adjustment' && $amount === 0) {
            continue;
        }
        $seen = null;
        if ($mode === 'adjustment') {                         // the count the user was looking at
            $seen = parse_qty(is_string($expected[$sizeId] ?? null) ? $expected[$sizeId] : '');
            if ($seen === null) {
                throw new UserError('The page is out of date. Reload it and try again.', 'stale');
            }
        }
        $wanted[(int) $sizeId] = [$amount, $seen];
    }
    if (!$wanted) {
        return ['info', 'Nothing to save - no quantities were entered.'];
    }

    $pdo->beginTransaction();
    $parts = [];
    foreach ($wanted as $sizeId => [$amount, $seen]) {
        $r = change_stock($pdo, $sizeId, $colorwayId, $mode, $amount, $userId, $seen, $note !== '' ? $note : null);
        if ($r['changed']) {
            $diff    = $r['after'] - $r['before'];
            $parts[] = 'US ' . $labels[$sizeId] . ' ' . ($diff > 0 ? '+' : '') . $diff . ' (now ' . $r['after'] . ')';
        }
    }
    $pdo->commit();
    if (!$parts) {
        return ['info', 'Nothing changed - the counts were already right.'];
    }
    return ['success', STOCK_MODES[$mode]['title'] . ' saved for ' . $model['brand'] . ' ' . $model['name'] . ' - '
                     . $colorway['name'] . ': ' . implode(', ', $parts) . '.'];
}

function save_shoe_details(PDO $pdo, int $shoeId): string
{
    authorize('shoes.edit');
    if (get_shoe_model($pdo, $shoeId) === null) {
        throw new UserError('That shoe model no longer exists.', 'missing');
    }
    [$v, $errors] = validate_shoe_fields($_POST);
    if ($errors) {
        throw new UserError(implode(' ', $errors), 'invalid');
    }
    update_shoe_model($pdo, $shoeId, $v);
    return 'Saved the details of "' . $v['brand'] . ' ' . $v['name'] . '".';
}

function remove_colorway(PDO $pdo, int $shoeId, int $colorwayId, int $userId): string
{
    authorize('colorways.delete');
    $model    = get_shoe_model($pdo, $shoeId);
    $colorway = get_colorway($pdo, $colorwayId, $shoeId);
    if ($model === null || $colorway === null) {
        throw new UserError('That colorway no longer exists.', 'missing');
    }
    $pdo->beginTransaction();
    $image = delete_colorway($pdo, $colorwayId, $shoeId, $userId);
    $pdo->commit();
    delete_image_file($image);                                   // only after the delete is saved
    return 'Deleted the colorway "' . $colorway['name'] . '" of ' . $model['brand'] . ' ' . $model['name'] . '. Its stock history is kept.';
}

function remove_shoe(PDO $pdo, int $shoeId, int $userId): string
{
    authorize('shoes.delete');
    $model = get_shoe_model($pdo, $shoeId);
    if ($model === null) {
        throw new UserError('That shoe model no longer exists.', 'missing');
    }
    $pdo->beginTransaction();
    $images = delete_shoe_model($pdo, $shoeId, $userId);
    $pdo->commit();
    foreach ($images as $image) {
        delete_image_file($image);
    }
    return 'Deleted "' . $model['brand'] . ' ' . $model['name'] . '" with all its colorways. Its stock history is kept.';
}

/* ---------- POST actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        flash('danger', 'Security token invalid. Please try again.');
        redirect('admin.php');
    }
    try {
        match (form_str($_POST, 'action')) {
            'stock'           => flash(...save_stock($pdo, int_field('shoe_id'), int_field('colorway_id'), (int) $user['id'])),
            'update_shoe'     => flash('success', save_shoe_details($pdo, int_field('shoe_id'))),
            'delete_colorway' => flash('success', remove_colorway($pdo, int_field('shoe_id'), int_field('colorway_id'), (int) $user['id'])),
            'delete_shoe'     => flash('success', remove_shoe($pdo, int_field('shoe_id'), (int) $user['id'])),
            default           => flash('danger', 'Unknown action.'),
        };
    } catch (UserError $ex) {
        rollback_quietly($pdo);
        flash('danger', 'Nothing was saved. ' . $ex->getMessage());
    } catch (PDOException $ex) {
        rollback_quietly($pdo);
        error_log('admin.php: ' . $ex->getMessage());
        flash('danger', is_busy_error($ex) ? 'The database was busy, so nothing was saved. Please try again.'
                                           : 'Database error: nothing was saved.');
    }
    redirect('admin.php');
}

/* ---------- Load every model with its colorways and sizes ---------- */
$rows = $pdo->query(
    'SELECT s.id AS shoe_id, s.name, s.brand, s.main_category, s.category, s.price, s.image_url AS model_image,
            c.id AS colorway_id, c.name AS colorway, c.description, c.image_path, c.image_url,
            z.id AS size_id, z.size_number, z.quantity
       FROM shoes s
       LEFT JOIN shoe_colorways c ON c.shoe_id = s.id
       LEFT JOIN shoe_sizes z     ON z.colorway_id = c.id
      ORDER BY s.name, s.brand, s.id, c.sort_order, c.id, z.size_number'
)->fetchAll();

$shoes = [];
foreach ($rows as $r) {
    $id = (int) $r['shoe_id'];
    if (!isset($shoes[$id])) {
        $shoes[$id] = ['name' => $r['name'], 'brand' => $r['brand'], 'main' => (string) $r['main_category'],   // '' = not set yet
                       'category' => $r['category'], 'price' => (float) $r['price'],
                       'legacy_image' => $r['model_image'], 'colorways' => [], 'total' => 0, 'out' => 0, 'low' => 0];
    }
    if ($r['colorway_id'] === null) {
        continue;
    }
    $cw = (int) $r['colorway_id'];
    if (!isset($shoes[$id]['colorways'][$cw])) {
        $shoes[$id]['colorways'][$cw] = ['name' => $r['colorway'], 'description' => $r['description'],
                                         'image' => image_src($r['image_path'], $r['image_url']),
                                         'sizes' => [], 'total' => 0, 'out' => 0, 'low' => 0];
    }
    if ($r['size_id'] === null) {
        continue;
    }
    $qty    = (int) $r['quantity'];
    $status = stock_status($qty);
    $shoes[$id]['colorways'][$cw]['sizes'][] = ['id' => (int) $r['size_id'], 'size' => (string) $r['size_number'], 'qty' => $qty, 'status' => $status];
    $shoes[$id]['colorways'][$cw]['total'] += $qty;
    $shoes[$id]['total'] += $qty;
    if ($status !== 'ok') {
        $shoes[$id]['colorways'][$cw][$status]++;
        $shoes[$id][$status]++;
    }
}

$stats = ['models' => count($shoes), 'colorways' => 0, 'pairs' => 0, 'out' => 0, 'low' => 0];
$categories = [];
// Main category tabs: how many models each one has ('none' = no main category yet).
$mainCounts = ['' => count($shoes), 'none' => 0] + array_fill_keys(array_keys(MAIN_CATEGORIES), 0);
foreach ($shoes as $id => $shoe) {
    $mainCounts[$shoe['main'] === '' ? 'none' : $shoe['main']]++;
    // The model's picture: its first colorway photo; an old model-level image link only if no colorway has one.
    $image = null;
    foreach ($shoe['colorways'] as $c) {
        if ($c['image'] !== null) {
            $image = $c['image'];
            break;
        }
    }
    if ($image === null && $shoe['legacy_image'] !== null && image_url_problem($shoe['legacy_image']) === null) {
        $image = $shoe['legacy_image'];
    }
    $shoes[$id]['image'] = $image;
    $stats['colorways'] += count($shoe['colorways']);
    $stats['pairs']     += $shoe['total'];
    $stats['out']       += $shoe['out'];
    $stats['low']       += $shoe['low'];
    $categories[$shoe['category']] = true;
}
$categories = array_keys($categories);
sort($categories, SORT_NATURAL | SORT_FLAG_CASE);

$mainTabs = ['' => 'All Shoes'] + MAIN_CATEGORIES;
if ($mainCounts['none'] > 0) {
    $mainTabs['none'] = 'Unassigned';                  // only while some models have no main category
}
$mainFilter = is_string($_GET['main'] ?? null) && isset($mainTabs[$_GET['main']]) ? $_GET['main'] : '';

/** A photo box: the picture, or a neutral placeholder when there is none or it fails to load. */
function img_box(?string $src, string $alt, string $class): string
{
    if ($src === null) {
        return '<span class="img-box ' . $class . ' is-empty" title="No photo yet"><i class="bi bi-image" aria-hidden="true"></i></span>';
    }
    return '<span class="img-box ' . $class . '"><img src="' . e($src) . '" alt="' . e($alt) . '" loading="lazy" decoding="async"'
         . ' referrerpolicy="no-referrer" onerror="this.parentNode.classList.add(\'is-broken\');this.parentNode.title=\'This photo could not be loaded\';">'
         . '<i class="bi bi-image" aria-hidden="true"></i></span>';
}

$token = csrf_token();
$css = <<<'CSS'
<style>
    /* Inventory page only (the shared theme lives in layout.php) */
    .img-box { position: relative; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
               overflow: hidden; background: #f5f5f5; border-radius: 14px; }
    .img-box img { width: 100%; height: 100%; object-fit: contain; }
    .img-box > .bi { display: none; font-size: 1.35rem; color: #b5b5b7; }
    .img-box.is-empty > .bi, .img-box.is-broken > .bi { display: block; }
    .img-box.is-broken img { display: none; }
    .thumb-lg { width: 72px; height: 72px; }
    .thumb-md { width: 96px; height: 96px; border: 1px solid #ececec; }
    .thumb-sm { width: 26px; height: 26px; border-radius: 50%; }
    .thumb-sm > .bi { font-size: .75rem; }
    .cw-chip { display: inline-flex; align-items: center; gap: .35rem; max-width: 12rem; padding: .15rem .6rem .15rem .15rem;
               border: 1px solid #e5e5e5; border-radius: 50rem; background: #fff; font-size: .78rem; font-weight: 600; }
    .cw-chip .chip-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .cw-panel { background: #fff; border: 1px solid #ececec; border-radius: 16px; padding: 1rem 1.25rem; }
    .cw-panel + .cw-panel { margin-top: .75rem; }
    .cw-panel.is-target { box-shadow: 0 0 0 2px #111; }
    .model-actions .btn { white-space: nowrap; }
    @media (max-width: 575.98px) {                    /* phones: keep each model row within the screen */
        .thumb-lg { width: 52px; height: 52px; border-radius: 12px; }
        .thumb-md { width: 72px; height: 72px; }
        .cw-panel { padding: .85rem; }
        #stock-table .detail-cell { padding: .5rem; }
    }

    /* Size / stock tiles */
    .size-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(64px, 1fr)); gap: .4rem; }
    .size-cell { border: 1px solid #e5e5e5; border-radius: 10px; text-align: center; padding: .35rem .2rem .3rem; background: #fff; }
    .size-cell .sz { font-size: .68rem; font-weight: 600; letter-spacing: .02em; color: #707072; display: block; }
    .size-cell .qt { font-weight: 800; font-size: 1.1rem; line-height: 1.25; display: block; }
    .size-cell .flag { display: block; font-size: .58rem; font-weight: 800; letter-spacing: .08em; min-height: .9rem; }
    .size-cell.out { background: #fdecea; border-color: #f3b7ac; }
    .size-cell.out .sz, .size-cell.out .qt, .size-cell.out .flag { color: #c2371b; }
    .size-cell.low { background: #fff6dc; border-color: #f2d27a; }
    .size-cell.low .flag { color: #8a6500; }

    /* Summary cards */
    .stat-card { text-align: left; width: 100%; transition: transform .15s ease, box-shadow .15s ease; }
    .stat-card .small { font-size: .7rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #707072 !important; }
    .stat-card .num { font-size: 2.25rem; font-weight: 800; line-height: 1.1; letter-spacing: -.03em; }
    button.stat-card { cursor: pointer; }
    button.stat-card:hover { transform: translateY(-2px); box-shadow: 0 0 0 2px #111, 0 .5rem 1.25rem rgba(0,0,0,.08) !important; }

    /* Search box icon */
    #filter-q {
        padding-left: 2.4rem; background-repeat: no-repeat; background-position: .85rem center; background-size: 1rem;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%23707072'%3E%3Cpath d='M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85zm-5.242.656a5 5 0 1 1 0-10 5 5 0 0 1 0 10z'/%3E%3C/svg%3E");
    }

    /* A model with no main category yet (Women's Shoes / Men's Shoes) */
    .main-unset { color: #8a6500; font-weight: 600; }

    /* Editing */
    .qty-input.is-changed { border-color: #f0b400; background: #fffbea; box-shadow: 0 0 0 .15rem rgba(240,180,0,.25); }
    .stock-hint { font-size: .7rem; font-weight: 600; color: #8a6500; min-height: 1rem; }
    tbody.shoe-group > tr:first-child > td { border-top: 1px solid #f0f0f0; }
</style>
CSS;

render_header('Inventory', 'inventory', true, $css);
?>
<main id="main" class="container pb-5">
    <?php render_flash(); ?>

    <?php if ($canEdit): ?>
        <datalist id="cat-list"><?php foreach ($categories as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
    <?php endif; ?>

    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <h1 class="page-title mb-1">Current Stock</h1>
            <p class="page-sub mb-0">Every shoe model once. Open a model to see each colorway with its photo, sizes and stock.</p>
        </div>
        <?php if ($canAdd): ?>
            <a href="add-shoe.php" class="btn btn-success">+ Add New Shoe</a>
        <?php endif; ?>
    </div>

    <!-- Summary cards (the red / amber ones are shortcuts to the stock filter) -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-xl">
            <div class="card shadow-sm stat-card h-100"><div class="card-body">
                <div class="small text-muted">Shoe models</div><div class="num" data-stat="models"><?= $stats['models'] ?></div>
            </div></div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="card shadow-sm stat-card h-100"><div class="card-body">
                <div class="small text-muted">Colorways</div><div class="num" data-stat="colorways"><?= $stats['colorways'] ?></div>
            </div></div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="card shadow-sm stat-card h-100"><div class="card-body">
                <div class="small text-muted">Pairs in stock</div><div class="num" data-stat="pairs"><?= number_format($stats['pairs']) ?></div>
            </div></div>
        </div>
        <?php if ($showOut): ?>
        <div class="col-6 col-md-4 col-xl">
            <button type="button" class="card shadow-sm stat-card h-100 p-0 border-0 bg-white" data-quick-filter="out" aria-label="Show shoes with out-of-stock sizes">
                <span class="card-body d-block">
                    <span class="small text-muted d-block">Sizes out of stock</span>
                    <span class="num text-danger" data-stat="out"><?= $stats['out'] ?></span>
                </span>
            </button>
        </div>
        <?php endif; ?>
        <?php if ($showLow): ?>
        <div class="col-6 col-md-4 col-xl">
            <button type="button" class="card shadow-sm stat-card h-100 p-0 border-0 bg-white" data-quick-filter="low" aria-label="Show shoes with low-stock sizes">
                <span class="card-body d-block">
                    <span class="small text-muted d-block">Low-stock sizes (1&ndash;<?= LOW_STOCK_LIMIT ?>)</span>
                    <span class="num" style="color:#b58100" data-stat="low"><?= $stats['low'] ?></span>
                </span>
            </button>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$shoes): ?>
        <div class="card shadow-sm"><div class="card-body text-center py-5">
            <p class="text-muted mb-3">No shoes in the inventory yet.</p>
            <?php if ($canAdd): ?><a href="add-shoe.php" class="btn btn-success">Add your first shoe</a>
            <?php else: ?><p class="small text-muted mb-0">Ask the administrator to add shoes.</p><?php endif; ?>
        </div></div>
    <?php else: ?>

    <?php if ($canEdit && $mainCounts['none'] > 0): $n = $mainCounts['none']; ?>
        <div class="alert alert-warning d-flex flex-column flex-md-row align-items-md-center gap-2 gap-md-3" role="status">
            <span class="flex-grow-1"><?= $n ?> shoe model<?= $n === 1 ? ' has' : 's have' ?> no main category yet, so <?= $n === 1 ? 'it is' : 'they are' ?> only listed under All Shoes.
                Open <?= $n === 1 ? 'it' : 'each one' ?> and use <strong>Edit model details</strong> to choose Women's Shoes or Men's Shoes.</span>
            <a class="btn btn-outline-secondary btn-sm flex-shrink-0 align-self-start align-self-md-center" href="admin.php?main=none" data-main-link="none">Show <?= $n === 1 ? 'it' : 'them' ?></a>
        </div>
    <?php endif; ?>

    <!-- Main categories -->
    <nav class="cat-nav mb-3" id="main-nav" aria-label="Main categories">
        <?php foreach ($mainTabs as $code => $tab): ?>
            <a class="cat-pill<?= $code === 'none' ? ' is-unset' : '' ?>" href="admin.php<?= $code !== '' ? '?main=' . e($code) : '' ?>"
               data-main="<?= e($code) ?>"<?= $code === $mainFilter ? ' aria-current="page"' : '' ?>>
                <?= e($tab) ?><span class="cat-count"><span data-count><?= $mainCounts[$code] ?></span><span class="visually-hidden"> models</span></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- Filters -->
    <div class="card shadow-sm mb-3"><div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label for="filter-q" class="form-label small mb-1">Search <span class="text-muted">(press / to focus)</span></label>
                <input type="search" id="filter-q" class="form-control" placeholder="Name, brand, category or colorway" autocomplete="off">
            </div>
            <div class="col-6 col-md-2">
                <label for="filter-cat" class="form-label small mb-1">Category</label>
                <select id="filter-cat" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($categories as $c): ?><option value="<?= e($c) ?>"><?= e($c) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php if ($showOut || $showLow): ?>
            <div class="col-6 col-md-2">
                <label for="filter-status" class="form-label small mb-1">Stock</label>
                <select id="filter-status" class="form-select">
                    <option value="">All</option>
                    <?php if ($showOut): ?><option value="out">Has out-of-stock sizes</option><?php endif; ?>
                    <?php if ($showLow): ?><option value="low">Has low-stock sizes</option><?php endif; ?>
                    <?php if ($showOut && $showLow): ?><option value="ok">Fully stocked</option><?php endif; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-6 col-md-2">
                <label for="sort" class="form-label small mb-1">Sort by</label>
                <select id="sort" class="form-select">
                    <option value="name">Name (A-Z)</option>
                    <?php if ($showOut): ?><option value="out-desc">Most out-of-stock sizes</option><?php endif; ?>
                    <option value="stock-asc">Lowest total stock</option>
                    <option value="price-asc">Price (low to high)</option>
                    <option value="price-desc">Price (high to low)</option>
                </select>
            </div>
            <div class="col-6 col-md-2 d-grid">
                <button type="button" id="clear-filters" class="btn btn-outline-secondary">Clear filters</button>
            </div>
        </div>
        <div class="small text-muted mt-2" id="result-count" aria-live="polite"></div>
    </div></div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-grid align-middle mb-0" id="stock-table">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Shoe</th>
                        <th scope="col" class="d-none d-md-table-cell">Brand</th>
                        <th scope="col" class="text-end d-none d-sm-table-cell">Price</th>
                        <th scope="col" class="d-none d-lg-table-cell">Colorways</th>
                        <th scope="col" class="text-center">Total</th>
                        <th scope="col"><span class="visually-hidden">Details</span></th>
                    </tr>
                </thead>
                <tbody id="no-results" hidden>
                    <tr><td colspan="6" class="text-center text-muted py-5">No shoes match your filters.</td></tr>
                </tbody>

                <?php foreach ($shoes as $shoeId => $shoe):
                    $colorwayCount = count($shoe['colorways']);
                    $search = $shoe['name'] . ' ' . $shoe['brand'] . ' ' . $shoe['category'] . ' ' . implode(' ', array_column($shoe['colorways'], 'name'));
                    $model  = $shoe['brand'] . ' ' . $shoe['name']; ?>
                <tbody class="shoe-group"
                       data-search="<?= e(mb_strtolower($search)) ?>"
                       data-main="<?= e($shoe['main']) ?>"
                       data-category="<?= e($shoe['category']) ?>"
                       data-name="<?= e(mb_strtolower($shoe['name'] . ' ' . $shoe['brand'])) ?>"
                       data-price="<?= $shoe['price'] ?>"
                       data-total="<?= $shoe['total'] ?>"
                       data-out="<?= $shoe['out'] ?>"
                       data-low="<?= $shoe['low'] ?>">
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                <?= img_box($shoe['image'], '', 'thumb-lg') ?>
                                <div class="min-w-0">
                                    <div class="fw-semibold"><?= e($shoe['name']) ?></div>
                                    <div class="small text-muted">
                                        <?= $shoe['main'] !== '' ? e(main_category_label($shoe['main'])) : '<span class="main-unset">No main category</span>' ?>
                                        &middot; <?= e($shoe['category']) ?>
                                    </div>
                                    <div class="small text-muted d-md-none"><?= e($shoe['brand']) ?> &middot; &#8369;<?= number_format($shoe['price'], 2) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="d-none d-md-table-cell"><?= e($shoe['brand']) ?></td>
                        <td class="text-end text-nowrap d-none d-sm-table-cell">&#8369;<?= number_format($shoe['price'], 2) ?></td>
                        <td class="d-none d-lg-table-cell">
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach (array_slice($shoe['colorways'], 0, 4, true) as $c): ?>
                                    <span class="cw-chip" title="<?= e($c['name']) ?>: <?= $c['total'] ?> pair<?= $c['total'] === 1 ? '' : 's' ?>">
                                        <?= img_box($c['image'], '', 'thumb-sm') ?><span class="chip-name"><?= e($c['name']) ?></span>
                                        <span class="text-muted fw-normal"><?= $c['total'] ?></span>
                                    </span>
                                <?php endforeach; ?>
                                <?php if ($colorwayCount > 4): ?><span class="cw-chip ps-2">+<?= $colorwayCount - 4 ?> more</span><?php endif; ?>
                                <?php if ($colorwayCount === 0): ?><span class="small text-muted">No colorways</span><?php endif; ?>
                            </div>
                            <?php if (($showOut && $shoe['out']) || ($showLow && $shoe['low'])): ?>
                                <div class="mt-1">
                                    <?php if ($showOut && $shoe['out']): ?><span class="badge text-bg-danger"><?= $shoe['out'] ?> size<?= $shoe['out'] === 1 ? '' : 's' ?> out</span><?php endif; ?>
                                    <?php if ($showLow && $shoe['low']): ?><span class="badge text-bg-secondary" style="background:#fff6dc !important;color:#8a6500 !important"><?= $shoe['low'] ?> low</span><?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge rounded-pill fs-6 <?= $shoe['total'] === 0 && $showOut ? 'text-bg-danger' : 'text-bg-light border text-dark' ?>"
                                  title="<?= $shoe['total'] ?> pair<?= $shoe['total'] === 1 ? '' : 's' ?> in total"><?= $shoe['total'] ?></span>
                        </td>
                        <td class="text-end">
                            <button class="btn btn-outline-secondary btn-sm text-nowrap collapsed" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#shoe-<?= $shoeId ?>" aria-expanded="false" aria-controls="shoe-<?= $shoeId ?>"
                                    aria-label="Show the <?= $colorwayCount ?> colorway<?= $colorwayCount === 1 ? '' : 's' ?> of <?= e($model) ?>">
                                <?= $colorwayCount ?><span class="d-none d-sm-inline"> colorway<?= $colorwayCount === 1 ? '' : 's' ?></span>
                                <i class="bi bi-chevron-down" aria-hidden="true"></i>
                            </button>
                        </td>
                    </tr>

                    <tr class="collapse model-detail" id="shoe-<?= $shoeId ?>">
                        <td colspan="6" class="bg-light detail-cell">
                            <div class="p-1 p-md-2">
                                <?php if ($canViewShoe || $canAddCw || $canEdit || $canDelShoe): ?>
                                <div class="d-flex flex-wrap gap-2 mb-3 model-actions">
                                    <?php if ($canViewShoe): ?>
                                        <a class="btn btn-outline-secondary btn-sm" href="shoe.php?id=<?= $shoeId ?>"><i class="bi bi-eye" aria-hidden="true"></i> View details</a>
                                    <?php endif; ?>
                                    <?php if ($canAddCw): ?>
                                        <a class="btn btn-outline-secondary btn-sm" href="add-shoe.php?shoe=<?= $shoeId ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add colorway</a>
                                    <?php endif; ?>
                                    <?php if ($canEdit): ?>
                                        <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#details-<?= $shoeId ?>"
                                                aria-expanded="false" aria-controls="details-<?= $shoeId ?>">Edit model details</button>
                                    <?php endif; ?>
                                    <?php if ($canDelShoe): ?>
                                        <button class="btn btn-outline-danger btn-sm btn-delete-shoe" type="button" data-id="<?= $shoeId ?>" data-name="<?= e($model) ?>"
                                                data-detail="<?= $colorwayCount ?> colorway<?= $colorwayCount === 1 ? '' : 's' ?>, <?= $shoe['total'] ?> pair<?= $shoe['total'] === 1 ? '' : 's' ?>">Delete shoe</button>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>

                                <?php if ($canEdit): $priceStr = number_format($shoe['price'], 2, '.', ''); ?>
                                <form method="post" action="admin.php" class="collapse details-form border rounded-3 bg-white p-3 mb-3" id="details-<?= $shoeId ?>">
                                    <input type="hidden" name="action" value="update_shoe">
                                    <input type="hidden" name="csrf" value="<?= e($token) ?>">
                                    <input type="hidden" name="shoe_id" value="<?= $shoeId ?>">
                                    <p class="small fw-semibold mb-2">Model details (shared by all colorways)</p>
                                    <div class="row g-2">
                                        <div class="col-12 col-md-6 col-xl-3">
                                            <label class="form-label small mb-0" for="n<?= $shoeId ?>">Model name</label>
                                            <input type="text" class="form-control form-control-sm" id="n<?= $shoeId ?>" name="name" maxlength="150" required value="<?= e($shoe['name']) ?>">
                                        </div>
                                        <div class="col-6 col-md-6 col-xl-2">
                                            <label class="form-label small mb-0" for="b<?= $shoeId ?>">Brand</label>
                                            <input type="text" class="form-control form-control-sm" id="b<?= $shoeId ?>" name="brand" maxlength="100" required value="<?= e($shoe['brand']) ?>">
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-2">
                                            <label class="form-label small mb-0" for="m<?= $shoeId ?>">Main category</label>
                                            <select class="form-select form-select-sm" id="m<?= $shoeId ?>" name="main_category" required>
                                                <?php if ($shoe['main'] === ''): ?><option value="" selected disabled>Choose...</option><?php endif; ?>
                                                <?php foreach (MAIN_CATEGORIES as $code => $mainName): ?>
                                                    <option value="<?= e($code) ?>"<?= $shoe['main'] === $code ? ' selected' : '' ?>><?= e($mainName) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-3">
                                            <label class="form-label small mb-0" for="c<?= $shoeId ?>">Category</label>
                                            <input type="text" class="form-control form-control-sm" id="c<?= $shoeId ?>" name="category" maxlength="100" list="cat-list" required value="<?= e($shoe['category']) ?>">
                                        </div>
                                        <div class="col-6 col-md-4 col-xl-2">
                                            <label class="form-label small mb-0" for="p<?= $shoeId ?>">Price (&#8369;)</label>
                                            <input type="number" class="form-control form-control-sm" id="p<?= $shoeId ?>" name="price" min="0" max="9999999.99" step="0.01" inputmode="decimal" required value="<?= $priceStr ?>">
                                        </div>
                                    </div>
                                    <div class="d-flex gap-2 mt-3">
                                        <button type="submit" class="btn btn-primary btn-sm">Save details</button>
                                        <button type="button" class="btn btn-link btn-sm" data-bs-toggle="collapse" data-bs-target="#details-<?= $shoeId ?>">Cancel</button>
                                    </div>
                                </form>
                                <?php endif; ?>

                                <?php if ($colorwayCount === 0): ?>
                                    <p class="text-muted small mb-0">This model has no colorways yet.</p>
                                <?php endif; ?>

                                <?php foreach ($shoe['colorways'] as $cwId => $c): ?>
                                <div class="cw-panel" id="colorway-<?= $cwId ?>">
                                    <div class="d-flex flex-wrap gap-3 align-items-start">
                                        <?= img_box($c['image'], $c['name'], 'thumb-md') ?>
                                        <div class="flex-grow-1" style="min-width: 0;">
                                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                                                <div>
                                                    <div class="fw-bold"><?= e($c['name']) ?></div>
                                                    <?php if ($c['description'] !== null && $c['description'] !== ''): ?>
                                                        <div class="small text-muted"><?= e($c['description']) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="d-flex flex-wrap align-items-center gap-1">
                                                    <?php if ($showOut && $c['out']): ?><span class="badge text-bg-danger"><?= $c['out'] ?> out</span><?php endif; ?>
                                                    <?php if ($showLow && $c['low']): ?><span class="badge" style="background:#fff6dc;color:#8a6500"><?= $c['low'] ?> low</span><?php endif; ?>
                                                    <span class="badge rounded-pill text-bg-light border text-dark" title="Pairs of this colorway"><?= $c['total'] ?> pair<?= $c['total'] === 1 ? '' : 's' ?></span>
                                                </div>
                                            </div>

                                            <?php if ($c['sizes']): ?>
                                                <div class="size-grid">
                                                    <?php foreach ($c['sizes'] as $s):
                                                        $flag = ($s['status'] === 'out' && $showOut) ? 'OUT' : (($s['status'] === 'low' && $showLow) ? 'LOW' : '');
                                                        $cls  = $flag === 'OUT' ? 'out' : ($flag === 'LOW' ? 'low' : '');
                                                        $tip  = $c['name'] . ', US ' . $s['size'] . ': ' . $s['qty'] . ' pair' . ($s['qty'] === 1 ? '' : 's')
                                                              . ($flag === 'OUT' ? ' - out of stock' : ($flag === 'LOW' ? ' - low stock' : '')); ?>
                                                        <div class="size-cell <?= $cls ?>" title="<?= e($tip) ?>">
                                                            <span class="sz">US <?= e($s['size']) ?></span>
                                                            <span class="qt"><?= $s['qty'] ?></span>
                                                            <span class="flag"><?= $flag ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <p class="small text-muted mb-0">No sizes yet.</p>
                                            <?php endif; ?>

                                            <?php if (($modes && $c['sizes']) || $canEditCw || $canDelCw || $canHistory): ?>
                                            <div class="d-flex flex-wrap gap-2 mt-3">
                                                <?php if ($modes && $c['sizes']): ?>
                                                    <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#stock-<?= $cwId ?>"
                                                            aria-expanded="false" aria-controls="stock-<?= $cwId ?>">Edit stock</button>
                                                <?php endif; ?>
                                                <?php if ($canEditCw): ?>
                                                    <a class="btn btn-outline-secondary btn-sm btn-edit-colorway" href="colorway.php?id=<?= $cwId ?>">Edit colorway</a>
                                                <?php endif; ?>
                                                <?php if ($canDelCw): ?>
                                                    <button class="btn btn-outline-danger btn-sm btn-delete-colorway" type="button"
                                                            data-shoe="<?= $shoeId ?>" data-id="<?= $cwId ?>" data-name="<?= e($c['name']) ?>" data-model="<?= e($model) ?>"
                                                            data-pairs="<?= $c['total'] ?>"<?= $colorwayCount === 1 ? ' disabled title="This is the only colorway - delete the whole shoe model instead"' : '' ?>>Delete colorway</button>
                                                <?php endif; ?>
                                                <?php if ($canHistory): ?>
                                                    <a class="btn btn-link btn-sm" href="inventory-history.php?colorway=<?= $cwId ?>">History</a>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if ($modes && $c['sizes']): ?>
                                    <form method="post" action="admin.php" class="collapse stock-form mt-3 pt-3 border-top" id="stock-<?= $cwId ?>">
                                        <input type="hidden" name="action" value="stock">
                                        <input type="hidden" name="csrf" value="<?= e($token) ?>">
                                        <input type="hidden" name="shoe_id" value="<?= $shoeId ?>">
                                        <input type="hidden" name="colorway_id" value="<?= $cwId ?>">
                                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                            <span class="small fw-semibold">What happened?</span>
                                            <div class="btn-group btn-group-sm" role="group" aria-label="Kind of stock change">
                                                <?php foreach ($modes as $m): ?>
                                                    <input type="radio" class="btn-check mode-pick" name="mode" id="m<?= $cwId ?>-<?= $m ?>" value="<?= $m ?>"<?= $m === $defaultMode ? ' checked' : '' ?>>
                                                    <label class="btn btn-outline-secondary" for="m<?= $cwId ?>-<?= $m ?>"><?= STOCK_MODES[$m]['button'] ?></label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <p class="small text-muted mb-3 mode-help"></p>
                                        <div class="row g-2">
                                            <?php foreach ($c['sizes'] as $s): $lbl = 'US ' . $s['size']; ?>
                                                <div class="col-6 col-sm-4 col-md-3 col-xl-2 stock-cell">
                                                    <label class="form-label small mb-0" for="q<?= $s['id'] ?>"><?= e($lbl) ?> <span class="text-muted fw-normal">&middot; now <?= $s['qty'] ?></span></label>
                                                    <div class="input-group input-group-sm">
                                                        <button type="button" class="btn btn-outline-secondary step-btn" data-delta="-1" aria-label="Less for <?= e($lbl) ?>">&minus;</button>
                                                        <input type="number" class="form-control text-center qty-input" id="q<?= $s['id'] ?>" name="qty[<?= $s['id'] ?>]"
                                                               min="0" max="<?= MAX_QTY ?>" step="1" inputmode="numeric" data-current="<?= $s['qty'] ?>">
                                                        <button type="button" class="btn btn-outline-secondary step-btn" data-delta="1" aria-label="More for <?= e($lbl) ?>">+</button>
                                                    </div>
                                                    <input type="hidden" name="expected[<?= $s['id'] ?>]" value="<?= $s['qty'] ?>">
                                                    <div class="stock-hint" aria-live="polite"></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <label class="visually-hidden" for="note<?= $cwId ?>">Note</label>
                                        <input type="text" class="form-control form-control-sm mt-3" id="note<?= $cwId ?>" name="note" maxlength="255"
                                               placeholder="Note (optional) - e.g. delivery #1042, damaged pair, monthly count">
                                        <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                                            <button type="submit" class="btn btn-primary btn-sm" data-save disabled>
                                                Save <span class="spinner-border spinner-border-sm btn-spinner ms-1" aria-hidden="true"></span>
                                            </button>
                                            <button type="button" class="btn btn-link btn-sm" data-reset>Reset</button>
                                            <span class="small text-muted" data-changed-label aria-live="polite">No changes yet</span>
                                        </div>
                                    </form>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                </tbody>
                <?php endforeach; ?>
            </table>
        </div>

        <!-- Pagination (built by the script below; 10 shoe models per page) -->
        <div class="card-footer bg-white" id="pager-bar">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div class="small text-muted" id="page-info" aria-live="polite"></div>
                <nav aria-label="Inventory pages">
                    <ul class="pagination pagination-sm flex-wrap mb-0" id="pager"></ul>
                </nav>
            </div>
        </div>
    </div>
    <?php endif; ?>
</main>

<?php if ($canDelShoe): ?>
<!-- Delete a whole shoe model -->
<div class="modal fade" id="delete-shoe-modal" tabindex="-1" aria-labelledby="delete-shoe-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" action="admin.php" class="modal-content">
            <input type="hidden" name="action" value="delete_shoe">
            <input type="hidden" name="csrf" value="<?= e($token) ?>">
            <input type="hidden" name="shoe_id" value="">
            <div class="modal-header">
                <h2 class="modal-title h5" id="delete-shoe-title">Delete this shoe model?</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-1"><strong data-name></strong> (<span data-detail></span>) will be removed from the inventory, with all its colorways and stock.</p>
                <p class="small text-muted mb-0">The stock history keeps a record of it. This can't be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete shoe</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canDelCw): ?>
<!-- Delete one colorway -->
<div class="modal fade" id="delete-colorway-modal" tabindex="-1" aria-labelledby="delete-colorway-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" action="admin.php" class="modal-content">
            <input type="hidden" name="action" value="delete_colorway">
            <input type="hidden" name="csrf" value="<?= e($token) ?>">
            <input type="hidden" name="shoe_id" value="">
            <input type="hidden" name="colorway_id" value="">
            <div class="modal-header">
                <h2 class="modal-title h5" id="delete-colorway-title">Delete this colorway?</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-1">The colorway <strong data-name></strong> of <strong data-model></strong> and its <span data-pairs></span> will be removed.
                    The other colorways of this model are not affected.</p>
                <p class="small text-muted mb-0">The stock history keeps a record of it. This can't be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete colorway</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php render_scripts(); ?>
<script>
(function () {
    'use strict';
    const VIEW_KEY = 'inventory-view';
    const table = document.getElementById('stock-table');
    const q = document.getElementById('filter-q');
    const cat = document.getElementById('filter-cat');
    const status = document.getElementById('filter-status');
    const sortSel = document.getElementById('sort');
    const mainNav = document.getElementById('main-nav');
    const pills = mainNav ? Array.from(mainNav.querySelectorAll('.cat-pill')) : [];
    const pickedPill = pills.find((p) => p.getAttribute('aria-current') === 'page');
    let main = pickedPill ? pickedPill.dataset.main : '';     // '' = All Shoes, 'women', 'men', 'none' = Unassigned
    let page = 1;

    /* After saving, the page reloads: put the user back on the same filters, page and opened models (one time only). */
    function saveView() {
        try {
            sessionStorage.setItem(VIEW_KEY, JSON.stringify({
                q: q ? q.value : '', cat: cat ? cat.value : '', status: status ? status.value : '', sort: sortSel ? sortSel.value : 'name',
                main: main, page: page, open: Array.from(document.querySelectorAll('tr.model-detail.show')).map((el) => el.id), scroll: window.scrollY
            }));
        } catch (err) { /* storage unavailable: ignore */ }
    }

    /* Main category tab: mark it and show it in the address (so a reload keeps it). Unknown -> All Shoes. */
    function setMain(code) {
        const pill = pills.find((p) => p.dataset.main === code);
        main = pill ? code : '';
        pills.forEach((p) => {
            if (p.dataset.main === main) { p.setAttribute('aria-current', 'page'); } else { p.removeAttribute('aria-current'); }
        });
        try {
            const url = new URL(window.location.href);
            if (main) { url.searchParams.set('main', main); } else { url.searchParams.delete('main'); }
            history.replaceState(null, '', url.pathname + url.search + url.hash);
        } catch (err) { /* old browser: the links still work */ }
    }
    function inMain(g) {
        return main === '' || (main === 'none' ? g.dataset.main === '' : g.dataset.main === main);
    }
    let saved = null;
    try {
        saved = JSON.parse(sessionStorage.getItem(VIEW_KEY) || 'null');
        sessionStorage.removeItem(VIEW_KEY);
    } catch (err) { saved = null; }

    if (table) {
        const PER_PAGE = 10;
        const groups = Array.from(table.querySelectorAll('tbody.shoe-group'));
        const noRes = document.getElementById('no-results');
        const count = document.getElementById('result-count');
        const pagerBar = document.getElementById('pager-bar');
        const pager = document.getElementById('pager');
        const pageInfo = document.getElementById('page-info');
        const sorters = {
            'name': (a, b) => a.dataset.name.localeCompare(b.dataset.name),
            'out-desc': (a, b) => (+b.dataset.out - +a.dataset.out) || a.dataset.name.localeCompare(b.dataset.name),
            'stock-asc': (a, b) => (+a.dataset.total - +b.dataset.total) || a.dataset.name.localeCompare(b.dataset.name),
            'price-asc': (a, b) => +a.dataset.price - +b.dataset.price,
            'price-desc': (a, b) => +b.dataset.price - +a.dataset.price
        };

        if (saved) {
            q.value = saved.q || '';
            cat.value = saved.cat || '';
            if (cat.selectedIndex < 0) { cat.value = ''; }
            if (status) {
                status.value = saved.status || '';
                if (status.selectedIndex < 0) { status.value = ''; }
            }
            sortSel.value = saved.sort || 'name';
            if (sortSel.selectedIndex < 0 || !sorters[sortSel.value]) { sortSel.value = 'name'; }
            setMain(typeof saved.main === 'string' ? saved.main : '');
            page = parseInt(saved.page, 10) || 1;
            (saved.open || []).forEach((id) => {
                const row = document.getElementById(id);
                const toggle = document.querySelector('[data-bs-target="#' + id + '"]');
                if (row && row.classList.contains('model-detail')) {
                    row.classList.add('show');
                    if (toggle) {
                        toggle.classList.remove('collapsed');
                        toggle.setAttribute('aria-expanded', 'true');
                    }
                }
            });
        }

        /* Page numbers with "..." for long lists, e.g. 1 ... 4 5 6 ... 12 */
        function pageNumbers(current, total) {
            const out = [];
            for (let p = 1; p <= total; p++) {
                if (total <= 7 || p === 1 || p === total || Math.abs(p - current) <= 1) {
                    out.push(p);
                } else if (out[out.length - 1] !== '…') {
                    out.push('…');
                }
            }
            return out;
        }
        function pageItem(label, target, disabled, active, aria) {
            const li = document.createElement('li');
            li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'page-link';
            b.textContent = label;
            b.setAttribute('aria-label', aria);
            b.dataset.page = target;
            if (disabled) { b.disabled = true; }
            if (active) { b.setAttribute('aria-current', 'page'); }
            li.appendChild(b);
            return li;
        }
        function renderPager(pages) {
            pager.textContent = '';
            pager.appendChild(pageItem('Previous', page - 1, page <= 1, false, 'Previous page'));
            pageNumbers(page, pages).forEach((p) => {
                if (p === '…') {
                    const li = document.createElement('li');
                    li.className = 'page-item disabled';
                    const s = document.createElement('span');
                    s.className = 'page-link';
                    s.textContent = '…';
                    li.appendChild(s);
                    pager.appendChild(li);
                } else {
                    pager.appendChild(pageItem(String(p), p, false, p === page, 'Page ' + p));
                }
            });
            pager.appendChild(pageItem('Next', page + 1, page >= pages, false, 'Next page'));
        }
        function apply() {
            const term = q.value.trim().toLowerCase();
            const want = status ? status.value : '';
            const sorted = groups.slice().sort(sorters[sortSel.value] || sorters.name);
            sorted.forEach((g) => table.appendChild(g));
            // The other filters look at every model; each tab's count shows how many of those matches it holds.
            const counts = { '': 0 };
            pills.forEach((p) => { counts[p.dataset.main] = 0; });
            const matched = sorted.filter((g) => {
                const d = g.dataset;
                let ok = !term || d.search.indexOf(term) !== -1;
                if (ok && cat.value) { ok = d.category === cat.value; }
                if (ok && want === 'out') { ok = +d.out > 0; }
                if (ok && want === 'low') { ok = +d.low > 0; }
                if (ok && want === 'ok') { ok = +d.out === 0 && +d.low === 0; }
                if (ok) {
                    const key = d.main === '' ? 'none' : d.main;
                    counts['']++;
                    if (Object.prototype.hasOwnProperty.call(counts, key)) { counts[key]++; }
                }
                return ok && inMain(g);
            });
            pills.forEach((p) => { p.querySelector('[data-count]').textContent = counts[p.dataset.main]; });
            const pages = Math.max(1, Math.ceil(matched.length / PER_PAGE));
            page = Math.min(Math.max(page, 1), pages);
            const start = (page - 1) * PER_PAGE;
            const visible = matched.slice(start, start + PER_PAGE);
            groups.forEach((g) => { g.hidden = visible.indexOf(g) === -1; });
            noRes.hidden = matched.length !== 0;
            pagerBar.hidden = matched.length === 0;
            if (matched.length === 0) {
                count.textContent = 'No shoes match your filters (0 of ' + groups.length + ').';
            } else {
                count.textContent = 'Showing ' + (start + 1) + '–' + (start + visible.length) + ' of ' + matched.length
                    + ' shoe model' + (matched.length === 1 ? '' : 's') + (matched.length !== groups.length ? ' (filtered from ' + groups.length + ')' : '');
                pageInfo.textContent = 'Page ' + page + ' of ' + pages;
                renderPager(pages);
            }
            return matched;
        }

        /* admin.php#shoe-5 / #colorway-12 (links from the Shoes and Edit colorway pages): open that model on its page and show it. */
        function openFromHash(matched) {
            const m = /^#(shoe|colorway)-(\d+)$/.exec(window.location.hash);
            const target = m ? document.getElementById(m[1] + '-' + m[2]) : null;
            const group = target ? target.closest('tbody.shoe-group') : null;
            const idx = group ? matched.indexOf(group) : -1;
            if (idx === -1) {
                return;
            }
            page = Math.floor(idx / PER_PAGE) + 1;
            apply();
            const row = group.querySelector('tr.model-detail');
            const toggle = group.querySelector('[data-bs-target="#' + row.id + '"]');
            row.classList.add('show');
            if (toggle) {
                toggle.classList.remove('collapsed');
                toggle.setAttribute('aria-expanded', 'true');
            }
            if (target.classList.contains('cw-panel')) {
                target.classList.add('is-target');
            }
            // Jump to it (no slow animation when a page opens), now and once more after the page has finished
            // loading: the browser's own handling of the #... part of the address runs then and could undo the first jump.
            const reveal = () => (m[1] === 'shoe' ? group : target).scrollIntoView({ block: m[1] === 'shoe' ? 'start' : 'center', behavior: 'instant' });
            requestAnimationFrame(reveal);
            if (document.readyState === 'complete') {
                setTimeout(reveal, 0);
            } else {
                window.addEventListener('load', () => setTimeout(reveal, 0), { once: true });
            }
        }
        function applyFromFirstPage() {
            page = 1;
            apply();
        }

        [q, cat, status, sortSel].forEach((el) => { if (el) { el.addEventListener('input', applyFromFirstPage); } });
        document.getElementById('clear-filters').addEventListener('click', () => {
            q.value = '';
            cat.value = '';
            if (status) { status.value = ''; }
            sortSel.value = 'name';
            setMain('');
            applyFromFirstPage();
            q.focus();
        });
        /* Main category tabs, and the "Show them" link of the Unassigned notice (ctrl/middle click still opens a new tab) */
        document.addEventListener('click', (ev) => {
            const a = ev.target.closest('#main-nav .cat-pill, [data-main-link]');
            if (!a || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.altKey || ev.button !== 0) {
                return;
            }
            ev.preventDefault();
            setMain(a.hasAttribute('data-main-link') ? a.dataset.mainLink : a.dataset.main);
            applyFromFirstPage();
            if (a.hasAttribute('data-main-link')) {
                mainNav.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
        document.querySelectorAll('[data-quick-filter]').forEach((btn) => {
            btn.addEventListener('click', () => {
                if (!status) { return; }
                status.value = btn.dataset.quickFilter;
                if (sortSel.querySelector('option[value="out-desc"]')) { sortSel.value = 'out-desc'; }
                applyFromFirstPage();
                table.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });
        pager.addEventListener('click', (ev) => {
            const btn = ev.target.closest('button[data-page]');
            if (!btn || btn.disabled) { return; }
            page = parseInt(btn.dataset.page, 10) || 1;
            apply();
            table.closest('.card').scrollIntoView({ behavior: 'smooth', block: 'start' });
            const cur = pager.querySelector('[aria-current="page"]');
            if (cur) { cur.focus({ preventScroll: true }); }
        });
        document.addEventListener('keydown', (ev) => {
            const tag = (ev.target.tagName || '').toLowerCase();
            if (ev.key === '/' && tag !== 'input' && tag !== 'textarea' && tag !== 'select') {
                ev.preventDefault();
                q.focus();
            }
        });
        const firstMatch = apply();
        if (saved && saved.scroll) {
            requestAnimationFrame(() => window.scrollTo(0, saved.scroll));
        } else if (!saved) {
            openFromHash(firstMatch);
        }
    }

    /* ---------- Stock in / Stock out / Set count ---------- */
    const HELP = {
        stock_in: 'Enter how many pairs arrived for each size. Leave a size empty if nothing arrived.',
        stock_out: 'Enter how many pairs left (sold, returned, damaged). Stock can never go below 0.',
        adjustment: 'Type the number of pairs you actually counted for each size.'
    };
    document.querySelectorAll('form.stock-form').forEach((form) => {
        const inputs = Array.from(form.querySelectorAll('.qty-input'));
        const save = form.querySelector('[data-save]');
        const label = form.querySelector('[data-changed-label]');
        const help = form.querySelector('.mode-help');
        const mode = () => {
            const picked = form.querySelector('.mode-pick:checked');
            return picked ? picked.value : '';
        };
        function refresh() {
            const m = mode();
            let changed = 0;
            inputs.forEach((input) => {
                const current = Number(input.dataset.current);
                const raw = input.value.trim();
                const n = raw === '' ? NaN : Number(raw);
                let after = null;
                if (Number.isInteger(n) && n >= 0) {
                    if (m === 'stock_in' && n > 0) { after = current + n; }
                    if (m === 'stock_out' && n > 0) { after = current - n; }
                    if (m === 'adjustment' && n !== current) { after = n; }
                }
                const hint = input.closest('.stock-cell').querySelector('.stock-hint');
                input.classList.toggle('is-changed', after !== null);
                hint.textContent = after === null ? '' : (after < 0 ? 'only ' + current + ' in stock' : 'becomes ' + after);
                if (after !== null) { changed++; }
            });
            save.disabled = changed === 0;
            label.textContent = changed === 0 ? 'No changes yet' : changed + ' size' + (changed === 1 ? '' : 's') + ' will change';
        }
        function setMode() {
            const m = mode();
            help.textContent = HELP[m] || '';
            inputs.forEach((input) => {
                const current = Number(input.dataset.current);
                input.value = m === 'adjustment' ? String(current) : '';
                input.placeholder = m === 'adjustment' ? '' : '0';
                input.max = m === 'stock_out' ? String(current) : String(m === 'stock_in' ? 100000 - current : 100000);
            });
            form.classList.remove('was-validated');
            refresh();
        }
        form.addEventListener('change', (ev) => {
            if (ev.target.classList.contains('mode-pick')) { setMode(); }
        });
        form.addEventListener('input', (ev) => {
            if (ev.target.classList.contains('qty-input') && Number(ev.target.value) < 0) { ev.target.value = 0; }
            refresh();
        });
        form.addEventListener('click', (ev) => {
            const step = ev.target.closest('.step-btn');
            if (step) {
                const input = step.parentElement.querySelector('.qty-input');
                const base = input.value === '' ? (mode() === 'adjustment' ? Number(input.dataset.current) : 0) : (parseInt(input.value, 10) || 0);
                input.value = Math.max(0, base + parseInt(step.dataset.delta, 10));
                refresh();
            }
            if (ev.target.closest('[data-reset]')) { setMode(); }
        });
        form.addEventListener('submit', (ev) => {
            if (!form.checkValidity()) {
                ev.preventDefault();
                form.classList.add('was-validated');
                const bad = form.querySelector(':invalid');
                if (bad) { bad.focus(); }
                return;
            }
            saveView();
            save.classList.add('is-loading');
            setTimeout(() => { save.disabled = true; }, 0);
        });
        setMode();
    });

    document.querySelectorAll('form.details-form').forEach((form) => form.addEventListener('submit', saveView));

    /* ---------- Delete confirmations ---------- */
    const shoeModal = document.getElementById('delete-shoe-modal');
    if (shoeModal) {
        const modal = new bootstrap.Modal(shoeModal);
        document.querySelectorAll('.btn-delete-shoe').forEach((btn) => btn.addEventListener('click', () => {
            shoeModal.querySelector('[name="shoe_id"]').value = btn.dataset.id;
            shoeModal.querySelector('[data-name]').textContent = btn.dataset.name;
            shoeModal.querySelector('[data-detail]').textContent = btn.dataset.detail;
            modal.show();
        }));
        shoeModal.querySelector('form').addEventListener('submit', saveView);
    }
    const cwModal = document.getElementById('delete-colorway-modal');
    if (cwModal) {
        const modal = new bootstrap.Modal(cwModal);
        document.querySelectorAll('.btn-delete-colorway').forEach((btn) => btn.addEventListener('click', () => {
            const pairs = Number(btn.dataset.pairs);
            cwModal.querySelector('[name="shoe_id"]').value = btn.dataset.shoe;
            cwModal.querySelector('[name="colorway_id"]').value = btn.dataset.id;
            cwModal.querySelector('[data-name]').textContent = btn.dataset.name;
            cwModal.querySelector('[data-model]').textContent = btn.dataset.model;
            cwModal.querySelector('[data-pairs]').textContent = pairs + ' pair' + (pairs === 1 ? '' : 's') + ' in stock';
            modal.show();
        }));
        cwModal.querySelector('form').addEventListener('submit', saveView);
    }
})();
</script>
</body>
</html>
