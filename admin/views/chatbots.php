<?php if (!defined('ABSPATH')) exit; ?>

<div class="wrap">
    <h1>Chatbots</h1>
    
    <?php
    // Display success/error messages
    if (isset($_GET['created']) && $_GET['created'] == '1'): ?>
        <div class="notice notice-success is-dismissible">
            <p>Chatbot created successfully!</p>
        </div>
    <?php endif;
    
    if (isset($_GET['updated']) && $_GET['updated'] == '1'): ?>
        <div class="notice notice-success is-dismissible">
            <p>Chatbot updated successfully!</p>
        </div>
    <?php endif;
    
    if (isset($_GET['deleted']) && $_GET['deleted'] == '1'): ?>
        <div class="notice notice-success is-dismissible">
            <p>Chatbot deleted successfully!</p>
        </div>
    <?php endif;
    
    if (isset($_GET['error'])): 
        $error_message = 'An error occurred.';
        switch ($_GET['error']) {
            case '1':
                $error_message = 'Error updating chatbot.';
                break;
            case '2':
                $error_message = 'Please fill in all required fields.';
                break;
            case '3':
                $error_message = 'Database error occurred.';
                break;
        }
    ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html($error_message); ?></p>
        </div>
    <?php endif; ?>
    
    <div class="tablenav top">
        <div class="alignleft actions">
            <a href="#" class="button button-primary" id="add-new-chatbot">Add New Chatbot</a>
        </div>
    </div>
    
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th width="25%">Name</th>
                <th width="20%">Model</th>
                <th width="15%">Status</th>
                <th width="20%">Created</th>
                <th width="20%">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php $admin->render_chatbot_list(); ?>
        </tbody>
    </table>
    
    <!-- Add New Chatbot Modal -->
    <div id="owui-add-chatbot-modal" class="owui-modal" style="display: none;">
        <div class="owui-modal-content">
            <span class="owui-close">&times;</span>
            <h2>Create New Chatbot</h2>
            
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data" id="create-chatbot-form">
                <?php wp_nonce_field('owui_create_chatbot'); ?>
                <input type="hidden" name="action" value="owui_create_chatbot">
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="chatbot_name">Chatbot Name <span class="required">*</span></label>
                        </th>
                        <td>
                            <input type="text" id="chatbot_name" name="chatbot_name" class="regular-text" required>
                            <p class="description">Enter a name for your chatbot (e.g., "Customer Support Bot")</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="chatbot_model">AI Model <span class="required">*</span></label>
                        </th>
                        <td>
                            <div class="owui-model-select-container">
                                <select id="chatbot_model" name="chatbot_model" required>
                                    <option value="">Select a model...</option>
                                    <?php $admin->render_model_options(); ?>
                                </select>
                                <button type="button" id="load-models-btn" class="button">Refresh Models</button>
                            </div>
                            <p class="description">Choose the AI model to power your chatbot</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="system_prompt">System Prompt</label>
                        </th>
                        <td>
                            <textarea id="system_prompt" name="system_prompt" rows="4" class="large-text" placeholder="You are a helpful assistant that..."></textarea>
                            <p class="description">Instructions for the AI model on how to behave and respond</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="greeting_message">Greeting Message</label>
                        </th>
                        <td>
                            <textarea id="greeting_message" name="greeting_message" rows="3" class="large-text" placeholder="Hello! How can I help you today?"></textarea>
                            <p class="description">Message shown when the chatbot first opens</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="chatbot_avatar">Avatar</label>
                        </th>
                        <td>
                            <input type="file" id="chatbot_avatar" name="chatbot_avatar" accept="image/*">
                            <p class="description">Upload an avatar image for the chatbot (optional)</p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="Create Chatbot">
                    <button type="button" class="button" id="cancel-create">Cancel</button>
                </p>
            </form>
        </div>
    </div>
    
    <div class="owui-usage-instructions">
        <h2>Usage Instructions</h2>
        <div class="owui-instructions-grid">
            <div class="owui-instruction-box">
                <h3>🔧 Setup</h3>
                <ol>
                    <li>Configure your API connection in Settings</li>
                    <li>Create your first chatbot above</li>
                    <li>Test the connection</li>
                </ol>
            </div>
            
            <div class="owui-instruction-box">
                <h3>📝 Shortcodes</h3>
                <p>Use these shortcodes to display your chatbots:</p>
                <ul>
                    <li><code>[openwebui_chatbot]</code> - Default floating button</li>
                    <li><code>[openwebui_chatbot type="inline"]</code> - Inline chat</li>
                    <li><code>[openwebui_chatbot type="button"]</code> - Popup button</li>
                    <li><code>[openwebui_chatbot id="1"]</code> - Specific chatbot</li>
                </ul>
            </div>
            
            <div class="owui-instruction-box">
                <h3>🎨 Elementor</h3>
                <p>If you're using Elementor:</p>
                <ul>
                    <li>Find "OpenWebUI Chatbot" widget</li>
                    <li>Drag it to your page</li>
                    <li>Configure settings in the panel</li>
                    <li>Customize appearance with Elementor</li>
                </ul>
            </div>
            
            <div class="owui-instruction-box">
                <h3>💡 Tips</h3>
                <ul>
                    <li>Write clear system prompts for better responses</li>
                    <li>Test your chatbots before going live</li>
                    <li>Monitor chat history for improvements</li>
                    <li>Use greeting messages to guide users</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<style>
