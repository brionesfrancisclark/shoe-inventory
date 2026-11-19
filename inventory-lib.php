<?php
declare(strict_types=1);

/**
 * Inventory helpers: shoe models, colorways, sizes, stock changes and stock history.
 *
 *  - Every function gets the PDO connection passed in and never prints anything.
 *  - Problems the user can fix are thrown as UserError (message safe to show).
 *    Database errors stay PDOException: the page logs them and shows a generic message.
 *  - Functions that change data must run inside the caller's transaction, so a whole form
 *    (model + colorways + sizes + history) is saved completely or not at all.
 *  - Every stock change writes a stock_movements row: who, when, what, before and after.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/image-lib.php';

const STANDARD_SIZES         = [7.0, 7.5, 8.0, 8.5, 9.0, 9.5, 10.0, 10.5, 11.0];
const LOW_STOCK_LIMIT        = 3;        // 1..3 pairs = low stock, 0 = out of stock
const MAX_QTY                = 100000;   // most pairs one size can hold
const MAX_SIZES_PER_COLORWAY = 40;
const MAX_COLORWAYS_PER_FORM = 20;       // PHP accepts at most 20 files per request (max_file_uploads)

/**
 * The two main product categories: the code stored in shoes.main_category => the name people see.
 * The free-text category (Running, Basketball, Casual, ...) is separate and stays as it is.
 * Models from before migration 002 can have no main category (NULL) until someone picks one.
 */
const MAIN_CATEGORIES = ['women' => "Women's Shoes", 'men' => "Men's Shoes"];

/** "Women's Shoes" / "Men's Shoes" for a stored code; '' when the model has none yet. */
function main_category_label(?string $code): string
{
    return MAIN_CATEGORIES[(string) $code] ?? '';
}

/** A main category code from the address bar: 'women', 'men', or '' (anything else = All Shoes). */
function main_category_param(mixed $raw): string
{
    return is_string($raw) && isset(MAIN_CATEGORIES[$raw]) ? $raw : '';
}

/* =======================================================================================
   Text and number input
   ======================================================================================= */

/** A submitted value as text; '' for missing values and for non-text values such as arrays. */
function form_str(array $source, string $key): string
{
    $value = $source[$key] ?? '';
    return is_string($value) ? $value : '';
}

/** Trim, and squeeze runs of spaces / tabs / line breaks / other Unicode spaces into one space. */
function clean_text(string $s): string
{
    $out = preg_replace('/[\s\p{Z}]+/u', ' ', $s);
    return $out === null ? $s : trim($out);   // invalid UTF-8 is left as it is; check_label() rejects it
}

/** How names are compared: capitals and extra spaces ignored (same rule as the database keys). */
function match_key(string $s): string
{
    return mb_strtolower(clean_text($s), 'UTF-8');
}

/** Problem with a required one-line text value (already cleaned), or null if it is fine. */
function check_label(string $value, string $what, int $max): ?string
{
    if (!mb_check_encoding($value, 'UTF-8') || preg_match('/\p{C}/u', $value)) {
        return $what . ' contains characters that are not allowed.';   // control / invisible characters
    }
    if ($value === '') {
        return $what . ' is required.';
    }
    if (mb_strlen($value) > $max) {
        return $what . ' must be ' . $max . ' characters or fewer.';
    }
    return null;
}

/** Pairs: digits only, 0 to 100,000. Negative numbers, decimals, "1e3", "+5" etc. -> null. */
function parse_qty(string $raw): ?int
{
    $raw = trim($raw);
    if (!preg_match('/^[0-9]{1,6}$/', $raw)) {
        return null;
    }
    $n = (int) $raw;
    return $n <= MAX_QTY ? $n : null;
}

/** US size from 1 to 20 in half sizes -> "8.5" / "9.0"; anything else -> null. */
function parse_size(string $raw): ?string
{
    $raw = trim($raw);
    if (!preg_match('/^[0-9]{1,2}(\.[05]0?)?$/', $raw)) {
        return null;
    }
    $n = (float) $raw;
    return $n >= 1 && $n <= 20 ? number_format($n, 1, '.', '') : null;
}

/** Price from 0 to 9,999,999.99 with at most 2 decimals -> "5500.00"; anything else -> null. */
function parse_price(string $raw): ?string
{
    $raw = trim($raw);
    if (!preg_match('/^[0-9]{1,7}(\.[0-9]{1,2})?$/', $raw)) {
        return null;
    }
    return number_format((float) $raw, 2, '.', '');
}

/** 'out' (0 pairs), 'low' (1-3) or 'ok'. */
function stock_status(int $qty): string
{
    return $qty === 0 ? 'out' : ($qty <= LOW_STOCK_LIMIT ? 'low' : 'ok');
}

