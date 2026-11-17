<?php
declare(strict_types=1);
require __DIR__ . '/layout.php';
require_once __DIR__ . '/inventory-lib.php';

/*
 * Edit Colorway: rename it, change its description or photo, add sizes, remove sizes that have
 * 0 pairs, or delete the colorway. Each part needs its own permission, checked on the server:
 *   Edit Colorways      name, description, adding and removing sizes
 *   Change Shoe Images  adding, replacing or removing the photo
 *   Delete Colorways    deleting the colorway (never the model's last one)
 * One "Save changes" is saved in one transaction - completely or not at all. New and removed
 * sizes and deleted colorways are written to the stock history. A replaced photo file is deleted
 * only after the save has succeeded.
 */

$user        = require_any_permission('colorways.edit', 'images.change', 'colorways.delete');
$canDetails  = can('colorways.edit');
$canPhoto    = can('images.change');
$canDelete   = can('colorways.delete');
$canViewInv  = can('inventory.view');
$canViewShoe = can('shoes.view');
$canHistory  = can('inventory.history');

/** One colorway with its model and how many colorways the model has, or null. */
function load_colorway(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(
        'SELECT c.id, c.shoe_id, c.name, c.description, c.image_path, c.image_url, COALESCE(c.updated_at, 0) AS updated_at,
                s.brand, s.name AS shoe_name, s.category, s.price,
                (SELECT COUNT(*) FROM shoe_colorways o WHERE o.shoe_id = c.shoe_id) AS colorway_count
           FROM shoe_colorways c JOIN shoes s ON s.id = c.shoe_id
          WHERE c.id = :id'
    );
    $st->execute([':id' => $id]);
    return $st->fetch() ?: null;
}

/** New size rows as typed (plain text), so the form can be shown again exactly as it was. */
function typed_rows(mixed $rows): array
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

