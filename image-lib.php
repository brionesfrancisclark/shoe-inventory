<?php
declare(strict_types=1);

/**
 * Colorway images: checking, storing and deleting uploaded files, and image links.
 *
 * Safety rules (see also the CHECK constraints in migration 001):
 *  - Only JPEG, PNG, WebP and GIF, at most 5 MB. The real file content is checked with
 *    finfo and getimagesize; the name and type the browser sent are never trusted.
 *  - Files get a new random name and an extension chosen by the server from the detected
 *    type, so an upload can never become a .php file (Apache only runs *.php).
 *  - Only paths like uploads/colorways/<32 hex>.<jpg|png|webp|gif> are ever deleted.
 *  - While a form is being saved, ImageChanges remembers new files (removed again if the
 *    save fails) and replaced files (removed only after the save has succeeded).
 */

require_once __DIR__ . '/auth.php';

const IMAGE_MAX_BYTES   = 5 * 1024 * 1024;      // 5 MB per image
const IMAGE_MAX_PIXELS  = 40000000;             // 40 megapixels (e.g. 8000 x 5000)
const IMAGE_TYPES       = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
const IMAGE_DIR         = 'uploads/colorways';  // the only folder shoe_colorways.image_path may point to
const IMAGE_STAGING_DIR = 'uploads/staging';    // images kept while the user corrects a form
const IMAGE_STAGING_TTL = 86400;                // staged images older than a day are removed
const IMAGE_STAGING_MAX = 40;                   // staged images kept per signed-in session
const IMAGE_PATH_RE     = '#^uploads/(colorways|staging)/[0-9a-f]{32}\.(jpg|png|webp|gif)$#';

/** Problem with an image link, or null if it can be used. */
function image_url_problem(string $url): ?string
{
    if (mb_strlen($url) > 500) {
        return 'Image link must be 500 characters or fewer.';
    }
    // Only characters that are legal in a web address (RFC 3986): no spaces, quotes, < > or other
    // characters that could be confusing or unsafe. PHP's FILTER_VALIDATE_URL alone accepts quotes.
    if (!preg_match('#^https?://[A-Za-z0-9._~:/?\#\[\]@!$&\'()*+,;=%-]+$#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return 'Image link must be a valid web address starting with http:// or https://';
    }
    return null;
}

/** Message for a PHP upload error code, or null when the upload itself worked. */
function upload_problem(int $error): ?string
{
    return match ($error) {
        UPLOAD_ERR_OK                              => null,
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE  => 'Images must be 5 MB or smaller.',
        UPLOAD_ERR_PARTIAL                         => 'The image upload was interrupted. Please choose the file again.',
        UPLOAD_ERR_NO_FILE                         => 'No image file was chosen.',
        default                                    => 'The image could not be uploaded. Please try again.',
    };
}

/**
 * $_FILES['images'] for inputs named images[<key>] -> [key => ['name','tmp_name','error','size']].
 * (PHP stores such uploads "sideways": one array per attribute.)
 */
function uploaded_files(mixed $entry): array
{
    if (!is_array($entry) || !isset($entry['error'])) {
        return [];
    }
    if (!is_array($entry['error'])) {                       // a single <input type="file" name="x">
        $entry = ['name' => [$entry['name'] ?? ''], 'tmp_name' => [$entry['tmp_name'] ?? ''],
                  'error' => [$entry['error']], 'size' => [$entry['size'] ?? 0]];
    }
    $out = [];
    foreach ($entry['error'] as $key => $error) {
        if (is_array($error)) {
            continue;                                        // deeper nesting is not used by this app
        }
        $out[$key] = [
            'name'     => is_string($entry['name'][$key] ?? null) ? $entry['name'][$key] : '',
            'tmp_name' => is_string($entry['tmp_name'][$key] ?? null) ? $entry['tmp_name'][$key] : '',
            'error'    => (int) $error,
            'size'     => (int) ($entry['size'][$key] ?? 0),
        ];
    }
    return $out;
}

/**
 * Check that a file really is an allowed image. Returns ['mime', 'ext', 'width', 'height'].
 * Throws UserError with a message for the user if it is not.
 */
function inspect_image(string $path): array
{
    $bytes = is_file($path) ? filesize($path) : false;
    if ($bytes === false || $bytes === 0) {
        throw new UserError('The image file is empty or missing.', 'image');
    }
    if ($bytes > IMAGE_MAX_BYTES) {
        throw new UserError('Images must be 5 MB or smaller.', 'image');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!is_string($mime) || !isset(IMAGE_TYPES[$mime])) {
        throw new UserError('Only JPEG, PNG, WebP and GIF images are allowed.', 'image');
    }
    $dim = @getimagesize($path);
    if ($dim === false || ($dim['mime'] ?? '') !== $mime || $dim[0] < 1 || $dim[1] < 1) {
        throw new UserError('That file is not a valid image.', 'image');
    }
    if ($dim[0] * $dim[1] > IMAGE_MAX_PIXELS) {
        throw new UserError('That image has too many pixels (40 megapixels at most).', 'image');
    }
    return ['mime' => $mime, 'ext' => IMAGE_TYPES[$mime], 'width' => $dim[0], 'height' => $dim[1]];
}

/** Absolute path for a project-relative path such as uploads/colorways/abc.jpg. */
function image_abs_path(string $relative): string
{
    return __DIR__ . '/' . $relative;
}

function ensure_image_dir(string $dir): void
{
    $abs = image_abs_path($dir);
    if (!is_dir($abs) && !mkdir($abs, 0755, true) && !is_dir($abs)) {
        throw new RuntimeException('Cannot create the image folder ' . $dir);
    }
}

/**
 * Check an uploaded image and store it under a new random name in $dir.
 * Returns the project-relative path (e.g. uploads/colorways/<32 hex>.png).
 * $isHttpUpload is false only in the command-line tests (real requests must pass true).
 */
function store_image(array $file, string $dir = IMAGE_DIR, bool $isHttpUpload = true): string
{
    $problem = upload_problem((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE));
    if ($problem !== null) {
        throw new UserError($problem, 'upload');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp) || ($isHttpUpload && !is_uploaded_file($tmp))) {
        throw new UserError('The image upload failed. Please choose the file again.', 'upload');
    }
    $info = inspect_image($tmp);

    ensure_image_dir($dir);
    $relative = $dir . '/' . bin2hex(random_bytes(16)) . '.' . $info['ext'];
    $target   = image_abs_path($relative);
    $moved    = $isHttpUpload ? move_uploaded_file($tmp, $target) : copy($tmp, $target);
    if (!$moved) {
        throw new RuntimeException('Could not store the uploaded image.');
    }
    @chmod($target, 0644);
    return $relative;
}

