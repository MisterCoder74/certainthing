<?php
/**
 * Per-user audit log: a durable record of state-changing actions (deploys, file
 * saves, prompt library changes, API key/settings updates, GitHub pushes), so the
 * user can see what changed and when without digging through chat history.
 *
 * Storage: one JSON-Lines file per user, alongside the existing per-user key/
 * settings files (data/keys/<userId>.audit.jsonl) — same directory, same pattern.
 * Newest entries are appended; the file is capped at AUDIT_LOG_MAX_ENTRIES by
 * trimming the oldest entries on write, so it never grows unbounded.
 *
 * This file doubles as:
 *   1. A library — require it and call audit_log_event() from any endpoint that
 *      mutates user state.
 *   2. The read endpoint — GET (no action) or GET ?action=list returns
 *      { entries: [...] } for the current user, newest first.
 *
 * Never logs secret values themselves (API keys, GitHub PATs) — only that a
 * change happened, using the same mask_secret_value() the rest of the app uses.
 */

define('AUDIT_LOG_MAX_ENTRIES', 500);

function audit_log_file(?string $userId = null): ?string {
    $userId = $userId ?? ($_SESSION['user_id'] ?? null);
    if (!$userId) return null;
    return __DIR__ . '/../data/keys/' . $userId . '.audit.jsonl';
}

/**
 * Append one audit entry for the current session's user.
 * $action   short machine-readable label, e.g. 'deploy', 'file_save', 'api_key_updated'
 * $details  small associative array of non-secret context (session id, file name, counts...)
 */
function audit_log_event(string $action, array $details = []): bool {
    $file = audit_log_file();
    if (!$file) return false;

    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    $entry = [
        'ts'      => date('c'),
        'action'  => $action,
        'details' => $details,
    ];

    $line = json_encode($entry, JSON_UNESCAPED_UNICODE);
    if ($line === false) return false;

    $ok = @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    if ($ok === false) return false;

    audit_log_trim_if_needed($file);
    return true;
}

/**
 * Keeps the log file from growing forever: once it holds more than
 * AUDIT_LOG_MAX_ENTRIES lines, rewrite it with only the newest ones. Cheap to
 * skip on the common case (small file) via a fast line count.
 */
function audit_log_trim_if_needed(string $file): void {
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false || count($lines) <= AUDIT_LOG_MAX_ENTRIES) return;
    $trimmed = array_slice($lines, -AUDIT_LOG_MAX_ENTRIES);
    @file_put_contents($file, implode("\n", $trimmed) . "\n", LOCK_EX);
}

/**
 * Reads up to $limit most recent entries for the given (or current) user,
 * newest first. Malformed lines are skipped rather than aborting the read.
 */
function audit_log_read(?string $userId = null, int $limit = 200): array {
    $file = audit_log_file($userId);
    if (!$file || !file_exists($file)) return [];

    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return [];

    $entries = [];
    foreach (array_reverse($lines) as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded) && isset($decoded['ts'], $decoded['action'])) {
            $entries[] = $decoded;
        }
        if (count($entries) >= $limit) break;
    }
    return $entries;
}

// ── Endpoint mode: only when this file is the request entry point ───────────
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    require_once __DIR__ . '/config.php';
    check_auth();

    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed']);
        exit;
    }

    $limit = (int) ($_GET['limit'] ?? 200);
    if ($limit <= 0 || $limit > AUDIT_LOG_MAX_ENTRIES) {
        $limit = 200;
    }

    echo json_encode(['entries' => audit_log_read(null, $limit)]);
    exit;
}
