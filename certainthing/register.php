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
    <title>Register - CertainThing</title>
    <link rel="stylesheet" href="assets/style.css?v=<?php echo time(); ?>">
</head>
<body class="auth-page">
    <div class="auth-container">
        <h1><span class="icon">✦</span> CertainThing</h1>
        <p class="tagline">The vibe coder assistant</p>
        
        <form action="auth/register_handler.php" method="POST" class="auth-form">
            <?php if (isset($_GET['error'])): ?>
                <div class="error-msg"><?php echo htmlspecialchars($_GET['error']); ?></div>
            <?php endif; ?>
            
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required autocomplete="off">
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="new-password">
            </div>
            
            <button type="submit" class="btn-primary">Register</button>
        </form>
        
        <p class="auth-switch">Already have an account? <a href="login.php">Login</a></p>
    </div>
    
    <footer>
        <p>&copy; <?php echo date('Y'); ?> <span class="icon">✦</span> CertainThing - by Vivacity Design AI Division</p>
    </footer>
</body>
</html>