/* =======================================================================================
   Validating form input (nothing is saved here)
   ======================================================================================= */

/**
 * Shoe model fields -> [clean values, errors by field]. Price comes back as "5500.00".
 * main_category must be one of MAIN_CATEGORIES ('women' or 'men'): every saved model gets one.
 */
function validate_shoe_fields(array $in): array
{
    $v = [
        'name'          => clean_text(form_str($in, 'name')),
        'brand'         => clean_text(form_str($in, 'brand')),
        'main_category' => form_str($in, 'main_category'),
        'category'      => clean_text(form_str($in, 'category')),
        'price'         => trim(form_str($in, 'price')),
    ];
    $errors = [];
    foreach (['name' => ['Shoe name', 150], 'brand' => ['Brand', 100], 'category' => ['Category', 100]] as $field => [$what, $max]) {
        if (($msg = check_label($v[$field], $what, $max)) !== null) {
            $errors[$field] = $msg;
        }
    }
    if (!isset(MAIN_CATEGORIES[$v['main_category']])) {
        $errors['main_category'] = "Choose the main category: Women's Shoes or Men's Shoes.";
    }
    $price = parse_price($v['price']);
    if ($price === null) {
        $errors['price'] = 'Price must be a number from 0 to 9,999,999.99 (at most 2 decimals).';
    } else {
        $v['price'] = $price;
    }
    return [$v, $errors];
}

/**
 * Size rows [['size' => '8.5', 'qty' => '10'], ...] -> [['8.5' => 10, ...], list of errors].
 * Rows with both fields empty are skipped. A missing quantity counts as 0.
 */
function parse_size_rows(mixed $rows): array
{
    $sizes = [];
    $errors = [];
    if (!is_array($rows)) {
        return [[], []];
    }
    if (count($rows) > MAX_SIZES_PER_COLORWAY * 2) {
        return [[], ['Too many size rows were sent.']];
    }
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rawSize = trim(form_str($row, 'size'));
        $rawQty  = trim(form_str($row, 'qty'));
        if ($rawSize === '' && $rawQty === '') {
            continue;
        }
        $size = parse_size($rawSize);
        if ($size === null) {
            $errors[] = 'Size "' . mb_substr($rawSize, 0, 12) . '" is not valid. Use US sizes 1 to 20 in half sizes, e.g. 8.5.';
            continue;
        }
        $qty = $rawQty === '' ? 0 : parse_qty($rawQty);
        if ($qty === null) {
            $errors[] = 'Quantity for US ' . $size . ' must be a whole number from 0 to ' . number_format(MAX_QTY) . '.';
            continue;
        }
        if (isset($sizes[$size])) {
            $errors[] = 'US ' . $size . ' was entered more than once.';
            continue;
        }
        $sizes[$size] = $qty;
    }
    if (count($sizes) > MAX_SIZES_PER_COLORWAY) {
        $errors[] = 'A colorway can have at most ' . MAX_SIZES_PER_COLORWAY . ' sizes.';
    }
    return [$sizes, $errors];
}

/**
 * One colorway card -> [clean values, errors by field]. 'sizes' errors are a list.
 * The uploaded file itself is checked separately (image-lib.php).
 */
function validate_colorway_fields(array $in, bool $requireSizes = true): array
{
    $v = [
        'name'        => clean_text(form_str($in, 'name')),
        'description' => clean_text(form_str($in, 'description')),
        'image_url'   => trim(form_str($in, 'image_url')),
        'sizes'       => [],
    ];
    $errors = [];
    if (($msg = check_label($v['name'], 'Colorway name', 80)) !== null) {
        $errors['name'] = $msg;
    }
    if ($v['description'] !== '' && ($msg = check_label($v['description'], 'Description', 255)) !== null) {
        $errors['description'] = $msg;
    }
    if ($v['image_url'] !== '' && ($msg = image_url_problem($v['image_url'])) !== null) {
        $errors['image_url'] = $msg;
    }
    [$v['sizes'], $sizeErrors] = parse_size_rows($in['sizes'] ?? []);
    if ($sizeErrors) {
        $errors['sizes'] = $sizeErrors;
    } elseif ($requireSizes && !$v['sizes']) {
        $errors['sizes'] = ['Add at least one size.'];
    }
    return [$v, $errors];
}

/** Entries that are the same name (ignoring capitals / extra spaces): [key => [index, index, ...]]. */
function duplicate_names(array $names): array
{
    $groups = [];
    foreach ($names as $i => $name) {
        $key = match_key((string) $name);
        if ($key !== '') {
            $groups[$key][] = $i;
        }
    }
    return array_filter($groups, static fn (array $indexes): bool => count($indexes) > 1);
}

