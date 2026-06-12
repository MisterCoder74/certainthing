<?php
require_once __DIR__ . '/api/config.php';
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$token = $_GET['token'] ?? '';
if (empty($token)) {
    header('Location: forgot_password.php?error=' . urlencode('Invalid link'));
    exit;
}

// Verifica che il token esista e non sia scaduto
$token_file = __DIR__ . '/data/reset_tokens/' . basename($token) . '.json';
if (!file_exists($token_file)) {
    header('Location: forgot_password.php?error=' . urlencode('Invalid or expired link. Please try again.'));
    exit;
}
$token_data = json_decode(file_get_contents($token_file), true);
if (!$token_data || time() > ($token_data['expires_at'] ?? 0)) {
    @unlink($token_file);
    header('Location: forgot_password.php?error=' . urlencode('This link has expired. Please request a new one.'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - CertainThing</title>
    <link rel="stylesheet" href="assets/style.css?v=<?php echo time(); ?>">
</head>
<body class="auth-page">
    <div class="auth-container">
        <h1><span class="icon">✦</span> CertainThing</h1>
        <p class="tagline">Set your new password</p>

        <form action="auth/reset_handler.php" method="POST" class="auth-form">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

            <?php if (isset($_GET['error'])): ?>
                <div class="error-msg"><?php echo htmlspecialchars($_GET['error']); ?></div>
            <?php endif; ?>

            <div class="form-group">
                <label for="password">New Password</label>
                <input type="password" id="password" name="password" required
                       minlength="8" placeholder="At least 8 characters">
            </div>

            <div class="form-group">
                <label for="password_confirm">Confirm Password</label>
                <input type="password" id="password_confirm" name="password_confirm" required
                       minlength="8">
            </div>

            <button type="submit" class="btn-primary">Reset Password</button>
        </form>

        <p class="auth-switch"><a href="login.php">← Back to Login</a></p>
    </div>

    <footer>
        <p>&copy; <?php echo date('Y'); ?> <span class="icon">✦</span> CertainThing - by Vivacity Design AI Division</p>
    </footer>
</body>
</html>
