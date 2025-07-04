jQuery(document).ready(function($) {
    
    // Initialize all Elementor chatbot widgets
    $('.owui-inline-chat').each(function() {
        initializeInlineChat($(this));
    });
    
    // Handle popup buttons
    $(document).on('click', '.owui-popup-button', function() {
        var chatbotId = $(this).data('chatbot-id');
        openElementorPopup(chatbotId);
    });
    
    // Handle floating toggles
    $(document).on('click', '.owui-floating-toggle', function() {
        var chatbotId = $(this).data('chatbot-id');
        toggleFloatingChat(chatbotId);
    });
    
    function initializeInlineChat(chatContainer) {
        var chatbotId = chatContainer.data('chatbot-id');
        var input = chatContainer.find('.owui-elementor-input');
        var sendBtn = chatContainer.find('.owui-elementor-send');
        var messages = chatContainer.find('.owui-elementor-messages');
        var isProcessing = false;
        
        // Handle Enter key
        input.keypress(function(e) {
            if (e.which === 13 && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
        
        // Handle send button
        sendBtn.click(function() {
            sendMessage();
        });
        
        function sendMessage() {
            if (isProcessing) return;
            
            var message = input.val().trim();
            if (!message) return;
            
            isProcessing = true;
            sendBtn.prop('disabled', true).text('Sending...');
            
            // Add user message
            addMessage(message, 'user');
            input.val('');
            
            // Add typing indicator
            var typingIndicator = addMessage('...', 'bot typing');
            
            // Send AJAX request
            $.post(owui_elementor.ajax_url, {
                action: 'owui_send_message',
                message: message,
                chatbot_id: chatbotId,
                nonce: owui_elementor.nonce
            }, function(response) {
                typingIndicator.remove();
                
                if (response.success) {
                    addMessage(response.data, 'bot');
                } else {
                    addMessage(response.data || owui_elementor.error_message, 'bot error');
                }
            }).fail(function() {
                typingIndicator.remove();
                addMessage(owui_elementor.connection_error, 'bot error');
            }).always(function() {
                isProcessing = false;
                sendBtn.prop('disabled', false).text('Send');
                input.focus();
            });
        }
        
        function addMessage(content, type) {
            var messageClass = 'owui-message ' + type;
            var messageElement = $('<div class="' + messageClass + '">' + content + '</div>');
            
            messages.append(messageElement);
            messages.scrollTop(messages[0].scrollHeight);
            
            return messageElement;
        }
        
        // Auto-focus input
        input.focus();
    }
    
    function openElementorPopup(chatbotId) {
        // Check if popup already exists
        if ($('#owui-elementor-popup').length > 0) {
            $('#owui-elementor-popup').show();
            return;
        }
        
        // Create popup overlay
        var popup = $(`
            <div id="owui-elementor-popup" class="owui-elementor-popup-overlay">
                <div class="owui-elementor-popup-content">
                    <div class="owui-elementor-popup-header">
                        <h4>Loading...</h4>
                        <button class="owui-elementor-popup-close">×</button>
                    </div>
                    <div class="owui-elementor-popup-body">
                        <div class="owui-elementor-popup-messages">
                            <div class="owui-message bot">Connecting...</div>
                        </div>
                        <div class="owui-elementor-popup-input-container">
                            <input type="text" class="owui-elementor-popup-input" placeholder="Type your message...">
                            <button class="owui-elementor-popup-send">Send</button>
                        </div>
                    </div>
                </div>
            </div>
        `);
        
        $('body').append(popup);
        
        // Load chatbot details
        $.post(owui_elementor.ajax_url, {
            action: 'owui_get_chatbot_details',
            chatbot_id: chatbotId,
            nonce: owui_elementor.nonce
        }, function(response) {
            if (response.success) {
                var chatbot = response.data;
                popup.find('h4').text(chatbot.name);
                popup.find('.owui-elementor-popup-messages').html(
                    chatbot.greeting_message ? 
                    '<div class="owui-message bot">' + chatbot.greeting_message + '</div>' : 
                    '<div class="owui-message bot">Hello! How can I help you?</div>'
                );
                
                initializePopupHandlers(popup, chatbotId);
            } else {
                popup.find('.owui-elementor-popup-messages').html(
                    '<div class="owui-message bot error">Failed to load chatbot</div>'
                );
            }
        });
        
        // Close popup handlers
        popup.find('.owui-elementor-popup-close').click(function() {
            popup.remove();
        });
        
        popup.click(function(e) {
            if (e.target === this) {
                popup.remove();
            }
        });
        
        // Escape key to close
        $(document).keyup(function(e) {
            if (e.keyCode === 27) { // ESC key
                popup.remove();
                $(document).off('keyup');
            }
        });
    }
    
    function initializePopupHandlers(popup, chatbotId) {
        var input = popup.find('.owui-elementor-popup-input');
        var sendBtn = popup.find('.owui-elementor-popup-send');
        var messages = popup.find('.owui-elementor-popup-messages');
        var isProcessing = false;
        
        input.keypress(function(e) {
            if (e.which === 13) {
                e.preventDefault();
                sendMessage();
            }
        });
        
        sendBtn.click(function() {
            sendMessage();
        });
        
        function sendMessage() {
            if (isProcessing) return;
            
            var message = input.val().trim();
            if (!message) return;
            
            isProcessing = true;
            sendBtn.prop('disabled', true).text('Sending...');
            
            // Add user message
            addMessage(message, 'user');
            input.val('');
            
            // Add typing indicator
            var typingIndicator = addMessage('...', 'bot typing');
            
            // Send AJAX request
            $.post(owui_elementor.ajax_url, {
                action: 'owui_send_message',
                message: message,
                chatbot_id: chatbotId,
                nonce: owui_elementor.nonce
            }, function(response) {
                typingIndicator.remove();
                
                if (response.success) {
                    addMessage(response.data, 'bot');
                } else {
                    addMessage(response.data || owui_elementor.error_message, 'bot error');
                }
            }).fail(function() {
                typingIndicator.remove();
                addMessage(owui_elementor.connection_error, 'bot error');
            }).always(function() {
                isProcessing = false;
                sendBtn.prop('disabled', false).text('Send');
                input.focus();
            });
        }
        
        function addMessage(content, type) {
            var messageClass = 'owui-message ' + type;
            var messageElement = $('<div class="' + messageClass + '">' + content + '</div>');
            
            messages.append(messageElement);
            messages.scrollTop(messages[0].scrollHeight);
            
            return messageElement;
        }
        
        // Auto-focus input
        input.focus();
    }
    
    function toggleFloatingChat(chatbotId) {
        var floatingWidget = $('.owui-floating-widget[data-chatbot-id="' + chatbotId + '"]');
        var chatContainer = floatingWidget.find('.owui-floating-chat-container');
        
        if (chatContainer.length === 0) {
            // Create floating chat container
            createFloatingChatContainer(floatingWidget, chatbotId);
        } else {
            chatContainer.toggle();
        }
    }
    
    function createFloatingChatContainer(widget, chatbotId) {
        var chatContainer = $(`
            <div class="owui-floating-chat-container" style="display: none;">
                <div class="owui-floating-chat-header">
                    <h4>Loading...</h4>
                    <button class="owui-floating-chat-close">×</button>
                </div>
                <div class="owui-floating-chat-messages">
                    <div class="owui-message bot">Connecting...</div>
                </div>
                <div class="owui-floating-chat-input-container">
                    <input type="text" class="owui-floating-chat-input" placeholder="Type your message...">
                    <button class="owui-floating-chat-send">Send</button>
                </div>
            </div>
        `);
        
        widget.append(chatContainer);
        
        // Load chatbot details
        $.post(owui_elementor.ajax_url, {
            action: 'owui_get_chatbot_details',
            chatbot_id: chatbotId,
            nonce: owui_elementor.nonce
        }, function(response) {
            if (response.success) {
                var chatbot = response.data;
                chatContainer.find('h4').text(chatbot.name);
                chatContainer.find('.owui-floating-chat-messages').html(
                    chatbot.greeting_message ? 
                    '<div class="owui-message bot">' + chatbot.greeting_message + '</div>' : 
                    '<div class="owui-message bot">Hello! How can I help you?</div>'
                );
                
                initializeFloatingHandlers(chatContainer, chatbotId);
                chatContainer.show();
            } else {
                chatContainer.find('.owui-floating-chat-messages').html(
                    '<div class="owui-message bot error">Failed to load chatbot</div>'
                );
                chatContainer.show();
            }
        });
        
        // Close handler
        chatContainer.find('.owui-floating-chat-close').click(function() {
            chatContainer.hide();
        });
    }
    
    function initializeFloatingHandlers(container, chatbotId) {
        var input = container.find('.owui-floating-chat-input');
        var sendBtn = container.find('.owui-floating-chat-send');
        var messages = container.find('.owui-floating-chat-messages');
        var isProcessing = false;
        
        input.keypress(function(e) {
            if (e.which === 13) {
                e.preventDefault();
                sendMessage();
            }
        });
        
        sendBtn.click(function() {
            sendMessage();
        });
        
        function sendMessage() {
            if (isProcessing) return;
            
            var message = input.val().trim();
            if (!message) return;
            
            isProcessing = true;
            sendBtn.prop('disabled', true).text('Sending...');
            
            // Add user message
            addMessage(message, 'user');
            input.val('');
            
            // Add typing indicator
            var typingIndicator = addMessage('...', 'bot typing');
            
            // Send AJAX request
            $.post(owui_elementor.ajax_url, {
                action: 'owui_send_message',
                message: message,
                chatbot_id: chatbotId,
                nonce: owui_elementor.nonce
            }, function(response) {
                typingIndicator.remove();
                
                if (response.success) {
                    addMessage(response.data, 'bot');
                } else {
                    addMessage(response.data || owui_elementor.error_message, 'bot error');
                }
            }).fail(function() {
                typingIndicator.remove();
                addMessage(owui_elementor.connection_error, 'bot error');
            }).always(function() {
                isProcessing = false;
                sendBtn.prop('disabled', false).text('Send');
                input.focus();
            });
        }
        
        function addMessage(content, type) {
            var messageClass = 'owui-message ' + type;
            var messageElement = $('<div class="' + messageClass + '">' + content + '</div>');
            
            messages.append(messageElement);
            messages.scrollTop(messages[0].scrollHeight);
            
            return messageElement;
        }
        
        // Auto-focus input when opened
        input.focus();
    }
    
    // Handle shortcode widgets
    $('.owui-shortcode-widget').each(function() {
        var widget = $(this);
        var displayType = widget.data('display-type');
        
        if (displayType === 'inline') {
            var inlineChat = widget.find('.owui-inline-chat');
            if (inlineChat.length > 0) {
                initializeInlineChat(inlineChat);
            }
        }
    });
    
    // Responsive handling for inline chats
    function handleResponsiveChats() {
        $('.owui-inline-chat').each(function() {
            var chat = $(this);
            var container = chat.find('.owui-elementor-messages');
            var windowWidth = $(window).width();
            
            // Adjust chat dimensions for mobile
            if (windowWidth < 768) {
                chat.css({
                    'width': '100%',
                    'max-width': '100%',
                    'height': 'auto',
                    'min-height': '400px'
                });
                container.css('max-height', '300px');
            }
        });
    }
    
    $(window).resize(handleResponsiveChats);
    handleResponsiveChats();
    
    // Auto-scroll to bottom when new messages arrive
    $(document).on('DOMNodeInserted', '.owui-message', function() {
        var messagesContainer = $(this).closest('.owui-elementor-messages, .owui-floating-chat-messages, .owui-elementor-popup-messages');
        if (messagesContainer.length > 0) {
            messagesContainer.scrollTop(messagesContainer[0].scrollHeight);
        }
    });
    
    // Handle message formatting
    function formatMessage(content) {
        // Convert basic markdown to HTML
        content = content.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        content = content.replace(/\*(.*?)\*/g, '<em>$1</em>');
        content = content.replace(/`(.*?)`/g, '<code>$1</code>');
        content = content.replace(/\n/g, '<br>');
        
        return content;
    }
    
    // Apply message formatting to all new messages
    $(document).on('DOMNodeInserted', '.owui-message.bot', function() {
        var message = $(this);
        if (!message.hasClass('formatted')) {
            var content = message.html();
            message.html(formatMessage(content)).addClass('formatted');
        }
    });
    
    // Clear chat functionality
    $(document).on('click', '.owui-clear-chat', function() {
        var chatbotId = $(this).data('chatbot-id');
        var messagesContainer = $(this).closest('.owui-elementor-widget, .owui-floating-widget, .owui-elementor-popup').find('.owui-elementor-messages, .owui-floating-chat-messages, .owui-elementor-popup-messages');
        
        if (confirm('Are you sure you want to clear this chat?')) {
            // Clear session on server
            $.post(owui_elementor.ajax_url, {
                action: 'owui_clear_session',
                chatbot_id: chatbotId,
                nonce: owui_elementor.nonce
            }, function(response) {
                if (response.success) {
                    messagesContainer.html('<div class="owui-message bot">Chat cleared. How can I help you?</div>');
                }
            });
        }
    });
    
    // Handle widget visibility in Elementor editor
    if (typeof elementor !== 'undefined') {
        elementor.hooks.addAction('panel/open_editor/widget/owui_chatbot', function(panel, model, view) {
            // Refresh widget when settings change
            panel.on('editor:change', function() {
                setTimeout(function() {
                    view.$el.find('.owui-inline-chat').each(function() {
                        initializeInlineChat($(this));
                    });
                }, 100);
            });
        });
    }
    
});