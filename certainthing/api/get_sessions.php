<?php
/**
 * API: List all sessions for the authenticated user.
 * Returns session metadata sorted by last updated (most recent first).
 */

require_once __DIR__ . '/config.php';
check_auth();

$user_id = $_SESSION['user_id'];
$pattern = SESSIONS_DIR . '/' . $user_id . '_*.json';
$session_files = glob($pattern);

$sessions = [];

foreach ($session_files as $file) {
    $data = safe_read_json($file);
    if (empty($data)) continue;

    // Extract session_id from filename: user_id_sess_xxx.json
    $filename = basename($file, '.json');
    $parts = explode('_', $filename, 2);
    $session_id = $parts[1] ?? '';

    $messages = $data['messages'] ?? [];
    // Find the first user message as a title
    $title = 'Untitled';
    foreach ($messages as $msg) {
        if ($msg['role'] === 'user' && !empty($msg['content'])) {
            $title = mb_substr($msg['content'], 0, 60);
            if (mb_strlen($msg['content']) > 60) $title .= '…';
            break;
        }
    }

    $sessions[] = [
        'session_id' => $session_id,
        'title' => $title,
        'created_at' => $data['created_at'] ?? '',
        'updated_at' => $data['updated_at'] ?? '',
        'message_count' => count($messages)
    ];
}

// Sort by updated_at descending (most recent first)
usort($sessions, function ($a, $b) {
    $aTime = $a['updated_at'] ? strtotime($a['updated_at']) : 0;
    $bTime = $b['updated_at'] ? strtotime($b['updated_at']) : 0;
    return $bTime - $aTime;
});

header('Content-Type: application/json');
echo json_encode(['sessions' => $sessions]);