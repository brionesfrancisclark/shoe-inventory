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
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>
        /* Light / dark mode.
           The choice is saved in this browser (localStorage "df-theme"). With no saved choice the
           computer's own setting is used. This runs before the page is drawn, so there is no white
           flash in dark mode. Any button with data-theme-toggle switches the mode. */
        (function () {
            var KEY = 'df-theme', root = document.documentElement;
            var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

            function saved() {
                try { return localStorage.getItem(KEY); } catch (e) { return null; }
            }
            function pick() {
                var t = saved();
                return t === 'dark' || t === 'light' ? t : (media && media.matches ? 'dark' : 'light');
            }
            function apply(t) {
                root.setAttribute('data-bs-theme', t);
                document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
                    b.setAttribute('aria-pressed', t === 'dark' ? 'true' : 'false');
                    b.title = t === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
                });
            }

            apply(pick());
            document.addEventListener('DOMContentLoaded', function () { apply(pick()); });
            document.addEventListener('click', function (e) {
                if (!e.target.closest || !e.target.closest('[data-theme-toggle]')) {
                    return;
                }
                var t = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
                try { localStorage.setItem(KEY, t); } catch (err) { /* private window: still switches for this page */ }
                apply(t);
            });
            if (media && media.addEventListener) {
                media.addEventListener('change', function () { if (!saved()) { apply(pick()); } });
            }
            window.addEventListener('storage', function (e) { if (e.key === KEY) { apply(pick()); } });   // other open tabs follow
        })();
    </script>
    <title><?= e($title) ?> | Dazzling Footwear</title>
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        /* ============================================================
           Dazzling Footwear - shared theme
           Minimal black & white, bold type, pill buttons, soft cards.
           Everything below only re-skins Bootstrap 5; no behaviour here.

           Light / dark mode: <html data-bs-theme="light"> or "dark" (set by the script above).
           Every colour is one of the tokens below - light values in :root, dark values in
           [data-bs-theme="dark"]. Page styles use var(--ink), var(--surface) ... instead of
           fixed colours, so they switch with the mode automatically.
           ============================================================ */

        /* ---------- Colour tokens: light (default) ---------- */
        :root {
            /* Text, and the black "ink" of primary buttons and selected items */
            --ink: #111111;          --ink-rgb: 17, 17, 17;
            --ink-2: #707072;        --ink-2-rgb: 112, 112, 114;    /* secondary text */
            --ink-3: #b5b5b7;                                       /* faint / disabled text */
            --ink-active: #39393b;                                  /* primary button while pressed */
            --ink-disabled: #cacacb;
            --placeholder: #a0a0a2;
            --on-ink: #ffffff;       --on-ink-rgb: 255, 255, 255;   /* text on an --ink background */
            --on-ink-2: #dddddd;
            --on-ink-disabled: #ffffff;

            /* Backgrounds */
            --soft: #f5f5f5;         --soft-rgb: 245, 245, 245;     /* page and cards */
            --surface: #ffffff;                                     /* navbar, card headers, chips, panels */
            --surface-2: #f7f7f7;                                   /* size tiles */
            --hover: #fafafa;
            --pressed: #ebebeb;
            --pressed-2: #e0e0e0;
            --chip: #f0f0f0;
            --modal-bg: #f5f5f5;
            --photo-bg: #f5f5f5;     /* behind shoe photos; stays light in dark mode because the photos have white backgrounds */
            --photo-bg-2: #ffffff;
            --photo-icon: #b5b5b7;   /* "no photo" icon */

            /* Lines */
            --line: #e5e5e5;
            --line-2: #ececec;       /* card borders */
            --line-3: #f0f0f0;       /* dividers inside cards */
            --line-strong: #d5d5d5;
            --field: #cacacb;        /* inputs and outline buttons */
            --field-2: #8f8f91;      /* empty checkboxes */
            --shadow-rgb: 17, 17, 17;

            /* Status colours: ok = in stock / saved, bad = out of stock / error, warn = low stock / changed */
            --ok-bg: #e6f4ec;   --ok-text: #0b6b3a;   --ok-alert: #0b5b36;   --ok-line: #1a9b5c;
            --bad-bg: #fdecea;  --bad-text: #b3301a;  --bad-alert: #8f2a16;  --bad-line: #d43f21;
            --bad-strong: #c2371b;  --bad-solid: #c2371b;  --bad-btn: #d43f21;
            --bad-border: #f3b7ac;  --bad-border-2: #f0c4bb;  --bad-faint: #e0b0a6;  --bad-faint-line: #f3dcd6;
            --danger-rgb: 212, 63, 33;
            --warn-bg: #fff6dc;  --warn-bg-2: #fff4d6;  --warn-bg-3: #fffbea;
            --warn-text: #8a6500;  --warn-alert: #6e4d00;  --warn-num: #b58100;  --on-warn: #ffffff;
            --warn-border: #f2d27a;  --warn-line: #e0a800;  --warn-edit: #f0b400;  --warn-edit-rgb: 240, 180, 0;
            --info-bg: #eceef0;  --info-text: #2f3338;  --info-line: #707072;
            --badge-2-text: #444444;
        }

        /* ---------- Colour tokens: dark ---------- */
        [data-bs-theme="dark"] {
            --ink: #f2f2f2;          --ink-rgb: 242, 242, 242;
            --ink-2: #a1a1a6;        --ink-2-rgb: 161, 161, 166;
            --ink-3: #6b6b70;
            --ink-active: #d0d0d3;
            --ink-disabled: #3a3a3f;
            --placeholder: #77777c;
            --on-ink: #111111;       --on-ink-rgb: 17, 17, 17;
            --on-ink-2: #444444;
            --on-ink-disabled: #85858a;

            --soft: #121214;         --soft-rgb: 18, 18, 20;
            --surface: #1c1c1f;
            --surface-2: #202024;
            --hover: #19191c;
            --pressed: #2a2a2e;
            --pressed-2: #333338;
            --chip: #26262a;
            --modal-bg: #1c1c1f;
            /* --photo-bg / --photo-bg-2 / --photo-icon stay as in light mode */

            --line: #2e2e33;
            --line-2: #2a2a2e;
            --line-3: #232327;
            --line-strong: #3e3e43;
            --field: #48484e;
            --field-2: #7a7a80;
            --shadow-rgb: 0, 0, 0;

            --ok-bg: #0f2a1c;   --ok-text: #6fd39f;   --ok-alert: #8fdcb3;   --ok-line: #2fae6c;
            --bad-bg: #34150f;  --bad-text: #ff9a85;  --bad-alert: #ffb3a3;  --bad-line: #e2583b;
            --bad-strong: #ff8a72;  --bad-solid: #d9482b;  --bad-btn: #ff8a72;
            --bad-border: #6e2b1f;  --bad-border-2: #6e2b1f;  --bad-faint: #7a4036;  --bad-faint-line: #3d1d17;
            --danger-rgb: 255, 128, 104;
            --warn-bg: #2e2508;  --warn-bg-2: #2e2508;  --warn-bg-3: #2a2209;
            --warn-text: #f0c75a;  --warn-alert: #f5d77f;  --warn-num: #f0c75a;  --on-warn: #111111;
            --warn-border: #6a5414;  --warn-line: #e0a800;  --warn-edit: #e0a800;  --warn-edit-rgb: 224, 168, 0;
            --info-bg: #222428;  --info-text: #c9cdd2;  --info-line: #8a8a90;
            --badge-2-text: #c8c8cc;

            /* Bootstrap's own dark greys are bluish; keep them neutral */
            --bs-body-color-rgb: 242, 242, 242;
            --bs-emphasis-color: #f2f2f2;  --bs-emphasis-color-rgb: 242, 242, 242;
            --bs-secondary-bg: #26262a;    --bs-secondary-bg-rgb: 38, 38, 42;
            --bs-tertiary-bg: #1c1c1f;     --bs-tertiary-bg-rgb: 28, 28, 31;
            --bs-success-rgb: 111, 211, 159;
        }

        /* ---------- Bootstrap reads the tokens (both modes) ---------- */
        :root, [data-bs-theme] {
            --bs-body-font-family: "Helvetica Neue", Helvetica, Arial, "Segoe UI", sans-serif;
            --bs-body-color: var(--ink);
            --bs-body-bg: var(--soft);
            --bs-primary: var(--ink);        --bs-primary-rgb: var(--ink-rgb);
            --bs-secondary: var(--ink-2);    --bs-secondary-rgb: var(--ink-2-rgb);
            --bs-secondary-color: var(--ink-2);
            --bs-light-rgb: var(--soft-rgb);
            --bs-danger-rgb: var(--danger-rgb);
            --bs-link-color-rgb: var(--ink-rgb);
            --bs-link-hover-color-rgb: var(--ink-2-rgb);
            --bs-border-color: var(--line);
            --bs-border-radius: 10px;
            --bs-border-radius-sm: 8px;
            --bs-border-radius-lg: 16px;
            --bs-border-radius-xl: 20px;
            --bs-box-shadow-sm: 0 1px 2px rgba(var(--shadow-rgb), .05), 0 6px 18px rgba(var(--shadow-rgb), .04);
        }

        /* Bootstrap helpers the pages use that are always light: follow the mode instead */
        [data-bs-theme="dark"] .bg-white { background-color: var(--surface) !important; }
        [data-bs-theme="dark"] .text-dark,
        [data-bs-theme="dark"] .text-bg-light { color: var(--ink) !important; }
        [data-bs-theme="dark"] .table-light { --bs-table-color: var(--ink); --bs-table-bg: var(--surface); --bs-table-border-color: var(--line); }

        html { scroll-padding-top: 5.5rem; }      /* keeps scrolled-to content clear of the sticky navbar */
        body { background: var(--soft); -webkit-font-smoothing: antialiased; }
        :focus-visible { outline: 2px solid var(--ink); outline-offset: 2px; }
        h1, h2, h3, h4, h5, h6 { font-weight: 800; letter-spacing: -.02em; }
        .form-check-input:not(:checked) { border-color: var(--field-2); }   /* empty checkboxes / radios clearly visible */
        .btn-spinner { display: none; }
        .is-loading .btn-spinner { display: inline-block; }

        /* ---------- Page headings ---------- */
        .page-title {
            font-size: clamp(1.6rem, 1.2rem + 1.6vw, 2.25rem); font-weight: 800; line-height: 1.05;
            letter-spacing: -.03em; text-transform: uppercase;
        }
        .page-sub { color: var(--ink-2); font-size: .95rem; }

        /* ---------- Navbar ---------- */
        .app-nav { background: var(--surface); border-bottom: 1px solid var(--line); padding: .4rem 0; }
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
        .app-nav .navbar-toggler { border-color: var(--field); }
        .brand-logo { height: 2.25rem; width: auto; flex-shrink: 0; }          /* the two-shoe logo (assets/logo.png) */
        .brand-logo-lg { height: 5.5rem; }
        [data-bs-theme="dark"] .brand-logo { filter: invert(1); }            /* black line art -> white */

        /* ---------- Light / dark switch (navbar; top-right corner on pages without the navbar) ---------- */
        .theme-toggle { --bs-btn-padding-x: .6rem; }
        .theme-toggle .bi { font-size: .95rem; }
        .theme-toggle .bi-sun,
        [data-bs-theme="dark"] .theme-toggle .bi-moon-stars { display: none; }
        [data-bs-theme="dark"] .theme-toggle .bi-sun { display: inline-block; }
        .theme-corner { position: fixed; top: 1rem; right: 1rem; z-index: 1030; }

        /* ---------- Cards ---------- */
        .card { border: 1px solid var(--line-2); }
        .card-header {
            background: var(--surface); padding: 1rem 1.25rem; border-bottom: 1px solid var(--line-3);
            font-weight: 700; letter-spacing: -.01em;
        }
        .card-footer { background: var(--surface); padding: 1rem 1.25rem; border-top: 1px solid var(--line-3); }

        /* ---------- Buttons (pill; black primary - white in dark mode) ---------- */
        .btn {
            --bs-btn-padding-x: 1.25rem; --bs-btn-padding-y: .55rem;
            --bs-btn-font-weight: 600; --bs-btn-border-radius: 50rem;
            transition: background-color .15s ease, border-color .15s ease, color .15s ease, box-shadow .15s ease, transform .15s ease;
        }
        .btn-sm { --bs-btn-padding-x: .9rem; --bs-btn-padding-y: .35rem; --bs-btn-font-size: .8rem; --bs-btn-border-radius: 50rem; }
        .btn-primary, .btn-success {
            --bs-btn-color: var(--on-ink); --bs-btn-bg: var(--ink); --bs-btn-border-color: var(--ink);
            --bs-btn-hover-color: var(--on-ink); --bs-btn-hover-bg: var(--ink-2); --bs-btn-hover-border-color: var(--ink-2);
            --bs-btn-active-color: var(--on-ink); --bs-btn-active-bg: var(--ink-active); --bs-btn-active-border-color: var(--ink-active);
            --bs-btn-disabled-color: var(--on-ink-disabled); --bs-btn-disabled-bg: var(--ink-disabled); --bs-btn-disabled-border-color: var(--ink-disabled);
            --bs-btn-focus-shadow-rgb: var(--ink-rgb);
        }
        .btn-outline-primary, .btn-outline-secondary, .btn-outline-success, .btn-outline-dark {
            --bs-btn-color: var(--ink); --bs-btn-border-color: var(--field);
            --bs-btn-hover-color: var(--ink); --bs-btn-hover-bg: var(--surface); --bs-btn-hover-border-color: var(--ink);
            --bs-btn-active-color: var(--ink); --bs-btn-active-bg: var(--soft); --bs-btn-active-border-color: var(--ink);
            --bs-btn-disabled-color: var(--ink-3); --bs-btn-disabled-border-color: var(--line);
            --bs-btn-focus-shadow-rgb: var(--ink-rgb);
        }
        .btn-outline-danger {
            --bs-btn-color: var(--bad-btn); --bs-btn-border-color: var(--bad-border-2);
            --bs-btn-hover-color: #fff; --bs-btn-hover-bg: #d43f21; --bs-btn-hover-border-color: #d43f21;
            --bs-btn-active-color: #fff; --bs-btn-active-bg: #b3301a; --bs-btn-active-border-color: #b3301a;
            --bs-btn-disabled-color: var(--bad-faint); --bs-btn-disabled-border-color: var(--bad-faint-line);
            --bs-btn-focus-shadow-rgb: 212, 63, 33;
        }
        .btn-danger {
            --bs-btn-bg: #d43f21; --bs-btn-border-color: #d43f21;
            --bs-btn-hover-bg: #b3301a; --bs-btn-hover-border-color: #b3301a;
            --bs-btn-active-bg: #9c2b17; --bs-btn-active-border-color: #9c2b17;
            --bs-btn-focus-shadow-rgb: 212, 63, 33;
        }
        .btn-light {
            --bs-btn-bg: var(--soft); --bs-btn-border-color: var(--line); --bs-btn-color: var(--ink);
            --bs-btn-hover-bg: var(--pressed); --bs-btn-hover-border-color: var(--field); --bs-btn-hover-color: var(--ink);
            --bs-btn-active-bg: var(--pressed-2); --bs-btn-active-border-color: var(--field); --bs-btn-active-color: var(--ink);
        }
        .btn-link { --bs-btn-color: var(--ink-2); --bs-btn-hover-color: var(--ink); --bs-btn-active-color: var(--ink); }
        .input-group > .btn { --bs-btn-border-radius: var(--bs-border-radius); }
        .input-group-sm > .btn { --bs-btn-border-radius: var(--bs-border-radius-sm); }

        /* ---------- Forms ---------- */
        .form-label { margin-bottom: .35rem; font-size: .8rem; font-weight: 600; }
        .form-control, .form-select { padding: .6rem .85rem; border-color: var(--field); }
        .form-control-sm, .form-select-sm { padding: .35rem .65rem; }
        .form-control:focus, .form-select:focus { border-color: var(--ink); box-shadow: 0 0 0 .2rem rgba(var(--ink-rgb), .12); }
        .form-control::placeholder { color: var(--placeholder); }
        .form-text { color: var(--ink-2); }
        .input-group-text { background: var(--soft); border-color: var(--field); color: var(--ink-2); font-weight: 600; }
        .form-check-input:checked { background-color: var(--ink); border-color: var(--ink); }
        .form-check-input:focus { border-color: var(--ink); box-shadow: 0 0 0 .2rem rgba(var(--ink-rgb), .12); }
        /* Dark mode: a ticked box is white, so its tick / dot / switch knob is drawn black */
        [data-bs-theme="dark"] .form-check-input:checked[type="checkbox"] {
            --bs-form-check-bg-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'%3e%3cpath fill='none' stroke='%23111' stroke-linecap='round' stroke-linejoin='round' stroke-width='3' d='m6 10 3 3 6-6'/%3e%3c/svg%3e");
        }
        [data-bs-theme="dark"] .form-check-input:checked[type="radio"] {
            --bs-form-check-bg-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='-4 -4 8 8'%3e%3ccircle r='2' fill='%23111'/%3e%3c/svg%3e");
        }
        [data-bs-theme="dark"] .form-switch .form-check-input:checked {
            --bs-form-switch-bg: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='-4 -4 8 8'%3e%3ccircle r='3' fill='%23111'/%3e%3c/svg%3e");
        }

        /* ---------- Data tables ---------- */
        .table-grid > :not(caption) > * > * { padding: 1.1rem 1.25rem; border-bottom-color: var(--line-3); }
        .table-grid thead th {
            background: var(--surface); color: var(--ink-2); font-size: .72rem; font-weight: 700;
            letter-spacing: .08em; text-transform: uppercase; border-bottom: 2px solid var(--ink);
        }
        .table-grid tbody tr:not(.collapse):hover > * { --bs-table-bg-state: var(--hover); }
        @media (max-width: 767.98px) {
            .table-grid > :not(caption) > * > * { padding: .8rem; }
        }

        /* ---------- Badges ---------- */
        .badge { padding: .45em .8em; border-radius: 50rem; font-weight: 700; letter-spacing: .02em; }
        .badge.text-bg-success   { background-color: var(--ok-bg) !important; color: var(--ok-text) !important; }
        .badge.text-bg-danger    { background-color: var(--bad-bg) !important; color: var(--bad-text) !important; }
        .badge.text-bg-secondary { background-color: var(--line-2) !important; color: var(--badge-2-text) !important; }
        .badge.text-bg-primary   { color: var(--on-ink) !important; }                                   /* "Admin": black, or white in dark mode */
        .badge.badge-low         { background-color: var(--warn-bg) !important; color: var(--warn-text) !important; }   /* "2 low" */

        /* ---------- Alerts (flash messages) ---------- */
        .alert { border: 0; border-left: 4px solid transparent; border-radius: var(--bs-border-radius); font-weight: 500; }
        .alert-success { background: var(--ok-bg); color: var(--ok-alert); border-left-color: var(--ok-line); }
        .alert-danger  { background: var(--bad-bg); color: var(--bad-alert); border-left-color: var(--bad-line); }
        .alert-warning { background: var(--warn-bg-2); color: var(--warn-alert); border-left-color: var(--warn-line); }
        .alert-info    { background: var(--info-bg); color: var(--info-text); border-left-color: var(--info-line); }
        .alert ul { padding-left: 1.1rem; }

        /* ---------- Modals ---------- */
        .modal { --bs-modal-bg: var(--modal-bg); }
        .modal-content { border: 0; box-shadow: 0 24px 64px rgba(var(--shadow-rgb), .22); }
        [data-bs-theme="dark"] .modal-content { border: 1px solid var(--line); }
        .modal-header, .modal-footer { border: 0; }
        .modal-header { padding: 1.5rem 1.5rem .5rem; }
        .modal-title { font-weight: 800; letter-spacing: -.02em; text-transform: uppercase; }

        /* ---------- Pagination (round, black active page) ---------- */
        .pagination {
            --bs-pagination-color: var(--ink); --bs-pagination-bg: transparent; --bs-pagination-border-width: 0;
            --bs-pagination-hover-color: var(--ink); --bs-pagination-hover-bg: var(--soft);
            --bs-pagination-focus-color: var(--ink); --bs-pagination-focus-bg: var(--soft);
            --bs-pagination-focus-box-shadow: 0 0 0 .2rem rgba(var(--ink-rgb), .15);
            --bs-pagination-active-color: var(--on-ink); --bs-pagination-active-bg: var(--ink); --bs-pagination-active-border-color: var(--ink);
            --bs-pagination-disabled-color: var(--ink-3); --bs-pagination-disabled-bg: transparent;
            gap: .25rem;
        }
        .pagination .page-item .page-link,
        .pagination .page-item:first-child .page-link,
        .pagination .page-item:last-child .page-link {
            min-width: 2.2rem; text-align: center; font-weight: 600; border-radius: 50rem;
        }

        /* ---------- Main categories: All Shoes / Women's Shoes / Men's Shoes (Shoes and Inventory pages) ---------- */
        .cat-nav { display: flex; flex-wrap: wrap; gap: .5rem; }
        .cat-pill {
            display: inline-flex; align-items: center; gap: .5rem; padding: .45rem .45rem .45rem 1.1rem;
            border: 1px solid var(--field); border-radius: 50rem; background: var(--surface); color: var(--ink);
            font-weight: 600; line-height: 1.25; text-decoration: none; white-space: nowrap;
            transition: border-color .15s ease, background-color .15s ease, color .15s ease;
        }
        .cat-pill:hover { border-color: var(--ink); color: var(--ink); }
        .cat-pill[aria-current="page"] { background: var(--ink); border-color: var(--ink); color: var(--on-ink); }
        .cat-count {
            min-width: 1.7rem; padding: .15rem .5rem; border-radius: 50rem; background: var(--soft);
            color: var(--ink-2); font-size: .72rem; font-weight: 700; text-align: center;
        }
        .cat-pill[aria-current="page"] .cat-count { background: rgba(var(--on-ink-rgb), .2); color: var(--on-ink); }
        .cat-pill.is-unset { border-color: var(--warn-border); background: var(--warn-bg); color: var(--warn-text); }      /* models with no main category yet */
        .cat-pill.is-unset:hover { border-color: var(--warn-text); }
        .cat-pill.is-unset[aria-current="page"] { background: var(--warn-text); border-color: var(--warn-text); color: var(--on-warn); }
        @media (max-width: 575.98px) {
            .cat-pill { padding: .4rem .4rem .4rem .9rem; font-size: .875rem; }
        }

        /* ---------- Add Shoe: size tiles ---------- */
        .size-tile {
            padding: .6rem .7rem .7rem; background: var(--surface-2); border: 1px solid var(--line-2); border-radius: 12px;
            transition: border-color .15s ease, background-color .15s ease;
        }
        .size-tile:hover { border-color: var(--field); }
        .size-tile:focus-within { border-color: var(--ink); background: var(--surface); }
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
<nav class="navbar navbar-expand-md app-nav sticky-top mb-4">
    <div class="container">
        <a class="navbar-brand" href="<?= e(home_page()) ?>"><img src="assets/logo.png" class="brand-logo" width="201" height="182" alt="">Dazzling Footwear</a>
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
                <?php render_theme_toggle(); ?>
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
<?php else: ?>
<div class="theme-corner"><?php render_theme_toggle(); ?></div>
<?php endif;
}

/** The light / dark mode button. The script in render_header() makes every [data-theme-toggle] work. */
function render_theme_toggle(): void
{
    ?>
    <button type="button" class="btn btn-outline-secondary btn-sm theme-toggle" data-theme-toggle
            aria-pressed="false" title="Switch to dark mode">
        <i class="bi bi-moon-stars" aria-hidden="true"></i><i class="bi bi-sun" aria-hidden="true"></i>
        <span class="visually-hidden">Dark mode</span>
    </button>
    <?php
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
