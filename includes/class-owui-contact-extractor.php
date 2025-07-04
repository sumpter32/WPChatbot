<?php
/**
 * Contact Information Extractor for OpenWebUI Chatbot
 */

if (!defined('ABSPATH')) exit;

class OWUI_Contact_Extractor {
    
    public function __construct() {
        // Initialize patterns for contact extraction
    }
    
    /**
     * Extract contact information from text
     */
    public function extract_contact_info($user_message, $bot_response = '') {
        $contact_data = array();
        
        // Combine user message and bot response for extraction
        $text = $user_message . ' ' . $bot_response;
        
        // Extract names
        $names = $this->extract_names($text);
        if (!empty($names)) {
            $contact_data['name'] = $names;
        }
        
        // Extract emails
        $emails = $this->extract_emails($text);
        if (!empty($emails)) {
            $contact_data['email'] = $emails;
        }
        
        // Extract phone numbers
        $phones = $this->extract_phone_numbers($text);
        if (!empty($phones)) {
            $contact_data['phone'] = $phones;
        }
        
        return $contact_data;
    }
    
    /**
     * Extract names from text
     */
    private function extract_names($text) {
        $names = array();
        
        // Patterns to identify name introductions
        $name_patterns = array(
            '/(?:my name is|i\'m|i am|call me|this is|name\'s)\s+([a-zA-Z]+(?:\s+[a-zA-Z]+)*)/i',
            '/(?:i\'m|i am)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)/i'
        );
        
        foreach ($name_patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[1] as $name) {
                    $name = trim($name);
                    if (strlen($name) > 1 && strlen($name) < 50) {
                        $names[] = $name;
                    }
                }
            }
        }
        
        return array_unique($names);
    }
    
    /**
     * Extract email addresses from text
     */
    private function extract_emails($text) {
        $emails = array();
        
        // Email pattern
        $email_pattern = '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/';
        
        if (preg_match_all($email_pattern, $text, $matches)) {
            foreach ($matches[0] as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = strtolower($email);
                }
            }
        }
        
        return array_unique($emails);
    }
    
    /**
     * Extract phone numbers from text
     */
    private function extract_phone_numbers($text) {
        $phones = array();
        
        // Phone number patterns
        $phone_patterns = array(
            '/\b(?:\+?1[-.\s]?)?\(?([0-9]{3})\)?[-.\s]?([0-9]{3})[-.\s]?([0-9]{4})\b/',
            '/\b\+?[1-9]\d{1,14}\b/',
            '/\b\d{3}[-.\s]?\d{3}[-.\s]?\d{4}\b/',
            '/\(\d{3}\)\s?\d{3}[-.\s]?\d{4}/'
        );
        
        foreach ($phone_patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[0] as $phone) {
                    // Clean up the phone number
                    $clean_phone = preg_replace('/[^\d+]/', '', $phone);
                    if (strlen($clean_phone) >= 10) {
                        $phones[] = $phone;
                    }
                }
            }
        }
        
        return array_unique($phones);
    }
    
    /**
     * Save contact information to database
     */
    public function save_contact_info($session_id, $contact_data, $message_id = null) {
        global $wpdb;
        
        foreach ($contact_data as $type => $values) {
            if (is_array($values)) {
                foreach ($values as $value) {
                    $this->insert_contact_record($session_id, $type, $value, $message_id);
                }
            } else {
                $this->insert_contact_record($session_id, $type, $values, $message_id);
            }
        }
    }
    
    /**
     * Insert individual contact record
     */
    private function insert_contact_record($session_id, $type, $value, $message_id = null) {
        global $wpdb;
        
        // Check if this contact info already exists for this session
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}owui_contact_info 
            WHERE session_id = %d AND contact_type = %s AND contact_value = %s",
            $session_id, $type, $value
        ));
        
        if (!$existing) {
            $wpdb->insert(
                $wpdb->prefix . 'owui_contact_info',
                array(
                    'session_id' => $session_id,
                    'message_id' => $message_id,
                    'contact_type' => $type,
                    'contact_value' => $value,
                    'extracted_at' => current_time('mysql')
                ),
                array('%d', '%d', '%s', '%s', '%s')
            );
        }
    }
    
    /**
     * Get all contacts with pagination
     */
    public function get_all_contacts($limit = 100, $offset = 0) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT ci.*, s.chatbot_id, c.name as chatbot_name
            FROM {$wpdb->prefix}owui_contact_info ci
            LEFT JOIN {$wpdb->prefix}owui_chat_sessions s ON ci.session_id = s.id
            LEFT JOIN {$wpdb->prefix}owui_chatbots c ON s.chatbot_id = c.id
            ORDER BY ci.extracted_at DESC
            LIMIT %d OFFSET %d",
            $limit, $offset
        ));
    }
    
    /**
     * Get recent contacts grouped by session
     */
    public function get_recent_contacts_grouped($limit = 50) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT 
                ci.session_id,
                MAX(ci.extracted_at) as latest_date,
                c.name as chatbot_name,
                GROUP_CONCAT(CASE WHEN ci.contact_type = 'name' THEN ci.contact_value END SEPARATOR ', ') as names,
                GROUP_CONCAT(CASE WHEN ci.contact_type = 'email' THEN ci.contact_value END SEPARATOR ', ') as emails,
                GROUP_CONCAT(CASE WHEN ci.contact_type = 'phone' THEN ci.contact_value END SEPARATOR ', ') as phones
            FROM {$wpdb->prefix}owui_contact_info ci
            LEFT JOIN {$wpdb->prefix}owui_chat_sessions s ON ci.session_id = s.id
            LEFT JOIN {$wpdb->prefix}owui_chatbots c ON s.chatbot_id = c.id
            GROUP BY ci.session_id
            ORDER BY latest_date DESC
            LIMIT %d",
            $limit
        ));
    }
    
    /**
     * Get contacts for specific session
     */
    public function get_session_contacts($session_id) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}owui_contact_info 
            WHERE session_id = %d 
            ORDER BY extracted_at ASC",
            $session_id
        ));
    }
    
    /**
     * Search contacts
     */
    public function search_contacts($search_term, $limit = 50) {
        global $wpdb;
        
        $search_term = '%' . $wpdb->esc_like($search_term) . '%';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT ci.*, s.chatbot_id, c.name as chatbot_name
            FROM {$wpdb->prefix}owui_contact_info ci
            LEFT JOIN {$wpdb->prefix}owui_chat_sessions s ON ci.session_id = s.id
            LEFT JOIN {$wpdb->prefix}owui_chatbots c ON s.chatbot_id = c.id
            WHERE ci.contact_value LIKE %s
            ORDER BY ci.extracted_at DESC
            LIMIT %d",
            $search_term, $limit
        ));
    }
    
    /**
     * Get contact statistics
     */
    public function get_contact_stats() {
        global $wpdb;
        
        $stats = array();
        
        // Total contacts
        $stats['total'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}owui_contact_info"
        );
        
        // Contacts by type
        $stats['by_type'] = $wpdb->get_results(
            "SELECT contact_type, COUNT(*) as count 
            FROM {$wpdb->prefix}owui_contact_info 
            GROUP BY contact_type"
        );
        
        // Recent contacts (last 30 days)
        $stats['recent'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}owui_contact_info 
            WHERE extracted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        
        // Unique sessions with contacts
        $stats['unique_sessions'] = $wpdb->get_var(
            "SELECT COUNT(DISTINCT session_id) FROM {$wpdb->prefix}owui_contact_info"
        );
        
        return $stats;
    }
    
    /**
     * Delete old contact information
     */
    public function cleanup_old_contacts($days = 365) {
        global $wpdb;
        
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}owui_contact_info 
            WHERE extracted_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
    }
    
    /**
     * Export contacts to CSV format
     */
    public function export_contacts_csv() {
        $contacts = $this->get_all_contacts(10000); // Get all contacts
        
        $csv_data = "Date,Chatbot,Contact Type,Contact Value,Session ID\n";
        
        foreach ($contacts as $contact) {
            $csv_data .= sprintf(
                "%s,%s,%s,%s,%s\n",
                $contact->extracted_at,
                $contact->chatbot_name ?: 'Unknown',
                ucfirst($contact->contact_type),
                $contact->contact_value,
                $contact->session_id
            );
        }
        
        return $csv_data;
    }
}