$id = (int) filter_var($_GET['id'] ?? $_POST['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 0]]);
$cw = load_colorway($pdo, $id);
if ($cw === null) {
    flash('danger', 'That colorway no longer exists - it may have been deleted.');
    redirect($canViewInv ? 'admin.php' : ($canViewShoe ? 'shoe.php' : home_page()));
}
$shoeId   = (int) $cw['shoe_id'];
$model    = $cw['brand'] . ' ' . $cw['shoe_name'];
$selfUrl  = 'colorway.php?id=' . $id;
$backTo   = $canViewInv ? 'admin.php#colorway-' . $id : ($canViewShoe ? 'shoe.php?id=' . $shoeId . '&colorway=' . $id : home_page());
$backText = $canViewInv ? 'Back to inventory' : ($canViewShoe ? 'Back to shoe details' : 'Back');

$sizes   = colorway_sizes($pdo, $id);
$sizeIds = array_column($sizes, null, 'id');
$current = image_src($cw['image_path'], $cw['image_url']);
$hasPhoto = $cw['image_path'] !== null || $cw['image_url'] !== null;

// What the form shows (the saved values, or what was typed when a save was refused).
$form = ['name' => $cw['name'], 'description' => (string) $cw['description'], 'photo' => 'keep', 'image_url' => '',
         'image_token' => '', 'remove' => [], 'rows' => [], 'expected' => (int) $cw['updated_at']];
$errors      = [];    // shown at the top
$fieldErrors = [];    // by field: name, description, photo, sizes (list)

/* =====================================================================================
   Delete the colorway
   ===================================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && form_str($_POST, 'action') === 'delete') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        flash('danger', 'Security token invalid. Nothing was deleted - please try again.');
        redirect($selfUrl);
    }
    authorize('colorways.delete');
    try {
        $pdo->beginTransaction();
        $image = delete_colorway($pdo, $id, $shoeId, (int) $user['id']);
        $pdo->commit();
        delete_image_file($image);                                   // only after the delete is saved
        flash('success', 'Deleted the colorway "' . $cw['name'] . '" of ' . $model . '. Its stock history is kept.');
        redirect($canViewInv ? 'admin.php#shoe-' . $shoeId : ($canViewShoe ? 'shoe.php?id=' . $shoeId : home_page()));
    } catch (UserError $ex) {
        rollback_quietly($pdo);
        flash('danger', 'Nothing was deleted. ' . $ex->getMessage());
    } catch (PDOException $ex) {
        rollback_quietly($pdo);
        error_log('colorway.php delete: ' . $ex->getMessage());
        flash('danger', is_busy_error($ex) ? 'The database was busy, so nothing was deleted. Please try again.'
                                           : 'Database error: nothing was deleted.');
    }
    redirect($selfUrl);
}

/* =====================================================================================
   Save changes
   ===================================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (request_too_large()) {
        $errors[] = 'The photo was larger than the server accepts (' . ini_get('post_max_size') . 'B), so nothing arrived and nothing was saved.';
    } elseif (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors[] = 'Security token invalid. Please reload the page and try again.';
    } else {
        $changes = ['expected_updated' => (int) filter_var($_POST['expected_updated'] ?? '', FILTER_VALIDATE_INT,
                                                            ['options' => ['min_range' => 0, 'default' => -1]])];
        $form['expected'] = max(0, $changes['expected_updated']);

        /* ----- Name and description (Edit Colorways) ----- */
        if (array_key_exists('name', $_POST) || array_key_exists('description', $_POST)) {
            authorize('colorways.edit');                 // the fields are only on the page with this permission
            $form['name']        = clean_text(form_str($_POST, 'name'));
            $form['description'] = clean_text(form_str($_POST, 'description'));
            if (($msg = check_label($form['name'], 'Colorway name', 80)) !== null) {
                $fieldErrors['name'] = $msg;
            } elseif (match_key($form['name']) !== match_key($cw['name'])
                      && ($other = find_colorway($pdo, $shoeId, $form['name'])) !== null) {
                $fieldErrors['name'] = $model . ' already has a colorway called "' . $other['name'] . '".';
            }
            if ($form['description'] !== '' && ($msg = check_label($form['description'], 'Description', 255)) !== null) {
                $fieldErrors['description'] = $msg;
            }
            $changes['details'] = ['name' => $form['name'], 'description' => $form['description']];
        }

        /* ----- Sizes (Edit Colorways) ----- */
        $remove = $_POST['remove_sizes'] ?? [];
        $remove = is_array($remove) ? array_values(array_unique(array_filter(array_map(
            static fn (mixed $v): int => is_string($v) && ctype_digit($v) ? (int) $v : 0, $remove)))) : [];
        $form['rows'] = typed_rows($_POST['new_sizes'] ?? []);
        [$newSizes, $sizeErrors] = parse_size_rows($_POST['new_sizes'] ?? []);
        if ($remove || $newSizes || $sizeErrors) {
            authorize('colorways.edit');
        }
        $form['remove'] = $remove;
        foreach ($remove as $sizeId) {
            if (!isset($sizeIds[$sizeId])) {
                $sizeErrors[] = 'A size you ticked to remove is no longer part of this colorway. Reload the page and try again.';
            } elseif ((int) $sizeIds[$sizeId]['quantity'] !== 0) {
                $sizeErrors[] = 'US ' . $sizeIds[$sizeId]['size_number'] . ' still has pairs, so it cannot be removed.';
            }
        }
        $have = array_flip(array_map(static fn (array $s): string => (string) $s['size_number'], $sizes));
        foreach (array_keys($newSizes) as $size) {
            if (isset($have[(string) $size])) {
                $sizeErrors[] = 'US ' . $size . ' is already listed for this colorway.';
            }
        }
        if (!$sizeErrors && $remove && count($sizes) - count($remove) + count($newSizes) < 1) {
            $sizeErrors[] = 'A colorway needs at least one size. Add another size before removing the last one.';
        }
        if ($sizeErrors) {
            $fieldErrors['sizes'] = array_values(array_unique($sizeErrors));
        }
        $changes['remove_sizes'] = $remove;
        $changes['add_sizes']    = $newSizes;

        /* ----- Photo (Change Shoe Images) ----- */
        $photo = form_str($_POST, 'photo');
        $photo = in_array($photo, ['keep', 'upload', 'link', 'remove'], true) ? $photo : 'keep';
        $token = form_str($_POST, 'image_token');
        $token = preg_match('/^[0-9a-f]{32}$/', $token) ? $token : '';
        if ($photo !== 'keep') {
            authorize('images.change');
        }
        if ($photo === 'upload') {
            $file = uploaded_files($_FILES['photo_file'] ?? null)[0] ?? null;
            if ($file !== null && $file['error'] !== UPLOAD_ERR_NO_FILE) {
                try {
                    $newToken = stage_image($file);              // checked now, kept until the save succeeds
                    if ($token !== '') {
                        forget_staged_image($token);
                    }
                    $token = $newToken;
                } catch (UserError $ex) {
                    $fieldErrors['photo'] = $ex->getMessage();
                }
            } elseif ($token === '' || staged_image_path($token) === null) {
                $token = '';
                $fieldErrors['photo'] = 'Choose the photo to upload.';
            }
        } elseif ($token !== '') {
            forget_staged_image($token);                         // a photo kept from before is no longer wanted
            $token = '';
        }
        if ($photo === 'link') {
            $form['image_url'] = trim(form_str($_POST, 'image_url'));
            if ($form['image_url'] === '') {
                $fieldErrors['photo'] = 'Paste the link to the photo.';
            } elseif (($msg = image_url_problem($form['image_url'])) !== null) {
                $fieldErrors['photo'] = $msg;
            }
        }
        if ($photo === 'remove' && !$hasPhoto) {
            $photo = 'keep';
        }
        $form['photo']       = $photo;
        $form['image_token'] = $token;

        /* ----- Save ----- */
        if (!$fieldErrors) {
            $detailsSame = !isset($changes['details'])
                || ($changes['details']['name'] === $cw['name'] && $changes['details']['description'] === (string) $cw['description']);
            $linkSame    = $photo === 'link' && $cw['image_path'] === null && $form['image_url'] === $cw['image_url'];
            if ($detailsSame && ($photo === 'keep' || $linkSame) && !$remove && !$newSizes) {
                flash('info', 'Nothing to save - you did not change anything.');
                redirect($selfUrl);
            }
            $images = new ImageChanges();
            try {
                $pdo->beginTransaction();
                if ($photo === 'upload') {
                    $changes['image'] = ['path' => use_staged_image($token, $images), 'url' => null];
                } elseif ($photo === 'link' && !$linkSame) {
                    $changes['image'] = ['path' => null, 'url' => $form['image_url']];
                } elseif ($photo === 'remove') {
                    $changes['image'] = ['path' => null, 'url' => null];
                }
                $done = edit_colorway($pdo, $id, $shoeId, $changes, (int) $user['id'], $images);
                $pdo->commit();
                $images->commit();
                $name = $changes['details']['name'] ?? $cw['name'];
                flash('success', $done ? 'Saved "' . $name . '" (' . $model . '): ' . implode('; ', $done) . '.'
                                       : 'Nothing changed - the colorway already looked like this.');
                redirect($selfUrl);
            } catch (UserError $ex) {
                rollback_quietly($pdo);
                $images->rollback();
                if ($ex->reason === 'stale' || $ex->reason === 'missing') {
                    flash('danger', $ex->getMessage());
                    redirect($selfUrl);                          // shows the latest version (or the "no longer exists" message)
                }
                $errors[] = $ex->getMessage();
            } catch (PDOException $ex) {
                rollback_quietly($pdo);
                $images->rollback();
                error_log('colorway.php: ' . $ex->getMessage());
                $errors[] = is_busy_error($ex) ? 'The database was busy, so nothing was saved. Please save again.'
                                               : 'Database error: nothing was saved. Please try again.';
            } catch (Throwable $ex) {
                rollback_quietly($pdo);
                $images->rollback();
                error_log('colorway.php: ' . $ex);
                $errors[] = 'Something went wrong, so nothing was saved. Please try again.';
            }
        }
    }
}

