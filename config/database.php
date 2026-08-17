<?php
/**
 * config/database.php
 *
 * Credenciais de conexão com o MySQL 8 na Hostinger.
 * SUBSTITUA os valores abaixo pelos dados reais do seu painel Hostinger
 * (hPanel > Bancos de Dados > MySQL).
 *
 * NUNCA versione este arquivo com credenciais reais em repositórios públicos.
 */

// Valores padrão. O setup.php pode salvar credenciais específicas deste
// servidor em config/database.local.php, que fica fora do document root e
// não deve ser enviado a repositórios públicos.
$databaseConfig = [
    // Host do banco (na Hostinger normalmente é 'localhost')
    'host' => 'localhost',
    // Nome do banco (crie em hPanel > Banco de Dados MySQL)
    'name' => 'titanium_crm',
    // Usuário (Hostinger geralmente prefixa com u123456789_)
    'user' => 'root',
    // Senha do banco
    'pass' => '',
    'charset' => 'utf8mb4',
];

$localDatabaseConfig = __DIR__ . '/database.local.php';
if (is_file($localDatabaseConfig)) {
    $localValues = require $localDatabaseConfig;
    if (is_array($localValues)) {
        $databaseConfig = array_merge($databaseConfig, array_intersect_key($localValues, $databaseConfig));
    }
}

define('DB_HOST', $databaseConfig['host']);
define('DB_NAME', $databaseConfig['name']);
define('DB_USER', $databaseConfig['user']);
define('DB_PASS', $databaseConfig['pass']);
define('DB_CHARSET', $databaseConfig['charset']);