/** Fields where an existing model differs from what was typed: ['category' => [old, new], ...]. */
function model_differences(array $existing, array $v): array
{
    $diff = [];
    if ((string) ($existing['main_category'] ?? '') !== $v['main_category']) {     // '' = the model has none yet
        $diff['main_category'] = [(string) ($existing['main_category'] ?? ''), $v['main_category']];
    }
    if (match_key((string) $existing['category']) !== match_key($v['category'])) {
        $diff['category'] = [(string) $existing['category'], $v['category']];
    }
    if (number_format((float) $existing['price'], 2, '.', '') !== $v['price']) {
        $diff['price'] = [number_format((float) $existing['price'], 2, '.', ''), $v['price']];
    }
    return $diff;
}

/* =======================================================================================
   Database helpers
   ======================================================================================= */

/** Undo an unfinished transaction (used in error handling; does nothing if none is open). */
function rollback_quietly(PDO $pdo): void
{
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

function require_transaction(PDO $pdo): void
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('This change must run inside a database transaction.');
    }
}

/** Was this a "duplicate entry" error on the given unique key? */
function is_duplicate_key(PDOException $e, string $key): bool
{
    return (int) ($e->errorInfo[1] ?? 0) === 1062 && str_contains((string) ($e->errorInfo[2] ?? ''), $key . "'");
}

/** Deadlock or lock wait timeout: nothing was saved, trying again usually works. */
function is_busy_error(PDOException $e): bool
{
    return in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true);
}

/**
 * Stop with a clear message when the database has no main_category column yet - the PHP files
 * were updated but migrations/002_womens_mens_categories.sql has not been run. Pages that show
 * or save the main category call this right after their sign-in check.
 */
function require_main_categories(PDO $pdo): void
{
    if ($pdo->query("SHOW COLUMNS FROM shoes LIKE 'main_category'")->fetch() !== false) {
        return;
    }
    error_log('shoes.main_category is missing: run migrations/002_womens_mens_categories.sql');
    http_response_code(500);
    exit("The database needs a small update for the Women's Shoes and Men's Shoes categories. "
       . 'Run migrations/002_womens_mens_categories.sql (see README, "Upgrading to Women\'s and Men\'s Shoes"), then reload this page.');
}

/**
 * SQL that turns a bound value into the same matching key the database stores
 * (lower case, spaces squeezed and trimmed - see brand_key / name_key in migration 001).
 */
function sql_match_key(string $param): string
{
    return "(LOWER(TRIM(REGEXP_REPLACE(CONVERT($param USING utf8mb4) COLLATE utf8mb4_general_ci,"
         . " '[[:space:]]+', ' '))) COLLATE utf8mb4_bin)";
}

/* ---------- Shoe models ---------- */

/** The model with this brand + name (capitals and extra spaces ignored), or null. $lock: inside a transaction. */
function find_shoe_model(PDO $pdo, string $brand, string $name, bool $lock = false): ?array
{
    $st = $pdo->prepare(
        'SELECT id, name, brand, main_category, category, price, image_url FROM shoes
          WHERE brand_key = ' . sql_match_key(':brand') . ' AND name_key = ' . sql_match_key(':name')
        . ($lock ? ' FOR UPDATE' : '')
    );
    $st->execute([':brand' => $brand, ':name' => $name]);
    return $st->fetch() ?: null;
}

function get_shoe_model(PDO $pdo, int $shoeId, bool $lock = false): ?array
{
    $st = $pdo->prepare(
        'SELECT id, name, brand, main_category, category, price, image_url FROM shoes WHERE id = :id' . ($lock ? ' FOR UPDATE' : '')
    );
    $st->execute([':id' => $shoeId]);
    return $st->fetch() ?: null;
}

/** Create a model from validate_shoe_fields() values. Same brand + name already there -> UserError 'duplicate_model'. */
function insert_shoe_model(PDO $pdo, array $v): int
{
    try {
        $pdo->prepare('INSERT INTO shoes (name, brand, main_category, category, price) VALUES (:n, :b, :m, :c, :p)')
            ->execute([':n' => $v['name'], ':b' => $v['brand'], ':m' => $v['main_category'], ':c' => $v['category'], ':p' => $v['price']]);
    } catch (PDOException $e) {
        if (is_duplicate_key($e, 'uq_shoe_model')) {
            throw new UserError('"' . $v['brand'] . ' ' . $v['name'] . '" already exists.', 'duplicate_model');
        }
        throw $e;
    }
    return (int) $pdo->lastInsertId();
}

/** Change a model's shared details (name, brand, main category, category, price). */
function update_shoe_model(PDO $pdo, int $shoeId, array $v): void
{
    try {
        $st = $pdo->prepare('UPDATE shoes SET name = :n, brand = :b, main_category = :m, category = :c, price = :p WHERE id = :id');
        $st->execute([':n' => $v['name'], ':b' => $v['brand'], ':m' => $v['main_category'], ':c' => $v['category'],
                      ':p' => $v['price'], ':id' => $shoeId]);
    } catch (PDOException $e) {
        if (is_duplicate_key($e, 'uq_shoe_model')) {
            throw new UserError('Another model named "' . $v['brand'] . ' ' . $v['name'] . '" already exists.', 'duplicate_model');
        }
        throw $e;
    }
}

