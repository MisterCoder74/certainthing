/**
 * CertainThing App JS
 */

document.addEventListener('DOMContentLoaded', () => {
    const chatForm = document.getElementById('chat-form');
    const chatInput = document.getElementById('chat-input');
    const messagesContainer = document.getElementById('messages-container');
    const reasoningContainer = document.getElementById('reasoning-container');
    const statusBadge = document.getElementById('status-badge');
    const newChatBtn = document.getElementById('new-chat-btn');
    
    let currentSessionId = localStorage.getItem('certainthing_session_id') || 'sess_' + Date.now();
    localStorage.setItem('certainthing_session_id', currentSessionId);

    // Initial Load
    loadSession(currentSessionId);

    // Auto-resize textarea
    chatInput.addEventListener('input', () => {
        chatInput.style.height = 'auto';
        chatInput.style.height = (chatInput.scrollHeight) + 'px';
    });

    chatForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const message = chatInput.value.trim();
        if (!message) return;

        chatInput.value = '';
        chatInput.style.height = 'auto';
        
        appendMessage('user', message);
        await sendMessage(message);
    });

    newChatBtn.addEventListener('click', () => {
        currentSessionId = 'sess_' + Date.now();
        localStorage.setItem('certainthing_session_id', currentSessionId);
        messagesContainer.innerHTML = '';
        reasoningContainer.innerHTML = '';
        appendMessage('assistant', "New session started! How can I help?");
        showToast('New session started', 'info');
    });

    async function loadSession(sessionId) {
        try {
            const response = await fetch(`api/get_session.php?session_id=${sessionId}`, { cache: 'no-store' });
            const data = await response.json();
            if (data.messages && data.messages.length > 0) {
                messagesContainer.innerHTML = '';
                data.messages.forEach(msg => {
                    appendMessage(msg.role, msg.content);
                });
            }
        } catch (err) {
            console.error('Failed to load session', err);
            showToast('Failed to load previous session', 'error');
        }
    }

    function appendMessage(role, text) {
        const msgDiv = document.createElement('div');
        msgDiv.className = `message ${role}`;
        
        const bubble = document.createElement('div');
        bubble.className = 'bubble';
        
        // Simple Markdown-like formatting (newlines and code blocks)
        bubble.innerHTML = formatText(text);
        
        msgDiv.appendChild(bubble);
        messagesContainer.appendChild(msgDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
        
        // Trigger highlight.js
        msgDiv.querySelectorAll('pre code').forEach((block) => {
            hljs.highlightElement(block);
        });

        if (role === 'assistant') {
            finalizeAssistantMessage(bubble);
        }
    }

    function formatText(text) {
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
<div class="code-container" data-lang="${displayLang}" data-file="${displayFile}">
    <div class="code-header">
        <div class="code-title">
            <span class="language-badge">${displayLang}</span>
            ${displayFile ? `<span class="file-name">${displayFile}</span>` : ''}
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
        escaped = escaped.replace(/\n/g, '<br>');
        
        // Restore blocks
        blocks.forEach((blockHtml, index) => {
            escaped = escaped.replace(`__BLOCK_${index}__`, blockHtml);
        });
        
        return escaped;
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

                // Replace the first container in the group with the new tabContainer
                const first = group[0];
                first.parentNode.insertBefore(tabContainer, first);
                
                // Remove all original containers and intermediate <br>s
                group.forEach(c => {
                    let next = c.nextSibling;
                    while (next && next !== group[group.indexOf(c) + 1] && (next.tagName === 'BR' || (next.nodeType === Node.TEXT_NODE && !next.textContent.trim()))) {
                        let toRemove = next;
                        next = next.nextSibling;
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
                const fileName = container.dataset.file || (container.closest('.code-container').dataset.file) || 'file.' + (container.dataset.lang || 'txt');
                downloadFile(fileName, code);
            });
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
        const blob = new Blob([content], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = name;
        a.click();
        URL.revokeObjectURL(url);
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
            a.click();
            URL.revokeObjectURL(url);
            showToast('Export successful', 'success');
        } catch (err) {
            console.error('Export error', err);
            showToast('Failed to export ZIP', 'error');
        }
    }

    function appendReasoning(text) {
        // Mark existing steps as old
        reasoningContainer.querySelectorAll('.reasoning-step').forEach(step => {
            step.classList.add('is-old');
        });

        const step = document.createElement('div');
        step.className = 'reasoning-step';
        step.textContent = text;
        reasoningContainer.appendChild(step);
        reasoningContainer.scrollTop = reasoningContainer.scrollHeight;
    }

    async function sendMessage(message) {
        statusBadge.textContent = 'Thinking...';
        statusBadge.className = 'status-badge thinking';
        reasoningContainer.innerHTML = ''; // Clear for new request

        const formData = new FormData();
        formData.append('message', message);
        formData.append('session_id', currentSessionId);

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

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                const chunk = decoder.decode(value, { stream: true });
                const lines = chunk.split('\n');
                
                for (const line of lines) {
                    if (line.startsWith('data: ')) {
                        try {
                            const data = JSON.parse(line.substring(6));
                            if (data.type === 'reasoning') {
                                appendReasoning(data.text);
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
                                
                                // During streaming, we still highlight new blocks but don't tabify yet
                                assistantBubble.querySelectorAll('pre code').forEach((block) => {
                                    if (!block.classList.contains('hljs')) {
                                        hljs.highlightElement(block);
                                    }
                                });
                            } else if (data.type === 'status') {
                                statusBadge.textContent = data.text;
                                const statusClass = data.text.toLowerCase().replace(/\./g, '').replace(/\s+/g, '-');
                                statusBadge.className = `status-badge ${statusClass}`;
                            } else if (data.type === 'error') {
                                showToast(data.text, 'error');
                                appendReasoning('Error: ' + data.text);
                                statusBadge.textContent = 'Error';
                                statusBadge.className = 'status-badge error';
                            }
                        } catch (e) {
                            // Incomplete JSON chunk or other error
                        }
                    }
                }
            }
            
            // Finalize message: highlight and tabify
            if (assistantBubble) {
                assistantBubble.querySelectorAll('pre code').forEach((block) => {
                    hljs.highlightElement(block);
                });
                finalizeAssistantMessage(assistantBubble);
            }

        } catch (err) {
            console.error('Chat error', err);
            showToast('Something went wrong. Please try again.', 'error');
            statusBadge.textContent = 'Error';
            statusBadge.className = 'status-badge error';
        }
    }

    function showToast(message, type = 'info') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;
        container.appendChild(toast);

        setTimeout(() => {
            toast.remove();
        }, 3500);
    }
});
