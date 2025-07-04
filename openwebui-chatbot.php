<?php
/**
 * Plugin Name: OpenWebUI Chatbot
 * Description: Integrate OpenWebUI API chatbots into WordPress with Elementor support and email notifications
 * Version: 1.2.0
 * Author: SRS Designs LLC
 * Text Domain: openwebui-chatbot
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */

if (!defined('ABSPATH')) exit;

// Plugin constants
define('OWUI_VERSION', '1.2.0');
define('OWUI_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('OWUI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OWUI_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Debug mode (set to true for verbose logging)
define('OWUI_DEBUG', false);

// Disable SSL verification (only for development)
// define('OWUI_DISABLE_SSL_VERIFY', true);

// Check requirements and create tables on activation
function owui_activation_check() {
    if (version_compare(PHP_VERSION, '7.2', '<')) {
        deactivate_plugins(OWUI_PLUGIN_BASENAME);
        wp_die(__('This plugin requires PHP 7.2 or higher.', 'openwebui-chatbot'));
    }
    
    if (version_compare(get_bloginfo('version'), '5.0', '<')) {
        deactivate_plugins(OWUI_PLUGIN_BASENAME);
        wp_die(__('This plugin requires WordPress 5.0 or higher.', 'openwebui-chatbot'));
    }
    
    // Create database tables
    owui_create_tables();
    owui_create_default_chatbot();
}

// Create database tables
function owui_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Chatbots table
    $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}owui_chatbots (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        model varchar(100) NOT NULL,
        system_prompt text,
        greeting_message text,
        avatar_url varchar(255),
        is_active tinyint(1) DEFAULT 1,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY is_active (is_active)
    ) $charset_collate;";

    // Chat sessions table
    $sql .= "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}owui_chat_sessions (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        chatbot_id bigint(20) NOT NULL,
        user_id bigint(20),
        session_id varchar(100),
        started_at datetime DEFAULT CURRENT_TIMESTAMP,
        ended_at datetime,
        PRIMARY KEY (id),
        KEY chatbot_id (chatbot_id),
        KEY user_id (user_id),
        KEY session_id (session_id),
        KEY ended_at (ended_at)
    ) $charset_collate;";

    // Chat history table with session support
    $sql .= "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}owui_chat_history (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        chatbot_id bigint(20) NOT NULL,
        session_id bigint(20),
        user_id bigint(20),
        message text NOT NULL,
        response text NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY chatbot_id (chatbot_id),
        KEY session_id (session_id),
        KEY user_id (user_id),
        KEY created_at (created_at)
    ) $charset_collate;";

    // Contact information table
    $sql .= "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}owui_contact_info (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        session_id bigint(20) NOT NULL,
        message_id bigint(20),
        contact_type varchar(20) NOT NULL,
        contact_value varchar(255) NOT NULL,
        extracted_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY session_id (session_id),
        KEY contact_type (contact_type),
        KEY extracted_at (extracted_at),
        UNIQUE KEY unique_contact (session_id, contact_type, contact_value)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Create default chatbot
function owui_create_default_chatbot() {
    global $wpdb;
    
    // Check if any chatbot exists
    $exists = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}owui_chatbots");
    
    if (!$exists) {
        $wpdb->insert(
            $wpdb->prefix . 'owui_chatbots',
            array(
                'name' => 'Assistant',
                'model' => 'gpt-3.5-turbo',
                'system_prompt' => 'You are a helpful assistant.',
                'greeting_message' => 'Hello! How can I help you today?',
                'is_active' => 1,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s')
        );
    }
}

// Force create tables - this will run every time until tables exist
function owui_force_create_tables() {
    global $wpdb;
    
    // Check if the main table exists
    $table_name = $wpdb->prefix . 'owui_chatbots';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    
    if (!$table_exists) {
        owui_create_tables();
        owui_create_default_chatbot();
    }
}

// Run this on every admin page load until tables exist
add_action('admin_init', 'owui_force_create_tables');

// Also try to run on plugin activation
register_activation_hook(__FILE__, function() {
    owui_activation_check();
    owui_force_create_tables();
});

register_activation_hook(__FILE__, 'owui_activation_check');

// Load text domain
function owui_load_textdomain() {
    load_plugin_textdomain('openwebui-chatbot', false, dirname(OWUI_PLUGIN_BASENAME) . '/languages');
}
add_action('plugins_loaded', 'owui_load_textdomain');

// Load helper functions
require_once OWUI_PLUGIN_PATH . 'includes/helpers.php';

