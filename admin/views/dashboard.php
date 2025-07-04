<?php
// =============================================================================
// ADMIN VIEWS START HERE
// =============================================================================

// =============================================================================
// FILE: admin/views/dashboard.php
// =============================================================================
?>
<?php if (!defined('ABSPATH')) exit; ?>

<div class="wrap">
    <h1>OpenWebUI Chatbot Dashboard</h1>
    
    <div class="owui-dashboard-stats">
        <div class="stat-box">
            <h3>Active Chatbots</h3>
            <p class="stat-number"><?php echo esc_html($this->get_active_chatbots_count()); ?></p>
        </div>
        <div class="stat-box">
            <h3>Total Conversations</h3>
            <p class="stat-number"><?php echo esc_html($this->get_total_conversations()); ?></p>
        </div>
        <div class="stat-box">
            <h3>Response Rate</h3>
            <p class="stat-number"><?php echo esc_html($this->get_response_rate()); ?>%</p>
        </div>
        <div class="stat-box">
            <h3>Contacts Collected</h3>
            <p class="stat-number"><?php echo esc_html($this->get_total_contacts()); ?></p>
        </div>
    </div>

    <div class="owui-quick-actions">
        <a href="?page=owui-chatbots&action=new" class="button button-primary">New Chatbot</a>
        <a href="?page=owui-settings" class="button">Connection Settings</a>
        <a href="?page=owui-history" class="button">Chat History</a>
        <a href="?page=owui-contacts" class="button">View Contacts</a>
    </div>

    <div class="owui-recent-conversations">
        <h2>Recent Conversations</h2>
        <?php $this->render_recent_conversations(); ?>
    </div>
</div>
