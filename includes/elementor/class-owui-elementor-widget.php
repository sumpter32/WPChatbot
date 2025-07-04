<?php
/**
 * Elementor Widget for OpenWebUI Chatbot
 */

if (!defined('ABSPATH')) exit;

// Check if Elementor classes exist before extending
if (!class_exists('Elementor\Widget_Base') || !class_exists('Elementor\Controls_Manager')) {
    return;
}

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

class OWUI_Elementor_Widget extends Widget_Base {
    
    public function get_name() {
        return 'owui_chatbot';
    }

    public function get_title() {
        return esc_html__('OpenWebUI Chatbot', 'openwebui-chatbot');
    }

    public function get_icon() {
        return 'eicon-comments';
    }

    public function get_categories() {
        return ['owui-chatbot'];
    }
    
    public function get_keywords() {
        return ['chat', 'chatbot', 'ai', 'openwebui', 'conversation'];
    }

    protected function register_controls() {
        
        // Content Section
        $this->start_controls_section(
            'content_section',
            [
                'label' => esc_html__('Chatbot Settings', 'openwebui-chatbot'),
                'tab' => Controls_Manager::TAB_CONTENT,
            ]
        );

        // Get chatbots for dropdown
        global $wpdb;
        $chatbots = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}owui_chatbots WHERE is_active = 1 ORDER BY name ASC");
        $options = ['' => esc_html__('Select a chatbot...', 'openwebui-chatbot')];
        
        if (!empty($chatbots)) {
            foreach ($chatbots as $chatbot) {
                $options[$chatbot->id] = $chatbot->name;
            }
        } else {
            $options[''] = esc_html__('No active chatbots found', 'openwebui-chatbot');
        }

        $this->add_control(
            'chatbot_id',
            [
                'label' => esc_html__('Select Chatbot', 'openwebui-chatbot'),
                'type' => Controls_Manager::SELECT,
                'options' => $options,
                'default' => '',
                'description' => esc_html__('Choose which chatbot to display', 'openwebui-chatbot'),
            ]
        );
        
        $this->add_control(
            'display_type',
            [
                'label' => esc_html__('Display Type', 'openwebui-chatbot'),
                'type' => Controls_Manager::SELECT,
                'options' => [
                    'inline' => esc_html__('Inline Chat', 'openwebui-chatbot'),
                    'button' => esc_html__('Popup Button', 'openwebui-chatbot'),
                    'floating' => esc_html__('Floating Button', 'openwebui-chatbot'),
                ],
                'default' => 'inline',
            ]
        );
        
        $this->add_control(
            'button_text',
            [
                'label' => esc_html__('Button Text', 'openwebui-chatbot'),
                'type' => Controls_Manager::TEXT,
                'default' => esc_html__('Chat with us', 'openwebui-chatbot'),
                'condition' => [
                    'display_type' => ['button', 'floating'],
                ],
            ]
        );

        $this->end_controls_section();
        
        // Style Section
        $this->start_controls_section(
            'style_section',
            [
                'label' => esc_html__('Style', 'openwebui-chatbot'),
                'tab' => Controls_Manager::TAB_STYLE,
            ]
        );
        
        $this->add_responsive_control(
            'chat_width',
            [
                'label' => esc_html__('Width', 'openwebui-chatbot'),
                'type' => Controls_Manager::SLIDER,
                'size_units' => ['px', '%'],
                'range' => [
                    'px' => [
                        'min' => 300,
                        'max' => 800,
                        'step' => 10,
                    ],
                    '%' => [
                        'min' => 50,
                        'max' => 100,
                        'step' => 5,
                    ],
                ],
                'default' => [
                    'unit' => 'px',
                    'size' => 400,
                ],
                'condition' => [
                    'display_type' => 'inline',
                ],
                'selectors' => [
                    '{{WRAPPER}} .owui-inline-chat' => 'width: {{SIZE}}{{UNIT}};',
                ],
            ]
        );
        
