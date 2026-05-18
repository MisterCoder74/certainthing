<?php
require_once __DIR__ . '/config.php';

// Prevent output buffering
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // For Nginx

check_auth();

send_event('status', 'Thinking');

$user_id = $_SESSION['user_id'];
$message = $_POST['message'] ?? '';
$session_id = $_POST['session_id'] ?? 'default';

if (empty($message)) {
    send_event('error', 'Message is empty');
    exit;
}

// 1. Simulated Reasoning
send_event('reasoning', 'Reading your request...');
usleep(300000); // 0.3s

// 2. Load/Create Session
send_event('reasoning', 'Loading session history...');
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

// 3. Prepare Prompt
send_event('reasoning', 'Preparing system prompt...');
$system_prompt = file_get_contents(PROMPTS_DIR . '/system_prompt.txt');
$messages = [
    ['role' => 'system', 'content' => $system_prompt]
];

// Add history
foreach ($session_data['messages'] as $msg) {
    $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
}

// Add new message
$messages[] = ['role' => 'user', 'content' => $message];

send_event('reasoning', 'Planning code structure...');
usleep(300000); // 0.3s

// 4. OpenAI Request
if (empty($openai_key)) {
    send_event('error', 'OpenAI API key not configured');
    exit;
}

send_event('reasoning', 'Connecting to OpenAI...');
$ch = curl_init('https://api.openai.com/v1/chat/completions');

$post_data = [
    'model' => 'gpt-4o',
    'messages' => $messages,
    'stream' => true
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

// Callback for streaming
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$full_response) {
    $lines = explode("\n", $data);
    foreach ($lines as $line) {
        if (strpos($line, 'data: ') === 0) {
            $json_str = substr($line, 6);
            if ($json_str === '[DONE]') {
                break;
            }
            $json = json_decode($json_str, true);
            if (isset($json['choices'][0]['delta']['content'])) {
                $content = $json['choices'][0]['delta']['content'];
                $full_response .= $content;
                echo "data: " . json_encode(['type' => 'content', 'text' => $content]) . "\n\n";
                if (ob_get_level() > 0) ob_flush();
                flush();
            }
        }
    }
    return strlen($data);
});

curl_exec($ch);
curl_close($ch);

// 5. Save Session
$session_data['messages'][] = ['role' => 'user', 'content' => $message, 'timestamp' => date('c')];
$session_data['messages'][] = ['role' => 'assistant', 'content' => $full_response, 'timestamp' => date('c')];
$session_data['updated_at'] = date('c');

safe_write_json($session_file, $session_data);

send_event('reasoning', 'Done.');
send_event('status', 'Done');
