<?php
/**
 * app/core/Csrf.php
 * Geração e verificação de token CSRF por sessão.
 */

class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    public static function token(): string
    {
        Auth::start();

        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /** Gera o campo <input hidden> pronto para uso nos formulários */
    public static function field(): string
    {
        $token = self::token();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function verify(?string $token): bool
    {
        Auth::start();

        if (empty($_SESSION[self::SESSION_KEY]) || empty($token)) {
            return false;
        }

        return hash_equals($_SESSION[self::SESSION_KEY], $token);
    }

    /**
     * Verifica o token vindo do POST e encerra a requisição com 419 se inválido.
     */
    public static function verifyRequest(): void
    {
        $token = $_POST['csrf_token'] ?? '';
        if (!self::verify($token)) {
            http_response_code(419);
            die('Token de segurança inválido ou expirado. Recarregue a página e tente novamente.');
        }
    }
}
