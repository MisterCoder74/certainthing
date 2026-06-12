<?php
require_once __DIR__ . '/../api/config.php';

define('RESET_TOKENS_DIR', DATA_DIR . '/reset_tokens');
define('RESET_TOKEN_EXPIRY', 3600); // 1 ora

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../login.php');
    exit;
}

$email = trim($_POST['email'] ?? '');

if (empty($email)) {
    header('Location: ../forgot_password.php?error=' . urlencode('Please enter your email'));
    exit;
}

$users = safe_read_json(USERS_FILE);
$found_user = null;

foreach ($users as $user) {
    if ($user['email'] === $email) {
        $found_user = $user;
        break;
    }
}

// Sempre mostra successo (non rivelare se l'email esiste)
if (!$found_user) {
    header('Location: ../forgot_password.php?success=1');
    exit;
}

// Genera token
$token = bin2hex(random_bytes(32));
$token_data = [
    'user_id'    => $found_user['id'],
    'email'      => $found_user['email'],
    'created_at' => time(),
    'expires_at' => time() + RESET_TOKEN_EXPIRY,
];

// Salva token su file
if (!is_dir(RESET_TOKENS_DIR)) {
    mkdir(RESET_TOKENS_DIR, 0700, true);
}
file_put_contents(RESET_TOKENS_DIR . '/' . $token . '.json', json_encode($token_data));

// Invia email
$reset_url = BASE_URL . '/reset_password.php?token=' . $token;

$headers  = "From: certainthing_noreply@vivacitydesign.net\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";
$headers .= "MIME-Version: 1.0\r\n";

$body = "
<div style='font-family: -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif;
            max-width: 500px; padding: 24px; background: #0d1117; color: #c9d1d9;
            border-radius: 10px; border: 1px solid #30363d;'>
    <h2 style='color: #58a6ff; margin-top: 0;'>✦ CertainThing — Password Reset</h2>
    <p>You requested a password reset. Click the button below to set a new password:</p>
    <p style='text-align: center; margin: 24px 0;'>
        <a href='{$reset_url}'
           style='background: #238636; color: #fff; padding: 12px 28px;
                  border-radius: 6px; text-decoration: none; font-weight: 600;
                  display: inline-block;'>
            Reset Password
        </a>
    </p>
    <p style='color: #8b949e; font-size: 0.85rem;'>This link expires in 1 hour. If you didn't request this, ignore this email.</p>
</div>";

@mail($email, 'CertainThing - Password Reset', $body, $headers);

header('Location: ../forgot_password.php?success=1');
exit;
