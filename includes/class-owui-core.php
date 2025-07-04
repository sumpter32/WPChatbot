<?php
/**
 * Core functionality for OpenWebUI Chatbot
 */

if (!defined('ABSPATH')) exit;

class OWUI_Core {
    private $api;
    private $admin;
    private $rate_limiter;
    private $session_manager;
    private $contact_extractor;

    public function init() {
        // Initialize components
        $this->api = new OWUI_API();
        $this->rate_limiter = new OWUI_Rate_Limiter();
        $this->session_manager = new OWUI_Session_Manager();
        $this->contact_extractor = new OWUI_Contact_Extractor();
        
        // Initialize admin interface if in admin area
        if (is_admin()) {
            $this->admin = new OWUI_Admin();
            $this->admin->init();
        }

        // Add frontend functionality
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('wp_footer', array($this, 'add_chatbot_html'));
        add_action('wp_ajax_owui_send_message', array($this, 'handle_chat_message'));
        add_action('wp_ajax_nopriv_owui_send_message', array($this, 'handle_chat_message'));
        
        // Add new AJAX handlers
        add_action('wp_ajax_owui_get_chatbot_details', array($this, 'ajax_get_chatbot_details'));
        add_action('wp_ajax_nopriv_owui_get_chatbot_details', array($this, 'ajax_get_chatbot_details'));
        add_action('wp_ajax_owui_upload_file', array($this, 'ajax_upload_file'));
        add_action('wp_ajax_nopriv_owui_upload_file', array($this, 'ajax_upload_file'));
        add_action('wp_ajax_owui_clear_session', array($this, 'ajax_clear_session'));
        add_action('wp_ajax_nopriv_owui_clear_session', array($this, 'ajax_clear_session'));

        // Register activation/deactivation hooks
        register_activation_hook(OWUI_PLUGIN_PATH . 'openwebui-chatbot.php', array($this, 'activate'));
        register_deactivation_hook(OWUI_PLUGIN_PATH . 'openwebui-chatbot.php', array($this, 'deactivate'));
        
        // Initialize integrations
        //$this->init_elementor(); // Removed to prevent duplicate Elementor registration
        $this->init_shortcodes();
        
        // Add session cleanup cron
        add_action('owui_cleanup_sessions', array($this, 'cleanup_old_sessions'));
        
        if (!wp_next_scheduled('owui_cleanup_sessions')) {
            wp_schedule_event(time(), 'hourly', 'owui_cleanup_sessions');
        }
    }
    
    public function init_shortcodes() {
        add_shortcode('openwebui_chatbot', array($this, 'chatbot_shortcode'));
    }
    
