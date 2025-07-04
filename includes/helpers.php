<?php

if (!defined('ABSPATH')) exit;

/**
 * Get chatbot by ID
 */
function owui_get_chatbot($chatbot_id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}owui_chatbots WHERE id = %d",
        absint($chatbot_id)
    ));
}

/**
 * Get active chatbots
 */
function owui_get_active_chatbots() {
    global $wpdb;
    return $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}owui_chatbots WHERE is_active = 1 ORDER BY name ASC"
    );
}

/**
 * Get chat history for a session
 */
function owui_get_session_history($session_id, $limit = 50) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}owui_chat_history 
        WHERE session_id = %d 
        ORDER BY created_at DESC 
        LIMIT %d",
        absint($session_id),
        absint($limit)
    ));
}

/**
 * Format file size
 */
function owui_format_file_size($bytes) {
    $units = array('B', 'KB', 'MB', 'GB');
    $i = 0;
    
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    
    return round($bytes, 2) . ' ' . $units[$i];
}

/**
 * Check if file type is allowed
 */
function owui_is_file_type_allowed($filename) {
    $allowed_types = array('pdf', 'txt', 'doc', 'docx', 'csv', 'json', 'md');
    $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    return in_array($file_ext, $allowed_types);
}

/**
 * Sanitize chatbot data
 */
function owui_sanitize_chatbot_data($data) {
    $sanitized = array();
    
    if (isset($data['name'])) {
        $sanitized['name'] = sanitize_text_field($data['name']);
    }
    
    if (isset($data['model'])) {
        $sanitized['model'] = sanitize_text_field($data['model']);
    }
    
    if (isset($data['system_prompt'])) {
        $sanitized['system_prompt'] = sanitize_textarea_field($data['system_prompt']);
    }
    
    if (isset($data['greeting_message'])) {
        $sanitized['greeting_message'] = sanitize_textarea_field($data['greeting_message']);
    }
    
    if (isset($data['avatar_url'])) {
        $sanitized['avatar_url'] = esc_url_raw($data['avatar_url']);
    }
    
    if (isset($data['is_active'])) {
        $sanitized['is_active'] = absint($data['is_active']);
    }
    
    return $sanitized;
}

/**
 * Get conversation statistics
 */
function owui_get_conversation_stats($chatbot_id = null, $days = 30) {
    global $wpdb;
    
    $where = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)";
    $params = array($days);
    
    if ($chatbot_id) {
        $where .= " AND chatbot_id = %d";
        $params[] = $chatbot_id;
    }
    
    $query = "
        SELECT 
            COUNT(DISTINCT session_id) as total_sessions,
            COUNT(*) as total_messages,
            AVG(CHAR_LENGTH(response)) as avg_response_length,
            DATE(created_at) as date,
            COUNT(*) as daily_messages
        FROM {$wpdb->prefix}owui_chat_history
        {$where}
        GROUP BY DATE(created_at)
        ORDER BY date DESC
    ";
    
    return $wpdb->get_results($wpdb->prepare($query, $params));
}

/**
 * Export chat history
 */