/* ---------- Colorways ---------- */

function colorways_of(PDO $pdo, int $shoeId): array
{
    $st = $pdo->prepare(
        'SELECT id, shoe_id, name, description, image_path, image_url, sort_order
           FROM shoe_colorways WHERE shoe_id = :s ORDER BY sort_order, id'
    );
    $st->execute([':s' => $shoeId]);
    return $st->fetchAll();
}

/** A colorway - only if it belongs to that shoe model (so a forged id for another model finds nothing). */
function get_colorway(PDO $pdo, int $colorwayId, int $shoeId, bool $lock = false): ?array
{
    $st = $pdo->prepare(
        'SELECT id, shoe_id, name, description, image_path, image_url, sort_order
           FROM shoe_colorways WHERE id = :id AND shoe_id = :s' . ($lock ? ' FOR UPDATE' : '')
    );
    $st->execute([':id' => $colorwayId, ':s' => $shoeId]);
    return $st->fetch() ?: null;
}

/** The model's colorway with this name (capitals and extra spaces ignored), or null. */
function find_colorway(PDO $pdo, int $shoeId, string $name, bool $lock = false): ?array
{
    $st = $pdo->prepare(
        'SELECT id, shoe_id, name, description, image_path, image_url, sort_order FROM shoe_colorways
          WHERE shoe_id = :s AND name_key = ' . sql_match_key(':name') . ($lock ? ' FOR UPDATE' : '')
    );
    $st->execute([':s' => $shoeId, ':name' => $name]);
    return $st->fetch() ?: null;
}

/**
 * Create a colorway with its starting stock; each size is recorded as 'initial' stock.
 * $v comes from validate_colorway_fields(). An uploaded image ($imagePath) wins over a link.
 * Same name already on this model -> UserError 'duplicate_colorway'. Returns the new id.
 */
function create_colorway(PDO $pdo, int $shoeId, array $v, ?string $imagePath, int $userId): int
{
    require_transaction($pdo);
    $shoe = get_shoe_model($pdo, $shoeId, true);
    if ($shoe === null) {
        throw new UserError('That shoe model no longer exists.', 'missing');
    }
    $next = $pdo->prepare('SELECT COALESCE(MAX(sort_order) + 1, 0) FROM shoe_colorways WHERE shoe_id = :s');
    $next->execute([':s' => $shoeId]);

    try {
        $pdo->prepare(
            'INSERT INTO shoe_colorways (shoe_id, name, description, image_path, image_url, sort_order, created_at)
             VALUES (:s, :n, :d, :p, :u, :o, :t)'
        )->execute([
            ':s' => $shoeId, ':n' => $v['name'], ':d' => $v['description'] !== '' ? $v['description'] : null,
            ':p' => $imagePath, ':u' => $imagePath === null && $v['image_url'] !== '' ? $v['image_url'] : null,
            ':o' => (int) $next->fetchColumn(), ':t' => time(),
        ]);
    } catch (PDOException $e) {
        if (is_duplicate_key($e, 'uq_colorway_name')) {
            throw new UserError(
                '"' . $v['name'] . '" already exists for ' . $shoe['brand'] . ' ' . $shoe['name'] . '.',
                'duplicate_colorway'
            );
        }
        throw $e;
    }
    $colorwayId = (int) $pdo->lastInsertId();
    foreach ($v['sizes'] as $size => $qty) {
        add_size($pdo, $shoeId, $colorwayId, (string) $size, $qty, $userId);
    }
    return $colorwayId;
}

/**
 * Add Shoe: save a model together with its new colorways - completely or not at all.
 * Must run inside the caller's transaction; the caller commits, or rolls back AND calls
 * $changes->rollback() so images copied for this save are removed again.
 *   $shoe          validate_shoe_fields() values
 *   $colorways     list of ['v' => validate_colorway_fields() values, 'image_token' => staged image token or null]
 *   $existingId    the existing model the form was checked against, or null to create a new model
 *   $updateDetails true = also set the existing model's main category, category and price to $shoe's
 * Returns ['shoe_id' => int, 'created' => bool, 'brand' => ..., 'name' => ...,
 *          'colorways' => [['id', 'name', 'sizes', 'pairs'], ...]].
 */
