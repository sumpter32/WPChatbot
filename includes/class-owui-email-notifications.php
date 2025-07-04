<?php
/**
 * Email Notification System for OpenWebUI Chatbot
 */

if (!defined('ABSPATH')) exit;

class OWUI_Email_Notifications {
    
    private $api;
    private $contact_extractor;
    
    public function __construct() {
        $this->api = new OWUI_API();
        $this->contact_extractor = new OWUI_Contact_Extractor();
        
        // Hook into session management
        add_action('owui_session_ended', array($this, 'handle_session_ended'), 10, 2);
        
        // Add AJAX handlers
        add_action('wp_ajax_owui_test_email', array($this, 'ajax_test_email'));
        
        // Schedule cleanup of old sessions
        add_action('owui_check_inactive_sessions', array($this, 'check_inactive_sessions'));
        
        if (!wp_next_scheduled('owui_check_inactive_sessions')) {
            wp_schedule_event(time(), 'every_five_minutes', 'owui_check_inactive_sessions');
        }
    }
    
    /**
     * Check for inactive sessions and mark them as ended
     */
    public function check_inactive_sessions() {
        if (!get_option('owui_email_notifications')) {
            return;
        }
        
        global $wpdb;
        $timeout_minutes = get_option('owui_session_timeout', 15);
        
        // Find sessions that haven't had activity in the timeout period
        $inactive_sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id, s.chatbot_id, s.user_id, MAX(h.created_at) as last_activity
            FROM {$wpdb->prefix}owui_chat_sessions s
            LEFT JOIN {$wpdb->prefix}owui_chat_history h ON s.id = h.session_id
            WHERE s.ended_at IS NULL
            AND h.created_at < DATE_SUB(NOW(), INTERVAL %d MINUTE)
            GROUP BY s.id
            HAVING COUNT(h.id) > 0",
            $timeout_minutes
        ));
        
        foreach ($inactive_sessions as $session) {
            $this->end_session_and_notify($session->id, 'timeout');
        }
    }
    
    /**
     * Handle when a session is manually ended
     */
    public function handle_session_ended($session_id, $reason = 'manual') {
        $this->end_session_and_notify($session_id, $reason);
    }
    
    /**
     * End session and send notification if conditions are met
     */
    private function end_session_and_notify($session_id, $reason = 'timeout') {
        global $wpdb;
        
        // Mark session as ended
        $wpdb->update(
            $wpdb->prefix . 'owui_chat_sessions',
            array('ended_at' => current_time('mysql')),
            array('id' => $session_id),
            array('%s'),
            array('%d')
        );
        
        // Check if we should send email notification
        if ($this->should_send_notification($session_id)) {
            $this->send_conversation_summary($session_id, $reason);
        }
    }
    
    /**
     * Check if notification should be sent based on settings
     */
    private function should_send_notification($session_id) {
        // Check if email notifications are enabled
        if (!get_option('owui_email_notifications')) {
            return false;
        }
        
        global $wpdb;
        
        // Get conversation data
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}owui_chat_history WHERE session_id = %d ORDER BY created_at ASC",
            $session_id
        ));
        
        if (empty($messages)) {
            return false;
        }
        
        // Check minimum message count
        if (get_option('owui_email_on_long_conversations') && count($messages) < 3) {
            return false;
        }
        
        // Check if contact info is required
        if (get_option('owui_email_on_contact_info', 1)) {
            $contacts = $this->contact_extractor->get_session_contacts($session_id);
            if (empty($contacts)) {
                return false;
            }
        }
        
        // Check for keywords
        if (get_option('owui_email_on_keywords')) {
            $keywords = array_map('trim', explode(',', get_option('owui_notification_keywords', '')));
            $has_keyword = false;
            
            foreach ($messages as $message) {
                $text = strtolower($message->message . ' ' . $message->response);
                foreach ($keywords as $keyword) {
                    if (!empty($keyword) && strpos($text, strtolower($keyword)) !== false) {
                        $has_keyword = true;
                        break 2;
                    }
                }
            }
            
            if (!$has_keyword) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Send conversation summary email
     */
    public function send_conversation_summary($session_id, $reason = 'timeout') {
        global $wpdb;
        
        // Get session details
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, c.name as chatbot_name, u.display_name as user_name, u.user_email
            FROM {$wpdb->prefix}owui_chat_sessions s
            LEFT JOIN {$wpdb->prefix}owui_chatbots c ON s.chatbot_id = c.id
            LEFT JOIN {$wpdb->prefix}users u ON s.user_id = u.ID
            WHERE s.id = %d",
            $session_id
        ));
        
        if (!$session) {
            return false;
        }
        
        // Get messages
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}owui_chat_history WHERE session_id = %d ORDER BY created_at ASC",
            $session_id
        ));
        
        // Get contact information
        $contacts = $this->contact_extractor->get_session_contacts($session_id);
        
        // Generate conversation summary using AI
        $summary = $this->generate_ai_summary($messages, $session);
        
        // Build email content
        $email_content = $this->build_email_content($session, $messages, $contacts, $summary, $reason);
        
        // Send email
        $to = get_option('owui_notification_email', get_option('admin_email'));
        $subject = get_option('owui_email_subject', 'New Chatbot Conversation Summary');
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>'
        );
        
        return wp_mail($to, $subject, $email_content, $headers);
    }
    
    /**
     * Generate AI-powered conversation summary
     */
    private function generate_ai_summary($messages, $session) {
        if (empty($messages) || count($messages) < 2) {
            return 'Short conversation with minimal interaction.';
        }
        
        // Prepare conversation text for AI analysis
        $conversation_text = "Please provide a brief summary of this customer service conversation:\n\n";
        
        foreach ($messages as $message) {
            $conversation_text .= "Customer: " . $message->message . "\n";
            $conversation_text .= "Assistant: " . $message->response . "\n\n";
        }
        
        $conversation_text .= "\nPlease summarize: 1) What the customer wanted, 2) Key issues discussed, 3) Resolution status, 4) Next steps (if any). Keep it under 150 words.";
        
        // Use the chatbot's model to generate summary
        try {
            $response = $this->api->send_chat_message(
                'gpt-3.5-turbo', // Fallback model for summaries
                $conversation_text,
                'You are an expert at summarizing customer service conversations. Provide clear, concise summaries that highlight the key points and outcomes.'
            );
            
            if (isset($response['content'])) {
                return $response['content'];
            }
        } catch (Exception $e) {
            error_log('OpenWebUI Summary Generation Error: ' . $e->getMessage());
        }
        
        // Fallback summary
        return $this->generate_simple_summary($messages);
    }
    
    /**
     * Generate simple summary without AI
     */
    private function generate_simple_summary($messages) {
        $message_count = count($messages);
        $duration = human_time_diff(
            strtotime($messages[0]->created_at),
            strtotime(end($messages)->created_at)
        );
        
        $first_message = wp_trim_words($messages[0]->message, 15);
        
        return "Conversation with {$message_count} messages lasting {$duration}. Started with: \"{$first_message}\"";
    }
    
    /**
     * Build HTML email content
     */
    private function build_email_content($session, $messages, $contacts, $summary, $reason) {
        $site_name = get_option('blogname');
        $site_url = get_option('home');
        $header_text = get_option('owui_email_header', 'A new conversation has ended on your website. Here\'s a summary:');
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conversation Summary</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f5f5f5; }
        .container { max-width: 600px; margin: 20px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: #0073aa; color: white; padding: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 30px; }
        .summary-box { background: #f8f9fa; padding: 20px; border-radius: 6px; margin: 20px 0; border-left: 4px solid #0073aa; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0; }
        .info-box { background: #f9f9f9; padding: 15px; border-radius: 6px; }
        .info-box h4 { margin: 0 0 10px 0; color: #0073aa; font-size: 14px; text-transform: uppercase; }
        .contact-info { background: #e8f5e8; border-left: 4px solid #46b450; padding: 15px; margin: 15px 0; border-radius: 6px; }
        .conversation { background: #fff; border: 1px solid #ddd; border-radius: 6px; margin: 20px 0; max-height: 400px; overflow-y: auto; }
        .message { padding: 15px; border-bottom: 1px solid #eee; }
        .message:last-child { border-bottom: none; }
        .message.user { background: #f0f8ff; }
        .message.bot { background: #f8f8f8; }
        .message-label { font-weight: bold; color: #0073aa; font-size: 12px; text-transform: uppercase; margin-bottom: 5px; }
        .message-text { margin: 5px 0; }
        .message-time { font-size: 11px; color: #666; }
        .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; }
        .btn { display: inline-block; background: #0073aa; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; margin: 10px 5px; }
        @media (max-width: 600px) { .info-grid { grid-template-columns: 1fr; } .container { margin: 10px; } }
    </style>
</head>
<body>';
        
        $html .= '<div class="container">';
        $html .= '<div class="header">';
        $html .= '<h1>💬 Conversation Summary</h1>';
        $html .= '<p>' . esc_html($header_text) . '</p>';
        $html .= '</div>';
        
        $html .= '<div class="content">';
        
        // Summary section
        $html .= '<div class="summary-box">';
        $html .= '<h3>🤖 AI Summary</h3>';
        $html .= '<p>' . nl2br(esc_html($summary)) . '</p>';
        $html .= '</div>';
        
        // Info grid
        $html .= '<div class="info-grid">';
        
        $html .= '<div class="info-box">';
        $html .= '<h4>📊 Conversation Details</h4>';
        $html .= '<p><strong>Chatbot:</strong> ' . esc_html($session->chatbot_name ?: 'Unknown') . '</p>';
        $html .= '<p><strong>User:</strong> ' . esc_html($session->user_name ?: 'Guest') . '</p>';
        $html .= '<p><strong>Messages:</strong> ' . count($messages) . '</p>';
        $html .= '<p><strong>Started:</strong> ' . date('M j, Y g:i A', strtotime($session->started_at)) . '</p>';
        $html .= '<p><strong>Ended:</strong> ' . ucfirst($reason) . '</p>';
        $html .= '</div>';
        
        $html .= '<div class="info-box">';
        $html .= '<h4>🌐 Session Info</h4>';
        $html .= '<p><strong>Session ID:</strong> ' . $session->id . '</p>';
        $html .= '<p><strong>Duration:</strong> ' . human_time_diff(strtotime($session->started_at), strtotime($session->ended_at ?: current_time('mysql'))) . '</p>';
        if ($session->user_email) {
            $html .= '<p><strong>User Email:</strong> ' . esc_html($session->user_email) . '</p>';
        }
        $html .= '</div>';
        
        $html .= '</div>';
        
        // Contact information
        if (!empty($contacts)) {
            $html .= '<div class="contact-info">';
            $html .= '<h4>📞 Contact Information Collected</h4>';
            
            $contact_types = array();
            foreach ($contacts as $contact) {
                if (!isset($contact_types[$contact->contact_type])) {
                    $contact_types[$contact->contact_type] = array();
                }
                $contact_types[$contact->contact_type][] = $contact->contact_value;
            }
            
            foreach ($contact_types as $type => $values) {
                $html .= '<p><strong>' . ucfirst($type) . ':</strong> ' . implode(', ', array_map('esc_html', $values)) . '</p>';
            }
            $html .= '</div>';
        }
        
        // Conversation transcript
        $html .= '<h4>💬 Conversation Transcript</h4>';
        $html .= '<div class="conversation">';
        
        foreach ($messages as $message) {
            $html .= '<div class="message user">';
            $html .= '<div class="message-label">👤 User</div>';
            $html .= '<div class="message-text">' . nl2br(esc_html($message->message)) . '</div>';
            $html .= '<div class="message-time">' . date('g:i A', strtotime($message->created_at)) . '</div>';
            $html .= '</div>';
            
            $html .= '<div class="message bot">';
            $html .= '<div class="message-label">🤖 Assistant</div>';
            $html .= '<div class="message-text">' . nl2br(esc_html($message->response)) . '</div>';
            $html .= '<div class="message-time">' . date('g:i A', strtotime($message->created_at)) . '</div>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        
        // Action buttons
        $view_url = admin_url('admin.php?page=owui-history&conversation=' . $session->id);
        $html .= '<div style="text-align: center; margin: 30px 0;">';
        $html .= '<a href="' . $view_url . '" class="btn">View Full Conversation</a>';
        $html .= '<a href="' . admin_url('admin.php?page=owui-history') . '" class="btn">View All Conversations</a>';
        $html .= '</div>';
        
        $html .= '</div>';
        
        $html .= '<div class="footer">';
        $html .= '<p>This email was sent by <strong>' . esc_html($site_name) . '</strong></p>';
        $html .= '<p><a href="' . $site_url . '">' . $site_url . '</a></p>';
        $html .= '<p style="font-size: 11px; color: #999;">You can disable these notifications in your OpenWebUI Chatbot settings.</p>';
        $html .= '</div>';
        
        $html .= '</div>';
        $html .= '</body></html>';
        
        return $html;
    }
    
    /**
     * Test email functionality
     */
    public function ajax_test_email() {
        check_ajax_referer('owui_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $to = get_option('owui_notification_email', get_option('admin_email'));
        $subject = 'OpenWebUI Chatbot - Test Email';
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 20px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: #0073aa; color: white; padding: 30px; text-align: center; }
        .content { padding: 30px; }
        .success-box { background: #e8f5e8; border-left: 4px solid #46b450; padding: 20px; border-radius: 6px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>✅ Test Email Successful!</h1>
        </div>
        <div class="content">
            <div class="success-box">
                <h3>Email System Working Correctly</h3>
                <p>Your OpenWebUI Chatbot email notification system is configured properly and working correctly!</p>
                <p><strong>Configuration Details:</strong></p>
                <ul>
                    <li><strong>Notification Email:</strong> ' . esc_html($to) . '</li>
                    <li><strong>Session Timeout:</strong> ' . get_option('owui_session_timeout', 15) . ' minutes</li>
                    <li><strong>WordPress Site:</strong> ' . get_option('blogname') . '</li>
                    <li><strong>Test Time:</strong> ' . current_time('mysql') . '</li>
                </ul>
                <p>When conversations end, you\'ll receive detailed summaries with contact information and AI-generated insights.</p>
            </div>
        </div>
    </div>
</body>
</html>';
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>'
        );
        
        $result = wp_mail($to, $subject, $html, $headers);
        
        if ($result) {
            wp_send_json_success('Test email sent successfully to ' . $to);
        } else {
            wp_send_json_error('Failed to send test email. Please check your WordPress email configuration.');
        }
    }
    
    /**
     * Manually end a session (called from frontend)
     */
    public static function end_session_manually($session_id) {
        $instance = new self();
        $instance->end_session_and_notify($session_id, 'manual');
    }
    
    /**
     * Check if a session should be considered ended due to page unload
     */
    public function handle_page_unload($session_id) {
        // Add a small delay before ending session to allow for quick page reloads
        wp_schedule_single_event(time() + 60, 'owui_delayed_session_end', array($session_id));
    }
    
    /**
     * Handle delayed session ending
     */
    public function handle_delayed_session_end($session_id) {
        global $wpdb;
        
        // Check if session had any recent activity (within last 2 minutes)
        $recent_activity = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}owui_chat_history 
            WHERE session_id = %d AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)",
            $session_id
        ));
        
        // Only end session if no recent activity
        if (!$recent_activity) {
            $this->end_session_and_notify($session_id, 'page_close');
        }
    }
    
    /**
     * Get conversation statistics for email
     */
    private function get_conversation_stats($messages) {
        if (empty($messages)) {
            return array();
        }
        
        $stats = array();
        $stats['message_count'] = count($messages);
        $stats['user_words'] = 0;
        $stats['bot_words'] = 0;
        $stats['duration'] = human_time_diff(
            strtotime($messages[0]->created_at),
            strtotime(end($messages)->created_at)
        );
        
        foreach ($messages as $message) {
            $stats['user_words'] += str_word_count($message->message);
            $stats['bot_words'] += str_word_count($message->response);
        }
        
        return $stats;
    }
    
    /**
     * Clean up old notification data
     */
    public function cleanup_old_notifications() {
        // This could be used to clean up any notification logs if we implement them
        // For now, it's a placeholder for future enhancement
    }
}