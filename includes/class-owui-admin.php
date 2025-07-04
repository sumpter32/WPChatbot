<?php
/**
 * Admin functionality for OpenWebUI Chatbot - Fixed Version
 */

if (!defined('ABSPATH')) {
    exit;
}

class OWUI_Admin {
    
    private $api;
    private $db;
    
    public function init() {
        $this->api = new OWUI_API();
        $this->db = OWUI_Database::get_instance();
        
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        
        // Secure admin post handlers
        add_action('admin_post_owui_create_chatbot', [$this, 'handle_create_chatbot']);
        add_action('admin_post_owui_update_chatbot', [$this, 'handle_update_chatbot']);
        add_action('admin_post_owui_delete_chatbot', [$this, 'handle_delete_chatbot']);
        
        // Secure AJAX handlers
        add_action('wp_ajax_owui_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_owui_get_models', [$this, 'ajax_get_models']);
        add_action('wp_ajax_owui_export_csv', [$this, 'ajax_export_csv']);
        add_action('wp_ajax_owui_export_contacts', [$this, 'ajax_export_contacts']);
        add_action('wp_ajax_owui_clear_history', [$this, 'ajax_clear_history']);
        add_action('wp_ajax_owui_get_stats', [$this, 'ajax_get_stats']);
        add_action('wp_ajax_owui_export_conversation', [$this, 'ajax_export_conversation']);
        add_action('wp_ajax_owui_delete_conversation', [$this, 'ajax_delete_conversation']);
        add_action('wp_ajax_owui_test_email', [$this, 'ajax_test_email']);
        add_action('wp_ajax_owui_end_session', [$this, 'ajax_end_session']);
        
        // Allow non-privileged users for session ending
        add_action('wp_ajax_nopriv_owui_end_session', [$this, 'ajax_end_session']);
    }
    
    /**
     * Add admin menu with proper capability checks
     */
    public function add_admin_menu() {
        add_menu_page(
            __('OpenWebUI Chatbot', 'openwebui-chatbot'),
            __('OpenWebUI', 'openwebui-chatbot'),
            'manage_options',
            'owui-dashboard',
            [$this, 'render_dashboard'],
            'dashicons-format-chat',
            30
        );

        add_submenu_page(
            'owui-dashboard',
            __('Chatbots', 'openwebui-chatbot'),
            __('Chatbots', 'openwebui-chatbot'),
            'manage_options',
            'owui-chatbots',
            [$this, 'render_chatbots']
        );

        add_submenu_page(
            'owui-dashboard',
            __('Chat History', 'openwebui-chatbot'),
            __('Chat History', 'openwebui-chatbot'),
            'manage_options',
            'owui-history',
            [$this, 'render_history']
        );

        add_submenu_page(
            'owui-dashboard',
            __('Contact Information', 'openwebui-chatbot'),
            __('Contacts', 'openwebui-chatbot'),
            'manage_options',
            'owui-contacts',
            [$this, 'render_contacts']
        );

        add_submenu_page(
            'owui-dashboard',
            __('Settings', 'openwebui-chatbot'),
            __('Settings', 'openwebui-chatbot'),
            'manage_options',
            'owui-settings',
            [$this, 'render_settings']
        );
    }
    
    /**
     * Enqueue admin assets with proper versioning
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'owui-') === false) {
            return;
        }
        
        wp_enqueue_style(
            'owui-admin',
            OWUI_PLUGIN_URL . 'assets/css/admin.css',
            [],
            OWUI_VERSION
        );
        
        wp_enqueue_script(
            'owui-admin',
            OWUI_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            OWUI_VERSION,
            true
        );
        
        wp_localize_script('owui-admin', 'owui_admin_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('owui_admin_nonce'),
            'strings' => [
                'confirm_delete' => __('Are you sure you want to delete this item? This action cannot be undone.', 'openwebui-chatbot'),
                'confirm_clear_history' => __('Are you sure you want to clear all chat history? This action cannot be undone.', 'openwebui-chatbot'),
                'loading' => __('Loading...', 'openwebui-chatbot'),
                'error' => __('An error occurred. Please try again.', 'openwebui-chatbot'),
                'success' => __('Operation completed successfully.', 'openwebui-chatbot')
            ]
        ]);
    }
    
    /**
     * AJAX: Test API connection
     */
    public function ajax_test_connection() {
        OWUI_Security::verify_ajax_nonce($_POST['nonce'], 'owui_admin_nonce');
        OWUI_Security::check_ajax_capability('manage_options');
        
        try {
            $success = $this->api->test_connection();
            
            if ($success) {
                wp_send_json_success(__('Connection successful!', 'openwebui-chatbot'));
            } else {
                wp_send_json_error(__('Connection failed. Please check your settings.', 'openwebui-chatbot'));
            }
            
        } catch (Exception $e) {
            owui_log_error('Test Connection', $e->getMessage());
            wp_send_json_error(__('Connection test failed: ', 'openwebui-chatbot') . $e->getMessage());
        }
    }
    
