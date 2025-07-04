<?php
if (!defined('ABSPATH')) exit;
?>

<div class="wrap">
    <h1><?php echo esc_html__('Edit Chatbot', 'openwebui-chatbot'); ?></h1>
    
    <?php if (isset($_GET['error'])): ?>
        <div class="notice notice-error">
            <p><?php echo esc_html__('Error updating chatbot. Please try again.', 'openwebui-chatbot'); ?></p>
        </div>
    <?php endif; ?>
    
    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
        <?php wp_nonce_field('owui_update_chatbot'); ?>
        <input type="hidden" name="action" value="owui_update_chatbot">
        <input type="hidden" name="chatbot_id" value="<?php echo esc_attr($chatbot->id); ?>">
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="chatbot_name"><?php echo esc_html__('Chatbot Name', 'openwebui-chatbot'); ?></label>
                </th>
                <td>
                    <input type="text" id="chatbot_name" name="chatbot_name" value="<?php echo esc_attr($chatbot->name); ?>" class="regular-text" required>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="chatbot_model"><?php echo esc_html__('AI Model', 'openwebui-chatbot'); ?></label>
                </th>
                <td>
                    <div class="owui-model-select-container">
                        <select id="chatbot_model" name="chatbot_model" required>
                            <option value=""><?php echo esc_html__('Select a model...', 'openwebui-chatbot'); ?></option>
                            <?php $admin->render_model_options($chatbot->model); ?>
                        </select>
                        <button type="button" id="load-models-btn" class="button"><?php echo esc_html__('Refresh Models', 'openwebui-chatbot'); ?></button>
                    </div>
                    <p class="description"><?php echo esc_html__('Instructions for the AI model on how to behave.', 'openwebui-chatbot'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="greeting_message"><?php echo esc_html__('Greeting Message', 'openwebui-chatbot'); ?></label>
                </th>
                <td>
                    <textarea id="greeting_message" name="greeting_message" rows="3" class="large-text"><?php echo esc_textarea($chatbot->greeting_message); ?></textarea>
                    <p class="description"><?php echo esc_html__('Message shown when the chatbot first opens.', 'openwebui-chatbot'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="chatbot_avatar"><?php echo esc_html__('Avatar', 'openwebui-chatbot'); ?></label>
                </th>
                <td>
                    <?php if ($chatbot->avatar_url): ?>
                        <div class="owui-current-avatar">
                            <img src="<?php echo esc_url($chatbot->avatar_url); ?>" alt="Current avatar" style="width: 50px; height: 50px; border-radius: 50%; margin-bottom: 10px; display: block;">
                            <small>Current avatar</small>
                        </div>
                    <?php endif; ?>
                    <input type="file" id="chatbot_avatar" name="chatbot_avatar" accept="image/*">
                    <p class="description"><?php echo esc_html__('Upload an avatar image for the chatbot (optional).', 'openwebui-chatbot'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="is_active"><?php echo esc_html__('Status', 'openwebui-chatbot'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="is_active" name="is_active" value="1" <?php checked($chatbot->is_active, 1); ?>>
                        <?php echo esc_html__('Active', 'openwebui-chatbot'); ?>
                    </label>
                    <p class="description"><?php echo esc_html__('Enable or disable this chatbot.', 'openwebui-chatbot'); ?></p>
                </td>
            </tr>
        </table>
        
        <p class="submit">
            <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php echo esc_attr__('Update Chatbot', 'openwebui-chatbot'); ?>">
            <a href="<?php echo admin_url('admin.php?page=owui-chatbots'); ?>" class="button"><?php echo esc_html__('Cancel', 'openwebui-chatbot'); ?></a>
        </p>
    </form>
</div>
