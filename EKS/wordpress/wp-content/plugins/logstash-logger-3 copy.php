<?php
/**
 * Plugin Name: Logstash Logger Enhanced
 * Description: Envia logs detalhados para Logstash com tracking de URLs e arquivos
 * Version: 3.0
 */

class LogstashLogger {
    
    private $file_types = array(
        'image' => array('jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp'),
        'audio' => array('mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'),
        'video' => array('mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv'),
        'document' => array('pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'),
        'archive' => array('zip', 'rar', '7z', 'tar', 'gz'),
        'code' => array('css', 'js', 'php', 'html', 'xml', 'json')
    );
    
    public function __construct() {
        add_action('wp_loaded', array($this, 'log_page_access'));
        add_action('wp_login', array($this, 'log_user_login'), 10, 2);
        add_action('user_register', array($this, 'log_user_registration'));
        add_action('wp_logout', array($this, 'log_user_logout'));
        add_action('wp_head', array($this, 'log_special_events'));
    }
    
    private function send_to_logstash($log_data) {
        $logstash_url = 'http://logstash:5044';
        
        $response = wp_remote_post($logstash_url, array(
            'headers' => array('Content-Type' => 'text/plain'),
            'body' => $log_data,
            'timeout' => 2,
            'blocking' => false
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
        $user_role = $user_id ? $this->get_user_role($user_id) : 'guest';
        
        $timestamp = date('d/M/Y:H:i:s O');
        
        // Detectar tipo de conteúdo
        $content_type = $this->detect_content_type($request_uri);
        $file_extension = $this->get_file_extension($request_uri);
        
        // Detectar se é uma página específica do WordPress
        $page_type = $this->detect_page_type($request_uri);
        
        // Informações adicionais para arquivos
        $file_info = '';
        if ($file_extension) {
            $file_size = $this->get_file_size($request_uri);
            $file_info = sprintf('[FILE:%s:%s:%s]', $content_type, $file_extension, $file_size);
        } else {
            $file_info = sprintf('[PAGE:%s]', $page_type);
        }
        
        $log_message = sprintf(
            '%s - %s [%s] "%s %s HTTP/1.1" %d %d "-" "%s" [USER_ROLE:%s] %s',
            $client_ip,
            $user_name,
            $timestamp,
            $http_method,
            $request_uri,
            $response_code,
            $this->get_response_size(),
            $user_agent,
            $user_role,
            $file_info
        );
        
        $this->send_to_logstash($log_message);
    }
    
    public function log_user_login($user_login, $user) {
        $client_ip = $this->get_client_ip();
        $timestamp = date('d/M/Y:H:i:s O');
        $user_role = $this->get_user_role($user->ID);
        
        $log_message = sprintf(
            '%s - %s [%s] "POST /wp-login.php HTTP/1.1" 200 %d "-" "%s" [LOGIN:ROLE:%s]',
            $client_ip,
            $user_login,
            $timestamp,
            rand(800, 1200),
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            $user_role
        );
        
        $this->send_to_logstash($log_message);
    }
    
    public function log_user_registration($user_id) {
        $client_ip = $this->get_client_ip();
        $timestamp = date('d/M/Y:H:i:s O');
        $user = get_userdata($user_id);
        
        $log_message = sprintf(
            '%s - %s [%s] "POST /wp-register.php HTTP/1.1" 200 %d "-" "%s" [REGISTER:ROLE:%s]',
            $client_ip,
            $user->user_login,
            $timestamp,
            rand(1000, 1500),
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            $user->roles[0] ?? 'subscriber'
        );
        
        $this->send_to_logstash($log_message);
    }
    
    public function log_user_logout() {
        $user_id = get_current_user_id();
        if ($user_id) {
            $user = get_userdata($user_id);
            $client_ip = $this->get_client_ip();
            $timestamp = date('d/M/Y:H:i:s O');
            $user_role = $this->get_user_role($user_id);
            
            $log_message = sprintf(
                '%s - %s [%s] "GET /wp-logout.php HTTP/1.1" 200 %d "-" "%s" [LOGOUT:ROLE:%s]',
                $client_ip,
                $user->user_login,
                $timestamp,
                rand(600, 1000),
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                $user_role
            );
            
            $this->send_to_logstash($log_message);
        }
    }
    
    public function log_special_events() {
        // Log para ações específicas
        global $post;
        
        if (is_single() || is_page()) {
            $client_ip = $this->get_client_ip();
            $timestamp = date('d/M/Y:H:i:s O');
            $post_type = get_post_type();
            $post_id = get_the_ID();
            
            $log_message = sprintf(
                '%s - %s [%s] "GET %s HTTP/1.1" 200 %d "-" "%s" [CONTENT:%s:ID:%d]',
                $client_ip,
                get_current_user_id() ? get_userdata(get_current_user_id())->user_login : 'anonymous',
                $timestamp,
                $_SERVER['REQUEST_URI'],
                $this->get_response_size(),
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                $post_type,
                $post_id
            );
            
            $this->send_to_logstash($log_message);
        }
    }
    
    private function detect_content_type($uri) {
        $extension = $this->get_file_extension($uri);
        
        if (!$extension) return 'webpage';
        
        foreach ($this->file_types as $type => $extensions) {
            if (in_array($extension, $extensions)) {
                return $type;
            }
        }
        
        return 'other';
    }
    
    private function detect_page_type($uri) {
        // Detectar tipos específicos de páginas WordPress
        if (strpos($uri, '/wp-admin') !== false) return 'admin';
        if (strpos($uri, '/wp-content/uploads') !== false) return 'media';
        if (strpos($uri, '/wp-json') !== false) return 'api';
        if (strpos($uri, '/wp-login.php') !== false) return 'login';
        if (strpos($uri, '/?s=') !== false || strpos($uri, '/search/') !== false) return 'search';
        if (is_front_page()) return 'homepage';
        if (is_category()) return 'category';
        if (is_tag()) return 'tag';
        if (is_author()) return 'author';
        if (is_archive()) return 'archive';
        if (is_single()) return 'single';
        if (is_page()) return 'page';
        
        return 'unknown';
    }
    
    private function get_file_extension($uri) {
        $path = parse_url($uri, PHP_URL_PATH);
        if ($path && preg_match('/\.([a-z0-9]+)$/i', $path, $matches)) {
            return strtolower($matches[1]);
        }
        return '';
    }
    
    private function get_file_size($uri) {
        $upload_dir = wp_upload_dir();
        $file_path = ABSPATH . ltrim($uri, '/');
        
        if (file_exists($file_path) && is_file($file_path)) {
            $size = filesize($file_path);
            return $this->format_file_size($size);
        }
        
        return 'unknown';
    }
    
    private function get_response_size() {
        // Simular tamanho de resposta baseado no tipo de conteúdo
        if (is_admin()) return rand(50000, 200000);
        if (is_single() || is_page()) return rand(30000, 100000);
        if ($this->get_file_extension($_SERVER['REQUEST_URI'])) {
            return rand(100000, 5000000); // Arquivos são maiores
        }
        return rand(10000, 50000);
    }
    
    private function format_file_size($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . 'GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . 'MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . 'KB';
        } else {
            return $bytes . 'B';
        }
    }
    
    private function get_user_role($user_id) {
        $user = get_userdata($user_id);
        if ($user && !empty($user->roles)) {
            return $user->roles[0];
        }
        return 'guest';
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
        
        return false;
    }
}

new LogstashLogger();
?>