<?php
/**
 * Plugin Name: OpenWebUI Chatbot
 * Description: Integrate OpenWebUI API chatbots into WordPress with Elementor support and email notifications
 * Version: 1.2.1
 * Author: SRS Designs LLC
 * Text Domain: openwebui-chatbot
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Plugin constants
define('OWUI_VERSION', '1.2.1');
define('OWUI_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('OWUI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OWUI_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Debug mode (set to true for verbose logging)
define('OWUI_DEBUG', defined('WP_DEBUG') && WP_DEBUG);

// File upload limits
define('OWUI_MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('OWUI_ALLOWED_FILE_TYPES', ['pdf', 'txt', 'doc', 'docx', 'csv', 'json', 'md']);

/**
 * Plugin activation check and setup
 */
function owui_activation_check() {
    // Check PHP version
    if (version_compare(PHP_VERSION, '7.2', '<')) {
        deactivate_plugins(OWUI_PLUGIN_BASENAME);
        wp_die(
            esc_html__('This plugin requires PHP 7.2 or higher.', 'openwebui-chatbot'),
            esc_html__('Plugin Activation Error', 'openwebui-chatbot'),
            ['back_link' => true]
        );
    }
    
    // Check WordPress version
    if (version_compare(get_bloginfo('version'), '5.0', '<')) {
        deactivate_plugins(OWUI_PLUGIN_BASENAME);
        wp_die(
            esc_html__('This plugin requires WordPress 5.0 or higher.', 'openwebui-chatbot'),
            esc_html__('Plugin Activation Error', 'openwebui-chatbot'),
            ['back_link' => true]
        );
    }
    
    // Check required PHP extensions
    $required_extensions = ['curl', 'json'];
    $missing_extensions = [];
    
    foreach ($required_extensions as $extension) {
        if (!extension_loaded($extension)) {
            $missing_extensions[] = $extension;
        }
    }
    
    if (!empty($missing_extensions)) {
        deactivate_plugins(OWUI_PLUGIN_BASENAME);
        wp_die(
            sprintf(
                esc_html__('This plugin requires the following PHP extensions: %s', 'openwebui-chatbot'),
                implode(', ', $missing_extensions)
            ),
            esc_html__('Plugin Activation Error', 'openwebui-chatbot'),
            ['back_link' => true]
        );
    }
    
    try {
        // Create database tables
        owui_create_tables();
        owui_create_default_chatbot();
        
        // Set up cron jobs
        owui_setup_cron_jobs();
        
        // Set activation flag
        update_option('owui_plugin_activated', true);
        
    } catch (Exception $e) {
        owui_log_error('Activation Error', $e->getMessage());
        deactivate_plugins(OWUI_PLUGIN_BASENAME);
        wp_die(
            esc_html__('Plugin activation failed. Please check your database permissions.', 'openwebui-chatbot'),
            esc_html__('Plugin Activation Error', 'openwebui-chatbot'),
            ['back_link' => true]
        );
    }
}

/**
 * Create database tables with proper error handling
 */
