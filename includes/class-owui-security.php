<?php
/**
 * Security utilities for OpenWebUI Chatbot
 */

if (!defined('ABSPATH')) {
    exit;
}

class OWUI_Security {
    
    /**
     * Verify nonce with proper error handling
     */
    public static function verify_nonce($nonce, $action = 'owui_nonce') {
        if (!wp_verify_nonce($nonce, $action)) {
            owui_log_error('Security', 'Nonce verification failed', [
                'action' => $action,
                'user_id' => get_current_user_id(),
                'ip' => self::get_client_ip()
            ]);
            return false;
        }
        return true;
    }
    
    /**
     * Verify AJAX nonce and send error response if invalid
     */
    public static function verify_ajax_nonce($nonce, $action = 'owui_nonce') {
        if (!self::verify_nonce($nonce, $action)) {
            wp_send_json_error([
                'message' => __('Security check failed. Please refresh the page and try again.', 'openwebui-chatbot'),
                'code' => 'nonce_verification_failed'
            ], 403);
        }
    }
    
    /**
     * Check user capabilities
     */
    public static function check_capability($capability = 'manage_options') {
        if (!current_user_can($capability)) {
            owui_log_error('Security', 'Capability check failed', [
                'capability' => $capability,
                'user_id' => get_current_user_id(),
                'ip' => self::get_client_ip()
            ]);
            return false;
        }
        return true;
    }
    
