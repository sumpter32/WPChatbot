<?php
/**
 * Contact Information Extractor for OpenWebUI Chatbot - Fixed Version
 */

if (!defined('ABSPATH')) {
    exit;
}

class OWUI_Contact_Extractor {
    
    public function __construct() {
        // Initialize patterns for contact extraction
    }
    
    /**
     * Extract contact information from text
     */
    public function extract_contact_info($user_message, $bot_response = '') {
        $contact_data = [];
        
        try {
            // Sanitize inputs
            $user_message = sanitize_textarea_field($user_message);
            $bot_response = sanitize_textarea_field($bot_response);
            
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
            
        } catch (Exception $e) {
            owui_log_error('Contact Extraction', $e->getMessage());
        }
        
        return $contact_data;
    }
    
    /**
     * Extract names from text
     */
    private function extract_names($text) {
        $names = [];
        
        try {
            // Patterns to identify name introductions
            $name_patterns = [
                '/(?:my name is|i\'m|i am|call me|this is|name\'s)\s+([a-zA-Z]+(?:\s+[a-zA-Z]+)*)/i',
                '/(?:i\'m|i am)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)/i'
            ];
            
            foreach ($name_patterns as $pattern) {
                if (preg_match_all($pattern, $text, $matches)) {
                    foreach ($matches[1] as $name) {
                        $name = trim(sanitize_text_field($name));
                        if (strlen($name) > 1 && strlen($name) < 50) {
                            $names[] = $name;
                        }
                    }
                }
            }
            
        } catch (Exception $e) {
            owui_log_error('Name Extraction', $e->getMessage());
        }
        
        return array_unique($names);
    }
    
    /**
     * Extract email addresses from text
     */
    private function extract_emails($text) {
        $emails = [];
        
        try {
            // Email pattern
            $email_pattern = '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/';
            
            if (preg_match_all($email_pattern, $text, $matches)) {
                foreach ($matches[0] as $email) {
                    $email = sanitize_email($email);
                    if (is_email($email)) {
                        $emails[] = strtolower($email);
                    }
                }
            }
            
        } catch (Exception $e) {
            owui_log_error('Email Extraction', $e->getMessage());
        }
        
        return array_unique($emails);
    }
    
    /**
     * Extract phone numbers from text
     */
    private function extract_phone_numbers($text) {
        $phones = [];
        
        try {
            // Phone number patterns
            $phone_patterns = [
                '/\b(?:\+?1[-.\s]?)?\(?([0-9]{3})\)?[-.\s]?([0-9]{3})[-.\s]?([0-9]{4})\b/',
                '/\b\+?[1-9]\d{1,14}\b/',
                '/\b\d{3}[-.\s]?\d{3}[-.\s]?\d{4}\b/',
                '/\(\d{3}\)\s?\d{3}[-.\s]?\d{4}/'
            ];
            
            foreach ($phone_patterns as $pattern) {
                if (preg_match_all($pattern, $text, $matches)) {
                    foreach ($matches[0] as $phone) {
                        // Clean up the phone number
                        $clean_phone = preg_replace('/[^\d+]/', '', $phone);
                        if (strlen($clean_phone) >= 10) {
                            $phones[] = sanitize_text_field($phone);
                        }
                    }
                }
            }
            
        } catch (Exception $e) {
            owui_log_error('Phone Extraction', $e->getMessage());
        }
        
        return array_unique($phones);
    }
    
    /**
     * Save contact information to database
     */
    public function save_contact_info($session_id, $contact_data, $message_id = null) {
        try {
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
            
        } catch (Exception $e) {
            owui_log_error('Save Contact Info', $e->getMessage());
        }
    }
    
