<?php

if (!defined('ABSPATH')) exit;

class OWUI_Rate_Limiter {
    private $max_requests_per_minute;
    private $max_requests_per_hour;
    
    public function __construct() {
        $this->max_requests_per_minute = apply_filters('owui_rate_limit_per_minute', 10);
        $this->max_requests_per_hour = apply_filters('owui_rate_limit_per_hour', 100);
    }
    
    public function check_rate_limit($identifier = null) {
        if (!$identifier) {
            $user_id = get_current_user_id();
            $ip_address = $this->get_client_ip();
            $identifier = $user_id ? "user_{$user_id}" : "ip_{$ip_address}";
        }
        
        // Check minute limit
        if (!$this->check_limit($identifier, 'minute', $this->max_requests_per_minute)) {
            return false;
        }
        
        // Check hour limit
        if (!$this->check_limit($identifier, 'hour', $this->max_requests_per_hour)) {
            return false;
        }
        
        // Record this request
        $this->record_request($identifier);
        
        return true;
    }
    
    private function check_limit($identifier, $period, $max_requests) {
        $key = "owui_rate_limit_{$identifier}_{$period}";
        $current_time = time();
        
        if ($period === 'minute') {
            $window_start = $current_time - 60;
        } else {
            $window_start = $current_time - 3600;
        }
        
        $requests = get_transient($key);
        if (!$requests) {
            return true;
        }
        
        // Remove old requests outside the window
        $requests = array_filter($requests, function($timestamp) use ($window_start) {
            return $timestamp >= $window_start;
        });
        
        return count($requests) < $max_requests;
    }
    
    private function record_request($identifier) {
        $current_time = time();
        
        // Record for minute limit
        $minute_key = "owui_rate_limit_{$identifier}_minute";
        $minute_requests = get_transient($minute_key) ?: array();
        $minute_requests[] = $current_time;
        set_transient($minute_key, $minute_requests, 70); // 70 seconds to be safe
        
        // Record for hour limit
        $hour_key = "owui_rate_limit_{$identifier}_hour";
        $hour_requests = get_transient($hour_key) ?: array();
        $hour_requests[] = $current_time;
        set_transient($hour_key, $hour_requests, 3660); // 3660 seconds to be safe
    }
    
    private function get_client_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}