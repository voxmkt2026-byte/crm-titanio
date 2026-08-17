<?php
/**
 * app/core/Auth.php
 * Gerenciamento de sessão/autenticação.
 * - Sessão segura (session_regenerate_id em login, cookies httponly)
 * - password_hash / password_verify
 * - Middleware simples de autenticação (requireLogin)
 */

class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function attempt(string $email, string $password): bool
    {
        require_once APP_PATH . '/models/User.php';
        $userModel = new User();
        $user = $userModel->findByEmail($email);

        if (!$user) {
            return false;
        }

        if ((int) $user['active'] !== 1) {
            return false;
        }

        if (!password_verify($password, $user['password'])) {
            return false;
        }

        self::login($user);
        return true;
    }

    public static function login(array $user): void
    {
        self::start();

        // Regenera o ID de sessão para prevenir Session Fixation
        session_regenerate_id(true);

        $_SESSION['user_id']   = (int) $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role']  = $user['role'];
        $_SESSION['user_avatar'] = $user['avatar'] ?? null;
        $_SESSION['logged_in_at'] = time();
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'], $params['secure'], $params['httponly']
            );
        }

        session_destroy();
    }

    public static function check(): bool
    {
        self::start();
        return isset($_SESSION['user_id']);
    }

    public static function user(): ?array
    {
        self::start();
        if (!self::check()) {
            return null;
        }

        return [
            'id'     => $_SESSION['user_id'],
            'name'   => $_SESSION['user_name'],
            'email'  => $_SESSION['user_email'],
            'role'   => $_SESSION['user_role'],
            'avatar' => $_SESSION['user_avatar'],
        ];
    }

    public static function id(): ?int
    {
        self::start();
        return $_SESSION['user_id'] ?? null;
    }

    public static function requireLogin(): void
    {
        self::start();
        if (!self::check()) {
            header('Location: ' . BASE_URL . '/login');
            exit;
        }
    }

    public static function hasRole(array $roles): bool
    {
        self::start();
        return isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], $roles, true);
    }

    /**
     * Permissões granulares por ação (Fase 3), lidas das tabelas
     * `permissions`/`role_permissions` + overrides por pessoa em
     * `user_permissions` (ver database/sql/migration_user_permissions.sql),
     * e cacheadas na sessão (por usuário) para evitar consulta ao banco a
     * cada verificação. Ex: Auth::can('leads.delete').
     *
     * Overrides por usuário têm prioridade sobre o papel: uma "negação"
     * explícita (deny) sempre vence, mesmo que o papel libere a permissão;
     * uma "liberação" explícita (allow) libera mesmo que o papel não dê.
     *
     * Se as tabelas ainda não existirem (banco não migrado) ou ocorrer
     * qualquer erro de consulta, o acesso é liberado apenas para admin
     * (comportamento equivalente ao anterior), para não travar o sistema em
     * produção antes das migrations serem executadas.
     */
    public static function can(string $permissionSlug): bool
    {
        self::start();

        if (!self::check()) {
            return false;
        }

        $role = $_SESSION['user_role'] ?? null;
        $userId = $_SESSION['user_id'] ?? null;
        if (!$role || !$userId) {
            return false;
        }

        if (!isset($_SESSION['perm_cache']) || !is_array($_SESSION['perm_cache']) || ($_SESSION['perm_cache_uid'] ?? null) !== $userId) {
            $_SESSION['perm_cache'] = self::loadPermissionsForUser($role, (int) $userId);
            $_SESSION['perm_cache_uid'] = $userId;
        }

        $granted = $_SESSION['perm_cache'];
        return in_array('*', $granted, true) || in_array($permissionSlug, $granted, true);
    }

    /** Limpa o cache de permissões da sessão atual (ex: após alterar role_permissions/user_permissions). */
    public static function clearPermissionCache(): void
    {
        self::start();
        unset($_SESSION['perm_cache'], $_SESSION['perm_cache_uid']);
    }

    private static function loadPermissionsForUser(string $role, int $userId): array
    {
        try {
            require_once APP_PATH . '/models/Permission.php';
            $model = new Permission();
            $granted = $model->slugsForRole($role);

            if (!in_array('*', $granted, true)) {
                $overrides = $model->overridesForUser($userId);
                $granted = array_values(array_diff(array_unique(array_merge($granted, $overrides['allow'])), $overrides['deny']));
            }

            return $granted;
        } catch (Throwable $e) {
            error_log('Auth::can() - falha ao carregar permissões (rode database/sql/migration_fase3.sql e migration_user_permissions.sql): ' . $e->getMessage());
            // Fallback seguro: só admin passa a ter acesso liberado se as tabelas ainda não existirem
            return $role === 'admin' ? ['*'] : [];
        }
    }
}
