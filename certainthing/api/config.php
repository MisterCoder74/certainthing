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
define('OPENAI_KEY_FILE', DATA_DIR . '/openai_api_key.txt');

/**
 * SSE Helper: Send event to client
 */
function send_event($type, $payload = '') {
    if (!is_array($payload)) {
        $payload = ['text' => (string) $payload];
    }

    $payload['type'] = $type;
    echo "data: " . json_encode($payload) . "\n\n";

    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

/**
 * Check if user is authenticated
 */
function check_auth() {
    if (!isset($_SESSION['user_id'])) {
        header('HTTP/1.1 401 Unauthorized');
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

/**
 * Get currently configured OpenAI API key
 */
function get_openai_api_key() {
    if (file_exists(OPENAI_KEY_FILE)) {
        $fileKey = trim((string) @file_get_contents(OPENAI_KEY_FILE));
        if ($fileKey !== '') {
            return $fileKey;
        }
    }

    return trim((string) getenv('OPENAI_API_KEY'));
}

/**
 * Save OpenAI API key to server-side file
 */
function save_openai_api_key($apiKey) {
    $apiKey = trim((string) $apiKey);

    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0777, true);
    }

    if ($apiKey === '') {
        if (file_exists(OPENAI_KEY_FILE)) {
            return unlink(OPENAI_KEY_FILE);
        }
        return true;
    }

    $bytes = file_put_contents(OPENAI_KEY_FILE, $apiKey, LOCK_EX);
    if ($bytes === false) {
        return false;
    }

    @chmod(OPENAI_KEY_FILE, 0600);
    return true;
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
    $fp = fopen($file, 'c');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}
