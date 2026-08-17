SET NAMES utf8mb4;

-- Construtor de Formulários: formulários públicos personalizáveis que criam
-- leads automaticamente (mesmo princípio dos webhooks de captação já
-- existentes — ver WebhookController), com campos arrastáveis mapeados para
-- colunas reais da tabela `leads`, link público (/f/{slug}) e, opcionalmente,
-- um responsável padrão (usado também pelo QR Code por vendedor: o mesmo
-- link com ?consultor=ID sobrepõe o responsável padrão do formulário).

CREATE TABLE IF NOT EXISTS forms (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(150) NOT NULL,
 slug VARCHAR(80) NOT NULL,
 description VARCHAR(255) NULL,
 fields JSON NOT NULL,
 default_source VARCHAR(40) NOT NULL DEFAULT 'formulario',
 default_interest VARCHAR(40) NULL,
 default_assigned_to INT UNSIGNED NULL,
 notify_assignee TINYINT(1) NOT NULL DEFAULT 1,
 success_message VARCHAR(255) NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 submissions_count INT UNSIGNED NOT NULL DEFAULT 0,
 created_by INT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_forms_slug (slug),
 KEY idx_forms_assignee (default_assigned_to),
 CONSTRAINT fk_forms_assignee FOREIGN KEY (default_assigned_to) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT fk_forms_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (slug, label) VALUES
('forms.manage', 'Criar e gerenciar formulários de captação');
INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'admin', id FROM permissions WHERE slug = 'forms.manage';
INSERT IGNORE INTO role_permissions (role, permission_id) SELECT 'supervisor', id FROM permissions WHERE slug = 'forms.manage';
