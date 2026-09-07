<?php
/**
 * API: Persistent multi-image asset manager (BYOK-era "Assets" library).
 * Location: ./api/assets.php
 *
 * Design (approved 2026-08-15): the existing global `assets/images/` folder
 * stays the single physical file store used by generated code and by
 * deploy.php's "copy only referenced images" logic — no change there. This
 * endpoint adds a proper front door on top of it: persistent upload, a
 * per-user metadata sidecar (label, upload date, size, dimensions), rename
 * (label), replace-in-place (same filename, new bytes — every file that
 * references it just picks up the new version on next deploy), delete, and
 * a best-effort "where is this used" reverse lookup across the user's own
 * chat sessions (same assets/images/... regex deploy.php already uses).
 *
 * Storage: data/keys/<userId>.assets.json — same directory/pattern as
 * settings/prompts/audit log/usage log (config.php), NOT assets/images/ itself
 * (that folder holds bytes only; ownership/labels live in the per-user file).
 *
 * A user may only rename/delete/replace assets they themselves uploaded
 * (tracked via 'owner' in the metadata). Pre-existing files physically in
 * `assets/images/` (the stock hero catalog plus anything dropped in before
 * this endpoint existed) have no per-user metadata entry and are merged in
 * at list time as read-only "library" entries — visible and selectable for
 * "Use selected in prompt", but not owned by anyone so label/replace/delete
 * stay locked (see assets_merge_library_entries()).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/audit_log.php';
check_auth();

header('Content-Type: application/json');

define('ASSETS_IMAGES_DIR', __DIR__ . '/../assets/images');
define('ASSET_MAX_BYTES', 8 * 1024 * 1024); // 8MB per image
define('ASSET_ALLOWED_MIME', ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp']);
define('ASSET_SCAN_EXTENSIONS', ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg']);

function assets_meta_file(): ?string {
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) return null;
    return __DIR__ . '/../data/keys/' . $userId . '.assets.json';
}

function get_user_assets(): array {
    $file = assets_meta_file();
    if (!$file || !file_exists($file)) return [];
    $saved = safe_read_json($file);
    return is_array($saved) ? $saved : [];
}

function save_user_assets(array $assets): bool {
    $file = assets_meta_file();
    if (!$file) return false;
    if (empty($assets)) {
        return file_exists($file) ? unlink($file) : true;
    }
    return safe_write_json($file, $assets);
}

/** Label lookup for pre-existing files: catalog description, else placeholder description. */
function assets_catalog_labels(): array {
    $indexFile = __DIR__ . '/../styles/images_index.json';
    if (!file_exists($indexFile)) return [];
    $data = safe_read_json($indexFile);
    if (!is_array($data)) return [];

    $labels = [];
    foreach (($data['catalog'] ?? []) as $filename => $entry) {
        $labels[$filename] = is_array($entry) ? ($entry['description'] ?? '') : '';
    }
    foreach (($data['placeholders'] ?? []) as $filename => $desc) {
        $labels[$filename] = is_string($desc) ? $desc : '';
    }
    return $labels;
}

/**
 * Any physical file in assets/images/ (and its immediate subfolders, e.g.
 * `heroes/`) not present in this user's own metadata is a pre-existing /
 * shared library file: surface it as a read-only entry so it shows up
 * alongside the user's own uploads instead of silently disappearing.
 */
function assets_scan_library_files(array $ownedSlugs): array {
    if (!is_dir(ASSETS_IMAGES_DIR)) return [];
    $labels = assets_catalog_labels();
    $found = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(ASSETS_IMAGES_DIR, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) continue;
        $ext = strtolower($fileInfo->getExtension());
        if (!in_array($ext, ASSET_SCAN_EXTENSIONS, true)) continue;

        $slug = ltrim(str_replace(
            ASSETS_IMAGES_DIR,
            '',
            $fileInfo->getPathname()
        ), '/\\');
        $slug = str_replace('\\', '/', $slug);
        if (isset($ownedSlugs[$slug])) continue; // already covered by user's own metadata

        $baseName = basename($slug);
        $found[$slug] = [
            'original_name' => $baseName,
            'label'         => $labels[$baseName] ?? '',
            'uploaded_at'   => date('c', $fileInfo->getMTime()),
            'size'          => $fileInfo->getSize(),
            'owner'         => null,
        ];
    }
    return $found;
}