/**
 * Delete an image file - but only one of ours: uploads/colorways or uploads/staging,
 * random name, image extension. Anything else (e.g. ../../db.php) is refused.
 */
function delete_image_file(?string $relative): bool
{
    if ($relative === null || !preg_match(IMAGE_PATH_RE, $relative)) {
        return false;
    }
    $root = realpath(__DIR__ . '/uploads');
    $abs  = realpath(image_abs_path($relative));
    if ($root === false || $abs === false || !str_starts_with($abs, $root . DIRECTORY_SEPARATOR) || !is_file($abs)) {
        return false;
    }
    return @unlink($abs);
}

/** The address to show for a colorway image (uploaded file or link), or null if it has none. */
function image_src(?string $imagePath, ?string $imageUrl): ?string
{
    if ($imagePath !== null && preg_match(IMAGE_PATH_RE, $imagePath)) {
        return $imagePath;
    }
    if ($imageUrl !== null && image_url_problem($imageUrl) === null) {
        return $imageUrl;
    }
    return null;
}

/* ---------------------------------------------------------------------------------------
   Keeping chosen images while a form is corrected ("staging")
   When a form has an error, a correctly uploaded image is kept for this signed-in session,
   so the user does not have to choose it again. The form only carries a token; the file
   path is looked up in the session, so nobody can claim someone else's image.
   --------------------------------------------------------------------------------------- */

function stage_image(array $file, bool $isHttpUpload = true): string
{
    purge_staged_images();
    $path  = store_image($file, IMAGE_STAGING_DIR, $isHttpUpload);
    $token = substr(basename($path), 0, 32);
    $_SESSION['staged_images'][$token] = $path;

    while (count($_SESSION['staged_images']) > IMAGE_STAGING_MAX) {   // keep the newest ones
        $oldest = array_key_first($_SESSION['staged_images']);
        forget_staged_image((string) $oldest);
    }
    return $token;
}

