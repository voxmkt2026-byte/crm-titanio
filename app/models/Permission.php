<?php
/**
 * app/models/Permission.php
 * Permissões granulares por ação (Fase 3): tabelas `permissions` e
 * `role_permissions` (ver database/sql/migration_fase3.sql).
 * Usado por Auth::can() e pela checagem de acesso nos controllers.
 */

require_once APP_PATH . '/core/Model.php';

class Permission extends Model
{
    protected string $table = 'permissions';

    /** Todas as permissões cadastradas (catálogo completo), ordenadas por slug. */
    public function allPermissions(): array
    {
        $stmt = $this->db->query("SELECT * FROM permissions ORDER BY slug ASC");
        return $stmt->fetchAll();
    }

    /** Lista de slugs de permissão liberados para um papel (admin/supervisor/consultor). */
    public function slugsForRole(string $role): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.slug
             FROM role_permissions rp
             INNER JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role = :role"
        );
        $stmt->execute([':role' => $role]);
        return array_column($stmt->fetchAll(), 'slug');
    }

    /** Matriz [role => [slugs...]] para telas administrativas de permissões. */
    public function matrix(): array
    {
        $matrix = ['admin' => [], 'supervisor' => [], 'consultor' => []];
        foreach (array_keys($matrix) as $role) {
            $matrix[$role] = $this->slugsForRole($role);
        }
        return $matrix;
    }

    /**
     * Overrides de permissão de UM usuário específico (além do papel dele),
     * ver database/sql/migration_user_permissions.sql e Auth::can().
     * @return array{allow: string[], deny: string[]}
     */
    public function overridesForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.slug, up.grant_type
             FROM user_permissions up
             INNER JOIN permissions p ON p.id = up.permission_id
             WHERE up.user_id = :uid"
        );
        $stmt->execute([':uid' => $userId]);
        $overrides = ['allow' => [], 'deny' => []];
        foreach ($stmt->fetchAll() as $row) {
            $overrides[$row['grant_type']][] = $row['slug'];
        }
        return $overrides;
    }

    /** Substitui todos os overrides de um usuário pelos slugs informados (allow/deny). */
    public function setOverridesForUser(int $userId, array $allowSlugs, array $denySlugs): void
    {
        $this->db->prepare("DELETE FROM user_permissions WHERE user_id = :uid")->execute([':uid' => $userId]);

        $catalog = array_column($this->allPermissions(), 'id', 'slug');
        $insert = $this->db->prepare(
            "INSERT INTO user_permissions (user_id, permission_id, grant_type) VALUES (:uid, :pid, :type)"
        );

        foreach (array_unique($allowSlugs) as $slug) {
            if (isset($catalog[$slug])) {
                $insert->execute([':uid' => $userId, ':pid' => $catalog[$slug], ':type' => 'allow']);
            }
        }
        // Negação sempre prevalece se a mesma permissão vier marcada nos dois grupos por engano.
        foreach (array_unique($denySlugs) as $slug) {
            if (isset($catalog[$slug]) && !in_array($slug, $allowSlugs, true)) {
                $insert->execute([':uid' => $userId, ':pid' => $catalog[$slug], ':type' => 'deny']);
            }
        }
    }
}
