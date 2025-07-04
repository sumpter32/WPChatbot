<?php
if (!defined('ABSPATH')) exit;

class OWUI_Session_Manager {
    
    public function __construct() {
        // Initialize session if not already started
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
    }
    
    public function get_session_id() {
        if (!session_id()) {
            return null;
        }
        
        return session_id();
    }
    
    public function create_session() {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        
        return session_id();
    }
    
    public function destroy_session() {
        if (session_id()) {
            session_destroy();
        }
    }
    
    public function set_session_data($key, $value) {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        
        $_SESSION['owui_' . $key] = $value;
    }
    
    public function get_session_data($key, $default = null) {
        if (!session_id()) {
            return $default;
        }
        
        return $_SESSION['owui_' . $key] ?? $default;
    }
    
    public function clear_session_data($key) {
        if (session_id() && isset($_SESSION['owui_' . $key])) {
            unset($_SESSION['owui_' . $key]);
        }
    }
    
    public function get_or_create_session($chatbot_id, $user_id = null) {
        $session_id = $this->get_or_create_session_id();
        
        global $wpdb;
        
        // Look for existing active session for this chatbot and session
        $existing_session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}owui_chat_sessions 
            WHERE session_id = %s AND chatbot_id = %d 
            AND (ended_at IS NULL OR ended_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE))",
            $session_id, $chatbot_id
        ));
        
        if ($existing_session) {
            return $existing_session->id;
        }
        
        // Create new session record
        $result = $wpdb->insert(
            $wpdb->prefix . 'owui_chat_sessions',
            array(
                'chatbot_id' => $chatbot_id,
                'user_id' => $user_id,
                'session_id' => $session_id,
                'started_at' => current_time('mysql')
            ),
            array('%d', '%d', '%s', '%s')
        );
        
        if ($result) {
            return $wpdb->insert_id;
        }
        
        return null;
    }
    
    public function get_or_create_session_id() {
        $session_id = $this->get_session_id();
        
        if (!$session_id) {
            $session_id = $this->create_session();
        }
        
        return $session_id;
    }
    
    public function get_conversation_context($session_db_id, $limit = 10) {
        global $wpdb;
        
        // Get recent conversation history for this session
        $history = $wpdb->get_results($wpdb->prepare(
            "SELECT message, response FROM {$wpdb->prefix}owui_chat_history 
            WHERE session_id = %d 
            ORDER BY created_at DESC 
            LIMIT %d",
            $session_db_id, $limit
        ));
        
        // Reverse to get chronological order (oldest first)
        return array_reverse($history);
    }
    
    public function is_session_active() {
        return session_id() !== '';
    }
    
    public function end_session($session_db_id) {
        global $wpdb;
        
        $wpdb->update(
            $wpdb->prefix . 'owui_chat_sessions',
            array('ended_at' => current_time('mysql')),
            array('id' => $session_db_id),
            array('%s'),
            array('%d')
        );
    }
}