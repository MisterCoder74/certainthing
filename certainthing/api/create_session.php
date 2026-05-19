<?php
/**
 * API: Create a placeholder session file.
 * Called when user clicks "New Chat" to make the session visible immediately.
 */

require_once __DIR__ . '/config.php';
check_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$user_id = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
$session_id = $input['session_id'] ?? '';

if (empty($session_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'session_id is required']);
    exit;
}

$session_file = SESSIONS_DIR . '/' . $user_id . '_' . $session_id . '.json';

// Don't overwrite existing session
if (file_exists($session_file)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'session_id' => $session_id]);
    exit;
}

$session_data = [
    'session_id' => $session_id,
    'user_id' => $user_id,
    'created_at' => date('c'),
    'messages' => [],
    'updated_at' => date('c')
];

if (safe_write_json($session_file, $session_data)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'session_id' => $session_id]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create session']);
}