        $this->add_responsive_control(
            'chat_height',
            [
                'label' => esc_html__('Height', 'openwebui-chatbot'),
                'type' => Controls_Manager::SLIDER,
                'size_units' => ['px'],
                'range' => [
                    'px' => [
                        'min' => 300,
                        'max' => 800,
                        'step' => 10,
                    ],
                ],
                'default' => [
                    'unit' => 'px',
                    'size' => 500,
                ],
                'condition' => [
                    'display_type' => 'inline',
                ],
                'selectors' => [
                    '{{WRAPPER}} .owui-inline-chat' => 'height: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        $chatbot_id = $settings['chatbot_id'];
        
        if (empty($chatbot_id)) {
            if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
                echo '<div style="padding: 20px; background: #f5f5f5; border: 1px dashed #ccc; text-align: center;">';
                echo esc_html__('Please select a chatbot in the widget settings.', 'openwebui-chatbot');
                echo '</div>';
            }
            return;
        }

        global $wpdb;
        $chatbot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}owui_chatbots WHERE id = %d AND is_active = 1",
            $chatbot_id
        ));
        
        if (!$chatbot) {
            if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
                echo '<div style="padding: 20px; background: #ffe6e6; border: 1px solid #ff9999; text-align: center;">';
                echo esc_html__('Selected chatbot not found or inactive.', 'openwebui-chatbot');
                echo '</div>';
            }
            return;
        }
        
        // Enqueue frontend assets
        wp_enqueue_style('owui-elementor-frontend');
        wp_enqueue_script('owui-elementor-frontend');
        
        $display_type = $settings['display_type'];
        $button_text = $settings['button_text'] ?: esc_html__('Chat with us', 'openwebui-chatbot');
        
        echo '<div class="owui-elementor-widget" data-chatbot-id="' . esc_attr($chatbot->id) . '">';
        
        if ($display_type === 'inline') {
            $this->render_inline_chat($chatbot);
        } elseif ($display_type === 'button') {
            $this->render_popup_button($chatbot, $button_text);
        } elseif ($display_type === 'floating') {
            $this->render_floating_button($chatbot, $button_text);
        }
        
        echo '</div>';
    }
    
    private function render_inline_chat($chatbot) {
        ?>
        <div class="owui-inline-chat" data-chatbot-id="<?php echo esc_attr($chatbot->id); ?>">
            <div class="owui-elementor-header">
                <div class="owui-header-info">
                    <?php if ($chatbot->avatar_url): ?>
                        <img src="<?php echo esc_url($chatbot->avatar_url); ?>" alt="<?php echo esc_attr($chatbot->name); ?>" class="owui-avatar">
                    <?php endif; ?>
                    <h4><?php echo esc_html($chatbot->name); ?></h4>
                </div>
            </div>
            <div class="owui-elementor-messages">
                <?php if ($chatbot->greeting_message): ?>
                    <div class="owui-message bot owui-greeting">
                        <?php echo wp_kses_post($this->format_message($chatbot->greeting_message)); ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="owui-elementor-input-container">
                <input type="text" class="owui-elementor-input" placeholder="<?php echo esc_attr__('Type your message...', 'openwebui-chatbot'); ?>">
                <button class="owui-elementor-send"><?php echo esc_html__('Send', 'openwebui-chatbot'); ?></button>
            </div>
        </div>
        <?php
    }
    
    private function render_popup_button($chatbot, $button_text) {
        ?>
        <button class="owui-popup-button" data-chatbot-id="<?php echo esc_attr($chatbot->id); ?>">
            <?php echo esc_html($button_text); ?>
        </button>
        <?php
    }
    
    private function render_floating_button($chatbot, $button_text) {
        ?>
        <div class="owui-floating-widget">
            <div class="owui-floating-toggle" data-chatbot-id="<?php echo esc_attr($chatbot->id); ?>">
                <?php if ($chatbot->avatar_url): ?>
                    <img src="<?php echo esc_url($chatbot->avatar_url); ?>" alt="<?php echo esc_attr($chatbot->name); ?>">
                <?php else: ?>
                    <span>💬</span>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    private function format_message($text) {
        // Basic markdown to HTML conversion
        $text = wp_kses_post($text);
        $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $text);
        $text = nl2br($text);
        return $text;
    }
}