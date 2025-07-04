<?php if (!defined('ABSPATH')) exit; ?>

<div class="wrap">
    <h1>OpenWebUI Settings</h1>

    <?php if (isset($_GET['settings-updated']) && $_GET['settings-updated']): ?>
        <div class="notice notice-success is-dismissible">
            <p>Settings saved successfully!</p>
        </div>
    <?php endif; ?>

    <form method="post" action="options.php">
        <?php settings_fields('owui_settings'); ?>
        
        <h2>API Connection Settings</h2>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="owui_base_url">Base URL Domain</label></th>
                <td>
                    <input type="url" id="owui_base_url" name="owui_base_url" 
                           value="<?php echo esc_url(get_option('owui_base_url')); ?>" class="regular-text"
                           placeholder="https://your-openwebui-instance.com">
                    <p class="description">Your OpenWebUI instance URL (e.g., https://chat.yoursite.com)</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="owui_api_key">API Key</label></th>
                <td>
                    <input type="password" id="owui_api_key" name="owui_api_key" 
                           value="<?php echo esc_attr(get_option('owui_api_key')); ?>" class="regular-text"
                           placeholder="sk-...">
                    <p class="description">Your OpenWebUI API key</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="owui_jwt_token">JWT Token (Optional)</label></th>
                <td>
                    <input type="password" id="owui_jwt_token" name="owui_jwt_token" 
                           value="<?php echo esc_attr(get_option('owui_jwt_token')); ?>" class="regular-text">
                    <p class="description">JWT token for authentication (if using JWT instead of API key)</p>
                </td>
            </tr>
        </table>

        <hr>

        <h2>🔔 Email Notifications</h2>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="owui_email_notifications">Enable Email Notifications</label></th>
                <td>
                    <label>
                        <input type="checkbox" id="owui_email_notifications" name="owui_email_notifications" value="1" 
                               <?php checked(get_option('owui_email_notifications'), 1); ?>>
                        Send email notifications when conversations end
                    </label>
                    <p class="description">Get notified when users finish chatting with your chatbots</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="owui_notification_email">Notification Email</label></th>
                <td>
                    <input type="email" id="owui_notification_email" name="owui_notification_email" 
                           value="<?php echo esc_attr(get_option('owui_notification_email', get_option('admin_email'))); ?>" class="regular-text"
                           placeholder="admin@yoursite.com">
                    <p class="description">Email address to receive conversation summaries (defaults to admin email)</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="owui_session_timeout">Session Timeout</label></th>
                <td>
                    <select id="owui_session_timeout" name="owui_session_timeout">
                        <option value="5" <?php selected(get_option('owui_session_timeout', 15), 5); ?>>5 minutes</option>
                        <option value="10" <?php selected(get_option('owui_session_timeout', 15), 10); ?>>10 minutes</option>
                        <option value="15" <?php selected(get_option('owui_session_timeout', 15), 15); ?>>15 minutes</option>
                        <option value="20" <?php selected(get_option('owui_session_timeout', 15), 20); ?>>20 minutes</option>
                        <option value="30" <?php selected(get_option('owui_session_timeout', 15), 30); ?>>30 minutes</option>
                        <option value="60" <?php selected(get_option('owui_session_timeout', 15), 60); ?>>60 minutes</option>
                    </select>
                    <p class="description">How long to wait before considering a conversation ended due to inactivity</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="owui_email_conditions">Email Conditions</label></th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text"><span>Email Conditions</span></legend>
                        <label>
                            <input type="checkbox" name="owui_email_on_contact_info" value="1" 
                                   <?php checked(get_option('owui_email_on_contact_info', 1), 1); ?>>
                            Only send emails when contact information is collected
                        </label><br>
                        <label>
                            <input type="checkbox" name="owui_email_on_long_conversations" value="1" 
                                   <?php checked(get_option('owui_email_on_long_conversations'), 1); ?>>
                            Only send emails for conversations with 3+ messages
                        </label><br>
                        <label>
                            <input type="checkbox" name="owui_email_on_keywords" value="1" 
                                   <?php checked(get_option('owui_email_on_keywords'), 1); ?>>
                            Send emails when specific keywords are mentioned
                        </label>
                    </fieldset>
                    <p class="description">Choose when to send email notifications</p>
                </td>
            </tr>
            <tr id="keywords-row" style="<?php echo get_option('owui_email_on_keywords') ? '' : 'display: none;'; ?>">
                <th scope="row"><label for="owui_notification_keywords">Keywords</label></th>
                <td>
                    <textarea id="owui_notification_keywords" name="owui_notification_keywords" rows="3" class="large-text"
                              placeholder="pricing, quote, buy, purchase, interested, contact sales"><?php echo esc_textarea(get_option('owui_notification_keywords')); ?></textarea>
                    <p class="description">Comma-separated list of keywords that trigger email notifications</p>
                </td>
            </tr>
        </table>

        <hr>

        <h2>📧 Email Template</h2>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="owui_email_subject">Email Subject</label></th>
                <td>
                    <input type="text" id="owui_email_subject" name="owui_email_subject" 
                           value="<?php echo esc_attr(get_option('owui_email_subject', 'New Chatbot Conversation Summary')); ?>" class="large-text">
                    <p class="description">Subject line for notification emails</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="owui_email_header">Email Header</label></th>
                <td>
                    <textarea id="owui_email_header" name="owui_email_header" rows="4" class="large-text"
                              placeholder="A new conversation has ended on your website..."><?php echo esc_textarea(get_option('owui_email_header', 'A new conversation has ended on your website. Here\'s a summary:')); ?></textarea>
                    <p class="description">Introduction text for the email</p>
                </td>
            </tr>
        </table>

        <p class="submit">
            <button type="button" class="button" id="owui-test-connection">Test Connection</button>
            <button type="button" class="button" id="owui-test-email">Test Email</button>
            <?php submit_button('Save Settings', 'primary', 'submit', false); ?>
        </p>
    </form>

    <hr>

    <h2>Backup & Export</h2>
    <table class="form-table">
        <tr>
            <th scope="row">Export Chat History</th>
            <td>
                <button type="button" class="button" id="owui-export-csv">Export to CSV</button>
                <p class="description">Download your chat history in CSV format</p>
            </td>
        </tr>
    </table>

    <hr>

    <h2>Usage Instructions</h2>
    <div style="background: #f9f9f9; padding: 20px; border: 1px solid #ddd; border-radius: 5px;">
        <h3>Getting Started</h3>
        <ol>
            <li><strong>Configure API Connection:</strong> Enter your OpenWebUI instance URL and API key above</li>
            <li><strong>Test Connection:</strong> Click "Test Connection" to verify your settings</li>
            <li><strong>Setup Email Notifications:</strong> Configure when and how you want to be notified about conversations</li>
            <li><strong>Create Chatbots:</strong> Go to OpenWebUI > Chatbots to create your first chatbot</li>
            <li><strong>Display Options:</strong> Use shortcodes, Elementor widget, or automatic floating button</li>
        </ol>

        <h3>Shortcode Usage</h3>
        <p>Use these shortcodes to display chatbots:</p>
        <ul>
            <li><code>[openwebui_chatbot]</code> - Floating button (default)</li>
            <li><code>[openwebui_chatbot type="inline" width="400" height="600"]</code> - Inline chat</li>
            <li><code>[openwebui_chatbot type="button" button_text="Chat Now"]</code> - Popup button</li>
            <li><code>[openwebui_chatbot id="1"]</code> - Specific chatbot by ID</li>
        </ul>

        <h3>Email Notifications</h3>
        <p>The system will automatically:</p>
        <ul>
            <li>✅ Track conversation sessions and detect when they end</li>
            <li>✅ Generate AI-powered summaries of conversations</li>
            <li>✅ Extract and highlight any contact information collected</li>
            <li>✅ Send formatted email notifications based on your conditions</li>
            <li>✅ Include conversation transcripts and actionable insights</li>
        </ul>

        <h3>Elementor Integration</h3>
        <p>If Elementor is installed, you'll find the "OpenWebUI Chatbot" widget in the widgets panel with full customization options.</p>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    
    // Show/hide keywords field based on checkbox
    $('input[name="owui_email_on_keywords"]').change(function() {
        if ($(this).is(':checked')) {
            $('#keywords-row').show();
        } else {
            $('#keywords-row').hide();
        }
    });
    
    // Test email functionality
    $('#owui-test-email').click(function() {
        var btn = $(this);
        var originalText = btn.text();
        btn.prop('disabled', true).text('Sending...');
        
        $.post(owui_admin_ajax.ajax_url, {
            action: 'owui_test_email',
            nonce: owui_admin_ajax.nonce
        }, function(response) {
            btn.prop('disabled', false).text(originalText);
            
            if (response.success) {
                alert('✅ Test email sent successfully! Check your inbox.');
            } else {
                alert('❌ Failed to send test email: ' + response.data);
            }
        }).fail(function() {
            btn.prop('disabled', false).text(originalText);
            alert('❌ Failed to send test email');
        });
    });
    
});
</script>