<?php
/**
 * API: BYOK usage/cost dashboard.
 * Location: ./api/usage_dashboard.php
 *
 * Read-only GET endpoint: aggregates the current user's OpenAI usage log
 * (written by log_openai_usage() in config.php from every OpenAI call site —
 * chat, vibecode single/batch/scope, session title) into totals, a per-model
 * breakdown, and a per-day breakdown for the last 30 days, plus the current
 * key source so the UI can explain whether these numbers are the user's own
 * OpenAI bill (BYOK) or usage currently billed to the app's shared/env key.
 *
 * Never writes anything — logging happens inline at each call site.
 */

require_once __DIR__ . '/config.php';
check_auth();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$entries = usage_log_read(null, USAGE_LOG_MAX_ENTRIES);

// Current key source, same logic used when logging, so the UI can explain
// whether the totals below are money the user themselves owes OpenAI.
$userKeyFile     = get_user_key_file();
$currentSource   = ($userKeyFile && file_exists($userKeyFile) && trim((string) @file_get_contents($userKeyFile)) !== '')
    ? 'user'
    : 'shared_or_env';

$totalInputTokens  = 0;
$totalOutputTokens = 0;
$totalCost         = 0.0;
$byModel           = []; // model => ['input'=>, 'output'=>, 'cost'=>, 'calls'=>]
$byDay             = []; // 'YYYY-MM-DD' => ['tokens'=>, 'cost'=>]
$byokCost          = 0.0; // subset of $totalCost where key_source === 'user'
$byokTokens        = 0;

$cutoff = (new DateTime('-30 days'))->format('c');

foreach ($entries as $e) {
    $inputTokens  = (int) ($e['input_tokens'] ?? 0);
    $outputTokens = (int) ($e['output_tokens'] ?? 0);
    $cost         = (float) ($e['estimated_cost'] ?? 0);
    $model        = (string) ($e['model'] ?? 'unknown');
    $ts           = (string) ($e['ts'] ?? '');
    $keySource    = (string) ($e['key_source'] ?? 'shared_or_env');

    $totalInputTokens  += $inputTokens;
    $totalOutputTokens += $outputTokens;
    $totalCost         += $cost;

    if ($keySource === 'user') {
        $byokCost   += $cost;
        $byokTokens += $inputTokens + $outputTokens;
    }

    if (!isset($byModel[$model])) {
        $byModel[$model] = ['input' => 0, 'output' => 0, 'cost' => 0.0, 'calls' => 0];
    }
    $byModel[$model]['input']  += $inputTokens;
    $byModel[$model]['output'] += $outputTokens;
    $byModel[$model]['cost']   += $cost;
    $byModel[$model]['calls']  += 1;

    if ($ts !== '' && $ts >= $cutoff) {
        $day = substr($ts, 0, 10);
        if (!isset($byDay[$day])) {
            $byDay[$day] = ['tokens' => 0, 'cost' => 0.0];
        }
        $byDay[$day]['tokens'] += ($inputTokens + $outputTokens);
        $byDay[$day]['cost']   += $cost;
    }
}

ksort($byDay);
foreach ($byModel as $m => $v) {
    $byModel[$m]['cost'] = round($v['cost'], 4);
}
foreach ($byDay as $d => $v) {
    $byDay[$d]['cost'] = round($v['cost'], 4);
}

echo json_encode([
    'ok'                  => true,
    'current_key_source'  => $currentSource, // 'user' (BYOK) | 'shared_or_env'
    'total_calls'         => count($entries),
    'total_input_tokens'  => $totalInputTokens,
    'total_output_tokens' => $totalOutputTokens,
    'total_tokens'        => $totalInputTokens + $totalOutputTokens,
    'total_estimated_cost'      => round($totalCost, 4),
    'byok_estimated_cost'       => round($byokCost, 4),
    'byok_tokens'               => $byokTokens,
    'by_model'            => $byModel,
    'by_day'              => $byDay,
    'recent'              => array_slice($entries, 0, 20),
]);
