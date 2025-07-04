<?php if (!defined('ABSPATH')) exit; ?>

<div class="wrap">
    <h1>Contact Information</h1>
    <p>This page shows contact information (names, emails, phone numbers) automatically extracted from chat conversations.</p>
    
    <div class="tablenav top">
        <div class="alignleft actions">
            <button type="button" class="button" id="export-contacts">Export to CSV</button>
        </div>
    </div>
    
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th width="15%">Date</th>
                <th width="15%">Chatbot</th>
                <th width="20%">Name(s)</th>
                <th width="25%">Email(s)</th>
                <th width="15%">Phone(s)</th>
                <th width="10%">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php $admin->render_contact_list(); ?>
        </tbody>
    </table>
    
    <div class="owui-contact-info">
        <h3>How Contact Extraction Works</h3>
        <p>The system automatically detects and extracts:</p>
        <ul>
            <li><strong>Names:</strong> When users say "My name is...", "I'm...", "Call me...", etc.</li>
            <li><strong>Emails:</strong> Any valid email address format</li>
            <li><strong>Phone Numbers:</strong> Various formats including (123) 456-7890, 123-456-7890, +1-123-456-7890</li>
        </ul>
        <p><em>Note: Contact information is extracted from both user messages and bot responses to catch any details mentioned during the conversation.</em></p>
    </div>
</div>
