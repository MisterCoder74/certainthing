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
    const sendBtn = document.getElementById('send-btn');
    const stopBtn = document.getElementById('stop-btn');
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
    const previewIframe = document.getElementById('preview-iframe');
    const refreshPreviewBtn = document.getElementById('refresh-preview-btn');
    const apiKeyBtn = document.getElementById('api-key-btn');
    const apiKeyModal = document.getElementById('api-key-modal');
    const apiKeyInput = document.getElementById('openai-api-key-input');
    const apiKeyStatus = document.getElementById('api-key-status');
    const apiKeySaveBtn = document.getElementById('api-key-save');
    const apiKeyCancelBtn = document.getElementById('api-key-cancel');
    const messageSpinner = document.getElementById('message-spinner');
    
    let currentSessionId = localStorage.getItem('certainthing_session_id') || 'sess_' + Date.now();
    localStorage.setItem('certainthing_session_id', currentSessionId);
    let attachmentQueue = [];
    let allSessions = [];
    let isDeploying = false;
    let isGenerating = false;
    let currentStreamController = null;
    let pastedImageCounter = 0;
    let debugMode = false;

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

    refreshPreviewBtn.addEventListener('click', () => {
        refreshPreview();
    });

    // =============================================
    // API Key Modal
    // =============================================
    if (apiKeyBtn && apiKeyModal && apiKeyCancelBtn && apiKeySaveBtn) {
        apiKeyBtn.addEventListener('click', openApiKeyModal);
        apiKeyCancelBtn.addEventListener('click', closeApiKeyModal);
        apiKeySaveBtn.addEventListener('click', saveApiKey);
        apiKeyModal.addEventListener('click', (e) => {
            if (e.target === apiKeyModal) closeApiKeyModal();
        });
    }

    // Close reasoning pane on escape
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;

        if (rightPane.classList.contains('show-reasoning')) {
                e.preventDefault();
            rightPane.classList.remove('show-reasoning');
        }

        if (apiKeyModal && !apiKeyModal.classList.contains('hidden')) {
            closeApiKeyModal();
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
    // Paste Images
    // =============================================    
    chatInput.addEventListener('paste', handlePaste);

function handlePaste(e) {
    const items = [...(e.clipboardData?.items || [])];
    const imageItems = items.filter(item => item.type.startsWith('image/'));
    
    if (imageItems.length === 0) return; // testo normale, lascia il default
    
    e.preventDefault();
    
    imageItems.forEach(item => {
        const file = item.getAsFile();
        if (!file) return;
        
        pastedImageCounter++;
        const name = `pasted_image_${String(pastedImageCounter).padStart(2, '0')}`;
        
        // Rinomina il file (i pasted files hanno nomi generici tipo "image.png")
        const ext = file.type.split('/')[1] || 'png';
        const renamedFile = new File([file], `${name}.${ext}`, { type: file.type });
        
        // Riusa la stessa logica del bottone graffetta
        uploadFile(renamedFile);
    });
}
    

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

        if (isGenerating) {
            showToast('Please wait for the current response or click Stop.', 'info');
            return;
        }

        const message = chatInput.value.trim();
        if (!message && attachmentQueue.length === 0) return;

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
    function startNewChat() {
        if (isGenerating && currentStreamController) {
            currentStreamController.abort();
        }

        // Reset debug mode
        debugMode = false;
        const debugBtn = document.getElementById('debug-btn');
        const langWrap = document.getElementById('debug-lang-wrap');
        const langSel  = document.getElementById('debug-lang');
        if (debugBtn) debugBtn.classList.remove('active');
        if (langWrap) langWrap.classList.remove('visible');
        if (langSel)  langSel.value = '';
        const chatInput = document.getElementById('chat-input');
        if (chatInput) chatInput.placeholder = 'Describe what you want to build...';

        currentSessionId = 'sess_' + Date.now();
        localStorage.setItem('certainthing_session_id', currentSessionId);
        messagesContainer.querySelectorAll('.message').forEach(msg => msg.remove());
        reasoningContainer.innerHTML = '';
		previewIframe.srcdoc = '<html><body><p style="color: #eee; background: rgba(0,0,0,.75);  font-family: sans-serif; padding: 20px;">No HTML found to preview. Try asking the AI to build an HTML page.</p></body></html>';            
        statusBadge.textContent = 'Idle';
        statusBadge.className = 'status-badge idle';

        appendMessage('assistant', "Hello! I'm CertainThing. I can help you build web projects. What are we building today?");
        hideGenerationUi();
        renderSessions(allSessions);
        showToast('New session started', 'info');
    }

    newChatBtn.addEventListener('click', startNewChat);
    if (newChatSidebarBtn) {
        newChatSidebarBtn.addEventListener('click', startNewChat);
    }

    if (stopBtn) {
        stopBtn.addEventListener('click', () => {
            if (currentStreamController) {
                stopBtn.disabled = true;
                stopBtn.textContent = 'Stopping...';
                currentStreamController.abort();
            }
        });
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
        messagesContainer.querySelectorAll('.message').forEach(msg => msg.remove());
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
                messagesContainer.querySelectorAll('.message').forEach(msg => msg.remove());
                data.messages.forEach(msg => {
                    appendMessage(msg.role, msg.content, msg.attachments || [], msg.timestamp || null);
                });
                refreshPreview();
            } else {
                messagesContainer.querySelectorAll('.message').forEach(msg => msg.remove());
                appendMessage('assistant', "Hello! I'm CertainThing. I can help you build web projects. What are we building today?");
            }
        } catch (err) {
            console.error('Failed to load session', err);
            showToast('Failed to load previous session', 'error');
            messagesContainer.querySelectorAll('.message').forEach(msg => msg.remove());
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

                    // Fallback: scan code content for a filename comment
                    name = extractFilenameFromCode(code.textContent);
                }
                if (!name) {
                    // Last resort: generate a name based on language
                   
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
            const bubble = createAssistantBubble('Deployment completed successfully.', new Date().toISOString());
            const textDiv = document.createElement('div');
            textDiv.className = 'text-content';
            textDiv.innerHTML = deployHtml;
            bubble.appendChild(textDiv);
            msgDiv.appendChild(bubble);
            appendMessageNode(msgDiv);

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
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function formatClock(timestamp) {
        const date = timestamp ? new Date(timestamp) : new Date();
        if (Number.isNaN(date.getTime())) return '--:--';
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
    }

    function appendMessageNode(node) {
        if (messageSpinner && messageSpinner.parentNode === messagesContainer) {
            messagesContainer.insertBefore(node, messageSpinner);
        } else {
            messagesContainer.appendChild(node);
        }
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    function createAssistantMeta(bubble, timestamp, rawText) {
        const meta = document.createElement('div');
        meta.className = 'assistant-msg-meta';

        const brand = document.createElement('div');
        brand.className = 'assistant-msg-brand';
        brand.textContent = '✦ CertainThing';

        const right = document.createElement('div');
        right.className = 'assistant-meta-right';

        const copyBtn = document.createElement('button');
        copyBtn.type = 'button';
        copyBtn.className = 'assistant-copy-btn';
        copyBtn.textContent = 'Copy';
        copyBtn.addEventListener('click', async () => {
            const textToCopy = bubble.dataset.rawText || rawText || bubble.querySelector('.text-content')?.innerText || '';
            await copyToClipboard(textToCopy);
            const oldText = copyBtn.textContent;
            copyBtn.textContent = 'Copied';
            setTimeout(() => {
                copyBtn.textContent = oldText;
            }, 1500);
        });

        const time = document.createElement('span');
        time.className = 'assistant-msg-time';
        time.textContent = formatClock(timestamp);

        right.appendChild(copyBtn);
        right.appendChild(time);
        meta.appendChild(brand);
        meta.appendChild(right);

        return meta;
    }

    function createAssistantBubble(rawText = '', timestamp = null) {
        const bubble = document.createElement('div');
        bubble.className = 'bubble';
        bubble.dataset.rawText = rawText || '';
        bubble.appendChild(createAssistantMeta(bubble, timestamp, rawText));
        return bubble;
    }

    function normalizeLanguage(language) {
        const lang = (language || '').trim().toLowerCase();
        if (!lang) return 'text';

        const aliases = {
            js: 'javascript',
            ts: 'typescript',
            py: 'python',
            sh: 'bash',
            shell: 'bash',
            md: 'markdown',
            yml: 'yaml'
        };

        return aliases[lang] || lang.replace(/[^a-z0-9_+\-#]/g, '');
    }

    function detectLanguageFromFilename(filename) {
        if (!filename || !filename.includes('.')) return '';
        const ext = filename.split('.').pop().toLowerCase();
        const map = {
            html: 'html',
            htm: 'html',
            css: 'css',
            js: 'javascript',
            mjs: 'javascript',
            cjs: 'javascript',
            ts: 'typescript',
            jsx: 'javascript',
            tsx: 'typescript',
            php: 'php',
            py: 'python',
            json: 'json',
            md: 'markdown',
            txt: 'text'
        };
        return map[ext] || '';
    }
        
	// Fallback: scan first 5 lines of code content for a filename comment
    // e.g. "// auth.php", "/* styles.css */", "<!-- index.html -->", "# config.yaml"
    function extractFilenameFromCode(code) {
        if (!code) return '';
        const lines = (code + '').split('\n').slice(0, 5);
        for (const line of lines) {
            const t = line.trim();
            // Supports: // file.ext, // file.ext - description, /* file.ext */, <!-- file.ext -->, # file.ext
            const m = t.match(/^(?:\/\/|\/\*|<!--|#)\s*([\w][\w.\-]*\.[a-zA-Z0-9]{1,8})\b/);
            if (m && m[1] && !m[1].startsWith('http')) return m[1];
        }
        return '';
    }        

    function parseFenceInfo(infoRaw) {
        const info = (infoRaw || '').trim();
        let lang = 'text';
        let filename = '';

        if (!info) return { lang, filename };

        const bracket = info.match(/^([^\s]+)?\s*\[([^\]]+)\]\s*$/);
        if (bracket) {
            lang = normalizeLanguage(bracket[1] || 'text');
            filename = (bracket[2] || '').trim();
            return { lang, filename };
        }

        const parts = info.split(/\s+/).filter(Boolean);
        if (parts.length === 1) {
            if (/[\/\\]/.test(parts[0]) || /\.[A-Za-z0-9]+$/.test(parts[0])) {
                filename = parts[0];
                lang = detectLanguageFromFilename(filename) || 'text';
            } else {
                lang = normalizeLanguage(parts[0]);
            }
            return { lang, filename };
        }

        lang = normalizeLanguage(parts[0]);
        filename = parts.slice(1).join(' ').trim().replace(/^\[|\]$/g, '');
        if (!lang || lang === 'text') {
            lang = detectLanguageFromFilename(filename) || 'text';
        }

        return { lang, filename };
    }

    function renderCodeBlock(lang, filename, code) {
        const safeLang = normalizeLanguage(lang || 'text');
        const safeFilename = filename ? escapeHtml(filename) : '';
        const codeHtml = escapeHtml(code);

        return `
<div class="code-container" data-lang="${safeLang}" data-file="${safeFilename}">
    <div class="code-header">
        <div class="code-title">
            <span class="language-badge">${safeLang}</span>
            ${safeFilename ? `<span class="file-name">${safeFilename}</span>` : ''}
        </div>
        <div class="code-actions">
            <button class="code-action-btn copy-btn" title="Copy to clipboard">Copy</button>
            <button class="code-action-btn download-btn" title="Download file">Download</button>
        </div>
    </div>
    <pre><code class="language-${safeLang}">${codeHtml}</code></pre>
</div>`;
    }

    // =============================================
    // Message Rendering
    // =============================================
    function appendMessage(role, text, attachments = [], timestamp = null) {
        const msgDiv = document.createElement('div');
        msgDiv.className = `message ${role}`;

        const bubble = role === 'assistant'
            ? createAssistantBubble(text, timestamp)
            : document.createElement('div');

        if (role !== 'assistant') {
            bubble.className = 'bubble';
        }

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
        appendMessageNode(msgDiv);

        msgDiv.querySelectorAll('pre code').forEach((block) => {
            try {
                hljs.highlightElement(block);
            } catch (e) {
                // Ignore highlight errors
            }
        });

        if (role === 'assistant') {
            bubble.dataset.rawText = text || '';
            finalizeAssistantMessage(bubble);
        }
    }

    function formatText(text) {
        if (!text) return '';

        // Ensure the input is a string and handle potential incomplete code blocks
        let input = String(text).replace(/\r\n/g, '\n');
        
        // If there's an unclosed code block, close it to prevent rendering issues
        const openFences = (input.match(/```/g) || []).length;
        if (openFences % 2 !== 0) {
            input += '\n```';
        }

        const fenceRegex = /```([^\n`]*)\n?([\s\S]*?)```/g;

        let html = '';
        let lastIndex = 0;
        let match;

        while ((match = fenceRegex.exec(input)) !== null) {
            const before = input.slice(lastIndex, match.index);
            if (before) {
                html += escapeHtml(before).replace(/\n/g, '<br>');
            }

            const info = parseFenceInfo(match[1]);
            // Fallback: if no filename in fence info, scan the code content itself
            if (!info.filename) {
                info.filename = extractFilenameFromCode(match[2]);
            }
            html += renderCodeBlock(info.lang, info.filename, match[2]);
            lastIndex = fenceRegex.lastIndex;
        }

        const remaining = input.slice(lastIndex);
        if (remaining) {
            html += escapeHtml(remaining).replace(/\n/g, '<br>');
        }

        return html;
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
                    const panes = tabContainer.querySelectorAll('.code-pane');
                    const files = Array.from(panes)
                        .filter(p => p.querySelector('code'))
                        .map(p => ({
                            name: p.dataset.file || 'unnamed_file',
                            content: p.querySelector('code').textContent
                        }));
                    if (files.length === 0) return;
                    exportAsZip(files);
                });
                tabContainer.appendChild(exportBtn);

                // Add GitHub Deploy button
                const githubBtn = document.createElement('button');
                githubBtn.className = 'github-deploy-btn';
                githubBtn.innerHTML = '<span><svg height="16" viewBox="0 0 16 16" width="16" style="fill:currentColor;vertical-align:middle;margin-right:4px;"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"></path></svg></span> Push to GitHub';    
                githubBtn.addEventListener('click', () => {
                    const panes = tabContainer.querySelectorAll('.code-pane');
                    const files = Array.from(panes)
                        .filter(p => p.querySelector('code'))
                        .map(p => ({
                            name: p.dataset.file || 'unnamed_file',
                            content: p.querySelector('code').textContent
                        }));
                    if (files.length === 0) return;
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
            //const branch = modal.querySelector('#gh-branch').value.trim();
            const message = modal.querySelector('#gh-message').value.trim();
            
            if (!repo || !pat ) {
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
                    body: JSON.stringify({ repo, pat, message, files })
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
            previewIframe.srcdoc = '<html><body><p style="color: #666; background: rgba(0,0,0,.35);  font-family: sans-serif; padding: 20px;">No HTML found to preview. Try asking the AI to build an HTML page.</p></body></html>';
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
    function appendReasoning(stepData, isStreaming = false) {
        const payload = typeof stepData === 'string' ? { text: stepData } : (stepData || {});
        const text = payload.text || '';
        const action = (payload.action || 'event').replace(/_/g, ' ');
        const resource = payload.resource || '';
        const model = payload.model || '';
        const timestamp = payload.timestamp || new Date().toISOString();

        const lastStep = reasoningContainer.lastElementChild;
        const canAppendToCurrent = Boolean(
            isStreaming &&
            lastStep &&
            lastStep.classList.contains('reasoning-step') &&
            lastStep.dataset.streaming === 'true' &&
            lastStep.dataset.action === action &&
            (lastStep.dataset.resource || '') === resource &&
            (lastStep.dataset.model || '') === model
        );

        if (canAppendToCurrent) {
            const main = lastStep.querySelector('.reasoning-step-main');
            if (main) {
                main.innerHTML += text;
            }
            const timeEl = lastStep.querySelector('.reasoning-time');
            if (timeEl) {
                timeEl.textContent = formatClock(timestamp);
            }
        } else {
            reasoningContainer.querySelectorAll('.reasoning-step').forEach(step => {
                step.classList.add('is-old');
            });

            const step = document.createElement('div');
            step.className = 'reasoning-step';
            step.dataset.streaming = isStreaming ? 'true' : 'false';
            step.dataset.action = action;
            step.dataset.resource = resource;
            step.dataset.model = model;

            const main = document.createElement('div');
            main.className = 'reasoning-step-main';
            main.innerHTML = text || action;

            const meta = document.createElement('div');
            meta.className = 'reasoning-step-meta';

            const actionPill = document.createElement('span');
            actionPill.className = 'reasoning-pill action';
            actionPill.textContent = action;
            meta.appendChild(actionPill);

            if (resource) {
                const resourcePill = document.createElement('span');
                resourcePill.className = 'reasoning-pill resource';
                resourcePill.textContent = resource;
                meta.appendChild(resourcePill);
            }

            if (model) {
                const modelPill = document.createElement('span');
                modelPill.className = 'reasoning-pill model';
                modelPill.textContent = model;
                meta.appendChild(modelPill);
            }

            const timePill = document.createElement('span');
            timePill.className = 'reasoning-pill reasoning-time';
            timePill.textContent = formatClock(timestamp);
            meta.appendChild(timePill);

            step.appendChild(main);
            step.appendChild(meta);
            reasoningContainer.appendChild(step);
        }

        reasoningContainer.scrollTop = reasoningContainer.scrollHeight;
    }

    function showGenerationUi() {
        isGenerating = true;

        if (messageSpinner) {
            messageSpinner.classList.add('active');
            messageSpinner.setAttribute('aria-hidden', 'false');
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }

        if (stopBtn) {
            stopBtn.classList.add('active');
            stopBtn.disabled = false;
            stopBtn.textContent = 'Stop';
        }

        if (sendBtn) {
            sendBtn.disabled = true;
        }
    }

    function hideGenerationUi() {
        isGenerating = false;

        if (messageSpinner) {
            messageSpinner.classList.remove('active');
            messageSpinner.setAttribute('aria-hidden', 'true');
        }

        if (stopBtn) {
            stopBtn.classList.remove('active');
            stopBtn.disabled = false;
            stopBtn.textContent = 'Stop';
        }

        if (sendBtn) {
            sendBtn.disabled = false;
        }
    }

    function setApiKeyStatus(message, isError = false) {
        if (!apiKeyStatus) return;
        apiKeyStatus.textContent = message || '';
        apiKeyStatus.style.color = isError ? 'var(--error-color)' : 'var(--reasoning-text)';
    }

async function openApiKeyModal() {
    if (!apiKeyModal) return;
    apiKeyModal.classList.remove('hidden');
    if (apiKeyInput) apiKeyInput.value = '';

    setApiKeyStatus('Checking current key...');

    try {
        const response = await fetch('api/save_api_key.php', { cache: 'no-store' });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'Failed to load key status');

        // Stato testuale (logica esistente)
        if (data.configured) {
            setApiKeyStatus('API key configured.');
        } else {
            setApiKeyStatus('No API key saved. You can save one now.');
        }

        // Key preview + trial warning (nuovo)
        const statusBox  = document.getElementById('key-status-info');
        const warningBox = document.getElementById('key-shared-warning');

        if (statusBox) {
            if (data.configured && data.key_preview) {
                const sourceLabels = {
                    user:   '🔑 Chiave personale',
                    shared: '🔑 Chiave condivisa (trial)',
                    env:    '🔑 Chiave server',
                };
                const label = sourceLabels[data.source] || '🔑 Chiave configurata';
                statusBox.innerHTML = `${label} &nbsp;·&nbsp; <code>${data.key_preview}</code>`;
                statusBox.style.display = 'block';
            } else {
                statusBox.style.display = 'none';
            }
        }

        if (warningBox) {
            warningBox.style.display = data.show_shared_warning ? 'block' : 'none';
        }

        const maskedField = document.getElementById('current-api-key');
        if (maskedField) maskedField.value = data.masked_key || '';

    } catch (err) {
        console.error(err);
        setApiKeyStatus('Failed to read current key status.', true);
    }
}
 

    function closeApiKeyModal() {
        if (!apiKeyModal) return;
        apiKeyModal.classList.add('hidden');
        if (apiKeyInput) {
            apiKeyInput.value = '';
        }
        setApiKeyStatus('');
    }

    async function saveApiKey() {
        if (!apiKeyInput || !apiKeySaveBtn) return;

        const apiKey = apiKeyInput.value.trim();
        const oldText = apiKeySaveBtn.textContent;
        apiKeySaveBtn.disabled = true;
        apiKeySaveBtn.textContent = 'Saving...';

        try {
            const response = await fetch('api/save_api_key.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ api_key: apiKey })
            });

            const data = await response.json();
            if (!response.ok) {
                throw new Error(data.error || 'Failed to save API key');
            }

            setApiKeyStatus(data.configured
                ? `Saved successfully.`
                : 'Cleared saved key. Using environment fallback.');
            showToast('API key settings updated', 'success');

            setTimeout(() => {
                closeApiKeyModal();
            }, 500);
        } catch (err) {
            console.error(err);
            setApiKeyStatus(err.message || 'Failed to save API key', true);
            showToast('Failed to save API key', 'error');
        } finally {
            apiKeySaveBtn.disabled = false;
            apiKeySaveBtn.textContent = oldText;
        }
    }

    function applyStatus(statusText) {
        const normalized = (statusText || 'idle').toString();
        const statusClass = normalized.toLowerCase().replace(/\./g, '').replace(/\s+/g, '-');
        statusBadge.textContent = normalized;
        statusBadge.className = `status-badge ${statusClass}`;
    }

    // =============================================
    // Send Message & SSE Streaming
    // =============================================
    async function sendMessage(message, attachments = [], urls = []) {
        applyStatus('Thinking');
        //reasoningContainer.innerHTML = '';
        showGenerationUi();

        const formData = new FormData();
        formData.append('message', message);
        formData.append('session_id', currentSessionId);
        if (attachments.length > 0) {
            formData.append('attachments', JSON.stringify(attachments));
        }
        if (urls.length > 0) {
            formData.append('urls', JSON.stringify(urls));
        }
        if (debugMode) {
            formData.append('debug_mode', '1');
            const langSel = document.getElementById('debug-lang');
            if (langSel && langSel.value) {
                formData.append('debug_language', langSel.value);
            }
        }

        currentStreamController = new AbortController();

        let assistantMessageDiv = null;
        let assistantBubble = null;
        let assistantTextDiv = null;
        let fullText = '';
        let doneStepSeen = false;
        let pendingUsageStep = null;

        try {
            const response = await fetch('api/chat.php', {
                method: 'POST',
                body: formData,
                cache: 'no-store',
                signal: currentStreamController.signal
            });

            if (!response.ok) {
                throw new Error('Network response was not ok');
            }

            if (!response.body) {
                throw new Error('Empty streaming response');
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let streamBuffer = '';

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                streamBuffer += decoder.decode(value, { stream: true });

                while (true) {
                    const separatorIndex = streamBuffer.indexOf('\n\n');
                    if (separatorIndex === -1) break;

                    const rawEvent = streamBuffer.slice(0, separatorIndex);
                    streamBuffer = streamBuffer.slice(separatorIndex + 2);

                    const payload = rawEvent
                        .split('\n')
                        .filter(line => line.startsWith('data:'))
                        .map(line => line.slice(5).trim())
                        .join('');

                    if (!payload || payload === '[DONE]') {
                        continue;
                    }

                    let data;
                    try {
                        data = JSON.parse(payload);
                    } catch (err) {
                        continue;
                    }

                    if (data.type === 'reasoning') {
                        appendReasoning(data, Boolean(data.streaming));

                        const isDoneStep = !data.streaming && (
                            String(data.action || '').toLowerCase() === 'done' ||
                            /^done\.?$/i.test(String(data.text || '').trim())
                        );

                        if (isDoneStep) {
                            doneStepSeen = true;
                            if (pendingUsageStep) {
                                appendReasoning(pendingUsageStep);
                                pendingUsageStep = null;
                            }
                        }
                    } else if (data.type === 'content') {
                        if (!assistantMessageDiv) {
                            assistantMessageDiv = document.createElement('div');
                            assistantMessageDiv.className = 'message assistant';
                            assistantBubble = createAssistantBubble('', new Date().toISOString());
                            assistantTextDiv = document.createElement('div');
                            assistantTextDiv.className = 'text-content';
                            assistantBubble.appendChild(assistantTextDiv);
                            assistantMessageDiv.appendChild(assistantBubble);
                            appendMessageNode(assistantMessageDiv);
                        }

                        fullText += data.text || '';
                        assistantBubble.dataset.rawText = fullText;
                        assistantTextDiv.innerHTML = formatText(fullText);
                        messagesContainer.scrollTop = messagesContainer.scrollHeight;

                    } else if (data.type === 'status') {
                        applyStatus(data.text || 'Working');
                    } else if (data.type === 'usage') {
                        if (data.usage) {
                            const total = data.usage.total_tokens || 0;
                            const completion = data.usage.completion_tokens || 0;
                            const prompt = data.usage.prompt_tokens || 0;

                            pendingUsageStep = {
                                text: `Tokens used: ${total.toLocaleString()} (Prompt: ${prompt.toLocaleString()}, Completion: ${completion.toLocaleString()})`,
                                action: 'token_usage',
                                resource: 'OpenAI usage',
                                model: data.model || '',
                                timestamp: data.timestamp || new Date().toISOString()
                            };

                            if (doneStepSeen) {
                                appendReasoning(pendingUsageStep);
                                pendingUsageStep = null;
                            }
                        }
                    } else if (data.type === 'error') {
                        const errorMessage = data.text || 'Unknown error';
                        showToast(errorMessage, 'error');
                        appendReasoning({
                            text: 'Error: ' + errorMessage,
                            action: 'error',
                            resource: 'stream',
                            timestamp: new Date().toISOString()
                        });
                        applyStatus('Error');
                    }
                }
            }

            if (pendingUsageStep) {
                appendReasoning(pendingUsageStep);
            }

            if (assistantBubble) {
                assistantBubble.querySelectorAll('pre code').forEach((block) => {
                    try {
                        hljs.highlightElement(block);
                    } catch (e) {
                        // Ignore highlight errors
                    }
                });
                finalizeAssistantMessage(assistantBubble);

                if (assistantBubble.querySelector('.code-container')) {
                    refreshPreview();
                }
            }

            fetchSessions();
        } catch (err) {
            if (err.name === 'AbortError') {
                appendReasoning({
                    text: 'Generation stopped by user.',
                    action: 'cancelled',
                    resource: 'stream',
                    timestamp: new Date().toISOString()
                });
                applyStatus('Stopped');
                showToast('Generation stopped', 'info');
            } else {
                console.error('Chat error', err);
                showToast('Something went wrong. Please try again.', 'error');
                applyStatus('Error');
            }
        } finally {
            currentStreamController = null;
            hideGenerationUi();
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

    // ─── Debug Mode ──────────────────────────────────────────
    (function initDebugMode() {
        const inputActions = document.querySelector('.input-actions');
        if (!inputActions) return;

        const langWrap = document.createElement('div');
        langWrap.id = 'debug-lang-wrap';
        langWrap.innerHTML = `
            <select id="debug-lang" title="Code language">
                <option value="">Auto-detect</option>
                <option value="javascript">JavaScript</option>
                <option value="php">PHP</option>
                <option value="html">HTML</option>
                <option value="css">CSS</option>
                <option value="python">Python</option>
                <option value="java">Java</option>
                <option value="sql">SQL</option>
                <option value="typescript">TypeScript</option>
            </select>`;

        const stopBtn = document.getElementById('stop-btn');
        const debugBtn = document.createElement('button');
        debugBtn.type = 'button';
        debugBtn.id = 'debug-btn';
        debugBtn.title = 'Debug mode: analyze existing code for bugs';
        debugBtn.textContent = '🐛';

        inputActions.insertBefore(debugBtn, stopBtn);

        const inputArea = document.querySelector('.input-area');
        if (inputArea) inputArea.insertBefore(langWrap, inputArea.querySelector('form'));

        debugBtn.addEventListener('click', () => {
            debugMode = !debugMode;
            debugBtn.classList.toggle('active', debugMode);
            langWrap.classList.toggle('visible', debugMode);

            const chatInput = document.getElementById('chat-input');
            if (chatInput) {
                chatInput.placeholder = debugMode
                    ? 'Paste your code here to debug...'
                    : 'Describe what you want to build...';
            }
            if (!debugMode) {
                const langSel = document.getElementById('debug-lang');
                if (langSel) langSel.value = '';
            }
        });
    })();
});

(function() {
    let libraryData = null;

    // Crea il modale (inserito nel DOM una sola volta)
    const modal = document.createElement('div');
    modal.id = 'promptLibraryModal';
    modal.innerHTML = `
        <div class="pl-overlay"></div>
        <div class="pl-container">
            <div class="pl-header">
                <h2>⚡ Prompt Library</h2>
                <input type="text" id="plSearch" placeholder="Search prompts..." autocomplete="off" />
                <button class="pl-close">&times;</button>
            </div>
            <div class="pl-body" id="plBody"></div>
        </div>
    `;
    document.body.appendChild(modal);

    // CSS del modale
    const style = document.createElement('style');
    style.textContent = `
        #promptLibraryModal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; z-index:10000; }
        #promptLibraryModal.active { display:flex; align-items:center; justify-content:center; }
        .pl-overlay { position:absolute; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); }
        .pl-container { position:relative; background:#161b22; border:1px solid #30363d; border-radius:12px; width:90%; max-width:640px; max-height:75vh; display:flex; flex-direction:column; box-shadow:0 16px 48px rgba(0,0,0,0.4); }
        .pl-header { display:flex; align-items:center; gap:12px; padding:16px 20px; border-bottom:1px solid #30363d; flex-wrap:wrap; }
        .pl-header h2 { margin:0; font-size:18px; color:#e6edf3; white-space:nowrap; }
        #plSearch { flex:1; min-width:160px; padding:8px 12px; background:#0d1117; border:1px solid #30363d; border-radius:6px; color:#c9d1d9; font-size:14px; outline:none; }
        #plSearch:focus { border-color:#58a6ff; }
        .pl-close { background:none; border:none; color:#8b949e; font-size:24px; cursor:pointer; padding:0 4px; line-height:1; }
        .pl-close:hover { color:#e6edf3; }
        .pl-body { overflow-y:auto; padding:12px 20px 20px; }
        .pl-category { margin-bottom:16px; }
        .pl-category-title { font-size:15px; color:#58a6ff; margin-bottom:8px; cursor:pointer; user-select:none; }
        .pl-category-title:hover { text-decoration:underline; }
        .pl-prompt-card { background:#0d1117; border:1px solid #30363d; border-radius:8px; padding:12px 14px; margin-bottom:8px; cursor:pointer; transition:border-color 0.15s, background 0.15s; }
        .pl-prompt-card:hover { border-color:#58a6ff; background:#1c2333; }
        .pl-prompt-card .pl-title { font-size:14px; font-weight:600; color:#e6edf3; margin-bottom:4px; }
        .pl-prompt-card .pl-text { font-size:12px; color:#8b949e; line-height:1.4; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
        .pl-empty { text-align:center; color:#8b949e; padding:32px 0; font-size:14px; }
    `;
    document.head.appendChild(style);

    // Funzioni
    function openLibrary() {
        if (!libraryData) {
            fetch('prompts_library.json')
                .then(r => r.json())
                .then(data => { libraryData = data; renderCategories(''); })
                .catch(() => { document.getElementById('plBody').innerHTML = '<div class="pl-empty">Failed to load prompts.</div>'; });
        } else {
            renderCategories('');
        }
        document.getElementById('plSearch').value = '';
        modal.classList.add('active');
        setTimeout(() => document.getElementById('plSearch').focus(), 100);
    }

    function closeLibrary() {
        modal.classList.remove('active');
    }

    function renderCategories(filter) {
        const body = document.getElementById('plBody');
        const q = filter.toLowerCase().trim();
        let html = '';
        let totalMatches = 0;

        libraryData.categories.forEach(cat => {
            const prompts = cat.prompts.filter(p =>
                !q || p.title.toLowerCase().includes(q) || p.text.toLowerCase().includes(q) || cat.name.toLowerCase().includes(q)
            );
            if (prompts.length === 0) return;
            totalMatches += prompts.length;

            html += `<div class="pl-category">`;
            html += `<div class="pl-category-title">${cat.icon} ${cat.name}</div>`;
            prompts.forEach(p => {
                html += `<div class="pl-prompt-card" data-prompt="${escapeAttr(p.text)}">
                    <div class="pl-title">${highlight(p.title, q)}</div>
                    <div class="pl-text">${highlight(p.text, q)}</div>
                </div>`;
            });
            html += `</div>`;
        });

        if (totalMatches === 0) {
            html = '<div class="pl-empty">No prompts found.</div>';
        }
        body.innerHTML = html;
    }

    function escapeAttr(str) {
        return str.replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function highlight(text, q) {
        if (!q) return text;
        const escaped = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return text.replace(new RegExp(`(${escaped})`, 'gi'), '<mark style="background:#58a6ff33;color:#e6edf3;border-radius:2px;padding:0 1px;">$1</mark>');
    }

    function selectPrompt(promptText) {
        const input = document.getElementById('chat-input'); 

        if (input) {
            input.value = promptText;
            input.focus();
            // Trigger input event per aggiornare eventuali binding
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
        closeLibrary();
    }

    // Event listeners
    document.getElementById('promptLibraryBtn').addEventListener('click', openLibrary);
    modal.querySelector('.pl-overlay').addEventListener('click', closeLibrary);
    modal.querySelector('.pl-close').addEventListener('click', closeLibrary);
    document.getElementById('plSearch').addEventListener('input', function() {
        renderCategories(this.value);
    });
    document.getElementById('plBody').addEventListener('click', function(e) {
        const card = e.target.closest('.pl-prompt-card');
        if (card) selectPrompt(card.dataset.prompt);
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.classList.contains('active')) closeLibrary();
    });
})();

document.addEventListener('click', function(e) {
    var popup = document.getElementById('userInfoPopup');
    if (popup && !e.target.closest('.user-info-wrapper')) {
        popup.classList.remove('show');
    }
});