// Load core classes
require_once OWUI_PLUGIN_PATH . 'includes/class-owui-api.php';
require_once OWUI_PLUGIN_PATH . 'includes/class-owui-admin.php';
require_once OWUI_PLUGIN_PATH . 'includes/class-owui-rate-limiter.php';
require_once OWUI_PLUGIN_PATH . 'includes/class-owui-session-manager.php';
require_once OWUI_PLUGIN_PATH . 'includes/class-owui-contact-extractor.php';
require_once OWUI_PLUGIN_PATH . 'includes/class-owui-email-notifications.php';
require_once OWUI_PLUGIN_PATH . 'includes/class-owui-core.php';

// Initialize plugin
function owui_init() {
    // Check if all required classes exist
    if (!class_exists('OWUI_Core')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>OpenWebUI Chatbot: Core class not found. Please check file permissions.</p></div>';
        });
        return;
    }
    
    $core = new OWUI_Core();
    $core->init();
    
    // Initialize email notifications
    if (class_exists('OWUI_Email_Notifications')) {
        new OWUI_Email_Notifications();
    }
}
add_action('plugins_loaded', 'owui_init', 20);

// Register settings
function owui_register_settings() {
    // API Settings
    register_setting('owui_settings', 'owui_api_key', array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => ''
    ));
    
    register_setting('owui_settings', 'owui_jwt_token', array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => ''
    ));
    
    register_setting('owui_settings', 'owui_base_url', array(
        'type' => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default' => ''
    ));
    
    // Email Notification Settings
    register_setting('owui_settings', 'owui_email_notifications', array(
        'type' => 'boolean',
        'sanitize_callback' => 'rest_sanitize_boolean',
        'default' => false
    ));
    
    register_setting('owui_settings', 'owui_notification_email', array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_email',
        'default' => get_option('admin_email')
    ));
    
    register_setting('owui_settings', 'owui_session_timeout', array(
        'type' => 'integer',
        'sanitize_callback' => 'absint',
        'default' => 15
    ));
    
    register_setting('owui_settings', 'owui_email_on_contact_info', array(
        'type' => 'boolean',
        'sanitize_callback' => 'rest_sanitize_boolean',
        'default' => true
    ));
    
    register_setting('owui_settings', 'owui_email_on_long_conversations', array(
        'type' => 'boolean',
        'sanitize_callback' => 'rest_sanitize_boolean',
        'default' => false
    ));
    
    register_setting('owui_settings', 'owui_email_on_keywords', array(
        'type' => 'boolean',
        'sanitize_callback' => 'rest_sanitize_boolean',
        'default' => false
    ));
    
    register_setting('owui_settings', 'owui_notification_keywords', array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_textarea_field',
        'default' => ''
    ));
    
    register_setting('owui_settings', 'owui_email_subject', array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => 'New Chatbot Conversation Summary'
    ));
    
    register_setting('owui_settings', 'owui_email_header', array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_textarea_field',
        'default' => 'A new conversation has ended on your website. Here\'s a summary:'
    ));
}
add_action('admin_init', 'owui_register_settings');

// Add settings link to plugins page
function owui_add_settings_link($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=owui-settings') . '">' . 
                     __('Settings', 'openwebui-chatbot') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . OWUI_PLUGIN_BASENAME, 'owui_add_settings_link');

// Pro features filter (for future extensibility)
add_filter('owui_pro_features_enabled', function() {
    return defined('OWUI_PRO_VERSION');
});

// Custom capabilities
function owui_add_capabilities() {
    $role = get_role('administrator');
    if ($role) {
        $role->add_cap('manage_owui_chatbots');
        $role->add_cap('view_owui_analytics');
    }
}
register_activation_hook(__FILE__, 'owui_add_capabilities');

// Remove capabilities on deactivation
function owui_remove_capabilities() {
    $role = get_role('administrator');
    if ($role) {
        $role->remove_cap('manage_owui_chatbots');
        $role->remove_cap('view_owui_analytics');
    }
}
register_deactivation_hook(__FILE__, 'owui_remove_capabilities');

// Add custom cron schedules
function owui_add_cron_schedules($schedules) {
    $schedules['every_five_minutes'] = array(
        'interval' => 300, // 5 minutes in seconds
        'display' => __('Every 5 Minutes', 'openwebui-chatbot')
    );
    return $schedules;
}
add_filter('cron_schedules', 'owui_add_cron_schedules');

