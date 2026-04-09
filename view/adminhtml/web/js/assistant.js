define([
    'jquery',
    'Magento_Ui/js/modal/modal'
], function($, modal) {
    'use strict';

    var STORAGE_KEY = 'typesense_ai_chat';

    function getState() {
        try {
            var data = sessionStorage.getItem(STORAGE_KEY);
            return data ? JSON.parse(data) : { messages: [], openaiMessages: [] };
        } catch (e) {
            return { messages: [], openaiMessages: [] };
        }
    }

    function saveState(state) {
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (e) {}
    }

    function renderMarkdown(text) {
        // Escape HTML entities first to prevent XSS
        text = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

        // Render markdown tables
        text = text.replace(/((?:^\|.+\|$\n?)+)/gm, function(tableBlock) {
            var rows = tableBlock.trim().split('\n');
            var html = '<table class="typesense-chat-table">';
            rows.forEach(function(row, i) {
                // Skip separator rows (|---|---|---| or |---------|)
                if (/^\|[\s\-:|]+$/.test(row.trim())) return;
                var cells = row.split('|').filter(function(c, j, a) { return j > 0 && j < a.length - 1; });
                var tag = i === 0 ? 'th' : 'td';
                html += '<tr>';
                cells.forEach(function(cell) {
                    html += '<' + tag + '>' + cell.trim() + '</' + tag + '>';
                });
                html += '</tr>';
            });
            html += '</table>';
            return html;
        });

        // Basic markdown rendering
        return text
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/^(\d+)\.\s+(.+)$/gm, '<li>$2</li>')
            .replace(/^[-*]\s+(.+)$/gm, '<li>$1</li>')
            .replace(/\n/g, '<br>');
    }

    function createChatHtml() {
        return '<div class="typesense-chat-container">' +
            '<div class="typesense-chat-messages" id="typesense-chat-messages"></div>' +
            '<div class="typesense-chat-input-area">' +
                '<textarea id="typesense-chat-input" placeholder="Ask about your store..." rows="1"></textarea>' +
                '<button id="typesense-chat-send" class="action-primary" type="button">Send</button>' +
            '</div>' +
            '<div class="typesense-chat-actions">' +
                '<button id="typesense-chat-new" class="action-secondary" type="button">New Chat</button>' +
            '</div>' +
        '</div>';
    }

    function renderMessages(container, messages) {
        container.innerHTML = '';

        if (messages.length === 0) {
            container.innerHTML = '<div class="typesense-chat-welcome">' +
                '<strong>TypeSense AI Assistant</strong><br><br>' +
                'Ask me anything about your store — products, orders, customers, revenue, configuration.' +
                '</div>';
            return;
        }

        messages.forEach(function(msg) {
            var bubble = document.createElement('div');
            bubble.className = 'typesense-chat-bubble typesense-chat-' + msg.role;

            if (msg.role === 'user') {
                bubble.textContent = msg.content;
            } else {
                bubble.innerHTML = renderMarkdown(msg.content);
            }

            container.appendChild(bubble);
        });

        container.scrollTop = container.scrollHeight;
    }

    return {
        init: function(config) {
            var self = this;
            this.chatUrl = config.chatUrl;

            // Create floating button
            var btn = document.createElement('button');
            btn.id = 'typesense-ai-btn';
            btn.className = 'typesense-ai-floating-btn';
            btn.innerHTML = '<span class="typesense-ai-icon">AI</span>';
            btn.title = 'TypeSense AI Assistant';
            document.body.appendChild(btn);

            // Create modal container
            var modalDiv = document.createElement('div');
            modalDiv.id = 'typesense-ai-modal';
            modalDiv.innerHTML = createChatHtml();
            document.body.appendChild(modalDiv);

            // Load CSS
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = require.toUrl('RunAsRoot_TypeSense/css/assistant.css');
            document.head.appendChild(link);

            // Init Magento slide modal
            modal({
                type: 'slide',
                title: 'TypeSense AI Assistant',
                modalClass: 'typesense-ai-slideout',
                buttons: []
            }, $(modalDiv));

            btn.addEventListener('click', function() {
                $(modalDiv).modal('openModal');
                var state = getState();
                var container = document.getElementById('typesense-chat-messages');
                renderMessages(container, state.messages);
            });

            // Send message
            $(document).on('click', '#typesense-chat-send', function() {
                self.sendMessage();
            });

            $(document).on('keydown', '#typesense-chat-input', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    self.sendMessage();
                }
            });

            // New chat
            $(document).on('click', '#typesense-chat-new', function() {
                saveState({ messages: [], openaiMessages: [] });
                var container = document.getElementById('typesense-chat-messages');
                renderMessages(container, []);
            });
        },

        sendMessage: function() {
            var input = document.getElementById('typesense-chat-input');
            var query = input.value.trim();
            if (!query) return;

            var state = getState();
            state.messages.push({ role: 'user', content: query });

            var container = document.getElementById('typesense-chat-messages');
            renderMessages(container, state.messages);
            input.value = '';

            // Show typing indicator
            var typing = document.createElement('div');
            typing.className = 'typesense-chat-bubble typesense-chat-assistant typesense-chat-typing';
            typing.textContent = 'Thinking...';
            container.appendChild(typing);
            container.scrollTop = container.scrollHeight;

            var sendBtn = document.getElementById('typesense-chat-send');
            sendBtn.disabled = true;

            var self = this;

            $.ajax({
                url: self.chatUrl,
                method: 'POST',
                data: {
                    query: query,
                    history: JSON.stringify(state.openaiMessages || []),
                    form_key: window.FORM_KEY
                },
                dataType: 'json',
                success: function(response) {
                    typing.remove();
                    sendBtn.disabled = false;

                    if (response.success) {
                        state.messages.push({ role: 'assistant', content: response.answer });
                        state.openaiMessages = response.messages;
                    } else {
                        state.messages.push({
                            role: 'assistant',
                            content: 'Error: ' + (response.error || 'Unknown error occurred.')
                        });
                    }

                    saveState(state);
                    renderMessages(container, state.messages);
                },
                error: function() {
                    typing.remove();
                    sendBtn.disabled = false;

                    state.messages.push({
                        role: 'assistant',
                        content: 'Error: Failed to connect. Please try again.'
                    });
                    saveState(state);
                    renderMessages(container, state.messages);
                }
            });
        }
    };
});
