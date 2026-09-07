<?php
/**
 * Configuration & Helpers
 */

// Error reporting for development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Session start if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Global settings
define('APP_NAME', 'CertainThing');
define('DATA_DIR', __DIR__ . '/../data');
define('SESSIONS_DIR', DATA_DIR . '/sessions');
// Fase 2 — bounded history: chronological rules/decisions established during a project,
// extracted from model-emitted markers (see chat.php's CT_LEDGER handling), one file per
// user+session, periodically compacted so it stays small enough to inject every turn.
define('LEDGERS_DIR', DATA_DIR . '/ledgers');
define('USERS_FILE', DATA_DIR . '/users.json');
define('PROMPTS_DIR', __DIR__ . '/../prompts');
define('STYLES_DIR', __DIR__ . '/../styles');
define('OPENAI_KEY_FILE', DATA_DIR . '/shared_key.txt');
// Agentic Workspace sandbox root: per-user staged/extracted ZIP contents.
// Never web-accessible directly (see agentic/.htaccess) — reached only through
// api/agentic_workspace_upload.php and api/agentic_serve_test.php, both auth-gated.
define('AGENTIC_DIR', __DIR__ . '/../agentic');
// Phase E lifecycle: a confirmed/extracted workspace with no activity (open, edit,
// vibecode, deploy, push) for this many days is considered abandoned and swept on
// the next workspace-list load. A staged-but-never-confirmed zip upload is swept
// much sooner — it's meant to be a few-seconds detour to "confirm" or "cancel".
define('AGENTIC_WORKSPACE_TTL_DAYS', 14);
define('AGENTIC_STAGING_TTL_HOURS', 24);

/**
 * SSE Helper: Send event to client
 */
function send_event($type, $payload = '') {
    if (!is_array($payload)) {
        $payload = ['text' => (string) $payload];
    }
    $payload['type'] = $type;
    echo "data: " . json_encode($payload) . "\n\n";
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    flush();
}

/**
 * Translate a failed OpenAI call (any phase: chat, vibecode, scope, batch, title)
 * into one plain-language sentence a non-technical user can act on, instead of a
 * raw HTTP code or a generic "network error". Callers pass whichever pieces they
 * have — a curl-level failure (never reached OpenAI) OR an HTTP response from
 * OpenAI/the edge proxy (reached OpenAI or was intercepted before it).
 *
 * @param int    $httpCode  HTTP status from curl_getinfo, 0 if the request never got a response.
 * @param int    $curlErrno curl_errno(), 0 if curl itself succeeded (a response was received).
 * @param string $curlError curl_error(), human text from curl for the errno above.
 * @param string $rawBody   Raw response body, if any was received (may be OpenAI JSON or an
 *                           edge-proxy HTML/plain-text page — both are handled).
 */
function classify_openai_error($httpCode, $curlErrno, $curlError, $rawBody = '') {
    // 1) Never got an HTTP response at all — a curl/transport-level failure.
    if ($curlErrno) {
        $timeoutErrnos = [28]; // CURLE_OPERATION_TIMEDOUT
        $connectErrnos = [6, 7, 35, 51, 60]; // resolve host, connect, SSL, cert, SSL cacert
        if (in_array($curlErrno, $timeoutErrnos, true)) {
            return "The AI didn't respond in time. This can happen with a large or complex request — try again, or split it into smaller pieces.";
        }
        if (in_array($curlErrno, $connectErrnos, true)) {
            return "Couldn't establish a connection to the AI service. This is usually temporary — try again in a moment.";
        }
        return "The connection to the AI service failed (" . ($curlError ?: 'unknown transport error') . "). Try again.";
    }

    // 2) Got an HTTP response — try to read OpenAI's own error payload first, it's the most specific.
    $decoded = null;
    if ($rawBody !== '') {
        $decoded = json_decode($rawBody, true);
    }
    $apiType = is_array($decoded) ? ($decoded['error']['type'] ?? '') : '';
    $apiCode = is_array($decoded) ? ($decoded['error']['code'] ?? '') : '';
    $apiMsg  = is_array($decoded) ? ($decoded['error']['message'] ?? '') : '';

    if ($httpCode === 401 || $apiCode === 'invalid_api_key') {
        return "The AI service rejected the API key. Check that a valid key is set (Setup panel or admin key) and try again.";
    }
    if ($httpCode === 429) {
        if ($apiCode === 'insufficient_quota' || stripos($apiMsg, 'quota') !== false) {
            return "The API key has run out of usage credits. Add credits to that account, or switch to a different key, then try again.";
        }
        return "The AI service is getting too many requests right now (rate limit). Wait a few seconds and try again.";
    }
    if ($httpCode === 400 && ($apiCode === 'context_length_exceeded' || stripos($apiMsg, 'context length') !== false || stripos($apiMsg, 'maximum context') !== false)) {
        return "This request is too large for the model to handle in one go. Try a shorter prompt, or a smaller/split file.";
    }
    if ($httpCode === 400 && $apiMsg !== '') {
        return "The AI service rejected the request: " . $apiMsg;
    }
    if ($httpCode === 403) {
        return "The request was blocked by the AI provider's content policy or access rules.";
    }
    if ($httpCode === 404) {
        return "The selected AI model isn't available right now. Check the model selection and try again.";
    }
    if ($httpCode === 413) {
        return "The request was too large to send. Try a smaller file or a shorter prompt.";
    }
    if ($httpCode === 524 || $httpCode === 504) {
        return "The AI took too long to finish and the connection timed out before an answer came back. This is more likely with large files — try a smaller one, or try again.";
    }
    if ($httpCode === 502 || $httpCode === 503) {
        return "The AI service is temporarily unavailable on its end. Wait a moment and try again.";
    }
    if ($httpCode >= 500) {
        return "The AI service had an internal error (HTTP " . $httpCode . "). Try again in a moment.";
    }
    if ($httpCode >= 400) {
        return $apiMsg !== ''
            ? "The AI service rejected the request: " . $apiMsg
            : "The AI service rejected the request (HTTP " . $httpCode . "). Try again or adjust your request.";
    }

    return "The AI request failed for an unknown reason. Try again.";
}


