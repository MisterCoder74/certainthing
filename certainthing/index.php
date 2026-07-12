<?php
require_once __DIR__ . '/api/config.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// ── TRIAL CHECK ───────────────────────────────────────────────
$trialEndingSoon = $_SESSION['trial_ending_soon'] ?? false;
$trialDaysLeft   = $_SESSION['trial_days_left']   ?? 0;
// ── FINE TRIAL CHECK ─────────────────────────────────────────────────────

// ── PAID CHECK ────────────────────────────────────────────────────────────
$paidEndingSoon = false;
$paidDaysLeft   = 0;
$paidExpired    = false;

if (
    ($_SESSION['user_mode']   ?? '') === 'paid' &&
    ($_SESSION['user_status'] ?? '') === 'enabled' &&
    !empty($_SESSION['last_payment_at'])
){
    $lastPayment  = new DateTime($_SESSION['last_payment_at']);
    $today        = new DateTime('today');
    $expiryDate   = (clone $lastPayment)->modify('+1 month'); // calendar month, handles Feb/short months correctly
    $paidExpired  = ($today >= $expiryDate);
    $paidDaysLeft = $paidExpired ? 0 : (int) $today->diff($expiryDate)->days;

    if (!$paidExpired && $paidDaysLeft <= 5) $paidEndingSoon = true;
}
// ── FINE PAID CHECK ───────────────────────────────────────────────────────

// ── STATUS DOT (gestisce trial + paid) ────────────────────────────────────
$statusDot  = null;
$dotTitle   = '';
$userMode   = $_SESSION['user_mode']   ?? '';
$userStatus = $_SESSION['user_status'] ?? '';

if ($userMode === 'trial' && $userStatus === 'enabled') {
    $created  = new DateTime($_SESSION['user_created']);
    $today    = new DateTime('today');
    $diffDays = (int) $today->diff($created)->days;
    $dotTitle = "Trial: day {$diffDays}/7";

    if ($diffDays < 4)                        $statusDot = 'green';
    elseif ($diffDays >= 4 && $diffDays <= 6) $statusDot = 'orange';
    else                                      $statusDot = 'red';

} elseif ($userMode === 'paid' && $userStatus === 'enabled') {
    if (!empty($_SESSION['last_payment_at'])) {
        $dotTitle = "Paid Plan: {$paidDaysLeft} days left";

        if ($paidExpired)           $statusDot = 'red';
        elseif ($paidDaysLeft <= 5) $statusDot = 'orange';
        else                        $statusDot = 'green';
    } else {
        $statusDot = 'green';
        $dotTitle  = 'Plan active';
    }
}
// ── FINE STATUS DOT ───────────────────────────────────────────────────────

// ── USER INFO POPUP DATA ──────────────────────────────────────────────────
$userInfo = [
    'email'        => $_SESSION['user_email'] ?? '',
    'account_type' => ucfirst($userMode ?: 'unknown'),
    'status'       => ucfirst($userStatus ?: 'unknown'),
    'registered'   => $_SESSION['user_created'] ?? '—',
    'mode'         => $userMode === 'trial' ? 'Trial' : ($userMode === 'paid' ? 'Paid' : '—'),
    'days_left'    => '—',
    'last_payment' => $_SESSION['last_payment_at'],    
];

if ($userMode === 'trial' && !empty($_SESSION['user_created'])) {
    $created  = new DateTime($_SESSION['user_created']);
    $today    = new DateTime('today');
    $diffDays = (int) $today->diff($created)->days;
    $userInfo['days_left'] = max(0, 7 - $diffDays) . ' days (trial)';
} elseif ($userMode === 'paid' && !empty($_SESSION['last_payment_at'])) {
$userInfo['days_left'] = $paidDaysLeft . ' days (subscription)';
}
// ── FINE USER INFO POPUP DATA ─────────────────────────────────────────────


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>✦ CertainThing - AI Vibe Coder</title>
    <link rel="icon" type="image/x-icon" href="icons8-codice-48.png">    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
    <link rel="stylesheet" href="assets/style.css?v=<?php echo time(); ?>">
        
    <style>
/* ── Paid/Trial Banner variants ─────────────────────────────────────────── */
.trial-banner {
    background: #fff8e1;
    border-bottom: 1px solid #f59e0b;
    border-left: 4px solid #f59e0b;
    color: #92400e;
    padding: 9px 20px;
    font-size: 0.875rem;
    display: flex;
    align-items: center;
    gap: 6px;
}
.trial-banner a {
    margin-left: 10px;
    font-weight: 600;
    color: #b45309;
    text-decoration: none;
}
.trial-banner a:hover { text-decoration: underline; }

