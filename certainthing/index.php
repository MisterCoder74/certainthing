<?php
require_once __DIR__ . '/api/config.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CertainThing - AI Vibe Coder</title>
    <!-- Highlight.js for code rendering -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
    <link rel="stylesheet" href="assets/style.css?v=<?php echo time(); ?>">
</head>
<body>
    <div id="app">
        <!-- Top Navigation -->
        <header class="main-header">
            <div class="logo">
                <span class="icon">✦</span> CertainThing
            </div>
            <div class="user-menu">
                <span class="user-email"><?php echo htmlspecialchars($_SESSION['user_email']); ?></span>
                <a href="auth/logout.php" class="logout-btn">Logout</a>
            </div>
        </header>

        <main class="split-container">
            <!-- Left Pane: Conversation -->
            <section class="pane left-pane" id="chat-pane">
                <div class="pane-header">
                    <h2>Conversation</h2>
                    <button id="new-chat-btn" class="btn-small">New Chat</button>
                </div>
                <div class="messages-container" id="messages-container">
                    <!-- Messages will appear here -->
                    <div class="message assistant">
                        <div class="bubble">
                            Hello! I'm CertainThing. I can help you build web projects. What are we building today?
                        </div>
                    </div>
                </div>
                <div class="input-area">
                    <div id="attachment-preview" class="attachment-preview"></div>
                    <form id="chat-form">
                        <div class="input-wrapper">
                            <input type="file" id="file-input" multiple style="display: none;">
                            <textarea id="chat-input" placeholder="Describe what you want to build..." rows="1"></textarea>
                            <div class="input-actions">
                                <button type="button" id="attach-btn" title="Attach file">📎</button>
                                <button type="submit" id="send-btn">Send</button>
                            </div>
                        </div>
                    </form>
                </div>
            </section>

            <!-- Right Pane: Reasoning/Processing -->
            <section class="pane right-pane" id="reasoning-pane">
                <div class="pane-header">
                    <h2>Reasoning / Processing</h2>
                    <div id="status-badge" class="status-badge idle">Idle</div>
                </div>
                <div class="reasoning-container" id="reasoning-container">
                    <!-- Reasoning steps will appear here -->
                </div>
            </section>
        </main>

        <!-- Toast Notifications -->
        <div id="toast-container"></div>

        <footer class="main-footer">
            &copy; <?php echo date('Y'); ?> CertainThing AI Assistant. All rights reserved.
        </footer>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
    <script src="assets/app.js?v=<?php echo time(); ?>"></script>
</body>
</html>
