<?php
/**
 * API: Agentic Workspace — Phase F.2 (multi-file vibecode, per-file call).
 * Location: ./api/agentic_vibecode_batch.php
 *
 * Sandbox-scoped, single-file step of a multi-file AI edit. Given ONE file from the list the
 * user confirmed after reviewing Phase F.1's scope result, calls the model once for that file.
 * The client (agentic_workspace.php) drives the sequential loop across the batch — one HTTP
 * request per file — passing back the running plain-text "batch summary" it accumulated from
 * prior files' responses so later files in the same batch stay consistent with earlier ones
 * (e.g. the CSS class name file 1 introduces is the one file 3's JS toggles).
 *
 * This endpoint used to loop over the whole file list itself inside one request. On shared
 * hosting behind a reverse proxy / CDN, that meant up to 5 sequential OpenAI calls (each up to
 * AGENTIC_VIBECODE_PER_FILE_TIMEOUT seconds) inside a single HTTP response — comfortably past a
 * 100s edge timeout (Cloudflare 524), independent of PHP's own max_execution_time. One file per
 * request keeps every request's worst case close to Phase C's already-live single-file call.
 *
 * Reuses the same complete-file-replacement contract as Phase C's single-file endpoint
 * (prompts/agentic_vibecode_prompt.txt, including its Phase F.2 addendum section) — only the
 * surrounding context is new. This endpoint does NOT write to disk: api/agentic_files.php's
 * existing save_file action stays the only write path (F.4), called by the client once per
 * kept file after the Step 2 review screen.
 *
 * sandbox_isolation_directive: every read here is scoped under
 * AGENTIC_DIR/{user_id}/workspaces/{workspace_id}/ — this endpoint never touches
 * SESSIONS_DIR or any normal chat session data.
 *
 * POST (application/json) {
 *   workspace_id, file, file_number, total_count, files: [path, ...] (full sibling list),
 *   instruction, batch_summary (running summary text from prior files in this batch, '' for file 1)
 * } -> JSON:
 *   { ok:true,  result: { path, ok, exists, new_content?, explanation?, error? }, batch_summary }
 *   { ok:false, error }
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/agentic_helpers.php';
check_auth();

header('Content-Type: application/json; charset=utf-8');

const AGENTIC_VIBECODE_BATCH_FILE_CAP   = 5;
const AGENTIC_VIBECODE_PER_FILE_TIMEOUT = 120; // seconds, matches Phase C's single-file CURLOPT_TIMEOUT

@set_time_limit(AGENTIC_VIBECODE_PER_FILE_TIMEOUT + 30);

$jsonBody = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $jsonBody = json_decode(file_get_contents('php://input'), true) ?? [];
}

$user_id      = $_SESSION['user_id'];
$workspace_id = $jsonBody['workspace_id'] ?? '';
$file         = agentic_sanitize_path((string) ($jsonBody['file'] ?? ''));
$fileNumber   = (int) ($jsonBody['file_number'] ?? 1);
$totalCount   = (int) ($jsonBody['total_count'] ?? 1);
$batchSummary = (string) ($jsonBody['batch_summary'] ?? '');
$instruction  = (string) ($jsonBody['instruction'] ?? '');
$siblingFiles = is_array($jsonBody['files'] ?? null) ? $jsonBody['files'] : [];

if (!preg_match('/^[a-f0-9]{32}$/', $workspace_id)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing workspace id.']);
    exit;
}
if ($file === '') {
    echo json_encode(['ok' => false, 'error' => 'No file to process.']);
    exit;
}
if ($fileNumber < 1 || $fileNumber > AGENTIC_VIBECODE_BATCH_FILE_CAP) {
    echo json_encode(['ok' => false, 'error' => 'File position out of range for the batch cap.']);
    exit;
}

$workspacesRoot = rtrim(AGENTIC_DIR, '/') . '/' . $user_id . '/workspaces';
$baseDir        = $workspacesRoot . '/' . $workspace_id;
if (!is_dir($baseDir) || !agentic_is_inside_base($baseDir, $workspacesRoot)) {
    echo json_encode(['ok' => false, 'error' => 'Workspace not found.']);
    exit;
}
agentic_touch_workspace($baseDir); // activity-tracking only, matches Phase C's touch-on-vibecode behavior

// Current content: empty for a not-yet-existing path (new-file creation is in scope),
// otherwise read from disk with the same guard rails as Phase C's single-file call.
$fullPath       = $baseDir . '/' . $file;
$fileExists     = is_file($fullPath);
$currentContent = '';
if ($fileExists) {
    if (!agentic_is_inside_base($fullPath, $baseDir)) {
        echo json_encode(['ok' => true, 'result' => ['path' => $file, 'ok' => false, 'exists' => true, 'error' => 'Path resolves outside the workspace.'], 'batch_summary' => $batchSummary]);
        exit;
    }
    if (filesize($fullPath) > 2 * 1024 * 1024) {
        echo json_encode(['ok' => true, 'result' => ['path' => $file, 'ok' => false, 'exists' => true, 'error' => 'File too large for vibecode (over 2MB).'], 'batch_summary' => $batchSummary]);
        exit;
    }
    $currentContent = file_get_contents($fullPath);
}

$system_prompt = file_get_contents(PROMPTS_DIR . '/agentic_vibecode_prompt.txt');

// Same key-resolution cascade and paid/BYOK/shared token tiering as Phase C.
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

$_ctMode     = $userMode;
$_ctSettings = get_user_settings();
$model       = ($_ctMode === 'paid') ? $_ctSettings['model'] : 'gpt-5-nano';

$siblingPaths = array_slice(array_values(array_diff($siblingFiles, [$file])), 0, 200);

$userMessage = "This is file {$fileNumber} of {$totalCount} in the current multi-file batch.\n\n"
    . "File: {$file}" . ($fileExists ? '' : ' (does not exist yet — create it)') . "\n\n"
    . "Other files in this workspace (names only, do not output their content):\n"
    . ($siblingPaths ? "- " . implode("\n- ", $siblingPaths) : '(none)')
    . "\n\n--- CURRENT CONTENT OF {$file} ---\n"
    . $currentContent
    . "\n--- END CURRENT CONTENT ---\n\n"
    . "Running summary of what this batch has already changed in earlier files (empty if this is file 1):\n"
    . ($batchSummary !== '' ? $batchSummary : '(none yet — this is the first file)')
    . "\n\nOverall batch instruction: {$instruction}";

$messages = [
    ['role' => 'developer', 'content' => $system_prompt],
    ['role' => 'user', 'content' => $userMessage],
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => AGENTIC_VIBECODE_PER_FILE_TIMEOUT,
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
    echo json_encode(['ok' => true, 'result' => ['path' => $file, 'ok' => false, 'exists' => $fileExists, 'error' => classify_openai_error(0, $curlErrno, $curlError, '')], 'batch_summary' => $batchSummary]);
    exit;
}
if ($httpCode >= 400) {
    echo json_encode(['ok' => true, 'result' => ['path' => $file, 'ok' => false, 'exists' => $fileExists, 'error' => classify_openai_error($httpCode, 0, '', (string) $response)], 'batch_summary' => $batchSummary]);
    exit;
}

$json         = json_decode($response, true);
if (isset($json['usage']) && is_array($json['usage'])) {
    log_openai_usage('vibecode_batch', $model, $json['usage']);
}
$fullResponse = $json['choices'][0]['message']['content'] ?? '';
if (!is_string($fullResponse) || trim($fullResponse) === '') {
    echo json_encode(['ok' => true, 'result' => ['path' => $file, 'ok' => false, 'exists' => $fileExists, 'error' => 'No response received from model'], 'batch_summary' => $batchSummary]);
    exit;
}

if (!preg_match('/```[^\n`]*\n([\s\S]*?)```/', $fullResponse, $m)) {
    echo json_encode([
        'ok'            => true,
        'result'        => ['path' => $file, 'ok' => false, 'exists' => $fileExists, 'error' => 'Model did not return a code block — nothing was changed for this file.'],
        'batch_summary' => $batchSummary,
    ]);
    exit;
}

$newContent  = $m[1];
$explanation = trim(str_replace($m[0], '', $fullResponse));

// Append to the running summary the NEXT file's request will send back. Keep it short — one
// line per file — so the summary doesn't itself balloon the prompt as the batch progresses
// toward the 5-file cap.
$summaryLine     = $explanation !== '' ? $explanation : "Updated {$file}.";
$updatedSummary  = $batchSummary . "- {$file}: " . mb_substr($summaryLine, 0, 300) . "\n";

echo json_encode([
    'ok'            => true,
    'result'        => [
        'path'        => $file,
        'ok'          => true,
        'exists'      => $fileExists,
        'new_content' => $newContent,
        'explanation' => $explanation,
    ],
    'batch_summary' => trim($updatedSummary),
]);