function owui_create_tables() {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    
    // Chatbots table
    $tables = [];
    
    $tables[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}owui_chatbots (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        model varchar(100) NOT NULL,
        system_prompt text,
        greeting_message text,
        avatar_url varchar(500),
        is_active tinyint(1) unsigned DEFAULT 1,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_is_active (is_active),
        KEY idx_created_at (created_at)
    ) $charset_collate;";

    // Chat sessions table
    $tables[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}owui_chat_sessions (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        chatbot_id bigint(20) unsigned NOT NULL,
        user_id bigint(20) unsigned,
        session_id varchar(100) NOT NULL,
        started_at datetime DEFAULT CURRENT_TIMESTAMP,
        ended_at datetime,
        ip_address varchar(45),
        user_agent text,
        PRIMARY KEY (id),
        KEY idx_chatbot_id (chatbot_id),
        KEY idx_user_id (user_id),
        KEY idx_session_id (session_id),
        KEY idx_ended_at (ended_at),
        KEY idx_started_at (started_at),
        FOREIGN KEY (chatbot_id) REFERENCES {$wpdb->prefix}owui_chatbots(id) ON DELETE CASCADE
    ) $charset_collate;";

    // Chat history table
    $tables[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}owui_chat_history (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        chatbot_id bigint(20) unsigned NOT NULL,
        session_id bigint(20) unsigned,
        user_id bigint(20) unsigned,
        message text NOT NULL,
        response text NOT NULL,
        tokens_used int unsigned DEFAULT 0,
        response_time float DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_chatbot_id (chatbot_id),
        KEY idx_session_id (session_id),
        KEY idx_user_id (user_id),
        KEY idx_created_at (created_at),
        FOREIGN KEY (chatbot_id) REFERENCES {$wpdb->prefix}owui_chatbots(id) ON DELETE CASCADE,
        FOREIGN KEY (session_id) REFERENCES {$wpdb->prefix}owui_chat_sessions(id) ON DELETE CASCADE
    ) $charset_collate;";

    // Contact information table
    $tables[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}owui_contact_info (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        session_id bigint(20) unsigned NOT NULL,
        message_id bigint(20) unsigned,
        contact_type varchar(20) NOT NULL,
        contact_value varchar(255) NOT NULL,
        extracted_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_session_id (session_id),
        KEY idx_contact_type (contact_type),
        KEY idx_extracted_at (extracted_at),
        UNIQUE KEY unique_contact (session_id, contact_type, contact_value),
        FOREIGN KEY (session_id) REFERENCES {$wpdb->prefix}owui_chat_sessions(id) ON DELETE CASCADE,
        FOREIGN KEY (message_id) REFERENCES {$wpdb->prefix}owui_chat_history(id) ON DELETE SET NULL
    ) $charset_collate;";

    // Rate limiting table
    $tables[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}owui_rate_limits (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        identifier varchar(100) NOT NULL,
        request_count int unsigned DEFAULT 1,
        window_start datetime DEFAULT CURRENT_TIMESTAMP,
        window_type enum('minute', 'hour', 'day') DEFAULT 'minute',
        metadata text,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_limit (identifier, window_type, window_start),
        KEY idx_window_start (window_start),
        KEY idx_identifier (identifier)
    ) $charset_collate;";

    // Security log table
    $tables[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}owui_security_log (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        event_type varchar(50) NOT NULL,
        event_data text,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_event_type (event_type),
        KEY idx_created_at (created_at)
    ) $charset_collate;";

    // Rate limit whitelist table
    $tables[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}owui_rate_limit_whitelist (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        identifier varchar(100) NOT NULL,
        reason varchar(255),
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_identifier (identifier),
        KEY idx_created_at (created_at)
    ) $charset_collate;";

    // Rate limit bans table
    $tables[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}owui_rate_limit_bans (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        identifier varchar(100) NOT NULL,
        expires_at datetime NOT NULL,
        reason varchar(255),
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_identifier (identifier),
        KEY idx_expires_at (expires_at),
        KEY idx_created_at (created_at)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
    foreach ($tables as $table_sql) {
        $result = dbDelta($table_sql);
        if (empty($result)) {
            throw new Exception("Failed to create database table");
        }
    }
    
    // Update database version
    update_option('owui_db_version', OWUI_VERSION);
}

/**
 * Create default chatbot safely
 */
function owui_create_default_chatbot() {
    global $wpdb;
    
    // Check if any chatbot exists
    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}owui_chatbots");
    
    if ($count === null) {
        throw new Exception("Database query failed");
    }
    
    if ((int) $count === 0) {
        $result = $wpdb->insert(
            $wpdb->prefix . 'owui_chatbots',
            [
                'name' => __('Assistant', 'openwebui-chatbot'),
                'model' => 'gpt-3.5-turbo',
                'system_prompt' => __('You are a helpful assistant.', 'openwebui-chatbot'),
                'greeting_message' => __('Hello! How can I help you today?', 'openwebui-chatbot'),
                'is_active' => 1,
                'created_at' => current_time('mysql', true)
            ],
            ['%s', '%s', '%s', '%s', '%d', '%s']
        );
        
        if ($result === false) {
            throw new Exception("Failed to create default chatbot: " . $wpdb->last_error);
        }
    }
}

/**
 * Setup cron jobs
 */
