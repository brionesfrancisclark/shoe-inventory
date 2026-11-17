<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Opens the HTML page: head, skip-link and (when signed in) the navbar.
 * Pages open their own <main id="main"> afterwards.
 */
function render_header(string $title, string $active = '', bool $nav = true, string $extraHead = ''): void
{
    $u = current_user();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> | Footwear Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        /* ============================================================
           Footwear Inventory - shared theme
           Minimal black & white, bold type, pill buttons, soft cards.
           Everything below only re-skins Bootstrap 5; no behaviour here.
           ============================================================ */
        :root {
            --ink: #111111;
            --ink-2: #707072;
            --line: #e5e5e5;
            --soft: #f5f5f5;

            --bs-body-font-family: "Helvetica Neue", Helvetica, Arial, "Segoe UI", sans-serif;
            --bs-body-color: #111111;
            --bs-body-bg: #f5f5f5;
            --bs-primary: #111111;           --bs-primary-rgb: 17, 17, 17;
            --bs-secondary: #707072;         --bs-secondary-rgb: 112, 112, 114;
            --bs-secondary-color: #707072;
            --bs-light-rgb: 245, 245, 245;
            --bs-danger-rgb: 212, 63, 33;
            --bs-link-color-rgb: 17, 17, 17;
            --bs-link-hover-color-rgb: 112, 112, 114;
            --bs-border-color: #e5e5e5;
            --bs-border-radius: 10px;
            --bs-border-radius-sm: 8px;
            --bs-border-radius-lg: 16px;
            --bs-border-radius-xl: 20px;
            --bs-box-shadow-sm: 0 1px 2px rgba(17, 17, 17, .05), 0 6px 18px rgba(17, 17, 17, .04);
        }

        html { scroll-padding-top: 5.5rem; }      /* keeps scrolled-to content clear of the sticky navbar */
        body { background: var(--soft); -webkit-font-smoothing: antialiased; }
        :focus-visible { outline: 2px solid #111; outline-offset: 2px; }
        h1, h2, h3, h4, h5, h6 { font-weight: 800; letter-spacing: -.02em; }
        .form-check-input:not(:checked) { border-color: #8f8f91; }   /* empty checkboxes / radios clearly visible */
        .btn-spinner { display: none; }
        .is-loading .btn-spinner { display: inline-block; }

        /* ---------- Page headings ---------- */
        .page-title {
            font-size: clamp(1.6rem, 1.2rem + 1.6vw, 2.25rem); font-weight: 800; line-height: 1.05;
            letter-spacing: -.03em; text-transform: uppercase;
        }
        .page-sub { color: var(--ink-2); font-size: .95rem; }

        /* ---------- Navbar ---------- */
        .app-nav { background: #fff; border-bottom: 1px solid var(--line); padding: .4rem 0; }
        .app-nav .navbar-brand {
            display: inline-flex; align-items: center; gap: .6rem;
            font-weight: 800; font-size: 1.05rem; letter-spacing: -.02em; text-transform: uppercase; color: var(--ink);
        }
        .app-nav .nav-link { position: relative; padding: .5rem .9rem; font-weight: 600; color: var(--ink); }
        .app-nav .nav-link::after {
            content: ""; position: absolute; left: .9rem; right: .9rem; bottom: .15rem; height: 2px;
            background: var(--ink); transform: scaleX(0); transform-origin: left; transition: transform .2s ease;
        }
        .app-nav .nav-link:hover::after, .app-nav .nav-link.active::after { transform: scaleX(1); }
        .app-nav .navbar-toggler { border-color: #cacacb; }
        .brand-mark {
            display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
            width: 2rem; height: 2rem; border-radius: .6rem; background: var(--ink); color: #fff; font-size: 1rem;
        }
        .brand-mark-lg { width: 3.5rem; height: 3.5rem; border-radius: 1rem; font-size: 1.6rem; }

        /* ---------- Cards ---------- */
        .card { border: 1px solid #ececec; }
        .card-header {
            background: #fff; padding: 1rem 1.25rem; border-bottom: 1px solid #f0f0f0;
            font-weight: 700; letter-spacing: -.01em;
        }
        .card-footer { background: #fff; padding: 1rem 1.25rem; border-top: 1px solid #f0f0f0; }

        /* ---------- Buttons (pill, black primary) ---------- */
        .btn {
            --bs-btn-padding-x: 1.25rem; --bs-btn-padding-y: .55rem;
            --bs-btn-font-weight: 600; --bs-btn-border-radius: 50rem;
            transition: background-color .15s ease, border-color .15s ease, color .15s ease, box-shadow .15s ease, transform .15s ease;
        }
        .btn-sm { --bs-btn-padding-x: .9rem; --bs-btn-padding-y: .35rem; --bs-btn-font-size: .8rem; --bs-btn-border-radius: 50rem; }
        .btn-primary, .btn-success {
            --bs-btn-color: #fff; --bs-btn-bg: #111; --bs-btn-border-color: #111;
            --bs-btn-hover-color: #fff; --bs-btn-hover-bg: #707072; --bs-btn-hover-border-color: #707072;
            --bs-btn-active-color: #fff; --bs-btn-active-bg: #39393b; --bs-btn-active-border-color: #39393b;
            --bs-btn-disabled-color: #fff; --bs-btn-disabled-bg: #cacacb; --bs-btn-disabled-border-color: #cacacb;
            --bs-btn-focus-shadow-rgb: 17, 17, 17;
        }
        .btn-outline-primary, .btn-outline-secondary, .btn-outline-success, .btn-outline-dark {
            --bs-btn-color: #111; --bs-btn-border-color: #cacacb;
            --bs-btn-hover-color: #111; --bs-btn-hover-bg: #fff; --bs-btn-hover-border-color: #111;
            --bs-btn-active-color: #111; --bs-btn-active-bg: #f5f5f5; --bs-btn-active-border-color: #111;
            --bs-btn-disabled-color: #b5b5b7; --bs-btn-disabled-border-color: #e5e5e5;
            --bs-btn-focus-shadow-rgb: 17, 17, 17;
        }
        .btn-outline-danger {
            --bs-btn-color: #d43f21; --bs-btn-border-color: #f0c4bb;
            --bs-btn-hover-color: #fff; --bs-btn-hover-bg: #d43f21; --bs-btn-hover-border-color: #d43f21;
            --bs-btn-active-color: #fff; --bs-btn-active-bg: #b3301a; --bs-btn-active-border-color: #b3301a;
            --bs-btn-disabled-color: #e0b0a6; --bs-btn-disabled-border-color: #f3dcd6;
            --bs-btn-focus-shadow-rgb: 212, 63, 33;
        }
        .btn-danger {
            --bs-btn-bg: #d43f21; --bs-btn-border-color: #d43f21;
            --bs-btn-hover-bg: #b3301a; --bs-btn-hover-border-color: #b3301a;
            --bs-btn-active-bg: #9c2b17; --bs-btn-active-border-color: #9c2b17;
            --bs-btn-focus-shadow-rgb: 212, 63, 33;
        }
        .btn-light {
            --bs-btn-bg: #f5f5f5; --bs-btn-border-color: #e5e5e5; --bs-btn-color: #111;
            --bs-btn-hover-bg: #ebebeb; --bs-btn-hover-border-color: #cacacb; --bs-btn-hover-color: #111;
            --bs-btn-active-bg: #e0e0e0; --bs-btn-active-border-color: #cacacb; --bs-btn-active-color: #111;
        }
        .btn-link { --bs-btn-color: #707072; --bs-btn-hover-color: #111; --bs-btn-active-color: #111; }
        .input-group > .btn { --bs-btn-border-radius: var(--bs-border-radius); }
        .input-group-sm > .btn { --bs-btn-border-radius: var(--bs-border-radius-sm); }

        /* ---------- Forms ---------- */
        .form-label { margin-bottom: .35rem; font-size: .8rem; font-weight: 600; }
        .form-control, .form-select { padding: .6rem .85rem; border-color: #cacacb; }
        .form-control-sm, .form-select-sm { padding: .35rem .65rem; }
        .form-control:focus, .form-select:focus { border-color: #111; box-shadow: 0 0 0 .2rem rgba(17, 17, 17, .12); }
        .form-control::placeholder { color: #a0a0a2; }
        .form-text { color: var(--ink-2); }
        .input-group-text { background: var(--soft); border-color: #cacacb; color: var(--ink-2); font-weight: 600; }
        .form-check-input:checked { background-color: #111; border-color: #111; }
        .form-check-input:focus { border-color: #111; box-shadow: 0 0 0 .2rem rgba(17, 17, 17, .12); }

        /* ---------- Data tables ---------- */
        .table-grid > :not(caption) > * > * { padding: 1.1rem 1.25rem; border-bottom-color: #f0f0f0; }
        .table-grid thead th {
            background: #fff; color: var(--ink-2); font-size: .72rem; font-weight: 700;
            letter-spacing: .08em; text-transform: uppercase; border-bottom: 2px solid #111;
        }
        .table-grid tbody tr:not(.collapse):hover > * { --bs-table-bg-state: #fafafa; }
        @media (max-width: 767.98px) {
            .table-grid > :not(caption) > * > * { padding: .8rem; }
        }

        /* ---------- Badges ---------- */
        .badge { padding: .45em .8em; border-radius: 50rem; font-weight: 700; letter-spacing: .02em; }
        .badge.text-bg-success   { background-color: #e6f4ec !important; color: #0b6b3a !important; }
        .badge.text-bg-danger    { background-color: #fdecea !important; color: #b3301a !important; }
        .badge.text-bg-secondary { background-color: #ececec !important; color: #444 !important; }

        /* ---------- Alerts (flash messages) ---------- */
        .alert { border: 0; border-left: 4px solid transparent; border-radius: var(--bs-border-radius); font-weight: 500; }
        .alert-success { background: #e6f4ec; color: #0b5b36; border-left-color: #1a9b5c; }
        .alert-danger  { background: #fdecea; color: #8f2a16; border-left-color: #d43f21; }
        .alert-warning { background: #fff4d6; color: #6e4d00; border-left-color: #e0a800; }
        .alert-info    { background: #eceef0; color: #2f3338; border-left-color: #707072; }
        .alert ul { padding-left: 1.1rem; }

        /* ---------- Modals ---------- */
        .modal-content { border: 0; box-shadow: 0 24px 64px rgba(17, 17, 17, .22); }
        .modal-header, .modal-footer { border: 0; }
        .modal-header { padding: 1.5rem 1.5rem .5rem; }
        .modal-title { font-weight: 800; letter-spacing: -.02em; text-transform: uppercase; }

        /* ---------- Pagination (round, black active page) ---------- */
        .pagination {
            --bs-pagination-color: #111; --bs-pagination-bg: transparent; --bs-pagination-border-width: 0;
            --bs-pagination-hover-color: #111; --bs-pagination-hover-bg: #f5f5f5;
            --bs-pagination-focus-color: #111; --bs-pagination-focus-bg: #f5f5f5;
            --bs-pagination-focus-box-shadow: 0 0 0 .2rem rgba(17, 17, 17, .15);
            --bs-pagination-active-color: #fff; --bs-pagination-active-bg: #111; --bs-pagination-active-border-color: #111;
            --bs-pagination-disabled-color: #b5b5b7; --bs-pagination-disabled-bg: transparent;
            gap: .25rem;
        }
        .pagination .page-item .page-link,
        .pagination .page-item:first-child .page-link,
        .pagination .page-item:last-child .page-link {
            min-width: 2.2rem; text-align: center; font-weight: 600; border-radius: 50rem;
        }

        /* ---------- Add Shoe: size tiles ---------- */
        .size-tile {
            padding: .6rem .7rem .7rem; background: #f7f7f7; border: 1px solid #ececec; border-radius: 12px;
            transition: border-color .15s ease, background-color .15s ease;
        }
        .size-tile:hover { border-color: #cacacb; }
        .size-tile:focus-within { border-color: #111; background: #fff; }
        .size-tile .form-label { margin-bottom: .25rem; color: var(--ink-2); }

        /* ---------- Users: role cards ---------- */
        .role-icon {
            display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
            width: 2.6rem; height: 2.6rem; border-radius: .8rem; background: var(--soft); font-size: 1.15rem;
        }

        /* ---------- Login ---------- */
        .auth-wrap { min-height: 100vh; display: flex; flex-direction: column; justify-content: center; }
        .auth-title { font-size: 1.9rem; font-weight: 800; letter-spacing: -.03em; text-transform: uppercase; }
    </style>
    <?= $extraHead ?>
</head>
<body>
<a class="visually-hidden-focusable position-absolute p-2 bg-white" href="#main">Skip to content</a>
<?php if ($nav && $u): ?>
<nav class="navbar navbar-expand-md navbar-light bg-white app-nav sticky-top mb-4">
    <div class="container">
        <a class="navbar-brand" href="<?= e(home_page()) ?>"><span class="brand-mark" aria-hidden="true"><i class="bi bi-bag-check-fill"></i></span>Footwear Inventory</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav-menu"
                aria-controls="nav-menu" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="nav-menu">
            <ul class="navbar-nav me-auto">
                <?php if (can('inventory.view')): ?>
                <li class="nav-item">
                    <a class="nav-link<?= $active === 'inventory' ? ' active' : '' ?>" href="admin.php"
                       <?= $active === 'inventory' ? 'aria-current="page"' : '' ?>>Inventory</a>
                </li>
                <?php endif; ?>
                <?php if (can('shoes.view')): ?>
                <li class="nav-item">
                    <a class="nav-link<?= $active === 'shoes' ? ' active' : '' ?>" href="shoe.php"
                       <?= $active === 'shoes' ? 'aria-current="page"' : '' ?>>Shoes</a>
                </li>
                <?php endif; ?>
                <?php if (can('inventory.history')): ?>
                <li class="nav-item">
                    <a class="nav-link<?= $active === 'history' ? ' active' : '' ?>" href="inventory-history.php"
                       <?= $active === 'history' ? 'aria-current="page"' : '' ?>>History</a>
                </li>
                <?php endif; ?>
                <?php if (can('shoes.add') || can('colorways.add')): ?>
                <li class="nav-item">
                    <a class="nav-link<?= $active === 'add' ? ' active' : '' ?>" href="add-shoe.php"
                       <?= $active === 'add' ? 'aria-current="page"' : '' ?>>Add Shoe</a>
                </li>
                <?php endif; ?>
                <?php if ($u['role'] === 'admin'): ?>
                <li class="nav-item">
                    <a class="nav-link<?= $active === 'users' ? ' active' : '' ?>" href="users.php"
                       <?= $active === 'users' ? 'aria-current="page"' : '' ?>>Users</a>
                </li>
                <?php endif; ?>
            </ul>
            <div class="d-flex align-items-center gap-3 mt-2 mt-md-0">
                <span class="navbar-text text-dark fw-semibold p-0">
                    <?= e($u['display_name']) ?>
                    <span class="badge text-bg-<?= $u['role'] === 'admin' ? 'primary' : 'secondary' ?> ms-1"><?= e(ROLES[$u['role']] ?? $u['role']) ?></span>
                </span>
                <!-- The button only opens the confirmation dialog; nothing is submitted until "Yes". -->
                <form method="post" action="logout.php" class="m-0" id="signout-form">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <button type="button" class="btn btn-outline-secondary btn-sm"
                            data-bs-toggle="modal" data-bs-target="#signout-modal">Sign out</button>
                </form>
            </div>
        </div>
    </div>
</nav>

<!-- Sign-out confirmation -->
<div class="modal fade" id="signout-modal" tabindex="-1" aria-labelledby="signout-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="signout-title">Sign out</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Are you sure you want to sign out?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">No</button>
                <!-- Submits the navbar form above (the "form" attribute links them), reusing the existing POST + CSRF sign-out. -->
                <button type="submit" class="btn btn-primary" form="signout-form">Yes</button>
            </div>
        </div>
    </div>
</div>
<?php endif;
}

/** Prints the one-time flash banner, if any. Success banners fade after a few seconds. */
function render_flash(): void
{
    $f = take_flash();
    if (!$f) {
        return;
    }
    $type = in_array($f['type'], ['success', 'danger', 'warning', 'info'], true) ? $f['type'] : 'info';
    ?>
    <div class="alert alert-<?= $type ?> alert-dismissible fade show" role="<?= $type === 'danger' ? 'alert' : 'status' ?>"
         data-autohide="<?= $type === 'success' ? '1' : '0' ?>">
        <?= e($f['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php
}

/** Bootstrap JS + auto-hide for success banners. Pages add their own <script> after this. */
function render_scripts(): void
{
    ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.querySelectorAll('.alert[data-autohide="1"]').forEach(function (el) {
        setTimeout(function () { bootstrap.Alert.getOrCreateInstance(el).close(); }, 6000);
    });
</script>
    <?php
}