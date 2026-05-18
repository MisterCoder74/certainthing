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
    }

    function formatText(text) {
        // Basic escaping
        let escaped = text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
        
        // Code blocks
        escaped = escaped.replace(/```(\w+)?\s?([\s\S]*?)```/g, (match, lang, code) => {
            return `<pre><code class="language-${lang || ''}">${code.trim()}</code></pre>`;
        });
        
        // Newlines
        return escaped.replace(/\n/g, '<br>');
    }

    function appendReasoning(text) {
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
                            } else if (data.type === 'status') {
                                statusBadge.textContent = data.text;
                                statusBadge.className = `status-badge ${data.text.toLowerCase()}`;
                            } else if (data.type === 'error') {
                                showToast(data.text, 'error');
                                appendReasoning('✦ Error: ' + data.text);
                                statusBadge.textContent = 'Error';
                                statusBadge.className = 'status-badge error';
                            }
                        } catch (e) {
                            // Incomplete JSON chunk or other error
                        }
                    }
                }
            }
            
            // Re-highlight everything at the end
            if (assistantMessageDiv) {
                assistantMessageDiv.querySelectorAll('pre code').forEach((block) => {
                    hljs.highlightElement(block);
                });
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
