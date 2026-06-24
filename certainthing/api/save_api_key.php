<?php
require_once __DIR__ . '/config.php';
check_auth();

header('Content-Type: application/json');

function mask_api_key_value($key) {
    $key = trim((string) $key);
    if ($key === '') {
        return '';
    }

    if (strlen($key) <= 8) {
        return str_repeat('*', strlen($key));
    }

    return substr($key, 0, 4) . str_repeat('*', max(strlen($key) - 8, 0)) . substr($key, -4);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
// ✅ FIX
if (function_exists('get_openai_api_key_with_source')) {
    $keyInfo = get_openai_api_key_with_source();
    $key     = $keyInfo['key'];
    $source  = $keyInfo['source'];
} else {
    $key          = get_openai_api_key();
    $userId       = $_SESSION['user_id'] ?? '';
    $userKeyFile  = __DIR__ . '/../data/keys/' . $userId . '.key';  // ← __DIR__ + risali di un livello
    $sharedFile   = __DIR__ . '/../data/shared_key.txt';             // ← idem
    if ($userId && file_exists($userKeyFile) && strlen(trim(file_get_contents($userKeyFile))) > 20) {
        $source = 'user';
    } elseif (file_exists($sharedFile) && strlen(trim(file_get_contents($sharedFile))) > 20) {
        $source = 'shared';
    } elseif (getenv('OPENAI_API_KEY') !== false && getenv('OPENAI_API_KEY') !== '') {
        $source = 'env';
    } else {
        $source = 'none';
    }
}

$preview = $key !== '' ? substr($key, 0, 15) . '...' : '';


    // Get user's current plan mode from users.json
    $users  = safe_read_json(USERS_FILE);
    $userId = $_SESSION['user_id'] ?? '';
    $mode   = 'trial'; // safe default
    foreach ($users as $u) {
        if (($u['id'] ?? '') === $userId) {
            $mode = $u['mode'] ?? 'trial';
            break;
        }
    }

    echo json_encode([
        'configured'          => $key !== '',
        'masked_key'          => mask_api_key_value($key),
        'source'              => $source,
        'key_preview'         => $preview,
        'is_trial'            => ($mode === 'trial'),
        'show_shared_warning' => ($source === 'shared' && $mode === 'trial'),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    $input = $_POST;
}

$apiKey = trim((string) ($input['api_key'] ?? ''));

// Validate format (only when non-empty — empty = delete key)
if ($apiKey !== '' && !preg_match('/^sk-[A-Za-z0-9\-_]+$/', $apiKey)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid API key format']);
    exit;
}

$file = get_user_key_file();

if ($apiKey === '') {
    // DELETE: remove the file instead of writing empty — prevents cascade bug
    if ($file && file_exists($file)) {
        unlink($file);
    }
    echo json_encode([
        'success'    => true,
        'configured' => false,
        'masked_key' => '',
        'source'     => 'none'
    ]);
    exit;
}

// SAVE non-empty key
if (!save_openai_api_key($apiKey)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save API key']);
    exit;
}

echo json_encode([
    'success'    => true,
    'configured' => true,
    'masked_key' => mask_api_key_value($apiKey),
    'source'     => 'user'
]);