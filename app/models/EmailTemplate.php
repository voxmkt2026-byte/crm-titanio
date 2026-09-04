<?php
/** Modelos reutilizáveis de e-mail para o atendimento comercial. */
require_once APP_PATH . '/core/Model.php';

class EmailTemplate extends Model
{
    protected string $table = 'email_templates';

    public function allActive(): array
    {
        return $this->db->query("SELECT * FROM email_templates WHERE active = 1 ORDER BY category ASC, name ASC")->fetchAll();
    }

    public function create(string $name, string $category, string $subject, string $content, bool $active = true): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO email_templates (name, category, subject, content, active) VALUES (:name, :category, :subject, :content, :active)'
        );
        $statement->execute([
            ':name' => $name, ':category' => $category, ':subject' => $subject,
            ':content' => $content, ':active' => $active ? 1 : 0,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function updateTemplate(int $id, string $name, string $category, string $subject, string $content, bool $active): bool
    {
        $statement = $this->db->prepare(
            'UPDATE email_templates SET name=:name, category=:category, subject=:subject, content=:content, active=:active WHERE id=:id'
        );
        return $statement->execute([
            ':id' => $id, ':name' => $name, ':category' => $category, ':subject' => $subject,
            ':content' => $content, ':active' => $active ? 1 : 0,
        ]);
    }
}
