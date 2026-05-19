/**
 * CertainThing App JS
 */

document.addEventListener('DOMContentLoaded', () => {
    // DOM References
    const chatForm = document.getElementById('chat-form');
    const chatInput = document.getElementById('chat-input');
    const messagesContainer = document.getElementById('messages-container');
    const reasoningContainer = document.getElementById('reasoning-container');
    const statusBadge = document.getElementById('status-badge');
    const newChatBtn = document.getElementById('new-chat-btn');
    const newChatSidebarBtn = document.getElementById('new-chat-sidebar-btn');
    const attachBtn = document.getElementById('attach-btn');
    const fileInput = document.getElementById('file-input');
    const attachmentPreview = document.getElementById('attachment-preview');
    const deployBtn = document.getElementById('deploy-btn');
    const sessionSidebar = document.getElementById('session-sidebar');
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sessionList = document.getElementById('session-list');
    const sessionSearch = document.getElementById('session-search');
    const sessionCount = document.getElementById('session-count');
    const sidebarOverlay = document.getElementById('sidebar-overlay');
    const reasoningToggleHeader = document.getElementById('reasoning-toggle-header');
    const reasoningTogglePane = document.getElementById('reasoning-toggle-pane');
    const rightPane = document.getElementById('reasoning-pane');
    const paneTabBtns = document.querySelectorAll('.pane-tab-btn');
    const tabContents = document.querySelectorAll('.tab-content');
    const previewIframe = document.getElementById('preview-iframe');
    const refreshPreviewBtn = document.getElementById('refresh-preview-btn');
    
    let currentSessionId = localStorage.getItem('certainthing_session_id') || 'sess_' + Date.now();
    localStorage.setItem('certainthing_session_id', currentSessionId);
    let attachmentQueue = [];
    let allSessions = [];
    let isDeploying = false;

    // Initial Load
    loadSession(currentSessionId);
    fetchSessions();

    // =============================================
    // Sidebar Toggle
    // =============================================
    sidebarToggle.addEventListener('click', () => {
        sessionSidebar.classList.toggle('collapsed');
        if (sidebarOverlay) {
            if (!sessionSidebar.classList.contains('collapsed') && window.innerWidth <= 830) {
                sidebarOverlay.classList.add('active');
            } else {
                sidebarOverlay.classList.remove('active');
            }
        }
        localStorage.setItem('certainthing_sidebar_collapsed', sessionSidebar.classList.contains('collapsed'));
    });

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', () => {
            sessionSidebar.classList.add('collapsed');
            sidebarOverlay.classList.remove('active');
            localStorage.setItem('certainthing_sidebar_collapsed', 'true');
        });
    }

    // Restore sidebar state
    if (localStorage.getItem('certainthing_sidebar_collapsed') === 'true') {
        sessionSidebar.classList.add('collapsed');
    }

    // =============================================
    // Reasoning Pane Toggle (Mobile)
    // =============================================
    reasoningToggleHeader.addEventListener('click', () => {
        rightPane.classList.toggle('show-reasoning');
    });

    reasoningTogglePane.addEventListener('click', () => {
        rightPane.classList.remove('show-reasoning');
    });

    // Pane Tabs
    paneTabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const tabId = btn.dataset.tab;
            paneTabBtns.forEach(b => b.classList.remove('active'));
            tabContents.forEach(c => c.classList.remove('active'));
            
            btn.classList.add('active');
            document.getElementById(`${tabId}-container`).classList.add('active');

            if (tabId === 'preview') {
                refreshPreview();
            }
        });
    });

    refreshPreviewBtn.addEventListener('click', () => {
        refreshPreview();
    });

    // Close reasoning pane on escape
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && rightPane.classList.contains('show-reasoning')) {
            rightPane.classList.remove('show-reasoning');
        }
    });

    // =============================================
    // Keyboard Shortcut: F10 - Deploy
    // =============================================
    document.addEventListener('keydown', (e) => {
        if (e.key === 'F10') {
            e.preventDefault();
            deployLatestCode();
        }
    });

    // =============================================
    // Deploy Button
    // =============================================
    deployBtn.addEventListener('click', () => {
        deployLatestCode();
    });

    // =============================================
    // Attachments
    // =============================================
    attachBtn.addEventListener('click', () => fileInput.click());

    fileInput.addEventListener('change', async () => {
        const files = Array.from(fileInput.files);
        if (files.length === 0) return;

        for (const file of files) {
            await uploadFile(file);
        }
        fileInput.value = '';
    });

    async function uploadFile(file) {
        const formData = new FormData();
        formData.append('file', file);

        try {
            const response = await fetch('api/upload.php', {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                const errData = await response.json().catch(() => ({}));
                throw new Error(errData.error || 'Upload failed');
            }

            const data = await response.json();
            addAttachmentToQueue(data);
        } catch (err) {
            console.error('Upload error', err);
            showToast('Upload failed: ' + err.message, 'error');
        }
    }

    function addAttachmentToQueue(attachment) {
        attachmentQueue.push(attachment);
        renderAttachmentChips();
    }

    function renderAttachmentChips() {
        attachmentPreview.innerHTML = '';
        attachmentQueue.forEach((att, index) => {
            const chip = document.createElement('div');
            chip.className = 'attachment-chip';
            
            if (att.is_image) {
                const img = document.createElement('img');
                img.src = att.content;
                img.alt = att.name;
                chip.appendChild(img);
            } else {
                const icon = document.createElement('span');
                icon.textContent = '📄';
                chip.appendChild(icon);
            }

            const name = document.createElement('span');
            name.textContent = att.name;
            chip.appendChild(name);

            const removeBtn = document.createElement('span');
            removeBtn.className = 'remove-btn';
            removeBtn.textContent = '×';
            removeBtn.onclick = () => {
                attachmentQueue.splice(index, 1);
                renderAttachmentChips();
            };
            chip.appendChild(removeBtn);

            attachmentPreview.appendChild(chip);
        });
    }

    // Auto-resize textarea
    chatInput.addEventListener('input', () => {
        chatInput.style.height = 'auto';
        chatInput.style.height = (chatInput.scrollHeight) + 'px';
    });

    // =============================================
    // Chat Form Submit
    // =============================================
    chatForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const message = chatInput.value.trim();
        if (!message && attachmentQueue.length === 0) return;

        // Detect URLs in the message
        const urlRegex = /(https?:\/\/[^\s]+)/g;
        const urls = [];
        let match;
        while ((match = urlRegex.exec(message)) !== null) {
            urls.push(match[1]);
        }

        const attachments = [...attachmentQueue];
        attachmentQueue = [];
        renderAttachmentChips();

        chatInput.value = '';
        chatInput.style.height = 'auto';
        
        appendMessage('user', message, attachments);
        await sendMessage(message, attachments, urls);
    });

    // =============================================
    // New Chat
    // =============================================
    async function startNewChat() {
        const newSessionId = 'sess_' + Date.now();
        const nowIso = new Date().toISOString();

        currentSessionId = newSessionId;
        localStorage.setItem('certainthing_session_id', currentSessionId);
        messagesContainer.innerHTML = '';
        reasoningContainer.innerHTML = '';
        appendMessage('assistant', "New session started! How can I help?");

        // Optimistically show the new session immediately in the sidebar
        allSessions = allSessions.filter(session => session.session_id !== newSessionId);
        allSessions.unshift({
            session_id: newSessionId,
            title: 'Untitled',
            created_at: nowIso,
            updated_at: nowIso,
            message_count: 0
        });
        renderSessions(allSessions);
        updateSessionCount();

        // Create placeholder session file so it appears in sidebar immediately on refresh too
        try {
            const response = await fetch('api/create_session.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ session_id: newSessionId })
            });

            if (!response.ok) {
                throw new Error('Failed to create session placeholder');
            }
        } catch (err) {
            console.error('Failed to create session placeholder', err);
            showToast('New chat created locally but failed to persist session file', 'error');
        }

        showToast('New session started', 'info');
        fetchSessions();
    }

    newChatBtn.addEventListener('click', startNewChat);
    if (newChatSidebarBtn) {
        newChatSidebarBtn.addEventListener('click', startNewChat);
    }

    // =============================================
    // Session Sidebar
    // =============================================
    async function fetchSessions() {
        try {
            const response = await fetch('api/get_sessions.php', { cache: 'no-store' });
            if (!response.ok) throw new Error('Failed to fetch sessions');
            const data = await response.json();
            allSessions = data.sessions || [];
            renderSessions(allSessions);
            updateSessionCount();
        } catch (err) {
            console.error('Failed to fetch sessions', err);
            sessionList.innerHTML = '<div class="sidebar-empty">Could not load sessions</div>';
        }
    }

    function renderSessions(sessions) {
        if (sessions.length === 0) {
            sessionList.innerHTML = '<div class="sidebar-empty">No sessions yet</div>';
            return;
        }

        sessionList.innerHTML = '';
        sessions.forEach(session => {
            const item = document.createElement('div');
            item.className = 'session-item' + (session.session_id === currentSessionId ? ' active' : '');
            item.dataset.sessionId = session.session_id;

            const content = document.createElement('div');
            content.className = 'session-item-content';

            const title = document.createElement('div');
            title.className = 'session-item-title';
            title.textContent = session.title;

            const meta = document.createElement('div');
            meta.className = 'session-item-meta';
            const dateStr = session.updated_at ? new Date(session.updated_at).toLocaleDateString() : '';
            meta.textContent = dateStr + (session.message_count ? ' · ' + session.message_count + ' msgs' : '');

            content.appendChild(title);
            content.appendChild(meta);

            const deleteBtn = document.createElement('button');
            deleteBtn.className = 'session-delete-btn';
            deleteBtn.title = 'Delete session';
            deleteBtn.textContent = '🗑';
            deleteBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                deleteSession(session.session_id);
            });

            item.appendChild(content);
            item.appendChild(deleteBtn);

            item.addEventListener('click', () => {
                switchSession(session.session_id);
            });

            sessionList.appendChild(item);
        });
    }

    function updateSessionCount() {
        if (sessionCount) {
            sessionCount.textContent = allSessions.length + ' session' + (allSessions.length !== 1 ? 's' : '');
        }
    }

    function filterSessions(query) {
        const q = query.toLowerCase().trim();
        if (!q) {
            renderSessions(allSessions);
            return;
        }
        const filtered = allSessions.filter(s => s.title.toLowerCase().includes(q));
        renderSessions(filtered);
    }

    sessionSearch.addEventListener('input', (e) => {
        filterSessions(e.target.value);
    });

    async function switchSession(sessionId) {
        if (sessionId === currentSessionId) return;
        
        currentSessionId = sessionId;
        localStorage.setItem('certainthing_session_id', currentSessionId);
        messagesContainer.innerHTML = '';
        reasoningContainer.innerHTML = '';
        statusBadge.textContent = 'Idle';
        statusBadge.className = 'status-badge idle';

        await loadSession(sessionId);
        renderSessions(allSessions); // Update active state
    }

    async function deleteSession(sessionId) {
        if (!confirm('Delete this session?')) return;

        try {
            const response = await fetch('api/delete_session.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ session_id: sessionId })
            });

            if (!response.ok) {
                const errData = await response.json().catch(() => ({}));
                throw new Error(errData.error || 'Delete failed');
            }

            showToast('Session deleted', 'info');
            
            // If currently viewing this session, start a new one
            if (sessionId === currentSessionId) {
                startNewChat();
            }

            fetchSessions();
        } catch (err) {
            console.error('Delete error', err);
            showToast('Failed to delete session: ' + err.message, 'error');
        }
    }

    // =============================================
    // Session Loading
    // =============================================
    async function loadSession(sessionId) {
        try {
            const response = await fetch(`api/get_session.php?session_id=${encodeURIComponent(sessionId)}`, { cache: 'no-store' });
            if (!response.ok) throw new Error('Failed to load session');
            const data = await response.json();
            if (data.messages && data.messages.length > 0) {
                messagesContainer.innerHTML = '';
                data.messages.forEach(msg => {
                    appendMessage(msg.role, msg.content, msg.attachments || []);
                });
                refreshPreview();
            } else {
                // Empty session, show welcome
                appendMessage('assistant', "Hello! I'm CertainThing. I can help you build web projects. What are we building today?");
            }
        } catch (err) {
            console.error('Failed to load session', err);
            showToast('Failed to load previous session', 'error');
            appendMessage('assistant', "Hello! I'm CertainThing. I can help you build web projects. What are we building today?");
        }
    }

    // =============================================
    // Deploy Feature (F10)
    // =============================================
    async function deployLatestCode() {
        if (isDeploying) return;
        if (!deployBtn) return;

        deployBtn.disabled = true;
        isDeploying = true;

        try {
            // Find the last assistant message with code blocks
            const messages = messagesContainer.querySelectorAll('.message.assistant');
            if (messages.length === 0) {
                showToast('No assistant messages to deploy', 'error');
                return;
            }

            const lastMessage = messages[messages.length - 1];
            const codeBlocks = lastMessage.querySelectorAll('.code-container code, .code-pane code');
            
            if (codeBlocks.length === 0) {
                showToast('No code blocks found in the last response', 'error');
                return;
            }

            // Extract files from code blocks
            const files = [];
            const processedNames = new Set();

            codeBlocks.forEach(code => {
                let name = '';
                let lang = '';

                // Try to get filename from parent containers
                let parent = code.closest('.code-container, .code-pane');
                if (parent) {
                    name = parent.dataset.file || '';
                    lang = parent.dataset.lang || '';
                    
                    // For multi-file tabs, also check the tab header
                    if (!name) {
                        const multiFile = code.closest('.multi-file');
                        if (multiFile) {
                            const pane = code.closest('.code-pane');
                            if (pane) {
                                name = pane.dataset.file || '';
                                lang = pane.dataset.lang || '';
                            }
                        }
                    }
                }

                if (!name) {
                    // Fallback: generate a name based on language
                    if (!lang) {
                        // Try to detect language from class
                        const classMatch = code.className.match(/language-(\w+)/);
                        lang = classMatch ? classMatch[1] : 'txt';
                    }
                    const ext = lang === 'text' ? 'txt' : (lang === 'javascript' ? 'js' : lang);
                    let baseName = 'file.' + ext;
                    let counter = 1;
                    while (processedNames.has(baseName)) {
                        baseName = 'file_' + counter + '.' + ext;
                        counter++;
                    }
                    name = baseName;
                }

                if (!processedNames.has(name)) {
                    processedNames.add(name);
                    files.push({
                        name: name,
                        content: code.textContent
                    });
                }
            });

            if (files.length === 0) {
                showToast('No deployable files found', 'error');
                return;
            }

            showToast('Deploying ' + files.length + ' file(s)...', 'info');

            const response = await fetch('api/deploy.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    session_id: currentSessionId,
                    files: files
                })
            });

            if (!response.ok) {
                const errData = await response.json().catch(() => ({}));
                throw new Error(errData.error || 'Deploy failed');
            }

            const data = await response.json();

            // Add deploy result message
            const deployHtml = `
                <div class="deploy-result success">
                    <div class="deploy-result-title">🚀 Deployed Successfully</div>
                    <div class="deploy-result-files">
                        <strong>${data.count} file(s) deployed:</strong>
                        <ul>${data.files.map(f => '<li>' + escapeHtml(f) + '</li>').join('')}</ul>
                    </div>
                    <a href="${data.view_url}" target="_blank" class="deploy-result-view-link">👁 View Live App</a>
                </div>
            `;

            const msgDiv = document.createElement('div');
            msgDiv.className = 'message assistant';
            const bubble = document.createElement('div');
            bubble.className = 'bubble';
            bubble.innerHTML = deployHtml;
            msgDiv.appendChild(bubble);
            messagesContainer.appendChild(msgDiv);
            messagesContainer.scrollTop = messagesContainer.scrollHeight;

            showToast('🚀 App deployed! ' + data.count + ' files live', 'success');
        } catch (err) {
            console.error('Deploy error', err);
            showToast('Deploy failed: ' + err.message, 'error');
        } finally {
            deployBtn.disabled = false;
            isDeploying = false;
        }
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // =============================================
    // Message Rendering
    // =============================================
    function appendMessage(role, text, attachments = []) {
        const msgDiv = document.createElement('div');
        msgDiv.className = `message ${role}`;
        
        const bubble = document.createElement('div');
        bubble.className = 'bubble';
        
        if (attachments && attachments.length > 0) {
            const attContainer = document.createElement('div');
            attContainer.className = 'message-attachments';
            attachments.forEach(att => {
                if (att.is_image) {
                    const img = document.createElement('img');
                    img.src = att.content;
                    img.className = 'msg-image';
                    img.alt = att.name;
                    attContainer.appendChild(img);
                } else {
                    const fileBox = document.createElement('div');
                    fileBox.className = 'msg-file-box';
                    fileBox.innerHTML = `📄 <strong>${escapeHtml(att.name)}</strong>`;
                    attContainer.appendChild(fileBox);
                }
            });
            bubble.appendChild(attContainer);
        }

        const textDiv = document.createElement('div');
        textDiv.className = 'text-content';
        textDiv.innerHTML = formatText(text);
        bubble.appendChild(textDiv);
        
        msgDiv.appendChild(bubble);
        messagesContainer.appendChild(msgDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
        
        // Trigger highlight.js
        msgDiv.querySelectorAll('pre code').forEach((block) => {
            try {
                hljs.highlightElement(block);
            } catch (e) {
                // Ignore highlight errors
            }
        });

        if (role === 'assistant') {
            finalizeAssistantMessage(bubble);
        }
    }

    function formatText(text) {
        if (!text) return '';
        
        // Basic escaping
        let escaped = text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
        
        // Code blocks with optional filename
        // Matches: ```lang [filename] \n code ```
        const blocks = [];
        escaped = escaped.replace(/```(\w+)?(?:\s+\[([\w\.-]+)\])?\s?\n?([\s\S]*?)```/g, (match, lang, filename, code) => {
            const displayLang = lang || 'text';
            const displayFile = filename || '';
            const codeTrimmed = code.trim();
            const id = `__BLOCK_${blocks.length}__`;
            
            blocks.push(`
<div class="code-container" data-lang="${displayLang}" data-file="${escapeHtml(displayFile)}">
    <div class="code-header">
        <div class="code-title">
            <span class="language-badge">${displayLang}</span>
            ${displayFile ? `<span class="file-name">${escapeHtml(displayFile)}</span>` : ''}
        </div>
        <div class="code-actions">
            <button class="code-action-btn copy-btn" title="Copy to clipboard">Copy</button>
            <button class="code-action-btn download-btn" title="Download file">Download</button>
        </div>
    </div>
    <pre><code class="language-${displayLang}">${codeTrimmed}</code></pre>
</div>`);
            return id;
        });
        
        // Newlines (replacing \n with <br> in non-code parts)
        let html = escaped.replace(/\n/g, '<br>');
        
        // Restore blocks
        blocks.forEach((blockHtml, index) => {
            html = html.replace(`__BLOCK_${index}__`, () => blockHtml);
        });
        
        return sanitizeHtml(html);
    }

    function sanitizeHtml(html) {
        if (!html) return '';
        // Use DOMParser to fix malformed HTML and close tags
        const doc = new DOMParser().parseFromString(html, 'text/html');
        // Prevent script execution by removing script tags if any (though they should be escaped)
        const scripts = doc.querySelectorAll('script');
        scripts.forEach(s => s.remove());
        return doc.body.innerHTML;
    }

    function finalizeAssistantMessage(bubble) {
        const containers = Array.from(bubble.querySelectorAll('.code-container:not(.multi-file)'));
        if (containers.length === 0) return;

        let i = 0;
        while (i < containers.length) {
            let group = [containers[i]];
            let next = containers[i].nextSibling;
            
            // Look ahead for more code containers, skipping only <br> and empty text nodes
            while (next) {
                if (next.nodeType === Node.ELEMENT_NODE && next.classList.contains('code-container')) {
                    group.push(next);
                    next = next.nextSibling;
                } else if (next.nodeType === Node.ELEMENT_NODE && next.tagName === 'BR') {
                    next = next.nextSibling;
                } else if (next.nodeType === Node.TEXT_NODE && !next.textContent.trim()) {
                    next = next.nextSibling;
                } else {
                    break;
                }
            }

            if (group.length > 1) {
                // Create tabbed interface
                const tabContainer = document.createElement('div');
                tabContainer.className = 'code-container multi-file';
                
                const tabsHeader = document.createElement('div');
                tabsHeader.className = 'code-tabs';
                
                const panesContainer = document.createElement('div');
                panesContainer.className = 'code-panes';
                
                group.forEach((container, index) => {
                    const lang = container.dataset.lang;
                    const file = container.dataset.file || `file${index + 1}.${lang === 'text' ? 'txt' : lang}`;
                    
                    // Create tab
                    const tab = document.createElement('div');
                    tab.className = `code-tab ${index === 0 ? 'active' : ''}`;
                    tab.textContent = file;
                    tabsHeader.appendChild(tab);
                    
                    // Create pane
                    const pane = document.createElement('div');
                    pane.className = `code-pane ${index === 0 ? 'active' : ''}`;
                    pane.dataset.file = container.dataset.file;
                    pane.dataset.lang = container.dataset.lang;
                    
                    const paneHeader = container.querySelector('.code-header');
                    const pre = container.querySelector('pre');
                    
                    pane.appendChild(paneHeader);
                    pane.appendChild(pre);
                    panesContainer.appendChild(pane);
                    
                    // Handle tab switching
                    tab.addEventListener('click', () => {
                        tabContainer.querySelectorAll('.code-tab').forEach(t => t.classList.remove('active'));
                        tabContainer.querySelectorAll('.code-pane').forEach(p => p.classList.remove('active'));
                        tab.classList.add('active');
                        pane.classList.add('active');
                    });
                });
                
                tabContainer.appendChild(tabsHeader);
                tabContainer.appendChild(panesContainer);
                
                // Add Export ZIP button for the group
                const exportBtn = document.createElement('button');
                exportBtn.className = 'zip-export-btn';
                exportBtn.innerHTML = '<span>📦</span> Export all as ZIP';
                exportBtn.addEventListener('click', () => {
                    const files = group.map(c => ({
                        name: c.dataset.file || 'unnamed_file',
                        content: c.querySelector('code').textContent
                    }));
                    exportAsZip(files);
                });
                tabContainer.appendChild(exportBtn);

                // Add GitHub Deploy button
                const githubBtn = document.createElement('button');
                githubBtn.className = 'github-deploy-btn';
                githubBtn.innerHTML = '<span><svg height="16" viewBox="0 0 16 16" width="16" style="fill:currentColor;vertical-align:middle;margin-right:4px;"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"></path></svg></span> Push to GitHub';
                githubBtn.addEventListener('click', () => {
                    const files = group.map(c => ({
                        name: c.dataset.file || 'unnamed_file',
                        content: c.querySelector('code').textContent
                    }));
                    openGitHubModal(files);
                });
                tabContainer.appendChild(githubBtn);

                // Replace the first container in the group with the new tabContainer
                const first = group[0];
                first.parentNode.insertBefore(tabContainer, first);
                
                // Remove all original containers and intermediate <br>s
                group.forEach(c => {
                    let nextSibling = c.nextSibling;
                    while (nextSibling && nextSibling !== group[group.indexOf(c) + 1] && (nextSibling.tagName === 'BR' || (nextSibling.nodeType === Node.TEXT_NODE && !nextSibling.textContent.trim()))) {
                        let toRemove = nextSibling;
                        nextSibling = nextSibling.nextSibling;
                        toRemove.remove();
                    }
                    c.remove();
                });
                
                i += group.length;
            } else {
                i++;
            }
        }
        
        // Setup individual action buttons
        bubble.querySelectorAll('.copy-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const code = btn.closest('.code-container, .code-pane').querySelector('code').textContent;
                copyToClipboard(code);
                const originalText = btn.textContent;
                btn.textContent = 'Copied!';
                setTimeout(() => btn.textContent = originalText, 2000);
            });
        });
        
        bubble.querySelectorAll('.download-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const container = btn.closest('.code-container, .code-pane');
                const code = container.querySelector('code').textContent;
                const fileName = container.dataset.file || (container.closest('.code-container') ? container.closest('.code-container').dataset.file : null) || 'file.' + (container.dataset.lang || 'txt');
                downloadFile(fileName, code);
            });
        });

        // Add Push to GitHub for single code blocks not in a group
        bubble.querySelectorAll('.code-container:not(.multi-file) .code-actions').forEach(actions => {
            if (actions.querySelector('.github-push-btn')) return; // Avoid duplicates

            const githubBtn = document.createElement('button');
            githubBtn.className = 'code-action-btn github-push-btn';
            githubBtn.title = 'Push to GitHub';
            githubBtn.textContent = 'GitHub';
            githubBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                const container = actions.closest('.code-container');
                const code = container.querySelector('code').textContent;
                const fileName = container.dataset.file || 'file.' + (container.dataset.lang || 'txt');
                openGitHubModal([{ name: fileName, content: code }]);
            });
            actions.appendChild(githubBtn);
        });
    }

    async function copyToClipboard(text) {
        try {
            await navigator.clipboard.writeText(text);
        } catch (err) {
            console.error('Failed to copy', err);
            showToast('Failed to copy to clipboard', 'error');
        }
    }

    function downloadFile(name, content) {
        try {
            const blob = new Blob([content], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = name;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        } catch (err) {
            console.error('Download error', err);
            showToast('Failed to download file', 'error');
        }
    }

    async function exportAsZip(files) {
        showToast('Generating ZIP...', 'info');
        try {
            const response = await fetch('api/export.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ files })
            });
            
            if (!response.ok) throw new Error('Export failed');
            
            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            // Try to get filename from header
            const disposition = response.headers.get('Content-Disposition');
            let filename = `certainthing_export_${new Date().getTime()}.zip`;
            if (disposition && disposition.indexOf('attachment') !== -1) {
                const filenameRegex = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/;
                const matches = filenameRegex.exec(disposition);
                if (matches != null && matches[1]) { 
                    filename = matches[1].replace(/['"]/g, '');
                }
            }
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            showToast('Export successful', 'success');
        } catch (err) {
            console.error('Export error', err);
            showToast('Failed to export ZIP', 'error');
        }
    }

    // =============================================
    // GitHub Integration
    // =============================================
    function openGitHubModal(files) {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        
        const modal = document.createElement('div');
        modal.className = 'modal-content';
        modal.innerHTML = `
            <h3>Push to GitHub</h3>
            <p style="font-size: 0.85rem; color: var(--reasoning-text); margin-bottom: 1.5rem;">
                This will create a new commit with ${files.length} file(s).
            </p>
            <div class="form-group">
                <label>Repository (user/repo)</label>
                <input type="text" id="gh-repo" placeholder="e.g. octocat/hello-world" value="${localStorage.getItem('ct_gh_repo') || ''}">
            </div>
            <div class="form-group">
                <label>Personal Access Token (PAT)</label>
                <input type="password" id="gh-pat" placeholder="ghp_xxxxxxxxxxxx" value="${localStorage.getItem('ct_gh_pat') || ''}">
            </div>
            <div class="form-group">
                <label>Branch</label>
                <input type="text" id="gh-branch" value="main">
            </div>
            <div class="form-group">
                <label>Commit Message</label>
                <input type="text" id="gh-message" value="Deploy from CertainThing">
            </div>
            <div class="modal-footer">
                <button class="btn-small" id="gh-cancel">Cancel</button>
                <button class="btn-primary" id="gh-push" style="margin-top: 0; width: auto; padding: 0.5rem 1.5rem;">Push Commit</button>
            </div>
        `;
        
        overlay.appendChild(modal);
        document.body.appendChild(overlay);
        
        modal.querySelector('#gh-cancel').onclick = () => overlay.remove();
        
        modal.querySelector('#gh-push').onclick = async () => {
            const repo = modal.querySelector('#gh-repo').value.trim();
            const pat = modal.querySelector('#gh-pat').value.trim();
            const branch = modal.querySelector('#gh-branch').value.trim();
            const message = modal.querySelector('#gh-message').value.trim();
            
            if (!repo || !pat || !branch) {
                showToast('Please fill all required fields', 'error');
                return;
            }

            // Save to localStorage for convenience (PAT should be handled carefully but as per ticket we can store for duration or use caution)
            localStorage.setItem('ct_gh_repo', repo);
            localStorage.setItem('ct_gh_pat', pat);

            const pushBtn = modal.querySelector('#gh-push');
            pushBtn.disabled = true;
            pushBtn.textContent = 'Pushing...';

            try {
                const response = await fetch('api/github_push.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ repo, pat, branch, message, files })
                });

                const result = await response.json();
                if (result.success) {
                    showToast('Successfully pushed to GitHub!', 'success');
                    overlay.remove();
                    // Optional: add a message to the chat
                    appendMessage('assistant', `Successfully pushed to GitHub repository **${repo}**! [View Commit](${result.view_url})`);
                } else {
                    throw new Error(result.error || 'GitHub push failed');
                }
            } catch (err) {
                console.error('GitHub push error', err);
                showToast(err.message, 'error');
                pushBtn.disabled = false;
                pushBtn.textContent = 'Push Commit';
            }
        };
    }

    // =============================================
    // Preview Engine
    // =============================================
    function refreshPreview() {
        const assistantMessages = messagesContainer.querySelectorAll('.message.assistant');
        if (assistantMessages.length === 0) return;

        const lastMessage = assistantMessages[assistantMessages.length - 1];
        const codeBlocks = lastMessage.querySelectorAll('.code-container code, .code-pane code');
        
        if (codeBlocks.length === 0) return;

        const files = {};
        codeBlocks.forEach(code => {
            const container = code.closest('.code-container, .code-pane');
            const filename = container.dataset.file || '';
            const lang = container.dataset.lang || '';
            
            if (filename) {
                files[filename] = code.textContent;
            } else {
                if (lang === 'html') files['index.html'] = code.textContent;
                else if (lang === 'css') files['style.css'] = code.textContent;
                else if (lang === 'javascript') files['script.js'] = code.textContent;
            }
        });

        let html = files['index.html'] || '';
        if (!html) {
            const htmlFile = Object.keys(files).find(f => f.endsWith('.html'));
            if (htmlFile) html = files[htmlFile];
            else {
                for (const code of codeBlocks) {
                    if (code.closest('.code-container, .code-pane').dataset.lang === 'html') {
                        html = code.textContent;
                        break;
                    }
                }
            }
        }

        if (!html) {
            previewIframe.srcdoc = '<html><body><p style="color: #666; font-family: sans-serif; padding: 20px;">No HTML found to preview. Try asking the AI to build an HTML page.</p></body></html>';
            return;
        }

        // Inline CSS and JS
        Object.keys(files).forEach(filename => {
            if (filename.endsWith('.css')) {
                const css = files[filename];
                const regex = new RegExp(`<link[^>]+href=["'\\s]*${filename}["'\\s]*[^>]*>`, 'gi');
                if (html.match(regex)) {
                    html = html.replace(regex, `<style>${css}</style>`);
                } else if (html.includes('</head>')) {
                    html = html.replace('</head>', `<style>${css}</style></head>`);
                } else {
                    html += `<style>${css}</style>`;
                }
            } else if (filename.endsWith('.js')) {
                const js = files[filename];
                const regex = new RegExp(`<script[^>]+src=["'\\s]*${filename}["'\\s]*[^>]*><\/script>`, 'gi');
                if (html.match(regex)) {
                    html = html.replace(regex, `<script>${js}<\/script>`);
                } else if (html.includes('</body>')) {
                    html = html.replace('</body>', `<script>${js}<\/script></body>`);
                } else {
                    html += `<script>${js}</script>`;
                }
            }
        });

        previewIframe.srcdoc = html;
        
        // Update URL indicator
        const urlIndicator = document.querySelector('.preview-url');
        if (urlIndicator) {
            const activeFile = Object.keys(files).find(f => f.endsWith('.html')) || 'index.html';
            urlIndicator.textContent = 'sandbox://' + activeFile;
        }
    }

    // =============================================
    // Reasoning
    // =============================================
    function appendReasoning(text, isStreaming = false) {
        let lastStep = reasoningContainer.lastElementChild;
        
        if (isStreaming && lastStep && lastStep.classList.contains('reasoning-step') && !lastStep.classList.contains('is-old')) {
            lastStep.textContent += text;
        } else {
            // Mark existing steps as old if not streaming or if we want a new block
            reasoningContainer.querySelectorAll('.reasoning-step').forEach(step => {
                step.classList.add('is-old');
            });

            const step = document.createElement('div');
            step.className = 'reasoning-step';
            step.textContent = text;
            reasoningContainer.appendChild(step);
        }
        reasoningContainer.scrollTop = reasoningContainer.scrollHeight;
    }

    // =============================================
    // Send Message & SSE Streaming
    // =============================================
    async function sendMessage(message, attachments = [], urls = []) {
        statusBadge.textContent = 'Thinking...';
        statusBadge.className = 'status-badge thinking';
        reasoningContainer.innerHTML = '';

        const formData = new FormData();
        formData.append('message', message);
        formData.append('session_id', currentSessionId);
        if (attachments.length > 0) {
            formData.append('attachments', JSON.stringify(attachments));
        }
        if (urls.length > 0) {
            formData.append('urls', JSON.stringify(urls));
        }

        try {
            const response = await fetch('api/chat.php', {
                method: 'POST',
                body: formData,
                cache: 'no-store'
            });

            if (!response.ok) throw new Error('Network response was not ok');

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            
            let assistantMessageDiv = null;
            let assistantBubble = null;
            let fullText = '';
            let streamBuffer = '';
            let jsonBuffer = '';
            let pendingUsageText = '';
            let hasDoneReasoningStep = false;

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                streamBuffer += decoder.decode(value, { stream: true });
                const lines = streamBuffer.split('\n');
                streamBuffer = lines.pop();
                
                for (const line of lines) {
                    const trimmedLine = line.trim();
                    if (!trimmedLine || !trimmedLine.startsWith('data: ')) continue;

                    const content = trimmedLine.substring(6);
                    if (content === '[DONE]') {
                        jsonBuffer = '';
                        continue;
                    }

                    jsonBuffer += content;
                    try {
                        const data = JSON.parse(jsonBuffer);
                        jsonBuffer = ''; // Reset on success

                        if (data.type === 'reasoning') {
                            appendReasoning(data.text, data.streaming || false);

                            const isDoneStep = !data.streaming && /^done\.?$/i.test((data.text || '').trim());
                            if (isDoneStep) {
                                hasDoneReasoningStep = true;
                                if (pendingUsageText) {
                                    appendReasoning(pendingUsageText);
                                    pendingUsageText = '';
                                }
                            }
                        } else if (data.type === 'content') {
                            if (!assistantMessageDiv) {
                                assistantMessageDiv = document.createElement('div');
                                assistantMessageDiv.className = 'message assistant';
                                assistantBubble = document.createElement('div');
                                assistantBubble.className = 'bubble';
                                assistantMessageDiv.appendChild(assistantBubble);
                                messagesContainer.appendChild(assistantMessageDiv);
                            }
                            fullText += data.text;
                            assistantBubble.innerHTML = formatText(fullText);
                            messagesContainer.scrollTop = messagesContainer.scrollHeight;
                            
                            // During streaming, highlight new blocks but don't tabify yet
                            assistantBubble.querySelectorAll('pre code').forEach((block) => {
                                if (!block.classList.contains('hljs')) {
                                    try {
                                        hljs.highlightElement(block);
                                    } catch (e) {
                                        // Ignore highlight errors during streaming
                                    }
                                }
                            });
                        } else if (data.type === 'status') {
                            statusBadge.textContent = data.text;
                            const statusClass = data.text.toLowerCase().replace(/\./g, '').replace(/\s+/g, '-');
                            statusBadge.className = `status-badge ${statusClass}`;
                        } else if (data.type === 'usage') {
                            if (data.usage) {
                                const total = data.usage.total_tokens || 0;
                                const used = data.usage.completion_tokens || 0;
                                const prompt = data.usage.prompt_tokens || 0;
                                pendingUsageText = `Tokens used: ${total.toLocaleString()} (P: ${prompt.toLocaleString()}, C: ${used.toLocaleString()})`;

                                if (hasDoneReasoningStep && pendingUsageText) {
                                    appendReasoning(pendingUsageText);
                                    pendingUsageText = '';
                                }
                            }
                        } else if (data.type === 'error') {
                            showToast(data.text, 'error');
                            appendReasoning('Error: ' + data.text);
                            statusBadge.textContent = 'Error';
                            statusBadge.className = 'status-badge error';
                        }
                    } catch (e) {
                        // Incomplete JSON chunk or other error - keep buffering
                    }
                }
            }

            if (pendingUsageText) {
                appendReasoning(pendingUsageText);
            }
            
            // Finalize message: highlight and tabify
            if (assistantBubble) {
                assistantBubble.querySelectorAll('pre code').forEach((block) => {
                    try {
                        hljs.highlightElement(block);
                    } catch (e) {
                        // Ignore highlight errors
                    }
                });
                finalizeAssistantMessage(assistantBubble);
                
                // If it contains code, refresh the preview
                if (assistantBubble.querySelector('.code-container')) {
                    refreshPreview();
                }
            }

        } catch (err) {
            console.error('Chat error', err);
            showToast('Something went wrong. Please try again.', 'error');
            statusBadge.textContent = 'Error';
            statusBadge.className = 'status-badge error';
        }
    }

    // =============================================
    // Toast Notifications
    // =============================================
    function showToast(message, type = 'info') {
        const container = document.getElementById('toast-container');
        if (!container) return;
        
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;
        container.appendChild(toast);

        setTimeout(() => {
            toast.remove();
        }, 3500);
    }
});