<?php
/**
 * API: Delete a session for the authenticated user.
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

// Security: ensure the session belongs to this user
$session_file = SESSIONS_DIR . '/' . $user_id . '_' . $session_id . '.json';

if (!file_exists($session_file)) {
    http_response_code(404);
    echo json_encode(['error' => 'Session not found']);
    exit;
}

// Verify ownership by checking the filename pattern (user_id prefix)
$expected_prefix = $user_id . '_';
$basename = basename($session_file);
if (strpos($basename, $expected_prefix) !== 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

if (unlink($session_file)) {
    // Also clean up any deploy directory for this session
    $deploy_dir = __DIR__ . '/../deploy/' . $user_id . '/' . $session_id;
    if (is_dir($deploy_dir)) {
        array_map('unlink', glob($deploy_dir . '/*.*'));
        rmdir($deploy_dir);
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to delete session']);
}