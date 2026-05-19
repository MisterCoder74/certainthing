<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/scrape.php';

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

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
        return mb_substr($clean, 0, $maxLen) . '…';
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

send_event('status', 'Thinking');

$user_id = $_SESSION['user_id'];
$message = $_POST['message'] ?? '';
$session_id = $_POST['session_id'] ?? 'default';
$attachments = isset($_POST['attachments']) ? json_decode($_POST['attachments'], true) : [];
$urls = isset($_POST['urls']) ? json_decode($_POST['urls'], true) : [];
$model = 'gpt-5-nano';

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

$promptSummary = summarize_prompt($message);
if ($promptSummary !== '') {
    reasoning_step('Processing prompt: "' . $promptSummary . '"', 'prompt_process', 'User prompt');
}

$processed_message = $message;
$has_images = false;
$image_attachments = [];

if (!empty($attachments)) {
    foreach ($attachments as $att) {
        $attName = $att['name'] ?? 'attachment';
        if (!empty($att['is_image'])) {
            $has_images = true;
            $image_attachments[] = $att['content'] ?? '';
            reasoning_step('Loading image: ' . $attName, 'image_load', $attName);
        } else {
            reasoning_step('Loading file: ' . $attName, 'file_load', $attName);
            $processed_message .= "\n\n--- ATTACHED FILE: {$attName} ---\n" . ($att['content'] ?? '') . "\n--- END OF FILE ---";
        }
    }
}

if (!empty($urls)) {
    foreach ($urls as $url) {
        reasoning_step('Fetching website: ' . $url, 'web_fetch', $url);
        $scrape_result = scrape_url($url);
        if (!empty($scrape_result['success'])) {
            $scraped_title = $scrape_result['title'] ?? $url;
            $scraped_content = $scrape_result['content'] ?? '';
            $processed_message .= "\n\n--- WEBSITE CONTENT: {$scraped_title} ({$url}) ---\n{$scraped_content}\n--- END OF CONTENT ---";
            reasoning_step('Fetched website content: ' . $url, 'web_fetch_success', $url);
        } else {
            $reason = $scrape_result['error'] ?? 'Unknown error';
            reasoning_step('Failed to fetch website: ' . $url . ' - ' . $reason, 'web_fetch_failed', $url);
        }
    }
}

reasoning_step('Loading session: ' . $session_id, 'session_load', $session_id);
$session_file = SESSIONS_DIR . '/' . $user_id . '_' . $session_id . '.json';
$session_data = safe_read_json($session_file);

if (empty($session_data)) {
    $session_data = [
        'session_id' => $session_id,
        'user_id' => $user_id,
        'created_at' => date('c'),
        'messages' => []
    ];
}

reasoning_step('Preparing system prompt', 'prompt_load', 'system_prompt.txt');
$system_prompt = file_get_contents(PROMPTS_DIR . '/system_prompt.txt');
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
} else {
    $messages[] = ['role' => 'user', 'content' => $processed_message];
}

$openai_key = get_openai_api_key();
if ($openai_key === '') {
    send_event('error', 'OpenAI API key not configured');
    exit;
}

reasoning_step('Calling model: ' . $model, 'model_call', 'chat.completions', $model);

$ch = curl_init('https://api.openai.com/v1/chat/completions');
$post_data = [
    'model' => $model,
    'messages' => $messages,
    'stream' => true,
    'stream_options' => ['include_usage' => true],
    'max_completion_tokens' => 10000
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

            $eventData .= trim(substr($line, 5));
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

$generatedFiles = extract_generated_filenames($full_response);
foreach ($generatedFiles as $fileName) {
    reasoning_step('Creating file: ' . $fileName, 'file_create', $fileName, $model);
}

reasoning_step('Saving session: ' . $session_id, 'session_save', $session_id);
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

reasoning_step('Done.', 'done', 'Response ready', $model);
send_event('status', 'Done');
