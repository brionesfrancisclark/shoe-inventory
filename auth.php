<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

// Browser protections on every page (the Apache config only sets these for another site folder):
// other websites may not show these pages inside a frame (clickjacking), the browser may not guess
// file types, and full page addresses are not sent to other sites.
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

/**
 * Roles
 *   admin : everything, always (there is exactly one admin account)
 *   staff : only what the admin ticked for that person (tables permissions / user_permissions)
 */
const ROLES        = ['admin' => 'Admin', 'staff' => 'Staff'];
const IDLE_TIMEOUT = 3600;   // seconds of inactivity before auto sign-out
const MAX_FAILED   = 5;      // wrong passwords before a temporary lock
const LOCK_SECONDS = 300;    // lock length (5 minutes)
const APP_PAGES    = ['admin.php', 'add-shoe.php', 'users.php', 'user-permissions.php', 'inventory-history.php', 'shoe.php', 'colorway.php'];

/** An error whose message is written for the person using the app, so it is safe to show on screen. */
class UserError extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = '')
    {
        parent::__construct($message);
    }
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? null) === 'admin';
}

function sign_in(array $row): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'           => (int) $row['id'],
        'username'     => $row['username'],
        'display_name' => $row['display_name'],
        'role'         => $row['role'],
    ];
    $_SESSION['last_seen'] = time();
}

function sign_out(): void
{
    unset($_SESSION['user'], $_SESSION['last_seen']);
    session_regenerate_id(true);
}

/**
 * Gate a page behind login. Re-reads the account from the database on every
 * request, so a deactivated account or changed role takes effect immediately.
 */
function require_login(): array
{
    global $pdo;

    $u = current_user();
    if ($u === null) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $_SESSION['return_to'] = basename($_SERVER['SCRIPT_NAME']);
        }
        redirect('login.php');
    }

    if (time() - (int) ($_SESSION['last_seen'] ?? 0) > IDLE_TIMEOUT) {
        sign_out();
        flash('warning', 'You were signed out after a period of inactivity. Please sign in again.');
        redirect('login.php');
    }

    $st = $pdo->prepare('SELECT id, username, display_name, role, is_active FROM users WHERE id = :id');
    $st->execute([':id' => $u['id']]);
    $row = $st->fetch();
    if (!$row || (int) $row['is_active'] !== 1) {
        sign_out();
        flash('danger', 'Your account is no longer active. Please contact an administrator.');
        redirect('login.php');
    }

    $_SESSION['user'] = [
        'id'           => (int) $row['id'],
        'username'     => $row['username'],
        'display_name' => $row['display_name'],
        'role'         => $row['role'],
    ];
    $_SESSION['last_seen'] = time();

    // Staff permissions are read fresh on every request, so ticking or unticking a box
    // takes effect on the person's very next click. The admin needs no list.
    permission_cache($row['role'] === 'staff' ? load_user_permissions($pdo, (int) $row['id']) : []);

    return $_SESSION['user'];
}

/** Like require_login(), but only for the listed roles (in practice: admin-only pages). */
function require_role(string ...$roles): array
{
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        deny_page('Only the administrator can open this page.', 'the ' . implode('/', $roles) . ' role');
    }
    return $u;
}

/* ---------------------------------------------------------------------------------------
   Staff permissions
   Pages:   $user = require_permission('inventory.view');   // sign-in + permission
   Actions: authorize('stock.in');                          // inside a page that already signed in
   Views:   if (can('stock.in')) { ...show the button... }  // hiding is cosmetic; the two calls
                                                            // above are what actually protect
   --------------------------------------------------------------------------------------- */

/** Permission codes of the signed-in staff member for this request (set by require_login()). */
function permission_cache(?array $codes = null): array
{
    static $cache = [];
    if ($codes !== null) {
        $cache = array_values(array_unique(array_filter($codes, 'is_string')));
    }
    return $cache;
}

