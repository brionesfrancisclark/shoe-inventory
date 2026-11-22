<?php
declare(strict_types=1);
require __DIR__ . '/layout.php';
require_once __DIR__ . '/inventory-lib.php';

/*
 * Shoes (internal - staff and admin only, needs "View Shoes"):
 *   shoe.php               every shoe model as a card: All Shoes / Women's Shoes / Men's Shoes,
 *                          with search and a category filter
 *   shoe.php?main=women    opens on Women's Shoes (main=men: Men's Shoes)
 *   shoe.php?id=N          one model: pick a colorway to see its own photo and sizes, then pick a size
 *   shoe.php?id=N&colorway=M  opens with that colorway selected
 * What is shown follows the account's permissions: colorways and photos need "View Colorways",
 * pair counts need "View Inventory", low / out-of-stock marks need their own permissions.
 */

$user         = require_permission('shoes.view');
require_main_categories($pdo);
$canColorways = can('colorways.view');
$canViewInv   = can('inventory.view');
$showLow      = can('inventory.low_stock');
$showOut      = can('inventory.out_of_stock');
$canEditCw    = can('colorways.edit') || can('images.change') || can('colorways.delete');
$canAddCw     = can('colorways.add');
$canHistory   = can('inventory.history');

/** A photo box: the picture, or a neutral placeholder when there is none or it fails to load. */
function photo_box(?string $src, string $alt, string $class, bool $lazy = true): string
{
    if ($src === null) {
        return '<span class="pbox ' . $class . ' is-empty" title="No photo yet"><i class="bi bi-image" aria-hidden="true"></i></span>';
    }
    return '<span class="pbox ' . $class . '"><img src="' . e($src) . '" alt="' . e($alt) . '"' . ($lazy ? ' loading="lazy"' : '')
         . ' decoding="async" referrerpolicy="no-referrer" onerror="this.parentNode.classList.add(\'is-broken\');">'
         . '<i class="bi bi-image" aria-hidden="true"></i></span>';
}

