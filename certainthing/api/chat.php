<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/scrape.php';

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}
echo str_repeat(' ', 2048) . "\n\n";
if (ob_get_level() > 0) {
    ob_flush();
}
flush();

check_auth();

function reasoning_step($text, $action = 'system', $resource = '', $model = '', $streaming = false) {
    send_event('reasoning', [
        'text' => $text,
        'action' => $action,
        'resource' => $resource,
        'model' => $model,
        'timestamp' => date('c'),
        'streaming' => $streaming
    ]);
}

function summarize_prompt($prompt, $maxLen = 80) {
    $prompt = trim((string) $prompt);
    if ($prompt === '') {
        return '';
    }

    $clean = preg_replace('/\s+/', ' ', $prompt);
    if (mb_strlen($clean) > $maxLen) {
        return mb_substr($clean, 0, $maxLen) . '&hellip;';
    }

    return $clean;
}

function extract_generated_filenames($text) {
    $files = [];
    if (!is_string($text) || $text === '') {
        return $files;
    }

    if (!preg_match_all('/```([^\n`]*)\n([\s\S]*?)```/m', $text, $matches, PREG_SET_ORDER)) {
        return $files;
    }

    foreach ($matches as $match) {
        $info = trim($match[1] ?? '');
        if ($info === '') {
            continue;
        }

        $language = 'text';
        $filename = '';

        if (preg_match('/^([^\s]+)\s+\[([^\]]+)\]$/', $info, $parts)) {
            $language = trim($parts[1]);
            $filename = trim($parts[2]);
        } elseif (preg_match('/^([^\s]+)\s+(.+)$/', $info, $parts)) {
            $language = trim($parts[1]);
            $candidate = trim($parts[2]);
            if (preg_match('/[\\\/]|\.[A-Za-z0-9]+$/', $candidate)) {
                $filename = $candidate;
            }
        } else {
            $language = $info;
        }

        if ($filename === '' && preg_match('/\[([^\]]+)\]/', $info, $named)) {
            $filename = trim($named[1]);
        }

        if ($filename !== '' && !in_array($filename, $files, true)) {
            $files[] = $filename;
        } elseif ($filename === '' && $language !== '' && !in_array($language, $files, true)) {
            $files[] = 'file.' . preg_replace('/[^a-z0-9]+/i', '', strtolower($language));
        }
    }

    return $files;
}

// ─── Fase 2: extract filename => full content for every fenced code block,
// in document order (later blocks for the same filename overwrite earlier ones
// within a single response, matching "full file replacement" semantics). ───
function extract_generated_files_map($text) {
    $map = [];
    if (!is_string($text) || $text === '') {
        return $map;
    }

    if (!preg_match_all('/```([^\n`]*)\n([\s\S]*?)```/m', $text, $matches, PREG_SET_ORDER)) {
        return $map;
    }

    foreach ($matches as $match) {
        $info = trim($match[1] ?? '');
        $content = $match[2] ?? '';
        if ($info === '') {
            continue;
        }

        $language = 'text';
        $filename = '';

        if (preg_match('/^([^\s]+)\s+\[([^\]]+)\]$/', $info, $parts)) {
            $language = trim($parts[1]);
            $filename = trim($parts[2]);
        } elseif (preg_match('/^([^\s]+)\s+(.+)$/', $info, $parts)) {
            $language = trim($parts[1]);
            $candidate = trim($parts[2]);
            if (preg_match('/[\\\/]|\.[A-Za-z0-9]+$/', $candidate)) {
                $filename = $candidate;
            }
        } else {
            $language = $info;
        }

        if ($filename === '' && preg_match('/\[([^\]]+)\]/', $info, $named)) {
            $filename = trim($named[1]);
        }

        if ($filename === '') {
            $filename = 'file.' . preg_replace('/[^a-z0-9]+/i', '', strtolower($language));
        }

        $map[$filename] = $content;
    }

    return $map;
}

// ─── Fase 2: replace fenced code blocks in a HISTORICAL message with a short
// reference note. Current file content already lives in the "current project
// file state" context block, so replaying it again inside the bounded
// interaction window would duplicate tokens for no benefit. ───
function strip_code_for_history($text) {
    if (!is_string($text) || $text === '') {
        return $text;
    }

    return preg_replace_callback('/```([^\n`]*)\n([\s\S]*?)```/m', function ($m) {
        $info = trim($m[1] ?? '');
        $filename = '';
        if (preg_match('/\[([^\]]+)\]/', $info, $named)) {
            $filename = trim($named[1]);
        }
        return $filename !== ''
            ? "[code for {$filename} generated here — see current project file state]"
            : '[code block generated here — see current project file state]';
    }, $text);
}

// ─── Fase 2: build filename => latest content by replaying every assistant
// message chronologically; later versions overwrite earlier ones, so the
// result is always the current state of every file the model has produced. ───
function build_current_file_state($messages) {
    $files = [];
    foreach ($messages as $msg) {
        if (($msg['role'] ?? '') !== 'assistant') {
            continue;
        }
        foreach (extract_generated_files_map($msg['content'] ?? '') as $filename => $content) {
            $files[$filename] = $content;
        }
    }
    return $files;
}

// ─── Fase 2: rules/decisions ledger storage ───────────────────────────
const CT_LEDGER_MARKER = '<<<CT_LEDGER>>>';
const CT_LEDGER_COMPACT_THRESHOLD = 20;
const CT_HISTORY_WINDOW_INTERACTIONS = 5;

