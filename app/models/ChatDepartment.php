<?php
/**
 * app/models/ChatDepartment.php
 * Departamentos usados para dividir o chat interno (Comercial, Suporte,
 * Financeiro, Diretoria, Geral). Tabela `chat_departments` criada em
 * database/sql/migration_chat.sql. "Geral" (slug 'geral') é a sala padrão
 * visível a todos os usuários, independente do departamento de cada um.
 */

require_once APP_PATH . '/core/Model.php';

class ChatDepartment extends Model
{
    protected string $table = 'chat_departments';

    public function allActive(): array
    {
        $stmt = $this->db->query("SELECT * FROM chat_departments WHERE active = 1 ORDER BY name ASC");
        return $stmt->fetchAll();
    }

    public function findBySlug(string $slug)
    {
        $stmt = $this->db->prepare("SELECT * FROM chat_departments WHERE slug = :slug LIMIT 1");
        $stmt->execute([':slug' => $slug]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /** Todos os departamentos (ativos e inativos), com contagem de usuários, para a tela de gestão. */
    public function allWithUserCount(): array
    {
        $stmt = $this->db->query(
            "SELECT d.*, COUNT(u.id) AS user_count
             FROM chat_departments d
             LEFT JOIN users u ON u.department_id = d.id
             GROUP BY d.id
             ORDER BY d.name ASC"
        );
        return $stmt->fetchAll();
    }

    /** Nomes dos usuários de cada departamento, agrupados por department_id (para a tela de gestão). */
    public function membersGrouped(): array
    {
        $stmt = $this->db->query(
            "SELECT department_id, name, active FROM users WHERE department_id IS NOT NULL ORDER BY name ASC"
        );
        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[(int) $row['department_id']][] = $row;
        }
        return $grouped;
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $sql = "SELECT id FROM chat_departments WHERE slug = :slug";
        $params = [':slug' => $slug];
        if ($excludeId) {
            $sql .= " AND id != :id";
            $params[':id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql . " LIMIT 1");
        $stmt->execute($params);
        return (bool) $stmt->fetch();
    }

    public function create(string $name, string $slug, string $color, string $icon): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO chat_departments (name, slug, color, icon, active) VALUES (:name, :slug, :color, :icon, 1)"
        );
        $stmt->execute([':name' => $name, ':slug' => $slug, ':color' => $color, ':icon' => $icon]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $name, string $color, string $icon): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE chat_departments SET name = :name, color = :color, icon = :icon WHERE id = :id"
        );
        return $stmt->execute([':name' => $name, ':color' => $color, ':icon' => $icon, ':id' => $id]);
    }

    /** Ativa/desativa sem excluir — evitamos DELETE porque chat_rooms.department_id é ON DELETE CASCADE
     *  (apagaria a sala e o histórico de mensagens do departamento). */
    public function toggleActive(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE chat_departments SET active = IF(active = 1, 0, 1) WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}