/* =====================================================================================
   Page
   ===================================================================================== */
$summary = $errors;
foreach ($fieldErrors as $msg) {
    foreach ((array) $msg as $one) {
        $summary[] = $one;
    }
}
$others = array_values(array_filter(
    array_column(colorways_of($pdo, $shoeId), 'name', 'id'),
    static fn (int $key): bool => $key !== $id, ARRAY_FILTER_USE_KEY
));
$kept = $form['image_token'] !== '' ? staged_image_path($form['image_token']) : null;

// New-size rows: ticked standard sizes (not yet on this colorway) and any other sizes typed.
$have    = array_flip(array_map(static fn (array $s): string => (string) $s['size_number'], $sizes));
$offer   = array_values(array_filter(array_map(static fn (float $s): string => number_format($s, 1, '.', ''), STANDARD_SIZES),
                                     static fn (string $s): bool => !isset($have[$s])));
$ticked  = [];
$custom  = [];
foreach ($form['rows'] as $row) {
    $size = parse_size($row['size']);
    if ($size !== null && in_array($size, $offer, true) && !array_key_exists($size, $ticked)) {
        $ticked[$size] = $row['qty'];
    } elseif ($row['size'] !== '' || $row['qty'] !== '') {
        $custom[] = $row;
    }
}
$removing = array_flip($form['remove']);
$pairs    = array_sum(array_map(static fn (array $s): int => (int) $s['quantity'], $sizes));
$isOnly   = (int) $cw['colorway_count'] <= 1;
$token    = csrf_token();

