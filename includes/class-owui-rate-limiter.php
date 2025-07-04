<?php
/**
 * Rate Limiter for OpenWebUI Chatbot - Fixed Version
 */

if (!defined('ABSPATH')) {
    exit;
}

class OWUI_Rate_Limiter {
    
    private $max_requests_per_minute;
    private $max_requests_per_hour;
    private $max_requests_per_day;
    
    public function __construct() {
        $this->max_requests_per_minute = get_option('owui_rate_limit_per_minute', 10);
        $this->max_requests_per_hour = get_option('owui_rate_limit_per_hour', 100);
        $this->max_requests_per_day = get_option('owui_rate_limit_per_day', 1000);
    }
    
    /**
     * Check rate limit for a user identifier
     */
    public function check_rate_limit($identifier = null, $action = 'chat') {
        if (!$identifier) {
            $identifier = $this->get_user_identifier();
        }
        
        try {
            // Check all time windows
            if (!$this->check_window_limit($identifier, 'minute', $this->max_requests_per_minute, $action)) {
                $this->log_rate_limit_exceeded($identifier, 'minute', $action);
                return false;
            }
            
            if (!$this->check_window_limit($identifier, 'hour', $this->max_requests_per_hour, $action)) {
                $this->log_rate_limit_exceeded($identifier, 'hour', $action);
                return false;
            }
            
            if (!$this->check_window_limit($identifier, 'day', $this->max_requests_per_day, $action)) {
                $this->log_rate_limit_exceeded($identifier, 'day', $action);
                return false;
            }
            
            // Record this request
            $this->record_request($identifier, $action);
            
            return true;
            
        } catch (Exception $e) {
            owui_log_error('Rate Limiter', $e->getMessage());
            // Allow request if rate limiter fails
            return true;
        }
    }
    