function owui_setup_cron_jobs() {
    // Session cleanup
    if (!wp_next_scheduled('owui_cleanup_sessions')) {
        wp_schedule_event(time(), 'hourly', 'owui_cleanup_sessions');
    }
    
    // Rate limit cleanup
    if (!wp_next_scheduled('owui_cleanup_rate_limits')) {
        wp_schedule_event(time(), 'hourly', 'owui_cleanup_rate_limits');
    }
    
    // Check inactive sessions
    if (!wp_next_scheduled('owui_check_inactive_sessions')) {
        wp_schedule_event(time(), 'every_five_minutes', 'owui_check_inactive_sessions');
    }
}

/**
 * Plugin deactivation cleanup
 */
function owui_deactivation_cleanup() {
    // Clear scheduled hooks
    wp_clear_scheduled_hook('owui_cleanup_sessions');
    wp_clear_scheduled_hook('owui_cleanup_rate_limits');
    wp_clear_scheduled_hook('owui_check_inactive_sessions');
    wp_clear_scheduled_hook('owui_delayed_session_end');
    
    // Clear transients
    delete_transient('owui_models_cache');
    
    // Remove activation flag
    delete_option('owui_plugin_activated');
}

/**
 * Check if plugin needs database updates
 */
function owui_check_database_version() {
    $current_version = get_option('owui_db_version', '0');
    
    if (version_compare($current_version, OWUI_VERSION, '<')) {
        try {
            owui_create_tables();
            owui_log_info('Database updated to version: ' . OWUI_VERSION);
        } catch (Exception $e) {
            owui_log_error('Database Update Error', $e->getMessage());
        }
    }
}

/**
 * Load text domain for translations
 */
function owui_load_textdomain() {
    load_plugin_textdomain(
        'openwebui-chatbot',
        false,
        dirname(OWUI_PLUGIN_BASENAME) . '/languages'
    );
}

/**
 * Logging functions
 */
function owui_log_error($context, $message, $data = []) {
    if (OWUI_DEBUG || (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG)) {
        $log_message = sprintf(
            '[OpenWebUI] %s: %s',
            $context,
            $message
        );
        
        if (!empty($data)) {
            $log_message .= ' | Data: ' . wp_json_encode($data);
        }
        
        error_log($log_message);
    }
}

function owui_log_info($message, $data = []) {
    if (OWUI_DEBUG) {
        owui_log_error('INFO', $message, $data);
    }
}

/**
 * Load required files
 */
function owui_load_files() {
    $required_files = [
        'includes/helpers.php',
        'includes/class-owui-security.php',
        'includes/class-owui-database.php',
        'includes/class-owui-api.php',
        'includes/class-owui-rate-limiter.php',
        'includes/class-owui-session-manager.php',
        'includes/class-owui-contact-extractor.php',
        'includes/class-owui-email-notifications.php',
        'includes/class-owui-admin.php',
        'includes/class-owui-core.php'
    ];
    
    foreach ($required_files as $file) {
        $file_path = OWUI_PLUGIN_PATH . $file;
        if (file_exists($file_path)) {
            require_once $file_path;
        } else {
            owui_log_error('Missing File', "Required file not found: {$file}");
        }
    }
}

/**
 * Initialize plugin
 */
function owui_init() {
    // Load files first
    owui_load_files();
    
    // Check if core class exists
    if (!class_exists('OWUI_Core')) {
        add_action('admin_notices', function() {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html__('OpenWebUI Chatbot: Core class not found. Please check file permissions.', 'openwebui-chatbot')
            );
        });
        return;
    }
    
    try {
        // Initialize core functionality
        $core = new OWUI_Core();
        $core->init();
        
        // Initialize email notifications
        if (class_exists('OWUI_Email_Notifications')) {
            new OWUI_Email_Notifications();
        }
        
        owui_log_info('Plugin initialized successfully');
        
    } catch (Exception $e) {
        owui_log_error('Initialization Error', $e->getMessage());
        
        add_action('admin_notices', function() use ($e) {
            printf(
                '<div class="notice notice-error"><p>%s: %s</p></div>',
                esc_html__('OpenWebUI Chatbot initialization failed', 'openwebui-chatbot'),
                esc_html($e->getMessage())
            );
        });
    }
}

/**
 * Register settings with proper sanitization
 */
