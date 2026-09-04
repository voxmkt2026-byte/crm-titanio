<?php
/**
 * app/models/WhatsappTemplate.php
 * Templates de mensagem para WhatsApp (Fase 7 - auditoria UX), com
 * placeholders tipo {{nome}}, {{interesse}}, {{responsavel}} substituídos
 * pelos dados reais do lead no modal de envio (ver leads/show.php).
 */

require_once APP_PATH . '/core/Model.php';

class WhatsappTemplate extends Model
{
    protected string $table = 'whatsapp_templates';

    public function allActive(): array
    {
        $stmt = $this->db->query("SELECT * FROM whatsapp_templates WHERE active = 1 ORDER BY name ASC");
        return $stmt->fetchAll();
    }

    public function create(string $name, string $content, string $category = 'geral', bool $active = true): int
    {
        $stmt = $this->db->prepare("INSERT INTO whatsapp_templates (name, category, content, active) VALUES (:name, :category, :content, :active)");
        $stmt->execute([':name' => $name, ':category' => $category, ':content' => $content, ':active' => $active ? 1 : 0]);
        return (int) $this->db->lastInsertId();
    }

    public function updateTemplate(int $id, string $name, string $content, string $category, bool $active): bool
    {
        $stmt = $this->db->prepare("UPDATE whatsapp_templates SET name = :name, category = :category, content = :content, active = :active WHERE id = :id");
        return $stmt->execute([':name' => $name, ':category' => $category, ':content' => $content, ':active' => $active ? 1 : 0, ':id' => $id]);
    }
}
