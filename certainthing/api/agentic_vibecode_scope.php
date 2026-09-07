<?php
/**
 * API: Agentic Workspace — Phase F.1 (multi-file vibecode, scoping call).
 * Location: ./api/agentic_vibecode_scope.php
 *
 * Sandbox-scoped, workspace-level scoping step. Given one instruction (not tied to a
 * single open file) and the full file tree (paths/names only, never content), calls the
 * model to decide which existing files are relevant and which new files (if any) the
 * instruction implies, each with a one-line reason. This call generates NO file content
 * and writes NOTHING to disk — it only decides the blast radius so the client can show it
 * to the user before Phase F.2 (per-file loop) runs against the confirmed list.
 *
 * sandbox_isolation_directive: every read here is scoped under
 * AGENTIC_DIR/{user_id}/workspaces/{workspace_id}/ — this endpoint never touches
 * SESSIONS_DIR or any normal chat session data.
 *
 * POST (application/json) { workspace_id, instruction } -> JSON:
 *   { ok:true,  files: [{ path, exists, reason }], out_of_scope_note, model }
 *   { ok:false, error }
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/agentic_helpers.php';
check_auth();

header('Content-Type: application/json; charset=utf-8');

const AGENTIC_VIBECODE_BATCH_FILE_CAP = 5;

$jsonBody = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $jsonBody = json_decode(file_get_contents('php://input'), true) ?? [];
}

$user_id      = $_SESSION['user_id'];
$workspace_id = $jsonBody['workspace_id'] ?? '';
$instruction  = trim((string) ($jsonBody['instruction'] ?? ''));

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
agentic_touch_workspace($baseDir); // activity-tracking only, matches Phase C's touch-on-vibecode behavior

if ($instruction === '') {
    echo json_encode(['ok' => false, 'error' => 'Tell it what to change or build first.']);
    exit;
}

// Text-like extensions only — mirrors the MIME-lookup helper's known non-binary set.
// Binary/non-text files (images, zips) are never eligible for scope, same exclusion
// Phase C already applies via its 2MB/text assumption.
$textExts = ['html', 'htm', 'css', 'js', 'json', 'php', 'txt', 'md', 'svg', 'xml', 'yml', 'yaml'];

$filePaths = [];
$flatten = function (array $nodes) use (&$flatten, &$filePaths, $textExts) {
    foreach ($nodes as $node) {
        if ($node['type'] === 'folder') {
            $flatten($node['children']);
            continue;
        }
        $ext = strtolower(pathinfo($node['path'], PATHINFO_EXTENSION));
        if (in_array($ext, $textExts, true)) {
            $filePaths[] = $node['path'];
        }
    }
};
$flatten(agentic_build_tree($baseDir, $baseDir));
$filePaths = array_slice($filePaths, 0, 500); // same defensive cap style as Phase C's sibling list

$system_prompt = file_get_contents(PROMPTS_DIR . '/agentic_vibecode_scope_prompt.txt');

$userMessage = "Files currently in this workspace (paths only, no content):\n"
    . ($filePaths ? "- " . implode("\n- ", $filePaths) : '(workspace is empty)')
    . "\n\nHard cap: " . AGENTIC_VIBECODE_BATCH_FILE_CAP . " files total per batch.\n\n"
    . "Instruction: {$instruction}";

$messages = [
    ['role' => 'developer', 'content' => $system_prompt],
    ['role' => 'user', 'content' => $userMessage],
];

// Same key-resolution cascade and paid/BYOK/shared token tiering as Phase C's vibecode
// endpoint, so scoping behaves identically with respect to plan limits.
if (function_exists('get_openai_api_key_with_source')) {
    $keyInfo    = get_openai_api_key_with_source();
    $openai_key = $keyInfo['key'];
    $key_source = $keyInfo['source'];
} else {
    $openai_key = get_openai_api_key();
    $key_source = $openai_key !== '' ? 'user' : 'none';
}

if ($openai_key === '') {
    $userMode = $_SESSION['user_mode'] ?? 'trial';
    echo json_encode(['ok' => false, 'error' => $userMode === 'trial'
        ? 'No shared API key configured. Contact the admin.'
        : 'OpenAI API key not configured. Add your key via the 🔑 button.']);
    exit;
}

$userMode = $_SESSION['user_mode'] ?? 'trial';
if ($userMode === 'paid') {
    $max_tokens = 8000;
} elseif ($key_source === 'user') {
    $max_tokens = 6000;
} else {
    $max_tokens = 4000;
}
// Scoping output is small structured JSON, not file content — a modest cap keeps this
// call cheap regardless of tier, unlike the large per-file caps Phase C/F.2 need.

$_ctMode  = $userMode;
$_ctSettings = get_user_settings();
$model = ($_ctMode === 'paid') ? $_ctSettings['model'] : 'gpt-5-nano';

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_POSTFIELDS     => json_encode([
        'model'                 => $model,
        'messages'              => $messages,
        'max_completion_tokens' => $max_tokens,
        'response_format'       => ['type' => 'json_object'],
    ]),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $openai_key,
    ],
]);

$response  = curl_exec($ch);
$curlErrno = curl_errno($ch);
$curlError = curl_error($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false && $curlErrno !== CURLE_OK) {
    echo json_encode(['ok' => false, 'error' => classify_openai_error(0, $curlErrno, $curlError, '')]);
    exit;
}
if ($httpCode >= 400) {
    echo json_encode(['ok' => false, 'error' => classify_openai_error($httpCode, 0, '', (string) $response)]);
    exit;
}

$json = json_decode($response, true);
if (isset($json['usage']) && is_array($json['usage'])) {
    log_openai_usage('vibecode_scope', $model, $json['usage']);
}
$rawContent = $json['choices'][0]['message']['content'] ?? '';
if (!is_string($rawContent) || trim($rawContent) === '') {
    echo json_encode(['ok' => false, 'error' => 'No response received from model']);
    exit;
}

$scope = json_decode(trim($rawContent), true);
if (!is_array($scope) || !isset($scope['files']) || !is_array($scope['files'])) {
    echo json_encode([
        'ok'    => false,
        'error' => 'Model did not return a valid scope — nothing was decided.',
        'raw'   => mb_substr($rawContent, 0, 4000),
    ]);
    exit;
}

// Defensive re-validation in PHP — never trust the model's own cap compliance or path
// sanitation, same posture as every other agentic_* endpoint that touches user paths.
$existingSet = array_flip($filePaths);
$files = [];
foreach ($scope['files'] as $entry) {
    if (count($files) >= AGENTIC_VIBECODE_BATCH_FILE_CAP) break;
    if (!is_array($entry)) continue;
    $path = agentic_sanitize_path((string) ($entry['path'] ?? ''));
    if ($path === '') continue;
    $exists = isset($existingSet[$path]);
    $files[] = [
        'path'   => $path,
        'exists' => $exists,
        'reason' => trim((string) ($entry['reason'] ?? '')),
    ];
}

$outOfScopeNote = trim((string) ($scope['out_of_scope_note'] ?? ''));
if (is_array($scope['files']) && count($scope['files']) > AGENTIC_VIBECODE_BATCH_FILE_CAP && $outOfScopeNote === '') {
    $outOfScopeNote = 'The model proposed more than ' . AGENTIC_VIBECODE_BATCH_FILE_CAP
        . ' files; showing the first ' . AGENTIC_VIBECODE_BATCH_FILE_CAP . ' — consider splitting this into more than one batch.';
}

echo json_encode([
    'ok'                => true,
    'files'             => $files,
    'out_of_scope_note' => $outOfScopeNote,
    'model'             => $model,
]);