/**
 * Check if user is authenticated
 */
function check_auth() {
    if (!isset($_SESSION['user_id'])) {
        header('HTTP/1.1 401 Unauthorized');
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

// In config.php, modifica queste funzioni:

function get_user_key_file() {
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) return null;
    return __DIR__ . '/../data/keys/' . $userId . '.key';
}

function get_openai_api_key() {
    $file = get_user_key_file();
    if ($file && file_exists($file)) {
        $key = trim(file_get_contents($file));
        if ($key !== '') {          // ← AGGIUNTO: salta file vuoti (0 byte)
            return $key;
        }
    }
    // 2. Chiave condivisa (admin) su file
    $sharedFile = __DIR__ . '/../data/shared_key.txt';
    if (file_exists($sharedFile)) {
        return trim(file_get_contents($sharedFile));
    }
    // Fallback: variabile d'ambiente globale (admin default)
    return getenv('OPENAI_API_KEY') ?: '';
}

function save_openai_api_key($key) {
    $file = get_user_key_file();
    if (!$file) return false;
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    if ($key === '') {               // ← AGGIUNTO: cancella invece di scrivere vuoto
        return file_exists($file) ? unlink($file) : true;
    }
    return file_put_contents($file, $key) !== false;
}

/**
 * Unified per-user settings (GitHub PAT/repo, model choice, voice language),
 * stored server-side alongside the API key (same directory/pattern), never in localStorage.
 */
function get_user_settings_file() {
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) return null;
    return __DIR__ . '/../data/keys/' . $userId . '.settings.json';
}

function get_default_settings() {
    return [
        'github_pat'     => '',
        'github_repo'    => '',
        'model'          => 'gpt-5-nano',
        'voice_language' => '',
    ];
}

function get_user_settings() {
    $defaults = get_default_settings();
    $file = get_user_settings_file();
    if (!$file || !file_exists($file)) return $defaults;
    $saved = safe_read_json($file);
    return array_merge($defaults, is_array($saved) ? $saved : []);
}

function save_user_settings(array $partial) {
    $file = get_user_settings_file();
    if (!$file) return false;
    $updated = array_merge(get_user_settings(), $partial);
    if ($updated === get_default_settings()) {   // tutto tornato ai default: cancella invece di scrivere vuoto
        return file_exists($file) ? unlink($file) : true;
    }
    return safe_write_json($file, $updated);
}

/**
 * BYOK cost/usage dashboard — per-user record of OpenAI token usage and an
 * estimated USD cost, so a user paying OpenAI directly (their own key) has
 * in-app visibility into what they're spending. Same storage pattern as the
 * audit log: one JSON-Lines file per user under data/keys/, capped and
 * trimmed the same way.
 *
 * Pricing is an ESTIMATE for cost-awareness only (OpenAI pricing can change
 * independently of this app / config) — always the user's own OpenAI
 * dashboard is the source of truth for actual billing.
 */
define('USAGE_LOG_MAX_ENTRIES', 5000);

