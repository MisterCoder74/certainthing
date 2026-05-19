<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/scrape.php';

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
$attachments = isset($_POST['attachments']) ? json_decode($_POST['attachments'], true) : [];
$urls = isset($_POST['urls']) ? json_decode($_POST['urls'], true) : [];

if (empty($message) && empty($attachments)) {
    send_event('error', 'Message is empty');
    exit;
}

// 1. Process Attachments for LLM
$processed_message = $message;
$has_images = false;
$image_attachments = [];

if (!empty($attachments)) {
    foreach ($attachments as $att) {
        if ($att['is_image']) {
            $has_images = true;
            $image_attachments[] = $att['content']; // base64 data url
        } else {
            $processed_message .= "\n\n--- ATTACHED FILE: {$att['name']} ---\n" . $att['content'] . "\n--- END OF FILE ---";
        }
    }
}

// 1b. Scrape URLs if provided
if (!empty($urls)) {
    foreach ($urls as $url) {
        send_event('reasoning', "Fetching website: {$url}...");
        $scrape_result = scrape_url($url);
        if ($scrape_result['success']) {
            $scraped_title = $scrape_result['title'];
            $scraped_content = $scrape_result['content'];
            $processed_message .= "\n\n--- WEBSITE CONTENT: {$scraped_title} ({$url}) ---\n{$scraped_content}\n--- END OF CONTENT ---";
            send_event('reasoning', "Successfully fetched: {$url}");
        } else {
            send_event('reasoning', "Failed to fetch website: {$url} - " . $scrape_result['error']);
        }
    }
}

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
    ['role' => 'developer', 'content' => $system_prompt]
];

// Add history
foreach ($session_data['messages'] as $msg) {
    $role = $msg['role'];
    $content = $msg['content'];
    
    if ($role === 'user' && !empty($msg['attachments'])) {
        $has_img = false;
        $processed = $content;
        $imgs = [];
        
        foreach ($msg['attachments'] as $att) {
            if ($att['is_image']) {
                $has_img = true;
                $imgs[] = $att['content'];
            } else {
                $processed .= "\n\n--- ATTACHED FILE: {$att['name']} ---\n" . $att['content'] . "\n--- END OF FILE ---";
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

// Add new message
if ($has_images) {
    $user_content = [
        ['type' => 'text', 'text' => $processed_message]
    ];
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

// 4. OpenAI Request
if (empty($openai_key)) {
    send_event('error', 'OpenAI API key not configured');
    exit;
}

send_event('reasoning', 'Connecting to OpenAI...');
$ch = curl_init('https://api.openai.com/v1/chat/completions');

$post_data = [
    'model' => 'gpt-5-nano', // Upgraded to gpt-5-nano for real thinking tokens
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

// Callback for streaming
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$full_response, &$buffer, &$json_buffer) {
    $buffer .= $data;
    while (($pos = strpos($buffer, "\n")) !== false) {
        $line = substr($buffer, 0, $pos);
        $buffer = substr($buffer, $pos + 1);
        $line = trim($line);

        if (empty($line)) continue;
        if (strpos($line, 'data: ') !== 0) continue;

        $json_str = substr($line, 6);
        if ($json_str === '[DONE]') {
            $json_buffer = '';
            continue;
        }

        $json_buffer .= $json_str;
        $json = json_decode($json_buffer, true);

        if ($json !== null && json_last_error() === JSON_ERROR_NONE) {
            $json_buffer = ''; // Success, clear for next event

            // Handle Usage
            if (isset($json['usage'])) {
                $usage = $json['usage'];
                echo "data: " . json_encode(['type' => 'usage', 'usage' => $usage]) . "\n\n";
                if (ob_get_level() > 0) ob_flush();
                flush();
            }

            // Handle Reasoning Content (Thinking Tokens)
            if (isset($json['choices'][0]['delta']['reasoning_content'])) {
                $reasoning = $json['choices'][0]['delta']['reasoning_content'];
                echo "data: " . json_encode(['type' => 'reasoning', 'text' => $reasoning, 'streaming' => true]) . "\n\n";
                if (ob_get_level() > 0) ob_flush();
                flush();
            }

            // Handle Regular Content
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
$session_data['messages'][] = [
    'role' => 'user', 
    'content' => $message, 
    'attachments' => $attachments,
    'timestamp' => date('c')
];
$session_data['messages'][] = ['role' => 'assistant', 'content' => $full_response, 'timestamp' => date('c')];
$session_data['updated_at'] = date('c');

safe_write_json($session_file, $session_data);

send_event('reasoning', 'Done.');
send_event('status', 'Done');
