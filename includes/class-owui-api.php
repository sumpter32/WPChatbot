<?php
/**
 * OpenWebUI API Class - Fixed Version
 */

if (!defined('ABSPATH')) {
    exit;
}

class OWUI_API {
    
    private $base_url;
    private $api_key;
    private $jwt_token;
    private $timeout;
    private $max_retries;
    
    public function __construct() {
        $this->base_url = rtrim(get_option('owui_base_url', ''), '/');
        $this->api_key = get_option('owui_api_key', '');
        $this->jwt_token = get_option('owui_jwt_token', '');
        $this->timeout = apply_filters('owui_api_timeout', 30);
        $this->max_retries = apply_filters('owui_api_max_retries', 2);
    }
    
    /**
     * Test API connection with comprehensive validation
     */
    public function test_connection() {
        if (empty($this->base_url)) {
            owui_log_error('API Test', 'Base URL not configured');
            return false;
        }
        
        if (empty($this->api_key) && empty($this->jwt_token)) {
            owui_log_error('API Test', 'No authentication credentials configured');
            return false;
        }
        
        try {
            $response = $this->make_request('/api/models', 'GET');
            
            if (isset($response['error'])) {
                owui_log_error('API Test', 'Connection failed: ' . $response['error']);
                return false;
            }
            
            owui_log_info('API connection test successful');
            return true;
            
        } catch (Exception $e) {
            owui_log_error('API Test', 'Exception: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get available models with caching
     */
    public function get_models($use_cache = true) {
        $cache_key = 'owui_models_cache';
        
        if ($use_cache) {
            $cached_models = get_transient($cache_key);
            if ($cached_models !== false) {
                return $cached_models;
            }
        }
        
        try {
            $response = $this->make_request('/api/models', 'GET');
            
            if (isset($response['error'])) {
                owui_log_error('API Models', 'Failed to fetch models: ' . $response['error']);
                return [];
            }
            
            $models = $this->parse_models_response($response);
            
            if ($use_cache && !empty($models)) {
                set_transient($cache_key, $models, HOUR_IN_SECONDS);
            }
            
            return $models;
            
        } catch (Exception $e) {
            owui_log_error('API Models', 'Exception: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Parse models response with error handling
     */
    private function parse_models_response($response) {
        $models = [];
        
        if (!is_array($response)) {
            return $models;
        }
        
        $model_data = $response['data'] ?? $response;
        
        if (!is_array($model_data)) {
            return $models;
        }
        
        foreach ($model_data as $model) {
            if (is_array($model)) {
                if (isset($model['id'])) {
                    $models[] = sanitize_text_field($model['id']);
                } elseif (isset($model['name'])) {
                    $models[] = sanitize_text_field($model['name']);
                }
            } elseif (is_string($model)) {
                $models[] = sanitize_text_field($model);
            }
        }
        
        return array_filter(array_unique($models));
    }
    
    /**
     * Send chat message with context and enhanced error handling
     */
    public function send_chat_message_with_context($model, $message, $system_prompt = '', $context = [], $file_id = null) {
        // Validate inputs
        if (empty($model) || empty($message)) {
            throw new InvalidArgumentException(__('Model and message are required.', 'openwebui-chatbot'));
        }
        
        $model = sanitize_text_field($model);
        $message = OWUI_Security::sanitize_message($message);
        $system_prompt = sanitize_textarea_field($system_prompt);
        
        $start_time = microtime(true);
        
        try {
            $messages = $this->build_message_array($message, $system_prompt, $context);
            
            $data = [
                'model' => $model,
                'messages' => $messages,
                'stream' => false,
                'max_tokens' => apply_filters('owui_max_tokens', 2000),
                'temperature' => apply_filters('owui_temperature', 0.7)
            ];
            
            if ($file_id) {
                $data['file_id'] = sanitize_text_field($file_id);
            }
            
            $response = $this->make_request('/api/chat/completions', 'POST', $data);
            
            if (isset($response['error'])) {
                throw new Exception($response['error']);
            }
            
            $content = $this->extract_response_content($response);
            $response_time = microtime(true) - $start_time;
            
            owui_log_info('Chat API request completed', [
                'model' => $model,
                'response_time' => $response_time,
                'message_length' => strlen($message),
                'response_length' => strlen($content),
                'context_messages' => count($context)
            ]);
            
            return [
                'content' => $content,
                'response_time' => $response_time,
                'tokens_used' => $response['usage']['total_tokens'] ?? 0
            ];
            
        } catch (Exception $e) {
            $response_time = microtime(true) - $start_time;
            
            owui_log_error('Chat API Error', $e->getMessage(), [
                'model' => $model,
                'response_time' => $response_time,
                'message_length' => strlen($message)
            ]);
            
            return [
                'error' => $this->get_user_friendly_error($e->getMessage())
            ];
        }
    }
    
    /**
     * Build message array for API request
     */
    private function build_message_array($message, $system_prompt = '', $context = []) {
        $messages = [];
        
        // Add system prompt first
        if (!empty($system_prompt)) {
            $messages[] = [
                'role' => 'system',
                'content' => $system_prompt
            ];
        }
        
        // Add conversation context
        if (!empty($context) && is_array($context)) {
            foreach ($context as $conv) {
                if (!is_object($conv) || empty($conv->message) || empty($conv->response)) {
                    continue;
                }
                
                // Add user message
                $messages[] = [
                    'role' => 'user',
                    'content' => sanitize_textarea_field($conv->message)
                ];
                
                // Add assistant response
                $messages[] = [
                    'role' => 'assistant',
                    'content' => sanitize_textarea_field($conv->response)
                ];
            }
        }
        
        // Add current user message
        $messages[] = [
            'role' => 'user',
            'content' => $message
        ];
        
        return $messages;
    }
    
    /**
     * Extract response content with validation
     */
    private function extract_response_content($response) {
        if (!is_array($response)) {
            throw new Exception(__('Invalid response format from API.', 'openwebui-chatbot'));
        }
        
        if (isset($response['choices'][0]['message']['content'])) {
            $content = $response['choices'][0]['message']['content'];
            return sanitize_textarea_field($content);
        }
        
        if (isset($response['response'])) {
            return sanitize_textarea_field($response['response']);
        }
        
        if (isset($response['content'])) {
            return sanitize_textarea_field($response['content']);
        }
        
        throw new Exception(__('No content found in API response.', 'openwebui-chatbot'));
    }
    
    /**
     * Upload file with comprehensive validation
     */
    public function upload_file($file) {
        if (empty($this->base_url)) {
            throw new Exception(__('Base URL not configured.', 'openwebui-chatbot'));
        }
        
        // Validate file
        OWUI_Security::validate_file_upload($file);
        
        $upload_url = $this->base_url . '/api/files/upload';
        
        try {
            $response = $this->upload_file_curl($upload_url, $file);
            
            if (isset($response['error'])) {
                throw new Exception($response['error']);
            }
            
            owui_log_info('File uploaded successfully', [
                'filename' => $file['name'],
                'size' => $file['size']
            ]);
            
            return $response;
            
        } catch (Exception $e) {
            owui_log_error('File Upload', $e->getMessage(), [
                'filename' => $file['name'] ?? 'unknown',
                'size' => $file['size'] ?? 0
            ]);
            
            throw new Exception(__('File upload failed: ', 'openwebui-chatbot') . $e->getMessage());
        }
    }
    
    /**
     * Upload file using cURL with proper error handling
     */
    private function upload_file_curl($url, $file) {
        if (!function_exists('curl_init')) {
            throw new Exception(__('cURL extension is required for file uploads.', 'openwebui-chatbot'));
        }
        
        $ch = curl_init();
        
        // Create CURLFile
        $curl_file = new CURLFile($file['tmp_name'], $file['type'], $file['name']);
        
        $headers = [
            'Authorization: Bearer ' . $this->get_auth_token(),
            'Accept: application/json'
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['file' => $curl_file],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => !defined('OWUI_DISABLE_SSL_VERIFY') || !OWUI_DISABLE_SSL_VERIFY,
            CURLOPT_USERAGENT => 'OpenWebUI-WordPress-Plugin/' . OWUI_VERSION
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            throw new Exception("cURL error: {$error}");
        }
        
        if ($http_code >= 400) {
            throw new Exception("HTTP error {$http_code}");
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception(__('Invalid JSON response from upload endpoint.', 'openwebui-chatbot'));
        }
        
        return $data;
    }
    
    /**
     * Get chatbot details safely
     */
    public function get_chatbot_details($chatbot_id) {
        $db = OWUI_Database::get_instance();
        
        try {
            $chatbot = $db->get_chatbot($chatbot_id);
            
            if (!$chatbot || !$chatbot->is_active) {
                return null;
            }
            
            // Sanitize output
            return (object) [
                'id' => absint($chatbot->id),
                'name' => esc_html($chatbot->name),
                'model' => esc_html($chatbot->model),
                'system_prompt' => esc_textarea($chatbot->system_prompt),
                'greeting_message' => wp_kses_post($chatbot->greeting_message),
                'avatar_url' => esc_url($chatbot->avatar_url)
            ];
            
        } catch (Exception $e) {
            owui_log_error('Get Chatbot Details', $e->getMessage(), ['chatbot_id' => $chatbot_id]);
            return null;
        }
    }
    
    /**
     * Make HTTP request with retry logic and comprehensive error handling
     */
    private function make_request($endpoint, $method = 'GET', $data = null, $retry_count = 0) {
        if (empty($this->base_url)) {
            throw new Exception(__('Base URL not configured.', 'openwebui-chatbot'));
        }
        
        $url = $this->base_url . $endpoint;
        
        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new Exception(__('Invalid API URL.', 'openwebui-chatbot'));
        }
        
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->get_auth_token(),
            'User-Agent' => 'OpenWebUI-WordPress-Plugin/' . OWUI_VERSION
        ];
        
        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => $this->timeout,
            'sslverify' => !defined('OWUI_DISABLE_SSL_VERIFY') || !OWUI_DISABLE_SSL_VERIFY,
            'blocking' => true,
            'compress' => true,
            'decompress' => true
        ];
        
        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $args['body'] = wp_json_encode($data);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            
            // Retry logic for certain errors
            if ($retry_count < $this->max_retries && $this->should_retry($error_message)) {
                owui_log_info("Retrying API request (attempt " . ($retry_count + 1) . ")", [
                    'endpoint' => $endpoint,
                    'error' => $error_message
                ]);
                
                sleep(pow(2, $retry_count)); // Exponential backoff
                return $this->make_request($endpoint, $method, $data, $retry_count + 1);
            }
            
            throw new Exception($error_message);
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Handle HTTP errors
        if ($status_code >= 400) {
            $error_data = json_decode($body, true);
            $error_message = $error_data['error'] ?? $error_data['message'] ?? "HTTP {$status_code}";
            
            // Retry for certain status codes
            if ($retry_count < $this->max_retries && in_array($status_code, [429, 500, 502, 503, 504], true)) {
                owui_log_info("Retrying API request for HTTP {$status_code} (attempt " . ($retry_count + 1) . ")");
                sleep(pow(2, $retry_count));
                return $this->make_request($endpoint, $method, $data, $retry_count + 1);
            }
            
            throw new Exception($error_message);
        }
        
        // Parse JSON response
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception(__('Invalid JSON response from API.', 'openwebui-chatbot'));
        }
        
        return $data;
    }
    
    /**
     * Check if error should trigger a retry
     */
    private function should_retry($error_message) {
        $retry_indicators = [
            'timeout',
            'connection',
            'network',
            'temporarily unavailable',
            'rate limit'
        ];
        
        $error_lower = strtolower($error_message);
        
        foreach ($retry_indicators as $indicator) {
            if (strpos($error_lower, $indicator) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get authentication token
     */
    private function get_auth_token() {
        $token = !empty($this->jwt_token) ? $this->jwt_token : $this->api_key;
        
        if (empty($token)) {
            throw new Exception(__('No authentication token configured.', 'openwebui-chatbot'));
        }
        
        return $token;
    }
    
    /**
     * Convert technical errors to user-friendly messages
     */
    private function get_user_friendly_error($error_message) {
        $error_lower = strtolower($error_message);
        
        if (strpos($error_lower, 'timeout') !== false) {
            return __('The request timed out. Please try again.', 'openwebui-chatbot');
        }
        
        if (strpos($error_lower, 'unauthorized') !== false || strpos($error_lower, '401') !== false) {
            return __('Authentication failed. Please check your API credentials.', 'openwebui-chatbot');
        }
        
        if (strpos($error_lower, 'rate limit') !== false || strpos($error_lower, '429') !== false) {
            return __('Too many requests. Please wait a moment and try again.', 'openwebui-chatbot');
        }
        
        if (strpos($error_lower, 'not found') !== false || strpos($error_lower, '404') !== false) {
            return __('The requested resource was not found.', 'openwebui-chatbot');
        }
        
        if (strpos($error_lower, 'server error') !== false || strpos($error_lower, '500') !== false) {
            return __('Server error. Please try again later.', 'openwebui-chatbot');
        }
        
        if (strpos($error_lower, 'connection') !== false) {
            return __('Connection error. Please check your internet connection.', 'openwebui-chatbot');
        }
        
        // Return generic error for unknown issues
        return __('An error occurred. Please try again.', 'openwebui-chatbot');
    }
    
    /**
     * Get API health status
     */
    public function get_health_status() {
        try {
            $start_time = microtime(true);
            $response = $this->make_request('/api/health', 'GET');
            $response_time = microtime(true) - $start_time;
            
            return [
                'status' => 'healthy',
                'response_time' => $response_time,
                'timestamp' => current_time('mysql', true)
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'timestamp' => current_time('mysql', true)
            ];
        }
    }
    
    /**
     * Clear models cache
     */
    public function clear_models_cache() {
        delete_transient('owui_models_cache');
    }
    
    /**
     * Validate API configuration
     */
    public function validate_configuration() {
        $issues = [];
        
        if (empty($this->base_url)) {
            $issues[] = __('Base URL is not configured.', 'openwebui-chatbot');
        } elseif (!filter_var($this->base_url, FILTER_VALIDATE_URL)) {
            $issues[] = __('Base URL is not a valid URL.', 'openwebui-chatbot');
        }
        
        if (empty($this->api_key) && empty($this->jwt_token)) {
            $issues[] = __('No authentication credentials configured.', 'openwebui-chatbot');
        }
        
        return $issues;
    }
}