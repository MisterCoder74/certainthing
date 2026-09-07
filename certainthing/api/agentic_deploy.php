<?php
/**
 * API: Agentic Workspace — Phase D, part 1 ("Deploy").
 * Location: ./api/agentic_deploy.php
 *
 * Copies the CURRENT full state of a sandbox workspace to a live-viewable folder,
 * preserving the real folder structure (unlike api/deploy.php, which flattens every
 * file to a single directory via basename() — fine for that endpoint's flat,
 * chat-generated file set, but wrong here: a ZIP-uploaded workspace has real
 * subdirectories that relative <link>/<script>/<img> references depend on).
 *
 * Uses direct filesystem copy (not file_get_contents/file_put_contents as strings)
 * so binary assets (images, fonts, etc.) survive byte-for-byte.
 *
 * Single-wrapper-folder flattening: a ZIP whose entries all sit under one top-level
 * folder (the common "GitHub download" / "Compress" shape, e.g. vd-ai-browser-web/…)
 * is extracted by Phase A with that wrapper folder preserved as-is — correct for the
 * file-tree UI (the user sees their own folder). But deploying it verbatim puts the
 * real entry file one directory below the deploy root, so the root itself has nothing
 * to serve and shows a bare directory listing. When the workspace root contains
 * exactly one item and it is a directory, this endpoint deploys that directory's
 * CONTENTS as the doc root instead of the wrapper folder itself.
 *
 * Entry-file detection: after flattening, looks for index.php/index.html/index.htm
 * (case-insensitive) at the effective doc root and points view_url directly at it.
 * If none exists (no naming convention to guess from), view_url falls back to the
 * deploy root — still a working directory listing the user can click through, since
 * every reference here is a real same-filesystem relative path (unlike the sandboxed
 * serve harness, nothing needs URL rewriting) — and the response says so explicitly
 * (`has_index: false`) so the caller can message it honestly instead of implying a
 * broken deploy.
 *
 * sandbox_isolation_directive: source is scoped under
 * AGENTIC_DIR/{user_id}/workspaces/{workspace_id}/ only. Destination is a NEW sibling
 * root, deploy/agentic/{user_id}/{workspace_id}/ — deliberately not deploy/{user_id}/{session_id}/
 * (api/deploy.php's own tree) to keep the two deploy origins (normal chat vs. sandbox
 * workspace) from ever colliding on the same directory.
 *
 * POST (application/json) { workspace_id } -> JSON:
 *   { ok:true,  count, view_url, has_index, root_files }
 *   { ok:false, error }
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/agentic_helpers.php';
check_auth();

header('Content-Type: application/json; charset=utf-8');

$jsonBody = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $jsonBody = json_decode(file_get_contents('php://input'), true) ?? [];
}

$user_id      = $_SESSION['user_id'];
$workspace_id = $jsonBody['workspace_id'] ?? '';

if (!preg_match('/^[a-f0-9]{32}$/', $workspace_id)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing workspace id.']);
    exit;
}

$workspacesRoot = rtrim(AGENTIC_DIR, '/') . '/' . $user_id . '/workspaces';
$srcDir         = $workspacesRoot . '/' . $workspace_id;
if (!is_dir($srcDir) || !agentic_is_inside_base($srcDir, $workspacesRoot)) {
    echo json_encode(['ok' => false, 'error' => 'Workspace not found.']);
    exit;
}
agentic_touch_workspace($srcDir); // Phase E: deploying counts as activity

// Deploy destination root, sibling to (never inside) the normal chat deploy/ tree.
$deployRoot = rtrim(AGENTIC_DIR, '/') . '/../deploy/agentic/' . $user_id;
$destDir    = $deployRoot . '/' . $workspace_id;

if (!is_dir($deployRoot)) {
    mkdir($deployRoot, 0755, true);
}
// Fresh deploy every time: clear out any previous copy so removed/renamed files in the
// workspace don't linger as stale files in the deployed copy.
if (is_dir($destDir)) {
    agentic_rrmdir($destDir);
}
mkdir($destDir, 0755, true);

// Flatten a single top-level wrapping folder: if the workspace root holds exactly one
// entry and it's a directory, that directory's contents become the doc root.
$copyFrom = $srcDir;
$rootEntries = array_values(array_diff(scandir($srcDir), ['.', '..', '.agentic_meta.json']));
if (count($rootEntries) === 1 && is_dir($srcDir . '/' . $rootEntries[0])) {
    $copyFrom = $srcDir . '/' . $rootEntries[0];
}

$copied = 0;
$realSrc = realpath($copyFrom);
$items = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($copyFrom, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($items as $item) {
    $itemPath = $item->getRealPath();
    if ($itemPath === false) continue;
    $relative = ltrim(substr($itemPath, strlen($realSrc)), '/\\');
    $relative = str_replace('\\', '/', $relative);
    if ($relative === '' || $relative === '.agentic_meta.json') continue;

    $target = $destDir . '/' . $relative;
    if ($item->isDir()) {
        if (!is_dir($target)) mkdir($target, 0755, true);
    } else {
        $targetParent = dirname($target);
        if (!is_dir($targetParent)) mkdir($targetParent, 0755, true);
        if (copy($itemPath, $target)) $copied++;
    }
}

if ($copied === 0) {
    echo json_encode(['ok' => false, 'error' => 'No files were deployed (workspace is empty).']);
    exit;
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base_url = $protocol . '://' . $host;

$script_path = $_SERVER['SCRIPT_NAME'] ?? '/api/agentic_deploy.php';
$base_path   = dirname(dirname($script_path)); // strip /api
$base_path   = rtrim($base_path, '/');

$folder_url = $base_url . $base_path . '/deploy/agentic/' . $user_id . '/' . $workspace_id . '/';

// Look for a conventional entry file at the (possibly flattened) doc root.
$has_index = false;
$view_url  = $folder_url;
$root_files = [];
foreach (scandir($destDir) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    if (is_dir($destDir . '/' . $entry)) continue;
    $root_files[] = $entry;
    if (!$has_index && in_array(strtolower($entry), ['index.php', 'index.html', 'index.htm'], true)) {
        $has_index = true;
        $view_url  = $folder_url . rawurlencode($entry);
    }
}
sort($root_files);

echo json_encode([
    'ok'         => true,
    'count'      => $copied,
    'view_url'   => $view_url,
    'folder_url' => $folder_url,
    'has_index'  => $has_index,
    'root_files' => $root_files,
]);
