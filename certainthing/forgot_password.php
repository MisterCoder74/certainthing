<?php
require_once __DIR__ . '/api/config.php';
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - CertainThing</title>
    <link rel="stylesheet" href="assets/style.css?v=<?php echo time(); ?>">
</head>
<body class="auth-page">
    <div class="auth-container">
        <h1><span class="icon">✦</span> CertainThing</h1>
        <p class="tagline">Reset your password</p>

        <form action="auth/forgot_handler.php" method="POST" class="auth-form">
            <?php if (isset($_GET['error'])): ?>
                <div class="error-msg"><?php echo htmlspecialchars($_GET['error']); ?></div>
            <?php endif; ?>

            <?php if (isset($_GET['success'])): ?>
                <div class="success-msg" style="background: rgba(34,197,94,0.1); border: 1px solid #22c55e; color: #22c55e; padding: 12px; border-radius: 6px; margin-bottom: 16px; font-size: 0.9rem;">
                    If this email is registered, you will receive a reset link shortly. Check your inbox (and spam folder).
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required autocomplete="off"
                       placeholder="Enter your registered email">
            </div>

            <button type="submit" class="btn-primary">Send Reset Link</button>
        </form>

        <p class="auth-switch"><a href="login.php">← Back to Login</a></p>
    </div>

    <footer>
        <p>&copy; <?php echo date('Y'); ?> <span class="icon">✦</span> CertainThing - by Vivacity Design AI Division</p>
    </footer>
</body>
</html>