function openai_pricing_table(): array {
    // USD per 1,000,000 tokens.
    return [
        'gpt-5-nano'   => ['input' => 0.05, 'output' => 0.40],
        'gpt-5.4-nano' => ['input' => 0.20, 'output' => 1.25],
        'gpt-4o-mini'  => ['input' => 0.15, 'output' => 0.60],
    ];
}

function estimate_openai_cost(string $model, int $inputTokens, int $outputTokens): float {
    $table = openai_pricing_table();
    $rates = $table[$model] ?? ['input' => 0.20, 'output' => 0.75]; // generic fallback for unlisted models
    return ($inputTokens / 1000000 * $rates['input']) + ($outputTokens / 1000000 * $rates['output']);
}

function usage_log_file(?string $userId = null): ?string {
    $userId = $userId ?? ($_SESSION['user_id'] ?? null);
    if (!$userId) return null;
    return __DIR__ . '/../data/keys/' . $userId . '.usage.jsonl';
}

/**
 * Append one usage entry for the current session's user.
 * $endpoint  short machine-readable label, e.g. 'chat', 'vibecode', 'vibecode_batch', 'vibecode_scope', 'title'
 * $model     the OpenAI model actually called
 * $usage     the raw 'usage' object from the OpenAI response (prompt_tokens/completion_tokens/total_tokens)
 */
function log_openai_usage(string $endpoint, string $model, array $usage): bool {
    $file = usage_log_file();
    if (!$file) return false;

    $inputTokens  = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
    $outputTokens = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
    $totalTokens  = (int) ($usage['total_tokens'] ?? ($inputTokens + $outputTokens));
    if ($totalTokens <= 0) return false;

    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    // Only 'user' (BYOK) key usage is billed to this user directly; shared/env
    // key usage is billed to the app owner. Logged regardless so the dashboard
    // can be accurate if the user switches key source later, but tagged so the
    // UI can explain which entries are actually theirs to pay for.
    $userKeyFile = get_user_key_file();
    $keySource   = ($userKeyFile && file_exists($userKeyFile) && trim((string) @file_get_contents($userKeyFile)) !== '')
        ? 'user'
        : 'shared_or_env';

    $entry = [
        'ts'             => date('c'),
        'endpoint'       => $endpoint,
        'model'          => $model,
        'input_tokens'   => $inputTokens,
        'output_tokens'  => $outputTokens,
        'total_tokens'   => $totalTokens,
        'estimated_cost' => round(estimate_openai_cost($model, $inputTokens, $outputTokens), 6),
        'key_source'     => $keySource,
    ];

    $line = json_encode($entry, JSON_UNESCAPED_UNICODE);
    if ($line === false) return false;

    $ok = @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    if ($ok === false) return false;

    usage_log_trim_if_needed($file);
    return true;
}

function usage_log_trim_if_needed(string $file): void {
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false || count($lines) <= USAGE_LOG_MAX_ENTRIES) return;
    $trimmed = array_slice($lines, -USAGE_LOG_MAX_ENTRIES);
    @file_put_contents($file, implode("\n", $trimmed) . "\n", LOCK_EX);
}

/**
 * Reads up to $limit most recent usage entries for the given (or current)
 * user, newest first. Malformed lines are skipped rather than aborting.
 */
function usage_log_read(?string $userId = null, int $limit = USAGE_LOG_MAX_ENTRIES): array {
    $file = usage_log_file($userId);
    if (!$file || !file_exists($file)) return [];

    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return [];

    $entries = [];
    foreach (array_reverse($lines) as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded) && isset($decoded['ts'], $decoded['model'])) {
            $entries[] = $decoded;
        }
        if (count($entries) >= $limit) break;
    }
    return $entries;
}

function mask_secret_value($val) {
    $val = trim((string) $val);
    if ($val === '') return '';
    if (strlen($val) <= 8) return str_repeat('*', strlen($val));
    return substr($val, 0, 4) . str_repeat('*', max(strlen($val) - 8, 0)) . substr($val, -4);
}

/**
 * Per-user custom prompt library ("My Prompts"), stored server-side alongside the
 * API key/settings (same directory/pattern). Shape: { "categories": [ { "name":
 * string, "icon": string, "prompts": [ { "title": string, "text": string } ] } ] }.
 * Kept separate from the app-curated prompts_library.json so imports/edits never
 * touch the shared curated content.
 */
function get_user_prompts_file() {
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) return null;
    return __DIR__ . '/../data/keys/' . $userId . '.prompts.json';
}

