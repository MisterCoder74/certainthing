<?php
/**
 * API: Agentic Workspace — Phase B file actions.
 * Location: ./api/agentic_files.php
 *
 * Mirrors deploy/deploy_manager.php's proven CRUD + path-safety patterns
 * (dm_sanitizePath/dm_isInsideBase -> agentic_sanitize_path/agentic_is_inside_base),
 * scoped to a single sandbox workspace instead of a deploy folder. Deliberately a
 * sibling endpoint rather than an edit to deploy_manager.php itself — that file is
 * ~1,100 lines already live in production, and this cell already lost content once
 * by overwriting a live shared file from a stale local copy. A sibling avoids that
 * risk entirely: nothing here touches deploy_manager.php or the deploy/ path.
 *
 * Every action requires workspace_id (32-char hex) to scope the whole request to
 * one sandbox workspace under AGENTIC_DIR/{user_id}/workspaces/{workspace_id}/ —
 * never any other path (sandbox_isolation_directive).
 *
 * Actions:
 *   GET  ?action=tree&workspace_id=..                       -> {tree:[...]}
 *   GET  ?action=get_content&workspace_id=..&file=..         -> {ok,content,name}
 *   GET  ?action=download&workspace_id=..&file=..            -> raw file, attachment
 *   GET  ?action=download_zip&workspace_id=..&folder=..(opt) -> zip, attachment
 *   POST ?action=save_file      {workspace_id, file, content}
 *   POST ?action=delete_item    {workspace_id, file}
 *   POST ?action=rename_file    {workspace_id, file, newName}
 *   POST ?action=move_item      {workspace_id, file, destFolder}
 *   POST ?action=create_file    {workspace_id, file}
 *   POST ?action=create_folder  {workspace_id, folder}
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/agentic_helpers.php';
require_once __DIR__ . '/predeploy_checks.php';
require_once __DIR__ . '/audit_log.php';
check_auth();

// JSON POST bodies (save_file/delete_item/rename_file/move_item/create_file/create_folder)
// carry workspace_id INSIDE the body, not in $_POST — PHP never populates $_POST for a
// non form-encoded body. Decode once up front so every field (workspace_id included) has
// one consistent source, and reuse $jsonBody below instead of re-reading php://input.
$jsonBody = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $jsonBody = json_decode(file_get_contents('php://input'), true) ?? [];
}

$user_id      = $_SESSION['user_id'];
$workspace_id = $_GET['workspace_id'] ?? $_POST['workspace_id'] ?? $jsonBody['workspace_id'] ?? '';

if (!preg_match('/^[a-f0-9]{32}$/', $workspace_id)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing workspace id.']);
    exit;
}

$baseDir = rtrim(AGENTIC_DIR, '/') . '/' . $user_id . '/workspaces/' . $workspace_id;
if (!is_dir($baseDir) || !agentic_is_inside_base($baseDir, rtrim(AGENTIC_DIR, '/') . '/' . $user_id . '/workspaces')) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Workspace not found.']);
    exit;
}
agentic_touch_workspace($baseDir); // Phase E: any file action counts as activity

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Download handler (non-JSON) ─────────────────────────────────────────────
if ($action === 'download') {
    $file = agentic_sanitize_path($_GET['file'] ?? '');
    $full = $baseDir . '/' . $file;
    if (!$file || !is_file($full) || !agentic_is_inside_base($full, $baseDir)) {
        http_response_code(404);
        echo 'File not found';
        exit;
    }
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($full) . '"');
    header('Content-Length: ' . filesize($full));
    readfile($full);
    exit;
}

// ── ZIP download handler (whole workspace, or a subfolder; non-JSON) ───────
if ($action === 'download_zip') {
    $folder = agentic_sanitize_path($_GET['folder'] ?? '');
    $full   = $baseDir . ($folder ? '/' . $folder : '');

    if (!is_dir($full) || !agentic_is_inside_base($full, $baseDir)) {
        http_response_code(404);
        echo 'Folder not found';
        exit;
    }
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        echo 'ZIP support (ZipArchive) is not available on this server';
        exit;
    }

    $tmpZip = tempnam(sys_get_temp_dir(), 'agentic_zip_');
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
        if ($relative === '' || $relative === '.agentic_meta.json') continue;
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

    $zipName = ($folder ? basename($folder) : 'workspace') . '.zip';
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

// ── JSON endpoints ───────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

switch ($action) {

    case 'tree':
        echo json_encode(['ok' => true, 'tree' => agentic_build_tree($baseDir, $baseDir)]);
        exit;

    case 'get_content': {
        $file = agentic_sanitize_path($_GET['file'] ?? '');
        $full = $baseDir . '/' . $file;
        if (!$file || !is_file($full) || !agentic_is_inside_base($full, $baseDir)) {
            echo json_encode(['ok' => false, 'error' => 'File not found']);
            exit;
        }
        if (filesize($full) > 2 * 1024 * 1024) {
            echo json_encode(['ok' => false, 'error' => 'File too large to view/edit inline (use Download instead).']);
            exit;
        }
        echo json_encode([
            'ok'      => true,
            'content' => file_get_contents($full),
            'name'    => basename($file),
        ]);
        exit;
    }

    case 'save_file': {
        $input   = $jsonBody;
        $file    = agentic_sanitize_path($input['file'] ?? '');
        $content = $input['content'] ?? '';
        $full    = $baseDir . '/' . $file;
        if (!$file || !is_file($full) || !agentic_is_inside_base($full, $baseDir)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid file']);
            exit;
        }
        // Pre-deploy check: this workspace is what agentic_deploy.php later copies
        // byte-for-byte to the live folder, so gate the syntax here at save time
        // rather than discovering a broken PHP/JS file only after it's already live.
        $check = predeploy_check_file(basename($file), $content);
        if ($check['status'] === 'error') {
            echo json_encode(['ok' => false, 'error' => 'Syntax check failed: ' . $check['message']]);
            exit;
        }
        file_put_contents($full, $content);
        audit_log_event('file_save', ['workspace_id' => $workspace_id, 'file' => $file]);
        echo json_encode([
            'ok'                 => true,
            'predeploy_warning'  => $check['status'] === 'warning' ? $check['message'] : null,
        ]);
        exit;
    }

    case 'delete_item': {
        $input = $jsonBody;
        $file  = agentic_sanitize_path($input['file'] ?? '');
        $full  = $baseDir . '/' . $file;
        if (!$file || !file_exists($full) || !agentic_is_inside_base($full, $baseDir)) {
            echo json_encode(['ok' => false, 'error' => 'Item not found']);
            exit;
        }
        $wasDir = is_dir($full);
        if ($wasDir) {
            agentic_rrmdir($full);
        } else {
            unlink($full);
        }
        audit_log_event('item_delete', ['workspace_id' => $workspace_id, 'file' => $file, 'was_folder' => $wasDir]);
        echo json_encode(['ok' => true]);
        exit;
    }

    case 'rename_file': {
        $input   = $jsonBody;
        $oldFile = agentic_sanitize_path($input['file'] ?? '');
        $newName = agentic_sanitize_path($input['newName'] ?? '');
        $oldFull = $baseDir . '/' . $oldFile;

        if (!$oldFile || !$newName) {
            echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
            exit;
        }
        if (!file_exists($oldFull) || !agentic_is_inside_base($oldFull, $baseDir)) {
            echo json_encode(['ok' => false, 'error' => 'Item not found']);
            exit;
        }
        // newName must be a bare filename, not a path (no nested move via rename).
        if (strpos($newName, '/') !== false) {
            echo json_encode(['ok' => false, 'error' => 'New name cannot contain a path.']);
            exit;
        }

        $parentDir = dirname($oldFull);
        $newFull   = $parentDir . '/' . $newName;

        if (file_exists($newFull)) {
            echo json_encode(['ok' => false, 'error' => 'An item with that name already exists']);
            exit;
        }
        if (rename($oldFull, $newFull)) {
            audit_log_event('item_rename', ['workspace_id' => $workspace_id, 'from' => $oldFile, 'to' => $newName]);
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Rename failed']);
        }
        exit;
    }

    case 'move_item': {
        $input      = $jsonBody;
        $file       = agentic_sanitize_path($input['file'] ?? '');
        $destFolder = agentic_sanitize_path($input['destFolder'] ?? ''); // '' = workspace root
        $srcFull    = $baseDir . '/' . $file;

        if (!$file || !file_exists($srcFull) || !agentic_is_inside_base($srcFull, $baseDir)) {
            echo json_encode(['ok' => false, 'error' => 'Item not found']);
            exit;
        }
        $destDir = $destFolder ? ($baseDir . '/' . $destFolder) : $baseDir;
        if (!is_dir($destDir) || !agentic_is_inside_base($destDir, $baseDir)) {
            echo json_encode(['ok' => false, 'error' => 'Destination folder not found']);
            exit;
        }
        // Guard against "move a folder into its own descendant" BEFORE building $newFull:
        // $newFull doesn't exist yet at this point, so realpath() on it always fails and
        // a check against it can never catch anything — compare the already-existing
        // $destDir and $srcFull instead (both are real, resolvable paths here).
        if (is_dir($srcFull)) {
            $realSrc  = realpath($srcFull);
            $realDest = realpath($destDir);
            if ($realDest === $realSrc || str_starts_with($realDest . '/', $realSrc . '/')) {
                echo json_encode(['ok' => false, 'error' => 'Cannot move a folder into itself or its own subfolder.']);
                exit;
            }
        }
        $newFull = rtrim($destDir, '/') . '/' . basename($srcFull);
        if ($newFull === $srcFull) {
            echo json_encode(['ok' => false, 'error' => 'Item is already in that folder.']);
            exit;
        }
        if (file_exists($newFull)) {
            echo json_encode(['ok' => false, 'error' => 'An item with that name already exists in the destination.']);
            exit;
        }
        if (rename($srcFull, $newFull)) {
            audit_log_event('item_move', ['workspace_id' => $workspace_id, 'file' => $file, 'dest_folder' => $destFolder]);
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Move failed']);
        }
        exit;
    }

    case 'create_file': {
        $input = $jsonBody;
        $file  = agentic_sanitize_path($input['file'] ?? '');
        $full  = $baseDir . '/' . $file;
        if (!$file) {
            echo json_encode(['ok' => false, 'error' => 'No filename']);
            exit;
        }
        $parent = dirname($full);
        if (!is_dir($parent) || !agentic_is_inside_base($parent, $baseDir)) {
            echo json_encode(['ok' => false, 'error' => 'Parent folder not found']);
            exit;
        }
        if (file_exists($full)) {
            echo json_encode(['ok' => false, 'error' => 'File already exists']);
            exit;
        }
        file_put_contents($full, '');
        audit_log_event('file_create', ['workspace_id' => $workspace_id, 'file' => $file]);
        echo json_encode(['ok' => true]);
        exit;
    }

    case 'create_folder': {
        $input  = $jsonBody;
        $folder = agentic_sanitize_path($input['folder'] ?? '');
        $full   = $baseDir . '/' . $folder;
        if (!$folder) {
            echo json_encode(['ok' => false, 'error' => 'No folder name']);
            exit;
        }
        $parent = dirname($full);
        if (!is_dir($parent) || !agentic_is_inside_base($parent, $baseDir)) {
            echo json_encode(['ok' => false, 'error' => 'Parent folder not found']);
            exit;
        }
        if (file_exists($full)) {
            echo json_encode(['ok' => false, 'error' => 'An item with that name already exists']);
            exit;
        }
        mkdir($full, 0755, true);
        audit_log_event('folder_create', ['workspace_id' => $workspace_id, 'folder' => $folder]);
        echo json_encode(['ok' => true]);
        exit;
    }

    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
        exit;
}