function owui_register_settings() {
    // API Settings
    register_setting('owui_settings', 'owui_api_key', [
        'type' => 'string',
        'sanitize_callback' => 'owui_sanitize_api_key',
        'default' => ''
    ]);
    
    register_setting('owui_settings', 'owui_jwt_token', [
        'type' => 'string',
        'sanitize_callback' => 'owui_sanitize_jwt_token',
        'default' => ''
    ]);
    
    register_setting('owui_settings', 'owui_base_url', [
        'type' => 'string',
        'sanitize_callback' => 'owui_sanitize_base_url',
        'default' => ''
    ]);
    
    // Email Notification Settings
    register_setting('owui_settings', 'owui_email_notifications', [
        'type' => 'boolean',
        'sanitize_callback' => 'rest_sanitize_boolean',
        'default' => false
    ]);
    
    register_setting('owui_settings', 'owui_notification_email', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_email',
        'default' => get_option('admin_email')
    ]);
    
    register_setting('owui_settings', 'owui_session_timeout', [
        'type' => 'integer',
        'sanitize_callback' => 'owui_sanitize_session_timeout',
        'default' => 15
    ]);
    
    register_setting('owui_settings', 'owui_email_on_contact_info', [
        'type' => 'boolean',
        'sanitize_callback' => 'rest_sanitize_boolean',
        'default' => true
    ]);
    
    register_setting('owui_settings', 'owui_email_on_long_conversations', [
        'type' => 'boolean',
        'sanitize_callback' => 'rest_sanitize_boolean',
        'default' => false
    ]);
    
    register_setting('owui_settings', 'owui_email_on_keywords', [
        'type' => 'boolean',
        'sanitize_callback' => 'rest_sanitize_boolean',
        'default' => false
    ]);
    
    register_setting('owui_settings', 'owui_notification_keywords', [
        'type' => 'string',
        'sanitize_callback' => 'owui_sanitize_keywords',
        'default' => ''
    ]);
    
    register_setting('owui_settings', 'owui_email_subject', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => __('New Chatbot Conversation Summary', 'openwebui-chatbot')
    ]);
    
    register_setting('owui_settings', 'owui_email_header', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_textarea_field',
        'default' => __('A new conversation has ended on your website. Here\'s a summary:', 'openwebui-chatbot')
    ]);
    
    // Rate limiting settings
    register_setting('owui_settings', 'owui_rate_limit_per_minute', [
        'type' => 'integer',
        'sanitize_callback' => 'owui_sanitize_rate_limit',
        'default' => 10
    ]);
    
    register_setting('owui_settings', 'owui_rate_limit_per_hour', [
        'type' => 'integer',
        'sanitize_callback' => 'owui_sanitize_rate_limit',
        'default' => 100
    ]);
}

/**
 * Sanitization callbacks
 */
function owui_sanitize_api_key($value) {
    $sanitized = sanitize_text_field($value);
    if (!empty($sanitized) && !preg_match('/^[a-zA-Z0-9\-_\.]+$/', $sanitized)) {
        add_settings_error('owui_api_key', 'invalid_api_key', 
            __('API key contains invalid characters', 'openwebui-chatbot'));
        return get_option('owui_api_key', '');
    }
    return $sanitized;
}

function owui_sanitize_jwt_token($value) {
    $sanitized = sanitize_text_field($value);
    if (!empty($sanitized) && !preg_match('/^[a-zA-Z0-9\-_\.]+$/', $sanitized)) {
        add_settings_error('owui_jwt_token', 'invalid_jwt_token', 
            __('JWT token contains invalid characters', 'openwebui-chatbot'));
        return get_option('owui_jwt_token', '');
    }
    return $sanitized;
}

function owui_sanitize_base_url($value) {
    $sanitized = esc_url_raw($value);
    if (!empty($value) && empty($sanitized)) {
        add_settings_error('owui_base_url', 'invalid_url', 
            __('Please enter a valid URL', 'openwebui-chatbot'));
        return get_option('owui_base_url', '');
    }
    return rtrim($sanitized, '/');
}

function owui_sanitize_session_timeout($value) {
    $int_value = absint($value);
    if ($int_value < 1 || $int_value > 1440) { // 1 minute to 24 hours
        add_settings_error('owui_session_timeout', 'invalid_timeout', 
            __('Session timeout must be between 1 and 1440 minutes', 'openwebui-chatbot'));
        return get_option('owui_session_timeout', 15);
    }
    return $int_value;
}