function ledger_file_path($user_id, $session_id) {
    return LEDGERS_DIR . '/' . $user_id . '_' . $session_id . '.json';
}

function load_ledger($user_id, $session_id) {
    $data = safe_read_json(ledger_file_path($user_id, $session_id));
    return (is_array($data) && isset($data['entries']) && is_array($data['entries'])) ? $data['entries'] : [];
}

function save_ledger($user_id, $session_id, array $entries) {
    safe_write_json(ledger_file_path($user_id, $session_id), [
        'session_id' => $session_id,
        'user_id' => $user_id,
        'updated_at' => date('c'),
        'entries' => array_values($entries)
    ]);
}

// Splits the raw model output into [display_text, ledger_entries_or_null].
// The marker and everything after it are never shown to the user or saved
// into the visible session history — only parsed into the ledger.
function extract_ledger_marker($full_response) {
    $pos = strpos($full_response, CT_LEDGER_MARKER);
    if ($pos === false) {
        return [$full_response, null];
    }

    $display = rtrim(substr($full_response, 0, $pos));
    $raw = trim(substr($full_response, $pos + strlen(CT_LEDGER_MARKER)));
    // Model may still wrap the JSON in a stray code fence despite instructions not to.
    $raw = preg_replace('/^```[a-z]*\n?|```$/i', '', trim($raw));

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [$display, null];
    }

    $entries = [];
    foreach ($decoded as $item) {
        $type = strtolower(trim((string) ($item['type'] ?? '')));
        $text = trim((string) ($item['text'] ?? ''));
        if ($text === '' || !in_array($type, ['rule', 'decision'], true)) {
            continue;
        }
        $entries[] = ['type' => $type, 'text' => $text, 'timestamp' => date('c')];
    }

    return [$display, $entries];
}

// Cheap heuristic gate: only worth a small-model fallback call when the reply
// itself reads like it settled something (keeps this from firing every turn).
function ledger_fallback_heuristic($user_message, $assistant_text) {
    $probe = mb_strtolower($user_message . ' ' . $assistant_text);
    $signals = [
        'd\'ora in poi', 'da ora in poi', 'sempre', 'mai più', 'regola', 'confermato',
        'confermiamo', 'stabilito', 'importante:', 'ricorda che', 'preferisco che',
        'from now on', 'always', 'never again', 'rule:', 'established', 'confirmed',
        'decided', 'let\'s go with', 'final decision'
    ];
    foreach ($signals as $kw) {
        if (mb_strpos($probe, $kw) !== false) {
            return true;
        }
    }
    return false;
}

// Best-effort extraction via a cheap non-streaming call, used only when the
// model didn't emit a CT_LEDGER marker but the exchange looks decision-like.
function call_small_model_ledger_extract($openai_key, $user_message, $assistant_text) {
    $prompt = "Read this exchange from a coding project. If it establishes a lasting rule or a firm decision "
        . "for the project (not routine code output), reply with a compact JSON array like "
        . "[{\"type\":\"rule\",\"text\":\"...\"}]. If nothing lasting was established, reply with exactly: []\n\n"
        . "USER: " . mb_substr($user_message, 0, 800) . "\n\nASSISTANT: " . mb_substr($assistant_text, 0, 1200);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => 'gpt-5-nano',
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'max_completion_tokens' => 300
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $openai_key
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $code >= 400) {
        return [];
    }

    $json = json_decode($resp, true);
    $text = trim((string) ($json['choices'][0]['message']['content'] ?? ''));
    $text = preg_replace('/^```[a-z]*\n?|```$/i', '', $text);
    $decoded = json_decode($text, true);
    if (!is_array($decoded)) {
        return [];
    }

    $entries = [];
    foreach ($decoded as $item) {
        $type = strtolower(trim((string) ($item['type'] ?? '')));
        $itemText = trim((string) ($item['text'] ?? ''));
        if ($itemText === '' || !in_array($type, ['rule', 'decision'], true)) {
            continue;
        }
        $entries[] = ['type' => $type, 'text' => $itemText, 'timestamp' => date('c')];
    }
    return $entries;
}

// When the ledger grows past the threshold, ask a cheap model to merge/dedupe
// it into a shorter equivalent list instead of letting it grow unbounded.
function compact_ledger_if_needed($openai_key, array $entries) {
    if (count($entries) <= CT_LEDGER_COMPACT_THRESHOLD) {
        return [$entries, false];
    }

    $numbered = [];
    foreach ($entries as $i => $e) {
        $numbered[] = ($i + 1) . '. [' . $e['type'] . '] ' . $e['text'];
    }
    $prompt = "Merge and deduplicate this list of project rules/decisions into the shortest equivalent list "
        . "that loses no distinct rule or decision. Keep chronological order where it matters. Reply with ONLY "
        . "a compact JSON array like [{\"type\":\"rule\",\"text\":\"...\"}], no commentary.\n\n" . implode("\n", $numbered);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => 'gpt-5-nano',
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'max_completion_tokens' => 1200
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $openai_key
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $code >= 400) {
        return [$entries, false];
    }

    $json = json_decode($resp, true);
    $text = trim((string) ($json['choices'][0]['message']['content'] ?? ''));
    $text = preg_replace('/^```[a-z]*\n?|```$/i', '', $text);
    $decoded = json_decode($text, true);
    if (!is_array($decoded) || empty($decoded)) {
        return [$entries, false];
    }

    $compacted = [];
    foreach ($decoded as $item) {
        $type = strtolower(trim((string) ($item['type'] ?? '')));
        $itemText = trim((string) ($item['text'] ?? ''));
        if ($itemText === '' || !in_array($type, ['rule', 'decision'], true)) {
            continue;
        }
        $compacted[] = ['type' => $type, 'text' => $itemText, 'timestamp' => date('c')];
    }

    return [!empty($compacted) ? $compacted : $entries, !empty($compacted)];
}

