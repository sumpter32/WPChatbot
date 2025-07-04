<?php
/**
 * Elementor Integration for OpenWebUI Chatbot
 */

if (!defined('ABSPATH')) exit;

class OWUI_Elementor_Integration {
    
    public function __construct() {
        // Hook into Elementor
        add_action('elementor/widgets/register', array($this, 'register_widgets'));
        add_action('elementor/elements/categories_registered', array($this, 'add_widget_categories'));
        add_action('elementor/editor/after_enqueue_styles', array($this, 'enqueue_editor_styles'));
    }
    
    public function register_widgets($widgets_manager) {
        // Make sure our widget file exists and can be loaded
        $widget_file = OWUI_PLUGIN_PATH . 'includes/elementor/class-owui-elementor-widget.php';
        
        if (file_exists($widget_file)) {
            require_once $widget_file;
            
            // Check if our widget class exists before registering
            if (class_exists('OWUI_Elementor_Widget')) {
                $widgets_manager->register(new OWUI_Elementor_Widget());
            }
        }
    }
    
    public function add_widget_categories($elements_manager) {
        $elements_manager->add_category(
            'owui-chatbot',
            [
                'title' => esc_html__('OpenWebUI Chatbot', 'openwebui-chatbot'),
                'icon' => 'fa fa-comments',
            ]
        );
    }
    
    public function enqueue_editor_styles() {
        wp_enqueue_style(
            'owui-elementor-editor',
            OWUI_PLUGIN_URL . 'assets/css/elementor-editor.css',
            [],
            OWUI_VERSION
        );
    }
}

// Only initialize if Elementor is loaded and classes are available
if (did_action('elementor/loaded') && class_exists('Elementor\Widget_Base')) {
    new OWUI_Elementor_Integration();
}