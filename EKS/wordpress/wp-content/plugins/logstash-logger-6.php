<?php
/**
 * Plugin Name: Logstash Logger - With User Subscribe
 * Description: Envia logs de acesso para Logstash incluindo inscrições de usuários
 * Version: 6.2
 */

class LogstashLogger {
    
    private $file_types = array(
        'image' => array('jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'),
        'audio' => array('mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'),
        'video' => array('mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv'),
        'document' => array('pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'),
        'archive' => array('zip', 'rar', '7z', 'tar', 'gz'),
    );
    
    public function __construct() {
        add_action('wp_loaded', array($this, 'log_page_access'));
        add_action('wp_login', array($this, 'log_user_login'), 10, 2);
        add_action('user_register', array($this, 'log_user_registration'));
        add_action('wp_logout', array($this, 'log_user_logout'));
        add_action('wp_head', array($this, 'log_special_events'));
        
        // NOVO: Capturar inscrições de usuários
        add_action('wp_ajax_nopriv_subscribe_user', array($this, 'log_user_subscribe'));
        add_action('wp_ajax_subscribe_user', array($this, 'log_user_subscribe'));
        
        // Para formulários de newsletter comuns
        add_action('wpcf7_mail_sent', array($this, 'log_contact_form_subscribe'));
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
    
    /**
     * MÉTODO CORRIGIDO: should_skip_logging()
     */
    private function should_skip_logging() {
        // Não logar requisições de sistema
        if (defined('DOING_AJAX') && DOING_AJAX) return true;
        if (defined('DOING_CRON') && DOING_CRON) return true;
        if (defined('WP_CLI') && WP_CLI) return true;
        if (wp_is_json_request()) return true;
        
        return false;
    }
    
    /**
     * NOVO MÉTODO: Log para inscrição de usuários
     */
    public function log_user_subscribe() {
        $client_ip = $this->get_client_ip();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $timestamp = date('d/M/Y:H:i:s O');
        
        // Dados do formulário de inscrição
        $email = sanitize_email($_POST['email'] ?? 'unknown@example.com');
        $name = sanitize_text_field($_POST['name'] ?? 'Anonymous');
        $newsletter_type = sanitize_text_field($_POST['newsletter_type'] ?? 'general');
        
        $log_message = sprintf(
            '%s - subscribe_user [%s] "POST /wp-admin/admin-ajax.php HTTP/1.1" 200 %d "-" "%s" [USER_ROLE:guest] [SUBSCRIBE:email:%s:name:%s:type:%s]',
            $client_ip,
            $timestamp,
            rand(500, 1000),
            $user_agent,
            $email,
            $name,
            $newsletter_type
        );
        
        $this->send_to_logstash($log_message);
        
        // Não interrompe o fluxo normal do WordPress
        return;
    }
    
    /**
     * NOVO MÉTODO: Para Contact Form 7
     */
    public function log_contact_form_subscribe($contact_form) {
        // Verificar se a classe WPCF7_Submission existe
        if (!class_exists('WPCF7_Submission')) {
            return;
        }
        
        $submission = WPCF7_Submission::get_instance();
        
        if ($submission) {
            $posted_data = $submission->get_posted_data();
            
            // Verificar se é um formulário de newsletter
            if (isset($posted_data['your-email']) || isset($posted_data['email'])) {
                $client_ip = $this->get_client_ip();
                $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                $timestamp = date('d/M/Y:H:i:s O');
                
                $email = sanitize_email($posted_data['your-email'] ?? $posted_data['email'] ?? 'unknown@example.com');
                $name = sanitize_text_field($posted_data['your-name'] ?? $posted_data['name'] ?? 'Anonymous');
                
                $log_message = sprintf(
                    '%s - subscribe_user [%s] "POST %s HTTP/1.1" 200 %d "-" "%s" [USER_ROLE:guest] [SUBSCRIBE:email:%s:name:%s:type:newsletter]',
                    $client_ip,
                    $timestamp,
                    $_SERVER['REQUEST_URI'],
                    rand(500, 1000),
                    $user_agent,
                    $email,
                    $name
                );
                
                $this->send_to_logstash($log_message);
            }
        }
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
        
        // DETECÇÃO OTIMIZADA
        $content_type = $this->detect_content_type($request_uri);
        $file_extension = $this->get_file_extension($request_uri);
        
        // Detectar se é uma página específica
        $page_type = $this->detect_page_type($request_uri);
        
        // Informações adicionais para arquivos
        $file_info = '';
        if ($file_extension) {
            $file_size = $this->get_file_size($file_extension);
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
    
    /**
     * DETECÇÃO DE CONTEÚDO
     */
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
    
    /**
     * DETECÇÃO DEFINITIVA - OTIMIZADA PARA "NOME DO POST"
     */
    private function get_file_extension($uri) {
        $path = parse_url($uri, PHP_URL_PATH);
        $query = parse_url($uri, PHP_URL_QUERY);
        
        if (!$path) return '';
        
        // 1. PRIMEIRO: Verificar se é TAINACAN
        if (strpos($path, '/collection/') !== false || strpos($path, '/tainacan/') !== false) {
            if (strpos($path, '/document/') !== false) return 'pdf';
            if (strpos($path, '/image/') !== false) return 'jpg';
            if (strpos($path, '/audio/') !== false) return 'mp3';
            if (strpos($path, '/video/') !== false) return 'mp4';
            return 'pdf'; // Padrão Tainacan
        }
        
        // 2. URLs com EXTENSÃO EXPLÍCITA
        if (preg_match('/\.([a-z0-9]{2,6})$/i', $path, $matches)) {
            $ext = strtolower($matches[1]);
            $all_extensions = array_merge(...array_values($this->file_types));
            if (in_array($ext, $all_extensions)) {
                return $ext;
            }
        }
        
        return '';
    }
    
    private function detect_page_type($uri) {
        // Detectar URLs do Tainacan primeiro
        if (strpos($uri, '/collection/') !== false || strpos($uri, '/tainacan/') !== false) {
            if (strpos($uri, '/item/') !== false) return 'tainacan_item';
            if (strpos($uri, '/collection/') !== false) return 'tainacan_collection';
            return 'tainacan';
        }
        
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
    
    private function get_file_size($extension) {
        $default_sizes = [
            'pdf' => '2.5MB',
            'jpg' => '1.2MB', 
            'jpeg' => '1.2MB',
            'png' => '1.5MB',
            'mp3' => '8.5MB',
            'mp4' => '45MB',
            'doc' => '1.8MB',
            'docx' => '2.1MB',
            'zip' => '15MB',
        ];
        
        return $default_sizes[$extension] ?? '1.0MB';
    }
    
    private function get_response_size() {
        // Tamanho baseado no tipo de conteúdo
        if (is_admin()) return rand(50000, 200000);
        if (is_single() || is_page()) return rand(30000, 100000);
        if ($this->get_file_extension($_SERVER['REQUEST_URI'])) {
            return rand(100000, 5000000);
        }
        return rand(10000, 50000);
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
}

new LogstashLogger();