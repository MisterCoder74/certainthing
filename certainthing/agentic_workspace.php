<?php
/**
 * Agentic Workspace — Phase A (upload/stage/confirm) + Phase B (file-tree UI + actions) entry page.
 * Location: ./agentic_workspace.php (top-level, sibling to index.php)
 *
 * Deliberately a SELF-CONTAINED page (own markup/CSS/JS, mirrors deploy_manager.php's
 * "one PHP file, embedded style+script" shape) rather than new code wired into the
 * existing SPA's app_canonical.js/style.css. Those two files are the biggest shared
 * assets in the app; keeping this feature on its own page means the only touch to the
 * existing UI is the single "Agentic Workspace" link added to index.php's header —
 * everything else here is new files, so there is nothing to diff-lose.
 *
 * All actions call the existing endpoints:
 *   api/agentic_workspace_upload.php  (stage / confirm / cancel / list)
 *   api/agentic_files.php             (tree / get_content / save_file / delete_item /
 *                                       rename_file / move_item / create_file /
 *                                       create_folder / download / download_zip)
 *   api/agentic_serve_test.php?harness=0  (view / "get link")
 *
 * "Vibecode it" (Phase C) is intentionally a disabled placeholder button here —
 * not wired yet.
 */
require_once __DIR__ . '/api/config.php';
check_auth();

