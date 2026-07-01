<?php
/**
 * API: Generate a short AI title for a session.
 * Called after the first message exchange is complete.
 * Stores the result as custom_title in the session JSON.
 */

require_once __DIR__ . '/config.php';
check_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

header('Content-Type: application/json');

$user_id    = $_SESSION['user_id'];
$input      = json_decode(file_get_contents('php://input'), true);
$session_id = trim($input['session_id'] ?? '');

if (empty($session_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'session_id is required']);
    exit;
}

// Validate ownership and load session
$session_file = SESSIONS_DIR . '/' . $user_id . '_' . $session_id . '.json';
if (!file_exists($session_file)) {
    http_response_code(404);
    echo json_encode(['error' => 'Session not found']);
    exit;
}

$data = safe_read_json($session_file);

// Return immediately if already titled
if (!empty($data['custom_title'])) {
    echo json_encode(['title' => $data['custom_title']]);
    exit;
}

// Find first user message
$firstUserMsg = '';
foreach ($data['messages'] ?? [] as $msg) {
    if ($msg['role'] === 'user' && !empty($msg['content'])) {
        $firstUserMsg = mb_substr(trim((string) $msg['content']), 0, 300);
        break;
    }
}

if (empty($firstUserMsg)) {
    echo json_encode(['title' => 'New Session']);
    exit;
}

$title   = '';
$apiKey  = get_openai_api_key();

// Try AI-generated title (fast, cheap: max 20 tokens)
if (!empty($apiKey)) {
    $payload = [
        'model'       => 'gpt-5-nano',
        'messages'    => [
            [
                'role'    => 'system',
                'content' => 'Generate a concise 3-5 word title for this chat session. Reply with ONLY the title — no quotes, no period at the end.'
            ],
            [
                'role'    => 'user',
                'content' => $firstUserMsg
            ]
        ],
        'max_tokens'  => 20,
        'temperature' => 0.4
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_TIMEOUT        => 10
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $result    = json_decode($response, true);
        $candidate = trim($result['choices'][0]['message']['content'] ?? '');
        $candidate = trim($candidate, '"\'');
        // Accept only sane lengths
        if (mb_strlen($candidate) >= 3 && mb_strlen($candidate) <= 80) {
            $title = $candidate;
        }
    }
}

// Fallback: smart truncation of first message
if (empty($title)) {
    $title = mb_substr($firstUserMsg, 0, 50);
    if (mb_strlen($firstUserMsg) > 50) {
        $title .= '…';
    }
}

// Persist so subsequent calls are instant
$data['custom_title'] = $title;
safe_write_json($session_file, $data);

echo json_encode(['title' => $title]);
