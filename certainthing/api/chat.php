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
ob_flush();
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
if ($debug_mode) {
    $lang_hint = $debug_language ? ' &middot; Language: ' . htmlspecialchars($debug_language) : ' &middot; Language: auto-detect';
    reasoning_step('&#x1F41B; Debug mode activated' . $lang_hint, 'debug_start', 'debugger');
    $system_prompt = file_get_contents(PROMPTS_DIR . '/debug_prompt.txt');
} else {
    reasoning_step('&#x1F4DC; Loading system prompt&hellip;', 'prompt_load', 'system_prompt.txt');
    $system_prompt = file_get_contents(PROMPTS_DIR . '/system_prompt.txt');

    // ─── Progressive disclosure: inject design-style index + matched detail(s) ───
    $style_context = build_style_context($message);
    if ($style_context !== '') {
        $system_prompt .= "\n\n" . $style_context;
    }
}
$messages = [
    ['role' => 'developer', 'content' => $system_prompt]
];

foreach ($session_data['messages'] as $msg) {
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

curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$full_response, &$buffer, &$json_buffer, &$request_cancelled, $model) {
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
            send_event('content', ['text' => $content]);
        }
    }

    return strlen($data);
});

$curl_result = curl_exec($ch);
$curl_errno = curl_errno($ch);
$curl_error = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$stream_elapsed = round(microtime(true) - $stream_start_time, 2);

if (connection_aborted() || $request_cancelled) {
    exit;
}

if ($curl_result === false && $curl_errno !== CURLE_OK) {
    send_event('error', 'OpenAI streaming error: ' . ($curl_error ?: 'Unknown cURL error'));
    send_event('status', 'Error');
    exit;
}

if ($http_code >= 400) {
    send_event('error', 'OpenAI request failed with HTTP ' . $http_code);
    send_event('status', 'Error');
    exit;
}

if (trim($full_response) === '') {
    send_event('error', 'No response received from model');
    send_event('status', 'Error');
    exit;
}

// ═══════════════════════════════════════════════════
//  &#x1F4CA; STEP 7 &mdash; Analyze response
// ═══════════════════════════════════════════════════
$responseWords = str_word_count($full_response);
$responseChars = mb_strlen($full_response);
$responseLines = substr_count($full_response, "\n") + 1;

reasoning_step(
    '&#x2705; Response complete in ' . $stream_elapsed . 's &mdash; ' . number_format($responseWords) . ' words &middot; ' . number_format($responseChars) . ' chars &middot; ' . number_format($responseLines) . ' lines',
    'response_stats', 'output', $model
);

// ═══════════════════════════════════════════════════
//  &#x1F4C1; STEP 8 &mdash; Detect generated files
// ═══════════════════════════════════════════════════
$generatedFiles = extract_generated_filenames($full_response);
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
    'content' => $full_response,
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