<?php
/**
 * app/models/User.php
 */

require_once APP_PATH . '/core/Model.php';

class User extends Model
{
    protected string $table = 'users';

    public function findByEmail(string $email)
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function allActive(): array
    {
        $stmt = $this->db->query("SELECT id, name, email, role, commercial_function, avatar FROM users WHERE active = 1 ORDER BY name ASC");
        return $stmt->fetchAll();
    }

    /** Todos os usuários (ativos e inativos), para a tela administrativa */
    public function allUsers(): array
    {
        $stmt = $this->db->query("SELECT id, name, email, role, commercial_function, avatar, active, created_at FROM users ORDER BY name ASC");
        return $stmt->fetchAll();
    }

    /**
     * Listagem paginada de usuários (Fase 5), com busca opcional por
     * nome/e-mail, seguindo o mesmo padrão de ActivityLog::paginate()/
     * Lead::paginate().
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $conditions = [];
        $params = [];
        if (!empty($filters['search'])) {
            $conditions[] = "(u.name LIKE :search OR u.email LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['role'])) {
            $conditions[] = "u.role = :role";
            $params[':role'] = $filters['role'];
        }
        if (isset($filters['department_id']) && $filters['department_id'] !== '') {
            $conditions[] = "u.department_id = :department_id";
            $params[':department_id'] = (int) $filters['department_id'];
        }
        if (isset($filters['active']) && $filters['active'] !== '') {
            $conditions[] = "u.active = :active";
            $params[':active'] = (int) $filters['active'];
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "SELECT u.id, u.name, u.email, u.role, u.commercial_function, u.avatar, u.active, u.created_at, u.department_id, d.name AS department_name
                FROM users u LEFT JOIN chat_departments d ON d.id = u.department_id
                {$where} ORDER BY u.name ASC LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll();

        $countSql = "SELECT COUNT(*) AS total FROM users u {$where}";
        $countStmt = $this->db->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->fetch()['total'] ?? 0);

        return [
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Busca usuários ativos por nome/e-mail (chat interno: iniciar DM,
     * comandos /adicionar e /remover). Usada pelo ChatController.
     */
    public function searchActive(string $term, int $excludeId, int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, name, email, avatar, department_id FROM users
             WHERE active = 1 AND id != :exclude_id AND (name LIKE :term OR email LIKE :term2)
             ORDER BY name ASC LIMIT :limit"
        );
        $stmt->bindValue(':exclude_id', $excludeId, PDO::PARAM_INT);
        $stmt->bindValue(':term', '%' . $term . '%');
        $stmt->bindValue(':term2', '%' . $term . '%');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Busca um único usuário ativo por nome exato (case-insensitive) ou
     * e-mail, usada pelos comandos /adicionar e /remover do chat interno.
     */
    public function findActiveByNameOrEmail(string $term)
    {
        $stmt = $this->db->prepare(
            "SELECT id, name, email FROM users
             WHERE active = 1 AND (LOWER(name) = LOWER(:term) OR LOWER(email) = LOWER(:term2))
             LIMIT 1"
        );
        $stmt->execute([':term' => $term, ':term2' => $term]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        $sql = "SELECT id FROM users WHERE email = :email";
        $params = [':email' => $email];
        if ($excludeId) {
            $sql .= " AND id != :id";
            $params[':id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return (bool) $stmt->fetch();
    }

    public function create(array $data): int
    {
        // department_id só existe a partir da migration_chat.sql (chat interno). Faz
        // fallback gracioso se o banco ainda não tiver sido migrado, para não travar
        // o cadastro básico de usuário (Fase 2) em quem ainda não rodou a migration nova.
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO users (name, email, password, role, commercial_function, avatar, active, department_id, created_at, updated_at)
                 VALUES (:name, :email, :password, :role, :commercial_function, :avatar, :active, :department_id, NOW(), NOW())"
            );
            $stmt->execute([
                ':name'          => $data['name'],
                ':email'         => $data['email'],
                ':password'      => $data['password'],
                ':role'          => $data['role'],
                ':commercial_function' => $data['commercial_function'] ?? 'vendedor',
                ':avatar'        => $data['avatar'] ?? null,
                ':active'        => !empty($data['active']) ? 1 : 0,
                ':department_id' => $data['department_id'] ?? null,
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'department_id') === false) {
                throw $e;
            }
            error_log('User::create - coluna department_id ainda não existe (rode database/sql/migration_chat.sql): ' . $e->getMessage());
            $stmt = $this->db->prepare(
                "INSERT INTO users (name, email, password, role, avatar, active, created_at, updated_at)
                 VALUES (:name, :email, :password, :role, :avatar, :active, NOW(), NOW())"
            );
            $stmt->execute([
                ':name'     => $data['name'],
                ':email'    => $data['email'],
                ':password' => $data['password'],
                ':role'     => $data['role'],
                ':avatar'   => $data['avatar'] ?? null,
                ':active'   => !empty($data['active']) ? 1 : 0,
            ]);
            return (int) $this->db->lastInsertId();
        }
    }

    /** Atualiza dados do usuário. Se $data['password'] vier vazio/null, a senha atual é mantida. */
    public function updateUser(int $id, array $data): bool
    {
        $sets = ['name = :name', 'email = :email', 'role = :role', 'commercial_function = :commercial_function', 'active = :active', 'department_id = :department_id'];
        $params = [
            ':name'          => $data['name'],
            ':email'         => $data['email'],
            ':role'          => $data['role'],
            ':commercial_function' => $data['commercial_function'] ?? 'vendedor',
            ':active'        => !empty($data['active']) ? 1 : 0,
            ':department_id' => $data['department_id'] ?? null,
            ':id'            => $id,
        ];

        if (!empty($data['password'])) {
            $sets[] = 'password = :password';
            $params[':password'] = $data['password'];
        }
        if (array_key_exists('avatar', $data) && $data['avatar'] !== null) {
            $sets[] = 'avatar = :avatar';
            $params[':avatar'] = $data['avatar'];
        }

        $sql = "UPDATE users SET " . implode(', ', $sets) . " WHERE id = :id";
        try {
            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'department_id') === false) {
                throw $e;
            }
            error_log('User::updateUser - coluna department_id ainda não existe (rode database/sql/migration_chat.sql): ' . $e->getMessage());
            unset($params[':department_id']);
            $sets = array_values(array_filter($sets, fn($s) => strpos($s, 'department_id') === false));
            $sql = "UPDATE users SET " . implode(', ', $sets) . " WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        }
    }