.owui-modal {
    position: fixed;
    z-index: 100000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.owui-modal-content {
    background-color: #fefefe;
    margin: 5% auto;
    padding: 20px;
    border: 1px solid #888;
    width: 80%;
    max-width: 800px;
    border-radius: 5px;
    max-height: 90vh;
    overflow-y: auto;
}

.owui-close {
    color: #aaa;
    float: right;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
}

.owui-close:hover {
    color: black;
}

.owui-model-select-container {
    display: flex;
    gap: 10px;
    align-items: center;
}

.owui-model-select-container select {
    flex: 1;
}

.required {
    color: #d63638;
}

.owui-usage-instructions {
    margin-top: 40px;
    background: #f9f9f9;
    padding: 20px;
    border-radius: 5px;
}

.owui-instructions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.owui-instruction-box {
    background: white;
    padding: 20px;
    border-radius: 5px;
    border-left: 4px solid #0073aa;
}

.owui-instruction-box h3 {
    margin-top: 0;
    color: #0073aa;
}

.owui-instruction-box code {
    background: #f1f1f1;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 12px;
}

.owui-instruction-box ul, .owui-instruction-box ol {
    margin: 10px 0;
}

.owui-instruction-box li {
    margin: 5px 0;
}
</style>

<script>
jQuery(document).ready(function($) {
    
    // Add new chatbot modal
    $('#add-new-chatbot').click(function(e) {
        e.preventDefault();
        $('#owui-add-chatbot-modal').show();
    });
    
    // Close modal
    $('.owui-close, #cancel-create').click(function() {
        $('#owui-add-chatbot-modal').hide();
    });
    
    // Close modal when clicking outside
    $(window).click(function(event) {
        if (event.target.id === 'owui-add-chatbot-modal') {
            $('#owui-add-chatbot-modal').hide();
        }
    });
    
    // Form validation
    $('#create-chatbot-form').submit(function(e) {
        var name = $('#chatbot_name').val().trim();
        var model = $('#chatbot_model').val();
        
        if (!name || !model) {
            e.preventDefault();
            alert('Please fill in all required fields (Name and Model).');
            return false;
        }
    });
    
    // Copy shortcode functionality
    $(document).on('click', '.copy-shortcode', function() {
        var shortcode = $(this).data('shortcode');
        navigator.clipboard.writeText(shortcode).then(function() {
            alert('Shortcode copied to clipboard!');
        });
    });
    
});
</script>