function load_user_permissions(PDO $pdo, int $userId): array
{
    $st = $pdo->prepare(
        'SELECT p.code FROM user_permissions up JOIN permissions p ON p.id = up.permission_id
          WHERE up.user_id = :u ORDER BY p.sort_order'
    );
    $st->execute([':u' => $userId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** May the signed-in user do this? The admin: always. Staff: only with that permission ticked. */
function can(string $code): bool
{
    $u = current_user();
    if ($u === null) {
        return false;
    }
    if (($u['role'] ?? '') === 'admin') {
        return true;
    }
    return in_array($code, permission_cache(), true);
}

/** The first page the signed-in user may open: the inventory, else Add Shoe, else Shoes (else the inventory, which explains what is missing). */
function home_page(): string
{
    if (can('inventory.view')) {
        return 'admin.php';
    }
    if (can('shoes.add') || can('colorways.add')) {
        return 'add-shoe.php';
    }
    if (can('shoes.view')) {
        return 'shoe.php';
    }
    return 'admin.php';
}

/** Gate a page: signed in AND holding every listed permission, otherwise a 403 page. */
function require_permission(string ...$codes): array
{
    $u = require_login();
    authorize(...$codes);
    return $u;
}

/** Gate a page where any ONE of the listed permissions is enough. */
function require_any_permission(string ...$codes): array
{
    $u = require_login();
    foreach ($codes as $code) {
        if (can($code)) {
            return $u;
        }
    }
    deny_access($codes[0] ?? '');
}

/** Check permissions for an action (the page has already called require_login()). */
function authorize(string ...$codes): void
{
    foreach ($codes as $code) {
        if (!can($code)) {
            deny_access($code);
        }
    }
}

function permission_label(PDO $pdo, string $code): string
{
    try {
        $st = $pdo->prepare('SELECT label FROM permissions WHERE code = :c');
        $st->execute([':c' => $code]);
        $label = $st->fetchColumn();
    } catch (PDOException $ex) {
        $label = false;
    }
    return is_string($label) ? $label : $code;
}

/** Stop with a "403 Access denied" page naming the missing permission. */
function deny_access(string $code): never
{
    global $pdo;
    deny_page('Your account does not have the "' . permission_label($pdo, $code) . '" permission. Ask the administrator if you need it.',
              '"' . $code . '"');
}

/** Stop with a "403 Access denied" page. Never redirects, so it can never cause a redirect loop. */
function deny_page(string $message, string $needs): never
{
    $u = current_user();
    error_log(sprintf(
        'Access denied: user #%d needs %s for %s %s',
        (int) ($u['id'] ?? 0), $needs, $_SERVER['REQUEST_METHOD'] ?? 'CLI', $_SERVER['SCRIPT_NAME'] ?? ''
    ));
    http_response_code(403);

    if (!function_exists('render_header')) {      // auth.php used without layout.php
        echo $message;
        exit;
    }
    render_header('Access denied', '');
    ?>
<main id="main" class="container pb-5" style="max-width: 640px;">
    <div class="card shadow-sm"><div class="card-body p-4 text-center">
        <span class="role-icon mb-3" aria-hidden="true"><i class="bi bi-shield-lock"></i></span>
        <h1 class="h4 mb-2">Access denied</h1>
        <p class="mb-4" data-denied="<?= e($needs) ?>"><?= e($message) ?></p>
        <?php if (can('inventory.view')): ?>
            <a href="admin.php" class="btn btn-primary">Back to inventory</a>
        <?php elseif (can('shoes.view')): ?>
            <a href="shoe.php" class="btn btn-primary">Browse shoes</a>
        <?php endif; ?>
    </div></div>
</main>
    <?php
    render_scripts();
    echo "</body>\n</html>\n";
    exit;
}

function validate_username(string $u): ?string
{
    return preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $u)
        ? null
        : 'Username must be 3-30 characters: letters, numbers, dot, dash or underscore.';
}

function validate_password(string $p): ?string
{
    if (strlen($p) < 8)  return 'Password must be at least 8 characters.';
    if (strlen($p) > 72) return 'Password must be 72 characters or fewer.';
    return null;
}