    /** Alterna ativo/inativo sem precisar abrir o formulário de edição completo. */
    public function toggleActive(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE users SET active = IF(active = 1, 0, 1) WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    // ---- Fluxo de "Esqueci minha senha" (Fase 3) ----

    /** Gera e salva um token de redefinição de senha, válido por $minutesValid minutos. */
    public function setResetToken(int $id, string $token, int $minutesValid = 60): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE users SET password_reset_token = :token,
             password_reset_expires = DATE_ADD(NOW(), INTERVAL :minutes MINUTE)
             WHERE id = :id"
        );
        return $stmt->execute([':token' => $token, ':minutes' => $minutesValid, ':id' => $id]);
    }

    /** Busca usuário por token de redefinição, apenas se ainda não expirou. */
    public function findByResetToken(string $token)
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM users
             WHERE password_reset_token = :token
             AND password_reset_expires IS NOT NULL
             AND password_reset_expires >= NOW()
             LIMIT 1"
        );
        $stmt->execute([':token' => $token]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function clearResetToken(int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE users SET password_reset_token = NULL, password_reset_expires = NULL WHERE id = :id"
        );
        return $stmt->execute([':id' => $id]);
    }

    public function updatePassword(int $id, string $passwordHash): bool
    {
        $stmt = $this->db->prepare("UPDATE users SET password = :password WHERE id = :id");
        return $stmt->execute([':password' => $passwordHash, ':id' => $id]);
    }
}
