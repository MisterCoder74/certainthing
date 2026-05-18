<?php
require_once __DIR__ . '/config.php';
check_auth();

$user_id = $_SESSION['user_id'];
$session_id = $_GET['session_id'] ?? 'default';

$session_file = SESSIONS_DIR . '/' . $user_id . '_' . $session_id . '.json';
if (file_exists($session_file)) {
    echo file_get_contents($session_file);
} else {
    echo json_encode(['messages' => []]);
}
