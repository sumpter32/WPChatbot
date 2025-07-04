<?php if (!defined('ABSPATH')) exit; ?>

<div class="wrap">
    <h1>Chat History</h1>
    
    <?php
    global $wpdb;
    
    // Check if we're viewing a specific conversation
    $view_conversation = isset($_GET['conversation']) ? absint($_GET['conversation']) : 0;
    
    if ($view_conversation) {
        // Show specific conversation
        $conversation = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, c.name as chatbot_name, u.display_name as user_name, u.user_email
            FROM {$wpdb->prefix}owui_chat_sessions s
            LEFT JOIN {$wpdb->prefix}owui_chatbots c ON s.chatbot_id = c.id
            LEFT JOIN {$wpdb->prefix}users u ON s.user_id = u.ID
            WHERE s.id = %d",
            $view_conversation
        ));
        
        if (!$conversation) {
            echo '<div class="notice notice-error"><p>Conversation not found.</p></div>';
            echo '<a href="' . admin_url('admin.php?page=owui-history') . '" class="button">← Back to History</a>';
            return;
        }
        
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}owui_chat_history 
            WHERE session_id = %d 
            ORDER BY created_at ASC",
            $view_conversation
        ));
        ?>
        
        <div class="owui-conversation-header">
            <div class="owui-conversation-info">
                <a href="<?php echo admin_url('admin.php?page=owui-history'); ?>" class="button">← Back to History</a>
                <h2>Conversation Details</h2>
                <div class="owui-conversation-meta">
                    <span class="owui-meta-item"><strong>Chatbot:</strong> <?php echo esc_html($conversation->chatbot_name ?: 'Unknown'); ?></span>
                    <span class="owui-meta-item"><strong>User:</strong> <?php echo esc_html($conversation->user_name ?: 'Guest'); ?></span>
                    <?php if ($conversation->user_email): ?>
                        <span class="owui-meta-item"><strong>Email:</strong> <?php echo esc_html($conversation->user_email); ?></span>
                    <?php endif; ?>
                    <span class="owui-meta-item"><strong>Started:</strong> <?php echo esc_html(date('M j, Y g:i A', strtotime($conversation->started_at))); ?></span>
                    <span class="owui-meta-item"><strong>Messages:</strong> <?php echo count($messages); ?></span>
                </div>
            </div>
            <div class="owui-conversation-actions">
                <button type="button" class="button" id="export-conversation" data-session-id="<?php echo $conversation->id; ?>">Export Conversation</button>
                <button type="button" class="button button-secondary" id="delete-conversation" data-session-id="<?php echo $conversation->id; ?>">Delete Conversation</button>
            </div>
        </div>
        
        <div class="owui-conversation-view">
            <?php if (empty($messages)): ?>
                <div class="owui-no-messages">
                    <p>No messages found in this conversation.</p>
                </div>
            <?php else: ?>
                <div class="owui-chat-messages">
                    <?php foreach ($messages as $message): ?>
                        <div class="owui-message-pair">
                            <div class="owui-message owui-message-user">
                                <div class="owui-message-content">
                                    <div class="owui-message-text"><?php echo nl2br(esc_html($message->message)); ?></div>
                                    <div class="owui-message-time"><?php echo esc_html(date('g:i A', strtotime($message->created_at))); ?></div>
                                </div>
                            </div>
                            <div class="owui-message owui-message-bot">
                                <div class="owui-message-content">
                                    <div class="owui-message-text"><?php echo wp_kses_post(nl2br($message->response)); ?></div>
                                    <div class="owui-message-time"><?php echo esc_html(date('g:i A', strtotime($message->created_at))); ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
    <?php } else {
        // Show conversations list
        $conversations = $wpdb->get_results("
            SELECT 
                s.*,
                c.name as chatbot_name,
                u.display_name as user_name,
                u.user_email,
                COUNT(h.id) as message_count,
                MAX(h.created_at) as last_message,
                SUBSTRING(h.message, 1, 100) as first_message
            FROM {$wpdb->prefix}owui_chat_sessions s
            LEFT JOIN {$wpdb->prefix}owui_chatbots c ON s.chatbot_id = c.id
            LEFT JOIN {$wpdb->prefix}users u ON s.user_id = u.ID
            LEFT JOIN {$wpdb->prefix}owui_chat_history h ON s.id = h.session_id
            GROUP BY s.id
            HAVING message_count > 0
            ORDER BY last_message DESC
            LIMIT 100
        ");
        ?>
        
        <div class="tablenav top">
            <div class="alignleft actions">
                <select id="filter-chatbot">
                    <option value="">All Chatbots</option>
                    <?php
                    $chatbots = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}owui_chatbots ORDER BY name");
                    foreach ($chatbots as $chatbot) {
                        echo '<option value="' . $chatbot->id . '">' . esc_html($chatbot->name) . '</option>';
                    }
                    ?>
                </select>
                <select id="filter-timeframe">
                    <option value="">All Time</option>
                    <option value="today">Today</option>
                    <option value="week">This Week</option>
                    <option value="month">This Month</option>
                </select>
                <button type="button" class="button" id="apply-filters">Filter</button>
                <button type="button" class="button" id="export-all-history">Export All</button>
                <button type="button" class="button button-secondary" id="clear-all-history">Clear All History</button>
            </div>
            <div class="alignright">
                <span class="displaying-num"><?php echo count($conversations); ?> conversations</span>
            </div>
        </div>
        
        <div class="owui-conversations-grid">
            <?php if (empty($conversations)): ?>
                <div class="owui-no-conversations">
                    <h3>No conversations found</h3>
                    <p>When users start chatting with your chatbots, their conversations will appear here.</p>
                </div>
            <?php else: ?>
                <?php foreach ($conversations as $conv): ?>
                    <div class="owui-conversation-card" data-chatbot-id="<?php echo $conv->chatbot_id; ?>" data-created="<?php echo $conv->started_at; ?>">
                        <div class="owui-conversation-header">
                            <div class="owui-conversation-title">
                                <h4><?php echo esc_html($conv->chatbot_name ?: 'Unknown Chatbot'); ?></h4>
                                <span class="owui-conversation-user"><?php echo esc_html($conv->user_name ?: 'Guest User'); ?></span>
                            </div>
                            <div class="owui-conversation-meta">
                                <span class="owui-message-count"><?php echo $conv->message_count; ?> messages</span>
                                <span class="owui-conversation-date"><?php echo esc_html(human_time_diff(strtotime($conv->last_message), current_time('timestamp')) . ' ago'); ?></span>
                            </div>
                        </div>
                        
                        <div class="owui-conversation-preview">
                            <p><?php echo esc_html(wp_trim_words($conv->first_message, 15)); ?></p>
                        </div>
                        
                        <div class="owui-conversation-actions">
                            <a href="<?php echo admin_url('admin.php?page=owui-history&conversation=' . $conv->id); ?>" class="button button-primary button-small">View Conversation</a>
                            <button class="button button-small export-single" data-session-id="<?php echo $conv->id; ?>">Export</button>
                            <button class="button button-small button-link-delete delete-single" data-session-id="<?php echo $conv->id; ?>">Delete</button>
                        </div>
                        
                        <?php
                        // Show contact info if available
                        $contacts = $wpdb->get_results($wpdb->prepare(
                            "SELECT contact_type, contact_value FROM {$wpdb->prefix}owui_contact_info WHERE session_id = %d",
                            $conv->id
                        ));
                        
                        if (!empty($contacts)): ?>
                            <div class="owui-conversation-contacts">
                                <small><strong>Contact Info:</strong> 
                                <?php
                                $contact_strings = array();
                                foreach ($contacts as $contact) {
                                    $contact_strings[] = $contact->contact_type . ': ' . $contact->contact_value;
                                }
                                echo esc_html(implode(', ', $contact_strings));
                                ?>
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
    <?php } ?>