// ─── Fase 2: bounded context — original prompt + current file state + ledger,
// appended to the system prompt; plus the last N interactions (code stripped)
// used as the conversational window instead of the full unbounded history. ───
function build_bounded_context($session_data) {
    $messages = $session_data['messages'] ?? [];

    $original_prompt = '';
    foreach ($messages as $msg) {
        if (($msg['role'] ?? '') === 'user') {
            $original_prompt = trim((string) ($msg['content'] ?? ''));
            break;
        }
    }

    $current_files = build_current_file_state($messages);

    $context = '';
    if ($original_prompt !== '') {
        $context .= "=== ORIGINAL PROJECT PROMPT ===\n" . $original_prompt . "\n";
    }

    if (!empty($current_files)) {
        $context .= "\n=== CURRENT PROJECT FILE STATE (latest version of every generated file) ===\n";
        foreach ($current_files as $filename => $content) {
            $context .= "--- {$filename} ---\n" . $content . "\n";
        }
    }

    // Bounded window: last N user/assistant pairs, with historical code stripped
    // (the block above already carries the latest version of every file).
    $pairCount = 0;
    $windowed = [];
    for ($i = count($messages) - 1; $i >= 0 && $pairCount < CT_HISTORY_WINDOW_INTERACTIONS; $i--) {
        $msg = $messages[$i];
        $role = $msg['role'] ?? '';
        $content = $msg['content'] ?? '';
        if ($role === 'assistant') {
            $content = strip_code_for_history($content);
        }
        array_unshift($windowed, ['role' => $role, 'content' => $content, 'attachments' => $msg['attachments'] ?? null]);
        if ($role === 'user') {
            $pairCount++;
        }
    }

    // Never window out the very first user message twice — if it's already
    // inside the windowed slice, drop the duplicate original-prompt block link
    // is fine since the file-state block already summarizes generated code.
    return [$context, $windowed];
}

// ─── Format a byte count into human-readable size ───
function format_bytes($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 2) . ' MB';
}

// ─── Progressive disclosure: 2-level design style retrieval ───────────
// Level 1: index.json (lightweight router) is ALWAYS injected.
// Level 2: the 1–2 style detail files whose keywords match the user
//          message are loaded on demand. Fixed cost = index; variable
//          cost = only the styles relevant to this specific request.
function build_style_context($message) {
    $indexFile = STYLES_DIR . '/index.json';
    if (!is_file($indexFile)) {
        return '';
    }

    $indexRaw = file_get_contents($indexFile);
    $index = json_decode($indexRaw, true);
    if (!is_array($index) || empty($index['styles']) || !is_array($index['styles'])) {
        return '';
    }

    // Level 1 — index always loaded
    $context = "=== DESIGN STYLE INDEX (Level 1 — always loaded) ===\n" . trim($indexRaw);

    $styleCount = count($index['styles']);
    reasoning_step(
        '&#x1F4D6; Reading style index &middot; ' . $styleCount . ' style(s) available: ' . implode(', ', array_keys($index['styles'])),
        'style_index_read', 'styles/index.json'
    );

    // Keyword matching: score each style by how many of its keywords
    // appear as substrings in the (lowercased) user message.
    $msg = mb_strtolower((string) $message);
    $scores = [];
    $matchedKeywords = [];
    foreach ($index['styles'] as $styleKey => $style) {
        $score = 0;
        $hits = [];
        foreach (($style['keywords'] ?? []) as $kw) {
            $kw = mb_strtolower(trim((string) $kw));
            if ($kw !== '' && mb_strpos($msg, $kw) !== false) {
                $score++;
                $hits[] = $kw;
            }
        }
        if ($score > 0) {
            $scores[$styleKey] = $score;
            $matchedKeywords[$styleKey] = $hits;
        }
    }

    if (empty($scores)) {
        reasoning_step(
            '&#x1F3A8; No style keywords matched this prompt &mdash; proceeding with index only (no detail file loaded)',
            'style_choice', 'progressive_disclosure'
        );
        return $context;
    }

    // Rank by score desc, cap at 2 (rule: never load more than 2 files)
    arsort($scores);
    $selected = array_slice(array_keys($scores), 0, 2);

    reasoning_step(
        '&#x1F3AF; Style choice &middot; prompt matched: ' . implode(', ', array_map(
            function ($k) use ($matchedKeywords) {
                return $k . ' (' . implode('/', $matchedKeywords[$k]) . ')';
            },
            $selected
        )),
        'style_choice', 'progressive_disclosure'
    );

    $loaded = [];
    $missing = [];
    foreach ($selected as $styleKey) {
        $file = $index['styles'][$styleKey]['file'] ?? '';
        // basename() hardens against path traversal in the index file
        $safeFile = basename((string) $file);
        $detailFile = STYLES_DIR . '/' . $safeFile;
        if ($file !== '' && is_file($detailFile)) {
            $detailRaw = file_get_contents($detailFile);
            $context .= "\n\n=== DESIGN STYLE DETAIL: {$styleKey} (Level 2 — loaded on demand) ===\n" . trim($detailRaw);
            $loaded[] = $styleKey . ' (' . $safeFile . ')';
        } else {
            $missing[] = $styleKey . ' (' . $safeFile . ')';
        }
    }

    if (!empty($loaded)) {
        reasoning_step(
            '&#x1F4C4; Definition file(s) loaded &middot; ' . implode(', ', $loaded),
            'style_detail_load', 'progressive_disclosure'
        );
    }
    if (!empty($missing)) {
        reasoning_step(
            '&#x26A0;&#xFE0F; Style matched but detail file missing on server: ' . implode(', ', $missing) . ' &mdash; using index only for those',
            'style_missing', 'progressive_disclosure'
        );
    }

    return $context;
}

