<?php
/**
 * app/core/Database.php
 * Wrapper PDO Singleton. Todas as queries do sistema devem passar por aqui,
 * sempre usando prepared statements (proteção contra SQL Injection).
 */

class Database
{
    private static ?PDO $instance = null;

    private function __construct()
    {
        // Não instanciável diretamente
    }

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // IMPORTANTE: mantido em TRUE (emulado) porque várias consultas do sistema
                // reaproveitam o mesmo parâmetro nomeado mais de uma vez na mesma query
                // (ex: WHERE name LIKE :term OR phone LIKE :term OR ... ; ou created_by=:uid,
                // updated_by=:uid). Isso é válido com prepares emulados (PDO resolve os
                // valores no cliente antes de enviar), mas o MySQL nativo (emulate=false)
                // rejeita com "SQLSTATE[HY093]: Invalid parameter number" — foi a causa raiz
                // de bugs na busca rápida de leads, na Wiki (publicar) e possivelmente em
                // outras telas. Como todas as queries do sistema já usam apenas placeholders
                // vinculados (nunca interpolação direta de entrada do usuário), manter
                // emulação ligada não reduz a proteção contra SQL Injection.
                PDO::ATTR_EMULATE_PREPARES   => true,
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                // Não expõe detalhes sensíveis da conexão ao usuário final
                error_log('Erro de conexão com o banco de dados: ' . $e->getMessage());
                http_response_code(500);
                die('Erro ao conectar ao banco de dados. Verifique config/database.php e tente novamente.');
            }
        }

        return self::$instance;
    }

    // Impede clonagem da instância (padrão singleton)
    private function __clone()
    {
    }
}
