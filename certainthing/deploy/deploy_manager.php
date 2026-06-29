<?php
/**
 * CertainThing – Deploy Manager
 * Location: ./deploy/deploy_manager.php
 * Users file: ../../data/users.json (relative to this file)
 *
 * Provides authenticated file browsing, editing, deletion,
 * viewing and link-sharing for user deploy folders.
 */
session_start();

$usersFile = __DIR__ . '/../../data/users.json';

// ── Helpers ─────────────────────────────────────────────────────────────────
function dm_loadUsers(string $file): array {
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function dm_sanitizePath(string $path): string {
    $path = str_replace("\0", '', $path);
    // Remove any ".." segments
    $parts = explode('/', $path);
    $clean = [];
    foreach ($parts as $p) {
        if ($p === '' || $p === '.' || $p === '..') continue;
        $clean[] = $p;
    }
    return implode('/', $clean);
}

function dm_isInsideBase(string $target, string $baseDir): bool {
    $realBase = realpath($baseDir);
    if ($realBase === false) return false;
    $realTarget = realpath($target);
    if ($realTarget === false) return false;
    return str_starts_with($realTarget . '/', $realBase . '/') || $realTarget === $realBase;
}

function dm_formatSize(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

function dm_baseUrl(): string {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $proto . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
}

function dm_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
    }
    rmdir($dir);
}

// ── Download handler (non-JSON, before other AJAX) ──────────────────────────
if (
    isset($_GET['action'], $_SESSION['dm_user_id']) &&
    $_GET['action'] === 'download'
) {
    $userId  = $_SESSION['dm_user_id'];
    $baseDir = __DIR__ . '/' . $userId;
    $file    = dm_sanitizePath($_GET['file'] ?? '');
    $full    = $baseDir . '/' . $file;

    if ($file && is_file($full) && dm_isInsideBase($full, $baseDir)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($full) . '"');
        header('Content-Length: ' . filesize($full));
        readfile($full);
    } else {
        http_response_code(404);
        echo 'File not found';
    }
    exit;
}

// ── ZIP download handler (whole deploy folder, non-JSON) ────────────────────
if (
    isset($_GET['action'], $_SESSION['dm_user_id']) &&
    $_GET['action'] === 'download_zip'
) {
    $userId  = $_SESSION['dm_user_id'];
    $baseDir = __DIR__ . '/' . $userId;
    $folder  = dm_sanitizePath($_GET['folder'] ?? '');
    $full    = $baseDir . ($folder ? '/' . $folder : '');

    if (!$folder || !is_dir($full) || !dm_isInsideBase($full, $baseDir)) {
        http_response_code(404);
        echo 'Folder not found';
        exit;
    }
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        echo 'ZIP support (ZipArchive) is not available on this server';
        exit;
    }

    $tmpZip = tempnam(sys_get_temp_dir(), 'dm_zip_');
    $zip    = new ZipArchive();
    if (!$tmpZip || $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        echo 'Failed to create ZIP archive';
        if ($tmpZip) @unlink($tmpZip);
        exit;
    }

    $realFull = realpath($full);
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($full, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($items as $item) {
        $itemPath = $item->getRealPath();
        if ($itemPath === false) continue;
        $relative = ltrim(substr($itemPath, strlen($realFull)), '/\\');
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '') continue;
        if ($item->isDir()) {
            $zip->addEmptyDir($relative);
        } else {
            $zip->addFile($itemPath, $relative);
        }
    }
    $zip->close();

    if (!file_exists($tmpZip) || filesize($tmpZip) === 0) {
        http_response_code(500);
        echo 'ZIP generation produced an empty archive';
        @unlink($tmpZip);
        exit;
    }

    $zipName = basename($folder) . '.zip';
    header('Content-Description: File Transfer');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($tmpZip));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    readfile($tmpZip);
    unlink($tmpZip);
    exit;
}

