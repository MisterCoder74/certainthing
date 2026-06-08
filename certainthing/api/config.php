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
        @ob_flush();
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

// In config.php, modifica queste funzioni:

function get_user_key_file() {
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) return null;
    return __DIR__ . '/../data/keys/' . $userId . '.key';
}

function get_openai_api_key() {
    $file = get_user_key_file();
    if ($file && file_exists($file)) {
        return trim(file_get_contents($file));
    }
    // Fallback: variabile d'ambiente globale (admin default)
    return getenv('OPENAI_API_KEY') ?: '';
}

function save_openai_api_key($key) {
    $file = get_user_key_file();
    if (!$file) return false;
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    return file_put_contents($file, $key) !== false;
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

/* PRODUCTION 
define('STRIPE_SECRET_KEY',      'sk_live_');
define('STRIPE_PUBLISHABLE_KEY', 'pk_live_');
*/
/* TEST */
define('STRIPE_SECRET_KEY',      'sk_test_');
define('STRIPE_PUBLISHABLE_KEY', 'pk_test_');

define('STRIPE_WEBHOOK_SECRET',  'whsec_');    // dopo aver creato il webhook
define('STRIPE_PRICE_ID',        'price_');
// URL base dell'app — usato per i redirect Stripe
define('BASE_URL', 'https://www.vivacitydesign.net/certainThing/v1.2/certainthing');

/**
 * Chiama l'API Stripe via cURL (no dipendenze Composer)
 * @param string $method  GET | POST
 * @param string $endpoint  es. "checkout/sessions", "checkout/sessions/cs_xxx"
 * @param array  $data  parametri POST (ignorati per GET)
 * @return array  risposta decodificata
 */
function stripe_api(string $method, string $endpoint, array $data = []): array {
    $ch = curl_init("https://api.stripe.com/v1/{$endpoint}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
        CURLOPT_HTTPHEADER     => ['Stripe-Version: 2023-10-16'],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST,       true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode((string) $response, true) ?: [];
}