// ─── Progressive disclosure: 2-level image catalog retrieval ──────────
// Level 1: generic placeholders (avatar/team/banner) are ALWAYS injected —
//          cheap, near-universally useful, no keyword gate needed.
// Level 2: the themed stock-photo catalog is loaded only when the user's
//          message matches enough image-description keywords, capped at
//          the 8 best-scoring entries instead of the full 41-image list.
function build_image_context($message) {
    $indexFile = STYLES_DIR . '/images_index.json';
    if (!is_file($indexFile)) {
        return '';
    }

    $indexRaw = file_get_contents($indexFile);
    $index = json_decode($indexRaw, true);
    if (!is_array($index)) {
        return '';
    }

    $placeholders = $index['placeholders'] ?? [];
    $catalog = $index['catalog'] ?? [];
    $usageNote = $index['usage_note'] ?? '';
    $rules = $index['regole_selezione'] ?? [];

    // Level 1 — generic placeholders always loaded
    $context = "=== IMAGE CATALOG (Level 1 — placeholders, always loaded) ===\n";
    foreach ($placeholders as $file => $desc) {
        $context .= "&#8226; {$file} &mdash; {$desc}\n";
    }
    if ($usageNote !== '') {
        $context .= trim($usageNote) . "\n";
    }
    if (!empty($rules)) {
        $context .= "Regole: " . implode(' ', $rules);
    }

    reasoning_step(
        '&#x1F5BC;&#xFE0F; Image placeholders loaded &middot; ' . count($placeholders) . ' generic asset(s) available',
        'image_index_read', 'styles/images_index.json'
    );

    if (empty($catalog)) {
        return $context;
    }

    // Keyword matching against each catalog entry's precomputed keyword list.
    $msg = mb_strtolower((string) $message);
    $scores = [];
    foreach ($catalog as $file => $entry) {
        $score = 0;
        foreach (($entry['keywords'] ?? []) as $kw) {
            $kw = mb_strtolower(trim((string) $kw));
            if ($kw !== '' && mb_strpos($msg, $kw) !== false) {
                $score++;
            }
        }
        if ($score > 0) {
            $scores[$file] = $score;
        }
    }

    if (empty($scores)) {
        reasoning_step(
            '&#x1F5BC;&#xFE0F; No themed image keywords matched this prompt &mdash; proceeding with placeholders only',
            'image_choice', 'progressive_disclosure'
        );
        return $context;
    }

    // Rank by score desc, cap at 8 (rule: never load the full 36-entry catalog)
    arsort($scores);
    $selected = array_slice(array_keys($scores), 0, 8);

    $context .= "\n\n=== IMAGE CATALOG (Level 2 — themed matches, loaded on demand) ===\n";
    foreach ($selected as $file) {
        $desc = $catalog[$file]['description'] ?? '';
        $context .= "&#8226; {$file} &mdash; {$desc}\n";
    }

    reasoning_step(
        '&#x1F3AF; Themed image(s) loaded &middot; ' . implode(', ', $selected),
        'image_detail_load', 'progressive_disclosure'
    );

    return $context;
}

send_event('status', 'Thinking');

$user_id = $_SESSION['user_id'];
$message = $_POST['message'] ?? '';
$session_id = $_POST['session_id'] ?? 'default';
$attachments = isset($_POST['attachments']) ? json_decode($_POST['attachments'], true) : [];
$urls = isset($_POST['urls']) ? json_decode($_POST['urls'], true) : [];
$debug_mode = !empty($_POST['debug_mode']) && $_POST['debug_mode'] === '1';
$debug_language = trim($_POST['debug_language'] ?? '');
// Modello: preferenza persistita in Setup panel (server-side), non più letta dalla richiesta.
// Paid-only; fallback a gpt-5-nano se non paid o non ancora impostato.
$_ctMode = $_SESSION['user_mode'] ?? 'trial';
$_ctSettings = get_user_settings();
$model = ($_ctMode === 'paid') ? $_ctSettings['model'] : 'gpt-5-nano';

if (!is_array($attachments)) {
    $attachments = [];
}

if (!is_array($urls)) {
    $urls = [];
}

if (trim($message) === '' && empty($attachments)) {
    send_event('error', 'Message is empty');
    exit;
}

// ═══════════════════════════════════════════════════
//  &#x1F4E5; STEP 1 &mdash; Parse &amp; analyze user input
// ═══════════════════════════════════════════════════
$promptSummary = summarize_prompt($message);
$charCount = mb_strlen(trim($message));
$wordCount = str_word_count($message);