    public function chatbot_shortcode($atts) {
        $atts = shortcode_atts(array(
            'id' => '0',
            'type' => 'floating',
            'position' => 'bottom-right',
            'button_text' => __('Chat with us', 'openwebui-chatbot'),
            'width' => '350',
            'height' => '500',
        ), $atts);
        
        global $wpdb;
        
        if ($atts['id'] == '0') {
            $chatbot = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}owui_chatbots WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
        } else {
            $chatbot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}owui_chatbots WHERE id = %d AND is_active = 1",
                absint($atts['id'])
            ));
        }
        
        if (!$chatbot) {
            return '<p>' . esc_html__('No active chatbot found.', 'openwebui-chatbot') . '</p>';
        }
        
        // Enqueue scripts if not already loaded
        wp_enqueue_script('owui-elementor-frontend');
        wp_enqueue_style('owui-elementor-frontend');
        
        ob_start();
        ?>
        <div class="owui-shortcode-widget" data-display-type="<?php echo esc_attr($atts['type']); ?>">
            <?php if ($atts['type'] === 'inline'): ?>
                <div class="owui-inline-chat" data-chatbot-id="<?php echo esc_attr($chatbot->id); ?>" 
                     style="width: <?php echo esc_attr($atts['width']); ?>px; height: <?php echo esc_attr($atts['height']); ?>px;">
                    <div class="owui-elementor-header">
                        <h4><?php echo esc_html($chatbot->name); ?></h4>
                    </div>
                    <div class="owui-elementor-messages">
                        <?php if ($chatbot->greeting_message): ?>
                            <div class="owui-message bot"><?php echo wp_kses_post($this->format_message($chatbot->greeting_message)); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="owui-elementor-input-container">
                        <input type="text" class="owui-elementor-input" placeholder="<?php echo esc_attr__('Type your message...', 'openwebui-chatbot'); ?>">
                        <button class="owui-elementor-send"><?php echo esc_html__('Send', 'openwebui-chatbot'); ?></button>
                    </div>
                </div>
            <?php elseif ($atts['type'] === 'button'): ?>
                <button class="owui-popup-button" data-chatbot-id="<?php echo esc_attr($chatbot->id); ?>">
                    <?php echo esc_html($atts['button_text']); ?>
                </button>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function ajax_get_chatbot_details() {
        check_ajax_referer('owui_nonce', 'nonce');
        
        $chatbot_id = absint($_POST['chatbot_id']);
        $chatbot = $this->api->get_chatbot_details($chatbot_id);
        
        if ($chatbot) {
            wp_send_json_success($chatbot);
        } else {
            wp_send_json_error(__('Chatbot not found', 'openwebui-chatbot'));
        }
    }
    
    public function ajax_upload_file() {
        check_ajax_referer('owui_nonce', 'nonce');
        
        if (empty($_FILES['file'])) {
            wp_send_json_error(__('No file uploaded', 'openwebui-chatbot'));
        }
        
        // Check file type
        $allowed_types = array('pdf', 'txt', 'doc', 'docx', 'csv', 'json');
        $file_type = wp_check_filetype($_FILES['file']['name']);
        
        if (!in_array($file_type['ext'], $allowed_types)) {
            wp_send_json_error(__('File type not allowed', 'openwebui-chatbot'));
        }
        
        // Upload to OpenWebUI
        $response = $this->api->upload_file($_FILES['file']);
        
        if (isset($response['error'])) {
            wp_send_json_error($response['error']);
        }
        
        wp_send_json_success($response);
    }

    public function ajax_clear_session() {
        check_ajax_referer('owui_nonce', 'nonce');
        
        $chatbot_id = absint($_POST['chatbot_id']);
        
        // End current session
        $user_id = get_current_user_id() ?: null;
        $session_db_id = $this->session_manager->get_or_create_session($chatbot_id, $user_id);
        
        if ($session_db_id) {
            $this->session_manager->end_session($session_db_id);
        }
        
        wp_send_json_success('Session cleared');
    }

    public function activate() {
        // Tables are now created in main plugin file activation hook
        // This method can be used for other activation tasks if needed
    }

    public function deactivate() {
        wp_clear_scheduled_hook('owui_cleanup_sessions');
    }

    public function enqueue_frontend_assets() {
        wp_enqueue_script('jquery');
        wp_enqueue_script('owui-frontend', OWUI_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), OWUI_VERSION, true);
        wp_enqueue_style('owui-frontend', OWUI_PLUGIN_URL . 'assets/css/frontend.css', array(), OWUI_VERSION);
        
        // Enqueue Elementor assets
        wp_register_style('owui-elementor-frontend', OWUI_PLUGIN_URL . 'assets/css/elementor-frontend.css', array(), OWUI_VERSION);
        wp_register_script('owui-elementor-frontend', OWUI_PLUGIN_URL . 'assets/js/elementor-frontend.js', array('jquery'), OWUI_VERSION, true);
        
        $localize_data = array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('owui_nonce'),
            'rate_limit_message' => __('Please wait a moment before sending another message.', 'openwebui-chatbot'),
            'error_message' => __('Sorry, an error occurred. Please try again.', 'openwebui-chatbot'),
            'connection_error' => __('Connection error. Please check your internet connection.', 'openwebui-chatbot'),
        );
        
        wp_localize_script('owui-frontend', 'owui_ajax', $localize_data);
        wp_localize_script('owui-elementor-frontend', 'owui_elementor', $localize_data);
    }

    public function add_chatbot_html() {
        // Check if we should display the global chatbot
        $display_global = apply_filters('owui_display_global_chatbot', true);
        
        if (!$display_global) {
            return;
        }
        
        // Check if user has disabled chatbot
        if (isset($_COOKIE['owui_chatbot_hidden']) && $_COOKIE['owui_chatbot_hidden'] === 'true') {
            return;
        }
        
        global $wpdb;
        $chatbot = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}owui_chatbots WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
        
        if (!$chatbot) return;
        
        ?>
        <div id="owui-chatbot" data-chatbot-id="<?php echo esc_attr($chatbot->id); ?>">
            <div id="owui-chatbot-toggle">
                <?php if ($chatbot->avatar_url): ?>
                    <img src="<?php echo esc_url($chatbot->avatar_url); ?>" alt="<?php echo esc_attr($chatbot->name); ?>">
                <?php else: ?>
                    <span>💬</span>
                <?php endif; ?>
            </div>
            <div id="owui-chatbot-container" style="display: none;">
                <div id="owui-chatbot-header">
                    <h4><?php echo esc_html($chatbot->name); ?></h4>
                    <button id="owui-chatbot-close">×</button>
                </div>
                <div id="owui-chatbot-messages">
                    <?php if ($chatbot->greeting_message): ?>
                        <div class="owui-message bot owui-greeting"><?php echo wp_kses_post($this->format_message($chatbot->greeting_message)); ?></div>
                    <?php endif; ?>
                </div>
                <div id="owui-chatbot-input-container">
                    <input type="text" id="owui-chatbot-input" placeholder="<?php echo esc_attr__('Type your message...', 'openwebui-chatbot'); ?>">
                    <label for="owui-file-upload" class="owui-file-upload-label" title="<?php echo esc_attr__('Upload file', 'openwebui-chatbot'); ?>">
                        <span>📎</span>
                        <input type="file" id="owui-file-upload" accept=".pdf,.txt,.doc,.docx,.csv,.json" style="display: none;">
                    </label>
                    <button id="owui-chatbot-send"><?php echo esc_html__('Send', 'openwebui-chatbot'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    public function handle_chat_message() {
        check_ajax_referer('owui_nonce', 'nonce');
        
        $message = sanitize_textarea_field($_POST['message']);
        $chatbot_id = absint($_POST['chatbot_id']);
        $file_id = isset($_POST['file_id']) ? sanitize_text_field($_POST['file_id']) : null;
        
        // Rate limiting
        $user_identifier = $this->get_user_identifier();
        if (!$this->rate_limiter->check_rate_limit($user_identifier)) {
            wp_send_json_error(__('Rate limit exceeded. Please wait a moment before sending another message.', 'openwebui-chatbot'));
        }
        
        // Get chatbot details
        global $wpdb;
        $chatbot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}owui_chatbots WHERE id = %d", 
            $chatbot_id
        ));
        
        if (!$chatbot) {
            wp_send_json_error(__('Chatbot not found', 'openwebui-chatbot'));
        }
        
        // Get or create session (returns database session ID, not PHP session ID)
        $user_id = get_current_user_id() ?: null;
        $session_db_id = $this->session_manager->get_or_create_session($chatbot_id, $user_id);
        
        if (!$session_db_id) {
            wp_send_json_error(__('Failed to create chat session', 'openwebui-chatbot'));
        }
        
        // Get conversation context (recent chat history)
        $context = $this->session_manager->get_conversation_context($session_db_id, 10);
        
        // Send to OpenWebUI API with context
        $response = $this->api->send_chat_message_with_context(
            $chatbot->model, 
            $message, 
            $chatbot->system_prompt,
            $context,
            $file_id
        );
        
        if (isset($response['error'])) {
            $this->log_error('Chat API Error', $response['error']);
            wp_send_json_error($response['error']);
        }

        // Save to chat history with the database session ID
        $result = $wpdb->insert(
            $wpdb->prefix . 'owui_chat_history',
            array(
                'chatbot_id' => $chatbot_id,
                'session_id' => $session_db_id,  // This is the database session ID
                'user_id' => $user_id,
                'message' => $message,
                'response' => $response['content'],
                'created_at' => current_time('mysql')
            ),
            array('%d', '%d', '%d', '%s', '%s', '%s')
        );
        
        $message_id = $wpdb->insert_id;
        
        // Extract and save contact information
        $contact_data = $this->contact_extractor->extract_contact_info($message, $response['content']);
        if (!empty($contact_data)) {
            $this->contact_extractor->save_contact_info($session_db_id, $contact_data, $message_id);
        }
        
        // Format response
        $formatted_response = $this->format_message($response['content']);

        wp_send_json_success($formatted_response);
    }
    
    public function format_message($text) {
        // Convert markdown to HTML
        $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*(.*?)\*/s', '<em>$1</em>', $text);
        $text = preg_replace('/```(.*?)```/s', '<pre><code>$1</code></pre>', $text);
        $text = preg_replace('/`(.*?)`/', '<code>$1</code>', $text);
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $text);
        $text = preg_replace('/^- (.+)$/m', '<li>$1</li>', $text);
        $text = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $text);
        $text = preg_replace('/^(\d+)\. (.+)$/m', '<li>$2</li>', $text);
        $text = nl2br($text);
        
        return wp_kses_post($text);
    }
    
    private function get_user_identifier() {
        if (is_user_logged_in()) {
            return 'user_' . get_current_user_id();
        }
        return 'ip_' . $_SERVER['REMOTE_ADDR'];
    }
    
    private function log_error($type, $message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[OpenWebUI] %s: %s', $type, $message));
        }
    }
    
    public function cleanup_old_sessions() {
        global $wpdb;
        
        // Clean up sessions older than 24 hours with no activity
        $wpdb->query("
            UPDATE {$wpdb->prefix}owui_chat_sessions 
            SET ended_at = NOW() 
            WHERE ended_at IS NULL 
            AND session_id NOT IN (
                SELECT DISTINCT session_id 
                FROM {$wpdb->prefix}owui_chat_history 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            )
        ");
    }

    public function get_active_chatbots_count() {
        global $wpdb;
        return $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}owui_chatbots WHERE is_active = 1");
    }

    public function get_total_conversations() {
        global $wpdb;
        return $wpdb->get_var("SELECT COUNT(DISTINCT session_id) FROM {$wpdb->prefix}owui_chat_history WHERE session_id IS NOT NULL");
    }

    public function get_response_rate() {
        global $wpdb;
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}owui_chat_history");
        $responded = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}owui_chat_history WHERE response != ''");
        return $total > 0 ? round(($responded / $total) * 100, 1) : 100;
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