function assets_response_entry(array $meta, string $slug): array {
    $path = ASSETS_IMAGES_DIR . '/' . $slug;
    $exists = file_exists($path);
    $dims = $exists ? @getimagesize($path) : false;
    $userId = $_SESSION['user_id'] ?? null;
    return [
        'slug'         => $slug,
        'original_name'=> $meta['original_name'] ?? $slug,
        'label'        => $meta['label'] ?? '',
        'uploaded_at'  => $meta['uploaded_at'] ?? null,
        'size'         => $exists ? filesize($path) : ($meta['size'] ?? 0),
        'width'        => $dims ? $dims[0] : ($meta['width'] ?? null),
        'height'       => $dims ? $dims[1] : ($meta['height'] ?? null),
        'reference'    => 'assets/images/' . $slug,
        'missing'      => !$exists,
        'owner_mine'   => array_key_exists('owner', $meta) && $meta['owner'] !== null && $meta['owner'] === $userId,
    ];
}

/** Best-effort reverse lookup: does the user's own session data reference this slug? */
function assets_used_in(string $slug): array {
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) return [];
    $pattern = SESSIONS_DIR . '/' . $userId . '_*.json';
    $files = glob($pattern) ?: [];
    $needle = 'assets/images/' . $slug;
    $hits = [];
    foreach ($files as $f) {
        $content = @file_get_contents($f);
        if ($content !== false && strpos($content, $needle) !== false) {
            $sessionId = preg_replace('/^' . preg_quote($userId . '_', '/') . '/', '', basename($f, '.json'));
            $hits[] = $sessionId;
        }
    }
    return $hits;
}