function owui_export_chat_history($format = 'csv', $chatbot_id = null, $session_id = null) {
    global $wpdb;
    
    $where = "WHERE 1=1";
    $params = array();
    
    if ($chatbot_id) {
        $where .= " AND h.chatbot_id = %d";
        $params[] = $chatbot_id;
    }
    
    if ($session_id) {
        $where .= " AND h.session_id = %d";
        $params[] = $session_id;
    }
    
    $query = "
        SELECT 
            h.*,
            c.name as chatbot_name,
            u.display_name as user_name
        FROM {$wpdb->prefix}owui_chat_history h
        LEFT JOIN {$wpdb->prefix}owui_chatbots c ON h.chatbot_id = c.id
        LEFT JOIN {$wpdb->prefix}users u ON h.user_id = u.ID
        {$where}
        ORDER BY h.created_at DESC
    ";
    
    $conversations = $wpdb->get_results($wpdb->prepare($query, $params));
    
    if ($format === 'json') {
        return json_encode($conversations, JSON_PRETTY_PRINT);
    }
    
    // Default to CSV
    $output = fopen('php://temp', 'w');
    
    // Add BOM for Excel UTF-8 compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Headers
    fputcsv($output, array(
        'Date',
        'Time',
        'Chatbot',
        'User',
        'Message',
        'Response',
        'Session ID'
    ));
    
    // Data
    foreach ($conversations as $conv) {
        fputcsv($output, array(
            date('Y-m-d', strtotime($conv->created_at)),
            date('H:i:s', strtotime($conv->created_at)),
            $conv->chatbot_name ?: 'Unknown',
            $conv->user_name ?: 'Guest',
            $conv->message,
            $conv->response,
            $conv->session_id
        ));
    }
    
    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);
    
    return $csv;
}

/**
 * Clean up old sessions
 */
function owui_cleanup_old_sessions($days = 30) {
    global $wpdb;
    
    // Mark sessions as ended
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}owui_chat_sessions 
        SET ended_at = NOW() 
        WHERE ended_at IS NULL 
        AND started_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
        $days
    ));
    
    // Optional: Delete very old sessions
    if (apply_filters('owui_delete_old_sessions', false)) {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}owui_chat_sessions 
            WHERE ended_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days * 3
        ));
    }
}

/**
 * Get popular questions
 */
function owui_get_popular_questions($chatbot_id = null, $limit = 10) {
    global $wpdb;
    
    $where = "WHERE 1=1";
    $params = array();
    
    if ($chatbot_id) {
        $where .= " AND chatbot_id = %d";
        $params[] = $chatbot_id;
    }
    
    $params[] = $limit;
    
    $query = "
        SELECT 
            message,
            COUNT(*) as count
        FROM {$wpdb->prefix}owui_chat_history
        {$where}
        GROUP BY message
        ORDER BY count DESC
        LIMIT %d
    ";
    
    return $wpdb->get_results($wpdb->prepare($query, $params));
}

/**
 * Log analytics event
 */
function owui_log_analytics_event($chatbot_id, $event_type, $event_data = array()) {
    global $wpdb;
    
    $wpdb->insert(
        $wpdb->prefix . 'owui_analytics',
        array(
            'chatbot_id' => $chatbot_id,
            'event_type' => $event_type,
            'event_data' => json_encode($event_data),
            'created_at' => current_time('mysql')
        ),
        array('%d', '%s', '%s', '%s')
    );
}

/**
 * Check if Pro features are available
 */
function owui_is_pro() {
    return apply_filters('owui_pro_features_enabled', false);
}

/**
 * Display Pro feature notice
 */
function owui_pro_feature_notice($feature_name) {
    if (!owui_is_pro()) {
        echo '<div class="owui-pro-notice">';
        echo '<p>' . sprintf(
            esc_html__('%s is a Pro feature. Upgrade to Pro to unlock this and other advanced features.', 'openwebui-chatbot'),
            esc_html($feature_name)
        ) . '</p>';
        echo '<a href="https://example.com/pro" class="button button-primary" target="_blank">' . 
             esc_html__('Upgrade to Pro', 'openwebui-chatbot') . '</a>';
        echo '</div>';
    }
}

/**
 * Get chatbot embed code
 */
function owui_get_embed_code($chatbot_id, $type = 'floating') {
    $code = '';
    
    switch ($type) {
        case 'floating':
            $code = '[openwebui_chatbot id="' . $chatbot_id . '" type="floating"]';
            break;
            
        case 'inline':
            $code = '[openwebui_chatbot id="' . $chatbot_id . '" type="inline" width="400" height="600"]';
            break;
            
        case 'button':
            $code = '[openwebui_chatbot id="' . $chatbot_id . '" type="button" button_text="Chat with us"]';
            break;
    }
    
    return $code;
}
