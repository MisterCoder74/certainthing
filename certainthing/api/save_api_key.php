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
    $key = get_openai_api_key();
    echo json_encode([
        'configured' => $key !== '',
        'masked_key' => mask_api_key_value($key),
        'source' => file_exists(OPENAI_KEY_FILE) ? 'file' : 'environment'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    $input = $_POST;
}

$apiKey = trim((string) ($input['api_key'] ?? ''));

if ($apiKey !== '' && !preg_match('/^sk-[A-Za-z0-9\-_]+$/', $apiKey)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid API key format']);
    exit;
}

if (!save_openai_api_key($apiKey)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save API key']);
    exit;
}

echo json_encode([
    'success' => true,
    'configured' => $apiKey !== '',
    'masked_key' => mask_api_key_value($apiKey),
    'source' => $apiKey !== '' ? 'file' : 'environment'
]);