$userEmail = $_SESSION['user_email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>✦ CertainThing – Agentic Workspace</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, sans-serif;
    background: #0d1117;
    color: #c9d1d9;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}
a { color: #58a6ff; text-decoration: none; }
a:hover { text-decoration: underline; }

.aw-header {
    background: #161b22;
    border-bottom: 1px solid #30363d;
    padding: 12px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-shrink: 0;
}
.aw-logo { font-size: 1.15rem; font-weight: 600; color: #e6edf3; display: flex; align-items: center; gap: 8px; }
.aw-logo .icon { color: #58a6ff; }
.aw-back-link {
    font-size: 0.8rem; color: #8b949e; padding: 5px 12px; border: 1px solid #30363d;
    border-radius: 6px; text-decoration: none;
}
.aw-back-link:hover { color: #58a6ff; border-color: #58a6ff; text-decoration: none; }
.aw-header-right { display: flex; align-items: center; gap: 12px; font-size: 0.85rem; color: #8b949e; }

.btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px;
    border: 1px solid #30363d; border-radius: 6px; background: #21262d; color: #c9d1d9;
    font-size: 0.8rem; cursor: pointer; transition: background 0.15s, border-color 0.15s;
    white-space: nowrap;
}
.btn:hover { background: #30363d; border-color: #484f58; }
.btn-primary { background: #238636; border-color: #2ea043; color: #fff; }
.btn-primary:hover { background: #2ea043; }
.btn-danger { background: #da3633; border-color: #f85149; color: #fff; }
.btn-danger:hover { background: #f85149; }
.btn-sm { padding: 4px 10px; font-size: 0.75rem; }
.btn:disabled, .btn[disabled] { opacity: 0.45; cursor: not-allowed; }
.btn-icon {
    padding: 4px 8px; font-size: 0.85rem; border: none; background: transparent;
    color: #8b949e; cursor: pointer; border-radius: 4px;
}
.btn-icon:hover { color: #58a6ff; background: #1c2128; }
.btn-icon.danger:hover { color: #f85149; }
.btn-icon:disabled { opacity: 0.4; cursor: not-allowed; }
.btn-icon:disabled:hover { color: #8b949e; background: transparent; }

.aw-main { flex: 1; max-width: 1100px; width: 100%; margin: 0 auto; padding: 24px; }

.aw-upload-card {
    background: #161b22; border: 1px dashed #30363d; border-radius: 10px;
    padding: 28px; text-align: center; margin-bottom: 24px;
}
.aw-upload-card p { color: #8b949e; font-size: 0.85rem; margin-bottom: 14px; }
.aw-upload-card input[type="file"] { color: #c9d1d9; font-size: 0.85rem; margin-bottom: 14px; }

.aw-section-title { color: #e6edf3; font-size: 0.95rem; font-weight: 600; margin-bottom: 12px; }

.aw-ws-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 24px; }
.aw-ws-item {
    display: flex; align-items: center; justify-content: space-between;
    background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 12px 16px;
}
.aw-ws-item .id { font-family: monospace; font-size: 0.8rem; color: #e6edf3; }
.aw-ws-item .meta { color: #8b949e; font-size: 0.75rem; }
.aw-empty { text-align: center; padding: 40px 20px; color: #484f58; }
.aw-empty .icon { font-size: 2rem; margin-bottom: 10px; }

.aw-breadcrumbs { display: flex; align-items: center; gap: 6px; font-size: 0.85rem; margin-bottom: 16px; }

.aw-toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; gap: 12px; }
.aw-toolbar-left { font-size: 0.85rem; color: #8b949e; }
.aw-toolbar-right { display: flex; gap: 8px; }

.aw-tree { font-size: 0.85rem; }
.aw-node { }
.aw-node-row {
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
    padding: 7px 10px; border-radius: 6px;
}
.aw-node-row:hover { background: #161b22; }
.aw-node-name { display: flex; align-items: center; gap: 8px; color: #e6edf3; cursor: pointer; }
.aw-node-name .ficon { flex-shrink: 0; }
.aw-node-name .fsize { color: #8b949e; font-size: 0.75rem; margin-left: 6px; }
.aw-node-children { padding-left: 22px; border-left: 1px solid #21262d; margin-left: 9px; display: none; }
.aw-node-children.open { display: block; }
.aw-node-actions { display: flex; gap: 2px; align-items: center; flex-shrink: 0; }

.aw-modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,0.65); display: flex;
    align-items: center; justify-content: center; z-index: 999; padding: 20px;
    opacity: 0; visibility: hidden; transition: opacity 0.2s, visibility 0.2s;
}
.aw-modal-overlay.active { opacity: 1; visibility: visible; }
.aw-modal {
    background: #161b22; border: 1px solid #30363d; border-radius: 10px;
    width: 100%; max-width: 800px; max-height: 90vh; display: flex; flex-direction: column;
}
.aw-modal-sm { max-width: 460px; }
.aw-modal-header {
    display: flex; align-items: center; justify-content: space-between; padding: 14px 20px;
    border-bottom: 1px solid #30363d;
}
.aw-modal-header h3 { color: #e6edf3; font-size: 0.95rem; }
.aw-modal-body { flex: 1; overflow: auto; padding: 18px 20px; }
.aw-modal-body textarea {
    width: 100%; min-height: 400px; padding: 16px; background: #0d1117; color: #c9d1d9;
    border: 1px solid #30363d; border-radius: 6px; font-family: 'SF Mono','Fira Code',monospace;
    font-size: 0.85rem; line-height: 1.6; resize: vertical; outline: none; tab-size: 4;
}
.aw-modal-body ul.aw-preview-list { list-style: none; font-family: monospace; font-size: 0.78rem; color: #8b949e; max-height: 260px; overflow: auto; }
.aw-modal-body ul.aw-preview-list li { padding: 2px 0; }
.aw-modal-footer {
    display: flex; align-items: center; justify-content: flex-end; gap: 10px;
    padding: 12px 20px; border-top: 1px solid #30363d;
}
.aw-warning {
    background: rgba(210,153,34,0.1); border: 1px solid #d2992280; color: #d29922;
    padding: 10px 12px; border-radius: 6px; font-size: 0.8rem; margin-bottom: 14px;
}

.aw-toast {
    position: fixed; bottom: 24px; right: 24px; background: #238636; color: #fff;
    padding: 10px 18px; border-radius: 8px; font-size: 0.85rem; z-index: 1200;
    opacity: 0; transform: translateY(10px); transition: opacity 0.3s, transform 0.3s; pointer-events: none;
}
.aw-toast.error { background: #da3633; }
.aw-toast.visible { opacity: 1; transform: translateY(0); pointer-events: auto; }

.aw-vibecode-banner {
    background: rgba(88,166,255,0.1); border: 1px solid #58a6ff80; color: #79c0ff;
    padding: 10px 12px; border-radius: 6px; font-size: 0.8rem; margin-bottom: 12px;
    display: none;
}
.aw-vibecode-banner.visible { display: block; }
.aw-modal-body textarea#vibecode-instruction {
    min-height: 110px; width: 100%; padding: 12px; background: #0d1117; color: #c9d1d9;
    border: 1px solid #30363d; border-radius: 6px; font-family: inherit; font-size: 0.85rem;
    resize: vertical; outline: none;
}
.aw-vibecode-label { color: #8b949e; font-size: 0.8rem; margin-bottom: 8px; }
.aw-vibecode-target { font-family: monospace; color: #e6edf3; }

.aw-scope-item {
    display: flex; align-items: flex-start; gap: 10px; padding: 8px 10px;
    border: 1px solid #30363d; border-radius: 6px; margin-bottom: 6px; background: #0d1117;
}
.aw-scope-item input[type="checkbox"] { margin-top: 3px; flex-shrink: 0; }
.aw-scope-item .path { font-family: monospace; color: #e6edf3; font-size: 0.82rem; }
.aw-scope-item .badge {
    font-size: 0.68rem; padding: 1px 6px; border-radius: 10px; margin-left: 6px;
    background: rgba(88,166,255,0.15); color: #79c0ff; border: 1px solid #58a6ff60;
}
.aw-scope-item .badge.new { background: rgba(63,185,80,0.15); color: #3fb950; border-color: #3fb95060; }
.aw-scope-item .reason { color: #8b949e; font-size: 0.75rem; margin-top: 2px; }

.aw-batch-card {
    border: 1px solid #30363d; border-radius: 8px; margin-bottom: 12px; overflow: hidden;
}
.aw-batch-card.discarded { opacity: 0.5; }
.aw-batch-card-head {
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
    padding: 8px 12px; background: #0d1117; border-bottom: 1px solid #30363d;
}
.aw-batch-card-head .left { display: flex; align-items: center; gap: 8px; }
.aw-batch-card-head .path { font-family: monospace; color: #e6edf3; font-size: 0.82rem; }
.aw-batch-card-head .status { font-size: 0.72rem; }
.aw-batch-card-head .status.ok { color: #3fb950; }
.aw-batch-card-head .status.fail { color: #f85149; }
.aw-batch-card-body { padding: 10px 12px; }
.aw-batch-explain { color: #8b949e; font-size: 0.78rem; margin-bottom: 8px; }
.aw-batch-error { color: #f85149; font-size: 0.8rem; }
.aw-diff-box {
    max-height: 220px; overflow: auto; background: #0d1117; border: 1px solid #21262d;
    border-radius: 6px; font-family: 'SF Mono','Fira Code',monospace; font-size: 0.78rem;
    line-height: 1.5;
}
.aw-diff-box .diff-line { padding: 1px 8px; white-space: pre-wrap; word-break: break-all; }
.aw-diff-box .diff-add { background: rgba(63,185,80,0.15); color: #7ee787; }
.aw-diff-box .diff-del { background: rgba(248,81,73,0.15); color: #ffa198; }
.aw-diff-box .diff-ctx { color: #8b949e; }
.aw-batch-card-body details { margin-top: 8px; }
.aw-batch-card-body summary { cursor: pointer; color: #8b949e; font-size: 0.75rem; }
</style>
</head>
<body>
<header class="aw-header">
    <div class="aw-logo"><span class="icon">✦</span> CertainThing – Agentic Workspace</div>
    <div class="aw-header-right">
        <a href="index.php" class="aw-back-link">← Back to CertainThing</a>
        <span><?= htmlspecialchars($userEmail) ?></span>
    </div>
</header>

<div class="aw-main">
    <!-- Workspace list view -->
    <div id="view-list">
        <div class="aw-upload-card">
            <p>Upload a .zip to start a new sandbox workspace. It's extracted separately from your normal chat sessions.</p>
            <input type="file" id="zip-input" accept=".zip">
            <div><button class="btn btn-primary" onclick="stageUpload()">Upload &amp; Preview</button></div>
        </div>
        <div class="aw-section-title">Your workspaces</div>
        <div class="aw-ws-list" id="ws-list"><div class="aw-empty">Loading…</div></div>
    </div>

    <!-- File-tree view (shown once a workspace is open) -->
    <div id="view-tree" style="display:none">
        <nav class="aw-breadcrumbs">
            <a href="javascript:void(0)" onclick="showList()">🏠 Workspaces</a>
            <span style="color:#484f58">/</span>
            <span id="ws-current-id" style="color:#e6edf3;font-weight:500;font-family:monospace"></span>
        </nav>
        <div class="aw-toolbar">
            <div class="aw-toolbar-left" id="tree-count"></div>
            <div class="aw-toolbar-right">
                <button class="btn btn-sm" onclick="createFilePrompt('')">+ File</button>
                <button class="btn btn-sm" onclick="createFolderPrompt('')">+ Folder</button>
                <button class="btn btn-sm" onclick="downloadZip('')">📦 Download all as ZIP</button>
                <button class="btn btn-sm" onclick="openBatchVibecode()">✨ Vibecode it (multi-file)</button>
                <button class="btn btn-sm" onclick="deployWorkspace()">🚀 Deploy</button>
                <button class="btn btn-sm" onclick="openGithubPush()">⤴️ Push to GitHub</button>
                <button class="btn btn-sm" onclick="openDeployManager()">🗂 Manage Deploys</button>
            </div>
        </div>
        <div class="aw-tree" id="tree-root"></div>
    </div>
</div>

<!-- Confirm-extraction modal -->
<div class="aw-modal-overlay" id="confirm-overlay">
    <div class="aw-modal aw-modal-sm">
        <div class="aw-modal-header"><h3>Confirm extraction</h3></div>
        <div class="aw-modal-body">
            <div class="aw-warning" id="confirm-warning"></div>
            <div style="color:#8b949e;font-size:0.8rem;margin-bottom:8px" id="confirm-count"></div>
            <ul class="aw-preview-list" id="confirm-preview"></ul>
        </div>
        <div class="aw-modal-footer">
            <button class="btn btn-sm" onclick="cancelStaged()">Cancel</button>
            <button class="btn btn-primary btn-sm" onclick="confirmStaged()">Extract &amp; Open</button>
        </div>
    </div>
</div>

<!-- Editor modal -->
<div class="aw-modal-overlay" id="editor-overlay">
    <div class="aw-modal">
        <div class="aw-modal-header">
            <h3 id="editor-title">Edit file</h3>
            <button class="btn-icon" onclick="closeEditor()">✕</button>
        </div>
        <div class="aw-modal-body">
            <div class="aw-vibecode-banner" id="editor-ai-banner"></div>
            <textarea id="editor-textarea" spellcheck="false"></textarea>
        </div>
        <div class="aw-modal-footer">
            <button class="btn btn-sm" onclick="closeEditor()">Cancel</button>
            <button class="btn btn-primary btn-sm" id="editor-save-btn" onclick="saveFile()">Save</button>
        </div>
    </div>
</div>

<!-- Vibecode-it modal: collects the instruction, calls the model, hands the result to the editor modal for review before any save -->
<div class="aw-modal-overlay" id="vibecode-overlay">
    <div class="aw-modal aw-modal-sm">
        <div class="aw-modal-header">
            <h3>✨ Vibecode it</h3>
            <button class="btn-icon" onclick="closeVibecode()">✕</button>
        </div>
        <div class="aw-modal-body">
            <div class="aw-vibecode-label">Editing <span class="aw-vibecode-target" id="vibecode-target"></span> — describe what to change or build. It only ever touches this one file, and nothing saves until you review and click Save.</div>
            <textarea id="vibecode-instruction" placeholder="e.g. add a dark-mode toggle button, or: build a simple contact form matching the rest of the page style"></textarea>
        </div>
        <div class="aw-modal-footer">
            <button class="btn btn-sm" onclick="closeVibecode()">Cancel</button>
            <button class="btn btn-primary btn-sm" id="vibecode-generate-btn" onclick="generateVibecode()">Generate</button>
        </div>
    </div>
</div>

<!-- Multi-file vibecode modal (Phase F): 3-step flow inside one modal —
     instruction -> scope review (checkboxes) -> per-file diff review (keep/discard) -> Save All.
     Same isolation rule as Phase C: nothing is written until Save All is clicked, which
     calls the existing save_file action once per kept file (no new write path). -->
<div class="aw-modal-overlay" id="batchvibe-overlay">
    <div class="aw-modal">
        <div class="aw-modal-header">
            <h3 id="batchvibe-title">✨ Vibecode it (multi-file)</h3>
            <button class="btn-icon" onclick="closeBatchVibecode()">✕</button>
        </div>
        <div class="aw-modal-body">
            <div id="batchvibe-step-instruction">
                <div class="aw-vibecode-label">Describe a change that may span several files (up to 5 files per batch). This first call only decides which files are relevant — nothing is generated yet.</div>
                <textarea id="batchvibe-instruction" placeholder="e.g. add a dark-mode toggle across the HTML, CSS and JS"></textarea>
            </div>
            <div id="batchvibe-step-scope" style="display:none">
                <div class="aw-vibecode-label" id="batchvibe-scope-note"></div>
                <div id="batchvibe-scope-list"></div>
            </div>
            <div id="batchvibe-step-review" style="display:none">
                <div class="aw-vibecode-label" id="batchvibe-review-summary"></div>
                <div id="batchvibe-review-list"></div>
            </div>
        </div>
        <div class="aw-modal-footer" id="batchvibe-footer">
            <button class="btn btn-sm" onclick="closeBatchVibecode()">Cancel</button>
            <button class="btn btn-primary btn-sm" id="batchvibe-primary-btn" onclick="scopeBatchVibecode()">Scope files</button>
        </div>
    </div>
</div>

<!-- Push to GitHub modal -->
<div class="aw-modal-overlay" id="ghpush-overlay">
    <div class="aw-modal aw-modal-sm">
        <div class="aw-modal-header">
            <h3>⤴️ Push to GitHub</h3>
            <button class="btn-icon" onclick="closeGithubPush()">✕</button>
        </div>
        <div class="aw-modal-body">
            <div class="aw-vibecode-label">Repository (owner/repo)</div>
            <input type="text" id="ghpush-repo-input" placeholder="e.g. octocat/hello-world" style="width:100%;padding:8px;background:#0d1117;border:1px solid #30363d;border-radius:6px;color:#e6edf3;font-size:0.85rem">
            <div class="aw-vibecode-label" id="ghpush-status" style="margin-top:4px;font-size:0.75rem;color:#8b949e"></div>
            <div class="aw-vibecode-label" style="margin-top:12px">Commit message</div>
            <input type="text" id="ghpush-message" placeholder="Update from Agentic Workspace" style="width:100%;padding:8px;background:#0d1117;border:1px solid #30363d;border-radius:6px;color:#e6edf3;font-size:0.85rem">
        </div>
        <div class="aw-modal-footer">
            <button class="btn btn-sm" onclick="closeGithubPush()">Cancel</button>
            <button class="btn btn-primary btn-sm" id="ghpush-btn" onclick="doGithubPush()">Push Commit</button>
        </div>
    </div>
</div>

<!-- Deploy entry-file picker: shown instead of a bare directory listing when no
     index.php/html/htm was found at the deployed doc root. Lets the user open the
     right file directly rather than landing on Apache's raw autoindex page. -->
<div class="aw-modal-overlay" id="deploy-picker-overlay">
    <div class="aw-modal aw-modal-sm">
        <div class="aw-modal-header">
            <h3>🚀 Deployed — pick the entry file</h3>
            <button class="btn-icon" onclick="closeDeployPicker()">✕</button>
        </div>
        <div class="aw-modal-body">
            <div class="aw-vibecode-label">No <code>index.php</code>/<code>index.html</code> was found at the deploy root, so nothing auto-opens. Pick the file this app should be opened with:</div>
            <ul class="aw-preview-list" id="deploy-picker-list" style="margin-top:10px"></ul>
        </div>
        <div class="aw-modal-footer">
            <button class="btn btn-sm" onclick="closeDeployPicker()">Close</button>
        </div>
    </div>
</div>

<div class="aw-toast" id="aw-toast"></div>

<script>
const API_UPLOAD   = 'api/agentic_workspace_upload.php';
const API_FILES    = 'api/agentic_files.php';
const API_SERVE    = 'api/agentic_serve_test.php';
const API_VIBECODE       = 'api/agentic_vibecode.php';
const API_VIBECODE_SCOPE = 'api/agentic_vibecode_scope.php';
const API_VIBECODE_BATCH = 'api/agentic_vibecode_batch.php';
const API_DEPLOY   = 'api/agentic_deploy.php';
const API_GHPUSH   = 'api/agentic_github_push.php';

let currentWorkspaceId = new URLSearchParams(location.search).get('workspace_id') || '';
let stagingId = '';
let editorPath = '';
let vibecodePath = '';

// Phase F multi-file vibecode state — lives only for the duration of one batch modal
// session; nothing here persists across a close/reopen.
let batchvibeInstruction = '';
let batchvibeScope = [];   // [{ path, exists, reason, checked }]
let batchvibeResults = []; // [{ path, ok, exists, new_content, explanation, error, checked }]
let batchvibeOriginals = {}; // path -> content-before-batch, fetched lazily for the diff view

function showToast(msg, isError) {
    const el = document.getElementById('aw-toast');
    el.textContent = msg;
    el.classList.toggle('error', !!isError);
    el.classList.add('visible');
    clearTimeout(el._t);
    el._t = setTimeout(() => el.classList.remove('visible'), 3000);
}

// A fetch() to an AI-calling endpoint can fail two different ways that a plain
// .catch() on r.json() can't tell apart: (a) the browser never got a response at
// all (offline, DNS, CORS) — fetch() itself rejects; (b) it got a response, but a
// non-2xx one whose body isn't the expected JSON (e.g. a reverse-proxy/CDN's own
// HTML "504/524 Gateway Timeout" page when the AI call ran long) — r.json() throws
// on that body. Both used to collapse into one generic "Network error" toast. This
// reads the actual HTTP status (0 means "no response reached the browser at all")
// and returns one plain-language sentence for it.
function classifyHttpError(status) {
    if (!status) return 'Connection lost — check your internet connection and try again.';
    if (status === 524 || status === 504) return 'The request took too long and timed out before finishing. This is more likely with a large file — try a smaller one, or try again.';
    if (status === 502 || status === 503) return 'The server is temporarily unavailable. Wait a moment and try again.';
    if (status === 401 || status === 403) return 'Your session may have expired — reload the page and try again.';
    if (status === 413) return 'The request was too large for the server to accept.';
    if (status === 429) return 'Too many requests right now — wait a few seconds and try again.';
    return 'The request failed (server responded with status ' + status + '). Try again.';
}

// Wraps a fetch response: on success, resolves the parsed JSON as normal. On a
// non-2xx or non-JSON response, rejects with a { httpError: '<readable message>' }
// object instead of letting a raw JSON.parse exception fall through to a generic
// catch(). Use in place of `.then(r => r.json())` for any AI-calling endpoint.
function readJsonOrHttpError(r) {
    return r.json().catch(() => null).then(d => {
        if (!r.ok || d === null) {
            return Promise.reject({ httpError: classifyHttpError(r.status) });
        }
        return d;
    });
}

/* ── Workspace list ─────────────────────────────────────────────────────── */
function showList() {
    currentWorkspaceId = '';
    history.pushState({}, '', 'agentic_workspace.php');
    document.getElementById('view-tree').style.display = 'none';
    document.getElementById('view-list').style.display = 'block';
    loadWorkspaceList();
}

function loadWorkspaceList() {
    fetch(API_UPLOAD + '?action=list')
        .then(r => r.json())
        .then(d => {
            const el = document.getElementById('ws-list');
            if (!d.workspaces || d.workspaces.length === 0) {
                el.innerHTML = '<div class="aw-empty"><div class="icon">📁</div><p>No workspaces yet — upload a zip above.</p></div>';
                return;
            }
            el.innerHTML = d.workspaces.map(w => `
                <div class="aw-ws-item">
                    <div>
                        <div class="id">${escapeHtml(w.workspace_id)}</div>
                        <div class="meta">Last active: ${w.last_activity_at ? escapeHtml(w.last_activity_at) : '—'} · auto-removed after ${d.ttl_days || 14}d idle</div>
                    </div>
                    <div style="display:flex;gap:6px">
                        <button class="btn btn-sm" onclick="openWorkspace('${w.workspace_id}')">Open</button>
                        <button class="btn btn-sm btn-danger" title="Delete workspace" onclick="deleteWorkspace('${w.workspace_id}')">🗑️</button>
                    </div>
                </div>
            `).join('');
        })
        .catch(() => showToast('Could not load workspaces', true));
}

function deleteWorkspace(id) {
    if (!confirm('Delete this entire workspace and all its files? This cannot be undone.')) return;
    fetch(API_UPLOAD, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=delete_workspace&workspace_id=' + encodeURIComponent(id)
    })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) return showToast(d.error || 'Could not delete workspace', true);
            showToast('Workspace deleted');
            loadWorkspaceList();
        })
        .catch(() => showToast('Network error', true));
}

function openWorkspace(id) {
    currentWorkspaceId = id;
    history.pushState({}, '', 'agentic_workspace.php?workspace_id=' + encodeURIComponent(id));
    document.getElementById('view-list').style.display = 'none';
    document.getElementById('view-tree').style.display = 'block';
    document.getElementById('ws-current-id').textContent = id;
    loadTree();
}

/* ── Upload / stage / confirm / cancel ─────────────────────────────────────── */
function stageUpload() {
    const input = document.getElementById('zip-input');
    if (!input.files.length) return showToast('Choose a .zip file first', true);
    const fd = new FormData();
    fd.append('action', 'stage');
    fd.append('zip', input.files[0]);
    showToast('Uploading…');
    fetch(API_UPLOAD, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.error) return showToast(d.error, true);
            stagingId = d.staging_id;
            document.getElementById('confirm-warning').textContent = d.warning || '';
            document.getElementById('confirm-count').textContent =
                d.entry_count + ' entries' + (d.truncated ? ' (preview truncated)' : '');
            document.getElementById('confirm-preview').innerHTML =
                d.preview.map(p => `<li>${escapeHtml(p)}</li>`).join('');
            document.getElementById('confirm-overlay').classList.add('active');
        })
        .catch(() => showToast('Upload failed', true));
}

function cancelStaged() {
    document.getElementById('confirm-overlay').classList.remove('active');
    if (!stagingId) return;
    fetch(API_UPLOAD, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=cancel&staging_id=' + encodeURIComponent(stagingId)
    }).finally(() => { stagingId = ''; });
}

function confirmStaged() {
    if (!stagingId) return;
    fetch(API_UPLOAD, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=confirm&staging_id=' + encodeURIComponent(stagingId)
    })
    .then(r => r.json())
    .then(d => {
        document.getElementById('confirm-overlay').classList.remove('active');
        if (d.error) return showToast(d.error, true);
        stagingId = '';
        showToast('Workspace ready');
        openWorkspace(d.workspace_id);
    })
    .catch(() => showToast('Extraction failed', true));
}

/* ── Tree ───────────────────────────────────────────────────────────────── */
function loadTree() {
    fetch(API_FILES + '?action=tree&workspace_id=' + encodeURIComponent(currentWorkspaceId))
        .then(r => r.json())
        .then(d => {
            if (!d.ok) return showToast(d.error || 'Could not load workspace', true);
            document.getElementById('tree-root').innerHTML = renderNodes(d.tree);
            document.getElementById('tree-count').textContent = countNodes(d.tree) + ' item(s)';
        })
        .catch(() => showToast('Network error', true));
}

function countNodes(nodes) {
    let n = 0;
    for (const node of nodes) { n++; if (node.type === 'folder') n += countNodes(node.children); }
    return n;
}

function renderNodes(nodes) {
    return nodes.map(node => {
        const isFolder = node.type === 'folder';
        const icon = isFolder ? '📁' : '📄';
        const sizeLabel = isFolder ? '' : `<span class="fsize">${formatSize(node.size)}</span>`;
        const pathAttr = escapeAttr(node.path);
        const nameAttr = escapeAttr(node.name);
        const rowActions = isFolder ? `
            <button class="btn-icon" title="New file here" onclick="createFilePrompt('${pathAttr}')">＋📄</button>
            <button class="btn-icon" title="New folder here" onclick="createFolderPrompt('${pathAttr}')">＋📁</button>
            <button class="btn-icon" title="Download as ZIP" onclick="downloadZip('${pathAttr}')">📦</button>
            <button class="btn-icon" title="Move" onclick="moveItem('${pathAttr}')">📤</button>
            <button class="btn-icon" title="Rename" onclick="renameItem('${pathAttr}', '${nameAttr}')">📝</button>
            <button class="btn-icon danger" title="Delete folder" onclick="deleteItem('${pathAttr}', true)">🗑️</button>
        ` : `
            <button class="btn-icon" title="View" onclick="viewFile('${pathAttr}')">👁️</button>
            <button class="btn-icon" title="Edit" onclick="openEditor('${pathAttr}')">✏️</button>
            <button class="btn-icon" title="Get link" onclick="getLink('${pathAttr}')">🔗</button>
            <button class="btn-icon" title="Download" onclick="downloadFile('${pathAttr}')">⬇️</button>
            <button class="btn-icon" title="Move" onclick="moveItem('${pathAttr}')">📤</button>
            <button class="btn-icon" title="Rename" onclick="renameItem('${pathAttr}', '${nameAttr}')">📝</button>
            <button class="btn-icon danger" title="Delete" onclick="deleteItem('${pathAttr}', false)">🗑️</button>
            <button class="btn-icon" title="Vibecode it" onclick="vibecodeFile('${pathAttr}')">✨</button>
        `;
        const childrenHtml = isFolder ? `<div class="aw-node-children" id="children-${cssIdSafe(node.path)}">${renderNodes(node.children)}</div>` : '';
        const toggle = isFolder ? `onclick="toggleFolder('${cssIdSafe(node.path)}')"` : '';
        return `
            <div class="aw-node">
                <div class="aw-node-row">
                    <div class="aw-node-name" ${toggle}>
                        <span class="ficon">${icon}</span>${escapeHtml(node.name)}${sizeLabel}
                    </div>
                    <div class="aw-node-actions">${rowActions}</div>
                </div>
                ${childrenHtml}
            </div>
        `;
    }).join('');
}

function toggleFolder(id) {
    const el = document.getElementById('children-' + id);
    if (el) el.classList.toggle('open');
}

function cssIdSafe(path) { return path.replace(/[^a-zA-Z0-9]/g, '_') || 'root'; }
function escapeHtml(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function escapeAttr(s) { return String(s).replace(/'/g, "\\'"); }
function formatSize(b) {
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
    return (b / 1048576).toFixed(1) + ' MB';
}

/* ── File actions ───────────────────────────────────────────────────────── */
function postJson(action, body) {
    return fetch(API_FILES + '?action=' + action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({ workspace_id: currentWorkspaceId }, body))
    }).then(r => r.json());
}

function viewFile(path) {
    window.open(API_SERVE + '?workspace_id=' + encodeURIComponent(currentWorkspaceId) + '&file=' + encodeURIComponent(path) + '&harness=0', '_blank');
}

function getLink(path) {
    const url = location.origin + location.pathname.replace(/[^/]*$/, '') +
        API_SERVE + '?workspace_id=' + encodeURIComponent(currentWorkspaceId) + '&file=' + encodeURIComponent(path) + '&harness=0';
    navigator.clipboard.writeText(url).then(
        () => showToast('Link copied (opens in your session, not public)'),
        () => showToast('Could not copy link', true)
    );
}

function downloadFile(path) {
    location.href = API_FILES + '?action=download&workspace_id=' + encodeURIComponent(currentWorkspaceId) + '&file=' + encodeURIComponent(path);
}

function downloadZip(folder) {
    showToast('Preparing ZIP…');
    location.href = API_FILES + '?action=download_zip&workspace_id=' + encodeURIComponent(currentWorkspaceId) + '&folder=' + encodeURIComponent(folder);
}

function deleteItem(path, isDir) {
    if (!confirm('Delete ' + (isDir ? 'this entire folder and all its contents' : 'this file') + '?')) return;
    postJson('delete_item', { file: path })
        .then(d => d.ok ? loadTree() : showToast(d.error || 'Delete failed', true))
        .catch(() => showToast('Network error', true));
}

function renameItem(path, currentName) {
    const newName = prompt('New name:', currentName);
    if (!newName || newName === currentName) return;
    postJson('rename_file', { file: path, newName })
        .then(d => d.ok ? loadTree() : showToast(d.error || 'Rename failed', true))
        .catch(() => showToast('Network error', true));
}

function moveItem(path) {
    const dest = prompt('Move to which folder? (blank = workspace root, or a path like "assets")', '');
    if (dest === null) return;
    postJson('move_item', { file: path, destFolder: dest })
        .then(d => d.ok ? loadTree() : showToast(d.error || 'Move failed', true))
        .catch(() => showToast('Network error', true));
}

function createFilePrompt(folderPath) {
    const name = prompt('New file name:', '');
    if (!name) return;
    const full = folderPath ? folderPath + '/' + name : name;
    postJson('create_file', { file: full })
        .then(d => d.ok ? loadTree() : showToast(d.error || 'Create failed', true))
        .catch(() => showToast('Network error', true));
}

function createFolderPrompt(folderPath) {
    const name = prompt('New folder name:', '');
    if (!name) return;
    const full = folderPath ? folderPath + '/' + name : name;
    postJson('create_folder', { folder: full })
        .then(d => d.ok ? loadTree() : showToast(d.error || 'Create failed', true))
        .catch(() => showToast('Network error', true));
}

/* ── Editor ─────────────────────────────────────────────────────────────── */
function hideAiBanner() {
    const banner = document.getElementById('editor-ai-banner');
    banner.classList.remove('visible');
    banner.textContent = '';
}

function openEditor(path) {
    editorPath = path;
    hideAiBanner();
    document.getElementById('editor-title').textContent = 'Edit: ' + path.split('/').pop();
    const ta = document.getElementById('editor-textarea');
    ta.value = 'Loading…';
    ta.readOnly = true;
    document.getElementById('editor-overlay').classList.add('active');
    fetch(API_FILES + '?action=get_content&workspace_id=' + encodeURIComponent(currentWorkspaceId) + '&file=' + encodeURIComponent(path))
        .then(r => r.json())
        .then(d => {
            if (d.ok) { ta.value = d.content; ta.readOnly = false; ta.focus(); }
            else { ta.value = '// Error: ' + (d.error || 'Could not load file'); }
        })
        .catch(() => { ta.value = '// Network error'; });
}

/* Opens the same editor modal pre-filled with AI-generated content for review — nothing
   is written to disk until the user reviews it here and clicks Save (existing save_file
   action, unchanged). Cancel discards the AI output exactly like it discards a manual edit. */
function openEditorWithAiContent(path, content, explanation) {
    editorPath = path;
    document.getElementById('editor-title').textContent = 'Review AI edit: ' + path.split('/').pop();
    const ta = document.getElementById('editor-textarea');
    ta.value = content;
    ta.readOnly = false;
    const banner = document.getElementById('editor-ai-banner');
    banner.textContent = '✨ AI-generated — not saved yet. ' + (explanation || 'Review the content below, then Save or Cancel.');
    banner.classList.add('visible');
    document.getElementById('editor-overlay').classList.add('active');
    ta.focus();
}

function closeEditor() {
    document.getElementById('editor-overlay').classList.remove('active');
    hideAiBanner();
    editorPath = '';
}

function saveFile() {
    if (!editorPath) return;
    const btn = document.getElementById('editor-save-btn');
    btn.disabled = true; btn.textContent = 'Saving…';
    postJson('save_file', { file: editorPath, content: document.getElementById('editor-textarea').value })
        .then(d => {
            btn.disabled = false; btn.textContent = 'Save';
            if (d.ok) { showToast('Saved'); closeEditor(); loadTree(); }
            else showToast(d.error || 'Save failed', true);
        })
        .catch(() => { btn.disabled = false; btn.textContent = 'Save'; showToast('Network error', true); });
}

/* ── Vibecode it (Phase C) ──────────────────────────────────────────────────
   Collects an instruction, calls the sandbox-scoped model endpoint, and hands the
   result to the editor modal for review — nothing is written until Save is clicked. */
function vibecodeFile(path) {
    vibecodePath = path;
    document.getElementById('vibecode-target').textContent = path;
    document.getElementById('vibecode-instruction').value = '';
    document.getElementById('vibecode-overlay').classList.add('active');
    setTimeout(() => document.getElementById('vibecode-instruction').focus(), 50);
}

function closeVibecode() {
    document.getElementById('vibecode-overlay').classList.remove('active');
    vibecodePath = '';
}

function generateVibecode() {
    if (!vibecodePath) return;
    const instruction = document.getElementById('vibecode-instruction').value.trim();
    if (!instruction) return showToast('Describe what to change or build first', true);

    const btn = document.getElementById('vibecode-generate-btn');
    btn.disabled = true; btn.textContent = 'Generating…';
    showToast('Vibecoding ' + vibecodePath + '…');

    fetch(API_VIBECODE, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ workspace_id: currentWorkspaceId, file: vibecodePath, instruction })
    })
        .then(readJsonOrHttpError)
        .then(d => {
            btn.disabled = false; btn.textContent = 'Generate';
            if (!d.ok) return showToast(d.error || 'Vibecode failed', true);
            const path = vibecodePath;
            closeVibecode();
            openEditorWithAiContent(path, d.new_content, d.explanation);
        })
        .catch(err => {
            btn.disabled = false; btn.textContent = 'Generate';
            showToast((err && err.httpError) || 'Connection lost — check your internet connection and try again.', true);
        });
}

/* ── Vibecode it, multi-file (Phase F) ─────────────────────────────────────────
   3-step modal: instruction -> scope review -> per-file diff review -> Save All.
   F.1 (scope) and F.2 (per-file loop) endpoints never write to disk; the only write
   path is the same save_file action the single-file editor already uses (F.4). */
function openBatchVibecode() {
    batchvibeInstruction = '';
    batchvibeScope = [];
    batchvibeResults = [];
    batchvibeOriginals = {};
    document.getElementById('batchvibe-instruction').value = '';
    showBatchvibeStep('instruction');
    document.getElementById('batchvibe-overlay').classList.add('active');
    setTimeout(() => document.getElementById('batchvibe-instruction').focus(), 50);
}

function closeBatchVibecode() {
    document.getElementById('batchvibe-overlay').classList.remove('active');
}

function showBatchvibeStep(step) {
    document.getElementById('batchvibe-step-instruction').style.display = step === 'instruction' ? 'block' : 'none';
    document.getElementById('batchvibe-step-scope').style.display = step === 'scope' ? 'block' : 'none';
    document.getElementById('batchvibe-step-review').style.display = step === 'review' ? 'block' : 'none';
    const footer = document.getElementById('batchvibe-footer');
    const primaryBtn = document.getElementById('batchvibe-primary-btn');
    if (step === 'instruction') {
        document.getElementById('batchvibe-title').textContent = '✨ Vibecode it (multi-file)';
        primaryBtn.textContent = 'Scope files';
        primaryBtn.onclick = scopeBatchVibecode;
        primaryBtn.disabled = false;
    } else if (step === 'scope') {
        document.getElementById('batchvibe-title').textContent = '✨ Step 1 of 2 — Confirm scope';
        primaryBtn.textContent = 'Continue';
        primaryBtn.onclick = runBatchLoop;
        primaryBtn.disabled = false;
    } else {
        document.getElementById('batchvibe-title').textContent = '✨ Step 2 of 2 — Review changes';
        primaryBtn.textContent = 'Save All';
        primaryBtn.onclick = saveAllBatch;
        primaryBtn.disabled = false;
    }
}

function scopeBatchVibecode() {
    const instruction = document.getElementById('batchvibe-instruction').value.trim();
    if (!instruction) return showToast('Describe what to change or build first', true);
    batchvibeInstruction = instruction;

    const btn = document.getElementById('batchvibe-primary-btn');
    btn.disabled = true; btn.textContent = 'Scoping…';

    fetch(API_VIBECODE_SCOPE, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ workspace_id: currentWorkspaceId, instruction })
    })
        .then(readJsonOrHttpError)
        .then(d => {
            btn.disabled = false;
            if (!d.ok) { btn.textContent = 'Scope files'; return showToast(d.error || 'Scoping failed', true); }
            if (!d.files || d.files.length === 0) {
                btn.textContent = 'Scope files';
                return showToast(d.out_of_scope_note || 'No files matched this instruction', true);
            }
            batchvibeScope = d.files.map(f => Object.assign({}, f, { checked: true }));
            renderScopeList(d.out_of_scope_note || '');
            showBatchvibeStep('scope');
        })
        .catch(err => { btn.disabled = false; btn.textContent = 'Scope files'; showToast((err && err.httpError) || 'Connection lost — check your internet connection and try again.', true); });
}

function renderScopeList(note) {
    document.getElementById('batchvibe-scope-note').textContent = note
        ? '⚠️ ' + note
        : 'These files were identified as in scope. Uncheck any you want to skip, then continue.';
    document.getElementById('batchvibe-scope-list').innerHTML = batchvibeScope.map((f, i) => `
        <label class="aw-scope-item">
            <input type="checkbox" ${f.checked ? 'checked' : ''} onchange="toggleScopeFile(${i}, this.checked)">
            <div>
                <div class="path">${escapeHtml(f.path)}<span class="badge ${f.exists ? '' : 'new'}">${f.exists ? 'existing' : 'new file'}</span></div>
                <div class="reason">${escapeHtml(f.reason || '')}</div>
            </div>
        </label>
    `).join('');
}

function toggleScopeFile(i, checked) {
    batchvibeScope[i].checked = checked;
}

function runBatchLoop() {
    const chosen = batchvibeScope.filter(f => f.checked).map(f => f.path);
    if (chosen.length === 0) return showToast('Select at least one file', true);

    const btn = document.getElementById('batchvibe-primary-btn');
    btn.disabled = true;

    // One HTTP request per file, sequential, driven from here — NOT one request looping the
    // whole batch server-side. Each OpenAI call can take up to two minutes; five of them stacked
    // inside a single response blows past a reverse-proxy/CDN edge timeout (Cloudflare 524)
    // regardless of PHP's own execution-time limit. This mirrors saveAllBatch's per-file loop.
    let batchSummary = '';
    const results = [];
    let i = 0;

    function next() {
        if (i >= chosen.length) {
            btn.disabled = false;
            batchvibeResults = results.map(r => Object.assign({}, r, { checked: !!r.ok }));
            const okCount = batchvibeResults.filter(r => r.ok).length;
            document.getElementById('batchvibe-review-summary').textContent =
                okCount + ' of ' + batchvibeResults.length + ' file(s) generated successfully. Review each below — uncheck any you don\'t want to keep.';
            renderReviewList();
            showBatchvibeStep('review');
            fetchOriginalsForDiff(batchvibeResults.filter(r => r.ok && r.exists).map(r => r.path));
            return;
        }
        const file = chosen[i];
        btn.textContent = 'Generating ' + (i + 1) + ' of ' + chosen.length + '…';

        fetch(API_VIBECODE_BATCH, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                workspace_id: currentWorkspaceId,
                instruction: batchvibeInstruction,
                file: file,
                file_number: i + 1,
                total_count: chosen.length,
                files: chosen,
                batch_summary: batchSummary
            })
        })
            .then(readJsonOrHttpError)
            .then(d => {
                if (!d.ok) {
                    results.push({ path: file, ok: false, error: d.error || 'Batch generation failed' });
                } else {
                    results.push(d.result);
                    if (typeof d.batch_summary === 'string') batchSummary = d.batch_summary;
                }
                i++;
                next();
            })
            .catch(err => { results.push({ path: file, ok: false, error: (err && err.httpError) || 'Connection lost — check your internet connection and try again.' }); i++; next(); });
    }

    showToast('Vibecoding ' + chosen.length + ' file(s)…');
    next();
}

// Fetches each existing file's pre-batch content (best-effort) purely to render the diff
// view — read-only, never touches anything the batch endpoint already produced.
function fetchOriginalsForDiff(paths) {
    paths.forEach(p => {
        if (batchvibeOriginals[p] !== undefined) return;
        fetch(API_FILES + '?action=get_content&workspace_id=' + encodeURIComponent(currentWorkspaceId) + '&file=' + encodeURIComponent(p))
            .then(r => r.json())
            .then(d => {
                batchvibeOriginals[p] = d.ok ? d.content : '';
                renderReviewList();
            })
            .catch(() => { batchvibeOriginals[p] = ''; });
    });
}

// Minimal LCS-based line diff — good enough for a review screen, not a full diff engine.
function computeLineDiff(before, after) {
    const a = (before || '').split('\n');
    const b = (after || '').split('\n');
    const n = a.length, m = b.length;
    const dp = Array.from({ length: n + 1 }, () => new Array(m + 1).fill(0));
    for (let i = n - 1; i >= 0; i--) {
        for (let j = m - 1; j >= 0; j--) {
            dp[i][j] = a[i] === b[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
        }
    }
    const lines = [];
    let i = 0, j = 0;
    while (i < n && j < m) {
        if (a[i] === b[j]) { lines.push({ t: 'ctx', v: a[i] }); i++; j++; }
        else if (dp[i + 1][j] >= dp[i][j + 1]) { lines.push({ t: 'del', v: a[i] }); i++; }
        else { lines.push({ t: 'add', v: b[j] }); j++; }
    }
    while (i < n) { lines.push({ t: 'del', v: a[i] }); i++; }
    while (j < m) { lines.push({ t: 'add', v: b[j] }); j++; }
    return lines;
}

function renderReviewList() {
    document.getElementById('batchvibe-review-list').innerHTML = batchvibeResults.map((r, i) => {
        if (!r.ok) {
            return `
                <div class="aw-batch-card discarded">
                    <div class="aw-batch-card-head">
                        <div class="left"><span class="path">${escapeHtml(r.path)}</span></div>
                        <span class="status fail">✕ failed — skipped</span>
                    </div>
                    <div class="aw-batch-card-body">
                        <div class="aw-batch-error">${escapeHtml(r.error || 'Unknown error')}</div>
                    </div>
                </div>`;
        }
        const original = r.exists ? (batchvibeOriginals[r.path] ?? null) : '';
        let diffHtml = '<div style="color:#8b949e;font-size:0.75rem;padding:8px">Loading original for diff…</div>';
        if (original !== null) {
            const diffLines = computeLineDiff(original, r.new_content);
            diffHtml = diffLines.map(l =>
                `<div class="diff-line diff-${l.t === 'ctx' ? 'ctx' : l.t}">${l.t === 'add' ? '+ ' : l.t === 'del' ? '- ' : '  '}${escapeHtml(l.v)}</div>`
            ).join('');
        }
        return `
            <div class="aw-batch-card ${r.checked ? '' : 'discarded'}" id="batch-card-${i}">
                <div class="aw-batch-card-head">
                    <div class="left">
                        <input type="checkbox" ${r.checked ? 'checked' : ''} onchange="toggleReviewFile(${i}, this.checked)">
                        <span class="path">${escapeHtml(r.path)}</span>
                        <span class="badge ${r.exists ? '' : 'new'}">${r.exists ? 'modified' : 'new file'}</span>
                    </div>
                    <span class="status ok">✓ generated</span>
                </div>
                <div class="aw-batch-card-body">
                    ${r.explanation ? `<div class="aw-batch-explain">${escapeHtml(r.explanation)}</div>` : ''}
                    <details ${batchvibeResults.length <= 2 ? 'open' : ''}>
                        <summary>Diff (before / after)</summary>
                        <div class="aw-diff-box">${diffHtml}</div>
                    </details>
                </div>
            </div>`;
    }).join('');
}

function toggleReviewFile(i, checked) {
    batchvibeResults[i].checked = checked;
    const card = document.getElementById('batch-card-' + i);
    if (card) card.classList.toggle('discarded', !checked);
}

function saveAllBatch() {
    const toSave = batchvibeResults.filter(r => r.ok && r.checked);
    if (toSave.length === 0) { showToast('Nothing selected to save', true); return; }

    const btn = document.getElementById('batchvibe-primary-btn');
    btn.disabled = true; btn.textContent = 'Saving…';

    let done = 0, okCount = 0;
    const failures = [];
    const saveNext = () => {
        if (done >= toSave.length) {
            btn.disabled = false; btn.textContent = 'Save All';
            const summary = okCount + ' of ' + toSave.length + ' saved' +
                (failures.length ? ' — ' + failures.map(f => f.path + ': ' + f.error).join('; ') : '');
            showToast(summary, failures.length > 0);
            closeBatchVibecode();
            loadTree();
            return;
        }
        const r = toSave[done];
        // A proposed new file doesn't exist on disk yet — save_file only writes into an
        // already-existing path, so create it (existing create_file action) first, same
        // as manually clicking "+ File" then typing content. Skips straight to save_file
        // for a file that already existed pre-batch.
        const ensureThenSave = r.exists
            ? Promise.resolve({ ok: true })
            : postJson('create_file', { file: r.path });
        ensureThenSave
            .then(createResult => {
                if (!createResult.ok) return createResult;
                return postJson('save_file', { file: r.path, content: r.new_content });
            })
            .then(d => {
                if (d.ok) okCount++; else failures.push({ path: r.path, error: d.error || 'save failed' });
                done++;
                saveNext();
            })
            .catch(() => { failures.push({ path: r.path, error: 'Connection lost while saving' }); done++; saveNext(); });
    };
    saveNext();
}

/* ── Deploy (Phase D) ────────────────────────────────────────────────────────
   Copies the whole current workspace, folder structure intact, to a live-viewable
   folder. Always a fresh copy — no confirmation step needed since it never touches
   the workspace itself, only a separate deployed mirror of it. */
function deployWorkspace() {
    showToast('Deploying workspace…');
    fetch(API_DEPLOY, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ workspace_id: currentWorkspaceId })
    })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) return showToast(d.error || 'Deploy failed', true);
            if (d.has_index) {
                showToast('🚀 Deployed ' + d.count + ' file(s)');
                window.open(d.view_url, '_blank');
            } else {
                // No index.php/index.html at the doc root — nothing to safely auto-open.
                // Show our own picker instead of opening Apache's bare directory listing.
                showToast('🚀 Deployed ' + d.count + ' file(s)');
                openDeployPicker(d.folder_url, d.root_files || []);
            }
        })
        .catch(() => showToast('Network error', true));
}

function openDeployPicker(folderUrl, files) {
    const list = document.getElementById('deploy-picker-list');
    list.innerHTML = '';
    if (files.length === 0) {
        list.innerHTML = '<li>No files at the deploy root.</li>';
    } else {
        files.forEach(f => {
            const li = document.createElement('li');
            const a = document.createElement('a');
            a.href = folderUrl + encodeURIComponent(f);
            a.target = '_blank';
            a.rel = 'noopener';
            a.textContent = f;
            a.style.color = '#58a6ff';
            li.appendChild(a);
            list.appendChild(li);
        });
    }
    document.getElementById('deploy-picker-overlay').classList.add('active');
}

function closeDeployPicker() {
    document.getElementById('deploy-picker-overlay').classList.remove('active');
}

/* Opens the Agentic Deploy Manager (deploy/agentic_deploy_manager.php), scoped
   straight to this workspace's deployed folder — same browse/edit/rename/
   delete/download/ZIP toolset the regular deploy manager has, one level
   deeper (deploy/agentic/{user_id}/{workspace_id}/). Session carries auth
   over automatically (same login as this page), so it opens directly into
   the file listing, no separate sign-in step. */
function openDeployManager() {
    window.open('deploy/agentic_deploy_manager.php?path=' + encodeURIComponent(currentWorkspaceId), '_blank');
}

/* ── Push to GitHub (Phase D) ──────────────────────────────────────────────── */
function openGithubPush() {
    document.getElementById('ghpush-message').value = '';
    document.getElementById('ghpush-status').textContent = 'Loading…';
    document.getElementById('ghpush-overlay').classList.add('active');

    fetch('api/save_settings.php', { cache: 'no-store' })
        .then(r => r.json())
        .then(s => {
            const input = document.getElementById('ghpush-repo-input');
            const status = document.getElementById('ghpush-status');
            const btn = document.getElementById('ghpush-btn');
            input.value = s.github_repo || '';
            if (s.github_pat_set) {
                status.textContent = '';
                btn.disabled = false;
            } else {
                status.innerHTML = 'Token not configured — set it in Setup → GitHub first.';
                btn.disabled = true;
            }
        })
        .catch(() => {
            document.getElementById('ghpush-status').textContent = 'Could not load GitHub settings.';
            document.getElementById('ghpush-btn').disabled = true;
        });
}

function closeGithubPush() {
    document.getElementById('ghpush-overlay').classList.remove('active');
}

function doGithubPush() {
    const btn = document.getElementById('ghpush-btn');
    const status = document.getElementById('ghpush-status');
    const repo = document.getElementById('ghpush-repo-input').value.trim();
    if (!repo) {
        status.textContent = 'Enter a repository (owner/repo).';
        document.getElementById('ghpush-repo-input').focus();
        return;
    }
    const message = document.getElementById('ghpush-message').value.trim();
    btn.disabled = true; btn.textContent = 'Pushing…';

    fetch(API_GHPUSH, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ workspace_id: currentWorkspaceId, repo, message })
    })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false; btn.textContent = 'Push Commit';
            if (!d.ok) return showToast(d.error || 'GitHub push failed', true);
            showToast('Pushed ' + d.count + ' file(s) to GitHub');
            closeGithubPush();
            window.open(d.view_url, '_blank');
        })
        .catch(() => {
            btn.disabled = false; btn.textContent = 'Push Commit';
            showToast('Network error', true);
        });
}

/* ── Init ───────────────────────────────────────────────────────────────── */
if (currentWorkspaceId) {
    openWorkspace(currentWorkspaceId);
} else {
    loadWorkspaceList();
}
</script>
</body>
</html>
