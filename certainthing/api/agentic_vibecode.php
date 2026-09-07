<?php
/**
 * API: Agentic Workspace — Phase C ("vibecode it").
 * Location: ./api/agentic_vibecode.php
 *
 * Sandbox-scoped, single-file AI edit. Given a workspace-relative file path and a plain
 * instruction, calls the model with that file's current content (+ a name-only sibling
 * file listing for awareness) and returns the model's proposed complete replacement
 * content for review — it does NOT write to disk itself. The existing
 * api/agentic_files.php `save_file` action (already shipped, already tested) is the only
 * write path; the client only persists the result after the user clicks Save in the
 * editor modal. This keeps AI-authored content reviewable before it lands, same as any
 * manually-typed edit, and keeps this endpoint's blast radius to "read + call model" only.
 *
 * sandbox_isolation_directive: every read here is scoped under
 * AGENTIC_DIR/{user_id}/workspaces/{workspace_id}/ — this endpoint never touches
 * SESSIONS_DIR or any normal chat session data.
 *
 * POST (application/json) { workspace_id, file, instruction } -> JSON:
 *   { ok:true,  new_content, explanation, model }
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
$file         = agentic_sanitize_path($jsonBody['file'] ?? '');
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
agentic_touch_workspace($baseDir); // Phase E: vibecode counts as activity

if ($file === '') {
    echo json_encode(['ok' => false, 'error' => 'No file specified.']);
    exit;
}
if ($instruction === '') {
    echo json_encode(['ok' => false, 'error' => 'Tell it what to change or build first.']);
    exit;
}

$fullPath = $baseDir . '/' . $file;
if (!is_file($fullPath) || !agentic_is_inside_base($fullPath, $baseDir)) {
    echo json_encode(['ok' => false, 'error' => 'File not found.']);
    exit;
}
if (filesize($fullPath) > 2 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'error' => 'File too large for vibecode (over 2MB) — edit it directly instead.']);
    exit;
}

$currentContent = file_get_contents($fullPath);

// Name-only sibling listing, for awareness (never content) — capped so a very large
// workspace can't blow up the prompt.
$siblingPaths = [];
$flatten = function (array $nodes) use (&$flatten, &$siblingPaths, $file) {
    foreach ($nodes as $node) {
        if ($node['type'] === 'folder') {
            $flatten($node['children']);
        } elseif ($node['path'] !== $file) {
            $siblingPaths[] = $node['path'];
        }
    }
};
$flatten(agentic_build_tree($baseDir, $baseDir));
$siblingPaths = array_slice($siblingPaths, 0, 200);

$system_prompt = file_get_contents(PROMPTS_DIR . '/agentic_vibecode_prompt.txt');

$userMessage = "File: {$file}\n\n"
    . "Other files in this workspace (names only, do not output their content):\n"
    . ($siblingPaths ? "- " . implode("\n- ", $siblingPaths) : '(none)')
    . "\n\n--- CURRENT CONTENT OF {$file} ---\n"
    . $currentContent
    . "\n--- END CURRENT CONTENT ---\n\n"
    . "Instruction: {$instruction}";

$messages = [
    ['role' => 'developer', 'content' => $system_prompt],
    ['role' => 'user', 'content' => $userMessage],
];

// Same key-resolution cascade and paid/BYOK/shared token tiering as api/chat.php,
// so vibecode behaves identically to normal chat with respect to plan limits.
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
    $max_tokens = 110000;
} elseif ($key_source === 'user') {
    $max_tokens = 64000;
} else {
    $max_tokens = 28000;
}

$_ctMode  = $userMode;
$_ctSettings = get_user_settings();
$model = ($_ctMode === 'paid') ? $_ctSettings['model'] : 'gpt-5-nano';

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_POSTFIELDS     => json_encode([
        'model'                 => $model,
        'messages'              => $messages,
        'max_completion_tokens' => $max_tokens,
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
    log_openai_usage('vibecode', $model, $json['usage']);
}
$fullResponse = $json['choices'][0]['message']['content'] ?? '';
if (!is_string($fullResponse) || trim($fullResponse) === '') {
    echo json_encode(['ok' => false, 'error' => 'No response received from model']);
    exit;
}

// Extract the single fenced code block; everything outside it is the explanation.
if (!preg_match('/```[^\n`]*\n([\s\S]*?)```/', $fullResponse, $m)) {
    echo json_encode([
        'ok'    => false,
        'error' => 'Model did not return a code block — nothing was changed.',
        'raw'   => mb_substr($fullResponse, 0, 4000),
    ]);
    exit;
}

$newContent  = $m[1];
$explanation = trim(str_replace($m[0], '', $fullResponse));

echo json_encode([
    'ok'          => true,
    'new_content' => $newContent,
    'explanation' => $explanation,
    'model'       => $model,
]);
