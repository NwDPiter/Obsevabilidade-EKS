<?php
/**
 * Plugin Name: Logstash Logger
 * Description: Envia logs de acesso para Logstash
 * Version: 1.0
 */

function log_to_logstash($message) {
    $logstash_url = 'http://logstash:5044';
    
    $response = wp_remote_post($logstash_url, array(
        'headers' => array('Content-Type' => 'text/plain'),
        'body' => $message,
        'timeout' => 2
    ));
    
    if (is_wp_error($response)) {
        error_log('Logstash Error: ' . $response->get_error_message());
        return false;
    }
    return true;
}

function log_page_access() {
    // Não logar requisições AJAX ou de sistema
    if (defined('DOING_AJAX') && DOING_AJAX) return;
    if (defined('DOING_CRON') && DOING_CRON) return;
    
    $client_ip = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $request_uri = $_SERVER['REQUEST_URI'];
    $http_method = $_SERVER['REQUEST_METHOD'];
    $response_code = http_response_code();
    
    $timestamp = date('d/M/Y:H:i:s O');
    
    // Formato Apache Combined Log
    $log_message = sprintf(
        '%s - - [%s] "%s %s HTTP/1.1" %d %d "-" "%s"',
        $client_ip,
        $timestamp,
        $http_method,
        $request_uri,
        $response_code,
        rand(500, 5000), // bytes simulados
        $user_agent
    );
    
    log_to_logstash($log_message);
}

// Hook para logar cada acesso
add_action('init', 'log_page_access');
?>




------------------










<?php
/**
 * Plugin Name: Logstash Logger Enhanced
 * Description: Envia logs detalhados para Logstash
 * Version: 2.0
 */

class LogstashLogger {
    
    public function __construct() {
        add_action('wp_loaded', array($this, 'log_page_access'));
        add_action('wp_login', array($this, 'log_user_login'), 10, 2);
        add_action('user_register', array($this, 'log_user_registration'));
        add_action('wp_logout', array($this, 'log_user_logout'));
    }
    
    private function send_to_logstash($log_data) {
        $logstash_url = 'http://logstash:5044';
        
        $response = wp_remote_post($logstash_url, array(
            'headers' => array('Content-Type' => 'text/plain'),
            'body' => $log_data,
            'timeout' => 2,
            'blocking' => false  // Não bloquear a execução
        ));
        
        return !is_wp_error($response);
    }
    
    public function log_page_access() {
        if ($this->should_skip_logging()) return;
        
        $client_ip = $this->get_client_ip();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $request_uri = $_SERVER['REQUEST_URI'];
        $http_method = $_SERVER['REQUEST_METHOD'];
        $response_code = http_response_code();
        $user_id = get_current_user_id();
        $user_name = $user_id ? get_userdata($user_id)->user_login : 'anonymous';
        
        $timestamp = date('d/M/Y:H:i:s O');
        
        $log_message = sprintf(
            '%s - %s [%s] "%s %s HTTP/1.1" %d %d "-" "%s"',
            $client_ip,
            $user_name,
            $timestamp,
            $http_method,
            $request_uri,
            $response_code,
            rand(500, 5000),
            $user_agent
        );
        
        $this->send_to_logstash($log_message);
    }
    
    public function log_user_login($user_login, $user) {
        $client_ip = $this->get_client_ip();
        $timestamp = date('d/M/Y:H:i:s O');
        
        $log_message = sprintf(
            '%s - %s [%s] "POST /wp-login.php HTTP/1.1" 200 %d "-" "%s" [LOGIN]',
            $client_ip,
            $user_login,
            $timestamp,
            rand(800, 1200),
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        );
        
        $this->send_to_logstash($log_message);
    }
    
    public function log_user_logout() {
        $user_id = get_current_user_id();
        if ($user_id) {
            $user = get_userdata($user_id);
            $client_ip = $this->get_client_ip();
            $timestamp = date('d/M/Y:H:i:s O');
            
            $log_message = sprintf(
                '%s - %s [%s] "GET /wp-logout.php HTTP/1.1" 200 %d "-" "%s" [LOGOUT]',
                $client_ip,
                $user->user_login,
                $timestamp,
                rand(600, 1000),
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );
            
            $this->send_to_logstash($log_message);
        }
    }
    
    private function get_client_ip() {
        $ip_keys = array('HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR');
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    private function should_skip_logging() {
        // Não logar requisições de sistema
        if (defined('DOING_AJAX') && DOING_AJAX) return true;
        if (defined('DOING_CRON') && DOING_CRON) return true;
        if (defined('WP_CLI') && WP_CLI) return true;
        if (wp_is_json_request()) return true;
        
        // Não logar assets
        $extensions = array('.css', '.js', '.png', '.jpg', '.jpeg', '.gif', '.ico', '.svg');
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        foreach ($extensions as $ext) {
            if (strpos($request_uri, $ext) !== false) return true;
        }
        
        return false;
    }
}

new LogstashLogger();
?>