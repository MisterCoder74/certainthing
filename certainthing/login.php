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
    <title>Login - CertainThing</title>
    <link rel="stylesheet" href="assets/style.css?v=<?php echo time(); ?>">
</head>
<body class="auth-page">
    <div class="auth-container">
        <h1><span class="icon">✦</span> CertainThing</h1>
        <p class="tagline">The vibe coder assistant</p>
        
        <form action="auth/login_handler.php" method="POST" class="auth-form">
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
            
            <button type="submit" class="btn-primary">Login</button>
        </form>
        <p class="auth-switch"><a href="forgot_password.php">Forgotten password?</a></p>
        <p class="auth-switch">Don't have an account? <a href="register.php">Register</a></p>
            <p class="auth-switch"><a href="doc_en.html" target="_blank">Read Documentation</a></p>
    </div>
    
    <footer>
        <p>&copy; <?php echo date('Y'); ?> <span class="icon">✦</span> CertainThing - by Vivacity Design AI Division</p>
        <p><a href="tos.html" target="_blank" style="text-decoration: none; color: 	#808080;">Terms of Services </a> - <a href="privacy.html" target="_blank" style="text-decoration: none; color: 	#808080"> Privacy Policy</a></p>     
    </footer>
        
<!-- ============================================
     COOKIE NOTICE BANNER — paste before </body> in login.php
     ============================================ -->

<div id="cookieNotice" style="display:none;">
  <div class="cookie-overlay"></div>
  <div class="cookie-modal">
    <div class="cookie-icon">🍪</div>
    <h3>Cookie Notice</h3>
    <p>
      This site uses a single <strong>technical session cookie</strong> (<code>PHPSESSID</code>) 
      that is strictly necessary to keep you logged in while using the Service.
    </p>
    <p>
      We do <strong>not</strong> use any analytics, advertising, or third-party tracking cookies.
    </p>
    <p class="cookie-link">
      For more details, see our <a href="privacy.html" target="_blank">Privacy Policy</a> (Section 6).
    </p>
    <button id="cookieAccept" class="cookie-btn">Got it</button>
  </div>
</div>

<style>
  /* Cookie Notice — overlay + centered modal */
  .cookie-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.6);
    z-index: 9998;
  }

  .cookie-modal {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    z-index: 9999;
    background: #161b22;
    border: 1px solid #30363d;
    border-radius: 12px;
    padding: 2rem 2.5rem;
    max-width: 480px;
    width: 90%;
    text-align: center;
    color: #c9d1d9;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
    animation: cookieFadeIn 0.3s ease;
  }

  @keyframes cookieFadeIn {
    from { opacity: 0; transform: translate(-50%, -48%); }
    to   { opacity: 1; transform: translate(-50%, -50%); }
  }

  .cookie-icon {
    font-size: 2.5rem;
    margin-bottom: 0.8rem;
  }

  .cookie-modal h3 {
    color: #58a6ff;
    font-size: 1.3rem;
    margin-bottom: 1rem;
  }

  .cookie-modal p {
    font-size: 0.95rem;
    line-height: 1.6;
    margin-bottom: 0.8rem;
    color: #c9d1d9;
  }

  .cookie-modal code {
    background: rgba(255, 255, 255, 0.08);
    padding: 0.1rem 0.35rem;
    border-radius: 4px;
    font-size: 0.9em;
  }

  .cookie-modal strong {
    color: #ffffff;
  }

  .cookie-link {
    font-size: 0.85rem !important;
    color: #8b949e !important;
  }

  .cookie-link a {
    color: #58a6ff;
    text-decoration: none;
  }

  .cookie-link a:hover {
    text-decoration: underline;
  }

  .cookie-btn {
    margin-top: 1rem;
    background: #238636;
    color: #ffffff;
    border: none;
    padding: 0.65rem 2rem;
    border-radius: 6px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
  }

  .cookie-btn:hover {
    background: #2ea043;
  }
</style>

<script>
  (function () {
    var COOKIE_KEY = 'ct_cookie_notice';
    var notice = document.getElementById('cookieNotice');
    var btn    = document.getElementById('cookieAccept');

    // Show only if not previously acknowledged
    if (!localStorage.getItem(COOKIE_KEY)) {
      notice.style.display = 'block';
    }

    btn.addEventListener('click', function () {
      localStorage.setItem(COOKIE_KEY, '1');
      notice.style.display = 'none';
    });
  })();
</script>        
        
</body>
</html>