if ($promptSummary !== '') {
    reasoning_step(
        '&#x1F4E5; Received prompt: &ldquo;' . $promptSummary . '&rdquo;',
        'prompt_process', 'User prompt'
    );
    reasoning_step(
        '&#x1F4CF; Input analysis: ' . number_format($charCount) . ' chars &middot; ' . number_format($wordCount) . ' words',
        'input_stats', 'prompt_metrics'
    );
}

$processed_message = $message;
$has_images = false;
$image_attachments = [];

// ═══════════════════════════════════════════════════
//  &#x1F4CE; STEP 2 &mdash; Process attachments
// ═══════════════════════════════════════════════════
if (!empty($attachments)) {
    reasoning_step(
        '&#x1F4CE; Processing ' . count($attachments) . ' attachment(s)&hellip;',
        'attachments_start', 'file_processor'
    );
    foreach ($attachments as $att) {
        $attName = $att['name'] ?? 'attachment';
        if (!empty($att['is_image'])) {
            $has_images = true;
            $image_attachments[] = $att['content'] ?? '';
            reasoning_step(
                '&#x1F5BC;&#xFE0F; Image loaded: ' . $attName . ' &rarr; vision pipeline ready',
                'image_load', $attName
            );
        } else {
            $attSize = mb_strlen($att['content'] ?? '');
            reasoning_step(
                '&#x1F4C4; File loaded: ' . $attName . ' (' . format_bytes($attSize) . ')',
                'file_load', $attName
            );
            $processed_message .= "\n\n--- ATTACHED FILE: {$attName} ---\n" . ($att['content'] ?? '') . "\n--- END OF FILE ---";
        }
    }
}

// ═══════════════════════════════════════════════════
//  &#x1F310; STEP 3 &mdash; Web scraping
// ═══════════════════════════════════════════════════
if (!empty($urls)) {
    reasoning_step(
        '&#x1F310; Fetching ' . count($urls) . ' web source(s)&hellip;',
        'web_start', 'scraper'
    );
    foreach ($urls as $url) {
        reasoning_step('&#x1F50D; Connecting to: ' . $url, 'web_fetch', $url);
        $scrape_result = scrape_url($url);
        if (!empty($scrape_result['success'])) {
            $scraped_title = $scrape_result['title'] ?? $url;
            $scraped_content = $scrape_result['content'] ?? '';
            $scraped_words = str_word_count($scraped_content);
            $processed_message .= "\n\n--- WEBSITE CONTENT: {$scraped_title} ({$url}) ---\n{$scraped_content}\n--- END OF CONTENT ---";
            reasoning_step(
                '&#x2705; Fetched: &ldquo;' . $scraped_title . '&rdquo; &mdash; ' . number_format($scraped_words) . ' words extracted',
                'web_fetch_success', $url
            );
        } else {
            $reason = $scrape_result['error'] ?? 'Unknown error';
            reasoning_step(
                '&#x26A0;&#xFE0F; Fetch failed: ' . $url . ' &mdash; ' . $reason,
                'web_fetch_failed', $url
            );
        }
    }
}

// ═══════════════════════════════════════════════════
//  &#x1F4C2; STEP 4 &mdash; Load conversation session
// ═══════════════════════════════════════════════════
reasoning_step('&#x1F4C2; Loading session: ' . $session_id, 'session_load', $session_id);
$session_file = SESSIONS_DIR . '/' . $user_id . '_' . $session_id . '.json';
$session_data = safe_read_json($session_file);

if (empty($session_data)) {
    $session_data = [
        'session_id' => $session_id,
        'user_id' => $user_id,
        'created_at' => date('c'),
        'messages' => []
    ];
    reasoning_step(
        '&#x2728; New session created &mdash; starting fresh conversation',
        'session_new', $session_id
    );
} else {
    $msgCount = count($session_data['messages']);
    reasoning_step(
        '&#x1F4AC; Session loaded: ' . $msgCount . ' messages in history',
        'session_info', $session_id
    );
}

// ═══════════════════════════════════════════════════
//  &#x1F9E0; STEP 5 &mdash; Build context &amp; system prompt
// ═══════════════════════════════════════════════════
// Fallback prompts used only if the corresponding prompts/*.txt file is missing or
// unreadable on this deployment — file_get_contents() returns false in that case, and
// sending 'content' => false to OpenAI is rejected with an opaque HTTP 400. Better to
// degrade to a minimal built-in prompt (with a visible reasoning warning) than to break
// the whole request.
const DEBUG_PROMPT_FALLBACK = 'You are an empathetic and expert code debugger. Analyze the code the user sends, identify bugs, security/performance issues, and propose a refactoring, while being supportive and constructive.';
const SYSTEM_PROMPT_FALLBACK = 'You are CertainThing, an AI coding assistant that generates complete, production-ready web code (HTML, CSS, JavaScript, PHP) from the user\'s request.';