$css = <<<'CSS'
<style>
    .photo-box { width: 160px; height: 160px; border-radius: 16px; background: #f5f5f5; border: 1px solid #ececec; flex-shrink: 0;
                 display: inline-flex; align-items: center; justify-content: center; overflow: hidden; position: relative; }
    .photo-box img { width: 100%; height: 100%; object-fit: contain; }
    .photo-box > .bi { display: none; font-size: 2rem; color: #b5b5b7; }
    .photo-box.is-empty > .bi, .photo-box.is-broken > .bi { display: block; }
    .photo-box.is-broken img { display: none; }
    .photo-choice { border: 1px solid #ececec; border-radius: 12px; padding: .65rem .85rem; }
    .photo-choice + .photo-choice { margin-top: .5rem; }
    .photo-choice:has(input[name="photo"]:checked) { border-color: #111; background: #fff; }
    .photo-choice .choice-body { margin-top: .5rem; }
    .photo-choice:not(:has(input[name="photo"]:checked)) .choice-body { display: none; }
    .current-size { text-align: center; }
    .current-size.is-removing { background: #fdecea; border-color: #f3b7ac; text-decoration: line-through; }
    .current-size .sz { font-weight: 800; }
    .size-tile .form-check { min-height: 0; }
    .size-tile.is-off { background: #fff; border-style: dashed; }
    .size-tile.is-off .qty-input { opacity: .4; }
    .danger-zone { border-color: #f3b7ac; }
</style>
CSS;

render_header('Edit colorway: ' . $cw['name'], '', true, $css);
?>
<main id="main" class="container pb-5" style="max-width: 900px;">
    <?php render_flash(); ?>

    <a href="<?= e($backTo) ?>" class="btn btn-link btn-sm px-0 mb-2"><i class="bi bi-arrow-left" aria-hidden="true"></i> <?= e($backText) ?></a>

    <?php if ($summary): ?>
        <div class="alert alert-danger" role="alert" id="error-summary">
            <strong>Nothing was saved. Please fix the following:</strong>
            <ul class="mb-0"><?php foreach ($summary as $message): ?><li><?= e($message) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <p class="small text-muted mb-1"><?= e($model) ?> &middot; <?= e($cw['category']) ?> &middot; &#8369;<?= number_format((float) $cw['price'], 2) ?></p>
            <h1 class="page-title mb-1" id="cw-title"><?= e($cw['name']) ?></h1>
            <p class="page-sub mb-0">Edit this colorway<?= $canViewInv ? ' &middot; ' . $pairs . ' pair' . ($pairs === 1 ? '' : 's') . ' in ' . count($sizes) . ' size' . (count($sizes) === 1 ? '' : 's') : '' ?>.
                To add or remove pairs, use Stock in / Stock out on the inventory page.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($canViewShoe): ?><a class="btn btn-outline-secondary btn-sm" href="shoe.php?id=<?= $shoeId ?>&amp;colorway=<?= $id ?>">View shoe details</a><?php endif; ?>
            <?php if ($canHistory): ?><a class="btn btn-outline-secondary btn-sm" href="inventory-history.php?colorway=<?= $id ?>">History</a><?php endif; ?>
        </div>
    </div>

    <?php if ($canDetails || $canPhoto): ?>
    <form method="post" action="<?= e($selfUrl) ?>" id="cw-form" enctype="multipart/form-data" novalidate
          data-taken="<?= e(json_encode($others, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>" data-model="<?= e($model) ?>"
          data-sizes="<?= count($sizes) ?>" data-max-file="<?= IMAGE_MAX_BYTES ?>">
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="expected_updated" value="<?= (int) $form['expected'] ?>">

        <?php if ($canDetails): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Name and description</div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="cw-name">Colorway name</label>
                        <input type="text" class="form-control<?= isset($fieldErrors['name']) ? ' is-invalid' : '' ?>" id="cw-name" name="name"
                               maxlength="80" required value="<?= e($form['name']) ?>"<?= isset($fieldErrors['name']) ? ' data-server-error="' . e($fieldErrors['name']) . '"' : '' ?>>
                        <div class="invalid-feedback" data-default="Enter the colorway name."><?= e($fieldErrors['name'] ?? 'Enter the colorway name.') ?></div>
                        <?php if ($others): ?><div class="form-text">Other colorways of this model: <?= e(implode(', ', $others)) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="cw-desc">Description <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="text" class="form-control<?= isset($fieldErrors['description']) ? ' is-invalid' : '' ?>" id="cw-desc" name="description"
                               maxlength="255" placeholder="e.g. White leather, varsity red overlays" value="<?= e($form['description']) ?>">
                        <div class="invalid-feedback"><?= e($fieldErrors['description'] ?? 'Use 255 characters or fewer.') ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canPhoto): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Photo</div>
            <div class="card-body p-4">
                <div class="d-flex flex-wrap gap-4 align-items-start">
                    <div class="text-center">
                        <span class="photo-box<?= $current === null ? ' is-empty' : '' ?>" id="current-photo">
                            <?php if ($current !== null): ?>
                                <img src="<?= e($current) ?>" alt="Current photo of <?= e($cw['name']) ?>" referrerpolicy="no-referrer"
                                     onerror="this.parentNode.classList.add('is-broken');">
                            <?php endif; ?>
                            <i class="bi bi-image" aria-hidden="true"></i>
                        </span>
                        <div class="small text-muted mt-1"><?= $current === null ? 'No photo yet' : 'Current photo' ?></div>
                    </div>
                    <div class="text-center d-none" id="new-photo-wrap">
                        <span class="photo-box"><img id="new-photo" alt="New photo preview" referrerpolicy="no-referrer"></span>
                        <div class="small text-muted mt-1">New photo</div>
                    </div>
                    <fieldset class="flex-grow-1" style="min-width: 240px;">
                        <legend class="visually-hidden">What should happen to the photo?</legend>
                        <div class="photo-choice">
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="radio" name="photo" id="photo-keep" value="keep"<?= $form['photo'] === 'keep' ? ' checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="photo-keep"><?= $hasPhoto ? 'Keep the current photo' : 'No photo for now' ?></label>
                            </div>
                        </div>
                        <div class="photo-choice">
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="radio" name="photo" id="photo-upload" value="upload"<?= $form['photo'] === 'upload' ? ' checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="photo-upload"><?= $hasPhoto ? 'Replace it with an uploaded photo' : 'Upload a photo' ?></label>
                            </div>
                            <div class="choice-body">
                                <?php if ($kept !== null): ?>
                                    <div class="small mb-2" id="kept-photo" data-src="<?= e($kept) ?>">
                                        <i class="bi bi-check-circle" aria-hidden="true"></i> The photo you chose is kept - no need to choose it again.
                                    </div>
                                    <input type="hidden" name="image_token" value="<?= e($form['image_token']) ?>">
                                <?php endif; ?>
                                <label class="form-label small text-muted mb-1" for="photo-file">JPEG, PNG, WebP or GIF, up to 5 MB</label>
                                <input type="file" class="form-control<?= $form['photo'] === 'upload' && isset($fieldErrors['photo']) ? ' is-invalid' : '' ?>" id="photo-file"
                                       name="photo_file" accept="image/jpeg,image/png,image/webp,image/gif">
                                <div class="invalid-feedback" id="photo-file-feedback"><?= e($form['photo'] === 'upload' ? ($fieldErrors['photo'] ?? 'Choose the photo to upload.') : 'Choose the photo to upload.') ?></div>
                            </div>
                        </div>
                        <div class="photo-choice">
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="radio" name="photo" id="photo-link" value="link"<?= $form['photo'] === 'link' ? ' checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="photo-link"><?= $hasPhoto ? 'Replace it with a photo link' : 'Use a link to a photo' ?></label>
                            </div>
                            <div class="choice-body">
                                <label class="visually-hidden" for="photo-url">Link to the photo</label>
                                <input type="url" class="form-control<?= $form['photo'] === 'link' && isset($fieldErrors['photo']) ? ' is-invalid' : '' ?>" id="photo-url"
                                       name="image_url" maxlength="500" placeholder="https://..." value="<?= e($form['image_url']) ?>">
                                <div class="invalid-feedback" id="photo-url-feedback"><?= e($form['photo'] === 'link' ? ($fieldErrors['photo'] ?? 'Enter a link starting with http:// or https://') : 'Enter a link starting with http:// or https://') ?></div>
                                <div class="form-text" id="photo-url-status" aria-live="polite"></div>
                            </div>
                        </div>
                        <?php if ($hasPhoto): ?>
                        <div class="photo-choice">
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="radio" name="photo" id="photo-remove" value="remove"<?= $form['photo'] === 'remove' ? ' checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="photo-remove">Remove the photo</label>
                            </div>
                        </div>
                        <?php endif; ?>
                    </fieldset>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canDetails): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Sizes</div>
            <div class="card-body p-4">
                <?php if ($sizes): ?>
                    <p class="small text-muted mb-2">Tick <strong>Remove</strong> on sizes you no longer carry. Only sizes with 0 pairs can be removed;
                        to empty a size first, use Stock out or Set count on the inventory page. Removed sizes stay in the stock history.</p>
                    <div class="row g-2 mb-4" id="current-sizes">
                        <?php foreach ($sizes as $s):
                            $qty   = (int) $s['quantity'];
                            $sid   = (int) $s['id'];
                            $label = 'US ' . $s['size_number']; ?>
                            <div class="col-4 col-sm-3 col-md-2">
                                <div class="size-tile current-size<?= isset($removing[$sid]) ? ' is-removing' : '' ?>">
                                    <span class="sz d-block"><?= e($label) ?></span>
                                    <span class="small d-block text-muted"><?= $canViewInv ? $qty . ' pair' . ($qty === 1 ? '' : 's') : ($qty === 0 ? 'no pairs' : 'in stock') ?></span>
                                    <div class="form-check d-inline-block mt-1 mb-0">
                                        <input class="form-check-input remove-pick" type="checkbox" name="remove_sizes[]" value="<?= $sid ?>" id="rm-<?= $sid ?>"
                                               <?= isset($removing[$sid]) ? 'checked' : '' ?><?= $qty > 0 ? ' disabled' : '' ?>>
                                        <label class="form-check-label small" for="rm-<?= $sid ?>"<?= $qty > 0 ? ' title="Only sizes with 0 pairs can be removed"' : '' ?>>Remove</label>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="small text-muted">This colorway has no sizes yet.</p>
                <?php endif; ?>

                <p class="fw-semibold mb-2">Add sizes</p>
                <div class="row g-3" id="new-sizes" data-next-row="<?= count($offer) + count($custom) ?>">
                    <?php $j = 0; foreach ($offer as $sz):
                        $on     = array_key_exists($sz, $ticked);
                        $badQty = $on && $ticked[$sz] !== '' && parse_qty($ticked[$sz]) === null; ?>
                        <div class="col-6 col-sm-4 col-md-3">
                            <div class="size-tile<?= $on ? '' : ' is-off' ?>">
                                <div class="form-check mb-1">
                                    <input class="form-check-input size-pick" type="checkbox" id="ns-<?= $j ?>"<?= $on ? ' checked' : '' ?>>
                                    <label class="form-check-label small fw-semibold" for="ns-<?= $j ?>">US <?= $sz ?></label>
                                </div>
                                <input type="hidden" class="size-value" name="new_sizes[<?= $j ?>][size]" value="<?= $sz ?>"<?= $on ? '' : ' disabled' ?>>
                                <input type="number" class="form-control qty-input<?= $badQty ? ' is-invalid' : '' ?>" name="new_sizes[<?= $j ?>][qty]" min="0" max="<?= MAX_QTY ?>"
                                       step="1" inputmode="numeric" aria-label="Starting pairs of US <?= $sz ?>" value="<?= e($on ? $ticked[$sz] : '0') ?>"<?= $on ? '' : ' disabled' ?>>
                            </div>
                        </div>
                    <?php $j++; endforeach; ?>
                    <?php foreach ($custom as $row): ?>
                        <div class="col-12 col-sm-6 col-lg-4 custom-size">
                            <div class="input-group has-validation">
                                <span class="input-group-text">US</span>
                                <input type="number" class="form-control size-value<?= parse_size($row['size']) === null ? ' is-invalid' : '' ?>" name="new_sizes[<?= $j ?>][size]"
                                       min="1" max="20" step="0.5" required aria-label="Size" value="<?= e($row['size']) ?>">
                                <input type="number" class="form-control qty-input<?= $row['qty'] !== '' && parse_qty($row['qty']) === null ? ' is-invalid' : '' ?>"
                                       name="new_sizes[<?= $j ?>][qty]" min="0" max="<?= MAX_QTY ?>" step="1" aria-label="Starting pairs" value="<?= e($row['qty']) ?>">
                                <button type="button" class="btn btn-outline-danger remove-size" aria-label="Remove this size">&times;</button>
                                <div class="invalid-feedback">Enter a US size from 1 to 20, in half sizes.</div>
                            </div>
                        </div>
                    <?php $j++; endforeach; ?>
                </div>
                <button type="button" class="btn btn-outline-secondary btn-sm mt-3" id="add-size-row">+ Add another size</button>
                <div class="form-text">Tick a size to add it. The number is its starting stock (0 is fine); it is recorded in the stock history.</div>
                <?php if (!empty($fieldErrors['sizes'])): ?>
                    <ul class="small text-danger mt-2 mb-0 ps-3">
                        <?php foreach ($fieldErrors['sizes'] as $message): ?><li><?= e($message) ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <div class="small text-danger mt-2 d-none" id="last-size-msg">A colorway needs at least one size. Add another size before removing the last one.</div>
            </div>
        </div>
        <?php endif; ?>

        <div class="d-flex flex-wrap align-items-center gap-2 mb-5">
            <button type="submit" class="btn btn-success px-4" id="cw-save">Save changes
                <span class="spinner-border spinner-border-sm btn-spinner ms-1" aria-hidden="true"></span></button>
            <a href="<?= e($backTo) ?>" class="btn btn-light border">Cancel</a>
            <span class="small text-muted" id="cw-changes" aria-live="polite">No changes yet</span>
        </div>
    </form>
    <?php endif; ?>

    <?php if ($canDelete): ?>
    <div class="card shadow-sm danger-zone">
        <div class="card-body p-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <p class="fw-semibold mb-1">Delete this colorway</p>
                <p class="small text-muted mb-0">
                    <?= $isOnly ? 'This is the only colorway of ' . e($model) . ', so it cannot be deleted. Delete the whole shoe model from the inventory instead.'
                                : 'Removes "' . e($cw['name']) . '" and its stock from the inventory. The other colorways are not affected. The stock history keeps a record of it.' ?>
                </p>
            </div>
            <button type="button" class="btn btn-outline-danger" id="cw-delete" data-bs-toggle="modal" data-bs-target="#delete-modal"<?= $isOnly ? ' disabled' : '' ?>>Delete colorway</button>
        </div>
    </div>

    <?php if (!$isOnly): ?>
    <div class="modal fade" id="delete-modal" tabindex="-1" aria-labelledby="delete-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="post" action="<?= e($selfUrl) ?>" class="modal-content" id="delete-form">
                <input type="hidden" name="csrf" value="<?= e($token) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="action" value="delete">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="delete-title">Delete this colorway?</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-1">The colorway <strong><?= e($cw['name']) ?></strong> of <strong><?= e($model) ?></strong>
                        <?= $canViewInv ? 'and its ' . $pairs . ' pair' . ($pairs === 1 ? '' : 's') . ' in stock' : 'and its stock' ?> will be removed.</p>
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
    <?php endif; ?>
</main>

<?php render_scripts(); ?>
<script>
(function () {
    'use strict';
    const form = document.getElementById('cw-form');
    const delForm = document.getElementById('delete-form');
    let dirty = false;
    let saving = false;
    if (delForm) {
        delForm.addEventListener('submit', () => { saving = true; });
    }
    window.addEventListener('beforeunload', (ev) => {
        if (dirty && !saving) {
            ev.preventDefault();
            ev.returnValue = '';
        }
    });
    if (!form) {
        return;
    }
    const norm = (s) => String(s || '').replace(/\s+/g, ' ').trim().toLowerCase();
    const taken = JSON.parse(form.dataset.taken || '[]').map(norm);
    const nameEl = document.getElementById('cw-name');
    const changesEl = document.getElementById('cw-changes');
    const MAX_FILE = Number(form.dataset.maxFile);

    /* ---------- what changed (compared with how the page was loaded) ---------- */
    const fields = Array.from(form.elements).filter((el) => el.name && el.type !== 'hidden' && el.type !== 'file');
    const snapshot = () => fields.map((el) => (el.type === 'checkbox' || el.type === 'radio') ? (el.checked ? '1' : '0') + (el.disabled ? 'd' : '')
                                                                                            : el.value + (el.disabled ? 'd' : '')).join('\u0001');
    const initial = snapshot();
    const fileEl = document.getElementById('photo-file');
    function refreshChanged() {
        const custom = form.querySelectorAll('.custom-size').length;
        dirty = snapshot() !== initial || custom > 0 || !!(fileEl && fileEl.files && fileEl.files.length);
        changesEl.textContent = dirty ? 'You have changes that are not saved yet' : 'No changes yet';
    }

    /* ---------- name: not the same as another colorway of this model ---------- */
    function checkName() {
        if (!nameEl) {
            return;
        }
        const feedback = nameEl.parentElement.querySelector('.invalid-feedback');
        const n = norm(nameEl.value);
        const msg = n && taken.indexOf(n) !== -1 ? form.dataset.model + ' already has a colorway called "' + nameEl.value.trim() + '".' : '';
        nameEl.setCustomValidity(msg);
        if (msg) {
            feedback.textContent = msg;
            nameEl.classList.add('is-invalid');
        } else if (nameEl.dataset.serverError) {
            feedback.textContent = nameEl.dataset.serverError;
            nameEl.classList.add('is-invalid');
        } else {
            feedback.textContent = feedback.dataset.default;
            nameEl.classList.remove('is-invalid');
        }
    }

    /* ---------- photo: preview, and double-checking a link really shows a picture ---------- */
    const urlEl = document.getElementById('photo-url');
    const newWrap = document.getElementById('new-photo-wrap');
    const newImg = document.getElementById('new-photo');
    const urlStatus = document.getElementById('photo-url-status');
    const kept = document.getElementById('kept-photo');
    const LINK_BAD = 'This link does not show a picture. Use a direct link to an image (it usually ends in .jpg, .png or .webp), or upload the photo instead.';
    const choice = () => {
        const picked = form.querySelector('input[name="photo"]:checked');
        return picked ? picked.value : 'keep';
    };
    function pick(value) {
        const radio = form.querySelector('input[name="photo"][value="' + value + '"]');
        if (radio) {
            radio.checked = true;
        }
    }
    function linkState(state) {                         // '', 'checking', 'ok', 'bad'
        if (!urlEl) {
            return;
        }
        urlEl.setCustomValidity(state === 'bad' ? LINK_BAD : '');
        if (state === 'bad') {
            urlEl.classList.add('is-invalid');
            document.getElementById('photo-url-feedback').textContent = LINK_BAD;
        } else if (state !== '') {
            urlEl.classList.remove('is-invalid');
        }
        urlStatus.className = 'form-text' + (state === 'ok' ? ' text-success' : '');
        urlStatus.textContent = state === 'checking' ? 'Checking the picture...' : state === 'ok' ? '✓ Picture found - this is how it will look.' : '';
    }
    function showPreview() {
        if (!newImg) {
            return;
        }
        if (newImg.dataset.objectUrl) {
            URL.revokeObjectURL(newImg.dataset.objectUrl);
            delete newImg.dataset.objectUrl;
        }
        const c = choice();
        let src = '';
        let source = '';
        if (c === 'upload' && fileEl.files && fileEl.files[0] && fileEl.validity.valid) {
            src = URL.createObjectURL(fileEl.files[0]);
            newImg.dataset.objectUrl = src;
            source = 'file';
        } else if (c === 'upload' && kept) {
            src = kept.dataset.src;
            source = 'kept';
        } else if (c === 'link' && /^https?:\/\/\S+$/i.test(urlEl.value.trim())) {
            src = urlEl.value.trim();
            source = 'url';
        }
        newImg.dataset.source = source;
        if (urlEl) {
            urlEl.required = c === 'link';
            linkState(source === 'url' ? 'checking' : '');
        }
        if (fileEl) {
            fileEl.required = c === 'upload' && !kept;
        }
        newWrap.classList.toggle('d-none', !src);
        if (!src) {
            newImg.removeAttribute('src');
            return;
        }
        if (newImg.getAttribute('src') !== src) {
            newImg.src = src;                           // 'load' or 'error' follows
        } else if (newImg.complete) {
            (newImg.naturalWidth > 0 ? photoLoaded : photoFailed)();
        }
    }
    function photoLoaded() {
        if (newImg.dataset.source === 'url') {
            linkState('ok');
        }
    }
    function photoFailed() {
        newWrap.classList.add('d-none');
        if (newImg.dataset.source === 'url') {
            linkState('bad');
        } else if (newImg.dataset.source === 'file') {
            fileEl.setCustomValidity('This file cannot be shown as a picture.');
            fileEl.classList.add('is-invalid');
            document.getElementById('photo-file-feedback').textContent = 'This file cannot be shown as a picture. Choose a JPEG, PNG, WebP or GIF photo.';
        }
    }
    if (newImg) {
        newImg.addEventListener('load', photoLoaded);
        newImg.addEventListener('error', photoFailed);
    }
    if (fileEl) {
        fileEl.addEventListener('change', () => {
            const f = fileEl.files && fileEl.files[0];
            const tooLarge = !!f && f.size > MAX_FILE;
            fileEl.setCustomValidity(tooLarge ? 'Photos must be 5 MB or smaller.' : '');
            fileEl.classList.toggle('is-invalid', tooLarge);
            if (tooLarge) {
                document.getElementById('photo-file-feedback').textContent = 'This photo is larger than 5 MB. Choose a smaller one.';
            }
            if (f) {
                pick('upload');
            }
            showPreview();
        });
    }
    if (urlEl) {
        urlEl.addEventListener('input', () => {
            if (urlEl.value.trim() !== '') {
                pick('link');
            }
            urlEl.classList.remove('is-invalid');
            showPreview();
        });
    }
    form.querySelectorAll('input[name="photo"]').forEach((radio) => radio.addEventListener('change', showPreview));

    /* ---------- sizes ---------- */
    const newSizes = document.getElementById('new-sizes');
    const lastMsg = document.getElementById('last-size-msg');
    function setTile(tile, on) {
        tile.classList.toggle('is-off', !on);
        tile.querySelectorAll('.size-value, .qty-input').forEach((input) => { input.disabled = !on; });
    }
    function sizesLeft() {
        const removing = form.querySelectorAll('.remove-pick:checked').length;
        const adding = newSizes ? newSizes.querySelectorAll('.size-value:not(:disabled)').length : 0;
        return Number(form.dataset.sizes) - removing + adding;
    }
    if (newSizes) {
        newSizes.addEventListener('change', (ev) => {
            if (ev.target.classList.contains('size-pick')) {
                setTile(ev.target.closest('.size-tile'), ev.target.checked);
            }
        });
        newSizes.addEventListener('click', (ev) => {
            const btn = ev.target.closest('.remove-size');
            if (btn) {
                btn.closest('.custom-size').remove();
                refreshChanged();
            }
        });
        document.getElementById('add-size-row').addEventListener('click', () => {
            const j = Number(newSizes.dataset.nextRow);
            newSizes.dataset.nextRow = String(j + 1);
            const col = document.createElement('div');
            col.className = 'col-12 col-sm-6 col-lg-4 custom-size';
            col.innerHTML = '<div class="input-group has-validation">'
                + '<span class="input-group-text">US</span>'
                + '<input type="number" class="form-control size-value" name="new_sizes[' + j + '][size]" min="1" max="20" step="0.5" placeholder="Size" required aria-label="Size">'
                + '<input type="number" class="form-control qty-input" name="new_sizes[' + j + '][qty]" min="0" max="100000" step="1" value="0" aria-label="Starting pairs">'
                + '<button type="button" class="btn btn-outline-danger remove-size" aria-label="Remove this size">&times;</button>'
                + '<div class="invalid-feedback">Enter a US size from 1 to 20, in half sizes.</div>'
                + '</div>';
            newSizes.appendChild(col);
            col.querySelector('.size-value').focus();
            refreshChanged();
        });
    }
    form.querySelectorAll('.remove-pick').forEach((box) => box.addEventListener('change', () => {
        box.closest('.current-size').classList.toggle('is-removing', box.checked);
    }));

    /* ---------- events ---------- */
    form.addEventListener('input', (ev) => {
        if (ev.target === nameEl) {
            delete nameEl.dataset.serverError;
            checkName();
        }
        refreshChanged();
    });
    form.addEventListener('change', refreshChanged);
    form.addEventListener('submit', (ev) => {
        checkName();
        const noSizes = !!lastMsg && sizesLeft() < 1;
        if (lastMsg) {
            lastMsg.classList.toggle('d-none', !noSizes);
        }
        if (!form.checkValidity() || noSizes) {
            ev.preventDefault();
            form.classList.add('was-validated');
            const bad = form.querySelector(':invalid') || lastMsg;
            bad.scrollIntoView({ block: 'center', behavior: 'smooth' });
            if (typeof bad.focus === 'function') {
                bad.focus({ preventScroll: true });
            }
            return;
        }
        saving = true;
        document.getElementById('cw-save').classList.add('is-loading');
        setTimeout(() => { document.getElementById('cw-save').disabled = true; }, 0);
    });

    checkName();
    showPreview();
    refreshChanged();
    if (document.getElementById('error-summary')) {
        dirty = true;                                   // the refused changes are still in the form
        changesEl.textContent = 'You have changes that are not saved yet';
    }
})();
</script>
</body>
</html>
