<?php
/**
 * API: Agentic Workspace — Phase D, part 2 ("Push to GitHub").
 * Location: ./api/agentic_github_push.php
 *
 * Pushes the CURRENT full state of a sandbox workspace to the user's configured
 * GitHub repo (same Setup-panel repo/PAT as api/github_push.php), preserving the
 * real folder structure — paths are already workspace-relative with slashes, and
 * the GitHub trees API accepts that directly as `path`.
 *
 * Difference from api/github_push.php (which only ever sent chat-generated TEXT
 * files): a ZIP-uploaded workspace can legitimately contain binary assets (images,
 * fonts, ...). Sending raw bytes as a JSON string `content` field corrupts them, so
 * any file that doesn't decode as valid UTF-8 text is uploaded as its own blob first
 * (POST git/blobs, base64-encoded) and referenced by `sha` in the tree entry instead
 * of inline `content` — the git-trees API supports both forms per entry.
 *
 * sandbox_isolation_directive: reads are scoped under
 * AGENTIC_DIR/{user_id}/workspaces/{workspace_id}/ only.
 *
 * POST (application/json) { workspace_id, message? } -> JSON:
 *   { ok:true,  commit_sha, view_url, count }
 *   { ok:false, error }
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/agentic_helpers.php';
require_once __DIR__ . '/audit_log.php';
check_auth();

header('Content-Type: application/json; charset=utf-8');

$jsonBody = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $jsonBody = json_decode(file_get_contents('php://input'), true) ?? [];
}

$user_id      = $_SESSION['user_id'];
$workspace_id = $jsonBody['workspace_id'] ?? '';
$message      = trim((string) ($jsonBody['message'] ?? '')) ?: 'Deploy from CertainThing Agentic Workspace';
$repoInput    = trim((string) ($jsonBody['repo'] ?? ''));

if (!preg_match('/^[a-f0-9]{32}$/', $workspace_id)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing workspace id.']);
    exit;
}

$workspacesRoot = rtrim(AGENTIC_DIR, '/') . '/' . $user_id . '/workspaces';
$baseDir        = $workspacesRoot . '/' . $workspace_id;
if (!is_dir($baseDir) || !agentic_is_inside_base($baseDir, $workspacesRoot)) {
    echo json_encode(['ok' => false, 'error' => 'Workspace not found.']);
    exit;
}
agentic_touch_workspace($baseDir); // Phase E: pushing counts as activity

$settings = get_user_settings();
// Il PAT vive nel pannello Setup (server-side), unico per l'utente qualunque sia il repo.
// Il repo è per-push: arriva dal payload (il modal di push lo chiede ogni volta), con
// fallback all'ultimo repo usato se il client non lo manda per qualche motivo.
$repo = $repoInput !== '' ? $repoInput : ($settings['github_repo'] ?? '');
$pat  = $settings['github_pat'] ?? '';
if (empty($pat)) {
    echo json_encode(['ok' => false, 'error' => 'GitHub token not configured — set it in Setup → GitHub first.']);
    exit;
}
if (empty($repo)) {
    echo json_encode(['ok' => false, 'error' => 'Missing repository — enter it in the push dialog.']);
    exit;
}

// ── Collect every workspace file (workspace-relative path, with slashes) ───────────
$files = [];
$realBase = realpath($baseDir);
$items = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($items as $item) {
    if ($item->isDir()) continue;
    $itemPath = $item->getRealPath();
    if ($itemPath === false) continue;
    $relative = ltrim(substr($itemPath, strlen($realBase)), '/\\');
    $relative = str_replace('\\', '/', $relative);
    if ($relative === '' || $relative === '.agentic_meta.json') continue;
    $files[] = ['path' => $relative, 'full' => $itemPath];
}

if (empty($files)) {
    echo json_encode(['ok' => false, 'error' => 'Workspace is empty — nothing to push.']);
    exit;
}

function agentic_github_api($url, $pat, $method = 'GET', $post_data = null) {
    $ch = curl_init('https://api.github.com/' . $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: CertainThing-App',
        "Authorization: token $pat",
        'Accept: application/vnd.github.v3+json',
        'Content-Type: application/json',
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
    }
    $response = curl_exec($ch);
    if ($response === false) {
        return ['status' => 0, 'data' => ['message' => curl_error($ch)]];
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'data' => json_decode($response, true)];
}

// 1. Default branch
$res = agentic_github_api("repos/$repo", $pat);
if ($res['status'] !== 200) {
    echo json_encode(['ok' => false, 'error' => 'Failed to get repository info: ' . ($res['data']['message'] ?? 'Unknown error')]);
    exit;
}
$default_branch = $res['data']['default_branch'] ?? 'main';

// 2. Latest commit SHA of that branch
$res = agentic_github_api("repos/$repo/git/refs/heads/$default_branch", $pat);
if ($res['status'] !== 200) {
    echo json_encode(['ok' => false, 'error' => 'Failed to get branch reference: ' . ($res['data']['message'] ?? 'Unknown error')]);
    exit;
}
$base_commit_sha = $res['data']['object']['sha'];

// 3. Tree SHA of that commit
$res = agentic_github_api("repos/$repo/git/commits/$base_commit_sha", $pat);
if ($res['status'] !== 200) {
    echo json_encode(['ok' => false, 'error' => 'Failed to get commit info: ' . ($res['data']['message'] ?? 'Unknown error')]);
    exit;
}
$base_tree_sha = $res['data']['tree']['sha'];

// 4. Build tree entries — text files inline, binary files as pre-uploaded blobs.
$tree_data = [];
foreach ($files as $f) {
    $bytes   = file_get_contents($f['full']);
    $isText  = $bytes === '' || mb_check_encoding($bytes, 'UTF-8');

    if ($isText) {
        $tree_data[] = [
            'path'    => $f['path'],
            'mode'    => '100644',
            'type'    => 'blob',
            'content' => $bytes,
        ];
    } else {
        $blobRes = agentic_github_api("repos/$repo/git/blobs", $pat, 'POST', [
            'content'  => base64_encode($bytes),
            'encoding' => 'base64',
        ]);
        if ($blobRes['status'] !== 201) {
            echo json_encode(['ok' => false, 'error' => 'Failed to upload binary file "' . $f['path'] . '": ' . ($blobRes['data']['message'] ?? 'Unknown error')]);
            exit;
        }
        $tree_data[] = [
            'path' => $f['path'],
            'mode' => '100644',
            'type' => 'blob',
            'sha'  => $blobRes['data']['sha'],
        ];
    }
}

$res = agentic_github_api("repos/$repo/git/trees", $pat, 'POST', [
    'base_tree' => $base_tree_sha,
    'tree'      => $tree_data,
]);
if ($res['status'] !== 201) {
    echo json_encode(['ok' => false, 'error' => 'Failed to create tree: ' . ($res['data']['message'] ?? 'Unknown error')]);
    exit;
}
$new_tree_sha = $res['data']['sha'];

// 5. Commit
$res = agentic_github_api("repos/$repo/git/commits", $pat, 'POST', [
    'message' => $message,
    'tree'    => $new_tree_sha,
    'parents' => [$base_commit_sha],
]);
if ($res['status'] !== 201) {
    echo json_encode(['ok' => false, 'error' => 'Failed to create commit: ' . ($res['data']['message'] ?? 'Unknown error')]);
    exit;
}
$new_commit_sha = $res['data']['sha'];

// 6. Fast-forward the branch ref
$res = agentic_github_api("repos/$repo/git/refs/heads/$default_branch", $pat, 'PATCH', [
    'sha'   => $new_commit_sha,
    'force' => false,
]);

if ($res['status'] === 200) {
    save_user_settings(['github_repo' => $repo]); // remember as default pre-fill for next push
    audit_log_event('github_push', ['repo' => $repo, 'commit_sha' => $new_commit_sha, 'file_count' => count($files), 'source' => 'agentic_workspace']);
    echo json_encode([
        'ok'         => true,
        'count'      => count($files),
        'commit_sha' => $new_commit_sha,
        'view_url'   => "https://github.com/$repo/commit/$new_commit_sha",
    ]);
} else {
    echo json_encode(['ok' => false, 'error' => 'Failed to update branch reference: ' . ($res['data']['message'] ?? 'Unknown error')]);
}