function save_shoe_with_colorways(PDO $pdo, array $shoe, array $colorways, ?int $existingId, bool $updateDetails,
                                  int $userId, ImageChanges $changes): array
{
    require_transaction($pdo);
    if ($existingId === null) {
        // Checked again with a lock: someone may have created the same model since the form was checked.
        if (find_shoe_model($pdo, $shoe['brand'], $shoe['name'], true) !== null) {
            throw new UserError(
                '"' . $shoe['brand'] . ' ' . $shoe['name'] . '" was added by someone else a moment ago. '
                . 'Nothing was saved - check the form and save again to add your colorways to it.',
                'model_appeared'
            );
        }
        $shoeId = insert_shoe_model($pdo, $shoe);
        $model  = ['brand' => $shoe['brand'], 'name' => $shoe['name']];
    } else {
        $model = get_shoe_model($pdo, $existingId, true);
        if ($model === null) {
            throw new UserError('That shoe model was deleted a moment ago. Nothing was saved.', 'missing');
        }
        if ($updateDetails) {      // name and brand stay exactly as stored; only main category, category and price change
            update_shoe_model($pdo, $existingId, ['name' => $model['name'], 'brand' => $model['brand'],
                                                  'main_category' => $shoe['main_category'],
                                                  'category' => $shoe['category'], 'price' => $shoe['price']]);
        }
        $shoeId = $existingId;
    }

    $saved = [];
    foreach ($colorways as $colorway) {
        $path = $colorway['image_token'] !== null ? use_staged_image($colorway['image_token'], $changes) : null;
        $id   = create_colorway($pdo, $shoeId, $colorway['v'], $path, $userId);
        $saved[] = ['id' => $id, 'name' => $colorway['v']['name'], 'sizes' => count($colorway['v']['sizes']),
                    'pairs' => array_sum($colorway['v']['sizes'])];
    }
    return ['shoe_id' => $shoeId, 'created' => $existingId === null, 'brand' => $model['brand'],
            'name' => $model['name'], 'colorways' => $saved];
}

/** Rename a colorway / change its description. Name already used on this model -> UserError. */
function update_colorway_details(PDO $pdo, int $colorwayId, int $shoeId, string $name, string $description): void
{
    try {
        $st = $pdo->prepare(
            'UPDATE shoe_colorways SET name = :n, description = :d, updated_at = :t WHERE id = :id AND shoe_id = :s'
        );
        $st->execute([':n' => $name, ':d' => $description !== '' ? $description : null, ':t' => time(),
                      ':id' => $colorwayId, ':s' => $shoeId]);
    } catch (PDOException $e) {
        if (is_duplicate_key($e, 'uq_colorway_name')) {
            throw new UserError('This model already has a colorway named "' . $name . '".', 'duplicate_colorway');
        }
        throw $e;
    }
}

/**
 * Set (or remove, with two nulls) a colorway's image. Returns the previous
 * ['image_path' => ..., 'image_url' => ...] so the caller can delete the old FILE after commit.
 */
function set_colorway_image(PDO $pdo, int $colorwayId, int $shoeId, ?string $imagePath, ?string $imageUrl): array
{
    require_transaction($pdo);
    $old = get_colorway($pdo, $colorwayId, $shoeId, true);
    if ($old === null) {
        throw new UserError('That colorway no longer exists.', 'missing');
    }
    $pdo->prepare('UPDATE shoe_colorways SET image_path = :p, image_url = :u, updated_at = :t WHERE id = :id AND shoe_id = :s')
        ->execute([':p' => $imagePath, ':u' => $imagePath === null ? $imageUrl : null, ':t' => time(),
                   ':id' => $colorwayId, ':s' => $shoeId]);
    return ['image_path' => $old['image_path'], 'image_url' => $old['image_url']];
}

/** The sizes of one colorway, smallest first: [['id', 'size_number', 'quantity'], ...]. */
function colorway_sizes(PDO $pdo, int $colorwayId): array
{
    $st = $pdo->prepare('SELECT id, size_number, quantity FROM shoe_sizes WHERE colorway_id = :c ORDER BY size_number');
    $st->execute([':c' => $colorwayId]);
    return $st->fetchAll();
}

/**
 * Edit Colorway: save every change to one colorway - completely or not at all.
 * Must run inside the caller's transaction; on failure the caller rolls back AND calls $images->rollback().
 *   $changes['expected_updated'] the colorway's updated_at when the form was opened (int, 0 = never edited);
 *                                if someone else saved the colorway since, nothing is saved ('stale')
 *   $changes['details']          ['name' => ..., 'description' => ...] (clean values), or null = unchanged
 *   $changes['image']            ['path' => ?string, 'url' => ?string]: new photo (both null = remove it), or null = unchanged
 *   $changes['remove_sizes']     stock row ids to remove (only sizes with 0 pairs can be removed)
 *   $changes['add_sizes']        ['8.5' => starting pairs, ...] (from parse_size_rows())
 * The old uploaded photo FILE is deleted only after the commit ($images->commit()).
 * Returns a list of short sentences describing what changed.
 */