function owui_sanitize_keywords($value) {
    $sanitized = sanitize_textarea_field($value);
    $keywords = array_map('trim', explode(',', $sanitized));
    $keywords = array_filter($keywords, function($keyword) {
        return !empty($keyword) && strlen($keyword) >= 2;
    });
    return implode(', ', $keywords);
}

function owui_sanitize_rate_limit($value) {
    $int_value = absint($value);
    if ($int_value < 1 || $int_value > 1000) {
        return $int_value < 1 ? 1 : 1000;
    }
    return $int_value;
}

/**
 * Add settings link to plugins page
 */
function owui_add_settings_link($links) {
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        esc_url(admin_url('admin.php?page=owui-settings')),
        esc_html__('Settings', 'openwebui-chatbot')
    );
    array_unshift($links, $settings_link);
    return $links;
}

/**
 * Add custom cron schedules
 */
function owui_add_cron_schedules($schedules) {
    $schedules['every_five_minutes'] = [
        'interval' => 300, // 5 minutes in seconds
        'display' => __('Every 5 Minutes', 'openwebui-chatbot')
    ];
    return $schedules;
}

/**
 * Admin notices for missing requirements
 */
function owui_admin_notices() {
    // Only show on plugin pages
    $screen = get_current_screen();
    if (!$screen || strpos($screen->base, 'owui') === false) {
        return;
    }
    
    $notices = [];
    
    if (!extension_loaded('curl')) {
        $notices[] = __('OpenWebUI Chatbot requires the cURL PHP extension to be installed.', 'openwebui-chatbot');
    }
    
    if (!function_exists('json_encode')) {
        $notices[] = __('OpenWebUI Chatbot requires PHP JSON extension to be installed.', 'openwebui-chatbot');
    }
    
    if (empty(get_option('owui_base_url')) || empty(get_option('owui_api_key'))) {
        $notices[] = sprintf(
            __('Please configure your OpenWebUI connection in the <a href="%s">settings</a>.', 'openwebui-chatbot'),
            esc_url(admin_url('admin.php?page=owui-settings'))
        );
    }
    
    foreach ($notices as $notice) {
        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            wp_kses($notice, ['a' => ['href' => []]])
        );
    }
}

// Hook everything up
register_activation_hook(__FILE__, 'owui_activation_check');
register_deactivation_hook(__FILE__, 'owui_deactivation_cleanup');

add_action('plugins_loaded', 'owui_load_textdomain');
add_action('plugins_loaded', 'owui_check_database_version');
add_action('plugins_loaded', 'owui_init', 20);
add_action('admin_init', 'owui_register_settings');
add_action('admin_notices', 'owui_admin_notices');

add_filter('plugin_action_links_' . OWUI_PLUGIN_BASENAME, 'owui_add_settings_link');
add_filter('cron_schedules', 'owui_add_cron_schedules');

// Cleanup hooks
add_action('owui_cleanup_sessions', 'owui_cleanup_old_sessions');
add_action('owui_cleanup_rate_limits', function() {
    if (class_exists('OWUI_Rate_Limiter')) {
        OWUI_Rate_Limiter::cleanup_old_records();
    }
});

// Handle delayed session ending
add_action('owui_delayed_session_end', function($session_id) {
    if (class_exists('OWUI_Email_Notifications')) {
        $email_system = new OWUI_Email_Notifications();
        $email_system->handle_delayed_session_end($session_id);
    }
});

// Privacy compliance
function owui_privacy_policy_content() {
    $content = '<h2>' . esc_html__('OpenWebUI Chatbot', 'openwebui-chatbot') . '</h2>';
    $content .= '<p>' . esc_html__('This plugin collects chat messages and responses to provide AI-powered chat functionality.', 'openwebui-chatbot') . '</p>';
    $content .= '<h3>' . esc_html__('What data we collect', 'openwebui-chatbot') . '</h3>';
    $content .= '<ul>';
    $content .= '<li>' . esc_html__('Chat messages sent by users', 'openwebui-chatbot') . '</li>';
    $content .= '<li>' . esc_html__('AI responses to those messages', 'openwebui-chatbot') . '</li>';
    $content .= '<li>' . esc_html__('Timestamp of conversations', 'openwebui-chatbot') . '</li>';
    $content .= '<li>' . esc_html__('User ID (if logged in) or IP address (if guest)', 'openwebui-chatbot') . '</li>';
    $content .= '<li>' . esc_html__('Contact information voluntarily provided during conversations', 'openwebui-chatbot') . '</li>';
    $content .= '</ul>';
    $content .= '<h3>' . esc_html__('Email notifications', 'openwebui-chatbot') . '</h3>';
    $content .= '<p>' . esc_html__('We may send email notifications to administrators when conversations end, including conversation summaries and any contact information collected.', 'openwebui-chatbot') . '</p>';
    
    return $content;
}