    /**
     * Insert individual contact record
     */
    private function insert_contact_record($session_id, $type, $value, $message_id = null) {
        global $wpdb;
        
        try {
            // Sanitize inputs
            $session_id = absint($session_id);
            $message_id = $message_id ? absint($message_id) : null;
            $type = sanitize_text_field($type);
            $value = sanitize_text_field($value);
            
            if (!$session_id || empty($type) || empty($value)) {
                return false;
            }
            
            // Check if this contact info already exists for this session
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}owui_contact_info 
                WHERE session_id = %d AND contact_type = %s AND contact_value = %s",
                $session_id, $type, $value
            ));
            
            if (!$existing) {
                $result = $wpdb->insert(
                    $wpdb->prefix . 'owui_contact_info',
                    [
                        'session_id' => $session_id,
                        'message_id' => $message_id,
                        'contact_type' => $type,
                        'contact_value' => $value,
                        'extracted_at' => current_time('mysql', true)
                    ],
                    ['%d', '%d', '%s', '%s', '%s']
                );
                
                if ($result === false) {
                    owui_log_error('Insert Contact Record', $wpdb->last_error);
                }
                
                return $result !== false;
            }
            
            return true;
            
        } catch (Exception $e) {
            owui_log_error('Insert Contact Record', $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all contacts with pagination
     */
    public function get_all_contacts($limit = 100, $offset = 0) {
        global $wpdb;
        
        try {
            $limit = absint($limit);
            $offset = absint($offset);
            
            if ($limit <= 0) $limit = 100;
            if ($limit > 10000) $limit = 10000; // Safety limit
            
            return $wpdb->get_results($wpdb->prepare(
                "SELECT ci.*, s.chatbot_id, c.name as chatbot_name
                FROM {$wpdb->prefix}owui_contact_info ci
                LEFT JOIN {$wpdb->prefix}owui_chat_sessions s ON ci.session_id = s.id
                LEFT JOIN {$wpdb->prefix}owui_chatbots c ON s.chatbot_id = c.id
                ORDER BY ci.extracted_at DESC
                LIMIT %d OFFSET %d",
                $limit, $offset
            ));
            
        } catch (Exception $e) {
            owui_log_error('Get All Contacts', $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get recent contacts grouped by session
     */
    public function get_recent_contacts_grouped($limit = 50) {
        global $wpdb;
        
        try {
            $limit = absint($limit);
            if ($limit <= 0) $limit = 50;
            if ($limit > 1000) $limit = 1000; // Safety limit
            
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
            
        } catch (Exception $e) {
            owui_log_error('Get Recent Contacts Grouped', $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get contacts for specific session
     */
    public function get_session_contacts($session_id) {
        global $wpdb;
        
        try {
            $session_id = absint($session_id);
            if (!$session_id) {
                return [];
            }
            
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}owui_contact_info 
                WHERE session_id = %d 
                ORDER BY extracted_at ASC",
                $session_id
            ));
            
        } catch (Exception $e) {
            owui_log_error('Get Session Contacts', $e->getMessage());
            return [];
        }
    }
    
    /**
     * Search contacts
     */
    public function search_contacts($search_term, $limit = 50) {
        global $wpdb;
        
        try {
            $limit = absint($limit);
            if ($limit <= 0) $limit = 50;
            if ($limit > 1000) $limit = 1000; // Safety limit
            
            $search_term = '%' . $wpdb->esc_like(sanitize_text_field($search_term)) . '%';
            
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
            
        } catch (Exception $e) {
            owui_log_error('Search Contacts', $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get contact statistics
     */
    public function get_contact_stats() {
        global $wpdb;
        
        try {
            $stats = [];
            
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
            
        } catch (Exception $e) {
            owui_log_error('Get Contact Stats', $e->getMessage());
            return [
                'total' => 0,
                'by_type' => [],
                'recent' => 0,
                'unique_sessions' => 0
            ];
        }
    }
    
    /**
     * Delete old contact information
     */
    public function cleanup_old_contacts($days = 365) {
        global $wpdb;
        
        try {
            $days = absint($days);
            if ($days <= 0) $days = 365;
            
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}owui_contact_info 
                WHERE extracted_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ));
            
            if ($deleted !== false) {
                owui_log_info('Cleaned up old contacts', ['days' => $days, 'deleted' => $deleted]);
            }
            
            return $deleted;
            
        } catch (Exception $e) {
            owui_log_error('Cleanup Old Contacts', $e->getMessage());
            return false;
        }
    }
    
    /**
     * Export contacts to CSV format
     */
    public function export_contacts_csv() {
        try {
            $contacts = $this->get_all_contacts(10000); // Get all contacts
            
            $csv_data = "Date,Chatbot,Contact Type,Contact Value,Session ID\n";
            
            foreach ($contacts as $contact) {
                $csv_data .= sprintf(
                    "%s,%s,%s,%s,%s\n",
                    esc_html($contact->extracted_at),
                    esc_html($contact->chatbot_name ?: 'Unknown'),
                    esc_html(ucfirst($contact->contact_type)),
                    esc_html($contact->contact_value),
                    esc_html($contact->session_id)
                );
            }
            
            return $csv_data;
            
        } catch (Exception $e) {
            owui_log_error('Export Contacts CSV', $e->getMessage());
            return '';
        }
    }

    public function send_chat_message($model, $message, $system_prompt = '') {
        return $this->send_chat_message_with_context($model, $message, $system_prompt, []);
    }
}