/* Variante arancione (rinnovo imminente) */
.trial-banner--orange {
    background: #fff3e0;
    border-color: #f97316;
    border-left-color: #f97316;
    color: #7c2d12;
}
.trial-banner--orange a { color: #c2410c; }

/* Variante rossa (scaduto) */
.trial-banner--red {
    background: #fef2f2;
    border-color: #ef4444;
    border-left-color: #ef4444;
    color: #7f1d1d;
}
.trial-banner--red a { color: #b91c1c; }

/* ── Status Dot ─────────────────────────────────────────────────────────── */
.status-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: 5px;
    vertical-align: middle;
    flex-shrink: 0;
}
.status-dot.green  { background: #22c55e; box-shadow: 0 0 4px #22c55e88; }
.status-dot.orange { background: #f97316; box-shadow: 0 0 4px #f9731688; }
.status-dot.red    { background: #ef4444; box-shadow: 0 0 4px #ef444488; }

#attach-btn, #promptLibraryBtn, #stop-btn, #send-btn {
background:none;
border:none;
color:#58a6ff;
font-size:18px;
cursor:pointer;
padding:4px 6px;
margin-right:2px;
}   
            
            

            
@media screen and (max-width: 432px) {

        #attach-btn, #promptLibraryBtn, #stop-btn  {
background:none;
border:none;
color:#58a6ff;
font-size:14px;
cursor:pointer;
padding:4px;
margin-right:1px;
} 
#send-btn, #api-key-btn {
background:none;
border:none;
color:#58a6ff;
font-size:12px;
cursor:pointer;
padding:4px;
margin-right:1px;        
}        
.logout-btn a{
font-size:12px;
}        
.right-pane {
display: none;
        }        
}            
            
    </style>
</head>
<body data-mode="<?= htmlspecialchars($userMode) ?>">
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
                <!-- ── STATUS DOT nell'header: aggiorna la riga user-email ─────────────────── -->

                <div class="user-info-wrapper">
                    <span class="user-email">
                        <?php if ($statusDot): ?>
                            <span class="status-dot <?= $statusDot ?>" title="<?= htmlspecialchars($dotTitle) ?>"></span>
                        <?php endif; ?>
                        <?= htmlspecialchars($_SESSION['user_email']) ?>
                    </span>
                    <button class="user-info-toggle" onclick="document.getElementById('userInfoPopup').classList.toggle('show')" title="Account info">
                        &#9881;
                    </button>
                    <div class="user-info-popup" id="userInfoPopup">
                        <div class="user-info-row"><span class="user-info-label">Email</span><span><?= htmlspecialchars($userInfo['email']) ?></span></div>
                        <div class="user-info-row"><span class="user-info-label">Account</span><span><?= $userInfo['account_type'] ?></span></div>
                        <div class="user-info-row"><span class="user-info-label">Status</span><span class="status-value <?= $statusDot ?>"><?= $userInfo['status'] ?></span></div>
                        <div class="user-info-row"><span class="user-info-label">Registered</span><span><?= htmlspecialchars(date('d M Y', strtotime($userInfo['registered']))) ?></span></div>
                        <div class="user-info-row"><span class="user-info-label">Mode</span><span><?= $userInfo['mode'] ?></span></div>
                        <div class="user-info-row"><span class="user-info-label">Expires in</span><span><?= $userInfo['days_left'] ?></span></div>
                        <div class="user-info-row"><span class="user-info-label">Last Payment</span><span><?= $userInfo['last_payment'] ?></span></div>    
                    </div>
                </div>

                <!-- ── FINE STATUS DOT ─────────────────────────────────────────────────────── -->

                    <button id="api-key-btn" class="btn-small" type="button" title="Setup: API key, GitHub, model, voice">⚙ Setup</button>
                    <a href="auth/logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
        </header>

                <!-- ── TRIAL BANNER (invariato) ──────────────────────────────────────────── -->
                <?php if ($trialEndingSoon): ?>
                <div class="trial-banner">
                    ⚠️ Your trial period expires in
                    <strong><?= $trialDaysLeft ?> day<?= $trialDaysLeft > 1 ? 's' : '' ?></strong>.
                    <a href="upgrade.php">Upgrade to Paid Plan →</a>
                </div>
                <?php endif; ?>
                <!-- ── FINE TRIAL BANNER ───────────────────────────────────────────────────── -->

                <!-- ── PAID BANNER ─────────────────────────────────────────────────────────── -->
                <?php if ($paidExpired): ?>
                <div class="trial-banner trial-banner--red">
                    🔴 Your subscription has expired.
                    <a href="upgrade.php">Renew it now →</a>
                </div>
                <?php elseif ($paidEndingSoon): ?>
                <div class="trial-banner trial-banner--orange">
                    ⚠️ Your subscription renews in
                    <strong><?= $paidDaysLeft ?> day<?= $paidDaysLeft > 1 ? 's' : '' ?></strong>.
                    <a href="upgrade.php">Manage Subscription →</a>
                </div>
                <?php endif; ?>
                <!-- ── FINE PAID BANNER ────────────────────────────────────────────────────── -->

        <div class="app-layout">
            <!-- Session Sidebar -->
            <aside class="sidebar" id="session-sidebar">
                <div class="sidebar-header">
                    <h3>Sessions</h3>
                    <button id="new-chat-sidebar-btn" class="btn-small" title="New Chat">+ New</button>
                </div>
                <div class="sidebar-search">
                    <!-- Dummy input fields to trick Chrome's Password manager -->
                        <input type="text" name="fake_user" style="display:none" aria-hidden="true">
                        <input type="password" name="fake_pass" style="display:none" aria-hidden="true">
    
                    <input type="search" id="session-search" placeholder="Search sessions..." autocomplete="off">
                </div>
                <div class="sidebar-list" id="session-list">
                    <div class="sidebar-loading">Loading sessions...</div>
                </div>
                <div class="sidebar-footer">
                    <span class="session-count" id="session-count">0 sessions</span>
                </div>
                    
                <!-- ── Deployments Section ─────────────────────────────── -->
                <div class="sidebar-divider"></div>
                <div class="sidebar-header">
                    <h3>Deployments</h3>
                    <a href="deploy/deploy_manager.php" class="btn-small" title="Open Deploy Manager">Manage</a>
                </div>
                <div class="sidebar-list" id="deploy-list" style="max-height:200px;overflow-y:auto;">
                    <?php
                    $deployUserDir = __DIR__ . '/deploy/' . ($_SESSION['user_id'] ?? '');
                    $deploys = [];
                    if (is_dir($deployUserDir)) {
                        $dirs = scandir($deployUserDir);
                        foreach ($dirs as $d) {
                            if ($d === '.' || $d === '..' || !is_dir($deployUserDir . '/' . $d)) continue;
                            $deploys[] = [
                                'name' => $d,
                                'time' => filemtime($deployUserDir . '/' . $d),
                            ];
                        }
                        // Most recent first
                        usort($deploys, fn($a, $b) => $b['time'] - $a['time']);
                    }
                    if (empty($deploys)): ?>
                        <div class="sidebar-empty" style="padding:12px;color:#484f58;font-size:0.8rem;text-align:center;">
                            No deployments yet
                        </div>
                    <?php else:
                        foreach ($deploys as $dep): ?>
                        <div class="session-item" style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;">
                            <div style="overflow:hidden;">
                                <div style="font-size:0.8rem;color:#c9d1d9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                    📁 <?= htmlspecialchars($dep['name']) ?>
                                </div>
                                <div style="font-size:0.7rem;color:#484f58;">
                                    <?= date('M j, Y H:i', $dep['time']) ?>
                                </div>
                            </div>
                            <a href="deploy/deploy_manager.php?path=<?= urlencode($dep['name']) ?>"
                               class="btn-small" style="font-size:0.7rem;padding:3px 8px;flex-shrink:0;"
                               title="Open in Deploy Manager">Open</a>
                        </div>
                    <?php endforeach;
                    endif; ?>
                </div>
                <!-- ── End Deployments Section ─────────────────────────── -->
                   
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
                                    <button type="button" id="promptLibraryBtn" title="Prompt Library">⚡</button>
                                    <button type="button" id="voice-btn" title="Voice input">🎙️</button>
                                    <button type="button" id="stop-btn" class="stop-btn" title="Stop generation">⏹</button>
                                    <button type="submit" id="send-btn" title="Send">➤</button>
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
                    <div class="reasoning-container" id="reasoning-container"></div>
                    <div class="preview-container" id="preview-container">
                        <div class="preview-toolbar">
                            <button id="refresh-preview-btn" class="btn-small">Refresh</button>
                            <span class="preview-url">sandbox://index.html</span>
                        </div>
                        <iframe id="preview-iframe" sandbox="allow-scripts allow-modals allow-same-origin"></iframe>
                    </div>
                </section>
            </main>
        </div>
        <!-- Toast Notifications -->
        <div id="toast-container"></div>
        <div id="api-key-modal" class="modal-overlay api-key-modal hidden" role="dialog" aria-modal="true" aria-labelledby="api-key-modal-title">
            <div class="modal-content">
                <h3 id="api-key-modal-title">Setup</h3>

                <div class="setup-tabs">
                    <button type="button" class="setup-tab active" data-tab="account">API Key</button>
                    <button type="button" class="setup-tab" data-tab="github">GitHub</button>
                    <button type="button" class="setup-tab" data-tab="model">Model</button>
                    <button type="button" class="setup-tab" data-tab="voice">Voice</button>
                </div>

                <div class="setup-section" data-section="account">
                    <p class="api-key-help">Store your key securely on the server for chat requests.</p>
                    <div class="form-group">
                        <label for="openai-api-key-input">API Key</label>
                        <input type="password" id="openai-api-key-input" placeholder="sk-..." autocomplete="new-password">
                    </div>
                    <div class="api-key-status" id="api-key-status"></div>
                    <div id="key-status-info" class="key-status-box"></div>
                     <div id="key-shared-warning" class="key-warning-box">
                     ⚠️ NOTE: If you are using the fallback <strong>Shared Key</strong>,
                     you will need to enter your Personal API Key after evaluation expires.
                     </div>
                    <div class="modal-footer">
                        <button class="btn-small" type="button" id="api-key-cancel">Cancel</button>
                        <button class="btn-primary" type="button" id="api-key-save">Save Key</button>
                    </div>
                </div>

                <div class="setup-section" data-section="github" hidden>
                    <p class="api-key-help">Used by "Push to GitHub" — stored server-side, never in the browser.</p>
                    <div class="form-group">
                        <label for="setup-gh-repo">Repository (user/repo)</label>
                        <input type="text" id="setup-gh-repo" placeholder="e.g. octocat/hello-world">
                    </div>
                    <div class="form-group">
                        <label for="setup-gh-pat">Personal Access Token</label>
                        <input type="password" id="setup-gh-pat" placeholder="ghp_xxxxxxxxxxxx" autocomplete="new-password">
                    </div>
                    <div class="api-key-status" id="setup-gh-status"></div>
                    <div class="modal-footer">
                        <button class="btn-small" type="button" id="api-key-cancel-2">Cancel</button>
                        <button class="btn-primary" type="button" id="setup-gh-save">Save GitHub</button>
                    </div>
                </div>

                <div class="setup-section" data-section="model" hidden>
                    <p class="api-key-help">Available on the Paid plan. Trial/free accounts always use gpt-5-nano.</p>
                    <div class="form-group">
                        <label for="setup-model-select">AI Model</label>
                        <select id="setup-model-select">
                            <option value="gpt-5-nano">gpt-5-nano</option>
                            <option value="gpt-5.4-nano">gpt-5.4-nano</option>
                        </select>
                    </div>
                    <div class="api-key-status" id="setup-model-status"></div>
                </div>

                <div class="setup-section" data-section="voice" hidden>
                    <p class="api-key-help">Language used by voice input (🎙️). Saved automatically.</p>
                    <div class="form-group">
                        <label for="voice-language">Speech recognition language</label>
                        <select id="voice-language">
                            <option value="">Auto</option>
                            <option value="it-IT">IT</option>
                            <option value="en-US">EN</option>
                            <option value="es-ES">ES</option>
                            <option value="fr-FR">FR</option>
                            <option value="de-DE">DE</option>
                            <option value="pt-PT">PT</option>
                            <option value="ro-RO">RO</option>
                            <option value="ru-RU">RU</option>
                            <option value="pl-PL">PL</option>
                        </select>
                    </div>
                    <div class="api-key-status" id="setup-voice-status"></div>
                </div>
            </div>
        </div>
        <footer class="main-footer">
            &copy; <?php echo date('Y'); ?> ✦ CertainThing - by Vivacity Design AI Division. All rights reserved.
        </footer>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
    <script src="assets/app.js?v=<?php echo time(); ?>"></script>
</body>
</html>