$shoeId = (int) filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 0]]);
$css = <<<'CSS'
<style>
    .pbox { position: relative; display: flex; align-items: center; justify-content: center; overflow: hidden; background: var(--photo-bg); flex-shrink: 0; }
    .pbox img { width: 100%; height: 100%; object-fit: contain; }
    .pbox > .bi { display: none; font-size: 1.6rem; color: var(--photo-icon); }
    .pbox.is-empty > .bi, .pbox.is-broken > .bi { display: block; }
    .pbox.is-broken img { display: none; }
    .card-photo { width: 100%; aspect-ratio: 4 / 3; border-radius: 16px 16px 0 0; }
    .hero-photo { width: 100%; aspect-ratio: 1 / 1; max-height: 520px; border-radius: 20px; border: 1px solid var(--line-2); background: var(--photo-bg-2); }
    .hero-photo > .bi { font-size: 3rem; }
    .swatch { width: 28px; height: 28px; border-radius: 50%; border: 1px solid var(--line); }
    .swatch > .bi { font-size: .7rem; }
    .shoe-card { transition: transform .15s ease, box-shadow .15s ease; color: inherit; text-decoration: none; }
    .shoe-card:hover, .shoe-card:focus-visible { transform: translateY(-2px); box-shadow: 0 0 0 2px var(--ink), 0 .5rem 1.25rem rgba(0,0,0,.08) !important; }
    .cw-option { display: flex; flex-direction: column; align-items: center; gap: .3rem; width: 96px; padding: .4rem; border: 2px solid transparent;
                 border-radius: 14px; color: inherit; text-decoration: none; font-size: .75rem; font-weight: 600; text-align: center; }
    .cw-option .pbox { width: 80px; height: 80px; border-radius: 12px; }
    .cw-option:hover { border-color: var(--field); }
    .cw-option[aria-current="true"] { border-color: var(--ink); background: var(--surface); }
    .cw-option .cw-label { max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .size-btn { min-width: 4.6rem; border: 1px solid var(--line-strong); border-radius: 10px; background: var(--surface); padding: .45rem .5rem; line-height: 1.15; }
    .size-btn .qty { display: block; font-size: .7rem; font-weight: 600; color: var(--ink-2); }
    .size-btn:hover { border-color: var(--ink); }
    .size-btn[aria-pressed="true"] { background: var(--ink); border-color: var(--ink); color: var(--on-ink); }
    .size-btn[aria-pressed="true"] .qty { color: var(--on-ink-2); }
    .size-btn.is-out { background: var(--bad-bg); border-color: var(--bad-border); color: var(--bad-strong); }
    .size-btn.is-out .qty { color: var(--bad-strong); }
    .size-btn.is-low .qty { color: var(--warn-text); }
    .size-btn.is-out[aria-pressed="true"] { background: var(--bad-solid); color: #fff; }
    #shoe-search {
        padding-left: 2.4rem; background-repeat: no-repeat; background-position: .85rem center; background-size: 1rem;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%23707072'%3E%3Cpath d='M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85zm-5.242.656a5 5 0 1 1 0-10 5 5 0 0 1 0 10z'/%3E%3C/svg%3E");
    }
    @media (max-width: 575.98px) {
        .cw-option { width: 78px; }
        .cw-option .pbox { width: 62px; height: 62px; }
    }
</style>
CSS;

/* =====================================================================================
   One shoe model
   ===================================================================================== */
if ($shoeId > 0) {
    $model = get_shoe_model($pdo, $shoeId);
    if ($model === null) {
        flash('danger', 'That shoe model no longer exists - it may have been deleted.');
        redirect('shoe.php');
    }
    $label     = $model['brand'] . ' ' . $model['name'];
    $mainLabel = main_category_label($model['main_category']);
    $colorways = [];
    if ($canColorways) {
        foreach (colorways_of($pdo, $shoeId) as $c) {
            $sizes = colorway_sizes($pdo, (int) $c['id']);
            $colorways[(int) $c['id']] = [
                'name'  => $c['name'], 'description' => (string) $c['description'],
                'image' => image_src($c['image_path'], $c['image_url']),
                'sizes' => $sizes, 'pairs' => array_sum(array_map(static fn (array $s): int => (int) $s['quantity'], $sizes)),
            ];
        }
    }
    $wanted   = (int) filter_var($_GET['colorway'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 0]]);
    $selected = isset($colorways[$wanted]) ? $wanted : (array_key_first($colorways) ?? 0);
    $total    = array_sum(array_column($colorways, 'pairs'));

    render_header($label, 'shoes', true, $css);
    ?>
<main id="main" class="container pb-5">
    <?php render_flash(); ?>
    <div class="d-flex flex-wrap gap-2 mb-2">
        <a href="shoe.php" class="btn btn-link btn-sm px-0"><i class="bi bi-arrow-left" aria-hidden="true"></i> All shoes</a>
        <?php if ($canViewInv): ?><a href="admin.php#shoe-<?= $shoeId ?>" class="btn btn-link btn-sm">Open in inventory</a><?php endif; ?>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <p class="small text-muted text-uppercase fw-semibold mb-1" style="letter-spacing:.08em"><?= e($model['brand']) ?></p>
            <h1 class="page-title mb-1" id="shoe-title"><?= e($model['name']) ?></h1>
            <p class="page-sub mb-0">
                <?php if ($mainLabel !== ''): ?><a class="fw-semibold" href="shoe.php?main=<?= e($model['main_category']) ?>" id="shoe-main"><?= e($mainLabel) ?></a> &middot;<?php endif; ?>
                <?= e($model['category']) ?> &middot; <strong class="text-dark">&#8369;<?= number_format((float) $model['price'], 2) ?></strong>
                <?php if ($canColorways): ?>&middot; <?= count($colorways) ?> colorway<?= count($colorways) === 1 ? '' : 's' ?><?php endif; ?>
                <?php if ($canViewInv && $canColorways): ?>&middot; <?= $total ?> pair<?= $total === 1 ? '' : 's' ?> in stock<?php endif; ?>
            </p>
        </div>
        <?php if ($canAddCw): ?>
            <a class="btn btn-outline-secondary btn-sm" href="add-shoe.php?shoe=<?= $shoeId ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add colorway</a>
        <?php endif; ?>
    </div>

    <?php if (!$canColorways): ?>
        <div class="card shadow-sm"><div class="card-body text-center py-5 text-muted">
            Your account cannot see colorways, their photos or sizes. Ask the administrator for the "View Colorways" permission.
        </div></div>
    <?php elseif (!$colorways): ?>
        <div class="card shadow-sm"><div class="card-body text-center py-5 text-muted">This model has no colorways yet.</div></div>
    <?php else: ?>

    <nav class="d-flex flex-wrap gap-2 mb-4" id="colorway-picker" aria-label="Colorways of <?= e($label) ?>">
        <?php foreach ($colorways as $cid => $c): ?>
            <a class="cw-option" href="shoe.php?id=<?= $shoeId ?>&amp;colorway=<?= $cid ?>" data-colorway="<?= $cid ?>"
               aria-current="<?= $cid === $selected ? 'true' : 'false' ?>" title="<?= e($c['name']) ?>">
                <?= photo_box($c['image'], '', '', false) ?>
                <span class="cw-label"><?= e($c['name']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php foreach ($colorways as $cid => $c): ?>
    <section class="row g-4 cw-view" data-colorway="<?= $cid ?>" aria-label="<?= e($c['name']) ?>"<?= $cid === $selected ? '' : ' hidden' ?>>
        <div class="col-lg-7">
            <?= photo_box($c['image'], $label . ' - ' . $c['name'], 'hero-photo', $cid !== $selected) ?>
        </div>
        <div class="col-lg-5">
            <div class="card shadow-sm"><div class="card-body p-4">
                <p class="small text-muted mb-1">Colorway</p>
                <h2 class="h3 mb-1 cw-name"><?= e($c['name']) ?></h2>
                <?php if ($c['description'] !== ''): ?><p class="text-muted mb-0"><?= e($c['description']) ?></p><?php endif; ?>
                <?php if ($canViewInv): ?>
                    <p class="small mb-0 mt-2"><span class="badge rounded-pill text-bg-light border text-dark"><?= $c['pairs'] ?> pair<?= $c['pairs'] === 1 ? '' : 's' ?> in stock</span></p>
                <?php endif; ?>

                <hr>
                <?php if ($c['sizes']): ?>
                    <p class="fw-semibold mb-2" id="sizes-<?= $cid ?>">Select a size</p>
                    <div class="d-flex flex-wrap gap-2 size-list" role="group" aria-labelledby="sizes-<?= $cid ?>">
                        <?php foreach ($c['sizes'] as $s):
                            $qty    = (int) $s['quantity'];
                            $status = stock_status($qty);
                            $flag   = $canViewInv && $status === 'out' && $showOut ? 'is-out' : ($canViewInv && $status === 'low' && $showLow ? 'is-low' : '');
                            $info   = 'US ' . $s['size_number'] . ($canViewInv ? ' · ' . $qty . ' pair' . ($qty === 1 ? '' : 's') . ' in stock'
                                    . ($flag === 'is-out' ? ' (out of stock)' : ($flag === 'is-low' ? ' (low stock)' : '')) : ''); ?>
                            <button type="button" class="size-btn <?= $flag ?>" aria-pressed="false" data-info="<?= e($info) ?>">
                                US <?= e((string) $s['size_number']) ?>
                                <?php if ($canViewInv): ?><span class="qty"><?= $flag === 'is-out' ? 'out' : $qty . ' left' ?></span><?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <p class="small mt-3 mb-0 size-info" aria-live="polite">Pick a size to see <?= $canViewInv ? 'how many pairs are in stock' : 'it here' ?>.</p>
                <?php else: ?>
                    <p class="small text-muted mb-0">No sizes yet for this colorway.</p>
                <?php endif; ?>

                <?php if ($canEditCw || $canHistory || $canViewInv): ?>
                <div class="d-flex flex-wrap gap-2 mt-4">
                    <?php if ($canEditCw): ?><a class="btn btn-primary btn-sm" href="colorway.php?id=<?= $cid ?>">Edit colorway</a><?php endif; ?>
                    <?php if ($canViewInv): ?><a class="btn btn-outline-secondary btn-sm" href="admin.php#colorway-<?= $cid ?>">Stock in inventory</a><?php endif; ?>
                    <?php if ($canHistory): ?><a class="btn btn-link btn-sm" href="inventory-history.php?colorway=<?= $cid ?>">History</a><?php endif; ?>
                </div>
                <?php endif; ?>
            </div></div>
        </div>
    </section>
    <?php endforeach; ?>
    <?php endif; ?>
</main>

<?php render_scripts(); ?>
<script>
(function () {
    'use strict';
    const picker = document.getElementById('colorway-picker');
    if (!picker) {
        return;
    }
    const views = Array.from(document.querySelectorAll('.cw-view'));
    function show(id, focus) {
        views.forEach((v) => { v.hidden = v.dataset.colorway !== id; });
        picker.querySelectorAll('.cw-option').forEach((a) => a.setAttribute('aria-current', a.dataset.colorway === id ? 'true' : 'false'));
        const view = views.find((v) => v.dataset.colorway === id);
        const img = view ? view.querySelector('.hero-photo img') : null;
        if (img) {
            img.loading = 'eager';                      // load it now that it is shown
        }
        try {
            const url = new URL(window.location.href);
            url.searchParams.set('colorway', id);
            history.replaceState(null, '', url.pathname + url.search);
        } catch (err) { /* old browser: the link still works */ }
        if (focus && view) {
            view.querySelector('.cw-name').focus({ preventScroll: true });
        }
    }
    views.forEach((v) => v.querySelector('.cw-name').setAttribute('tabindex', '-1'));
    picker.addEventListener('click', (ev) => {
        const a = ev.target.closest('.cw-option');
        if (!a || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.button !== 0) {
            return;                                     // let "open in new tab" work as usual
        }
        ev.preventDefault();
        show(a.dataset.colorway, false);
    });
    document.querySelectorAll('.size-list').forEach((list) => {
        const info = list.parentElement.querySelector('.size-info');
        list.addEventListener('click', (ev) => {
            const btn = ev.target.closest('.size-btn');
            if (!btn) {
                return;
            }
            const on = btn.getAttribute('aria-pressed') !== 'true';
            list.querySelectorAll('.size-btn').forEach((b) => b.setAttribute('aria-pressed', 'false'));
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            info.textContent = on ? btn.dataset.info : 'Pick a size.';
        });
    });
})();
</script>
</body>
</html>
    <?php
    exit;
}

/* =====================================================================================
   All shoe models (?main=women / ?main=men opens on that main category)
   ===================================================================================== */
$models = $pdo->query(
    'SELECT s.id, s.name, s.brand, s.main_category, s.category, s.price,
            (SELECT COUNT(*) FROM shoe_colorways c WHERE c.shoe_id = s.id) AS colorways,
            (SELECT COALESCE(SUM(z.quantity), 0) FROM shoe_sizes z WHERE z.shoe_id = s.id) AS pairs
       FROM shoes s
      ORDER BY s.brand, s.name, s.id'
)->fetchAll();
$swatches = [];
if ($canColorways) {
    foreach ($pdo->query('SELECT id, shoe_id, name, image_path, image_url FROM shoe_colorways ORDER BY shoe_id, sort_order, id')->fetchAll() as $c) {
        $swatches[(int) $c['shoe_id']][] = ['id' => (int) $c['id'], 'name' => $c['name'], 'image' => image_src($c['image_path'], $c['image_url'])];
    }
}
$categories = array_values(array_unique(array_column($models, 'category')));
sort($categories, SORT_NATURAL | SORT_FLAG_CASE);

// Main category tabs: All Shoes, Women's Shoes, Men's Shoes - each with how many models it has.
$mainFilter = main_category_param($_GET['main'] ?? null);
$mainTabs   = ['' => 'All Shoes'] + MAIN_CATEGORIES;
$mainCounts = ['' => count($models)];
foreach (array_keys(MAIN_CATEGORIES) as $code) {
    $mainCounts[$code] = count(array_filter($models, static fn (array $m): bool => $m['main_category'] === $code));
}

render_header($mainTabs[$mainFilter], 'shoes', true, $css);
?>
<main id="main" class="container pb-5">
    <?php render_flash(); ?>
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <h1 class="page-title mb-1" id="shoe-heading"><?= e($mainTabs[$mainFilter]) ?></h1>
            <p class="page-sub mb-0">Every shoe model. Open one to see its colorways, photos and sizes.</p>
        </div>
        <?php if (can('shoes.add') || $canAddCw): ?><a href="add-shoe.php" class="btn btn-success">+ Add New Shoe</a><?php endif; ?>
    </div>

    <?php if (!$models): ?>
        <div class="card shadow-sm"><div class="card-body text-center py-5 text-muted">No shoes yet.</div></div>
    <?php else: ?>
    <nav class="cat-nav mb-3" id="main-nav" aria-label="Main categories">
        <?php foreach ($mainTabs as $code => $tab): ?>
            <a class="cat-pill" href="shoe.php<?= $code !== '' ? '?main=' . e($code) : '' ?>" data-main="<?= e($code) ?>" data-label="<?= e($tab) ?>"<?= $code === $mainFilter ? ' aria-current="page"' : '' ?>>
                <?= e($tab) ?><span class="cat-count"><span data-count><?= $mainCounts[$code] ?></span><span class="visually-hidden"> models</span></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="row g-2 align-items-end mb-3">
        <div class="col-12 col-md-6">
            <label for="shoe-search" class="form-label small mb-1">Search</label>
            <input type="search" id="shoe-search" class="form-control" placeholder="Name, brand, category<?= $canColorways ? ' or colorway' : '' ?>" autocomplete="off">
        </div>
        <div class="col-8 col-md-3">
            <label for="shoe-cat" class="form-label small mb-1">Category</label>
            <select id="shoe-cat" class="form-select">
                <option value="">All</option>
                <?php foreach ($categories as $c): ?><option value="<?= e($c) ?>"><?= e($c) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-4 col-md-3 small text-muted pb-2" id="shoe-count" aria-live="polite"></div>
    </div>

    <div class="row g-3" id="shoe-grid">
        <?php foreach ($models as $m):
            $mid    = (int) $m['id'];
            $sw     = $swatches[$mid] ?? [];
            $cover  = null;
            foreach ($sw as $one) {
                if ($one['image'] !== null) {
                    $cover = $one['image'];
                    break;
                }
            }
            $search = mb_strtolower($m['brand'] . ' ' . $m['name'] . ' ' . $m['category'] . ' ' . implode(' ', array_column($sw, 'name')));
            $main   = (string) $m['main_category'];                  // '' = no main category yet: listed under All Shoes only
            $inTab  = $mainFilter === '' || $main === $mainFilter; ?>
            <div class="col-12 col-sm-6 col-lg-4 col-xl-3 shoe-item" data-search="<?= e($search) ?>" data-category="<?= e($m['category']) ?>"
                 data-main="<?= e($main) ?>"<?= $inTab ? '' : ' hidden' ?>>
                <a class="card shadow-sm h-100 shoe-card" href="shoe.php?id=<?= $mid ?>">
                    <?= photo_box($cover, '', 'card-photo') ?>
                    <span class="card-body d-block">
                        <span class="small text-muted d-block"><?= e($m['brand']) ?></span>
                        <span class="fw-bold d-block"><?= e($m['name']) ?></span>
                        <span class="small text-muted d-block"><?php if ($main !== ''): ?><?= e(main_category_label($main)) ?> &middot; <?php endif; ?><?= e($m['category']) ?> &middot; &#8369;<?= number_format((float) $m['price'], 2) ?></span>
                        <?php if ($canColorways): ?>
                            <span class="d-flex flex-wrap align-items-center gap-1 mt-2">
                                <?php foreach (array_slice($sw, 0, 6) as $one): ?><span title="<?= e($one['name']) ?>"><?= photo_box($one['image'], '', 'swatch') ?></span><?php endforeach; ?>
                                <span class="small text-muted ms-1"><?= (int) $m['colorways'] ?> colorway<?= (int) $m['colorways'] === 1 ? '' : 's' ?></span>
                            </span>
                        <?php endif; ?>
                        <?php if ($canViewInv): ?><span class="small text-muted d-block mt-1"><?= (int) $m['pairs'] ?> pair<?= (int) $m['pairs'] === 1 ? '' : 's' ?> in stock</span><?php endif; ?>
                    </span>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="text-center text-muted py-5 d-none" id="shoe-none">No shoes match your search.</p>
    <?php endif; ?>
</main>

<?php render_scripts(); ?>
<script>
(function () {
    'use strict';
    const q = document.getElementById('shoe-search');
    if (!q) {
        return;
    }
    const cat = document.getElementById('shoe-cat');
    const nav = document.getElementById('main-nav');
    const pills = Array.from(nav.querySelectorAll('.cat-pill'));
    const heading = document.getElementById('shoe-heading');
    const none = document.getElementById('shoe-none');
    const items = Array.from(document.querySelectorAll('.shoe-item'));
    const picked = pills.find((p) => p.getAttribute('aria-current') === 'page');
    let main = picked ? picked.dataset.main : '';         // '' = All Shoes, else 'women' / 'men'

    /* Search and the category filter look at every model; the tab counts show how many matches each
       main category has, and only the chosen main category is listed. */
    function apply() {
        const term = q.value.trim().toLowerCase();
        const counts = {};
        pills.forEach((p) => { counts[p.dataset.main] = 0; });
        let shown = 0;
        let inTab = 0;
        items.forEach((it) => {
            const m = it.dataset.main;
            const inMain = main === '' || m === main;
            const ok = (!term || it.dataset.search.indexOf(term) !== -1) && (!cat.value || it.dataset.category === cat.value);
            if (ok) {
                counts['']++;
                if (m !== '' && Object.prototype.hasOwnProperty.call(counts, m)) { counts[m]++; }
            }
            if (inMain) { inTab++; }
            it.hidden = !(ok && inMain);
            if (ok && inMain) { shown++; }
        });
        pills.forEach((p) => { p.querySelector('[data-count]').textContent = counts[p.dataset.main]; });
        const current = pills.find((p) => p.dataset.main === main);
        none.textContent = (inTab === 0 && current) ? 'No ' + current.dataset.label + ' yet.' : 'No shoes match your search.';
        none.classList.toggle('d-none', shown !== 0);
        document.getElementById('shoe-count').textContent = shown + ' of ' + inTab + ' model' + (inTab === 1 ? '' : 's');
    }
    function setMain(code) {
        const pill = pills.find((p) => p.dataset.main === code);
        if (!pill) {
            return;
        }
        main = code;
        pills.forEach((p) => {
            if (p === pill) { p.setAttribute('aria-current', 'page'); } else { p.removeAttribute('aria-current'); }
        });
        heading.textContent = pill.dataset.label;
        const cut = document.title.indexOf(' | ');
        document.title = pill.dataset.label + (cut === -1 ? '' : document.title.slice(cut));
        try {                                             // the address shows the tab, so it can be bookmarked or shared
            const url = new URL(window.location.href);
            if (code) { url.searchParams.set('main', code); } else { url.searchParams.delete('main'); }
            history.replaceState(null, '', url.pathname + url.search);
        } catch (err) { /* old browser: the links still work */ }
        apply();
    }
    nav.addEventListener('click', (ev) => {
        const a = ev.target.closest('.cat-pill');
        if (!a || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.altKey || ev.button !== 0) {
            return;                                       // let "open in new tab" work as usual
        }
        ev.preventDefault();
        setMain(a.dataset.main);
    });
    q.addEventListener('input', apply);
    cat.addEventListener('change', apply);
    apply();
})();
</script>
</body>
</html>