$ledger_entries = [];
if ($debug_mode) {
    $lang_hint = $debug_language ? ' &middot; Language: ' . htmlspecialchars($debug_language) : ' &middot; Language: auto-detect';
    reasoning_step('&#x1F41B; Debug mode activated' . $lang_hint, 'debug_start', 'debugger');
    $system_prompt = @file_get_contents(PROMPTS_DIR . '/debug_prompt.txt');
    if ($system_prompt === false || trim($system_prompt) === '') {
        reasoning_step(
            '&#x26A0;&#xFE0F; debug_prompt.txt missing or unreadable on server &mdash; using built-in fallback prompt',
            'prompt_fallback', 'debug_prompt.txt'
        );
        $system_prompt = DEBUG_PROMPT_FALLBACK;
    }
    // Debug mode analyzes pasted code directly — bounded history/ledger context doesn't apply.
    $history_window = $session_data['messages'];
} else {
    reasoning_step('&#x1F4DC; Loading system prompt&hellip;', 'prompt_load', 'system_prompt.txt');
    $system_prompt = @file_get_contents(PROMPTS_DIR . '/system_prompt.txt');
    if ($system_prompt === false || trim($system_prompt) === '') {
        reasoning_step(
            '&#x26A0;&#xFE0F; system_prompt.txt missing or unreadable on server &mdash; using built-in fallback prompt',
            'prompt_fallback', 'system_prompt.txt'
        );
        $system_prompt = SYSTEM_PROMPT_FALLBACK;
    }

    // ─── Progressive disclosure: inject design-style index + matched detail(s) ───
    $style_context = build_style_context($message);
    if ($style_context !== '') {
        $system_prompt .= "\n\n" . $style_context;
    }

    // ─── Progressive disclosure: inject image catalog (placeholders + matched themed) ───
    $image_context = build_image_context($message);
    if ($image_context !== '') {
        $system_prompt .= "\n\n" . $image_context;
    }

    // ─── Fase 2: bounded history — original prompt + current file state + rules/decisions
    // ledger appended to the system prompt; only the last 5 interactions (code stripped)
    // are replayed as conversational turns, instead of the full unbounded session. ───
    $ledger_entries = load_ledger($user_id, $session_id);
    list($bounded_context, $history_window) = build_bounded_context($session_data);

    if (!empty($ledger_entries)) {
        $bounded_context .= "\n=== RULES & DECISIONS LEDGER (chronological) ===\n";
        foreach ($ledger_entries as $entry) {
            $bounded_context .= '- [' . $entry['type'] . '] ' . $entry['text'] . "\n";
        }
    }

    if ($bounded_context !== '') {
        $system_prompt .= "\n\n" . $bounded_context;
    }

    $totalHistoryMsgs = count($session_data['messages']);
    reasoning_step(
        '&#x1F5C2;&#xFE0F; Bounded context built &middot; ' . count(build_current_file_state($session_data['messages'])) . ' current file(s) &middot; '
            . count($ledger_entries) . ' ledger entr' . (count($ledger_entries) === 1 ? 'y' : 'ies') . ' &middot; '
            . 'last ' . count($history_window) . ' of ' . $totalHistoryMsgs . ' message(s) replayed',
        'bounded_history', 'context_optimization'
    );
}
$messages = [
    ['role' => 'developer', 'content' => $system_prompt]
];

foreach ($history_window as $msg) {
    $role = $msg['role'] ?? '';
    $content = $msg['content'] ?? '';

    if ($role === 'user' && !empty($msg['attachments']) && is_array($msg['attachments'])) {
        $has_img = false;
        $processed = $content;
        $imgs = [];

        foreach ($msg['attachments'] as $att) {
            if (!empty($att['is_image'])) {
                $has_img = true;
                $imgs[] = $att['content'] ?? '';
            } else {
                $attName = $att['name'] ?? 'attachment';
                $processed .= "\n\n--- ATTACHED FILE: {$attName} ---\n" . ($att['content'] ?? '') . "\n--- END OF FILE ---";
            }
        }

        if ($has_img) {
            $user_content = [['type' => 'text', 'text' => $processed]];
            foreach ($imgs as $url) {
                $user_content[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
            }
            $messages[] = ['role' => 'user', 'content' => $user_content];
        } else {
            $messages[] = ['role' => 'user', 'content' => $processed];
        }
    } else {
        $messages[] = ['role' => $role, 'content' => $content];
    }
}

if ($has_images) {
    $user_content = [['type' => 'text', 'text' => $processed_message]];
    foreach ($image_attachments as $img_url) {
        $user_content[] = [
            'type' => 'image_url',
            'image_url' => ['url' => $img_url]
        ];
    }
    $messages[] = ['role' => 'user', 'content' => $user_content];
    reasoning_step(
        '&#x1F441;&#xFE0F; Vision mode enabled: ' . count($image_attachments) . ' image(s) in payload',
        'vision_mode', 'multimodal'
    );
} else {
    $messages[] = ['role' => 'user', 'content' => $processed_message];
}

// ─── Debug mode: code analysis steps ───
if ($debug_mode) {
    $line_count = max(1, substr_count($message, "\n") + 1);
    $char_count  = strlen($message);
    reasoning_step(
        '&#x1F4CF; Code received &middot; ' . number_format($line_count) . ' lines &middot; ' . number_format($char_count) . ' chars',
        'debug_analyze', 'code_parser'
    );
    reasoning_step(
        '&#x1F50D; Sending to ' . $model . ' for empathetic analysis&hellip;',
        'debug_send', $model
    );
}

// ─── Context size estimate ───
$contextJson = json_encode($messages);
$contextBytes = strlen($contextJson);
$tokenEstimate = (int)($contextBytes / 4);
reasoning_step(
    '&#x1F9E9; Context assembled: ~' . number_format($tokenEstimate) . ' tokens &middot; ' . count($messages) . ' messages &middot; ' . format_bytes($contextBytes),
    'context_prep', 'token_estimate'
);

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
    if ($userMode === 'trial') {
        send_event('error', 'No shared API key configured. Contact the admin.');
    } else {
        send_event('error', 'OpenAI API key not configured. Add your key via the 🔑 button.');
    }
    exit;
}

