<?php
declare(strict_types=1);

/**
 * Managing staff permissions (used by the admin-only permission pages).
 * CHECKING permissions lives in auth.php: can(), require_permission(), authorize().
 */

require_once __DIR__ . '/auth.php';

/** The checklist, in display order, keyed by code: id, code, category, label, description, default_for_new_staff, requires. */
function permission_catalog(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT p.id, p.code, p.category, p.label, p.description, p.default_for_new_staff, r.code AS requires
           FROM permissions p
           LEFT JOIN permissions r ON r.id = p.requires_id
          ORDER BY p.sort_order, p.id'
    )->fetchAll();
    $catalog = [];
    foreach ($rows as $row) {
        $catalog[$row['code']] = $row;
    }
    return $catalog;
}

/**
 * The given codes plus everything they need, e.g. "Stock In" also needs View Inventory,
 * View Colorways and View Shoes. Unknown codes are ignored. Returned in checklist order.
 */
function with_prerequisites(array $codes, array $catalog): array
{
    $wanted = [];
    foreach ($codes as $code) {
        $steps = 0;
        while (is_string($code) && isset($catalog[$code]) && !isset($wanted[$code]) && $steps++ < 20) {
            $wanted[$code] = true;
            $code = $catalog[$code]['requires'];
        }
    }
    return array_values(array_filter(array_keys($catalog), static fn (string $c): bool => isset($wanted[$c])));
}

/**
 * Replace a staff member's permissions with exactly $codes (plus their prerequisites), in one
 * transaction. Only the active admin may do this, and only for staff accounts.
 * Returns ['added' => codes, 'removed' => codes, 'now' => codes].
 */
function save_staff_permissions(PDO $pdo, int $staffId, array $codes, int $adminId): array
{
    $catalog = permission_catalog($pdo);
    $codes   = array_values(array_unique(array_filter($codes, 'is_string')));
    $unknown = array_diff($codes, array_keys($catalog));
    if ($unknown) {
        throw new UserError('Unknown permission: ' . implode(', ', $unknown) . '.', 'invalid');
    }
    $wanted = with_prerequisites($codes, $catalog);
    $add = $remove = [];

    $pdo->beginTransaction();
    try {
        $who = $pdo->prepare('SELECT id, role, is_active FROM users WHERE id IN (:a, :s) FOR UPDATE');
        $who->execute([':a' => $adminId, ':s' => $staffId]);
        $accounts = [];
        foreach ($who->fetchAll() as $row) {
            $accounts[(int) $row['id']] = $row;
        }
        $admin = $accounts[$adminId] ?? null;
        if ($admin === null || $admin['role'] !== 'admin' || (int) $admin['is_active'] !== 1) {
            throw new UserError('Only the administrator can change permissions.', 'forbidden');
        }
        $staff = $accounts[$staffId] ?? null;
        if ($staff === null) {
            throw new UserError('That account no longer exists.', 'missing');
        }
        if ($staff['role'] !== 'staff') {
            throw new UserError('Permissions are set for staff accounts only. The administrator always has full access.', 'not_staff');
        }

        $current = load_user_permissions($pdo, $staffId);
        $add     = array_values(array_diff($wanted, $current));
        $remove  = array_values(array_diff($current, $wanted));

        if ($remove) {
            $ids = array_map(static fn (string $c): int => (int) $catalog[$c]['id'], $remove);
            $pdo->prepare(
                'DELETE FROM user_permissions WHERE user_id = ? AND permission_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
            )->execute([$staffId, ...$ids]);
        }
        if ($add) {
            $insert = $pdo->prepare(
                'INSERT INTO user_permissions (user_id, permission_id, granted_by, granted_at) VALUES (:u, :p, :by, :t)'
            );
            foreach ($add as $code) {
                $insert->execute([':u' => $staffId, ':p' => (int) $catalog[$code]['id'], ':by' => $adminId, ':t' => time()]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['added' => $add, 'removed' => $remove, 'now' => $wanted];
}

/** Give a NEW staff account the view-only starter set. Call inside the transaction that creates the account. */
function grant_default_permissions(PDO $pdo, int $staffId, ?int $adminId): int
{
    $st = $pdo->prepare(
        'INSERT INTO user_permissions (user_id, permission_id, granted_by, granted_at)
         SELECT :u, p.id, :by, :t FROM permissions p
          WHERE p.default_for_new_staff = 1
             ON DUPLICATE KEY UPDATE user_id = user_id'
    );
    $st->execute([':u' => $staffId, ':by' => $adminId, ':t' => time()]);
    return $st->rowCount();
}
