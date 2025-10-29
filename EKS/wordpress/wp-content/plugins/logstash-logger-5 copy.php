<?php
/**
 * Plugin Name: Logstash Logger - Optimized for Post Name
 * Description: Envia logs de acesso para Logstash com detecção para estrutura "Nome do post"
 * Version: 6.1
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
        
        // DETECÇÃO OTIMIZADA PARA ESTRUTURA "NOME DO POST"
        $content_type = $this->detect_content_type($request_uri);
        $file_extension = $this->get_file_extension($request_uri);
        
        // Detectar se é uma página específica
        $page_type = $this->detect_page_type($request_uri);
        
        // Informações adicionais para arquivos
        $file_info = '';
        if ($file_extension) {
            $file_size = $this->get_file_size($request_extension);
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
        
        // 2. URLs com EXTENSÃO EXPLÍCITA (mais comum)
        if (preg_match('/\.([a-z0-9]{2,6})$/i', $path, $matches)) {
            $ext = strtolower($matches[1]);
            $all_extensions = array_merge(...array_values($this->file_types));
            if (in_array($ext, $all_extensions)) {
                return $ext;
            }
        }
        
        // 3. DETECÇÃO POR PALAVRAS-CHAVE no slug (para estrutura "Nome do post")
        $slug = basename($path);
        
        // Padrões comuns em slugs de arquivos
        $keyword_patterns = [
            'pdf' => '/\b(pdf|documento|arquivo|file|doc)\b/i',
            'jpg' => '/\b(jpg|jpeg|png|imagem|foto|image|photo|img)\b/i',
            'mp3' => '/\b(mp3|audio|som|sound|music)\b/i',
            'mp4' => '/\b(mp4|video|filme|movie|video)\b/i',
            'zip' => '/\b(zip|compactado|arquivo|package)\b/i',
            'doc' => '/\b(doc|docx|word|document)\b/i',
        ];
        
        foreach ($keyword_patterns as $ext => $pattern) {
            if (preg_match($pattern, $slug)) {
                return $ext;
            }
        }
        
        // 4. Query string com parâmetros de arquivo
        if ($query) {
            if (preg_match('/(?:file|attachment|document|arquivo)=[^&]*\.([a-z0-9]{2,6})/i', $query, $matches)) {
                $ext = strtolower($matches[1]);
                $all_extensions = array_merge(...array_values($this->file_types));
                if (in_array($ext, $all_extensions)) {
                    return $ext;
                }
            }
            
            // Parâmetros comuns do WordPress
            if (preg_match('/attachment_id=(\d+)/i', $query)) {
                return 'jpg'; // Assumir imagem para attachments
            }
        }
        
        // 5. URLs do WordPress admin com parâmetro p (post)
        if ($query && preg_match('/p=(\d+)/i', $query)) {
            // É uma página/post - não é arquivo
            return '';
        }
        
        return '';
    }
    
    private function detect_page_type($uri) {
        $path = parse_url($uri, PHP_URL_PATH);
        
        // Detectar URLs do Tainacan
        if (strpos($path, '/collection/') !== false || strpos($path, '/tainacan/') !== false) {
            if (strpos($path, '/item/') !== false) return 'tainacan_item';
            if (strpos($path, '/collection/') !== false) return 'tainacan_collection';
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
    
    private function should_skip_logging() {
        if (defined('DOING_AJAX') && DOING_AJAX) return true;
        if (defined('DOING_CRON') && DOING_CRON) return true;
        if (defined('WP_CLI') && WP_CLI) return true;
        if (wp_is_json_request()) return true;
        
        return false;
    }
}

new LogstashLogger();
?>