function edit_colorway(PDO $pdo, int $colorwayId, int $shoeId, array $changes, int $userId, ImageChanges $images): array
{
    require_transaction($pdo);
    // Same lock order as adding / deleting colorways (model first), so they cannot deadlock each other.
    if (get_shoe_model($pdo, $shoeId, true) === null) {
        throw new UserError('That shoe model no longer exists.', 'missing');
    }
    $current = get_colorway($pdo, $colorwayId, $shoeId, true);
    if ($current === null) {
        throw new UserError('This colorway was deleted by someone else, so nothing was saved.', 'missing');
    }
    $st = $pdo->prepare('SELECT COALESCE(updated_at, 0) FROM shoe_colorways WHERE id = :id');
    $st->execute([':id' => $colorwayId]);
    if (isset($changes['expected_updated']) && (int) $st->fetchColumn() !== (int) $changes['expected_updated']) {
        throw new UserError('Someone else changed this colorway while you were editing, so nothing was saved. '
                          . 'The page now shows their changes - make yours again.', 'stale');
    }

    $done = [];
    $details = $changes['details'] ?? null;
    if ($details !== null) {
        $description = (string) $details['description'];
        if ($details['name'] !== $current['name']) {
            $done[] = 'renamed from "' . $current['name'] . '" to "' . $details['name'] . '"';
        }
        if ($description !== (string) $current['description']) {
            $done[] = $description === '' ? 'description removed' : 'description changed';
        }
        if ($done) {
            update_colorway_details($pdo, $colorwayId, $shoeId, $details['name'], $description);
        }
    }

    $image = $changes['image'] ?? null;
    if ($image !== null) {
        $hadPhoto = $current['image_path'] !== null || $current['image_url'] !== null;
        $old = set_colorway_image($pdo, $colorwayId, $shoeId, $image['path'], $image['url']);
        if ($old['image_path'] !== null && $old['image_path'] !== $image['path']) {
            $images->deleteAfterCommit($old['image_path']);        // the replaced file goes only after the commit
        }
        $done[] = $image['path'] === null && $image['url'] === null ? 'photo removed' : ($hadPhoto ? 'photo replaced' : 'photo added');
    }

    $removed = [];
    foreach (array_unique(array_map('intval', $changes['remove_sizes'] ?? [])) as $sizeId) {
        $at = size_snapshot($pdo, $sizeId, $colorwayId);
        remove_size($pdo, $sizeId, $colorwayId, $userId);          // refuses sizes that still have pairs
        $removed[] = 'US ' . $at['size_number'];
    }
    if ($removed) {
        $done[] = 'removed ' . implode(', ', $removed);
    }

    $add = $changes['add_sizes'] ?? [];
    if ($add) {
        $count = $pdo->prepare('SELECT COUNT(*) FROM shoe_sizes WHERE colorway_id = :c');
        $count->execute([':c' => $colorwayId]);
        if ((int) $count->fetchColumn() + count($add) > MAX_SIZES_PER_COLORWAY) {
            throw new UserError('A colorway can have at most ' . MAX_SIZES_PER_COLORWAY . ' sizes.', 'invalid');
        }
        $added = [];
        foreach ($add as $size => $qty) {
            add_size($pdo, $shoeId, $colorwayId, (string) $size, $qty, $userId);
            $added[] = 'US ' . $size . ' (' . $qty . ' pair' . ($qty === 1 ? '' : 's') . ')';
        }
        $done[] = 'added ' . implode(', ', $added);
    }
    return $done;
}

/**
 * Delete one colorway and its stock (the model's last colorway cannot be deleted).
 * Every size is first written to the history as 'colorway_deleted', so nothing is lost.
 * Returns the colorway's uploaded image path (or null) to delete AFTER the commit.
 */
