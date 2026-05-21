<?php
require_once __DIR__ . '/api/config.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Process account control for active sessions (check expiry, send reminders)
$current_user = process_account_control($_SESSION['user_id']);
if ($current_user === null || ($current_user['status'] ?? 'enabled') !== 'enabled') {
    session_destroy();
    header('Location: login.php?error=Account disabled');
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
                    <button id="api-key-btn" class="btn-small" type="button" title="Manage OpenAI API key">🔑 API Key</button>
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
                        <div class="message-spinner" id="message-spinner" aria-live="polite" aria-hidden="true">
                            <span class="message-spinner-dot"></span>
                            <span class="message-spinner-dot"></span>
                            <span class="message-spinner-dot"></span>
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
                                    <button type="button" id="stop-btn" class="stop-btn" title="Stop generation">Stop</button>
                                    <button type="submit" id="send-btn">Send</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </section>

                <!-- Right Pane: Reasoning/Processing -->
                <section class="pane right-pane" id="reasoning-pane">
                    <div class="pane-header">
                        <div class="pane-title">Reasoning & Preview</div>
                        <div class="pane-header-actions">
                            <div id="status-badge" class="status-badge idle">Idle</div>
                            <button id="reasoning-toggle-pane" class="btn-small" title="Close reasoning pane">✕</button>
                        </div>
                    </div>
                    <div class="reasoning-container" id="reasoning-container">
                        <!-- Reasoning steps will appear here -->
                    </div>
                    <div class="preview-container" id="preview-container">
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

        <div id="api-key-modal" class="modal-overlay api-key-modal hidden" role="dialog" aria-modal="true" aria-labelledby="api-key-modal-title">
            <div class="modal-content">
                <h3 id="api-key-modal-title">OpenAI API Key</h3>
                <p class="api-key-help">Store your key securely on the server for chat requests.</p>
                <div class="form-group">
                    <label for="openai-api-key-input">API Key</label>
                    <input type="password" id="openai-api-key-input" placeholder="sk-..." autocomplete="off">
                </div>
                <div class="api-key-status" id="api-key-status"></div>
                <div class="modal-footer">
                    <button class="btn-small" type="button" id="api-key-cancel">Cancel</button>
                    <button class="btn-primary" type="button" id="api-key-save">Save Key</button>
                </div>
            </div>
        </div>

        <footer class="main-footer">
            &copy; <?php echo date('Y'); ?> CertainThing AI Assistant. All rights reserved.
        </footer>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
    <script src="assets/app.js?v=<?php echo time(); ?>"></script>
</body>
</html>