    /**
     * Check rate limit for specific window
     */
    private function check_window_limit($identifier, $window_type, $max_requests, $action) {
        global $wpdb;
        $window_start = $this->get_window_start($window_type);
        $mysql_version = $wpdb->db_version();
        if (version_compare($mysql_version, '5.7.0', '<')) {
            // Fallback: match metadata as plain text (less accurate)
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}owui_rate_limits \
                     WHERE identifier = %s \
                     AND window_type = %s \
                     AND window_start >= %s \
                     AND metadata LIKE %s",
                    $identifier,
                    $window_type,
                    $window_start,
                    '%' . $wpdb->esc_like('"action":"' . $action . '"') . '%'
                )
            );
        } else {
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}owui_rate_limits \
                     WHERE identifier = %s \
                     AND window_type = %s \
                     AND window_start >= %s \
                     AND JSON_EXTRACT(metadata, '$.action') = %s",
                    $identifier,
                    $window_type,
                    $window_start,
                    $action
                )
            );
        }
        if ($count === null) {
            // Database error, allow request
            owui_log_error('Rate Limiter', 'Database query failed for rate limit check', [
                'user_id' => get_current_user_id(),
                'url' => $_SERVER['REQUEST_URI'] ?? '',
                'method' => $_SERVER['REQUEST_METHOD'] ?? ''
            ]);
            return true;
        }
        return (int) $count < $max_requests;
    }
    
    /**
     * Record a request
     */
    private function record_request($identifier, $action) {
        global $wpdb;
        
        $current_time = current_time('mysql', true);
        $metadata = wp_json_encode([
            'action' => $action,
            'ip' => OWUI_Security::get_client_ip(),
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'timestamp' => $current_time
        ]);
        
        // Record for each window type
        $windows = ['minute', 'hour', 'day'];
        
        foreach ($windows as $window_type) {
            $window_start = $this->get_window_start($window_type);
            
            // Use INSERT ... ON DUPLICATE KEY UPDATE to handle concurrent requests
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$wpdb->prefix}owui_rate_limits 
                     (identifier, window_type, window_start, request_count, metadata, created_at) 
                     VALUES (%s, %s, %s, 1, %s, %s)
                     ON DUPLICATE KEY UPDATE 
                     request_count = request_count + 1,
                     metadata = %s",
                    $identifier,
                    $window_type,
                    $window_start,
                    $metadata,
                    $current_time,
                    $metadata
                )
            );
        }
    }
    
    /**
     * Get window start time for rate limiting
     */
    private function get_window_start($window_type) {
        $now = current_time('timestamp', true);
        
        switch ($window_type) {
            case 'minute':
                return gmdate('Y-m-d H:i:00', $now);
            case 'hour':
                return gmdate('Y-m-d H:00:00', $now);
            case 'day':
                return gmdate('Y-m-d 00:00:00', $now);
            default:
                return gmdate('Y-m-d H:i:s', $now);
        }
    }
    
    /**
     * Get user identifier for rate limiting
     */
    private function get_user_identifier() {
        $user_id = get_current_user_id();
        
        if ($user_id) {
            return 'user_' . $user_id;
        }
        
        $ip = OWUI_Security::get_client_ip();
        return 'ip_' . hash('sha256', $ip . wp_salt('auth'));
    }
    
    /**
     * Get remaining requests for a user
     */
    public function get_remaining_requests($identifier = null, $window_type = 'minute') {
        if (!$identifier) {
            $identifier = $this->get_user_identifier();
        }
        
        global $wpdb;
        
        $window_start = $this->get_window_start($window_type);
        $max_requests = $this->get_max_requests($window_type);
        
        $used_requests = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(request_count), 0) FROM {$wpdb->prefix}owui_rate_limits 
                 WHERE identifier = %s 
                 AND window_type = %s 
                 AND window_start >= %s",
                $identifier,
                $window_type,
                $window_start
            )
        );
        
        return max(0, $max_requests - (int) $used_requests);
    }
    
    /**
     * Get max requests for window type
     */
    private function get_max_requests($window_type) {
        switch ($window_type) {
            case 'minute':
                return $this->max_requests_per_minute;
            case 'hour':
                return $this->max_requests_per_hour;
            case 'day':
                return $this->max_requests_per_day;
            default:
                return 0;
        }
    }
    
    /**
     * Get time until rate limit resets
     */
    public function get_reset_time($window_type = 'minute') {
        $now = current_time('timestamp', true);
        
        switch ($window_type) {
            case 'minute':
                return 60 - ($now % 60);
            case 'hour':
                return 3600 - ($now % 3600);
            case 'day':
                $next_day = strtotime('tomorrow 00:00:00', $now);
                return $next_day - $now;
            default:
                return 60;
        }
    }
    
    /**
     * Check if user is currently rate limited
     */
    public function is_rate_limited($identifier = null) {
        if (!$identifier) {
            $identifier = $this->get_user_identifier();
        }
        
        // Check if blocked by any window
        $windows = [
            'minute' => $this->max_requests_per_minute,
            'hour' => $this->max_requests_per_hour,
            'day' => $this->max_requests_per_day
        ];
        
        foreach ($windows as $window_type => $max_requests) {
            if (!$this->check_window_limit($identifier, $window_type, $max_requests, 'chat')) {
                return [
                    'limited' => true,
                    'window' => $window_type,
                    'reset_in' => $this->get_reset_time($window_type),
                    'remaining' => 0
                ];
            }
        }
        
        return [
            'limited' => false,
            'remaining' => $this->get_remaining_requests($identifier),
            'reset_in' => $this->get_reset_time()
        ];
    }
    
    /**
     * Whitelist an IP address or user
     */
    public function add_to_whitelist($identifier, $reason = '') {
        global $wpdb;
        
        $wpdb->insert(
            $wpdb->prefix . 'owui_rate_limit_whitelist',
            [
                'identifier' => sanitize_text_field($identifier),
                'reason' => sanitize_text_field($reason),
                'created_at' => current_time('mysql', true)
            ],
            ['%s', '%s', '%s']
        );
    }
    
    /**
     * Check if identifier is whitelisted
     */
    public function is_whitelisted($identifier = null) {
        if (!$identifier) {
            $identifier = $this->get_user_identifier();
        }
        
        global $wpdb;
        
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}owui_rate_limit_whitelist WHERE identifier = %s",
                $identifier
            )
        );
        
        return (int) $count > 0;
    }
    
    /**
     * Temporarily ban an identifier
     */
    public function ban_identifier($identifier, $duration_minutes = 60, $reason = '') {
        global $wpdb;
        
        $expires_at = gmdate('Y-m-d H:i:s', time() + ($duration_minutes * 60));
        
        $wpdb->insert(
            $wpdb->prefix . 'owui_rate_limit_bans',
            [
                'identifier' => sanitize_text_field($identifier),
                'expires_at' => $expires_at,
                'reason' => sanitize_text_field($reason),
                'created_at' => current_time('mysql', true)
            ],
            ['%s', '%s', '%s', '%s']
        );
        
        owui_log_error('Rate Limiter', "Banned identifier: {$identifier} for {$duration_minutes} minutes. Reason: {$reason}");
    }
    
    /**
     * Check if identifier is banned
     */
    public function is_banned($identifier = null) {
        if (!$identifier) {
            $identifier = $this->get_user_identifier();
        }
        
        global $wpdb;
        
        $ban = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}owui_rate_limit_bans 
                 WHERE identifier = %s AND expires_at > NOW() 
                 ORDER BY expires_at DESC LIMIT 1",
                $identifier
            )
        );
        
        if ($ban) {
            return [
                'banned' => true,
                'expires_at' => $ban->expires_at,
                'reason' => $ban->reason
            ];
        }
        
        return ['banned' => false];
    }
    
    /**
     * Log rate limit exceeded events
     */
    private function log_rate_limit_exceeded($identifier, $window_type, $action) {
        owui_log_error('Rate Limit Exceeded', "Identifier: {$identifier}, Window: {$window_type}, Action: {$action}");
        
        // Auto-ban after too many violations
        $violations = $this->get_violation_count($identifier, 'hour');
        
        if ($violations >= 10) { // 10 violations in an hour = 1 hour ban
            $this->ban_identifier($identifier, 60, 'Excessive rate limit violations');
        } elseif ($violations >= 5) { // 5 violations = 15 minute ban
            $this->ban_identifier($identifier, 15, 'Multiple rate limit violations');
        }
    }
    
    /**
     * Get violation count for identifier
     */
    private function get_violation_count($identifier, $window_type) {
        global $wpdb;
        
        $window_start = $this->get_window_start($window_type);
        
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}owui_rate_limits 
                 WHERE identifier = %s 
                 AND window_type = %s 
                 AND window_start >= %s 
                 AND request_count >= %d",
                $identifier,
                $window_type,
                $window_start,
                $this->get_max_requests($window_type)
            )
        );
    }
    
    /**
     * Clean up old rate limit records
     */
    public static function cleanup_old_records() {
        global $wpdb;
        
        // Clean up rate limit records older than 7 days
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}owui_rate_limits 
             WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        
        // Clean up expired bans
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}owui_rate_limit_bans 
             WHERE expires_at < NOW()"
        );
        
        owui_log_info('Rate limiter cleanup completed');
    }
    
    /**
     * Get rate limit statistics
     */
    public function get_stats($days = 7) {
        global $wpdb;
        
        $stats = [];
        
        // Total requests by day
        $stats['daily_requests'] = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(created_at) as date, SUM(request_count) as total_requests
                 FROM {$wpdb->prefix}owui_rate_limits 
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                 GROUP BY DATE(created_at)
                 ORDER BY date DESC",
                $days
            )
        );
        
        // Top rate limited identifiers
        $stats['top_limited'] = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT identifier, SUM(request_count) as total_requests
                 FROM {$wpdb->prefix}owui_rate_limits 
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                 GROUP BY identifier
                 ORDER BY total_requests DESC
                 LIMIT 10",
                $days
            )
        );
        
        // Current bans
        $stats['active_bans'] = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}owui_rate_limit_bans 
             WHERE expires_at > NOW()
             ORDER BY expires_at DESC"
        );
        
        return $stats;
    }
    
    /**
     * Reset rate limits for an identifier
     */
    public function reset_rate_limits($identifier) {
        global $wpdb;
        
        $wpdb->delete(
            $wpdb->prefix . 'owui_rate_limits',
            ['identifier' => $identifier],
            ['%s']
        );
        
        owui_log_info("Rate limits reset for identifier: {$identifier}");
    }
    
    /**
     * Get current rate limit configuration
     */
    public function get_configuration() {
        return [
            'per_minute' => $this->max_requests_per_minute,
            'per_hour' => $this->max_requests_per_hour,
            'per_day' => $this->max_requests_per_day,
            'windows' => ['minute', 'hour', 'day']
        ];
    }
}
