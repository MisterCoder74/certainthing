<?php
/**
 * CertainThing — Key Diagnostic Tool
 * Metti questo file nella root di certainthing/ (accanto a index.php)
 * Aprilo da browser: https://.../certainthing/debug_key.php
 * CANCELLALO dopo l'uso!
 */
require_once __DIR__ . '/api/config.php';

header('Content-Type: text/plain; charset=utf-8');

$data_dir   = __DIR__ . '/data';
$shared     = $data_dir . '/shared_key.txt';
$legacy     = $data_dir . '/openai_api_key.txt';   // vecchio percorso
$session_uid = session_id() ?: '(no session)';
$user_id    = $_SESSION['user_id'] ?? '(not logged in)';
$user_key   = $data_dir . '/keys/' . $user_id . '.key';

echo "=== CertainThing Key Diagnostic ===\n\n";

echo "-- 1. data/ directory --\n";
echo "Path     : $data_dir\n";
echo "Exists   : " . (is_dir($data_dir) ? 'YES' : 'NO') . "\n";
echo "Readable : " . (is_readable($data_dir) ? 'YES' : 'NO') . "\n\n";

echo "-- 2. shared_key.txt --\n";
echo "Path     : $shared\n";
echo "Exists   : " . (file_exists($shared) ? 'YES' : 'NO') . "\n";
if (file_exists($shared)) {
    $k = trim(file_get_contents($shared));
    echo "Readable : YES\n";
    echo "Length   : " . strlen($k) . " chars\n";
    echo "Preview  : " . (strlen($k) > 6 ? substr($k,0,4).'***'.substr($k,-3) : '(too short)') . "\n";
    echo "Has *    : " . (strpos($k,'*') !== false ? 'YES — PLACEHOLDER, not a real key!' : 'no') . "\n";
} else {
    echo "Readable : N/A\n";
}

echo "\n-- 3. Legacy openai_api_key.txt (old path) --\n";
echo "Path     : $legacy\n";
echo "Exists   : " . (file_exists($legacy) ? 'YES' : 'NO') . "\n";
if (file_exists($legacy)) {
    $k = trim(file_get_contents($legacy));
    echo "Length   : " . strlen($k) . " chars\n";
    echo "Preview  : " . (strlen($k) > 6 ? substr($k,0,4).'***'.substr($k,-3) : '(too short)') . "\n";
}

echo "\n-- 4. Per-user key (requires login) --\n";
echo "Session user_id : $user_id\n";
echo "Key path        : $user_key\n";
if ($user_id !== '(not logged in)') {
    echo "Exists          : " . (file_exists($user_key) ? 'YES' : 'NO') . "\n";
    if (file_exists($user_key)) {
        $k = trim(file_get_contents($user_key));
        echo "Length          : " . strlen($k) . " chars\n";
    }
}

echo "\n-- 5. Environment variable --\n";
$env = getenv('OPENAI_API_KEY') ?: '';
echo "OPENAI_API_KEY set : " . ($env !== '' ? 'YES (' . strlen($env) . ' chars)' : 'NO') . "\n";

echo "\n-- 6. get_openai_api_key() result --\n";
$result = get_openai_api_key();
echo "Returns empty : " . ($result === '' ? 'YES — this is the bug!' : 'NO') . "\n";
echo "Key length    : " . strlen($result) . " chars\n";
if ($result !== '') {
    echo "Preview       : " . substr($result,0,4).'***'.substr($result,-3) . "\n";
}

echo "\n=== Done. DELETE THIS FILE NOW! ===\n";
