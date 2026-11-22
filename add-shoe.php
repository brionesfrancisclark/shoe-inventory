<?php
declare(strict_types=1);
require __DIR__ . '/layout.php';
require_once __DIR__ . '/inventory-lib.php';

/*
 * Add Shoe: one shoe model with one or more colorways, each with its own photo, sizes and stock.
 *
 * Automatic grouping: if the brand + model name already exists (capitals and extra spaces are
 * ignored), the colorways are added to THAT model - a second copy of the model is never created,
 * and its main category (Women's / Men's Shoes) / category / price only change if the user
 * explicitly chooses so.
 * Everything is saved in one transaction (model, colorways, sizes, stock history, photos):
 * completely, or not at all.
 */

$user        = require_any_permission('shoes.add', 'colorways.add');   // the admin always passes
require_main_categories($pdo);
$canNewModel = can('shoes.add');
$canAddCw    = can('colorways.add');
$canImages   = can('images.upload');
$canEditShoe = can('shoes.edit');
$canViewInv  = can('inventory.view');     // only then are links / redirects to the inventory page offered

/* ---------- Live check while typing (GET ?lookup=1): does this brand + model exist already? ---------- */
if (($_GET['lookup'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $brand = clean_text(form_str($_GET, 'brand'));
    $name  = clean_text(form_str($_GET, 'name'));
    $model = null;
    if ($brand !== '' && $name !== '' && mb_check_encoding($brand . $name, 'UTF-8')
        && mb_strlen($brand) <= 100 && mb_strlen($name) <= 150) {
        $model = find_shoe_model($pdo, $brand, $name);
    }
    echo json_encode($model === null ? ['exists' => false] : [
        'exists'    => true,
        'brand'     => $model['brand'],
        'name'      => $model['name'],
        'main'      => (string) $model['main_category'],
        'mainLabel' => main_category_label($model['main_category']),
        'category'  => $model['category'],
        'price'     => number_format((float) $model['price'], 2, '.', ''),
        'colorways' => array_column(colorways_of($pdo, (int) $model['id']), 'name'),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

/** A new, empty colorway card: every standard size ticked with 0 pairs (as the old form saved them). */
function blank_card(): array
{
    $rows = [];
    foreach (STANDARD_SIZES as $size) {
        $rows[] = ['size' => number_format($size, 1, '.', ''), 'qty' => '0'];
    }
    return ['name' => '', 'description' => '', 'image_url' => '', 'image_token' => '', 'rows' => $rows,
            'errors' => [], 'duplicate_of' => '', 'duplicate_url' => ''];
}

/** The size rows a card sent, kept as plain text so the form can be shown again exactly as typed. */
function posted_rows(mixed $rows): array
{
    $out = [];
    if (is_array($rows)) {
        foreach (array_slice($rows, 0, MAX_SIZES_PER_COLORWAY * 2) as $row) {
            if (is_array($row)) {
                $out[] = ['size' => mb_substr(trim(form_str($row, 'size')), 0, 10), 'qty' => mb_substr(trim(form_str($row, 'qty')), 0, 10)];
            }
        }
    }
    return $out;
}

/** Every problem of one card as a flat list of sentences. */
function card_messages(array $card): array
{
    $out = [];
    foreach ($card['errors'] as $message) {
        foreach ((array) $message as $one) {
            $out[] = $one;
        }
    }
    return $out;
}

function saved_message(array $r, bool $updated): string
{
    $parts = array_map(
        static fn (array $c): string => $c['name'] . ' (' . $c['pairs'] . ' pair' . ($c['pairs'] === 1 ? '' : 's') . ')',
        $r['colorways']
    );
    $n     = count($r['colorways']);
    $model = $r['brand'] . ' ' . $r['name'];
    if ($r['created']) {
        return 'Added "' . $model . '" with ' . $n . ' colorway' . ($n === 1 ? '' : 's') . ': ' . implode(', ', $parts) . '.';
    }
    return '"' . $model . '" already existed, so ' . ($n === 1 ? 'the new colorway was' : 'the ' . $n . ' new colorways were')
        . ' added to it: ' . implode(', ', $parts) . '.' . ($updated ? ' Its main category, category and price were updated.' : '');
}

/** "Women's Shoes, Running, ₱5,500.00": a model's shared details, as shown in the keep / update choice. */
function details_text(?string $main, string $category, string|float $price): string
{
    return (main_category_label($main) ?: 'no main category') . ', ' . $category . ', ₱' . number_format((float) $price, 2);
}

/** One colorway card. $key is the card's number in the form, or __K__ in the template for new cards. */
function render_colorway_card(string $key, array $card, int $number, bool $canImages): void
{
    $err   = $card['errors'];
    $id    = 'cw' . $key;
    $field = 'colorways[' . $key . ']';

    // Standard sizes that are ticked, and any other sizes the user typed.
    $ticked = [];
    $custom = [];
    foreach ($card['rows'] as $row) {
        $size = parse_size($row['size']);
        if ($size !== null && in_array((float) $size, STANDARD_SIZES, true) && !array_key_exists($size, $ticked)) {
            $ticked[$size] = $row['qty'];
        } else {
            $custom[] = $row;
        }
    }
    $kept    = $card['image_token'] !== '' ? staged_image_path($card['image_token']) : null;
    $preview = $kept ?? ($card['image_url'] !== '' && image_url_problem($card['image_url']) === null ? $card['image_url'] : null);
    $nameErr = $err['name'] ?? null;
    $j       = 0;
    ?>
<div class="card shadow-sm mb-4 colorway-card" data-key="<?= e($key) ?>" data-next-row="<?= count(STANDARD_SIZES) + count($custom) ?>">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span class="fw-semibold cw-number">Colorway <?= $number ?></span>
        <div class="d-flex align-items-center gap-2">
            <span class="badge text-bg-light border fw-normal cw-total" aria-live="polite">0 pairs in 0 sizes</span>
            <button type="button" class="btn btn-outline-danger btn-sm cw-remove" aria-label="Remove colorway <?= $number ?>">
                <i class="bi bi-x-lg" aria-hidden="true"></i> Remove
            </button>
        </div>
    </div>
    <div class="card-body p-4">
        <?php if (($card['duplicate_of'] ?? '') !== ''): ?>
            <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2" role="alert">
                <span><strong><?= e($card['duplicate_of']) ?></strong> already exists for this model, so it can't be added again.
                    Keep the existing colorway by removing this card, or cancel. (To add pairs to it, use Stock In on the inventory page.)</span>
                <span class="d-flex flex-wrap gap-2">
                    <?php if (($card['duplicate_url'] ?? '') !== ''): ?>
                        <a class="btn btn-sm btn-outline-secondary cw-open-dup" href="<?= e($card['duplicate_url']) ?>" target="_blank" rel="noopener">Open the existing colorway (new tab)</a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary cw-remove-dup">Remove this card</button>
                </span>
            </div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label" for="<?= e($id) ?>-name">Colorway name</label>
                <input type="text" class="form-control cw-name<?= $nameErr ? ' is-invalid' : '' ?>" id="<?= e($id) ?>-name"
                       name="<?= e($field) ?>[name]" maxlength="80" required placeholder="e.g. Chicago Red"
                       value="<?= e($card['name']) ?>"<?= $nameErr ? ' data-server-error="' . e($nameErr) . '"' : '' ?>>
                <div class="invalid-feedback" data-default="Enter the colorway name."><?= e($nameErr ?? 'Enter the colorway name.') ?></div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="<?= e($id) ?>-desc">Description <span class="text-muted fw-normal">(optional)</span></label>
                <input type="text" class="form-control<?= isset($err['description']) ? ' is-invalid' : '' ?>" id="<?= e($id) ?>-desc"
                       name="<?= e($field) ?>[description]" maxlength="255" placeholder="e.g. White leather, varsity red overlays"
                       value="<?= e($card['description']) ?>">
                <div class="invalid-feedback"><?= e($err['description'] ?? 'Use 255 characters or fewer.') ?></div>
            </div>

            <?php if ($canImages): ?>
            <div class="col-12">
                <span class="form-label d-block">Photo <span class="text-muted fw-normal">(optional)</span></span>
                <div class="d-flex flex-wrap gap-3 align-items-start">
                    <img class="cw-thumb cw-preview<?= $preview === null ? ' d-none' : '' ?>" alt="Photo preview"<?= $preview !== null ? ' src="' . e($preview) . '"' : '' ?>>
                    <div class="flex-grow-1" style="min-width: 220px;">
                        <?php if ($kept !== null): ?>
                            <div class="cw-kept small mb-2" data-src="<?= e($kept) ?>">
                                <i class="bi bi-check-circle" aria-hidden="true"></i> The photo you chose is kept - no need to choose it again.
                                <div class="form-check form-check-inline ms-2 mb-0">
                                    <input class="form-check-input cw-drop" type="checkbox" id="<?= e($id) ?>-drop" name="<?= e($field) ?>[drop_image]" value="1">
                                    <label class="form-check-label" for="<?= e($id) ?>-drop">Remove it</label>
                                </div>
                                <input type="hidden" name="<?= e($field) ?>[image_token]" value="<?= e($card['image_token']) ?>">
                            </div>
                        <?php endif; ?>
                        <label class="form-label small text-muted mb-1" for="<?= e($id) ?>-file">Upload a photo (JPEG, PNG, WebP or GIF, up to 5 MB)</label>
                        <input type="file" class="form-control cw-file<?= isset($err['image']) ? ' is-invalid' : '' ?>" id="<?= e($id) ?>-file"
                               name="images[<?= e($key) ?>]" accept="image/jpeg,image/png,image/webp,image/gif">
                        <div class="invalid-feedback cw-file-feedback"><?= e($err['image'] ?? 'Photos must be 5 MB or smaller.') ?></div>
                        <label class="form-label small text-muted mb-1 mt-2" for="<?= e($id) ?>-url">...or paste a link to a photo</label>
                        <input type="url" class="form-control cw-url<?= isset($err['image_url']) ? ' is-invalid' : '' ?>" id="<?= e($id) ?>-url"
                               name="<?= e($field) ?>[image_url]" maxlength="500" placeholder="https://..." value="<?= e($card['image_url']) ?>"<?= isset($err['image_url']) ? ' data-server-error="' . e($err['image_url']) . '"' : '' ?>>
                        <div class="invalid-feedback cw-url-feedback" data-default="Enter a link starting with http:// or https://"><?= e($err['image_url'] ?? 'Enter a link starting with http:// or https://') ?></div>
                        <div class="form-text cw-url-status" aria-live="polite"></div>
                        <div class="form-text">If you give both, the uploaded photo is used.</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <p class="fw-semibold mb-2">Sizes and starting stock</p>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3 p-3 bg-light rounded">
            <label class="small fw-semibold mb-0" for="<?= e($id) ?>-fill">Quick fill:</label>
            <input type="number" id="<?= e($id) ?>-fill" class="form-control form-control-sm cw-fill-qty" style="width: 80px;" value="5"
                   aria-label="Pairs to put in every ticked size">
            <button type="button" class="btn btn-outline-secondary btn-sm cw-fill">Set ticked sizes to this</button>
            <button type="button" class="btn btn-outline-secondary btn-sm cw-clear">Clear to 0</button>
            <button type="button" class="btn btn-link btn-sm cw-all">Tick all</button>
            <button type="button" class="btn btn-link btn-sm cw-none">Untick all</button>
        </div>

        <div class="row g-3 cw-sizes">
            <?php foreach (STANDARD_SIZES as $s):
                $sz     = number_format($s, 1, '.', '');
                $on     = array_key_exists($sz, $ticked);
                $badQty = $on && $ticked[$sz] !== '' && parse_qty($ticked[$sz]) === null;   // shown again after a refused save ?>
                <div class="col-6 col-sm-4 col-md-3">
                    <div class="size-tile<?= $on ? '' : ' is-off' ?>">
                        <div class="form-check mb-1">
                            <input class="form-check-input size-pick" type="checkbox" id="<?= e($id) ?>-s<?= $j ?>"<?= $on ? ' checked' : '' ?>>
                            <label class="form-check-label" for="<?= e($id) ?>-s<?= $j ?>">US <?= $sz ?></label>
                        </div>
                        <input type="hidden" class="size-value" name="<?= e($field) ?>[sizes][<?= $j ?>][size]" value="<?= $sz ?>"<?= $on ? '' : ' disabled' ?>>
                        <input type="number" class="form-control qty-input<?= $badQty ? ' is-invalid' : '' ?>" name="<?= e($field) ?>[sizes][<?= $j ?>][qty]" min="0" max="<?= MAX_QTY ?>"
                               step="1" inputmode="numeric" aria-label="Pairs of US <?= $sz ?>" value="<?= e($on ? $ticked[$sz] : '0') ?>"<?= $on ? '' : ' disabled' ?>>
                    </div>
                </div>
                <?php $j++; ?>
            <?php endforeach; ?>
            <?php foreach ($custom as $row): ?>
                <div class="col-12 col-sm-6 col-lg-4 custom-size">
                    <div class="input-group has-validation">
                        <span class="input-group-text">US</span>
                        <input type="number" class="form-control size-value<?= parse_size($row['size']) === null ? ' is-invalid' : '' ?>"
                               name="<?= e($field) ?>[sizes][<?= $j ?>][size]" min="1" max="20"
                               step="0.5" required aria-label="Size" value="<?= e($row['size']) ?>">
                        <input type="number" class="form-control qty-input<?= $row['qty'] !== '' && parse_qty($row['qty']) === null ? ' is-invalid' : '' ?>"
                               name="<?= e($field) ?>[sizes][<?= $j ?>][qty]" min="0"
                               max="<?= MAX_QTY ?>" step="1" aria-label="Pairs" value="<?= e($row['qty']) ?>">
                        <button type="button" class="btn btn-outline-danger remove-size" aria-label="Remove this size">&times;</button>
                        <div class="invalid-feedback">Enter a US size from 1 to 20, in half sizes.</div>
                    </div>
                </div>
                <?php $j++; ?>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-outline-secondary btn-sm mt-3 cw-add-size">+ Add another size</button>
        <div class="form-text">Tick the sizes this colorway comes in. Ticked sizes left at 0 are saved too, so they show as out of stock.</div>
        <?php if (!empty($err['sizes'])): ?>
            <ul class="small text-danger mt-2 mb-0 ps-3">
                <?php foreach ((array) $err['sizes'] as $message): ?><li><?= e($message) ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <div class="small text-danger mt-2 d-none cw-size-msg">Tick at least one size for this colorway.</div>
    </div>
</div>
    <?php
}

/* =====================================================================================
   Handle the form
   ===================================================================================== */
$errors     = [];          // problems shown at the top of the page
$shoeErrors = [];          // problems with the model fields, by field
$shoe       = ['name' => '', 'brand' => '', 'main_category' => '', 'category' => '', 'price' => ''];
$cards      = [];          // colorway cards to show again, by card key
$existing   = null;        // the model that already has this brand + name
$checkedNew = false;       // true when the brand + name were checked and no such model exists
$conflict   = [];          // where that model's main category / category / price differ from what was typed
$choice     = '';          // keep | update (only asked when there is a conflict)
$after      = 'stay';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (request_too_large()) {
        $errors[] = 'The form was larger than the server accepts (' . ini_get('post_max_size') . 'B in total), so nothing arrived '
                  . 'and nothing was saved. Use smaller photos or add fewer colorways at a time.';
    } else {
        $csrfOk = csrf_valid($_POST['csrf'] ?? null);
        if (!$csrfOk) {
            $errors[] = 'Security token invalid. Please reload the page and try again.';
        }
        $after = form_str($_POST, 'after') === 'inventory' ? 'inventory' : 'stay';
        [$shoe, $shoeErrors] = validate_shoe_fields($_POST);

        $posted = $_POST['colorways'] ?? [];
        $posted = is_array($posted) ? $posted : [];
        if (count($posted) > MAX_COLORWAYS_PER_FORM) {
            $errors[] = 'At most ' . MAX_COLORWAYS_PER_FORM . ' colorways can be added in one save.';
            $posted   = array_slice($posted, 0, MAX_COLORWAYS_PER_FORM, true);
        }
        $files = $csrfOk ? uploaded_files($_FILES['images'] ?? null) : [];
        $valid = [];
        foreach ($posted as $key => $in) {
            $key = (string) $key;
            if (!is_array($in) || !preg_match('/^[0-9]{1,4}$/', $key)) {
                continue;
            }
            [$v, $cardErrors] = validate_colorway_fields($in);

            // Photo: a new upload replaces a photo kept from the last attempt; "Remove it" drops the kept one.
            $token = form_str($in, 'image_token');
            $token = preg_match('/^[0-9a-f]{32}$/', $token) ? $token : '';
            if ($csrfOk) {
                if ($token !== '' && form_str($in, 'drop_image') === '1') {
                    forget_staged_image($token);
                    $token = '';
                }
                $file = $files[$key] ?? null;
                if ($file !== null && $file['error'] !== UPLOAD_ERR_NO_FILE) {
                    authorize('images.upload');                  // the form only offers uploads with this permission
                    try {
                        $newToken = stage_image($file);          // checked now, kept until the save succeeds
                        if ($token !== '') {
                            forget_staged_image($token);
                        }
                        $token = $newToken;
                    } catch (UserError $ex) {
                        $cardErrors['image'] = $ex->getMessage();
                    }
                }
            }
            if ($token !== '' && staged_image_path($token) === null) {
                $cardErrors['image'] = 'The photo chosen earlier has expired. Please choose it again.';
                $token = '';
            }
            if ($csrfOk && ($token !== '' || $v['image_url'] !== '')) {
                authorize('images.upload');
            }
            $cards[$key] = ['name' => $v['name'], 'description' => $v['description'], 'image_url' => $v['image_url'],
                            'image_token' => $token, 'rows' => posted_rows($in['sizes'] ?? []), 'errors' => $cardErrors,
                            'duplicate_of' => '', 'duplicate_url' => ''];
            $valid[$key] = ['v' => $v, 'image_token' => $token !== '' ? $token : null];
        }
        if (!$cards) {
            $errors[] = 'Add at least one colorway.';
        }
        foreach (duplicate_names(array_map(static fn (array $c): string => $c['name'], $cards)) as $keys) {
            foreach ($keys as $k) {
                $cards[$k]['errors']['name'] = 'This colorway name is used more than once in this form.';
            }
        }

        /* ----- Automatic grouping: is this brand + model already in the inventory? ----- */
        if ($csrfOk && !$shoeErrors) {
            $existing = find_shoe_model($pdo, $shoe['brand'], $shoe['name']);
            if ($existing !== null) {
                $label = $existing['brand'] . ' ' . $existing['name'];
                if (!$canAddCw) {
                    $errors[] = '"' . $label . '" already exists. Adding colorways to an existing model needs the "Add Colorways" permission.';
                }
                $conflict = model_differences($existing, $shoe);
                $choice   = form_str($_POST, 'model_details');
                $choice   = in_array($choice, ['keep', 'update'], true) ? $choice : '';
                if ($conflict && $choice === '') {
                    $errors[] = '"' . $label . '" already exists with a different main category, category or price. '
                              . 'Choose below whether to keep its details or update them.';
                }
                if ($conflict && $choice === 'update') {
                    authorize('shoes.edit');                     // the option is only offered with this permission
                }
                foreach ($cards as $k => $c) {
                    if ($c['name'] !== '' && !isset($c['errors']['name'])
                        && ($found = find_colorway($pdo, (int) $existing['id'], $c['name'])) !== null) {
                        $cards[$k]['errors']['name']  = '"' . $found['name'] . '" already exists for ' . $label . '.';
                        $cards[$k]['duplicate_of'] = $found['name'];
                        // Where the existing colorway can be opened with this account's permissions (or nowhere).
                        $cards[$k]['duplicate_url'] = match (true) {
                            can('colorways.edit') || can('images.change') => 'colorway.php?id=' . (int) $found['id'],
                            can('shoes.view')                             => 'shoe.php?id=' . (int) $existing['id'] . '&colorway=' . (int) $found['id'],
                            $canViewInv                                   => 'admin.php#colorway-' . (int) $found['id'],
                            default                                       => '',
                        };
                    }
                }
            } else {
                $checkedNew = true;
                if (!$canNewModel) {
                    $errors[] = 'There is no model "' . $shoe['brand'] . ' ' . $shoe['name'] . '" yet. '
                              . 'Creating a new model needs the "Add Shoes" permission.';
                }
            }
        }

        /* ----- Save everything in one transaction ----- */
        $cardProblems = array_filter($cards, static fn (array $c): bool => (bool) $c['errors']);
        if ($csrfOk && !$errors && !$shoeErrors && !$cardProblems) {
            $update  = $existing !== null && $conflict && $choice === 'update';
            $changes = new ImageChanges();
            try {
                $pdo->beginTransaction();
                $result = save_shoe_with_colorways($pdo, $shoe, array_values($valid),
                                                   $existing !== null ? (int) $existing['id'] : null,
                                                   $update, (int) $user['id'], $changes);
                $pdo->commit();
                $changes->commit();
                flash('success', saved_message($result, $update));
                redirect($after === 'inventory' && $canViewInv ? 'admin.php' : 'add-shoe.php');   // Post/Redirect/Get
            } catch (UserError $ex) {
                rollback_quietly($pdo);
                $changes->rollback();
                $errors[] = $ex->getMessage();
            } catch (PDOException $ex) {
                rollback_quietly($pdo);
                $changes->rollback();
                error_log('add-shoe.php: ' . $ex->getMessage());
                $errors[] = is_busy_error($ex)
                    ? 'The database was busy, so nothing was saved. Please save again.'
                    : 'Database error: nothing was saved. Please try again.';
            } catch (Throwable $ex) {
                rollback_quietly($pdo);
                $changes->rollback();
                error_log('add-shoe.php: ' . $ex);
                $errors[] = 'Something went wrong, so nothing was saved. Please try again.';
            }
        }
    }
} else {
    // add-shoe.php?shoe=ID opens the form with that model filled in (to add colorways to it).
    $prefill = filter_var($_GET['shoe'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($prefill && ($model = get_shoe_model($pdo, (int) $prefill)) !== null) {
        $shoe = ['name' => $model['name'], 'brand' => $model['brand'], 'main_category' => (string) $model['main_category'],
                 'category' => $model['category'], 'price' => number_format((float) $model['price'], 2, '.', '')];
        $existing = $model;
    }
}

if (!$cards) {
    $cards = ['0' => blank_card()];
}
$existingColorways = $existing !== null ? array_column(colorways_of($pdo, (int) $existing['id']), 'name') : [];
// What the server already knows about the typed model, so the page starts in the same state
// (and stays correct even if a later live lookup fails).
$existingJson = $existing !== null
    ? json_encode(['exists' => true, 'brand' => $existing['brand'], 'name' => $existing['name'],
                   'main' => (string) $existing['main_category'], 'mainLabel' => main_category_label($existing['main_category']),
                   'category' => $existing['category'],
                   'price' => number_format((float) $existing['price'], 2, '.', ''), 'colorways' => $existingColorways],
                  JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
    : ($checkedNew ? '{"exists":false}' : '');

$summary = array_merge(array_values($shoeErrors), $errors);
$n = 0;
foreach ($cards as $c) {
    $n++;
    foreach (card_messages($c) as $message) {
        $summary[] = 'Colorway ' . $n . ($c['name'] !== '' ? ' ("' . $c['name'] . '")' : '') . ': ' . $message;
    }
}

$cats = array_unique(array_merge(
    ['Running', 'Casual', 'Formal', 'Basketball', 'Sandals', 'Boots'],
    $pdo->query('SELECT DISTINCT category FROM shoes ORDER BY category')->fetchAll(PDO::FETCH_COLUMN)
));
$intro = match (true) {
    $canNewModel && $canAddCw => 'Enter the model once, then add one or more colorways - each with its own photo, sizes and stock. '
                               . 'If the model already exists, the colorways are added to it.',
    $canAddCw                 => 'Your account can add colorways to models that already exist. Type the brand and model name to find the model.',
    default                   => 'Your account can create new shoe models with their colorways. '
                               . 'Adding colorways to an existing model needs the "Add Colorways" permission.',
};
$nextKey    = max(array_map('intval', array_keys($cards))) + 1;
$token      = csrf_token();
$showChoice = $existing !== null && $conflict;
$css = <<<'CSS'
<style>
    /* Add Shoe page only (the shared theme lives in layout.php) */
    .size-tile .form-check { min-height: 0; }
    .size-tile .form-check-label { font-size: .8rem; font-weight: 600; color: var(--ink-2); }
    .size-tile.is-off { background: var(--surface); border-style: dashed; }
    .size-tile.is-off .qty-input { opacity: .4; }
    .cw-thumb { width: 88px; height: 88px; object-fit: contain; border-radius: 14px; background: var(--photo-bg); border: 1px solid var(--line-2); flex-shrink: 0; }
    #model-choice .form-check-label { font-weight: 500; }
</style>
CSS;

render_header('Add New Shoe', 'add', true, $css);
?>
<main id="main" class="container pb-5" style="max-width: 900px;">
    <?php render_flash(); ?>

    <?php if ($summary): ?>
        <div class="alert alert-danger" role="alert">
            <strong>Nothing was saved. Please fix the following:</strong>
            <ul class="mb-0"><?php foreach ($summary as $message): ?><li><?= e($message) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <h1 class="page-title mb-1">Add New Shoe</h1>
            <p class="page-sub mb-0"><?= e($intro) ?></p>
        </div>
        <?php if ($canViewInv): ?><a href="admin.php" class="btn btn-outline-secondary btn-sm">View inventory</a><?php endif; ?>
    </div>

    <form method="post" action="add-shoe.php" id="shoe-form" enctype="multipart/form-data" novalidate
          data-next-key="<?= $nextKey ?>" data-max-cards="<?= MAX_COLORWAYS_PER_FORM ?>" data-max-file="<?= IMAGE_MAX_BYTES ?>"
          data-max-post="<?= max(0, ini_bytes((string) ini_get('post_max_size')) - 1048576) ?>"
          data-can-new="<?= $canNewModel ? '1' : '0' ?>" data-can-add="<?= $canAddCw ? '1' : '0' ?>" data-can-edit="<?= $canEditShoe ? '1' : '0' ?>"
          data-existing="<?= e($existingJson) ?>">
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <input type="hidden" name="after" id="after" value="stay">

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Shoe model</div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="name" class="form-label">Model name</label>
                        <input type="text" class="form-control<?= isset($shoeErrors['name']) ? ' is-invalid' : '' ?>" id="name" name="name"
                               maxlength="150" required autofocus placeholder="e.g. Air Force 1" value="<?= e($shoe['name']) ?>">
                        <div class="invalid-feedback"><?= e($shoeErrors['name'] ?? 'Please enter the model name.') ?></div>
                    </div>
                    <div class="col-md-6">
                        <label for="brand" class="form-label">Brand</label>
                        <input type="text" class="form-control<?= isset($shoeErrors['brand']) ? ' is-invalid' : '' ?>" id="brand" name="brand"
                               maxlength="100" required placeholder="e.g. Nike" value="<?= e($shoe['brand']) ?>">
                        <div class="invalid-feedback"><?= e($shoeErrors['brand'] ?? 'Please enter the brand.') ?></div>
                    </div>
                    <div class="col-md-4">
                        <label for="main_category" class="form-label">Main category</label>
                        <select class="form-select<?= isset($shoeErrors['main_category']) ? ' is-invalid' : '' ?>" id="main_category" name="main_category" required>
                            <option value=""<?= $shoe['main_category'] === '' ? ' selected' : '' ?> disabled>Choose...</option>
                            <?php foreach (MAIN_CATEGORIES as $code => $mainName): ?>
                                <option value="<?= e($code) ?>"<?= $shoe['main_category'] === $code ? ' selected' : '' ?>><?= e($mainName) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback"><?= e($shoeErrors['main_category'] ?? "Choose Women's Shoes or Men's Shoes.") ?></div>
                    </div>
                    <div class="col-md-4">
                        <label for="category" class="form-label">Category</label>
                        <input type="text" class="form-control<?= isset($shoeErrors['category']) ? ' is-invalid' : '' ?>" id="category" name="category"
                               maxlength="100" list="cats" placeholder="Pick one or type a new category" required value="<?= e($shoe['category']) ?>">
                        <datalist id="cats">
                            <?php foreach ($cats as $c): ?><option value="<?= e((string) $c) ?>"><?php endforeach; ?>
                        </datalist>
                        <div class="invalid-feedback"><?= e($shoeErrors['category'] ?? 'Please enter a category.') ?></div>
                    </div>
                    <div class="col-md-4">
                        <label for="price" class="form-label">Price</label>
                        <div class="input-group has-validation">
                            <span class="input-group-text">&#8369;</span>
                            <input type="number" class="form-control<?= isset($shoeErrors['price']) ? ' is-invalid' : '' ?>" id="price" name="price"
                                   min="0" max="9999999.99" step="0.01" inputmode="decimal" required value="<?= e($shoe['price']) ?>">
                            <div class="invalid-feedback"><?= e($shoeErrors['price'] ?? 'Enter a price from 0 to 9,999,999.99.') ?></div>
                        </div>
                    </div>
                </div>

                <div id="model-status" class="mt-3" aria-live="polite">
                    <?php if ($existing !== null): ?>
                        <div class="alert <?= $canAddCw ? 'alert-info' : 'alert-warning' ?> mb-0">
                            <p class="mb-1"><strong><?= e($existing['brand'] . ' ' . $existing['name']) ?></strong>
                                <?= $canAddCw ? 'already exists - your colorways will be added to it. No second model is created.'
                                              : 'already exists, and your account cannot add colorways to existing models.' ?></p>
                            <p class="mb-0 small">Main category: <?= e(main_category_label($existing['main_category']) ?: 'not set yet') ?>
                                &middot; Category: <?= e($existing['category']) ?> &middot; Price: &#8369;<?= number_format((float) $existing['price'], 2) ?>
                                &middot; Colorways: <?= e($existingColorways ? implode(', ', $existingColorways) : 'none') ?></p>
                        </div>
                    <?php endif; ?>
                </div>

                <fieldset id="model-choice" class="border rounded p-3 mt-3<?= $showChoice ? '' : ' d-none' ?>">
                    <legend class="fs-6 fw-semibold float-none w-auto px-1 mb-1">This model already exists with a different main category, category or price</legend>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="model_details" id="md-keep" value="keep"
                               <?= $choice === 'keep' ? 'checked' : '' ?><?= $showChoice ? ' required' : ' disabled' ?>>
                        <label class="form-check-label" for="md-keep">Keep its existing details
                            (<span data-existing><?= $existing ? e(details_text($existing['main_category'], $existing['category'], $existing['price'])) : '' ?></span>)
                            and just add the colorways</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="model_details" id="md-update" value="update"
                               <?= $choice === 'update' ? 'checked' : '' ?><?= $showChoice && $canEditShoe ? ' required' : ' disabled' ?>>
                        <label class="form-check-label" for="md-update">Update the model to what I typed
                            (<span data-typed><?= $showChoice ? e(details_text($shoe['main_category'], $shoe['category'], $shoe['price'])) : '' ?></span>)
                            <?= $canEditShoe ? '' : '<span class="text-muted">- needs the "Edit Shoes" permission</span>' ?></label>
                    </div>
                </fieldset>
            </div>
        </div>

        <div id="colorway-list">
            <?php $n = 0; foreach ($cards as $key => $card): $n++; render_colorway_card((string) $key, $card, $n, $canImages); endforeach; ?>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <button type="button" class="btn btn-outline-secondary" id="add-colorway">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Add another colorway
            </button>
            <span class="small text-muted" id="form-summary" aria-live="polite"></span>
        </div>

        <div id="too-big" class="alert alert-warning d-none" role="alert" tabindex="-1">
            The photos you chose add up to more than the server accepts in one save. Remove some photos, or save in two goes.
        </div>

        <div class="d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-success px-4" data-after="stay">Save shoe
                <span class="spinner-border spinner-border-sm btn-spinner ms-1" aria-hidden="true"></span></button>
            <?php if ($canViewInv): ?>
                <button type="submit" class="btn btn-outline-success" data-after="inventory">Save &amp; view inventory</button>
            <?php endif; ?>
            <a href="<?= $canViewInv ? 'admin.php' : 'add-shoe.php' ?>" class="btn btn-light border">Cancel</a>
        </div>
    </form>

    <template id="colorway-template"><?php render_colorway_card('__K__', blank_card(), 1, $canImages); ?></template>
</main>

<?php render_scripts(); ?>
<script>
(function () {
    'use strict';
    const form = document.getElementById('shoe-form');
    const list = document.getElementById('colorway-list');
    const template = document.getElementById('colorway-template');
    const addCardBtn = document.getElementById('add-colorway');
    const summary = document.getElementById('form-summary');
    const statusBox = document.getElementById('model-status');
    const choiceBox = document.getElementById('model-choice');
    const tooBigBox = document.getElementById('too-big');
    const brandEl = document.getElementById('brand');
    const nameEl = document.getElementById('name');
    const mainEl = document.getElementById('main_category');
    const catEl = document.getElementById('category');
    const priceEl = document.getElementById('price');
    const MAX_CARDS = Number(form.dataset.maxCards);
    const MAX_FILE = Number(form.dataset.maxFile);
    const MAX_POST = Number(form.dataset.maxPost);
    const canNew = form.dataset.canNew === '1';
    const canAdd = form.dataset.canAdd === '1';
    const canEdit = form.dataset.canEdit === '1';
    let nextKey = Number(form.dataset.nextKey);
    let existing = form.dataset.existing ? JSON.parse(form.dataset.existing) : null;   // what is known about the typed model
    let dirty = false;
    let lookupTimer = null;
    let lookupSeq = 0;

    const norm = (s) => String(s || '').replace(/\s+/g, ' ').trim().toLowerCase();
    const plural = (n, word) => n + ' ' + word + (n === 1 ? '' : 's');
    const money = (v) => Number(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const cards = () => Array.from(list.querySelectorAll('.colorway-card'));
    // "Women's Shoes, Running, ₱5,500.00" - the same wording as details_text() in PHP
    const detailsText = (mainLabel, category, price) => (mainLabel || 'no main category') + ', ' + category + ', ₱' + money(price);
    const typedMainLabel = () => (mainEl.value && mainEl.selectedIndex >= 0 ? mainEl.options[mainEl.selectedIndex].text.trim() : '');

    /* ---------- sizes and totals ---------- */
    function setTile(tile, on) {
        tile.classList.toggle('is-off', !on);
        tile.querySelectorAll('.size-value, .qty-input').forEach((input) => { input.disabled = !on; });
        tile.querySelector('.size-pick').checked = on;
    }
    function updateCard(card) {
        let pairs = 0;
        let sizes = 0;
        card.querySelectorAll('.qty-input:not(:disabled)').forEach((input) => {
            sizes++;
            pairs += Math.max(0, parseInt(input.value, 10) || 0);
        });
        card.querySelector('.cw-total').textContent = plural(pairs, 'pair') + ' in ' + plural(sizes, 'size');
        card.dataset.sizes = String(sizes);
        if (sizes > 0) {
            card.querySelector('.cw-size-msg').classList.add('d-none');
        }
        return pairs;
    }
    function updateAll() {
        const all = cards();
        let pairs = 0;
        all.forEach((card, i) => {
            pairs += updateCard(card);
            card.querySelector('.cw-number').textContent = 'Colorway ' + (i + 1);
            const remove = card.querySelector('.cw-remove');
            remove.setAttribute('aria-label', 'Remove colorway ' + (i + 1));
            remove.disabled = all.length === 1;
            const removeDup = card.querySelector('.cw-remove-dup');
            if (removeDup) {
                removeDup.disabled = all.length === 1;
            }
        });
        summary.textContent = plural(all.length, 'colorway') + ' · ' + plural(pairs, 'pair') + ' in total';
        addCardBtn.disabled = all.length >= MAX_CARDS;
        flagNames();
    }
    function addSizeRow(card) {
        const key = card.dataset.key;
        const j = Number(card.dataset.nextRow);
        card.dataset.nextRow = String(j + 1);
        const col = document.createElement('div');
        col.className = 'col-12 col-sm-6 col-lg-4 custom-size';
        col.innerHTML = '<div class="input-group has-validation">'
            + '<span class="input-group-text">US</span>'
            + '<input type="number" class="form-control size-value" name="colorways[' + key + '][sizes][' + j + '][size]" min="1" max="20" step="0.5" placeholder="Size" required aria-label="Size">'
            + '<input type="number" class="form-control qty-input" name="colorways[' + key + '][sizes][' + j + '][qty]" min="0" max="100000" step="1" value="0" aria-label="Pairs">'
            + '<button type="button" class="btn btn-outline-danger remove-size" aria-label="Remove this size">&times;</button>'
            + '<div class="invalid-feedback">Enter a US size from 1 to 20, in half sizes.</div>'
            + '</div>';
        card.querySelector('.cw-sizes').appendChild(col);
        col.querySelector('.size-value').focus();
    }

    /* ---------- colorway cards ---------- */
    function addCard() {
        if (cards().length >= MAX_CARDS) {
            return;
        }
        const holder = document.createElement('div');
        holder.innerHTML = template.innerHTML.replace(/__K__/g, String(nextKey++)).trim();
        const card = holder.firstElementChild;
        list.appendChild(card);
        dirty = true;
        updateAll();
        card.querySelector('.cw-name').focus();
    }
    function hasContent(card) {
        const file = card.querySelector('.cw-file');
        const url = card.querySelector('.cw-url');
        return card.querySelector('.cw-name').value.trim() !== ''
            || !!(file && file.files && file.files.length)
            || !!(url && url.value.trim() !== '')
            || !!card.querySelector('.cw-kept');
    }
    function removeCard(card, ask) {
        if (cards().length < 2) {
            return;
        }
        if (ask && hasContent(card) && !window.confirm('Remove this colorway from the form?')) {
            return;
        }
        card.remove();
        dirty = true;
        updateAll();
        addCardBtn.focus();
    }

    /* ---------- photos (and double-checking photo links) ---------- */
    const LINK_BAD = 'This link does not show a picture. Use a direct link to an image (it usually ends in .jpg, .png or .webp), '
                   + 'or upload the photo instead.';
    function linkState(card, state) {             // '', 'checking', 'ok', 'bad', 'ignored'
        const url = card.querySelector('.cw-url');
        const status = card.querySelector('.cw-url-status');
        if (!url || !status) {
            return;
        }
        const feedback = card.querySelector('.cw-url-feedback');
        const serverError = url.dataset.serverError || '';      // shown until the user edits the link
        url.setCustomValidity(state === 'bad' ? LINK_BAD : '');
        url.classList.toggle('is-invalid', state === 'bad' || serverError !== '');
        feedback.textContent = state === 'bad' ? LINK_BAD : (serverError || feedback.dataset.default);
        status.className = 'form-text cw-url-status' + (state === 'ok' ? ' text-success' : '');
        status.textContent = state === 'checking' ? 'Checking the picture...'
            : state === 'ok' ? '✓ Picture found - this is how it will look.'
            : state === 'ignored' ? 'The uploaded photo is used, so this link is ignored.' : '';
    }
    function photoLoaded(img) {
        const card = img.closest('.colorway-card');
        if (img.dataset.source === 'url') {
            linkState(card, 'ok');
        }
    }
    function photoFailed(img) {
        const card = img.closest('.colorway-card');
        img.classList.add('d-none');
        if (img.dataset.source === 'url') {
            linkState(card, 'bad');
        } else if (img.dataset.source === 'file') {
            const file = card.querySelector('.cw-file');
            file.setCustomValidity('This file cannot be shown as a picture.');
            file.classList.add('is-invalid');
            card.querySelector('.cw-file-feedback').textContent = 'This file cannot be shown as a picture. Choose a JPEG, PNG, WebP or GIF photo.';
        }
    }
    function preview(card) {
        const img = card.querySelector('.cw-preview');
        if (!img) {
            return;
        }
        const file = card.querySelector('.cw-file');
        const url = card.querySelector('.cw-url');
        const kept = card.querySelector('.cw-kept');
        const drop = card.querySelector('.cw-drop');
        const link = url.value.trim();
        const looksLikeLink = /^https?:\/\/\S+$/i.test(link);
        if (img.dataset.objectUrl) {
            URL.revokeObjectURL(img.dataset.objectUrl);
            delete img.dataset.objectUrl;
        }
        let src = '';
        let source = '';
        if (file.files && file.files[0] && file.validity.valid) {
            src = URL.createObjectURL(file.files[0]);
            img.dataset.objectUrl = src;
            source = 'file';
        } else if (kept && !(drop && drop.checked)) {
            src = kept.dataset.src;
            source = 'kept';
        } else if (looksLikeLink) {
            src = link;
            source = 'url';
        }
        img.dataset.source = source;
        linkState(card, source === 'url' ? 'checking' : (looksLikeLink ? 'ignored' : ''));
        if (!src) {
            img.removeAttribute('src');
            img.classList.add('d-none');
            return;
        }
        img.classList.remove('d-none');
        if (img.getAttribute('src') !== src) {
            img.src = src;                               // 'load' or 'error' follows
        } else if (img.complete) {
            if (img.naturalWidth > 0) {                  // same picture as before: no new event comes
                photoLoaded(img);
            } else {
                photoFailed(img);
            }
        }
    }
    function checkFile(input) {
        const file = input.files && input.files[0];
        const tooLarge = !!file && file.size > MAX_FILE;
        input.setCustomValidity(tooLarge ? 'Photos must be 5 MB or smaller.' : '');
        input.classList.toggle('is-invalid', tooLarge);
        if (tooLarge) {
            input.parentElement.querySelector('.cw-file-feedback').textContent = 'This photo is larger than 5 MB. Choose a smaller one.';
        }
    }

    /* ---------- colorway names: twice in this form, or already on the existing model ---------- */
    function flagNames() {
        const counts = {};
        cards().forEach((card) => {
            const n = norm(card.querySelector('.cw-name').value);
            if (n) {
                counts[n] = (counts[n] || 0) + 1;
            }
        });
        const taken = existing && existing.exists ? existing.colorways.map(norm) : [];
        cards().forEach((card) => {
            const input = card.querySelector('.cw-name');
            const feedback = input.parentElement.querySelector('.invalid-feedback');
            const n = norm(input.value);
            let msg = '';
            if (n && taken.indexOf(n) !== -1) {
                msg = existing.brand + ' ' + existing.name + ' already has a colorway called "' + input.value.trim() + '".';
            } else if (n && counts[n] > 1) {
                msg = 'Another colorway in this form has the same name.';
            }
            input.setCustomValidity(msg);
            if (msg) {
                feedback.textContent = msg;
                input.classList.add('is-invalid');
            } else if (input.dataset.serverError) {
                feedback.textContent = input.dataset.serverError;
                input.classList.add('is-invalid');
            } else {
                feedback.textContent = feedback.dataset.default;
                input.classList.remove('is-invalid');
            }
        });
    }

    /* ---------- does this brand + model exist already? ---------- */
    function el(tag, cls, text) {
        const node = document.createElement(tag);
        if (cls) {
            node.className = cls;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }
    function showStatus() {
        statusBox.textContent = '';
        if (existing && existing.exists) {
            const box = el('div', 'alert ' + (canAdd ? 'alert-info' : 'alert-warning') + ' mb-0');
            const p = el('p', 'mb-1');
            p.appendChild(el('strong', '', existing.brand + ' ' + existing.name));
            p.appendChild(document.createTextNode(canAdd
                ? ' already exists - your colorways will be added to it. No second model is created.'
                : ' already exists, and your account cannot add colorways to existing models.'));
            box.appendChild(p);
            box.appendChild(el('p', 'mb-0 small', 'Main category: ' + (existing.mainLabel || 'not set yet')
                + ' · Category: ' + existing.category + ' · Price: ₱' + money(existing.price)
                + ' · Colorways: ' + (existing.colorways.length ? existing.colorways.join(', ') : 'none')));
            statusBox.appendChild(box);
        } else if (existing) {
            statusBox.appendChild(el('p', 'small mb-0 ' + (canNew ? 'text-muted' : 'text-danger'), canNew
                ? 'New model - it will be created when you save.'
                : 'There is no model with this brand and name yet, and your account can only add colorways to existing models.'));
        }
        showChoice();
        flagNames();
    }
    function showChoice() {
        const differs = !!(existing && existing.exists)
            && (mainEl.value !== (existing.main || '') || norm(catEl.value) !== norm(existing.category)
                || Number(priceEl.value) !== Number(existing.price));
        choiceBox.classList.toggle('d-none', !differs);
        choiceBox.querySelectorAll('input[type=radio]').forEach((radio) => {
            radio.disabled = !differs || (radio.value === 'update' && !canEdit);
            radio.required = differs;
        });
        if (differs) {
            choiceBox.querySelector('[data-existing]').textContent = detailsText(existing.mainLabel, existing.category, existing.price);
            choiceBox.querySelector('[data-typed]').textContent = detailsText(typedMainLabel(), catEl.value.trim(), priceEl.value);
        }
    }
    function lookup() {
        const brand = brandEl.value.trim();
        const name = nameEl.value.trim();
        const mine = ++lookupSeq;
        if (!brand || !name) {
            existing = null;
            showStatus();
            return;
        }
        fetch('add-shoe.php?lookup=1&brand=' + encodeURIComponent(brand) + '&name=' + encodeURIComponent(name),
              { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('lookup failed');
                }
                return response.json();
            })
            .then((data) => {
                if (mine === lookupSeq && data && typeof data.exists === 'boolean') {
                    existing = data;
                    showStatus();
                }
            })
            .catch(() => { /* keep what is shown; the server checks everything again when saving */ });
    }

    /* ---------- events ---------- */
    [brandEl, nameEl].forEach((input) => input.addEventListener('input', () => {
        clearTimeout(lookupTimer);
        lookupTimer = setTimeout(lookup, 350);
    }));
    [catEl, priceEl].forEach((input) => input.addEventListener('input', showChoice));
    mainEl.addEventListener('change', showChoice);
    addCardBtn.addEventListener('click', addCard);

    list.addEventListener('change', (ev) => {
        const t = ev.target;
        const card = t.closest('.colorway-card');
        if (!card) {
            return;
        }
        if (t.classList.contains('size-pick')) {
            setTile(t.closest('.size-tile'), t.checked);
        } else if (t.classList.contains('cw-file')) {
            checkFile(t);
            preview(card);
        } else if (t.classList.contains('cw-drop')) {
            preview(card);
        }
        dirty = true;
        updateAll();
    });
    list.addEventListener('input', (ev) => {
        const t = ev.target;
        if (t.classList.contains('cw-name')) {
            delete t.dataset.serverError;
        }
        if (t.classList.contains('cw-url')) {
            delete t.dataset.serverError;
            preview(t.closest('.colorway-card'));
        }
        dirty = true;
        updateAll();
    });
    list.addEventListener('click', (ev) => {
        const btn = ev.target.closest('button');
        const card = btn ? btn.closest('.colorway-card') : null;
        if (!card) {
            return;
        }
        if (btn.classList.contains('cw-remove')) {
            removeCard(card, true);
            return;
        }
        if (btn.classList.contains('cw-remove-dup')) {
            removeCard(card, false);
            return;
        }
        if (btn.classList.contains('cw-add-size')) {
            addSizeRow(card);
        } else if (btn.classList.contains('remove-size')) {
            btn.closest('.custom-size').remove();
        } else if (btn.classList.contains('cw-fill') || btn.classList.contains('cw-clear')) {
            const n = btn.classList.contains('cw-clear') ? 0 : Math.max(0, parseInt(card.querySelector('.cw-fill-qty').value, 10) || 0);
            card.querySelectorAll('.qty-input:not(:disabled)').forEach((input) => { input.value = n; });
        } else if (btn.classList.contains('cw-all') || btn.classList.contains('cw-none')) {
            const on = btn.classList.contains('cw-all');
            card.querySelectorAll('.size-tile').forEach((tile) => setTile(tile, on));
        } else {
            return;
        }
        dirty = true;
        updateAll();
    });
    list.addEventListener('load', (ev) => {           // the preview picture loaded (events don't bubble: capture)
        if (ev.target.classList && ev.target.classList.contains('cw-preview')) {
            photoLoaded(ev.target);
        }
    }, true);
    list.addEventListener('error', (ev) => {          // the preview picture could not be shown
        if (ev.target.classList && ev.target.classList.contains('cw-preview')) {
            photoFailed(ev.target);
        }
    }, true);
    form.addEventListener('input', () => { dirty = true; });
    window.addEventListener('beforeunload', (ev) => {
        if (dirty) {
            ev.preventDefault();
            ev.returnValue = '';
        }
    });

    form.addEventListener('submit', (ev) => {
        updateAll();
        let noSizes = null;
        cards().forEach((card) => {
            const none = card.dataset.sizes === '0';
            card.querySelector('.cw-size-msg').classList.toggle('d-none', !none);
            if (none && !noSizes) {
                noSizes = card;
            }
        });
        let bytes = 0;
        form.querySelectorAll('.cw-file').forEach((input) => {
            if (input.files && input.files[0]) {
                bytes += input.files[0].size;
            }
        });
        const tooBig = bytes > MAX_POST;
        tooBigBox.classList.toggle('d-none', !tooBig);
        if (!form.checkValidity() || noSizes || tooBig) {
            ev.preventDefault();
            form.classList.add('was-validated');
            const bad = form.querySelector(':invalid') || (noSizes && noSizes.querySelector('.cw-size-msg')) || tooBigBox;
            bad.scrollIntoView({ block: 'center', behavior: 'smooth' });
            if (typeof bad.focus === 'function') {
                bad.focus({ preventScroll: true });
            }
            return;
        }
        const sub = ev.submitter;
        document.getElementById('after').value = sub && sub.dataset.after ? sub.dataset.after : 'stay';
        dirty = false;
        if (sub) {
            sub.classList.add('is-loading');
        }
        setTimeout(() => {
            form.querySelectorAll('button[type=submit]').forEach((b) => { b.disabled = true; });
        }, 0);
    });

    cards().forEach(preview);
    updateAll();
    if (existing) {
        showStatus();
    }
})();
</script>
</body>
</html>