add_action('admin_init', function() {
    if (function_exists('wp_add_privacy_policy_content')) {
        wp_add_privacy_policy_content('OpenWebUI Chatbot', owui_privacy_policy_content());
    }
});

// Data export handler
add_filter('wp_privacy_personal_data_exporters', function($exporters) {
    $exporters['openwebui-chatbot'] = [
        'exporter_friendly_name' => __('OpenWebUI Chatbot', 'openwebui-chatbot'),
        'callback' => 'owui_data_exporter'
    ];
    return $exporters;
});

function owui_data_exporter($email_address, $page = 1) {
    $user = get_user_by('email', $email_address);
    $data_to_export = [];
    
    if ($user) {
        global $wpdb;
        $conversations = $wpdb->get_results($wpdb->prepare(
            "SELECT message, response, created_at FROM {$wpdb->prefix}owui_chat_history WHERE user_id = %d ORDER BY created_at DESC",
            $user->ID
        ));
        
        if ($conversations) {
            $conversation_data = [];
            foreach ($conversations as $conv) {
                $conversation_data[] = [
                    'name' => __('Message', 'openwebui-chatbot'),
                    'value' => $conv->message
                ];
                $conversation_data[] = [
                    'name' => __('Response', 'openwebui-chatbot'),
                    'value' => $conv->response
                ];
                $conversation_data[] = [
                    'name' => __('Date', 'openwebui-chatbot'),
                    'value' => $conv->created_at
                ];
            }
            
            $data_to_export[] = [
                'group_id' => 'owui_chat_history',
                'group_label' => __('Chat History', 'openwebui-chatbot'),
                'item_id' => 'chat-history',
                'data' => $conversation_data
            ];
        }
    }
    
    return [
        'data' => $data_to_export,
        'done' => true
    ];
}

// Data eraser
add_filter('wp_privacy_personal_data_erasers', function($erasers) {
    $erasers['openwebui-chatbot'] = [
        'eraser_friendly_name' => __('OpenWebUI Chatbot', 'openwebui-chatbot'),
        'callback' => 'owui_data_eraser'
    ];
    return $erasers;
});

function owui_data_eraser($email_address, $page = 1) {
    $user = get_user_by('email', $email_address);
    $items_removed = 0;
    
    if ($user) {
        global $wpdb;
        
        // Delete chat history
        $deleted = $wpdb->delete(
            $wpdb->prefix . 'owui_chat_history',
            ['user_id' => $user->ID],
            ['%d']
        );
        
        if ($deleted !== false) {
            $items_removed += $deleted;
        }
        
        // Delete sessions
        $deleted = $wpdb->delete(
            $wpdb->prefix . 'owui_chat_sessions',
            ['user_id' => $user->ID],
            ['%d']
        );
        
        if ($deleted !== false) {
            $items_removed += $deleted;
        }
    }
    
    return [
        'items_removed' => $items_removed,
        'items_retained' => 0,
        'messages' => [],
        'done' => true
    ];
}

// Health check endpoint for AJAX
add_action('wp_ajax_owui_health_check', 'owui_health_check');
add_action('wp_ajax_nopriv_owui_health_check', 'owui_health_check');

function owui_health_check() {
    global $wpdb;
    $mysql_version = $wpdb->db_version();
    $php_version = PHP_VERSION;
    $plugin_version = OWUI_VERSION;
    $db_ok = $wpdb->check_connection();
    wp_send_json_success([
        'status' => 'ok',
        'plugin_version' => $plugin_version,
        'php_version' => $php_version,
        'mysql_version' => $mysql_version,
        'db_connection' => $db_ok ? 'ok' : 'error',
        'time' => current_time('mysql', 1)
    ]);
}