// ─── Token tier logic ────────────────────────────────────────────
$userMode = $_SESSION['user_mode'] ?? 'trial';
if ($userMode === 'paid') {
    $max_tokens = 110000;
    $token_tier = 'paid plan';
} elseif ($key_source === 'user') {
    $max_tokens = 64000;
    $token_tier = 'BYOK';
} else {
    $max_tokens = 28000;
    $token_tier = 'shared key';
}

// Mostra sorgente + preview della chiave nel pannello reasoning
$keyPreview  = substr($openai_key, 0, 15) . '...';
$sourceLabel = match($key_source) {
    'user'   => "🔑 Your key · {$keyPreview}",
    'shared' => "🔑 Shared key (trial) · {$keyPreview}",
    'env'    => "🔑 Server env key · {$keyPreview}",
    default  => '🔑 Key source unknown',
};
reasoning_step($sourceLabel, 'api_key_source', 'config');
reasoning_step(
    '&#x1F3AB; Token budget: ' . number_format($max_tokens) . ' &middot; tier: ' . $token_tier,
    'token_budget', 'config'
);

// ═══════════════════════════════════════════════════
//  &#x1F680; STEP 6 &mdash; Call model
// ═══════════════════════════════════════════════════
reasoning_step(
    '&#x1F680; Calling model: ' . $model . ' &rarr; max ' . number_format($max_tokens) . ' completion tokens (' . $token_tier . ')',
    'model_call', 'chat.completions', $model
);
reasoning_step(
    '&#x26A1; Streaming connection established &mdash; waiting for first token&hellip;',
    'stream_start', 'chat.completions', $model, true
);

$ch = curl_init('https://api.openai.com/v1/chat/completions');
$post_data = [
    'model' => $model,
    'messages' => $messages,
    'stream' => true,
    'stream_options' => ['include_usage' => true],
    'max_completion_tokens' => $max_tokens
];

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $openai_key
]);

send_event('status', 'Generating');

$full_response = '';
$buffer = '';
$json_buffer = '';
$request_cancelled = false;
$stream_start_time = microtime(true);

// Fase 2: streaming holdback so the CT_LEDGER marker (and its JSON tail) never
// reaches the live user stream, even split across multiple SSE chunks. We always
// withhold the last (marker length - 1) characters until we're sure they aren't
// the start of the marker; once the marker is seen, everything after it is
// captured into $full_response as usual but never flushed via send_event.
$ledger_marker_seen = false;
$stream_hold = '';
$markerLen = strlen(CT_LEDGER_MARKER);

curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$full_response, &$buffer, &$json_buffer, &$request_cancelled, &$ledger_marker_seen, &$stream_hold, $markerLen, $model) {
    if (connection_aborted()) {
        $request_cancelled = true;
        return 0;
    }

    $buffer .= $data;

    while (($eventPos = strpos($buffer, "\n\n")) !== false) {
        $eventChunk = substr($buffer, 0, $eventPos);
        $buffer = substr($buffer, $eventPos + 2);

        $lines = explode("\n", $eventChunk);
        $eventData = '';

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, 'data:') !== 0) {
                continue;
            }

                $rawData = substr($line, 5);
                if (strlen($rawData) > 0 && $rawData[0] === ' ') {
                    $rawData = substr($rawData, 1);
                }
                $eventData .= $rawData;
        }

        if ($eventData === '') {
            continue;
        }

        if ($eventData === '[DONE]') {
            $json_buffer = '';
            continue;
        }

        $json_buffer .= $eventData;
        $json = json_decode($json_buffer, true);

        if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
            continue;
        }

        $json_buffer = '';

        if (isset($json['usage'])) {
            send_event('usage', [
                'usage' => $json['usage'],
                'timestamp' => date('c'),
                'model' => $model
            ]);
            if (is_array($json['usage'])) {
                log_openai_usage('chat', $model, $json['usage']);
            }
        }

        if (isset($json['choices'][0]['delta']['reasoning_content'])) {
            $reasoning = (string) $json['choices'][0]['delta']['reasoning_content'];
            if ($reasoning !== '') {
                send_event('reasoning', [
                    'text' => $reasoning,
                    'action' => 'model_reasoning',
                    'resource' => 'Response reasoning',
                    'model' => $model,
                    'timestamp' => date('c'),
                    'streaming' => true
                ]);
            }
        }

        if (isset($json['choices'][0]['delta']['content'])) {
            $content = (string) $json['choices'][0]['delta']['content'];
            $full_response .= $content;

            if ($ledger_marker_seen) {
                // Already inside the ledger tail — captured above into $full_response,
                // never shown to the user.
            } else {
                $combined = $stream_hold . $content;
                $markerPos = strpos($combined, CT_LEDGER_MARKER);
                if ($markerPos !== false) {
                    $visible = substr($combined, 0, $markerPos);
                    if ($visible !== '') {
                        send_event('content', ['text' => $visible]);
                    }
                    $ledger_marker_seen = true;
                    $stream_hold = '';
                } else {
                    $keepLen = min(strlen($combined), $markerLen - 1);
                    $safeLen = strlen($combined) - $keepLen;
                    if ($safeLen > 0) {
                        send_event('content', ['text' => substr($combined, 0, $safeLen)]);
                    }
                    $stream_hold = substr($combined, $safeLen);
                }
            }
        }
    }

    return strlen($data);
});