// Handle delayed session ending
add_action('owui_delayed_session_end', function($session_id) {
    if (class_exists('OWUI_Email_Notifications')) {
        $email_system = new OWUI_Email_Notifications();
        $email_system->handle_delayed_session_end($session_id);
    }
});

// Privacy policy content
function owui_privacy_policy_content() {
    $content = '<h2>' . __('OpenWebUI Chatbot', 'openwebui-chatbot') . '</h2>';
    $content .= '<p>' . __('This plugin collects chat messages and responses to provide AI-powered chat functionality.', 'openwebui-chatbot') . '</p>';
    $content .= '<h3>' . __('What data we collect', 'openwebui-chatbot') . '</h3>';
    $content .= '<ul>';
    $content .= '<li>' . __('Chat messages sent by users', 'openwebui-chatbot') . '</li>';
    $content .= '<li>' . __('AI responses to those messages', 'openwebui-chatbot') . '</li>';
    $content .= '<li>' . __('Timestamp of conversations', 'openwebui-chatbot') . '</li>';
    $content .= '<li>' . __('User ID (if logged in) or IP address (if guest)', 'openwebui-chatbot') . '</li>';
    $content .= '<li>' . __('Contact information voluntarily provided during conversations', 'openwebui-chatbot') . '</li>';
    $content .= '</ul>';
    $content .= '<h3>' . __('Email notifications', 'openwebui-chatbot') . '</h3>';
    $content .= '<p>' . __('We may send email notifications to administrators when conversations end, including conversation summaries and any contact information collected.', 'openwebui-chatbot') . '</p>';
    
    return $content;
}

// Add to privacy policy guide
function owui_add_privacy_policy_content() {
    if (!function_exists('wp_add_privacy_policy_content')) {
        return;
    }
    
    wp_add_privacy_policy_content(
        'OpenWebUI Chatbot',
        owui_privacy_policy_content()
    );
}
add_action('admin_init', 'owui_add_privacy_policy_content');

// Data exporter
function owui_data_exporter($email_address, $page = 1) {
    $user = get_user_by('email', $email_address);
    $data_to_export = array();
    
    if ($user) {
        global $wpdb;
        $conversations = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}owui_chat_history WHERE user_id = %d",
            $user->ID
        ));
        
        if ($conversations) {
            $data_to_export[] = array(
                'group_id' => 'owui_chat_history',
                'group_label' => __('Chat History', 'openwebui-chatbot'),
                'item_id' => 'chat-history',
                'data' => array(
                    array(
                        'name' => __('Conversations', 'openwebui-chatbot'),
                        'value' => count($conversations) . ' ' . __('conversations', 'openwebui-chatbot')
                    )
                )
            );
        }
    }
    
    return array(
        'data' => $data_to_export,
        'done' => true
    );
}
add_filter('wp_privacy_personal_data_exporters', function($exporters) {
    $exporters['openwebui-chatbot'] = array(
        'exporter_friendly_name' => __('OpenWebUI Chatbot', 'openwebui-chatbot'),
        'callback' => 'owui_data_exporter'
    );
    return $exporters;
});

// Data eraser
function owui_data_eraser($email_address, $page = 1) {
    $user = get_user_by('email', $email_address);
    $items_removed = 0;
    
    if ($user) {
        global $wpdb;
        $deleted = $wpdb->delete(
            $wpdb->prefix . 'owui_chat_history',
            array('user_id' => $user->ID),
            array('%d')
        );
        
        if ($deleted) {
            $items_removed = $deleted;
        }
    }
    
    return array(
        'items_removed' => $items_removed,
        'items_retained' => 0,
        'messages' => array(),
        'done' => true
    );
}
add_filter('wp_privacy_personal_data_erasers', function($erasers) {
    $erasers['openwebui-chatbot'] = array(
        'eraser_friendly_name' => __('OpenWebUI Chatbot', 'openwebui-chatbot'),
        'callback' => 'owui_data_eraser'
    );
    return $erasers;
});

// Admin notices for missing requirements
function owui_admin_notices() {
    if (!extension_loaded('curl')) {
        echo '<div class="notice notice-error"><p>OpenWebUI Chatbot requires the cURL PHP extension to be installed.</p></div>';
    }
    
    if (!function_exists('json_encode')) {
        echo '<div class="notice notice-error"><p>OpenWebUI Chatbot requires PHP JSON extension to be installed.</p></div>';
    }
}
add_action('admin_notices', 'owui_admin_notices');