</div>

<style>
.owui-conversation-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding: 20px;
    background: #f9f9f9;
    border-radius: 5px;
}

.owui-conversation-meta {
    display: flex;
    gap: 20px;
    margin-top: 10px;
}

.owui-meta-item {
    padding: 5px 10px;
    background: #fff;
    border-radius: 3px;
    font-size: 13px;
}

.owui-conversation-view {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 5px;
    padding: 20px;
    max-height: 70vh;
    overflow-y: auto;
}

.owui-chat-messages {
    max-width: 800px;
    margin: 0 auto;
}

.owui-message-pair {
    margin-bottom: 20px;
}

.owui-message {
    margin-bottom: 10px;
    display: flex;
}

.owui-message-user {
    justify-content: flex-end;
}

.owui-message-bot {
    justify-content: flex-start;
}

.owui-message-content {
    max-width: 70%;
    padding: 12px 16px;
    border-radius: 18px;
    position: relative;
}

.owui-message-user .owui-message-content {
    background: #0073aa;
    color: white;
    border-bottom-right-radius: 4px;
}

.owui-message-bot .owui-message-content {
    background: #f1f1f1;
    color: #333;
    border-bottom-left-radius: 4px;
}

.owui-message-text {
    word-wrap: break-word;
    line-height: 1.4;
}

.owui-message-time {
    font-size: 11px;
    opacity: 0.7;
    margin-top: 5px;
}

.owui-conversations-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.owui-conversation-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 5px;
    padding: 20px;
    transition: box-shadow 0.3s ease;
    cursor: pointer;
}

.owui-conversation-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.owui-conversation-card .owui-conversation-header {
    background: none;
    padding: 0;
    margin-bottom: 15px;
}

