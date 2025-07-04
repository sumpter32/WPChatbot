<?php
/**
 * Admin functionality for OpenWebUI Chatbot
 */

if (!defined('ABSPATH')) exit;

class OWUI_Admin {
    private $api;
    private $core;

    public function init() {
        $this->api = new OWUI_API();
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_post_owui_create_chatbot', array($this, 'handle_create_chatbot'));
        add_action('admin_post_owui_update_chatbot', array($this, 'handle_update_chatbot'));
        add_action('admin_post_owui_delete_chatbot', array($this, 'handle_delete_chatbot'));
        add_action('wp_ajax_owui_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_owui_get_models', array($this, 'ajax_get_models'));
        add_action('wp_ajax_owui_export_csv', array($this, 'ajax_export_csv'));
        add_action('wp_ajax_owui_export_contacts', array($this, 'ajax_export_contacts'));
        add_action('wp_ajax_owui_clear_history', array($this, 'ajax_clear_history'));
        add_action('wp_ajax_owui_get_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_owui_export_conversation', array($this, 'ajax_export_conversation'));
        add_action('wp_ajax_owui_delete_conversation', array($this, 'ajax_delete_conversation'));
        add_action('wp_ajax_owui_test_email', array($this, 'ajax_test_email'));
        add_action('wp_ajax_owui_end_session', array($this, 'ajax_end_session'));
        add_action('wp_ajax_nopriv_owui_end_session', array($this, 'ajax_end_session'));
    }