$curl_result = curl_exec($ch);
$curl_errno = curl_errno($ch);
$curl_error = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Stream ended without ever completing the marker match — whatever was held back
// was genuine trailing content, not a ledger marker, so flush it now.
if (!$ledger_marker_seen && $stream_hold !== '') {
    send_event('content', ['text' => $stream_hold]);
}

$stream_elapsed = round(microtime(true) - $stream_start_time, 2);

if (connection_aborted() || $request_cancelled) {
    exit;
}

if ($curl_result === false && $curl_errno !== CURLE_OK) {
    send_event('error', classify_openai_error(0, $curl_errno, $curl_error, ''));
    send_event('status', 'Error');
    exit;
}

if ($http_code >= 400) {
    // A non-2xx response never gets framed as SSE by OpenAI (no "\n\n" boundaries), so
    // any body bytes it sent are still sitting unflushed in $buffer — that's the raw
    // OpenAI JSON error (or an edge-proxy's HTML/plain-text page for a gateway timeout).
    send_event('error', classify_openai_error($http_code, 0, '', $buffer));
    send_event('status', 'Error');
    exit;
}

if (trim($full_response) === '') {
    send_event('error', 'No response received from model');
    send_event('status', 'Error');
    exit;
}

// ═══════════════════════════════════════════════════
//  &#x1F5C2;&#xFE0F; STEP 6.5 &mdash; Rules/decisions ledger update
// ═══════════════════════════════════════════════════
// Split off the CT_LEDGER marker (never shown to the user, see streaming holdback
// above); $display_response is what gets saved to session history and analyzed below.
list($display_response, $marker_entries) = extract_ledger_marker($full_response);
if ($marker_entries !== null && !empty($marker_entries)) {
    reasoning_step(
        '&#x1F4D3; Ledger marker &middot; ' . count($marker_entries) . ' new entr' . (count($marker_entries) === 1 ? 'y' : 'ies') . ' captured',
        'ledger_marker', 'context_optimization'
    );
} elseif (!$debug_mode && ledger_fallback_heuristic($message, $display_response)) {
    reasoning_step(
        '&#x1F50D; No explicit marker, exchange reads decision-like &mdash; checking with a small model&hellip;',
        'ledger_fallback_check', 'context_optimization'
    );
    $marker_entries = call_small_model_ledger_extract($openai_key, $message, $display_response);
    if (!empty($marker_entries)) {
        reasoning_step(
            '&#x1F4D3; Fallback extraction &middot; ' . count($marker_entries) . ' entr' . (count($marker_entries) === 1 ? 'y' : 'ies') . ' captured',
            'ledger_fallback_hit', 'context_optimization'
        );
    }
}

if (!$debug_mode && !empty($marker_entries)) {
    $ledger_all = array_merge(load_ledger($user_id, $session_id), $marker_entries);
    list($ledger_all, $was_compacted) = compact_ledger_if_needed($openai_key, $ledger_all);
    if ($was_compacted) {
        reasoning_step(
            '&#x1F5C3;&#xFE0F; Ledger compacted &middot; now ' . count($ledger_all) . ' entries',
            'ledger_compact', 'context_optimization'
        );
    }
    save_ledger($user_id, $session_id, $ledger_all);
}

// ═══════════════════════════════════════════════════
//  &#x1F4CA; STEP 7 &mdash; Analyze response
// ═══════════════════════════════════════════════════
$responseWords = str_word_count($display_response);
$responseChars = mb_strlen($display_response);
$responseLines = substr_count($display_response, "\n") + 1;

reasoning_step(
    '&#x2705; Response complete in ' . $stream_elapsed . 's &mdash; ' . number_format($responseWords) . ' words &middot; ' . number_format($responseChars) . ' chars &middot; ' . number_format($responseLines) . ' lines',
    'response_stats', 'output', $model
);

// ═══════════════════════════════════════════════════
//  &#x1F4C1; STEP 8 &mdash; Detect generated files
// ═══════════════════════════════════════════════════
$generatedFiles = extract_generated_filenames($display_response);
if (!empty($generatedFiles)) {
    reasoning_step(
        '&#x1F50E; Detected ' . count($generatedFiles) . ' generated file(s) in response',
        'file_detect', 'code_analysis', $model
    );
    foreach ($generatedFiles as $fileName) {
        reasoning_step(
            '&#x1F4BE; Creating file: ' . $fileName,
            'file_create', $fileName, $model
        );
    }
} else {
    reasoning_step(
        '&#x1F4DD; Text-only response &mdash; no files detected',
        'response_type', 'text', $model
    );
}

// ═══════════════════════════════════════════════════
//  &#x1F4BE; STEP 9 &mdash; Save session
// ═══════════════════════════════════════════════════
reasoning_step('&#x1F4BE; Saving session: ' . $session_id, 'session_save', $session_id);
$session_data['messages'][] = [
    'role' => 'user',
    'content' => $message,
    'attachments' => $attachments,
    'timestamp' => date('c')
];
$session_data['messages'][] = [
    'role' => 'assistant',
    'content' => $display_response,
    'timestamp' => date('c')
];
$session_data['updated_at'] = date('c');

safe_write_json($session_file, $session_data);

$totalMessages = count($session_data['messages']);
reasoning_step(
    '&#x2705; Session saved &mdash; ' . $totalMessages . ' messages total',
    'session_saved', $session_id
);

// ═══════════════════════════════════════════════════
//  &#x1F3C1; DONE
// ═══════════════════════════════════════════════════
reasoning_step('&#x1F3C1; Done.', 'done', 'Response ready', $model);
send_event('status', 'Done');