function delete_colorway(PDO $pdo, int $colorwayId, int $shoeId, int $userId): ?string
{
    require_transaction($pdo);
    // Locking the model first means two people deleting the last two colorways at the same
    // moment are handled one after the other - the second one is refused.
    if (get_shoe_model($pdo, $shoeId, true) === null) {
        throw new UserError('That shoe model no longer exists.', 'missing');
    }
    $colorway = get_colorway($pdo, $colorwayId, $shoeId, true);
    if ($colorway === null) {
        throw new UserError('That colorway no longer exists.', 'missing');
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM shoe_colorways WHERE shoe_id = :s');
    $count->execute([':s' => $shoeId]);
    if ((int) $count->fetchColumn() <= 1) {
        throw new UserError(
            '"' . $colorway['name'] . '" is the only colorway of this model. Delete the whole shoe model instead.',
            'last_colorway'
        );
    }
    record_removed_stock($pdo, 'colorway_deleted', $shoeId, $colorwayId, $userId);
    $pdo->prepare('DELETE FROM shoe_colorways WHERE id = :id AND shoe_id = :s')
        ->execute([':id' => $colorwayId, ':s' => $shoeId]);          // its stock rows go with it
    return $colorway['image_path'];
}

/**
 * Delete a whole model with all its colorways and stock; history is kept ('shoe_deleted').
 * Returns the uploaded image paths to delete AFTER the commit.
 */
function delete_shoe_model(PDO $pdo, int $shoeId, int $userId): array
{
    require_transaction($pdo);
    if (get_shoe_model($pdo, $shoeId, true) === null) {
        throw new UserError('That shoe model no longer exists.', 'missing');
    }
    $images = array_values(array_filter(array_column(colorways_of($pdo, $shoeId), 'image_path')));
    record_removed_stock($pdo, 'shoe_deleted', $shoeId, null, $userId);
    $pdo->prepare('DELETE FROM shoes WHERE id = :id')->execute([':id' => $shoeId]);   // colorways + stock go with it
    return $images;
}

/* ---------- Sizes and stock ---------- */

/** One stock row with the names needed for the history, or null if it isn't in that colorway. */
function size_snapshot(PDO $pdo, int $sizeId, int $colorwayId, bool $lock = false): ?array
{
    $st = $pdo->prepare(
        'SELECT z.id AS size_id, z.shoe_id, z.colorway_id, z.size_number, z.quantity,
                s.brand, s.name AS shoe_name, c.name AS colorway_name
           FROM shoe_sizes z
           JOIN shoe_colorways c ON c.id = z.colorway_id
           JOIN shoes s          ON s.id = z.shoe_id
          WHERE z.id = :id AND z.colorway_id = :cw' . ($lock ? ' FOR UPDATE' : '')
    );
    $st->execute([':id' => $sizeId, ':cw' => $colorwayId]);
    return $st->fetch() ?: null;
}

/** Add a size with its starting stock to a colorway; recorded as 'initial' stock. Returns the stock row id. */
function add_size(PDO $pdo, int $shoeId, int $colorwayId, string $size, int $qty, int $userId): int
{
    require_transaction($pdo);
    if (parse_size($size) !== $size || $qty < 0 || $qty > MAX_QTY) {
        throw new InvalidArgumentException('add_size() needs a size from parse_size() and a quantity from parse_qty().');
    }
    try {
        $pdo->prepare('INSERT INTO shoe_sizes (shoe_id, colorway_id, size_number, quantity) VALUES (:s, :cw, :z, :q)')
            ->execute([':s' => $shoeId, ':cw' => $colorwayId, ':z' => $size, ':q' => $qty]);
    } catch (PDOException $e) {
        if (is_duplicate_key($e, 'uq_colorway_size')) {
            throw new UserError('US ' . $size . ' is already listed for this colorway.', 'duplicate_size');
        }
        if ((int) ($e->errorInfo[1] ?? 0) === 1452) {           // colorway gone, or not of this model
            throw new UserError('That colorway no longer exists.', 'missing');
        }
        throw $e;
    }
    $sizeId = (int) $pdo->lastInsertId();
    record_movement($pdo, size_snapshot($pdo, $sizeId, $colorwayId), 'initial', 0, $qty, $userId);
    return $sizeId;
}

/**
 * Stock In / Stock Out / Stock Adjustment for one size of one colorway, written to the history.
 * The stock row is locked, so two people changing the same size at once are applied one
 * after the other: nothing is lost and stock can never go below 0.
 *   'stock_in'   : add $amount (1 or more)
 *   'stock_out'  : remove $amount (1 or more, at most what is in stock)
 *   'adjustment' : set the count to $amount. Pass $expected = the count the user was looking at;
 *                  if it changed in the meantime the adjustment is refused instead of overwriting.
 * Returns ['before' => int, 'after' => int, 'changed' => bool].
 */
function change_stock(PDO $pdo, int $sizeId, int $colorwayId, string $type, int $amount, int $userId,
                      ?int $expected = null, ?string $note = null): array
{
    require_transaction($pdo);
    if (!in_array($type, ['stock_in', 'stock_out', 'adjustment'], true)) {
        throw new InvalidArgumentException('Unknown stock change type: ' . $type);
    }
    $at = size_snapshot($pdo, $sizeId, $colorwayId, true);
    if ($at === null) {
        throw new UserError('That size is no longer part of this colorway. Reload the page and try again.', 'missing');
    }
    $before = (int) $at['quantity'];
    $label  = $at['colorway_name'] . ' US ' . $at['size_number'];

    if ($type === 'stock_in') {
        if ($amount < 1) {
            throw new UserError('Enter how many pairs of ' . $label . ' were received (1 or more).', 'invalid');
        }
        if ($before + $amount > MAX_QTY) {
            throw new UserError($label . ' cannot hold more than ' . number_format(MAX_QTY) . ' pairs.', 'invalid');
        }
        $after = $before + $amount;
    } elseif ($type === 'stock_out') {
        if ($amount < 1) {
            throw new UserError('Enter how many pairs of ' . $label . ' to remove (1 or more).', 'invalid');
        }
        if ($amount > $before) {
            throw new UserError(
                'Only ' . $before . ' pair' . ($before === 1 ? '' : 's') . ' of ' . $label . ' in stock - cannot remove ' . $amount . '.',
                'insufficient'
            );
        }
        $after = $before - $amount;
    } else {
        if ($amount < 0 || $amount > MAX_QTY) {
            throw new UserError('The count for ' . $label . ' must be from 0 to ' . number_format(MAX_QTY) . '.', 'invalid');
        }
        if ($expected !== null && $expected !== $before) {
            throw new UserError(
                $label . ' was changed to ' . $before . ' by someone else while you were editing. Check the new count and try again.',
                'stale'
            );
        }
        $after = $amount;
    }

    if ($after === $before) {
        return ['before' => $before, 'after' => $after, 'changed' => false];
    }
    $pdo->prepare('UPDATE shoe_sizes SET quantity = :q WHERE id = :id AND colorway_id = :cw')
        ->execute([':q' => $after, ':id' => $sizeId, ':cw' => $colorwayId]);
    record_movement($pdo, $at, $type, $before, $after, $userId, $note);
    return ['before' => $before, 'after' => $after, 'changed' => true];
}

/** Remove a size from a colorway - only when it has 0 pairs. The history keeps a 'size_removed' entry. */
function remove_size(PDO $pdo, int $sizeId, int $colorwayId, int $userId): void
{
    require_transaction($pdo);
    $at = size_snapshot($pdo, $sizeId, $colorwayId, true);
    if ($at === null) {
        throw new UserError('That size is no longer part of this colorway.', 'missing');
    }
    if ((int) $at['quantity'] !== 0) {
        throw new UserError(
            'US ' . $at['size_number'] . ' still has ' . $at['quantity'] . ' pair(s). Only sizes with 0 pairs can be removed.',
            'not_empty'
        );
    }
    record_movement($pdo, $at, 'size_removed', 0, 0, $userId);
    $pdo->prepare('DELETE FROM shoe_sizes WHERE id = :id AND colorway_id = :cw AND quantity = 0')
        ->execute([':id' => $sizeId, ':cw' => $colorwayId]);
}

/** Write one history row. $at = a size_snapshot() row (names are copied into the history). */
function record_movement(PDO $pdo, array $at, string $type, int $before, int $after, ?int $userId, ?string $note = null): int
{
    $pdo->prepare(
        'INSERT INTO stock_movements
                (created_at, user_id, movement_type, shoe_id, colorway_id, size_id,
                 brand, shoe_name, colorway_name, size_number, quantity_before, quantity_after, note)
         VALUES (:t, :u, :type, :shoe, :cw, :size, :brand, :sname, :cname, :sz, :before, :after, :note)'
    )->execute([
        ':t' => time(), ':u' => $userId, ':type' => $type,
        ':shoe' => (int) $at['shoe_id'], ':cw' => (int) $at['colorway_id'], ':size' => (int) $at['size_id'],
        ':brand' => $at['brand'], ':sname' => $at['shoe_name'], ':cname' => $at['colorway_name'],
        ':sz' => $at['size_number'], ':before' => $before, ':after' => $after,
        ':note' => $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null,
    ]);
    return (int) $pdo->lastInsertId();
}

/** History rows for every size of a model (or of one colorway) that is about to be deleted. */
function record_removed_stock(PDO $pdo, string $type, int $shoeId, ?int $colorwayId, int $userId): int
{
    $sql = 'SELECT z.id AS size_id, z.shoe_id, z.colorway_id, z.size_number, z.quantity,
                   s.brand, s.name AS shoe_name, c.name AS colorway_name
              FROM shoe_sizes z
              JOIN shoe_colorways c ON c.id = z.colorway_id
              JOIN shoes s          ON s.id = z.shoe_id
             WHERE z.shoe_id = :s' . ($colorwayId !== null ? ' AND z.colorway_id = :cw' : '') . '
             ORDER BY c.sort_order, z.size_number
               FOR UPDATE';
    $st = $pdo->prepare($sql);
    $st->execute($colorwayId !== null ? [':s' => $shoeId, ':cw' => $colorwayId] : [':s' => $shoeId]);
    $rows = $st->fetchAll();
    foreach ($rows as $at) {
        record_movement($pdo, $at, $type, (int) $at['quantity'], 0, $userId);
    }
    return count($rows);
}