    public function get_response_rate() {
        global $wpdb;
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}owui_chat_history");
        $responded = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}owui_chat_history WHERE response != ''");
        return $total > 0 ? round(($responded / $total) * 100, 1) : 100;
    }

    public function ajax_get_stats() {
        check_ajax_referer('owui_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $stats = array(
            $this->get_active_chatbots_count(),
            $this->get_total_conversations(),
            $this->get_response_rate() . '%'
        );
        
        wp_send_json_success($stats);
    }

    public function add_admin_menu() {
        add_menu_page(
            'OpenWebUI Chatbot',
            'OpenWebUI',
            'manage_options',
            'owui-dashboard',
            array($this, 'render_dashboard'),
            'dashicons-format-chat'
        );

        add_submenu_page(
            'owui-dashboard',
            'Chatbots',
            'Chatbots',
            'manage_options',
            'owui-chatbots',
            array($this, 'render_chatbots')
        );

        add_submenu_page(
            'owui-dashboard',
            'Chat History',
            'Chat History',
            'manage_options',
            'owui-history',
            array($this, 'render_history')
        );

        add_submenu_page(
            'owui-dashboard',
            'Contact Information',
            'Contacts',
            'manage_options',
            'owui-contacts',
            array($this, 'render_contacts')
        );

        add_submenu_page(
            'owui-dashboard',
            'Settings',
            'Settings',
            'manage_options',
            'owui-settings',
            array($this, 'render_settings')
        );
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'owui-') !== false) {
            wp_enqueue_style('owui-admin', OWUI_PLUGIN_URL . 'assets/css/admin.css', array(), OWUI_VERSION);
            wp_enqueue_script('owui-admin', OWUI_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), OWUI_VERSION, true);
            wp_localize_script('owui-admin', 'owui_admin_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('owui_admin_nonce')
            ));
        }
    }

    public function ajax_test_email() {
        check_ajax_referer('owui_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        // Load the email notifications class
        if (!class_exists('OWUI_Email_Notifications')) {
            wp_send_json_error('Email notification system not available');
        }
        
        $email_system = new OWUI_Email_Notifications();
        $email_system->ajax_test_email();
    }

    public function ajax_end_session() {
        check_ajax_referer('owui_admin_nonce', 'nonce');
        
        $chatbot_id = absint($_POST['chatbot_id']);
        $reason = sanitize_text_field($_POST['reason'] ?? 'manual');
        
        if (!$chatbot_id) {
            wp_send_json_error('Invalid chatbot ID');
        }
        
        // Get current session
        $user_id = get_current_user_id() ?: null;
        $session_manager = new OWUI_Session_Manager();
        $session_id = $session_manager->get_or_create_session($chatbot_id, $user_id);
        
        if ($session_id) {
            // Trigger the session ended action for email notifications
            do_action('owui_session_ended', $session_id, $reason);
            wp_send_json_success('Session ended');
        } else {
            wp_send_json_error('Session not found');
        }
    }

    public function ajax_test_connection() {
        check_ajax_referer('owui_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $success = $this->api->test_connection();
        
        if ($success) {
            wp_send_json_success('Connection successful!');
        } else {
            wp_send_json_error('Connection failed. Please check your settings.');
        }
    }

    public function ajax_get_models() {
        check_ajax_referer('owui_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $models = $this->api->get_models();
        
        if (empty($models)) {
            wp_send_json_error('No models found or connection failed.');
        } else {
            wp_send_json_success($models);
        }
    }

    public function ajax_export_csv() {
        check_ajax_referer('owui_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        global $wpdb;
        $conversations = $wpdb->get_results("
            SELECT h.*, c.name as chatbot_name, u.display_name as user_name
            FROM {$wpdb->prefix}owui_chat_history h 
            LEFT JOIN {$wpdb->prefix}owui_chatbots c ON h.chatbot_id = c.id 
            LEFT JOIN {$wpdb->prefix}users u ON h.user_id = u.ID
            ORDER BY h.created_at DESC
        ");
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chat-history-' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Add BOM for Excel UTF-8 compatibility
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, array('Date', 'Chatbot', 'User', 'Message', 'Response'));
        
        foreach ($conversations as $conv) {
            fputcsv($output, array(
                $conv->created_at,
                $conv->chatbot_name ?: 'Unknown',
                $conv->user_name ?: 'Guest',
                $conv->message,
                $conv->response
            ));
        }
        
        fclose($output);
        exit;
    }

    public function ajax_clear_history() {
        check_ajax_referer('owui_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        global $wpdb;
        $result = $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}owui_chat_history");
        
        if ($result !== false) {
            wp_send_json_success('History cleared successfully');
        } else {
            wp_send_json_error('Failed to clear history');
        }
    }

    public function ajax_export_contacts() {
        check_ajax_referer('owui_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        if (!class_exists('OWUI_Contact_Extractor')) {
            wp_die('Contact extractor not available');
        }
        
        $extractor = new OWUI_Contact_Extractor();
        $contacts = $extractor->get_all_contacts(1000);
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="contacts-' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Add BOM for Excel UTF-8 compatibility
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, array('Date', 'Chatbot', 'Contact Type', 'Contact Value', 'Session ID'));
        
        foreach ($contacts as $contact) {
            fputcsv($output, array(
                $contact->extracted_at,
                $contact->chatbot_name ?: 'Unknown',
                ucfirst($contact->contact_type),
                $contact->contact_value,
                $contact->session_id
            ));
        }
        
        fclose($output);
        exit;
    }

    public function ajax_export_conversation() {
        check_ajax_referer('owui_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $session_id = absint($_GET['session_id']);
        
        global $wpdb;
        
        // Get conversation details
        $conversation = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, c.name as chatbot_name, u.display_name as user_name
            FROM {$wpdb->prefix}owui_chat_sessions s
            LEFT JOIN {$wpdb->prefix}owui_chatbots c ON s.chatbot_id = c.id
            LEFT JOIN {$wpdb->prefix}users u ON s.user_id = u.ID
            WHERE s.id = %d",
            $session_id
        ));
        
        if (!$conversation) {
            wp_die('Conversation not found');
        }
        
        // Get messages
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}owui_chat_history 
            WHERE session_id = %d 
            ORDER BY created_at ASC",
            $session_id
        ));
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="conversation-' . $session_id . '-' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Add BOM for Excel UTF-8 compatibility
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Add conversation header
        fputcsv($output, array('Conversation Export'));
        fputcsv($output, array('Chatbot', $conversation->chatbot_name ?: 'Unknown'));
        fputcsv($output, array('User', $conversation->user_name ?: 'Guest'));
        fputcsv($output, array('Started', $conversation->started_at));
        fputcsv($output, array('Messages', count($messages)));
        fputcsv($output, array());
        
        // Add headers
        fputcsv($output, array('Time', 'Type', 'Message'));
        
        // Add messages
        foreach ($messages as $message) {
            fputcsv($output, array(
                $message->created_at,
                'User',
                $message->message
            ));
            fputcsv($output, array(
                $message->created_at,
                'Bot',
                $message->response
            ));
        }
        
        fclose($output);
        exit;
    }

    public function ajax_delete_conversation() {
        check_ajax_referer('owui_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $session_id = absint($_POST['session_id']);
        
        global $wpdb;
        
        // Delete chat history
        $wpdb->delete(
            $wpdb->prefix . 'owui_chat_history',
            array('session_id' => $session_id),
            array('%d')
        );
        
        // Delete contact info
        $wpdb->delete(
            $wpdb->prefix . 'owui_contact_info',
            array('session_id' => $session_id),
            array('%d')
        );
        
        // Delete session
        $result = $wpdb->delete(
            $wpdb->prefix . 'owui_chat_sessions',
            array('id' => $session_id),
            array('%d')
        );
        
        if ($result !== false) {
            wp_send_json_success('Conversation deleted successfully');
        } else {
            wp_send_json_error('Failed to delete conversation');
        }
    }

    public function handle_create_chatbot() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('owui_create_chatbot');

        // Validate required fields
        if (empty($_POST['chatbot_name']) || empty($_POST['chatbot_model'])) {
            wp_redirect(admin_url('admin.php?page=owui-chatbots&error=2'));
            exit;
        }

        $name = sanitize_text_field($_POST['chatbot_name']);
        $model = sanitize_text_field($_POST['chatbot_model']);
        $system_prompt = sanitize_textarea_field($_POST['system_prompt'] ?? '');
        $greeting_message = sanitize_textarea_field($_POST['greeting_message'] ?? '');
        
        $data = array(
            'name' => $name,
            'model' => $model,
            'system_prompt' => $system_prompt,
            'greeting_message' => $greeting_message,
            'is_active' => 1,
            'created_at' => current_time('mysql')
        );
        
        $format = array('%s', '%s', '%s', '%s', '%d', '%s');
        
        // Handle avatar upload
        if (!empty($_FILES['chatbot_avatar']['name'])) {
            $allowed_types = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
            $file_type = wp_check_filetype($_FILES['chatbot_avatar']['name']);
            
            if (in_array($file_type['type'], $allowed_types)) {
                // Include WordPress file handling functions if they don't exist
                if (!function_exists('wp_handle_upload')) {
                    $upload_file = ABSPATH . 'wp-admin/includes/file.php';
                    if (file_exists($upload_file)) {
                        require_once($upload_file);
                    }
                }
                
                if (!function_exists('wp_handle_upload')) {
                    // Fallback: skip avatar upload if functions not available
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('OpenWebUI Chatbot: wp_handle_upload function not available');
                    }
                } else {
                    $upload = wp_handle_upload($_FILES['chatbot_avatar'], array('test_form' => false));
                    
                    if (!isset($upload['error']) && isset($upload['url'])) {
                        $data['avatar_url'] = esc_url_raw($upload['url']);
                        $format[] = '%s';
                    }
                }
            }
        }

        global $wpdb;
        
        // Debug the insert operation
        $result = $wpdb->insert(
            $wpdb->prefix . 'owui_chatbots',
            $data,
            $format
        );

        // Check for database errors
        if ($result === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('OpenWebUI Chatbot - Database error: ' . $wpdb->last_error);
            }
            wp_redirect(admin_url('admin.php?page=owui-chatbots&error=3'));
        } else {
            wp_redirect(admin_url('admin.php?page=owui-chatbots&created=1'));
        }
        exit;
    }

    public function handle_update_chatbot() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('owui_update_chatbot');

        $id = absint($_POST['chatbot_id']);
        $name = sanitize_text_field($_POST['chatbot_name']);
        $model = sanitize_text_field($_POST['chatbot_model']);
        $system_prompt = sanitize_textarea_field($_POST['system_prompt']);
        $greeting_message = sanitize_textarea_field($_POST['greeting_message']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        $data = array(
            'name' => $name,
            'model' => $model,
            'system_prompt' => $system_prompt,
            'greeting_message' => $greeting_message,
            'is_active' => $is_active
        );
        
        $format = array('%s', '%s', '%s', '%s', '%d');
        
        // Handle avatar upload
        if (!empty($_FILES['chatbot_avatar']['name'])) {
            $allowed_types = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
            $file_type = wp_check_filetype($_FILES['chatbot_avatar']['name']);
            
            if (in_array($file_type['type'], $allowed_types)) {
                // Include WordPress file handling functions if they don't exist
                if (!function_exists('wp_handle_upload')) {
                    $upload_file = ABSPATH . 'wp-admin/includes/file.php';
                    if (file_exists($upload_file)) {
                        require_once($upload_file);
                    }
                }
                
                if (!function_exists('wp_handle_upload')) {
                    // Fallback: skip avatar upload if functions not available
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('OpenWebUI Chatbot: wp_handle_upload function not available');
                    }
                } else {
                    $upload = wp_handle_upload($_FILES['chatbot_avatar'], array('test_form' => false));
                    
                    if (!isset($upload['error']) && isset($upload['url'])) {
                        $data['avatar_url'] = esc_url_raw($upload['url']);
                        $format[] = '%s';
                    }
                }
            }
        }

        global $wpdb;
        $result = $wpdb->update(
            $wpdb->prefix . 'owui_chatbots',
            $data,
            array('id' => $id),
            $format,
            array('%d')
        );

        if ($result !== false) {
            wp_redirect(admin_url('admin.php?page=owui-chatbots&updated=1'));
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('OpenWebUI Chatbot - Update error: ' . $wpdb->last_error);
            }
            wp_redirect(admin_url('admin.php?page=owui-chatbots&error=1'));
        }
        exit;
    }

    public function handle_delete_chatbot() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $chatbot_id = absint($_GET['id'] ?? 0);
        
        if (!$chatbot_id) {
            wp_die('Invalid chatbot ID');
        }
        
        check_admin_referer('delete_chatbot_' . $chatbot_id);
        
        global $wpdb;
        
        // Delete associated chat history
        $wpdb->delete(
            $wpdb->prefix . 'owui_chat_history',
            array('chatbot_id' => $chatbot_id),
            array('%d')
        );
        
        // Delete chatbot
        $result = $wpdb->delete(
            $wpdb->prefix . 'owui_chatbots',
            array('id' => $chatbot_id),
            array('%d')
        );
        
        if ($result) {
            wp_redirect(admin_url('admin.php?page=owui-chatbots&deleted=1'));
        } else {
            wp_redirect(admin_url('admin.php?page=owui-chatbots&error=1'));
        }
        exit;
    }

    // Admin page render methods
    public function render_dashboard() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $admin = $this;
        $dashboard_file = OWUI_PLUGIN_PATH . 'admin/views/dashboard.php';
        if (file_exists($dashboard_file)) {
            include $dashboard_file;
        } else {
            echo '<div class="wrap"><h1>Dashboard</h1><p>Dashboard view file not found.</p></div>';
        }
    }

    public function render_chatbots() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        // Handle edit action
        if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
            $this->render_edit_chatbot();
            return;
        }
        
        $admin = $this;
        $chatbots_file = OWUI_PLUGIN_PATH . 'admin/views/chatbots.php';
        if (file_exists($chatbots_file)) {
            include $chatbots_file;
        } else {
            echo '<div class="wrap"><h1>Chatbots</h1><p>Chatbots view file not found.</p></div>';
        }
    }

    public function render_edit_chatbot() {
        $chatbot_id = absint($_GET['id']);
        
        global $wpdb;
        $chatbot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}owui_chatbots WHERE id = %d",
            $chatbot_id
        ));
        
        if (!$chatbot) {
            wp_die('Chatbot not found');
        }
        
        $admin = $this;
        $edit_file = OWUI_PLUGIN_PATH . 'admin/views/edit-chatbot.php';
        if (file_exists($edit_file)) {
            include $edit_file;
        } else {
            echo '<div class="wrap"><h1>Edit Chatbot</h1><p>Edit view file not found.</p></div>';
        }
    }

    public function render_history() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $admin = $this;
        $history_file = OWUI_PLUGIN_PATH . 'admin/views/history.php';
        if (file_exists($history_file)) {
            include $history_file;
        } else {
            echo '<div class="wrap"><h1>Chat History</h1><p>History view file not found.</p></div>';
        }
    }

    public function render_contacts() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $admin = $this;
        $contacts_file = OWUI_PLUGIN_PATH . 'admin/views/contacts.php';
        if (file_exists($contacts_file)) {
            include $contacts_file;
        } else {
            echo '<div class="wrap"><h1>Contact Information</h1><p>Contacts view file not found.</p></div>';
        }
    }

    public function render_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $admin = $this;
        $settings_file = OWUI_PLUGIN_PATH . 'admin/views/settings.php';
        if (file_exists($settings_file)) {
            include $settings_file;
        } else {
            echo '<div class="wrap"><h1>Settings</h1><p>Settings view file not found.</p></div>';
        }
    }

    public function render_model_options($selected = '') {
        $models = $this->api->get_models();
        if (empty($models)) {
            echo '<option value="">' . esc_html__('No models available', 'openwebui-chatbot') . '</option>';
            return;
        }
        
        foreach ($models as $model) {
            $selected_attr = selected($selected, $model, false);
            echo '<option value="' . esc_attr($model) . '"' . $selected_attr . '>' . esc_html($model) . '</option>';
        }
    }

    public function render_chatbot_list() {
        global $wpdb;
        $chatbots = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}owui_chatbots ORDER BY created_at DESC");

        if (empty($chatbots)) {
            echo '<tr><td colspan="5">' . esc_html__('No chatbots created yet.', 'openwebui-chatbot') . '</td></tr>';
            return;
        }

        foreach ($chatbots as $chatbot) {
            $edit_url = admin_url('admin.php?page=owui-chatbots&action=edit&id=' . $chatbot->id);
            $delete_url = wp_nonce_url(
                admin_url('admin-post.php?action=owui_delete_chatbot&id=' . $chatbot->id),
                'delete_chatbot_' . $chatbot->id
            );
            
            $status = $chatbot->is_active ? 'Active' : 'Inactive';
            $status_class = $chatbot->is_active ? 'owui-status-active' : 'owui-status-inactive';
            
            echo '<tr>';
            echo '<td>';
            if ($chatbot->avatar_url) {
                echo '<img src="' . esc_url($chatbot->avatar_url) . '" alt="Avatar" style="width: 24px; height: 24px; border-radius: 50%; margin-right: 8px; vertical-align: middle;">';
            }
            echo esc_html($chatbot->name);
            echo '</td>';
            echo '<td>' . esc_html($chatbot->model) . '</td>';
            echo '<td><span class="' . esc_attr($status_class) . '">' . esc_html($status) . '</span></td>';
            echo '<td>' . esc_html(date('M j, Y', strtotime($chatbot->created_at))) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($edit_url) . '" class="button button-small">Edit</a> ';
            
            // Shortcode buttons
            echo '<button class="button button-small copy-shortcode" data-shortcode="[openwebui_chatbot id=&quot;' . $chatbot->id . '&quot;]" title="Copy shortcode">Copy Shortcode</button> ';
            
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'Are you sure you want to delete this chatbot? This will also delete all associated chat history.\')">Delete</a>';
            echo '</td>';
            echo '</tr>';
        }
    }

    public function render_contact_list() {
        if (!class_exists('OWUI_Contact_Extractor')) {
            echo '<tr><td colspan="6">Contact extractor not available.</td></tr>';
            return;
        }
        
        $extractor = new OWUI_Contact_Extractor();
        $contacts = $extractor->get_recent_contacts_grouped(50);

        if (empty($contacts)) {
            echo '<tr><td colspan="6">No contact information found.</td></tr>';
            return;
        }

        foreach ($contacts as $contact) {
            echo '<tr>';
            echo '<td>' . esc_html(date('M j, Y', strtotime($contact->latest_date))) . '</td>';
            echo '<td>' . esc_html($contact->chatbot_name ?: 'Unknown') . '</td>';
            echo '<td>' . esc_html($contact->names ?: '-') . '</td>';
            echo '<td>' . esc_html($contact->emails ?: '-') . '</td>';
            echo '<td>' . esc_html($contact->phones ?: '-') . '</td>';
            echo '<td><small>' . esc_html($contact->session_id) . '</small></td>';
            echo '</tr>';
        }
    }

    public function get_active_chatbots_count() {
        global $wpdb;
        return $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}owui_chatbots WHERE is_active = 1");
    }

    public function get_total_conversations() {
        global $wpdb;
        return $wpdb->get_var("SELECT COUNT(DISTINCT session_id) FROM {$wpdb->prefix}owui_chat_history WHERE session_id IS NOT NULL");
    }

    public function get_total_contacts() {
        global $wpdb;
        return $wpdb->get_var("SELECT COUNT(DISTINCT session_id) FROM {$wpdb->prefix}owui_contact_info");
    }

    public function render_recent_conversations() {
        global $wpdb;
        $conversations = $wpdb->get_results("
            SELECT h.*, c.name as chatbot_name 
            FROM {$wpdb->prefix}owui_chat_history h 
            LEFT JOIN {$wpdb->prefix}owui_chatbots c ON h.chatbot_id = c.id 
            ORDER BY h.created_at DESC 
            LIMIT 10
        ");

        if (empty($conversations)) {
            echo '<p>' . esc_html__('No conversations yet.', 'openwebui-chatbot') . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>Time</th><th>Chatbot</th><th>Message</th><th>Response</th></tr></thead>';
        echo '<tbody>';
        foreach ($conversations as $conv) {
            echo '<tr>';
            echo '<td>' . esc_html(human_time_diff(strtotime($conv->created_at), current_time('timestamp')) . ' ago') . '</td>';
            echo '<td>' . esc_html($conv->chatbot_name ?: 'Unknown') . '</td>';
            echo '<td>' . esc_html(wp_trim_words($conv->message, 10)) . '</td>';
            echo '<td>' . esc_html(wp_trim_words($conv->response, 10)) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}