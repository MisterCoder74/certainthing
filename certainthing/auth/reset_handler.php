<?php
require_once __DIR__ . '/../api/config.php';

define('RESET_TOKENS_DIR', DATA_DIR . '/reset_tokens');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../login.php');
    exit;
}

$token       = $_POST['token'] ?? '';
$password    = $_POST['password'] ?? '';
$password2   = $_POST['password_confirm'] ?? '';

// Validazioni
if (empty($token) || empty($password)) {
    header('Location: ../reset_password.php?token=' . urlencode($token) . '&error=' . urlencode('All fields are required'));
    exit;
}

if (strlen($password) < 8) {
    header('Location: ../reset_password.php?token=' . urlencode($token) . '&error=' . urlencode('Password must be at least 8 characters'));
    exit;
}

if ($password !== $password2) {
    header('Location: ../reset_password.php?token=' . urlencode($token) . '&error=' . urlencode('Passwords do not match'));
    exit;
}

// Verifica token
$token_file = RESET_TOKENS_DIR . '/' . basename($token) . '.json';
if (!file_exists($token_file)) {
    header('Location: ../forgot_password.php?error=' . urlencode('Invalid or expired link. Please try again.'));
    exit;
}

$token_data = json_decode(file_get_contents($token_file), true);

if (!$token_data || time() > ($token_data['expires_at'] ?? 0)) {
    @unlink($token_file);
    header('Location: ../forgot_password.php?error=' . urlencode('This link has expired. Please request a new one.'));
    exit;
}

// Aggiorna password nel JSON
$users = safe_read_json(USERS_FILE);
$updated = false;

foreach ($users as &$user) {
    if ($user['id'] === $token_data['user_id']) {
        $user['password_hash'] = password_hash($password, PASSWORD_BCRYPT);
        $updated = true;
        break;
    }
}
unset($user);

if ($updated) {
    safe_write_json(USERS_FILE, $users);
}

// Elimina token usato
@unlink($token_file);

header('Location: ../login.php?error=' . urlencode('Password updated successfully. Please login.'));
exit;
