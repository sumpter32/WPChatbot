<?php
/**
 * Database utilities for OpenWebUI Chatbot
 */

if (!defined('ABSPATH')) {
    exit;
}

class OWUI_Database {
    
    private static $instance = null;
    private $wpdb;
    
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Get chatbot by ID with proper validation
     */
    public function get_chatbot($chatbot_id) {
        $chatbot_id = absint($chatbot_id);
        if ($chatbot_id <= 0) {
            return null;
        }
        
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->wpdb->prefix}owui_chatbots WHERE id = %d",
                $chatbot_id
            )
        );
    }
    
    /**
     * Get active chatbots with caching
     */
    public function get_active_chatbots($use_cache = true) {
        $cache_key = 'owui_active_chatbots';
        
        if ($use_cache) {
            $cached = wp_cache_get($cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        $chatbots = $this->wpdb->get_results(
            "SELECT * FROM {$this->wpdb->prefix}owui_chatbots 
             WHERE is_active = 1 
             ORDER BY name ASC"
        );
        
        if ($use_cache && $chatbots !== null) {
            wp_cache_set($cache_key, $chatbots, '', 300); // Cache for 5 minutes
        }
        
        return $chatbots ?: [];
    }
    
    /**
     * Create chatbot with validation
     */
    public function create_chatbot($data) {
        $sanitized_data = OWUI_Security::sanitize_chatbot_data($data);
        $sanitized_data['created_at'] = current_time('mysql', true);
        
        $format = ['%s', '%s', '%s', '%s', '%d', '%s'];
        
        if (isset($sanitized_data['avatar_url'])) {
            $format[] = '%s';
        }
        
        $result = $this->wpdb->insert(
            $this->wpdb->prefix . 'owui_chatbots',
            $sanitized_data,
            $format
        );
        
        if ($result === false) {
            throw new Exception(__('Failed to create chatbot: ', 'openwebui-chatbot') . $this->wpdb->last_error);
        }
        
        // Clear cache
        wp_cache_delete('owui_active_chatbots');
        
        return $this->wpdb->insert_id;
    }
    
    /**
     * Update chatbot with validation
     */
    public function update_chatbot($chatbot_id, $data) {
        $chatbot_id = absint($chatbot_id);
        if ($chatbot_id <= 0) {
            throw new InvalidArgumentException(__('Invalid chatbot ID.', 'openwebui-chatbot'));
        }
        
        $sanitized_data = OWUI_Security::sanitize_chatbot_data($data);
        $sanitized_data['updated_at'] = current_time('mysql', true);
        
        $format = array_fill(0, count($sanitized_data), '%s');
        $format[array_search('is_active', array_keys($sanitized_data))] = '%d';
        
        $result = $this->wpdb->update(
            $this->wpdb->prefix . 'owui_chatbots',
            $sanitized_data,
            ['id' => $chatbot_id],
            $format,
            ['%d']
        );
        
        if ($result === false) {
            throw new Exception(__('Failed to update chatbot: ', 'openwebui-chatbot') . $this->wpdb->last_error);
        }
        
        // Clear cache
        wp_cache_delete('owui_active_chatbots');
        
        return $result;
    }
    
    /**
     * Delete chatbot and associated data
     */
    public function delete_chatbot($chatbot_id) {
        $chatbot_id = absint($chatbot_id);
        if ($chatbot_id <= 0) {
            throw new InvalidArgumentException(__('Invalid chatbot ID.', 'openwebui-chatbot'));
        }
        
        // Start transaction
        $this->wpdb->query('START TRANSACTION');
        
        try {
            // Delete associated contact info (via foreign key cascade)
            // Delete associated chat history (via foreign key cascade)
            // Delete associated sessions (via foreign key cascade)
            
            // Delete chatbot
            $result = $this->wpdb->delete(
                $this->wpdb->prefix . 'owui_chatbots',
                ['id' => $chatbot_id],
                ['%d']
            );
            
            if ($result === false) {
                throw new Exception(__('Failed to delete chatbot.', 'openwebui-chatbot'));
            }
            
            $this->wpdb->query('COMMIT');
            
            // Clear cache
            wp_cache_delete('owui_active_chatbots');
            
            return true;
            
        } catch (Exception $e) {
            $this->wpdb->query('ROLLBACK');
            throw $e;
        }
    }
    
    /**
     * Create or get session with proper handling
     */
    public function get_or_create_session($chatbot_id, $user_id = null, $session_id = null) {
        $chatbot_id = absint($chatbot_id);
        if ($chatbot_id <= 0) {
            throw new InvalidArgumentException(__('Invalid chatbot ID.', 'openwebui-chatbot'));
        }
        
        // Validate chatbot exists and is active
        $chatbot = $this->get_chatbot($chatbot_id);
        if (!$chatbot || !$chatbot->is_active) {
            throw new Exception(__('Chatbot not found or inactive.', 'openwebui-chatbot'));
        }
        
        if (empty($session_id)) {
            $session_id = session_id() ?: wp_generate_uuid4();
        }
        
        // Look for existing active session
        $existing_session = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->wpdb->prefix}owui_chat_sessions 
                 WHERE session_id = %s AND chatbot_id = %d 
                 AND (ended_at IS NULL OR ended_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE))",
                $session_id,
                $chatbot_id
            )
        );
        
        if ($existing_session) {
            return $existing_session->id;
        }
        
        // Create new session
        $session_data = [
            'chatbot_id' => $chatbot_id,
            'user_id' => $user_id ? absint($user_id) : null,
            'session_id' => sanitize_text_field($session_id),
            'started_at' => current_time('mysql', true),
            'ip_address' => OWUI_Security::get_client_ip(),
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '')
        ];
        
        $result = $this->wpdb->insert(
            $this->wpdb->prefix . 'owui_chat_sessions',
            $session_data,
            ['%d', '%d', '%s', '%s', '%s', '%s']
        );
        
        if ($result === false) {
            throw new Exception(__('Failed to create session: ', 'openwebui-chatbot') . $this->wpdb->last_error);
        }
        
        return $this->wpdb->insert_id;
    }
    
    /**
     * Save chat message with validation
     */
    public function save_chat_message($session_db_id, $chatbot_id, $user_id, $message, $response, $tokens_used = 0, $response_time = 0) {
        $data = [
            'chatbot_id' => absint($chatbot_id),
            'session_id' => absint($session_db_id),
            'user_id' => $user_id ? absint($user_id) : null,
            'message' => OWUI_Security::sanitize_message($message),
            'response' => sanitize_textarea_field($response),
            'tokens_used' => absint($tokens_used),
            'response_time' => floatval($response_time),
            'created_at' => current_time('mysql', true)
        ];
        
        $result = $this->wpdb->insert(
            $this->wpdb->prefix . 'owui_chat_history',
            $data,
            ['%d', '%d', '%d', '%s', '%s', '%d', '%f', '%s']
        );
        
        if ($result === false) {
            throw new Exception(__('Failed to save chat message: ', 'openwebui-chatbot') . $this->wpdb->last_error);
        }
        
        return $this->wpdb->insert_id;
    }
    
    /**
     * Get conversation context with pagination
     */
    public function get_conversation_context($session_db_id, $limit = 10) {
        $session_db_id = absint($session_db_id);
        $limit = absint($limit);
        
        if ($session_db_id <= 0 || $limit <= 0) {
            return [];
        }
        
        $history = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT message, response, created_at 
                 FROM {$this->wpdb->prefix}owui_chat_history 
                 WHERE session_id = %d 
                 ORDER BY created_at DESC 
                 LIMIT %d",
                $session_db_id,
                $limit
            )
        );
        
        // Reverse to get chronological order
        return $history ? array_reverse($history) : [];
    }
    
    /**
     * End session
     */
    public function end_session($session_db_id) {
        $session_db_id = absint($session_db_id);
        if ($session_db_id <= 0) {
            return false;
        }
        
        $result = $this->wpdb->update(
            $this->wpdb->prefix . 'owui_chat_sessions',
            ['ended_at' => current_time('mysql', true)],
            ['id' => $session_db_id],
            ['%s'],
            ['%d']
        );
        
        return $result !== false;
    }
    
    /**
     * Get conversations with pagination and filtering
     */
    public function get_conversations($args = []) {
        $defaults = [
            'chatbot_id' => null,
            'user_id' => null,
            'limit' => 50,
            'offset' => 0,
            'date_from' => null,
            'date_to' => null,
            'has_contacts' => null
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        $where_conditions = ['1=1'];
        $where_values = [];
        
        if ($args['chatbot_id']) {
            $where_conditions[] = 'h.chatbot_id = %d';
            $where_values[] = absint($args['chatbot_id']);
        }
        
        if ($args['user_id']) {
            $where_conditions[] = 'h.user_id = %d';
            $where_values[] = absint($args['user_id']);
        }
        
        if ($args['date_from']) {
            $where_conditions[] = 'h.created_at >= %s';
            $where_values[] = sanitize_text_field($args['date_from']);
        }
        
        if ($args['date_to']) {
            $where_conditions[] = 'h.created_at <= %s';
            $where_values[] = sanitize_text_field($args['date_to']);
        }
        
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        
        $query = "
            SELECT 
                s.*,
                c.name as chatbot_name,
                u.display_name as user_name,
                u.user_email,
                COUNT(h.id) as message_count,
                MAX(h.created_at) as last_message,
                MIN(h.message) as first_message
            FROM {$this->wpdb->prefix}owui_chat_sessions s
            LEFT JOIN {$this->wpdb->prefix}owui_chatbots c ON s.chatbot_id = c.id
            LEFT JOIN {$this->wpdb->prefix}users u ON s.user_id = u.ID
            LEFT JOIN {$this->wpdb->prefix}owui_chat_history h ON s.id = h.session_id
            {$where_clause}
            GROUP BY s.id
            HAVING message_count > 0
            ORDER BY last_message DESC
            LIMIT %d OFFSET %d
        ";
        
        $where_values[] = absint($args['limit']);
        $where_values[] = absint($args['offset']);
        
        if (!empty($where_values)) {
            return $this->wpdb->get_results(
                $this->wpdb->prepare($query, $where_values)
            );
        } else {
            return $this->wpdb->get_results($query);
        }
    }
    
    /**
     * Get conversation statistics
     */
    public function get_conversation_stats($chatbot_id = null, $days = 30) {
        $where = 'WHERE h.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)';
        $params = [absint($days)];
        
        if ($chatbot_id) {
            $where .= ' AND h.chatbot_id = %d';
            $params[] = absint($chatbot_id);
        }
        
        $query = "
            SELECT 
                COUNT(DISTINCT h.session_id) as total_sessions,
                COUNT(h.id) as total_messages,
                AVG(CHAR_LENGTH(h.response)) as avg_response_length,
                AVG(h.response_time) as avg_response_time,
                SUM(h.tokens_used) as total_tokens_used,
                DATE(h.created_at) as date,
                COUNT(h.id) as daily_messages
            FROM {$this->wpdb->prefix}owui_chat_history h
            {$where}
            GROUP BY DATE(h.created_at)
            ORDER BY date DESC
        ";
        
        return $this->wpdb->get_results(
            $this->wpdb->prepare($query, $params)
        );
    }
    
    /**
     * Clean up old sessions and data
     */
    public function cleanup_old_data($days = 30) {
        $this->wpdb->query('START TRANSACTION');
        
        try {
            // Mark old sessions as ended
            $this->wpdb->query(
                $this->wpdb->prepare(
                    "UPDATE {$this->wpdb->prefix}owui_chat_sessions 
                     SET ended_at = NOW() 
                     WHERE ended_at IS NULL 
                     AND started_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                    $days
                )
            );
            
            // Optional: Delete very old data if enabled
            if (apply_filters('owui_delete_old_data', false)) {
                $delete_days = absint(apply_filters('owui_delete_old_data_days', $days * 3));
                
                // Delete old contact info
                $this->wpdb->query(
                    $this->wpdb->prepare(
                        "DELETE FROM {$this->wpdb->prefix}owui_contact_info 
                         WHERE extracted_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                        $delete_days
                    )
                );
                
                // Delete old chat history
                $this->wpdb->query(
                    $this->wpdb->prepare(
                        "DELETE FROM {$this->wpdb->prefix}owui_chat_history 
                         WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                        $delete_days
                    )
                );
                
                // Delete old sessions
                $this->wpdb->query(
                    $this->wpdb->prepare(
                        "DELETE FROM {$this->wpdb->prefix}owui_chat_sessions 
                         WHERE ended_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                        $delete_days
                    )
                );
            }
            
            $this->wpdb->query('COMMIT');
            
            owui_log_info('Database cleanup completed', ['days' => $days]);
            
        } catch (Exception $e) {
            $this->wpdb->query('ROLLBACK');
            owui_log_error('Database Cleanup', $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get dashboard statistics with caching
     */
    public function get_dashboard_stats() {
        $cache_key = 'owui_dashboard_stats';
        $cached = wp_cache_get($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $stats = [];
        
        // Active chatbots
        $stats['active_chatbots'] = $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->wpdb->prefix}owui_chatbots WHERE is_active = 1"
        );
        
        // Total conversations
        $stats['total_conversations'] = $this->wpdb->get_var(
            "SELECT COUNT(DISTINCT session_id) FROM {$this->wpdb->prefix}owui_chat_history WHERE session_id IS NOT NULL"
        );
        
        // Total messages
        $total_messages = $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->wpdb->prefix}owui_chat_history"
        );
        
        // Messages with responses
        $responded_messages = $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->wpdb->prefix}owui_chat_history WHERE response != ''"
        );
        
        $stats['response_rate'] = $total_messages > 0 ? round(($responded_messages / $total_messages) * 100, 1) : 100;
        
        // Contacts collected
        $stats['total_contacts'] = $this->wpdb->get_var(
            "SELECT COUNT(DISTINCT session_id) FROM {$this->wpdb->prefix}owui_contact_info"
        );
        
        // Recent activity (last 24 hours)
        $stats['recent_messages'] = $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->wpdb->prefix}owui_chat_history 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        
        wp_cache_set($cache_key, $stats, '', 300); // Cache for 5 minutes
        
        return $stats;
    }
    
    /**
     * Search conversations
     */
    public function search_conversations($search_term, $args = []) {
        $defaults = [
            'limit' => 50,
            'offset' => 0,
            'chatbot_id' => null
        ];
        
        $args = wp_parse_args($args, $defaults);
        $search_term = '%' . $this->wpdb->esc_like(sanitize_text_field($search_term)) . '%';
        
        $where_conditions = [
            '(h.message LIKE %s OR h.response LIKE %s OR c.name LIKE %s OR u.display_name LIKE %s)'
        ];
        $where_values = [$search_term, $search_term, $search_term, $search_term];
        
        if ($args['chatbot_id']) {
            $where_conditions[] = 'h.chatbot_id = %d';
            $where_values[] = absint($args['chatbot_id']);
        }
        
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        
        $query = "
            SELECT 
                h.*,
                c.name as chatbot_name,
                u.display_name as user_name
            FROM {$this->wpdb->prefix}owui_chat_history h
            LEFT JOIN {$this->wpdb->prefix}owui_chatbots c ON h.chatbot_id = c.id
            LEFT JOIN {$this->wpdb->prefix}users u ON h.user_id = u.ID
            {$where_clause}
            ORDER BY h.created_at DESC
            LIMIT %d OFFSET %d
        ";
        
        $where_values[] = absint($args['limit']);
        $where_values[] = absint($args['offset']);
        
        return $this->wpdb->get_results(
            $this->wpdb->prepare($query, $where_values)
        );
    }
    
    /**
     * Export chat history with filtering
     */
    public function export_chat_history($args = []) {
        $defaults = [
            'chatbot_id' => null,
            'session_id' => null,
            'date_from' => null,
            'date_to' => null,
            'format' => 'array'
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        $where_conditions = ['1=1'];
        $where_values = [];
        
        if ($args['chatbot_id']) {
            $where_conditions[] = 'h.chatbot_id = %d';
            $where_values[] = absint($args['chatbot_id']);
        }
        
        if ($args['session_id']) {
            $where_conditions[] = 'h.session_id = %d';
            $where_values[] = absint($args['session_id']);
        }
        
        if ($args['date_from']) {
            $where_conditions[] = 'h.created_at >= %s';
            $where_values[] = sanitize_text_field($args['date_from']);
        }
        
        if ($args['date_to']) {
            $where_conditions[] = 'h.created_at <= %s';
            $where_values[] = sanitize_text_field($args['date_to']);
        }
        
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        
        $query = "
            SELECT 
                h.*,
                c.name as chatbot_name,
                u.display_name as user_name,
                u.user_email
            FROM {$this->wpdb->prefix}owui_chat_history h
            LEFT JOIN {$this->wpdb->prefix}owui_chatbots c ON h.chatbot_id = c.id
            LEFT JOIN {$this->wpdb->prefix}users u ON h.user_id = u.ID
            {$where_clause}
            ORDER BY h.created_at DESC
        ";
        
        if (!empty($where_values)) {
            $results = $this->wpdb->get_results(
                $this->wpdb->prepare($query, $where_values)
            );
        } else {
            $results = $this->wpdb->get_results($query);
        }
        
        return $results ?: [];
    }
    
    /**
     * Get popular questions/patterns
     */
    public function get_popular_questions($chatbot_id = null, $limit = 10, $days = 30) {
        $where = 'WHERE h.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)';
        $params = [absint($days)];
        
        if ($chatbot_id) {
            $where .= ' AND h.chatbot_id = %d';
            $params[] = absint($chatbot_id);
        }
        
        $params[] = absint($limit);
        
        $query = "
            SELECT 
                SUBSTRING(h.message, 1, 100) as message_preview,
                COUNT(*) as frequency,
                AVG(CHAR_LENGTH(h.response)) as avg_response_length
            FROM {$this->wpdb->prefix}owui_chat_history h
            {$where}
            AND CHAR_LENGTH(h.message) > 10
            GROUP BY SUBSTRING(h.message, 1, 50)
            HAVING frequency > 1
            ORDER BY frequency DESC
            LIMIT %d
        ";
        
        return $this->wpdb->get_results(
            $this->wpdb->prepare($query, $params)
        );
    }
    
    /**
     * Optimize database tables
     */
    public function optimize_tables() {
        $tables = [
            $this->wpdb->prefix . 'owui_chatbots',
            $this->wpdb->prefix . 'owui_chat_sessions',
            $this->wpdb->prefix . 'owui_chat_history',
            $this->wpdb->prefix . 'owui_contact_info'
        ];
        
        foreach ($tables as $table) {
            $this->wpdb->query("OPTIMIZE TABLE {$table}");
        }
        
        owui_log_info('Database tables optimized');
    }
    
    /**
     * Check database health
     */
    public function check_database_health() {
        $health = [
            'status' => 'healthy',
            'issues' => []
        ];
        
        // Check table existence
        $required_tables = [
            'owui_chatbots',
            'owui_chat_sessions', 
            'owui_chat_history',
            'owui_contact_info'
        ];
        
        foreach ($required_tables as $table) {
            $table_name = $this->wpdb->prefix . $table;
            $exists = $this->wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
            
            if (!$exists) {
                $health['issues'][] = "Missing table: {$table_name}";
                $health['status'] = 'error';
            }
        }
        
        // Check for orphaned records
        $orphaned_history = $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->wpdb->prefix}owui_chat_history h
             LEFT JOIN {$this->wpdb->prefix}owui_chat_sessions s ON h.session_id = s.id
             WHERE s.id IS NULL AND h.session_id IS NOT NULL"
        );
        
        if ($orphaned_history > 0) {
            $health['issues'][] = "Found {$orphaned_history} orphaned chat history records";
            $health['status'] = 'warning';
        }
        
        // Check for inactive chatbots with recent activity
        $inactive_with_activity = $this->wpdb->get_var(
            "SELECT COUNT(DISTINCT h.chatbot_id) 
             FROM {$this->wpdb->prefix}owui_chat_history h
             JOIN {$this->wpdb->prefix}owui_chatbots c ON h.chatbot_id = c.id
             WHERE c.is_active = 0 
             AND h.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        
        if ($inactive_with_activity > 0) {
            $health['issues'][] = "Found {$inactive_with_activity} inactive chatbots with recent activity";
            if ($health['status'] === 'healthy') {
                $health['status'] = 'warning';
            }
        }
        
        return $health;
    }
}
