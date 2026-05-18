<?php
/**
 * Configuration & Helpers
 */

// Error reporting for development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Session start if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Global settings
define('APP_NAME', 'CertainThing');
define('DATA_DIR', __DIR__ . '/../data');
define('SESSIONS_DIR', DATA_DIR . '/sessions');
define('USERS_FILE', DATA_DIR . '/users.json');
define('PROMPTS_DIR', __DIR__ . '/../prompts');

// OpenAI settings
$openai_key = getenv('OPENAI_API_KEY') ?: ''; // Set this in your environment

/**
 * SSE Helper: Send event to client
 */
function send_event($type, $text) {
    echo "data: " . json_encode(['type' => $type, 'text' => $text]) . "\n\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
}

/**
 * Check if user is authenticated
 */
function check_auth() {
    if (!isset($_SESSION['user_id'])) {
        header('HTTP/1.1 401 Unauthorized');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

/**
 * Safe file read with FLOCK
 */
function safe_read_json($file) {
    if (!file_exists($file)) return [];
    $fp = fopen($file, 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $size = filesize($file);
    $content = $size > 0 ? fread($fp, $size) : '[]';
    flock($fp, LOCK_UN);
    fclose($fp);
    return json_decode($content, true) ?: [];
}

/**
 * Safe file write with FLOCK
 */
function safe_write_json($file, $data) {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $fp = fopen($file, 'c'); // Open for reading/writing; create if not exists
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0); // Clear the file
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}
