<?php
/**
 * API: Deploy generated code files to a live-viewable folder.
 * Files are saved to: deploy/{user_id}/{session_id}/
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
$files = $input['files'] ?? [];

if (empty($session_id) || empty($files)) {
    http_response_code(400);
    echo json_encode(['error' => 'session_id and files array are required']);
    exit;
}

if (!is_array($files)) {
    http_response_code(400);
    echo json_encode(['error' => 'files must be an array']);
    exit;
}

// Create deploy directory for this user + session
$deploy_dir = __DIR__ . '/../deploy/' . $user_id . '/' . $session_id;
if (!is_dir($deploy_dir)) {
    mkdir($deploy_dir, 0755, true);
}

$written_files = [];

foreach ($files as $file) {
    $name = $file['name'] ?? 'unnamed_file';
    $content = $file['content'] ?? '';

    // Sanitize filename: prevent directory traversal
    // 1. Remove path separators and '..'
    $name = str_replace(['..', '\\'], ['', ''], $name);
    $name = basename($name);
    
    if (empty($name)) {
        $name = 'unnamed_file_' . uniqid();
    }

    $filepath = $deploy_dir . '/' . $name;

    // Double-check: ensure resolved path stays within deploy directory
    $real_deploy = realpath($deploy_dir);
    $real_target = realpath($filepath);
    if ($real_target === false) {
        // File doesn't exist yet, check parent
        $parent = dirname($filepath);
        $real_parent = realpath($parent);
        if ($real_parent === false || strpos($real_parent, $real_deploy) !== 0) {
            continue;
        }
    } else {
        if (strpos($real_target, $real_deploy) !== 0) {
            continue;
        }
    }

    if (file_put_contents($filepath, $content) !== false) {
        $written_files[] = $name;
    }
}

if (empty($written_files)) {
    http_response_code(500);
    echo json_encode(['error' => 'No files were written']);
    exit;
}

// Determine a base URL for viewing
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base_url = $protocol . '://' . $host;

// Get the base path by removing /api from the script path
$script_path = $_SERVER['SCRIPT_NAME'] ?? '/api/deploy.php';
$base_path = dirname(dirname($script_path));
$base_path = str_replace('/api', '', $base_path);
$base_path = rtrim($base_path, '/');

$view_url = $base_url . $base_path . '/deploy/' . $user_id . '/' . $session_id;

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'files' => $written_files,
    'count' => count($written_files),
    'deploy_path' => '/deploy/' . $user_id . '/' . $session_id,
    'view_url' => $view_url
]);