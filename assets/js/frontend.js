jQuery(document).ready(function($) {

    // ---- Main Variables ----
    var chatbotContainer = $('#owui-chatbot-container');
    var chatbotToggle = $('#owui-chatbot-toggle');
    var chatbotClose = $('#owui-chatbot-close');
    var chatbotInput = $('#owui-chatbot-input');
    var chatbotSend = $('#owui-chatbot-send');
    var chatbotMessages = $('#owui-chatbot-messages');
    var fileUpload = $('#owui-file-upload');
    var currentFileId = null;
    var isProcessing = false;

    // ---- Hide chatbot with cookie ----
    $(document).on('click', '.owui-hide-chatbot', function() {
        document.cookie = 'owui_chatbot_hidden=true; path=/; max-age=' + (7 * 24 * 60 * 60); // 7 days
        $('#owui-chatbot').hide();
    });

    // ---- Clear session ----
    $(document).on('click', '.owui-clear-session', function() {
        var chatbotId = $(this).data('chatbot-id');
        $.post(owui_ajax.ajax_url, {
            action: 'owui_clear_session',
            chatbot_id: chatbotId,
            nonce: owui_ajax.nonce
        }, function(response) {
            if (response.success) {
                chatbotMessages.empty();
                addMessage('Session cleared. How can I help you?', 'bot');
            }
        });
    });

    // ---- Toggle chatbot visibility ----
    chatbotToggle.click(function() {
        chatbotContainer.toggle();
        if (chatbotContainer.is(':visible')) {
            chatbotInput.focus();
        }
    });

    // ---- Close chatbot ----
    chatbotClose.click(function() {
        chatbotContainer.hide();
    });

    // ---- Handle Enter key in input ----
    chatbotInput.keypress(function(e) {
        if (e.which === 13 && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    // ---- Send button click ----
    chatbotSend.click(function() {
        sendMessage();
    });

    // ---- File upload handling ----
    fileUpload.change(function() {
        var file = this.files[0];
        if (file) {
            uploadFile(file);
        }
    });

    // ---- Send message function ----
    function sendMessage() {
        if (isProcessing) return;

        var message = chatbotInput.val().trim();
        if (!message) return;

        isProcessing = true;
        chatbotSend.prop('disabled', true).text('Sending...');

        // Add user message to chat
        addMessage(message, 'user');
        chatbotInput.val('');

        // Show typing indicator
        var typingIndicator = addMessage('...', 'bot typing');

        // Prepare data
        var data = {
            action: 'owui_send_message',
            message: message,
            chatbot_id: $('#owui-chatbot').data('chatbot-id'),
            nonce: owui_ajax.nonce
        };

        if (currentFileId) {
            data.file_id = currentFileId;
            currentFileId = null; // Reset after use
        }

        // Send AJAX request
        $.post(owui_ajax.ajax_url, data, function(response) {
            typingIndicator.remove();

            if (response.success) {
                addMessage(response.data, 'bot');
            } else {
                addMessage(response.data || owui_ajax.error_message, 'bot error');
            }
        }).fail(function() {
            typingIndicator.remove();
            addMessage(owui_ajax.connection_error, 'bot error');
        }).always(function() {
            isProcessing = false;
            chatbotSend.prop('disabled', false).text('Send');
            chatbotInput.focus();
        });
    }

    // ---- Add message to chat ----
    function addMessage(content, type) {
        var messageClass = 'owui-message ' + type;
        var messageElement = $('<div class="' + messageClass + '">' + content + '</div>');

        chatbotMessages.append(messageElement);
        chatbotMessages.scrollTop(chatbotMessages[0].scrollHeight);

        return messageElement;
    }

    // ---- Upload file function ----
    function uploadFile(file) {
        // Check file size (max 10MB)
        if (file.size > 10 * 1024 * 1024) {
            alert('File size must be less than 10MB');
            return;
        }

        // Check file type
        var allowedTypes = ['pdf', 'txt', 'doc', 'docx', 'csv', 'json'];
        var fileExt = file.name.split('.').pop().toLowerCase();

        if (allowedTypes.indexOf(fileExt) === -1) {
            alert('File type not supported. Allowed types: ' + allowedTypes.join(', '));
            return;
        }

        var formData = new FormData();
        formData.append('action', 'owui_upload_file');
        formData.append('file', file);
        formData.append('nonce', owui_ajax.nonce);

        // Show upload progress
        addMessage('📎 Uploading file: ' + file.name, 'bot');

        $.ajax({
            url: owui_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    currentFileId = response.data.file_id || response.data.id;
                    addMessage('✅ File uploaded successfully! You can now ask questions about it.', 'bot');
                } else {
                    addMessage('❌ Upload failed: ' + response.data, 'bot error');
                }
            },
            error: function() {
                addMessage('❌ Upload failed. Please try again.', 'bot error');
            }
        });

        // Reset file input
        fileUpload.val('');
    }

    // ---- Auto-resize chat container based on screen size ----
    function resizeChatbot() {
        var windowHeight = $(window).height();
        var maxHeight = Math.min(600, windowHeight - 100);
        chatbotContainer.css('max-height', maxHeight + 'px');

        var messagesHeight = maxHeight - 120; // Account for header and input
        chatbotMessages.css('max-height', messagesHeight + 'px');
    }

    $(window).resize(resizeChatbot);
    resizeChatbot();

    // ---- Handle shortcode and Elementor widgets ----
    $(document).on('click', '.owui-popup-button', function() {
        var chatbotId = $(this).data('chatbot-id');
        openChatbotPopup(chatbotId);
    });

    // ---- Handle floating widgets ----
    $(document).on('click', '.owui-floating-toggle', function() {
        var widget = $(this).closest('.owui-floating-widget');
        var chatContainer = widget.find('.owui-floating-chat');

        if (chatContainer.length === 0) {
            // Create floating chat container
            var chatbotId = $(this).data('chatbot-id');
            createFloatingChat(widget, chatbotId);
        } else {
            chatContainer.toggle();
        }
    });

    function openChatbotPopup(chatbotId) {
        // Create modal popup for chatbot
        var modal = $('<div class="owui-modal-overlay"></div>');
        var modalContent = $('<div class="owui-modal-content"></div>');
        var closeBtn = $('<button class="owui-modal-close">×</button>');

        modalContent.append(closeBtn);
        modalContent.append('<div class="owui-popup-chat" data-chatbot-id="' + chatbotId + '"></div>');
        modal.append(modalContent);

        $('body').append(modal);

        // Load chatbot details and initialize
        loadChatbotDetails(chatbotId, function(chatbot) {
            initializePopupChat(modal.find('.owui-popup-chat'), chatbot);
        });

        // Close modal handlers
        closeBtn.click(function() {
            modal.remove();
        });

        modal.click(function(e) {
            if (e.target === this) {
                modal.remove();
            }
        });
    }

    function createFloatingChat(widget, chatbotId) {
        var chatContainer = $('<div class="owui-floating-chat" style="display: none;"></div>');
        widget.append(chatContainer);

        loadChatbotDetails(chatbotId, function(chatbot) {
            initializeFloatingChat(chatContainer, chatbot);
            chatContainer.show();
        });
    }

    function loadChatbotDetails(chatbotId, callback) {
        $.post(owui_ajax.ajax_url, {
            action: 'owui_get_chatbot_details',
            chatbot_id: chatbotId,
            nonce: owui_ajax.nonce
        }, function(response) {
            if (response.success) {
                callback(response.data);
            } else {
                console.error('Failed to load chatbot details');
            }
        });
    }

    function initializePopupChat(container, chatbot) {
        var chatHtml = `
            <div class="owui-popup-header">
                <h4>${chatbot.name}</h4>
            </div>
            <div class="owui-popup-messages">
                ${chatbot.greeting_message ? '<div class="owui-message bot">' + chatbot.greeting_message + '</div>' : ''}
            </div>
            <div class="owui-popup-input-container">
                <input type="text" class="owui-popup-input" placeholder="Type your message...">
                <button class="owui-popup-send">Send</button>
            </div>
        `;

        container.html(chatHtml);
        initializeChatHandlers(container, chatbot.id);
    }

    function initializeFloatingChat(container, chatbot) {
        var chatHtml = `
            <div class="owui-floating-header">
                <h4>${chatbot.name}</h4>
                <button class="owui-floating-close">×</button>
            </div>
            <div class="owui-floating-messages">
                ${chatbot.greeting_message ? '<div class="owui-message bot">' + chatbot.greeting_message + '</div>' : ''}
            </div>
            <div class="owui-floating-input-container">
                <input type="text" class="owui-floating-input" placeholder="Type your message...">
                <button class="owui-floating-send">Send</button>
            </div>
        `;

        container.html(chatHtml);
        initializeChatHandlers(container, chatbot.id);

        container.find('.owui-floating-close').click(function() {
            container.hide();
        });
    }

    function initializeChatHandlers(container, chatbotId) {
        var input = container.find('input[type="text"]');
        var sendBtn = container.find('button[class*="send"]');
        var messages = container.find('div[class*="messages"]');

        input.keypress(function(e) {
            if (e.which === 13) {
                sendChatMessage();
            }
        });

        sendBtn.click(sendChatMessage);

        function sendChatMessage() {
            var message = input.val().trim();
            if (!message) return;

            // Add user message
            messages.append('<div class="owui-message user">' + message + '</div>');
            input.val('');

            // Show typing
            var typing = $('<div class="owui-message bot typing">...</div>');
            messages.append(typing);
            messages.scrollTop(messages[0].scrollHeight);

            // Send message
            $.post(owui_ajax.ajax_url, {
                action: 'owui_send_message',
                message: message,
                chatbot_id: chatbotId,
                nonce: owui_ajax.nonce
            }, function(response) {
                typing.remove();

                if (response.success) {
                    messages.append('<div class="owui-message bot">' + response.data + '</div>');
                } else {
                    messages.append('<div class="owui-message bot error">' + (response.data || 'Error occurred') + '</div>');
                }

                messages.scrollTop(messages[0].scrollHeight);
            }).fail(function() {
                typing.remove();
                messages.append('<div class="owui-message bot error">Connection error</div>');
                messages.scrollTop(messages[0].scrollHeight);
            });
        }
    }

    // ---- Chat session management ----
    var currentSessionId = null;
    var sessionStartTime = null;

    // Track when chat sessions start
    $(document).on('click', '#owui-chatbot-toggle, .owui-popup-button, .owui-floating-toggle', function() {
        if (!currentSessionId) {
            sessionStartTime = Date.now();
            // Get current session ID from chatbot data
            var chatbotId = $(this).data('chatbot-id') || $('#owui-chatbot').data('chatbot-id');
            if (chatbotId) {
                // Store session info for potential cleanup
                sessionStorage.setItem('owui_active_chatbot', chatbotId);
                sessionStorage.setItem('owui_session_start', sessionStartTime);
            }
        }
    });

    // Handle page unload
    $(window).on('beforeunload', function() {
        var chatbotId = sessionStorage.getItem('owui_active_chatbot');
        var startTime = sessionStorage.getItem('owui_session_start');

        if (chatbotId && startTime) {
            // Only trigger if session lasted more than 30 seconds
            if (Date.now() - parseInt(startTime) > 30000) {
                // Use navigator.sendBeacon for reliable delivery
                if (navigator.sendBeacon) {
                    var formData = new FormData();
                    formData.append('action', 'owui_end_session');
                    formData.append('chatbot_id', chatbotId);
                    formData.append('nonce', owui_ajax.nonce);

                    navigator.sendBeacon(owui_ajax.ajax_url, formData);
                }
            }
        }
    });

    // Clean up session storage when chat is manually closed
    $(document).on('click', '#owui-chatbot-close, .owui-floating-close, .owui-modal-close', function() {
        sessionStorage.removeItem('owui_active_chatbot');
        sessionStorage.removeItem('owui_session_start');
    });

    // ---- Track inactivity for session timeout ----
    var inactivityTimer = null;
    var timeoutDuration = 15 * 60 * 1000; // 15 minutes default

    function resetInactivityTimer() {
        clearTimeout(inactivityTimer);
        inactivityTimer = setTimeout(function() {
            var chatbotId = sessionStorage.getItem('owui_active_chatbot');
            if (chatbotId) {
                // End session due to inactivity
                $.post(owui_ajax.ajax_url, {
                    action: 'owui_end_session',
                    chatbot_id: chatbotId,
                    reason: 'inactivity',
                    nonce: owui_ajax.nonce
                });

                // Clean up
                sessionStorage.removeItem('owui_active_chatbot');
                sessionStorage.removeItem('owui_session_start');
            }
        }, timeoutDuration);
    }

    // Reset timer on any chat activity
    $(document).on('click keypress', '#owui-chatbot-input, .owui-elementor-input, .owui-popup-input, .owui-floating-input', function() {
        resetInactivityTimer();
    });

    // Start timer when chat opens
    $(document).on('click', '#owui-chatbot-toggle, .owui-popup-button, .owui-floating-toggle', function() {
        resetInactivityTimer();
    });

});
