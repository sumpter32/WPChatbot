<?php
// =============================================================================
// FILE: includes/class-owui-api.php
// =============================================================================
/**
 * OpenWebUI API Class
 */

if (!defined('ABSPATH')) exit;

class OWUI_API {
    private $base_url;
    private $api_key;
    private $jwt_token;
    
    public function __construct() {
        $this->base_url = rtrim(get_option('owui_base_url', ''), '/');
        $this->api_key = get_option('owui_api_key', '');
        $this->jwt_token = get_option('owui_jwt_token', '');
    }
    
    public function test_connection() {
        if (empty($this->base_url)) {
            return false;
        }
        
        $response = $this->make_request('/api/models', 'GET');
        return !isset($response['error']);
    }
    
    public function get_models() {
        $response = $this->make_request('/api/models', 'GET');
        
        if (isset($response['error'])) {
            return array();
        }
        
        $models = array();
        if (isset($response['data']) && is_array($response['data'])) {
            foreach ($response['data'] as $model) {
                if (isset($model['id'])) {
                    $models[] = $model['id'];
                } elseif (isset($model['name'])) {
                    $models[] = $model['name'];
                } elseif (is_string($model)) {
                    $models[] = $model;
                }
            }
        }
        
        return $models;
    }
    
    public function send_chat_message($model, $message, $system_prompt = '', $file_id = null) {
        $messages = array();
        
        if (!empty($system_prompt)) {
            $messages[] = array(
                'role' => 'system',
                'content' => $system_prompt
            );
        }
        
        $messages[] = array(
            'role' => 'user',
            'content' => $message
        );
        
        $data = array(
            'model' => $model,
            'messages' => $messages,
            'stream' => false
        );
        
        if ($file_id) {
            $data['file_id'] = $file_id;
        }
        
        $response = $this->make_request('/api/chat/completions', 'POST', $data);
        
        if (isset($response['error'])) {
            return $response;
        }
        
        if (isset($response['choices'][0]['message']['content'])) {
            return array('content' => $response['choices'][0]['message']['content']);
        }
        
        return array('error' => 'Invalid response format');
    }
    
    public function send_chat_message_with_context($model, $message, $system_prompt = '', $context = array(), $file_id = null) {
        $messages = array();
        
        // Add system prompt first
        if (!empty($system_prompt)) {
            $messages[] = array(
                'role' => 'system',
                'content' => $system_prompt
            );
        }
        
        // Add conversation context (previous messages)
        if (!empty($context)) {
            foreach ($context as $conv) {
                // Add user message
                $messages[] = array(
                    'role' => 'user',
                    'content' => $conv->message
                );
                // Add assistant response
                $messages[] = array(
                    'role' => 'assistant',
                    'content' => $conv->response
                );
            }
        }
        
        // Add current user message
        $messages[] = array(
            'role' => 'user',
            'content' => $message
        );
        
        $data = array(
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'max_tokens' => 2000,  // Limit response length
            'temperature' => 0.7   // Control randomness
        );
        
        if ($file_id) {
            $data['file_id'] = $file_id;
        }
        
        // Log the conversation for debugging (remove in production)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('OpenWebUI Chat Context: ' . json_encode(array(
                'model' => $model,
                'context_count' => count($context),
                'total_messages' => count($messages)
            )));
        }
        
        $response = $this->make_request('/api/chat/completions', 'POST', $data);
        
        if (isset($response['error'])) {
            return $response;
        }
        
        if (isset($response['choices'][0]['message']['content'])) {
            return array('content' => $response['choices'][0]['message']['content']);
        }
        
        return array('error' => 'Invalid response format');
    }
    
    public function upload_file($file) {
        if (empty($this->base_url)) {
            return array('error' => 'Base URL not configured');
        }
        
        $upload_url = $this->base_url . '/api/files/upload';
        
        $headers = array(
            'Authorization' => 'Bearer ' . $this->get_auth_token()
        );
        
        $body = array(
            'file' => new CURLFile($file['tmp_name'], $file['type'], $file['name'])
        );
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $upload_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->format_headers($headers));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        if (defined('OWUI_DISABLE_SSL_VERIFY') && OWUI_DISABLE_SSL_VERIFY) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return array('error' => 'Upload failed: ' . $error);
        }
        
        if ($http_code !== 200) {
            return array('error' => 'Upload failed with status: ' . $http_code);
        }
        
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array('error' => 'Invalid response format');
        }
        
        return $data;
    }
    
    public function get_chatbot_details($chatbot_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}owui_chatbots WHERE id = %d AND is_active = 1",
            $chatbot_id
        ));
    }
    
    private function make_request($endpoint, $method = 'GET', $data = null) {
        if (empty($this->base_url)) {
            return array('error' => 'Base URL not configured');
        }
        
        $url = $this->base_url . $endpoint;
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->get_auth_token()
        );
        
        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30,
            'sslverify' => !defined('OWUI_DISABLE_SSL_VERIFY') || !OWUI_DISABLE_SSL_VERIFY
        );
        
        if ($data && in_array($method, array('POST', 'PUT', 'PATCH'))) {
            $args['body'] = json_encode($data);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            return array('error' => $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code >= 400) {
            return array('error' => 'API request failed with status: ' . $status_code);
        }
        
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array('error' => 'Invalid JSON response');
        }
        
        return $data;
    }
    
    private function get_auth_token() {
        return !empty($this->jwt_token) ? $this->jwt_token : $this->api_key;
    }
    
    private function format_headers($headers) {
        $formatted = array();
        foreach ($headers as $key => $value) {
            $formatted[] = $key . ': ' . $value;
        }
        return $formatted;
    }
}