    /**
     * Check capability and send error response if insufficient
     */
    public static function check_ajax_capability($capability = 'manage_options') {
        if (!self::check_capability($capability)) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action.', 'openwebui-chatbot'),
                'code' => 'insufficient_capability'
            ], 403);
        }
    }
    
    /**
     * Sanitize chatbot data with validation
     */
    public static function sanitize_chatbot_data($data) {
        $sanitized = [];
        
        // Name validation
        if (isset($data['name'])) {
            $name = sanitize_text_field($data['name']);
            if (empty($name) || strlen($name) > 255) {
                throw new InvalidArgumentException(__('Chatbot name must be between 1 and 255 characters.', 'openwebui-chatbot'));
            }
            $sanitized['name'] = $name;
        }
        
        // Model validation
        if (isset($data['model'])) {
            $model = sanitize_text_field($data['model']);
            if (empty($model) || strlen($model) > 100) {
                throw new InvalidArgumentException(__('Model name must be between 1 and 100 characters.', 'openwebui-chatbot'));
            }
            // Validate model name format
            if (!preg_match('/^[a-zA-Z0-9\-_\.]+$/', $model)) {
                throw new InvalidArgumentException(__('Model name contains invalid characters.', 'openwebui-chatbot'));
            }
            $sanitized['model'] = $model;
        }
        
        // System prompt validation
        if (isset($data['system_prompt'])) {
            $prompt = sanitize_textarea_field($data['system_prompt']);
            if (strlen($prompt) > 5000) {
                throw new InvalidArgumentException(__('System prompt cannot exceed 5000 characters.', 'openwebui-chatbot'));
            }
            $sanitized['system_prompt'] = $prompt;
        }
        
        // Greeting message validation
        if (isset($data['greeting_message'])) {
            $greeting = sanitize_textarea_field($data['greeting_message']);
            if (strlen($greeting) > 1000) {
                throw new InvalidArgumentException(__('Greeting message cannot exceed 1000 characters.', 'openwebui-chatbot'));
            }
            $sanitized['greeting_message'] = $greeting;
        }
        
        // Avatar URL validation
        if (isset($data['avatar_url'])) {
            $avatar_url = esc_url_raw($data['avatar_url']);
            if (!empty($data['avatar_url']) && empty($avatar_url)) {
                throw new InvalidArgumentException(__('Invalid avatar URL provided.', 'openwebui-chatbot'));
            }
            if (strlen($avatar_url) > 500) {
                throw new InvalidArgumentException(__('Avatar URL cannot exceed 500 characters.', 'openwebui-chatbot'));
            }
            $sanitized['avatar_url'] = $avatar_url;
        }
        
        // Active status validation
        if (isset($data['is_active'])) {
            $sanitized['is_active'] = absint($data['is_active']) ? 1 : 0;
        }
        
        return $sanitized;
    }
    
    /**
     * Validate file upload with comprehensive checks
     */
    public static function validate_file_upload($file) {
        if (!isset($file['error']) || is_array($file['error'])) {
            throw new InvalidArgumentException(__('Invalid file upload.', 'openwebui-chatbot'));
        }
        
        // Check for upload errors
        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                throw new InvalidArgumentException(__('No file was uploaded.', 'openwebui-chatbot'));
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new InvalidArgumentException(__('File exceeds the maximum allowed size.', 'openwebui-chatbot'));
            default:
                throw new InvalidArgumentException(__('Unknown file upload error.', 'openwebui-chatbot'));
        }
        
        // Check file size
        if ($file['size'] > OWUI_MAX_FILE_SIZE) {
            throw new InvalidArgumentException(
                sprintf(
                    __('File size exceeds maximum limit of %s.', 'openwebui-chatbot'),
                    size_format(OWUI_MAX_FILE_SIZE)
                )
            );
        }
        
        // Check file type
        $file_info = pathinfo($file['name']);
        $extension = strtolower($file_info['extension'] ?? '');
        
        if (!in_array($extension, OWUI_ALLOWED_FILE_TYPES, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    __('File type not allowed. Allowed types: %s', 'openwebui-chatbot'),
                    implode(', ', OWUI_ALLOWED_FILE_TYPES)
                )
            );
        }
        
        // Additional MIME type validation
        $allowed_mimes = [
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'csv' => 'text/csv',
            'json' => 'application/json',
            'md' => 'text/markdown'
        ];
        
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (isset($allowed_mimes[$extension]) && $mime_type !== $allowed_mimes[$extension]) {
                // Allow some common variations
                $allowed_variations = [
                    'text/plain' => ['text/plain', 'application/octet-stream'],
                    'application/json' => ['application/json', 'text/plain'],
                    'text/csv' => ['text/csv', 'text/plain', 'application/csv'],
                    'text/markdown' => ['text/markdown', 'text/plain']
                ];
                
                $expected_mime = $allowed_mimes[$extension];
                if (!isset($allowed_variations[$expected_mime]) || 
                    !in_array($mime_type, $allowed_variations[$expected_mime], true)) {
                    throw new InvalidArgumentException(__('File type validation failed.', 'openwebui-chatbot'));
                }
            }
        }
        
        // Check for malicious content in text files
        if (in_array($extension, ['txt', 'csv', 'json', 'md'], true)) {
            $content = file_get_contents($file['tmp_name']);
            if (self::contains_malicious_content($content)) {
                throw new InvalidArgumentException(__('File contains potentially malicious content.', 'openwebui-chatbot'));
            }
        }
        
        return true;
    }
    
    /**
     * Check for potentially malicious content
     */
    private static function contains_malicious_content($content) {
        // Basic patterns to detect malicious content
        $malicious_patterns = [
            '/<script[\s\S]*?<\/script>/i',
            '/<iframe[\s\S]*?<\/iframe>/i',
            '/javascript:/i',
            '/vbscript:/i',
            '/onload\s*=/i',
            '/onerror\s*=/i',
            '/eval\s*\(/i',
            '/document\.cookie/i',
            '/window\.location/i'
        ];
        
        foreach ($malicious_patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Sanitize message content
     */
    public static function sanitize_message($message) {
        if (empty($message)) {
            throw new InvalidArgumentException(__('Message cannot be empty.', 'openwebui-chatbot'));
        }
        
        $sanitized = sanitize_textarea_field($message);
        
        if (strlen($sanitized) > 5000) {
            throw new InvalidArgumentException(__('Message cannot exceed 5000 characters.', 'openwebui-chatbot'));
        }
        
        if (strlen(trim($sanitized)) < 1) {
            throw new InvalidArgumentException(__('Message cannot be empty.', 'openwebui-chatbot'));
        }
        
        return $sanitized;
    }
    
    /**
     * Get client IP address safely
     */
    public static function get_client_ip() {
        $ip_headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_REAL_IP',           // Nginx
            'HTTP_X_FORWARDED_FOR',     // Load balancers
            'HTTP_X_FORWARDED',         // Proxies
            'HTTP_X_CLUSTER_CLIENT_IP', // Cluster
            'HTTP_CLIENT_IP',           // Proxy
            'REMOTE_ADDR'               // Standard
        ];
        
        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                
                // Validate IP address
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Generate secure random string
     */
    public static function generate_secure_token($length = 32) {
        if (function_exists('random_bytes')) {
            try {
                return bin2hex(random_bytes($length / 2));
            } catch (Exception $e) {
                owui_log_error('Security', 'Failed to generate secure token: ' . $e->getMessage());
            }
        }
        
        // Fallback to wp_generate_password
        return wp_generate_password($length, false);
    }
    
    /**
     * Hash sensitive data
     */
    public static function hash_data($data, $salt = '') {
        if (empty($salt)) {
            $salt = wp_salt('auth');
        }
        
        return hash_hmac('sha256', $data, $salt);
    }
    
    /**
     * Escape output for different contexts
     */
    public static function escape_for_context($data, $context = 'html') {
        switch ($context) {
            case 'html':
                return esc_html($data);
            case 'attr':
                return esc_attr($data);
            case 'url':
                return esc_url($data);
            case 'js':
                return esc_js($data);
            case 'textarea':
                return esc_textarea($data);
            case 'sql':
                global $wpdb;
                return $wpdb->esc_like($data);
            default:
                return esc_html($data);
        }
    }
    
    /**
     * Check if request is from authorized source
     */
    public static function is_authorized_request() {
        // Check if user is logged in with proper capabilities
        if (is_user_logged_in() && current_user_can('read')) {
            return true;
        }
        
        // Allow for frontend AJAX requests with proper nonce
        if (wp_doing_ajax() && isset($_POST['nonce'])) {
            return wp_verify_nonce($_POST['nonce'], 'owui_nonce');
        }
        
        return false;
    }
    
    /**
     * Rate limiting check
     */
    public static function check_rate_limit($identifier = null, $action = 'default') {
        if (!class_exists('OWUI_Rate_Limiter')) {
            return true; // Allow if rate limiter not available
        }
        
        $rate_limiter = new OWUI_Rate_Limiter();
        return $rate_limiter->check_rate_limit($identifier, $action);
    }
    
    /**
     * Log security events
     */
    public static function log_security_event($event_type, $details = []) {
        $log_data = array_merge([
            'event_type' => $event_type,
            'timestamp' => current_time('mysql', true),
            'user_id' => get_current_user_id(),
            'ip_address' => self::get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ], $details);
        
        owui_log_error('Security Event', $event_type, $log_data);
        
        // Store in database for security monitoring
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'owui_security_log',
            [
                'event_type' => $event_type,
                'event_data' => wp_json_encode($log_data),
                'created_at' => current_time('mysql', true)
            ],
            ['%s', '%s', '%s']
        );
    }
    
    /**
     * Validate API response
     */
    public static function validate_api_response($response) {
        if (!is_array($response)) {
            throw new InvalidArgumentException(__('Invalid API response format.', 'openwebui-chatbot'));
        }
        
        if (isset($response['error'])) {
            throw new Exception(__('API Error: ', 'openwebui-chatbot') . $response['error']);
        }
        
        return true;
    }
    
    /**
     * Clean up expired security logs
     */
    public static function cleanup_security_logs($days = 30) {
        global $wpdb;
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}owui_security_log WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
    }
}