// ── AJAX JSON endpoints ─────────────────────────────────────────────────────
if (isset($_GET['action']) && isset($_SESSION['dm_user_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $userId  = $_SESSION['dm_user_id'];
    $baseDir = __DIR__ . '/' . $userId;

    switch ($_GET['action']) {

        case 'get_content':
            $file = dm_sanitizePath($_GET['file'] ?? '');
            $full = $baseDir . '/' . $file;
            if (!$file || !is_file($full) || !dm_isInsideBase($full, $baseDir)) {
                echo json_encode(['ok' => false, 'error' => 'File not found']);
                exit;
            }
            echo json_encode([
                'ok'      => true,
                'content' => file_get_contents($full),
                'name'    => basename($file),
            ]);
            exit;

        case 'save_file':
            $input   = json_decode(file_get_contents('php://input'), true);
            $file    = dm_sanitizePath($input['file'] ?? '');
            $content = $input['content'] ?? '';
            $full    = $baseDir . '/' . $file;
            if (!$file || !is_file($full) || !dm_isInsideBase($full, $baseDir)) {
                echo json_encode(['ok' => false, 'error' => 'Invalid file']);
                exit;
            }
            file_put_contents($full, $content);
            echo json_encode(['ok' => true]);
            exit;

        case 'delete_item':
            $input = json_decode(file_get_contents('php://input'), true);
            $file  = dm_sanitizePath($input['file'] ?? '');
            $full  = $baseDir . '/' . $file;
            if (!$file || !file_exists($full) || !dm_isInsideBase($full, $baseDir)) {
                echo json_encode(['ok' => false, 'error' => 'Item not found']);
                exit;
            }
            if (is_dir($full)) {
                dm_rrmdir($full);
            } else {
                unlink($full);
            }
            echo json_encode(['ok' => true]);
            exit;

        case 'rename_file':
            $input   = json_decode(file_get_contents('php://input'), true);
            $oldFile = dm_sanitizePath($input['file'] ?? '');
            $newName = dm_sanitizePath($input['newName'] ?? '');
            $oldFull = $baseDir . '/' . $oldFile;

            if (!$oldFile || !$newName) {
                echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
                exit;
            }
            if (!file_exists($oldFull) || !dm_isInsideBase($oldFull, $baseDir)) {
                echo json_encode(['ok' => false, 'error' => 'Item not found']);
                exit;
            }

            $parentDir = dirname($oldFull);
            $newFull   = $parentDir . '/' . $newName;

            if (file_exists($newFull)) {
                echo json_encode(['ok' => false, 'error' => 'An item with that name already exists']);
                exit;
            }

            if (rename($oldFull, $newFull)) {
                echo json_encode(['ok' => true]);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Rename failed']);
            }
            exit;

        case 'create_file':
            $input = json_decode(file_get_contents('php://input'), true);
            $file  = dm_sanitizePath($input['file'] ?? '');
            $full  = $baseDir . '/' . $file;
            if (!$file) {
                echo json_encode(['ok' => false, 'error' => 'No filename']);
                exit;
            }
            $parent = dirname($full);
            if (!is_dir($parent)) {
                echo json_encode(['ok' => false, 'error' => 'Parent folder not found']);
                exit;
            }
            if (!dm_isInsideBase($parent, $baseDir)) {
                echo json_encode(['ok' => false, 'error' => 'Invalid path']);
                exit;
            }
            if (file_exists($full)) {
                echo json_encode(['ok' => false, 'error' => 'File already exists']);
                exit;
            }
            file_put_contents($full, '');
            echo json_encode(['ok' => true]);
            exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

// ── Logout ──────────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    unset($_SESSION['dm_user_id'], $_SESSION['dm_user_email']);
    // Set a flag so auto-detect won't immediately re-login
    $_SESSION['dm_logged_out'] = true;
    header('Location: deploy_manager.php');
    exit;
}

// ── Login POST ──────────────────────────────────────────────────────────────
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dm_login'])) {
    // Clear the logged-out flag on explicit login attempt
    unset($_SESSION['dm_logged_out']);

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $users    = dm_loadUsers($usersFile);
    $matched  = false;

    foreach ($users as $u) {
        if (strcasecmp($u['email'] ?? '', $email) === 0) {
            $matched = true;
            if (($u['status'] ?? '') !== 'enabled') {
                $loginError = 'This account is disabled.';
            } elseif (!password_verify($password, $u['password_hash'] ?? '')) {
                $loginError = 'Invalid email or password.';
            } else {
                $_SESSION['dm_user_id']    = $u['id'];
                $_SESSION['dm_user_email'] = $u['email'];
                header('Location: deploy_manager.php');
                exit;
            }
            break;
        }
    }
    if (!$matched) $loginError = 'Invalid email or password.';
}

// ── Auto-detect from main app session (only if user hasn't explicitly logged out) ──
if (
    !isset($_SESSION['dm_user_id']) &&
    empty($_SESSION['dm_logged_out']) &&
    isset($_SESSION['user_id'])
) {
    $_SESSION['dm_user_id']    = $_SESSION['user_id'];
    $_SESSION['dm_user_email'] = $_SESSION['user_email'] ?? '';
}

// ── Auth state ──────────────────────────────────────────────────────────────
$loggedIn  = isset($_SESSION['dm_user_id']);
$userId    = $_SESSION['dm_user_id'] ?? '';
$userEmail = $_SESSION['dm_user_email'] ?? '';

// ── Build file listing ──────────────────────────────────────────────────────
$currentPath = dm_sanitizePath($_GET['path'] ?? '');
$entries     = [];
$pathParts   = $currentPath ? explode('/', $currentPath) : [];

if ($loggedIn) {
    $baseDir = __DIR__ . '/' . $userId;
    $fullDir = $baseDir . ($currentPath ? '/' . $currentPath : '');

    if (is_dir($fullDir) && dm_isInsideBase($fullDir, $baseDir)) {
        $items = scandir($fullDir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $itemFull = $fullDir . '/' . $item;
            $entries[] = [
                'name'     => $item,
                'is_dir'   => is_dir($itemFull),
                'size'     => is_file($itemFull) ? filesize($itemFull) : 0,
                'modified' => filemtime($itemFull),
                'rel_path' => ($currentPath ? $currentPath . '/' : '') . $item,
            ];
        }
        // Sort: directories first, then alphabetical
        usort($entries, function ($a, $b) {
            if ($a['is_dir'] !== $b['is_dir']) return $b['is_dir'] - $a['is_dir'];
            return strcasecmp($a['name'], $b['name']);
        });
    }
}

$baseUrl = dm_baseUrl();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>✦ CertainThing – Deploy Manager</title>
    <style>
/* ── Reset & Base ────────────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, sans-serif;
    background: #0d1117;
    color: #c9d1d9;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}
a { color: #58a6ff; text-decoration: none; }
a:hover { text-decoration: underline; }

/* ── Header ──────────────────────────────────────────────────────────────── */
.dm-header {
    background: #161b22;
    border-bottom: 1px solid #30363d;
    padding: 12px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-shrink: 0;
}
.dm-logo {
    font-size: 1.15rem;
    font-weight: 600;
    color: #e6edf3;
    display: flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
}
.dm-logo .icon { color: #58a6ff; }
.dm-back-link {
    font-size: 0.8rem;
    color: #8b949e;
    padding: 5px 12px;
    border: 1px solid #30363d;
    border-radius: 6px;
    transition: color 0.15s, border-color 0.15s;
    text-decoration: none;
    white-space: nowrap;
}
.dm-back-link:hover { color: #58a6ff; border-color: #58a6ff; text-decoration: none; }
.dm-header-right {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 0.85rem;
}
.dm-user-email {
    color: #8b949e;
    max-width: 220px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* ── Buttons ─────────────────────────────────────────────────────────────── */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border: 1px solid #30363d;
    border-radius: 6px;
    background: #21262d;
    color: #c9d1d9;
    font-size: 0.8rem;
    cursor: pointer;
    transition: background 0.15s, border-color 0.15s;
    white-space: nowrap;
}
.btn:hover { background: #30363d; border-color: #484f58; }
.btn-primary { background: #238636; border-color: #2ea043; color: #fff; }
.btn-primary:hover { background: #2ea043; }
.btn-danger { background: #da3633; border-color: #f85149; color: #fff; }
.btn-danger:hover { background: #f85149; }
.btn-sm { padding: 4px 10px; font-size: 0.75rem; }
.btn-icon {
    padding: 4px 8px;
    font-size: 0.85rem;
    border: none;
    background: transparent;
    color: #8b949e;
    cursor: pointer;
    border-radius: 4px;
    transition: color 0.15s, background 0.15s;
}
.btn-icon:hover { color: #58a6ff; background: #1c2128; }
.btn-icon.danger:hover { color: #f85149; }

/* ── Main container ──────────────────────────────────────────────────────── */
.dm-main {
    flex: 1;
    max-width: 1100px;
    width: 100%;
    margin: 0 auto;
    padding: 24px;
}

/* ── Breadcrumbs ─────────────────────────────────────────────────────────── */
.dm-breadcrumbs {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.85rem;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.dm-breadcrumbs .sep { color: #484f58; }

/* ── Toolbar ─────────────────────────────────────────────────────────────── */
.dm-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
    gap: 12px;
}
.dm-toolbar-left {
    font-size: 0.85rem;
    color: #8b949e;
}

/* ── Table ────────────────────────────────────────────────────────────────── */
.dm-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}
.dm-table th {
    text-align: left;
    padding: 10px 12px;
    background: #161b22;
    border-bottom: 1px solid #30363d;
    color: #8b949e;
    font-weight: 600;
    white-space: nowrap;
}
.dm-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #21262d;
    vertical-align: middle;
}
.dm-table tr:hover td { background: #161b22; }
.dm-table .fname {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #e6edf3;
    font-weight: 500;
}
.dm-table .fname .ficon { font-size: 1rem; flex-shrink: 0; }
.dm-table .fname a { color: #58a6ff; }
.dm-table .fdate { color: #8b949e; white-space: nowrap; }
.dm-table .fsize { color: #8b949e; white-space: nowrap; text-align: right; }
.dm-table .factions {
    display: flex;
    gap: 4px;
    justify-content: flex-end;
    align-items: center;
}

/* ── Empty state ─────────────────────────────────────────────────────────── */
.dm-empty {
    text-align: center;
    padding: 60px 20px;
    color: #484f58;
}
.dm-empty .icon { font-size: 2.5rem; margin-bottom: 12px; }
.dm-empty p { font-size: 0.95rem; }

/* ── Login card ──────────────────────────────────────────────────────────── */
.dm-login-wrapper {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.dm-login-card {
    background: #161b22;
    border: 1px solid #30363d;
    border-radius: 10px;
    padding: 36px 32px;
    width: 100%;
    max-width: 380px;
}
.dm-login-card h2 {
    color: #e6edf3;
    font-size: 1.3rem;
    margin-bottom: 6px;
    text-align: center;
}
.dm-login-card .sub {
    color: #8b949e;
    font-size: 0.85rem;
    text-align: center;
    margin-bottom: 24px;
}
.dm-login-card label {
    display: block;
    font-size: 0.8rem;
    color: #8b949e;
    margin-bottom: 5px;
    font-weight: 500;
}
.dm-login-card input[type="email"],
.dm-login-card input[type="password"] {
    width: 100%;
    padding: 10px 12px;
    background: #0d1117;
    border: 1px solid #30363d;
    border-radius: 6px;
    color: #c9d1d9;
    font-size: 0.9rem;
    margin-bottom: 16px;
    outline: none;
    transition: border-color 0.15s;
}
.dm-login-card input:focus { border-color: #58a6ff; }
.dm-login-card .btn-primary {
    width: 100%;
    padding: 10px;
    font-size: 0.9rem;
    justify-content: center;
}
.dm-login-error {
    background: rgba(248, 81, 73, 0.1);
    border: 1px solid #f8514980;
    color: #f85149;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 0.8rem;
    margin-bottom: 16px;
    text-align: center;
}

/* ── Modal overlay ───────────────────────────────────────────────────────── */
.dm-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.65);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 999;
    padding: 20px;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.2s, visibility 0.2s;
}
.dm-modal-overlay.active { opacity: 1; visibility: visible; }
.dm-modal {
    background: #161b22;
    border: 1px solid #30363d;
    border-radius: 10px;
    width: 100%;
    max-width: 800px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
}
.dm-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 20px;
    border-bottom: 1px solid #30363d;
}
.dm-modal-header h3 {
    color: #e6edf3;
    font-size: 0.95rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.dm-modal-body {
    flex: 1;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.dm-modal-body textarea {
    flex: 1;
    width: 100%;
    min-height: 400px;
    padding: 16px;
    background: #0d1117;
    color: #c9d1d9;
    border: none;
    font-family: 'SF Mono', 'Fira Code', 'Cascadia Code', 'Consolas', monospace;
    font-size: 0.85rem;
    line-height: 1.6;
    resize: none;
    outline: none;
    tab-size: 4;
}
.dm-modal-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    padding: 12px 20px;
    border-top: 1px solid #30363d;
}

/* ── Toast ────────────────────────────────────────────────────────────────── */
.dm-toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    background: #238636;
    color: #fff;
    padding: 10px 18px;
    border-radius: 8px;
    font-size: 0.85rem;
    z-index: 1200;
    opacity: 0;
    transform: translateY(10px);
    transition: opacity 0.3s, transform 0.3s;
    pointer-events: none;
}
.dm-toast.error { background: #da3633; }
.dm-toast.visible { opacity: 1; transform: translateY(0); pointer-events: auto; }

/* ── Footer ──────────────────────────────────────────────────────────────── */
.dm-footer {
    text-align: center;
    padding: 14px 20px;
    font-size: 0.75rem;
    color: #484f58;
    border-top: 1px solid #21262d;
    flex-shrink: 0;
}

/* ── New-file inline row ─────────────────────────────────────────────────── */
.dm-new-file-row {
    display: none;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
}
.dm-new-file-row.active { display: flex; }
.dm-new-file-row input {
    flex: 1;
    padding: 8px 12px;
    background: #0d1117;
    border: 1px solid #30363d;
    border-radius: 6px;
    color: #c9d1d9;
    font-size: 0.85rem;
    outline: none;
}
.dm-new-file-row input:focus { border-color: #58a6ff; }

/* ── Responsive ──────────────────────────────────────────────────────────── */
@media (max-width: 640px) {
    .dm-main { padding: 16px; }
    .dm-table .fsize { display: none; }
    .dm-header { padding: 10px 16px; }
    .dm-modal { max-width: 100%; }
}
    </style>
</head>
<body>
<?php if (!$loggedIn): ?>
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!--  LOGIN VIEW                                                            -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<header class="dm-header">
    <div class="dm-logo"><span class="icon">✦</span> CertainThing</div>
    <a href="../index.php" class="dm-back-link">← Back to CertainThing</a>
</header>
<div class="dm-login-wrapper">
    <div class="dm-login-card">
        <h2>Deploy Manager</h2>
        <p class="sub">Sign in to manage your deployments</p>
        <?php if ($loginError): ?>
            <div class="dm-login-error"><?= htmlspecialchars($loginError) ?></div>
        <?php endif; ?>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="dm_login" value="1">
            <label for="dm-email">Email</label>
            <input type="email" name="email" id="dm-email" required autofocus
                   placeholder="you@example.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            <label for="dm-pass">Password</label>
            <input type="password" name="password" id="dm-pass" required placeholder="••••••••">
            <button type="submit" class="btn btn-primary">Sign In</button>
        </form>
    </div>
</div>
<?php else: ?>
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!--  FILE BROWSER VIEW                                                     -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<header class="dm-header">
    <div class="dm-logo"><span class="icon">✦</span> CertainThing – Deploys</div>
    <div class="dm-header-right">
        <a href="../index.php" class="dm-back-link">← Back to CertainThing</a>
        <span class="dm-user-email"><?= htmlspecialchars($userEmail) ?></span>
        <a href="deploy_manager.php?logout=1" class="btn btn-sm">Logout</a>
    </div>
</header>

<div class="dm-main">
    <!-- Breadcrumbs -->
    <nav class="dm-breadcrumbs">
        <a href="deploy_manager.php">🏠 Deployments</a>
        <?php
        $cumulative = '';
        foreach ($pathParts as $i => $part):
            $cumulative .= ($cumulative ? '/' : '') . $part;
        ?>
            <span class="sep">/</span>
            <?php if ($i < count($pathParts) - 1): ?>
                <a href="deploy_manager.php?path=<?= urlencode($cumulative) ?>"><?= htmlspecialchars($part) ?></a>
            <?php else: ?>
                <span style="color:#e6edf3;font-weight:500"><?= htmlspecialchars($part) ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <!-- Toolbar -->
    <div class="dm-toolbar">
        <div class="dm-toolbar-left">
            <?= count($entries) ?> item<?= count($entries) !== 1 ? 's' : '' ?>
        </div>
        <div>
            <?php if ($currentPath): // actions available inside a deploy folder ?>
                <button class="btn btn-sm" onclick="downloadZip('<?= htmlspecialchars($currentPath, ENT_QUOTES) ?>')">📦 Download ZIP</button>
                <button class="btn btn-sm" onclick="toggleNewFile()">+ New File</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- New file inline form -->
    <div class="dm-new-file-row" id="new-file-row">
        <input type="text" id="new-file-name" placeholder="filename.html" 
               onkeydown="if(event.key==='Enter')createFile();if(event.key==='Escape')toggleNewFile();">
        <button class="btn btn-primary btn-sm" onclick="createFile()">Create</button>
        <button class="btn btn-sm" onclick="toggleNewFile()">Cancel</button>
    </div>

    <?php if (empty($entries)): ?>
        <div class="dm-empty">
            <div class="icon">📁</div>
            <p><?= $currentPath ? 'This folder is empty.' : 'No deployments yet.' ?></p>
        </div>
    <?php else: ?>
        <table class="dm-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Modified</th>
                    <th style="text-align:right">Size</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $entry): ?>
                <tr>
                    <td>
                        <div class="fname">
                            <span class="ficon"><?= $entry['is_dir'] ? '📁' : '📄' ?></span>
                            <?php if ($entry['is_dir']): ?>
                                <a href="deploy_manager.php?path=<?= urlencode($entry['rel_path']) ?>">
                                    <?= htmlspecialchars($entry['name']) ?>
                                </a>
                            <?php else: ?>
                                <span><?= htmlspecialchars($entry['name']) ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="fdate">
                        <?= date('Y-m-d H:i', $entry['modified']) ?>
                    </td>
                    <td class="fsize">
                        <?= $entry['is_dir'] ? '—' : dm_formatSize($entry['size']) ?>
                    </td>
                    <td>
                        <div class="factions">
                            <?php if (!$entry['is_dir']): ?>
                                <button class="btn-icon" title="Edit"
                                    onclick="openEditor('<?= htmlspecialchars($entry['rel_path'], ENT_QUOTES) ?>')">✏️</button>
                                <a class="btn-icon" title="View in browser"
                                   href="<?= htmlspecialchars($baseUrl . '/' . $userId . '/' . $entry['rel_path']) ?>"
                                   target="_blank">🔗</a>
                                <button class="btn-icon" title="Download"
                                    onclick="location.href='deploy_manager.php?action=download&file=<?= urlencode($entry['rel_path']) ?>'">⬇️</button>
                                <button class="btn-icon" title="Rename"
                                    onclick="renameItem('<?= htmlspecialchars($entry['rel_path'], ENT_QUOTES) ?>', '<?= htmlspecialchars($entry['name'], ENT_QUOTES) ?>')">📝</button>
                                <button class="btn-icon" title="Copy link"
                                    onclick="copyLink('<?= htmlspecialchars($baseUrl . '/' . $userId . '/' . $entry['rel_path'], ENT_QUOTES) ?>')">📋</button>
                                <button class="btn-icon danger" title="Delete"
                                    onclick="deleteItem('<?= htmlspecialchars($entry['rel_path'], ENT_QUOTES) ?>', false)">🗑️</button>
                            <?php else: ?>
                                <a class="btn-icon" title="Open in browser"
                                   href="<?= htmlspecialchars($baseUrl . '/' . $userId . '/' . $entry['rel_path']) ?>/"
                                   target="_blank">🔗</a>
                                <button class="btn-icon" title="Download as ZIP"
                                    onclick="downloadZip('<?= htmlspecialchars($entry['rel_path'], ENT_QUOTES) ?>')">📦</button>
                                <button class="btn-icon" title="Rename"
                                    onclick="renameItem('<?= htmlspecialchars($entry['rel_path'], ENT_QUOTES) ?>', '<?= htmlspecialchars($entry['name'], ENT_QUOTES) ?>')">📝</button>
                                <button class="btn-icon" title="Copy link"
                                    onclick="copyLink('<?= htmlspecialchars($baseUrl . '/' . $userId . '/' . $entry['rel_path'], ENT_QUOTES) ?>/')">📋</button>
                                <button class="btn-icon danger" title="Delete folder"
                                    onclick="deleteItem('<?= htmlspecialchars($entry['rel_path'], ENT_QUOTES) ?>', true)">🗑️</button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- ── Edit Modal ─────────────────────────────────────────────────────────── -->
<div class="dm-modal-overlay" id="editor-overlay" onclick="if(event.target===this)closeEditor()">
    <div class="dm-modal">
        <div class="dm-modal-header">
            <h3 id="editor-title">Edit file</h3>
            <button class="btn-icon" onclick="closeEditor()">✕</button>
        </div>
        <div class="dm-modal-body">
            <textarea id="editor-textarea" spellcheck="false"></textarea>
        </div>
        <div class="dm-modal-footer">
            <button class="btn btn-sm" onclick="closeEditor()">Cancel</button>
            <button class="btn btn-primary btn-sm" id="editor-save-btn" onclick="saveFile()">Save</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Toast -->
<div class="dm-toast" id="dm-toast"></div>

<!-- Footer -->
<footer class="dm-footer">
    &copy; <?= date('Y') ?> CertainThing AI Assistant – by Vivacity Design AI Division. All rights reserved.
</footer>

<script>
/* ── Toast ───────────────────────────────────────────────────────────────── */
function showToast(msg, isError) {
    const el = document.getElementById('dm-toast');
    el.textContent = msg;
    el.classList.toggle('error', !!isError);
    el.classList.add('visible');
    clearTimeout(el._timer);
    el._timer = setTimeout(() => el.classList.remove('visible'), 3000);
}

/* ── Clipboard ──────────────────────────────────────────────────────────── */
function copyLink(url) {
    navigator.clipboard.writeText(url).then(
        () => showToast('Link copied to clipboard'),
        () => showToast('Failed to copy link', true)
    );
}

/* ── Download whole deploy folder as ZIP ─────────────────────────────────── */
function downloadZip(folder) {
    if (!folder) return;
    showToast('Preparing ZIP…');
    location.href = 'deploy_manager.php?action=download_zip&folder=' + encodeURIComponent(folder);
}

/* ── Delete ──────────────────────────────────────────────────────────────── */
function deleteItem(path, isDir) {
    const label = isDir ? 'this entire folder and all its contents' : 'this file';
    if (!confirm('Are you sure you want to delete ' + label + '?')) return;
    fetch('deploy_manager.php?action=delete_item', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ file: path })
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) location.reload();
        else showToast(d.error || 'Delete failed', true);
    })
    .catch(() => showToast('Network error', true));
}

/* ── Rename ──────────────────────────────────────────────────────────────── */
function renameItem(path, currentName) {
    const newName = prompt('New name:', currentName);
    if (!newName || newName === currentName) return;
    
    fetch('deploy_manager.php?action=rename_file', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ file: path, newName: newName })
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) location.reload();
        else showToast(d.error || 'Rename failed', true);
    })
    .catch(() => showToast('Network error', true));
}

/* ── Editor ──────────────────────────────────────────────────────────────── */
let editorFilePath = '';

function openEditor(path) {
    editorFilePath = path;
    document.getElementById('editor-title').textContent = 'Edit: ' + path.split('/').pop();
    document.getElementById('editor-textarea').value = 'Loading…';
    document.getElementById('editor-overlay').classList.add('active');
    document.getElementById('editor-textarea').readOnly = true;

    fetch('deploy_manager.php?action=get_content&file=' + encodeURIComponent(path))
        .then(r => r.json())
        .then(d => {
            const ta = document.getElementById('editor-textarea');
            if (d.ok) {
                ta.value = d.content;
                ta.readOnly = false;
                ta.focus();
            } else {
                ta.value = '// Error: ' + (d.error || 'Could not load file');
            }
        })
        .catch(() => {
            document.getElementById('editor-textarea').value = '// Network error';
        });
}

function closeEditor() {
    document.getElementById('editor-overlay').classList.remove('active');
    editorFilePath = '';
}

function saveFile() {
    if (!editorFilePath) return;
    const btn = document.getElementById('editor-save-btn');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    fetch('deploy_manager.php?action=save_file', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            file: editorFilePath,
            content: document.getElementById('editor-textarea').value
        })
    })
    .then(r => r.json())
    .then(d => {
        btn.disabled = false;
        btn.textContent = 'Save';
        if (d.ok) {
            showToast('File saved');
            closeEditor();
            location.reload();   // refresh modified date
        } else {
            showToast(d.error || 'Save failed', true);
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.textContent = 'Save';
        showToast('Network error', true);
    });
}

/* ── New file ────────────────────────────────────────────────────────────── */
function toggleNewFile() {
    const row = document.getElementById('new-file-row');
    row.classList.toggle('active');
    if (row.classList.contains('active')) {
        document.getElementById('new-file-name').value = '';
        document.getElementById('new-file-name').focus();
    }
}

function createFile() {
    const name = document.getElementById('new-file-name').value.trim();
    if (!name) return showToast('Enter a filename', true);
    const currentPath = new URLSearchParams(location.search).get('path') || '';
    const filePath = currentPath ? currentPath + '/' + name : name;

    fetch('deploy_manager.php?action=create_file', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ file: filePath })
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) location.reload();
        else showToast(d.error || 'Create failed', true);
    })
    .catch(() => showToast('Network error', true));
}

/* ── Keyboard shortcuts ──────────────────────────────────────────────────── */
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeEditor();
    // Ctrl+S / Cmd+S inside editor
    if ((e.ctrlKey || e.metaKey) && e.key === 's' && editorFilePath) {
        e.preventDefault();
        saveFile();
    }
});

/* ── Tab key in textarea ─────────────────────────────────────────────────── */
const ta = document.getElementById('editor-textarea');
if (ta) {
    ta.addEventListener('keydown', function(e) {
        if (e.key === 'Tab') {
            e.preventDefault();
            const start = this.selectionStart;
            const end = this.selectionEnd;
            this.value = this.value.substring(0, start) + '    ' + this.value.substring(end);
            this.selectionStart = this.selectionEnd = start + 4;
        }
    });
}
</script>
</body>
</html>
