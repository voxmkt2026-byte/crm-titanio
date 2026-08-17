<?php
/**
 * app/models/Tag.php
 */

require_once APP_PATH . '/core/Model.php';

class Tag extends Model
{
    protected string $table = 'tags';

    public function forLead(int $leadId): array
    {
        $stmt = $this->db->prepare(
            "SELECT t.* FROM tags t
             INNER JOIN lead_tags lt ON lt.tag_id = t.id
             WHERE lt.lead_id = :lead_id"
        );
        $stmt->execute([':lead_id' => $leadId]);
        return $stmt->fetchAll();
    }

    /**
     * Busca uma tag pelo nome (case-insensitive) ou cria uma nova (Fase 4,
     * usado pelo campo "Tags" do wizard de cadastro e pela importação CSV).
     */
    public function findOrCreateByName(string $name): int
    {
        $name = trim($name);

        $stmt = $this->db->prepare("SELECT id FROM tags WHERE name = :name LIMIT 1");
        $stmt->execute([':name' => $name]);
        $existing = $stmt->fetch();
        if ($existing) {
            return (int) $existing['id'];
        }

        $insert = $this->db->prepare("INSERT INTO tags (name, color) VALUES (:name, :color)");
        $insert->execute([':name' => $name, ':color' => '#3b82f6']);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Substitui o conjunto de tags associadas a um lead pelo array de IDs informado.
     */
    public function syncForLead(int $leadId, array $tagIds): void
    {
        $delete = $this->db->prepare("DELETE FROM lead_tags WHERE lead_id = :lead_id");
        $delete->execute([':lead_id' => $leadId]);

        if (empty($tagIds)) {
            return;
        }

        $insert = $this->db->prepare("INSERT IGNORE INTO lead_tags (lead_id, tag_id) VALUES (:lead_id, :tag_id)");
        foreach (array_unique(array_map('intval', $tagIds)) as $tagId) {
            if ($tagId > 0) {
                $insert->execute([':lead_id' => $leadId, ':tag_id' => $tagId]);
            }
        }
    }
}