    /**
     * AJAX: Get available models
     */
    public function ajax_get_models() {
        OWUI_Security::verify_ajax_nonce($_POST['nonce'], 'owui_admin_nonce');
        OWUI_Security::check_ajax_capability('manage_options');
        
        try {
            $models = $this->api->get_models(false); // Force fresh fetch
            
            if (empty($models)) {
                wp_send_json_error(__('No models found or connection failed.', 'openwebui-chatbot'));
            } else {
                wp_send_json_success($models);
            }
            
        } catch (Exception $e) {
            owui_log_error('Get Models', $e->getMessage());
            wp_send_json_error(__('Failed to fetch models: ', 'openwebui-chatbot') . $e->getMessage());
        }
    }
    
    /**
     * AJAX: Export chat history
     */
    public function ajax_export_csv() {
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'owui_admin_nonce')) {
            wp_die(__('Security check failed.', 'openwebui-chatbot'), 403);
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized access.', 'openwebui-chatbot'), 403);
        }
        
        try {
            $conversations = $this->db->export_chat_history();
            
            $filename = 'chat-history-' . gmdate('Y-m-d-H-i-s') . '.csv';
            
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, must-revalidate');
            header('Expires: 0');
            
            $output = fopen('php://output', 'w');
            
            // Add BOM for Excel UTF-8 compatibility
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Headers
            fputcsv($output, [
                __('Date', 'openwebui-chatbot'),
                __('Time', 'openwebui-chatbot'),
                __('Chatbot', 'openwebui-chatbot'),
                __('User', 'openwebui-chatbot'),
                __('Message', 'openwebui-chatbot'),
                __('Response', 'openwebui-chatbot'),
                __('Session ID', 'openwebui-chatbot')
            ]);
            
            // Data
            foreach ($conversations as $conv) {
                fputcsv($output, [
                    gmdate('Y-m-d', strtotime($conv->created_at)),
                    gmdate('H:i:s', strtotime($conv->created_at)),
                    $conv->chatbot_name ?: __('Unknown', 'openwebui-chatbot'),
                    $conv->user_name ?: __('Guest', 'openwebui-chatbot'),
                    $conv->message,
                    $conv->response,
                    $conv->session_id
                ]);
            }
            
            fclose($output);
            exit;
            
        } catch (Exception $e) {
            owui_log_error('Export CSV', $e->getMessage());
            wp_die(__('Export failed: ', 'openwebui-chatbot') . $e->getMessage());
        }
    }
    
    /**
     * AJAX: Export contacts
     */
    public function ajax_export_contacts() {
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'owui_admin_nonce')) {
            wp_die(__('Security check failed.', 'openwebui-chatbot'), 403);
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized access.', 'openwebui-chatbot'), 403);
        }
        
        if (!class_exists('OWUI_Contact_Extractor')) {
            wp_die(__('Contact extractor not available.', 'openwebui-chatbot'), 500);
        }
        
        try {
            $extractor = new OWUI_Contact_Extractor();
            $contacts = $extractor->get_all_contacts(10000); // Large limit for export
            
            $filename = 'contacts-' . gmdate('Y-m-d-H-i-s') . '.csv';
            
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, must-revalidate');
            header('Expires: 0');
            
            $output = fopen('php://output', 'w');
            
            // Add BOM for Excel UTF-8 compatibility
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Headers
            fputcsv($output, [
                __('Date', 'openwebui-chatbot'),
                __('Chatbot', 'openwebui-chatbot'),
                __('Contact Type', 'openwebui-chatbot'),
                __('Contact Value', 'openwebui-chatbot'),
                __('Session ID', 'openwebui-chatbot')
            ]);
            
            // Data
            foreach ($contacts as $contact) {
                fputcsv($output, [
                    $contact->extracted_at,
                    $contact->chatbot_name ?: __('Unknown', 'openwebui-chatbot'),
                    ucfirst($contact->contact_type),
                    $contact->contact_value,
                    $contact->session_id
                ]);
            }
            
            fclose($output);
            exit;
            
        } catch (Exception $e) {
            owui_log_error('Export Contacts', $e->getMessage());
            wp_die(__('Export failed: ', 'openwebui-chatbot') . $e->getMessage());
        }
    }
    public function ajax_clear_history() {
        OWUI_Security::verify_ajax_nonce($_POST['nonce'], 'owui_admin_nonce');
        OWUI_Security::check_ajax_capability('manage_options');
        
        try {
            global $wpdb;
            
            // Use transaction for safety
            $wpdb->query('START TRANSACTION');
            
            // Clear contact info first (foreign key constraint)
            $wpdb->query("DELETE FROM {$wpdb->prefix}owui_contact_info");
            
            // Clear chat history
            $wpdb->query("DELETE FROM {$wpdb->prefix}owui_chat_history");
            
            // End sessions
            $wpdb->query("UPDATE {$wpdb->prefix}owui_chat_sessions SET ended_at = NOW()");
            
            $wpdb->query('COMMIT');
            
            owui_log_info('Chat history cleared by admin', ['user_id' => get_current_user_id()]);
            
            wp_send_json_success(__('History cleared successfully', 'openwebui-chatbot'));
            
        } catch (Exception $e) {
            global $wpdb;
            $wpdb->query('ROLLBACK');
            
            owui_log_error('Clear History', $e->getMessage());
            wp_send_json_error(__('Failed to clear history: ', 'openwebui-chatbot') . $e->getMessage());
        }
    }
    
    /**
     * AJAX: Get dashboard statistics
     */
    public function ajax_get_stats() {
        OWUI_Security::verify_ajax_nonce($_POST['nonce'], 'owui_admin_nonce');
        OWUI_Security::check_ajax_capability('manage_options');
        
        try {
            $stats = $this->db->get_dashboard_stats();
            wp_send_json_success($stats);
            
        } catch (Exception $e) {
            owui_log_error('Get Stats', $e->getMessage());
            wp_send_json_error(__('Failed to fetch statistics.', 'openwebui-chatbot'));
        }
    }
    
    /**
     * AJAX: Export single conversation
     */
    public function ajax_export_conversation() {
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'owui_admin_nonce')) {
            wp_die(__('Security check failed.', 'openwebui-chatbot'), 403);
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized access.', 'openwebui-chatbot'), 403);
        }
        
        $session_id = absint($_GET['session_id'] ?? 0);
        if (!$session_id) {
            wp_die(__('Invalid session ID.', 'openwebui-chatbot'), 400);
        }
        
        try {
            $conversations = $this->db->export_chat_history(['session_id' => $session_id]);
            
            if (empty($conversations)) {
                wp_die(__('Conversation not found.', 'openwebui-chatbot'), 404);
            }
            
            $filename = 'conversation-' . $session_id . '-' . gmdate('Y-m-d-H-i-s') . '.csv';
            
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            
            $output = fopen('php://output', 'w');
            
            // Add BOM for Excel UTF-8 compatibility
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Conversation header
            fputcsv($output, [__('Conversation Export', 'openwebui-chatbot')]);
            fputcsv($output, [__('Session ID', 'openwebui-chatbot'), $session_id]);
            fputcsv($output, [__('Exported', 'openwebui-chatbot'), current_time('mysql')]);
            fputcsv($output, []); // Empty row
            
            // Headers
            fputcsv($output, [
                __('Time', 'openwebui-chatbot'),
                __('Type', 'openwebui-chatbot'),
                __('Message', 'openwebui-chatbot')
            ]);
            
            // Messages
            foreach ($conversations as $conv) {
                // User message
                fputcsv($output, [
                    $conv->created_at,
                    __('User', 'openwebui-chatbot'),
                    $conv->message
                ]);
                
                // Bot response
                fputcsv($output, [
                    $conv->created_at,
                    __('Bot', 'openwebui-chatbot'),
                    $conv->response
                ]);
            }
            
            fclose($output);
            exit;
            
        } catch (Exception $e) {
            owui_log_error('Export Conversation', $e->getMessage());
            wp_die(__('Export failed: ', 'openwebui-chatbot') . $e->getMessage());
        }
    }
    
    /**
     * AJAX: Delete conversation
     */
    public function ajax_delete_conversation() {
        OWUI_Security::verify_ajax_nonce($_POST['nonce'], 'owui_admin_nonce');
        OWUI_Security::check_ajax_capability('manage_options');
        
        $session_id = absint($_POST['session_id'] ?? 0);
        if (!$session_id) {
            wp_send_json_error(__('Invalid session ID.', 'openwebui-chatbot'));
        }
        
        try {
            global $wpdb;
            
            $wpdb->query('START TRANSACTION');
            
            // Delete contact info (foreign key cascade should handle this)
            $wpdb->delete(
                $wpdb->prefix . 'owui_contact_info',
                ['session_id' => $session_id],
                ['%d']
            );
            
            // Delete chat history (foreign key cascade should handle this)
            $wpdb->delete(
                $wpdb->prefix . 'owui_chat_history',
                ['session_id' => $session_id],
                ['%d']
            );
            
            // Delete session
            $result = $wpdb->delete(
                $wpdb->prefix . 'owui_chat_sessions',
                ['id' => $session_id],
                ['%d']
            );
            
            if ($result === false) {
                throw new Exception($wpdb->last_error ?: __('Failed to delete conversation.', 'openwebui-chatbot'));
            }
            
            $wpdb->query('COMMIT');
            
            owui_log_info('Conversation deleted by admin', [
                'session_id' => $session_id,
                'user_id' => get_current_user_id()
            ]);
            
            wp_send_json_success(__('Conversation deleted successfully', 'openwebui-chatbot'));
            
        } catch (Exception $e) {
            global $wpdb;
            $wpdb->query('ROLLBACK');
            
            owui_log_error('Delete Conversation', $e->getMessage());
            wp_send_json_error(__('Failed to delete conversation: ', 'openwebui-chatbot') . $e->getMessage());
        }
    }
    
    /**
     * AJAX: Test email notifications
     */
    public function ajax_test_email() {
        OWUI_Security::verify_ajax_nonce($_POST['nonce'], 'owui_admin_nonce');
        OWUI_Security::check_ajax_capability('manage_options');
        
        if (!class_exists('OWUI_Email_Notifications')) {
            wp_send_json_error(__('Email notification system not available', 'openwebui-chatbot'));
        }
        
        try {
            $email_system = new OWUI_Email_Notifications();
            $email_system->ajax_test_email();
            
        } catch (Exception $e) {
            owui_log_error('Test Email', $e->getMessage());
            wp_send_json_error(__('Failed to send test email: ', 'openwebui-chatbot') . $e->getMessage());
        }
    }
    
    /**
     * AJAX: End session (can be called by non-privileged users)
     */
    public function ajax_end_session() {
        // Verify nonce but allow non-privileged users
        if (isset($_POST['nonce'])) {
            OWUI_Security::verify_ajax_nonce($_POST['nonce'], 'owui_nonce');
        }
        
        $chatbot_id = absint($_POST['chatbot_id'] ?? 0);
        $reason = sanitize_text_field($_POST['reason'] ?? 'manual');
        
        if (!$chatbot_id) {
            wp_send_json_error(__('Invalid chatbot ID', 'openwebui-chatbot'));
        }
        
        try {
            $user_id = get_current_user_id() ?: null;
            $session_manager = new OWUI_Session_Manager();
            $session_id = $session_manager->get_or_create_session($chatbot_id, $user_id);
            
            if ($session_id) {
                // Trigger the session ended action for email notifications
                do_action('owui_session_ended', $session_id, $reason);
                wp_send_json_success(__('Session ended', 'openwebui-chatbot'));
            } else {
                wp_send_json_error(__('Session not found', 'openwebui-chatbot'));
            }
            
        } catch (Exception $e) {
            owui_log_error('End Session', $e->getMessage());
            wp_send_json_error(__('Failed to end session', 'openwebui-chatbot'));
        }
    }
    
    /**
     * Handle chatbot creation with proper validation
     */
    public function handle_create_chatbot() {
        // Security checks
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized access.', 'openwebui-chatbot'), 403);
        }
        
        if (!wp_verify_nonce($_POST['_wpnonce'], 'owui_create_chatbot')) {
            wp_die(__('Security check failed.', 'openwebui-chatbot'), 403);
        }
        
        try {
            // Validate required fields
            $required_fields = ['chatbot_name', 'chatbot_model'];
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    throw new InvalidArgumentException(__('Please fill in all required fields.', 'openwebui-chatbot'));
                }
            }
            
            $data = [
                'name' => $_POST['chatbot_name'],
                'model' => $_POST['chatbot_model'],
                'system_prompt' => $_POST['system_prompt'] ?? '',
                'greeting_message' => $_POST['greeting_message'] ?? '',
                'is_active' => 1
            ];
            
            // Handle avatar upload
            if (!empty($_FILES['chatbot_avatar']['name'])) {
                $avatar_url = $this->handle_avatar_upload($_FILES['chatbot_avatar']);
                if ($avatar_url) {
                    $data['avatar_url'] = $avatar_url;
                }
            }
            
            $chatbot_id = $this->db->create_chatbot($data);
            
            owui_log_info('Chatbot created', [
                'chatbot_id' => $chatbot_id,
                'user_id' => get_current_user_id()
            ]);
            
            wp_redirect(add_query_arg(['created' => '1'], admin_url('admin.php?page=owui-chatbots')));
            exit;
            
        } catch (Exception $e) {
            owui_log_error('Create Chatbot', $e->getMessage());
            
            $error_code = $e instanceof InvalidArgumentException ? '2' : '3';
            wp_redirect(add_query_arg(['error' => $error_code], admin_url('admin.php?page=owui-chatbots')));
            exit;
        }
    }
    
    /**
     * Handle avatar upload with security validation
     */
    private function handle_avatar_upload($file) {
        try {
            // Validate file
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $file_type = wp_check_filetype($file['name']);
            
            if (!in_array($file_type['type'], $allowed_types, true)) {
                throw new InvalidArgumentException(__('Invalid file type. Only JPEG, PNG, GIF, and WebP images are allowed.', 'openwebui-chatbot'));
            }
            
            if ($file['size'] > 2 * 1024 * 1024) { // 2MB limit
                throw new InvalidArgumentException(__('File size too large. Maximum size is 2MB.', 'openwebui-chatbot'));
            }
            
            // Use WordPress upload handling
            if (!function_exists('wp_handle_upload')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            
            $upload = wp_handle_upload($file, ['test_form' => false]);
            
            if (isset($upload['error'])) {
                throw new Exception($upload['error']);
            }
            
            return $upload['url'];
            
        } catch (Exception $e) {
            owui_log_error('Avatar Upload', $e->getMessage());
            return null;
        }
    }
    
    /**
     * Handle chatbot update with proper validation
     */
    public function handle_update_chatbot() {
        // Security checks
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized access.', 'openwebui-chatbot'), 403);
        }
        
        if (!wp_verify_nonce($_POST['_wpnonce'], 'owui_update_chatbot')) {
            wp_die(__('Security check failed.', 'openwebui-chatbot'), 403);
        }
        
        $chatbot_id = absint($_POST['chatbot_id'] ?? 0);
        if (!$chatbot_id) {
            wp_die(__('Invalid chatbot ID.', 'openwebui-chatbot'), 400);
        }
        
        try {
            $data = [
                'name' => $_POST['chatbot_name'],
                'model' => $_POST['chatbot_model'],
                'system_prompt' => $_POST['system_prompt'] ?? '',
                'greeting_message' => $_POST['greeting_message'] ?? '',
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            ];
            
            // Handle avatar upload
            if (!empty($_FILES['chatbot_avatar']['name'])) {
                $avatar_url = $this->handle_avatar_upload($_FILES['chatbot_avatar']);
                if ($avatar_url) {
                    $data['avatar_url'] = $avatar_url;
                }
            }
            
            $this->db->update_chatbot($chatbot_id, $data);
            
            owui_log_info('Chatbot updated', [
                'chatbot_id' => $chatbot_id,
                'user_id' => get_current_user_id()
            ]);
            
            wp_redirect(add_query_arg(['updated' => '1'], admin_url('admin.php?page=owui-chatbots')));
            exit;
            
        } catch (Exception $e) {
            owui_log_error('Update Chatbot', $e->getMessage());
            
            wp_redirect(add_query_arg([
                'action' => 'edit',
                'id' => $chatbot_id,
                'error' => '1'
            ], admin_url('admin.php?page=owui-chatbots')));
            exit;
        }
    }
    
    /**
     * Handle chatbot deletion with proper validation
     */
    public function handle_delete_chatbot() {
        // Security checks
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized access.', 'openwebui-chatbot'), 403);
        }
        
        $chatbot_id = absint($_GET['id'] ?? 0);
        if (!$chatbot_id) {
            wp_die(__('Invalid chatbot ID.', 'openwebui-chatbot'), 400);
        }
        
        if (!wp_verify_nonce($_GET['_wpnonce'], 'delete_chatbot_' . $chatbot_id)) {
            wp_die(__('Security check failed.', 'openwebui-chatbot'), 403);
        }
        
        try {
            $this->db->delete_chatbot($chatbot_id);
            
            owui_log_info('Chatbot deleted', [
                'chatbot_id' => $chatbot_id,
                'user_id' => get_current_user_id()
            ]);
            
            wp_redirect(add_query_arg(['deleted' => '1'], admin_url('admin.php?page=owui-chatbots')));
            exit;
            
        } catch (Exception $e) {
            owui_log_error('Delete Chatbot', $e->getMessage());
            
            wp_redirect(add_query_arg(['error' => '1'], admin_url('admin.php?page=owui-chatbots')));
            exit;
        }
    }
    
    // Render methods with proper capability checks
    
    public function render_dashboard() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized access.', 'openwebui-chatbot'), 403);
        }
        
        try {
            $stats = $this->db->get_dashboard_stats();
            $recent_conversations = $this->db->get_conversations(['limit' => 10]);
            
            include OWUI_PLUGIN_PATH . 'admin/views/dashboard.php';
            
        } catch (Exception $e) {
            owui_log_error('Dashboard Render', $e->getMessage());
            printf(
                '<div class="wrap"><h1>%s</h1><div class="notice notice-error"><p>%s</p></div></div>',
                esc_html__('Dashboard', 'openwebui-chatbot'),
                esc_html__('Failed to load dashboard data.', 'openwebui-chatbot')
            );
        }
    }
    
    public function render_chatbots() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized access.', 'openwebui-chatbot'), 403);
        }
        
        // Handle edit action
        if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
            $this->render_edit_chatbot();
            return;
        }
        
        include OWUI_PLUGIN_PATH . 'admin/views/chatbots.php';
    }
    
    public function render_edit_chatbot() {
        $chatbot_id = absint($_GET['id']);
        
        try {
            $chatbot = $this->db->get_chatbot($chatbot_id);
            
            if (!$chatbot) {
                wp_die(__('Chatbot not found.', 'openwebui-chatbot'), 404);
            }
            
            include OWUI_PLUGIN_PATH . 'admin/views/edit-chatbot.php';
            
        } catch (Exception $e) {
            owui_log_error('Edit Chatbot Render', $e->getMessage());
            wp_die(__('Failed to load chatbot data.', 'openwebui-chatbot'));
        }
    }
    
    public function render_history() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized access.', 'openwebui-chatbot'), 403);
        }
        
        include OWUI_PLUGIN_PATH . 'admin/views/history.php';
    }
    
    public function render_contacts() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized access.', 'openwebui-chatbot'), 403);
        }
        
        include OWUI_PLUGIN_PATH . 'admin/views/contacts.php';
    }
    
    public function render_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized access.', 'openwebui-chatbot'), 403);
        }
        
        include OWUI_PLUGIN_PATH . 'admin/views/settings.php';
    }
    
    // Helper methods for rendering
    
    public function render_model_options($selected = '') {
        try {
            $models = $this->api->get_models();
            
            if (empty($models)) {
                printf(
                    '<option value="">%s</option>',
                    esc_html__('No models available', 'openwebui-chatbot')
                );
                return;
            }
            
            foreach ($models as $model) {
                printf(
                    '<option value="%s"%s>%s</option>',
                    esc_attr($model),
                    selected($selected, $model, false),
                    esc_html($model)
                );
            }
            
        } catch (Exception $e) {
            owui_log_error('Render Model Options', $e->getMessage());
            printf(
                '<option value="">%s</option>',
                esc_html__('Error loading models', 'openwebui-chatbot')
            );
        }
    }
    
    public function render_chatbot_list() {
        try {
            $chatbots = $this->db->get_active_chatbots(false); // No cache for admin
            
            if (empty($chatbots)) {
                printf(
                    '<tr><td colspan="5">%s</td></tr>',
                    esc_html__('No chatbots created yet.', 'openwebui-chatbot')
                );
                return;
            }

            foreach ($chatbots as $chatbot) {
                $this->render_chatbot_row($chatbot);
            }
            
        } catch (Exception $e) {
            owui_log_error('Render Chatbot List', $e->getMessage());
            printf(
                '<tr><td colspan="5">%s</td></tr>',
                esc_html__('Error loading chatbots.', 'openwebui-chatbot')
            );
        }
    }
    
    private function render_chatbot_row($chatbot) {
        $edit_url = add_query_arg([
            'page' => 'owui-chatbots',
            'action' => 'edit',
            'id' => $chatbot->id
        ], admin_url('admin.php'));
        
        $delete_url = wp_nonce_url(
            add_query_arg([
                'action' => 'owui_delete_chatbot',
                'id' => $chatbot->id
            ], admin_url('admin-post.php')),
            'delete_chatbot_' . $chatbot->id
        );
        
        $status = $chatbot->is_active ? 
            __('Active', 'openwebui-chatbot') : 
            __('Inactive', 'openwebui-chatbot');
        $status_class = $chatbot->is_active ? 'owui-status-active' : 'owui-status-inactive';
        
        printf('<tr>');
        
        // Name column with avatar
        printf('<td>');
        if ($chatbot->avatar_url) {
            printf(
                '<img src="%s" alt="%s" style="width: 24px; height: 24px; border-radius: 50%%; margin-right: 8px; vertical-align: middle;">',
                esc_url($chatbot->avatar_url),
                esc_attr($chatbot->name)
            );
        }
        printf('%s</td>', esc_html($chatbot->name));
        
        // Model column
        printf('<td>%s</td>', esc_html($chatbot->model));
        
        // Status column
        printf(
            '<td><span class="%s">%s</span></td>',
            esc_attr($status_class),
            esc_html($status)
        );
        
        // Created column
        printf(
            '<td>%s</td>',
            esc_html(wp_date(get_option('date_format'), strtotime($chatbot->created_at)))
        );
        
        // Actions column
        printf('<td>');
        printf(
            '<a href="%s" class="button button-small">%s</a> ',
            esc_url($edit_url),
            esc_html__('Edit', 'openwebui-chatbot')
        );
        
        printf(
            '<button class="button button-small copy-shortcode" data-shortcode="%s" title="%s">%s</button> ',
            esc_attr('[openwebui_chatbot id="' . $chatbot->id . '"]'),
            esc_attr__('Copy shortcode', 'openwebui-chatbot'),
            esc_html__('Copy Shortcode', 'openwebui-chatbot')
        );
        
        printf(
            '<a href="%s" class="button button-small button-link-delete" onclick="return confirm(\'%s\')">%s</a>',
            esc_url($delete_url),
            esc_js__('Are you sure you want to delete this chatbot? This will also delete all associated chat history.', 'openwebui-chatbot'),
            esc_html__('Delete', 'openwebui-chatbot')
        );
        
        printf('</td>');
        printf('</tr>');
    }
    
    // Dashboard helper methods
    
    /**
     * Get active chatbots count
     */
    public function get_active_chatbots_count() {
        try {
            $stats = $this->db->get_dashboard_stats();
            return $stats['active_chatbots'] ?? 0;
        } catch (Exception $e) {
            owui_log_error('Get Active Chatbots Count', $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get total conversations count
     */
    public function get_total_conversations() {
        try {
            $stats = $this->db->get_dashboard_stats();
            return $stats['total_conversations'] ?? 0;
        } catch (Exception $e) {
            owui_log_error('Get Total Conversations', $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get response rate percentage
     */
    public function get_response_rate() {
        try {
            $stats = $this->db->get_dashboard_stats();
            return $stats['response_rate'] ?? 100;
        } catch (Exception $e) {
            owui_log_error('Get Response Rate', $e->getMessage());
            return 100;
        }
    }
    
    /**
     * Get total contacts collected
     */
    public function get_total_contacts() {
        try {
            $stats = $this->db->get_dashboard_stats();
            return $stats['total_contacts'] ?? 0;
        } catch (Exception $e) {
            owui_log_error('Get Total Contacts', $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Render recent conversations for dashboard
     */
    public function render_recent_conversations() {
        try {
            $conversations = $this->db->get_conversations(['limit' => 10]);
            
            if (empty($conversations)) {
                printf(
                    '<p>%s</p>',
                    esc_html__('No conversations yet.', 'openwebui-chatbot')
                );
                return;
            }
            
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            printf('<th>%s</th>', esc_html__('Time', 'openwebui-chatbot'));
            printf('<th>%s</th>', esc_html__('Chatbot', 'openwebui-chatbot'));
            printf('<th>%s</th>', esc_html__('User', 'openwebui-chatbot'));
            printf('<th>%s</th>', esc_html__('Messages', 'openwebui-chatbot'));
            printf('<th>%s</th>', esc_html__('Status', 'openwebui-chatbot'));
            echo '</tr></thead>';
            echo '<tbody>';
            
            foreach ($conversations as $conv) {
                $time_ago = human_time_diff(
                    strtotime($conv->last_message ?? $conv->started_at),
                    current_time('timestamp')
                );
                
                printf('<tr>');
                printf(
                    '<td>%s</td>',
                    esc_html(sprintf(__('%s ago', 'openwebui-chatbot'), $time_ago))
                );
                printf(
                    '<td>%s</td>',
                    esc_html($conv->chatbot_name ?: __('Unknown', 'openwebui-chatbot'))
                );
                printf(
                    '<td>%s</td>',
                    esc_html($conv->user_name ?: __('Guest', 'openwebui-chatbot'))
                );
                printf(
                    '<td>%d</td>',
                    absint($conv->message_count ?? 0)
                );
                printf(
                    '<td>%s</td>',
                    $conv->ended_at ? 
                        esc_html__('Ended', 'openwebui-chatbot') : 
                        esc_html__('Active', 'openwebui-chatbot')
                );
                printf('</tr>');
            }
            
            echo '</tbody></table>';
            
            // Link to full history
            printf(
                '<p><a href="%s" class="button">%s</a></p>',
                esc_url(admin_url('admin.php?page=owui-history')),
                esc_html__('View All Conversations', 'openwebui-chatbot')
            );
            
        } catch (Exception $e) {
            owui_log_error('Render Recent Conversations', $e->getMessage());
            printf(
                '<p>%s</p>',
                esc_html__('Failed to load recent conversations.', 'openwebui-chatbot')
            );
        }
    }
    
    /**
     * Render contact list for contacts page
     */
    public function render_contact_list() {
        if (!class_exists('OWUI_Contact_Extractor')) {
            printf(
                '<tr><td colspan="6">%s</td></tr>',
                esc_html__('Contact extractor not available.', 'openwebui-chatbot')
            );
            return;
        }
        
        try {
            $extractor = new OWUI_Contact_Extractor();
            $contacts = $extractor->get_recent_contacts_grouped(50);

            if (empty($contacts)) {
                printf(
                    '<tr><td colspan="6">%s</td></tr>',
                    esc_html__('No contact information found.', 'openwebui-chatbot')
                );
                return;
            }

            foreach ($contacts as $contact) {
                printf('<tr>');
                printf(
                    '<td>%s</td>',
                    esc_html(wp_date(get_option('date_format'), strtotime($contact->latest_date)))
                );
                printf(
                    '<td>%s</td>',
                    esc_html($contact->chatbot_name ?: __('Unknown', 'openwebui-chatbot'))
                );
                printf(
                    '<td>%s</td>',
                    esc_html($contact->names ?: '-')
                );
                printf(
                    '<td>%s</td>',
                    esc_html($contact->emails ?: '-')
                );
                printf(
                    '<td>%s</td>',
                    esc_html($contact->phones ?: '-')
                );
                printf(
                    '<td><small>%s</small></td>',
                    esc_html($contact->session_id)
                );
                printf('</tr>');
            }
            
        } catch (Exception $e) {
            owui_log_error('Render Contact List', $e->getMessage());
            printf(
                '<tr><td colspan="6">%s</td></tr>',
                esc_html__('Failed to load contact information.', 'openwebui-chatbot')
            );
        }
    }
}