function staged_image_path(string $token): ?string
{
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
        return null;
    }
    $path = $_SESSION['staged_images'][$token] ?? null;
    return is_string($path) && preg_match(IMAGE_PATH_RE, $path) && is_file(image_abs_path($path)) ? $path : null;
}

/**
 * Copy a staged image into the colorway image folder for the record being saved.
 * The staged copy is removed only after the save succeeds ($changes->commit()).
 */
function use_staged_image(string $token, ImageChanges $changes): string
{
    $source = staged_image_path($token);
    if ($source === null) {
        throw new UserError('The image chosen earlier has expired. Please choose it again.', 'upload');
    }
    ensure_image_dir(IMAGE_DIR);
    $relative = IMAGE_DIR . '/' . bin2hex(random_bytes(16)) . '.' . pathinfo($source, PATHINFO_EXTENSION);
    if (!copy(image_abs_path($source), image_abs_path($relative))) {
        throw new RuntimeException('Could not copy the staged image.');
    }
    $changes->added($relative);
    $changes->usedStaged($token);
    return $relative;
}

function forget_staged_image(string $token): void
{
    $path = staged_image_path($token);
    if ($path !== null) {
        delete_image_file($path);
    }
    unset($_SESSION['staged_images'][$token]);
}

/** Remove staged images older than $maxAge seconds (from any session) and stale session entries. */
function purge_staged_images(int $maxAge = IMAGE_STAGING_TTL): int
{
    $removed = 0;
    $dir = image_abs_path(IMAGE_STAGING_DIR);
    if (is_dir($dir)) {
        foreach (glob($dir . '/*') ?: [] as $file) {
            $name = basename($file);
            if (preg_match('/^[0-9a-f]{32}\.(jpg|png|webp|gif)$/', $name) && is_file($file)
                && filemtime($file) < time() - $maxAge && @unlink($file)) {
                $removed++;
            }
        }
    }
    foreach ($_SESSION['staged_images'] ?? [] as $token => $path) {
        if (!is_string($path) || !is_file(image_abs_path($path))) {
            unset($_SESSION['staged_images'][$token]);
        }
    }
    return $removed;
}

/**
 * Files touched while saving one form.
 *   added()             a file this request created (deleted again by rollback())
 *   deleteAfterCommit() an old file that is being replaced (deleted only by commit())
 *   usedStaged()        a staged image that is no longer needed once the save succeeded
 */
final class ImageChanges
{
    private array $added = [];
    private array $obsolete = [];
    private array $staged = [];

    public function added(string $path): void
    {
        $this->added[] = $path;
    }

    public function deleteAfterCommit(?string $path): void
    {
        if ($path !== null && $path !== '') {
            $this->obsolete[] = $path;
        }
    }

    public function usedStaged(string $token): void
    {
        $this->staged[] = $token;
    }

    /** The save failed: remove the new files. Old images and staged images stay untouched. */
    public function rollback(): void
    {
        foreach ($this->added as $path) {
            delete_image_file($path);
        }
        $this->added = $this->obsolete = $this->staged = [];
    }

    /** The save succeeded: remove replaced images and the staged copies that were used. */
    public function commit(): void
    {
        foreach ($this->obsolete as $path) {
            delete_image_file($path);
        }
        foreach ($this->staged as $token) {
            forget_staged_image($token);
        }
        $this->added = $this->obsolete = $this->staged = [];
    }
}

/** Bytes for a php.ini size such as "40M". */
function ini_bytes(string $value): int
{
    $value = trim($value);
    $n = (int) $value;
    return match (strtolower(substr($value, -1))) {
        'g'     => $n * 1024 ** 3,
        'm'     => $n * 1024 ** 2,
        'k'     => $n * 1024,
        default => $n,
    };
}

/**
 * True when the browser sent more than post_max_size. PHP then silently drops ALL fields and
 * files (including the CSRF token), so pages should show "too large" instead of a token error.
 */
function request_too_large(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !$_POST && !$_FILES
        && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > ini_bytes((string) ini_get('post_max_size'));
}
