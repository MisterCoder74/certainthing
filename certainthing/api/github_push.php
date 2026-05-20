<?php
require_once __DIR__ . '/config.php';
check_auth();

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$repo = $data['repo'] ?? ''; // e.g., "username/repo"
$pat = $data['pat'] ?? '';
$files = $data['files'] ?? [];
$message = $data['message'] ?? 'Deploy from CertainThing';

if (empty($repo) || empty($pat) || empty($files)) {
    echo json_encode(['error' => 'Missing required fields (repo, pat, or files)']);
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
    echo json_encode([
        'success' => true, 
        'commit_sha' => $new_commit_sha, 
        'view_url' => "https://github.com/$repo/commit/$new_commit_sha"
    ]);
} else {
    echo json_encode(['error' => 'Failed to update branch reference: ' . ($res['data']['message'] ?? 'Unknown error')]);
}
