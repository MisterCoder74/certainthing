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
            <button id="sidebar-toggle" class="sidebar-toggle-btn" title="Toggle sessions">
                <span class="hamburger-icon">☰</span>
            </button>
            <div class="logo">
                <span class="icon">✦</span> CertainThing
            </div>
            <div class="header-actions">
                <button id="reasoning-toggle-header" class="btn-small" title="Toggle reasoning pane">🧠</button>
                <div class="user-menu">
                    <span class="user-email"><?php echo htmlspecialchars($_SESSION['user_email']); ?></span>
                    <a href="auth/logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
        </header>

        <div class="app-layout">
            <!-- Session Sidebar -->
            <aside class="sidebar" id="session-sidebar">
                <div class="sidebar-header">
                    <h3>Sessions</h3>
                    <button id="new-chat-sidebar-btn" class="btn-small" title="New Chat">+ New</button>
                </div>
                <div class="sidebar-search">
                    <input type="text" id="session-search" placeholder="Search sessions..." />
                </div>
                <div class="sidebar-list" id="session-list">
                    <!-- Sessions will be loaded here -->
                    <div class="sidebar-loading">Loading sessions...</div>
                </div>
                <div class="sidebar-footer">
                    <span class="session-count" id="session-count">0 sessions</span>
                </div>
            </aside>
            <div id="sidebar-overlay" class="sidebar-overlay"></div>

            <main class="split-container">
                <!-- Left Pane: Conversation -->
                <section class="pane left-pane" id="chat-pane">
                    <div class="pane-header">
                        <h2>Conversation</h2>
                        <div class="pane-header-actions">
                            <button id="new-chat-btn" class="btn-small">New Chat</button>
                            <button id="deploy-btn" class="btn-small deploy-btn" title="Deploy latest code (F10)">🚀 Deploy</button>
                        </div>
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
                        <div class="pane-tabs">
                            <button class="pane-tab-btn active" data-tab="reasoning">Reasoning</button>
                            <button class="pane-tab-btn" data-tab="preview">Live Preview</button>
                        </div>
                        <div class="pane-header-actions">
                            <div id="status-badge" class="status-badge idle">Idle</div>
                            <button id="reasoning-toggle-pane" class="btn-small" title="Close reasoning pane">✕</button>
                        </div>
                    </div>
                    <div class="reasoning-container tab-content active" id="reasoning-container">
                        <!-- Reasoning steps will appear here -->
                    </div>
                    <div class="preview-container tab-content" id="preview-container">
                        <div class="preview-toolbar">
                            <button id="refresh-preview-btn" class="btn-small">Refresh</button>
                            <span class="preview-url">sandbox://index.html</span>
                        </div>
                        <iframe id="preview-iframe" sandbox="allow-scripts allow-modals"></iframe>
                    </div>
                </section>
            </main>
        </div>

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