/** Build a unique, filesystem-safe slug/filename for a newly uploaded asset. */
function assets_make_slug(string $originalName, string $ext): string {
    $base = pathinfo($originalName, PATHINFO_FILENAME);
    $base = preg_replace('/[^A-Za-z0-9_\-]+/', '-', $base);
    $base = trim($base, '-');
    if ($base === '') {
        $base = 'image';
    }
    $base = mb_substr($base, 0, 60);

    $slug = $base . '.' . $ext;
    $i = 1;
    while (file_exists(ASSETS_IMAGES_DIR . '/' . $slug)) {
        $slug = $base . '-' . $i . '.' . $ext;
        $i++;
    }
    return $slug;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

if ($method === 'GET') {
    if ($action === 'used_in') {
        $slug = trim((string) ($_GET['slug'] ?? ''));
        if ($slug === '' || strpos($slug, '/') !== false || strpos($slug, '..') !== false) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid slug']);
            exit;
        }
        echo json_encode(['ok' => true, 'slug' => $slug, 'sessions' => assets_used_in($slug)]);
        exit;
    }

    // default: list — own uploads (from per-user metadata) merged with any
    // pre-existing/shared physical file not yet tracked in that metadata.
    $assets = get_user_assets();
    $libraryAssets = assets_scan_library_files($assets);
    $allAssets = $assets + $libraryAssets;

    $entries = [];
    foreach ($allAssets as $slug => $meta) {
        $entries[] = assets_response_entry($meta, $slug);
    }
    usort($entries, fn($a, $b) => strcmp($b['uploaded_at'] ?? '', $a['uploaded_at'] ?? ''));
    echo json_encode(['ok' => true, 'assets' => $entries]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

if ($action === 'upload' || $action === 'replace') {
    if (!isset($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No file uploaded']);
        exit;
    }
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Upload failed']);
        exit;
    }
    if ($file['size'] > ASSET_MAX_BYTES) {
        http_response_code(400);
        echo json_encode(['error' => 'Image too large. Maximum size is 8MB.']);
        exit;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!isset(ASSET_ALLOWED_MIME[$mime])) {
        http_response_code(400);
        echo json_encode(['error' => 'Unsupported image type: ' . $mime . '. Use JPEG, PNG or WebP.']);
        exit;
    }
    $ext = ASSET_ALLOWED_MIME[$mime];

    if (!is_dir(ASSETS_IMAGES_DIR)) {
        @mkdir(ASSETS_IMAGES_DIR, 0755, true);
    }

    $assets = get_user_assets();
    $userId = $_SESSION['user_id'];

    if ($action === 'replace') {
        $slug = trim((string) ($_POST['slug'] ?? ''));
        if ($slug === '' || !isset($assets[$slug])) {
            http_response_code(404);
            echo json_encode(['error' => 'Asset not found']);
            exit;
        }
        if (($assets[$slug]['owner'] ?? null) !== $userId) {
            http_response_code(403);
            echo json_encode(['error' => 'You can only replace assets you uploaded']);
            exit;
        }
        $dest = ASSETS_IMAGES_DIR . '/' . $slug;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            http_response_code(500);
            echo json_encode(['error' => 'Could not save file']);
            exit;
        }
        $dims = @getimagesize($dest);
        $assets[$slug]['size']        = filesize($dest);
        $assets[$slug]['width']       = $dims ? $dims[0] : null;
        $assets[$slug]['height']      = $dims ? $dims[1] : null;
        $assets[$slug]['uploaded_at'] = date('c');
        save_user_assets($assets);
        audit_log_event('asset_replace', ['slug' => $slug]);
        echo json_encode(['ok' => true, 'asset' => assets_response_entry($assets[$slug], $slug)]);
        exit;
    }

    // upload (new asset)
    $originalName = $file['name'];
    $slug = assets_make_slug($originalName, $ext);
    $dest = ASSETS_IMAGES_DIR . '/' . $slug;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not save file']);
        exit;
    }
    $dims = @getimagesize($dest);
    $meta = [
        'original_name' => $originalName,
        'label'         => trim((string) ($_POST['label'] ?? '')),
        'uploaded_at'   => date('c'),
        'size'          => filesize($dest),
        'width'         => $dims ? $dims[0] : null,
        'height'        => $dims ? $dims[1] : null,
        'owner'         => $userId,
    ];
    $assets[$slug] = $meta;
    save_user_assets($assets);
    audit_log_event('asset_upload', ['slug' => $slug, 'original_name' => $originalName]);
    echo json_encode(['ok' => true, 'asset' => assets_response_entry($meta, $slug)]);
    exit;
}

if ($action === 'label') {
    $slug  = trim((string) ($_POST['slug'] ?? ''));
    $label = trim((string) ($_POST['label'] ?? ''));
    $assets = get_user_assets();
    if ($slug === '' || !isset($assets[$slug])) {
        http_response_code(404);
        echo json_encode(['error' => 'Asset not found']);
        exit;
    }
    if (($assets[$slug]['owner'] ?? null) !== $_SESSION['user_id']) {
        http_response_code(403);
        echo json_encode(['error' => 'You can only edit assets you uploaded']);
        exit;
    }
    $assets[$slug]['label'] = mb_substr($label, 0, 80);
    save_user_assets($assets);
    audit_log_event('asset_label', ['slug' => $slug]);
    echo json_encode(['ok' => true, 'asset' => assets_response_entry($assets[$slug], $slug)]);
    exit;
}

if ($action === 'delete') {
    $slug = trim((string) ($_POST['slug'] ?? ''));
    $assets = get_user_assets();
    if ($slug === '' || !isset($assets[$slug])) {
        http_response_code(404);
        echo json_encode(['error' => 'Asset not found']);
        exit;
    }
    if (($assets[$slug]['owner'] ?? null) !== $_SESSION['user_id']) {
        http_response_code(403);
        echo json_encode(['error' => 'You can only delete assets you uploaded']);
        exit;
    }
    $path = ASSETS_IMAGES_DIR . '/' . $slug;
    if (file_exists($path)) {
        @unlink($path);
    }
    unset($assets[$slug]);
    save_user_assets($assets);
    audit_log_event('asset_delete', ['slug' => $slug]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