.owui-conversation-title h4 {
    margin: 0;
    color: #0073aa;
    font-size: 16px;
}

.owui-conversation-user {
    color: #666;
    font-size: 13px;
}

.owui-conversation-meta {
    flex-direction: column;
    gap: 5px;
    text-align: right;
}

.owui-message-count {
    background: #0073aa;
    color: white;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
}

.owui-conversation-date {
    font-size: 12px;
    color: #666;
}

.owui-conversation-preview {
    margin: 15px 0;
    padding: 10px;
    background: #f9f9f9;
    border-radius: 3px;
    font-style: italic;
    color: #666;
}

.owui-conversation-preview p {
    margin: 0;
    font-size: 13px;
}

.owui-conversation-actions {
    display: flex;
    gap: 10px;
    margin-top: 15px;
}

.owui-conversation-contacts {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #eee;
    font-size: 12px;
    color: #666;
}

.owui-no-conversations, .owui-no-messages {
    text-align: center;
    padding: 40px;
    color: #666;
}

.tablenav {
    background: #f9f9f9;
    padding: 10px;
    border-radius: 3px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.tablenav select {
    margin-right: 10px;
}

/* Responsive */
@media (max-width: 768px) {
    .owui-conversations-grid {
        grid-template-columns: 1fr;
    }
    
    .owui-conversation-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .owui-conversation-meta {
        flex-direction: row;
        flex-wrap: wrap;
    }
    
    .owui-message-content {
        max-width: 85%;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    
    // Export single conversation
    $(document).on('click', '.export-single, #export-conversation', function() {
        var sessionId = $(this).data('session-id');
        window.location.href = owui_admin_ajax.ajax_url + 
            '?action=owui_export_conversation&session_id=' + sessionId + '&nonce=' + owui_admin_ajax.nonce;
    });
    
    // Delete single conversation
    $(document).on('click', '.delete-single, #delete-conversation', function() {
        if (!confirm('Are you sure you want to delete this conversation? This cannot be undone.')) {
            return;
        }
        
        var sessionId = $(this).data('session-id');
        var button = $(this);
        
        $.post(owui_admin_ajax.ajax_url, {
            action: 'owui_delete_conversation',
            session_id: sessionId,
            nonce: owui_admin_ajax.nonce
        }, function(response) {
            if (response.success) {
                if (button.attr('id') === 'delete-conversation') {
                    // Redirect to history page if deleting from conversation view
                    window.location.href = '<?php echo admin_url('admin.php?page=owui-history'); ?>';
                } else {
                    // Remove card from grid view
                    button.closest('.owui-conversation-card').fadeOut();
                }
            } else {
                alert('Error deleting conversation: ' + response.data);
            }
        });
    });
    
    // Export all history
    $('#export-all-history').click(function() {
        window.location.href = owui_admin_ajax.ajax_url + 
            '?action=owui_export_csv&nonce=' + owui_admin_ajax.nonce;
    });
    
    // Clear all history
    $('#clear-all-history').click(function() {
        if (!confirm('Are you sure you want to clear ALL chat history? This cannot be undone.')) {
            return;
        }
        
        $.post(owui_admin_ajax.ajax_url, {
            action: 'owui_clear_history',
            nonce: owui_admin_ajax.nonce
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Error clearing history: ' + response.data);
            }
        });
    });
    
    // Apply filters
    $('#apply-filters').click(function() {
        var chatbotId = $('#filter-chatbot').val();
        var timeframe = $('#filter-timeframe').val();
        
        $('.owui-conversation-card').show();
        
        if (chatbotId) {
            $('.owui-conversation-card').not('[data-chatbot-id="' + chatbotId + '"]').hide();
        }
        
        if (timeframe) {
            var now = new Date();
            var filterDate = new Date();
            
            switch(timeframe) {
                case 'today':
                    filterDate.setHours(0, 0, 0, 0);
                    break;
                case 'week':
                    filterDate.setDate(now.getDate() - 7);
                    break;
                case 'month':
                    filterDate.setMonth(now.getMonth() - 1);
                    break;
            }
            
            $('.owui-conversation-card:visible').each(function() {
                var cardDate = new Date($(this).data('created'));
                if (cardDate < filterDate) {
                    $(this).hide();
                }
            });
        }
        
        // Update count
        var visibleCount = $('.owui-conversation-card:visible').length;
        $('.displaying-num').text(visibleCount + ' conversations');
    });
    
    // Click on conversation card to view
    $(document).on('click', '.owui-conversation-card', function(e) {
        if ($(e.target).is('button, a')) {
            return; // Don't trigger if clicking buttons
        }
        
        var conversationId = $(this).find('.owui-conversation-actions a').attr('href').split('conversation=')[1];
        window.location.href = '<?php echo admin_url('admin.php?page=owui-history&conversation='); ?>' + conversationId;
    });
    
});
</script>