function get_user_prompts() {
    $file = get_user_prompts_file();
    if (!$file || !file_exists($file)) return ['categories' => []];
    $saved = safe_read_json($file);
    if (!is_array($saved) || !isset($saved['categories']) || !is_array($saved['categories'])) {
        return ['categories' => []];
    }
    return $saved;
}

function save_user_prompts(array $data) {
    $file = get_user_prompts_file();
    if (!$file) return false;
    if (empty($data['categories'])) {
        return file_exists($file) ? unlink($file) : true;
    }
    return safe_write_json($file, $data);
}

/**
 * Validate + normalize a categories array coming from client input (add/import).
 * Drops malformed entries instead of failing the whole request; returns a clean array.
 */
function sanitize_prompt_categories($raw): array {
    if (!is_array($raw)) return [];
    $clean = [];
    foreach ($raw as $cat) {
        if (!is_array($cat)) continue;
        $name = trim((string) ($cat['name'] ?? ''));
        if ($name === '') continue;
        $icon = trim((string) ($cat['icon'] ?? '⭐'));
        $prompts = [];
        foreach ((array) ($cat['prompts'] ?? []) as $p) {
            if (!is_array($p)) continue;
            $title = trim((string) ($p['title'] ?? ''));
            $text  = trim((string) ($p['text'] ?? ''));
            if ($title === '' || $text === '') continue;
            $prompts[] = ['title' => $title, 'text' => $text];
        }
        if (empty($prompts)) continue;
        $clean[] = ['name' => $name, 'icon' => $icon !== '' ? $icon : '⭐', 'prompts' => $prompts];
    }
    return $clean;
}

/**
 * Merge new categories into existing ones: same category name -> prompts merged
 * by title (case-insensitive) — a title already present has its text OVERWRITTEN
 * by the incoming one (so re-importing an edited/re-exported prompt actually
 * updates it instead of silently no-op'ing); a new title is appended. New
 * category name -> appended as-is.
 */
function merge_prompt_categories(array $existing, array $incoming): array {
    foreach ($incoming as $newCat) {
        $found = false;
        foreach ($existing as &$cat) {
            if (mb_strtolower($cat['name']) === mb_strtolower($newCat['name'])) {
                $found = true;
                foreach ($newCat['prompts'] as $p) {
                    $matchIdx = null;
                    foreach ($cat['prompts'] as $idx => $existingP) {
                        if (mb_strtolower($existingP['title']) === mb_strtolower($p['title'])) {
                            $matchIdx = $idx;
                            break;
                        }
                    }
                    if ($matchIdx !== null) {
                        $cat['prompts'][$matchIdx] = $p; // overwrite text on title match
                    } else {
                        $cat['prompts'][] = $p;
                    }
                }
                break;
            }
        }
        unset($cat);
        if (!$found) $existing[] = $newCat;
    }
    return $existing;
}



/**
 * Safe file read with FLOCK
 */
function safe_read_json($file) {
    if (!file_exists($file)) return [];
    $fp = fopen($file, 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $size = filesize($file);
    $content = $size > 0 ? fread($fp, $size) : '[]';
    flock($fp, LOCK_UN);
    fclose($fp);
    return json_decode($content, true) ?: [];
}

/**
 * Safe file write with FLOCK
 */
function safe_write_json($file, $data) {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $fp = fopen($file, 'c');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

/* PRODUCTION */
define('STRIPE_SECRET_KEY',      'sk_live_REDACTED');
define('STRIPE_PUBLISHABLE_KEY', 'pk_live_REDACTED');

/* TEST 
define('STRIPE_SECRET_KEY',      'sk_test_REDACTED');
define('STRIPE_PUBLISHABLE_KEY', 'pk_test_REDACTED');
*/
define('STRIPE_WEBHOOK_SECRET',  'whsec_REDACTED');    // dopo aver creato il webhook
define('STRIPE_PRICE_ID',        'price_1TZEmHAL6ac73fuGXpYPufyR');
// URL base dell'app — usato per i redirect Stripe
define('BASE_URL', 'https://www.vivacitydesign.net/certainThing/v1.2/certainthing');

/**
 * Chiama l'API Stripe via cURL (no dipendenze Composer)
 * @param string $method  GET | POST
 * @param string $endpoint  es. "checkout/sessions", "checkout/sessions/cs_xxx"
 * @param array  $data  parametri POST (ignorati per GET)
 * @return array  risposta decodificata
 */
function stripe_api(string $method, string $endpoint, array $data = []): array {
    $ch = curl_init("https://api.stripe.com/v1/{$endpoint}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
        CURLOPT_HTTPHEADER     => ['Stripe-Version: 2023-10-16'],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST,       true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode((string) $response, true) ?: [];
}
