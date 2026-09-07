<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/audit_log.php';
check_auth();

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$settings = get_user_settings();
// Il PAT vive nel pannello Setup (server-side), unico per l'utente qualunque sia il repo.
// Il repo è per-push: arriva dal payload (l'utente lo digita nel modal di push), con
// fallback all'ultimo repo usato se il client non lo manda per qualche motivo.
$repo = trim((string) ($data['repo'] ?? ''));
if ($repo === '') $repo = $settings['github_repo']; // e.g., "username/repo"
$pat = $settings['github_pat'];
$files = $data['files'] ?? [];
$message = $data['message'] ?? 'Deploy from CertainThing';

if (empty($pat)) {
    echo json_encode(['error' => 'GitHub token not configured — set it in Setup → GitHub first.']);
    exit;
}
if (empty($repo) || empty($files)) {
    echo json_encode(['error' => 'Missing required fields (repo or files)']);
    exit;
}

function github_api($url, $pat, $method = 'GET', $post_data = null) {
    $ch = curl_init("https://api.github.com/$url");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: CertainThing-App',
        "Authorization: token $pat",
        'Accept: application/vnd.github.v3+json',
        'Content-Type: application/json'
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
    }
    
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ['status' => $status, 'data' => json_decode($response, true)];
}

// 1. Get the default branch of the repository
$res = github_api("repos/$repo", $pat);
if ($res['status'] !== 200) {
    echo json_encode(['error' => 'Failed to get repository info: ' . ($res['data']['message'] ?? 'Unknown error')]);
    exit;
}
$default_branch = $res['data']['default_branch'] ?? 'main';

// 2. Get the latest commit SHA of the default branch
$res = github_api("repos/$repo/git/refs/heads/$default_branch", $pat);
if ($res['status'] !== 200) {
    echo json_encode(['error' => 'Failed to get branch reference: ' . ($res['data']['message'] ?? 'Unknown error')]);
    exit;
}
$base_commit_sha = $res['data']['object']['sha'];

// 3. Get the tree SHA of the latest commit
$res = github_api("repos/$repo/git/commits/$base_commit_sha", $pat);
if ($res['status'] !== 200) {
    echo json_encode(['error' => 'Failed to get commit info: ' . ($res['data']['message'] ?? 'Unknown error')]);
    exit;
}
$base_tree_sha = $res['data']['tree']['sha'];

// 4. Create a new Tree
$tree_data = [];
foreach ($files as $file) {
    $tree_data[] = [
        'path' => $file['name'],
        'mode' => '100644',
        'type' => 'blob',
        'content' => $file['content']
    ];
}

$res = github_api("repos/$repo/git/trees", $pat, 'POST', [
    'base_tree' => $base_tree_sha,
    'tree' => $tree_data
]);
if ($res['status'] !== 201) {
    echo json_encode(['error' => 'Failed to create tree: ' . ($res['data']['message'] ?? 'Unknown error')]);
    exit;
}
$new_tree_sha = $res['data']['sha'];

// 5. Create a new Commit
$res = github_api("repos/$repo/git/commits", $pat, 'POST', [
    'message' => $message,
    'tree' => $new_tree_sha,
    'parents' => [$base_commit_sha]
]);
if ($res['status'] !== 201) {
    echo json_encode(['error' => 'Failed to create commit: ' . ($res['data']['message'] ?? 'Unknown error')]);
    exit;
}
$new_commit_sha = $res['data']['sha'];

// 6. Update the default branch reference
$res = github_api("repos/$repo/git/refs/heads/$default_branch", $pat, 'PATCH', [
    'sha' => $new_commit_sha,
    'force' => false
]);

if ($res['status'] === 200) {
    save_user_settings(['github_repo' => $repo]); // remember as default pre-fill for next push
    audit_log_event('github_push', ['repo' => $repo, 'commit_sha' => $new_commit_sha, 'file_count' => count($files)]);
    echo json_encode([
        'success' => true, 
        'commit_sha' => $new_commit_sha, 
        'view_url' => "https://github.com/$repo/commit/$new_commit_sha"
    ]);
} else {
    echo json_encode(['error' => 'Failed to update branch reference: ' . ($res['data']['message'] ?? 'Unknown error')]);
}