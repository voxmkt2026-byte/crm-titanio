<?php
/**
 * config/config.php
 * Configurações gerais da aplicação Titanium CRM.
 *
 * IMPORTANTE: ao subir para a Hostinger, ajuste BASE_URL para a URL final
 * (ex: 'https://crm.titaniumconsultorias.com.br' ou com subpasta se necessário).
 */

// Evita acesso direto ao arquivo
if (!defined('APP_INIT')) {
    define('APP_INIT', true);
}

// Ambiente: 'development' ou 'production'
define('APP_ENV', 'production');

// Exibição de erros (deixe 0 em produção real na Hostinger)
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
}

// Nome da aplicação
define('APP_NAME', 'Titanium Consultoria');
define('COMPANY_NAME', 'Titanium Consultoria');

// Versão de "cache-busting" dos arquivos estáticos (CSS/JS), usada como
// reserva pelo helper asset() quando filemtime() do arquivo não está
// disponível (ex: is_file() falha por causa de open_basedir/permissões na
// hospedagem, mesmo com o arquivo servido normalmente pelo Apache/LiteSpeed).
// A Hostinger cacheia arquivos estáticos por 7 dias (Cache-Control:
// max-age=604800) tanto no navegador quanto na CDN deles — sem uma URL que
// realmente mude a cada alteração, essas mudanças de CSS/JS ficam "presas"
// em cache por até uma semana. SEMPRE que publicar um novo CSS/JS na
// Hostinger, troque este valor (data de hoje é suficiente) para forçar a
// atualização imediata em todo mundo.
define('ASSET_VERSION', '2026081712');

// URL base da aplicação (SEM barra no final)
// Exemplo local: http://localhost/leads/public
// Exemplo Hostinger: https://seudominio.com.br
define('BASE_URL', 'https://crm.titaniumconsultorias.com.br');

// Caminhos absolutos do sistema de arquivos
define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('UPLOADS_PATH', ROOT_PATH . '/public/uploads');
define('UPLOADS_URL', BASE_URL . '/uploads');

// Fuso horário
date_default_timezone_set('America/Sao_Paulo');

// Configurações de sessão segura (precisam ser definidas ANTES de session_start())
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
// Se o site rodar em HTTPS na Hostinger, deixe 1. Em localhost sem HTTPS, deixe 0.
ini_set('session.cookie_secure', '1');

// Log de erros em arquivo próprio (não expõe erros ao usuário final)
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/php_errors.log');
